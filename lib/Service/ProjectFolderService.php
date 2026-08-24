<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * A Nextcloud folder becomes a Penpot project — **when a design is in it**
 * (`projects/create.feature`).
 *
 * ## THE ASYMMETRY THIS SERVICE EXISTS TO CREATE
 *
 *     every Penpot project      →  a folder in Nextcloud     (automatic)
 *     SOME Nextcloud folders    →  a project in Penpot       (when a design lands)
 *
 * ## THE RULE REVERSED, AND THE OLD ONE IS WORTH READING
 *
 * This class used to say **by opt-in, never by accident**, and the opt-in was the
 * `penpot` tag: *"a folder created inside a mapped folder is an ORDINARY FOLDER.
 * Nothing is sent, nothing is inferred."* The reasoning was that a mapped folder
 * which silently turned every subfolder into a project would be unusable for
 * anything else.
 *
 * That reasoning was sound and the conclusion was too strong, because the thing
 * it was protecting is protected by a narrower rule: **an EMPTY folder is still
 * nobody's business but its owner's**, and `Create a folder in a mapping` pins
 * exactly that. Notes, exports, a subfolder of references — none of them contain
 * a design, so none of them become a project. What the old rule cost was the case
 * that actually matters: someone makes a folder, puts designs in it, and Penpot
 * never hears about it.
 *
 * **Promotion by content rather than by tag, because a move is a gesture people
 * already make and a tag is one they have to be taught** (`AGENTS.md`). The tag
 * still works — {@see onTagged()} is unchanged — it is simply no longer the only
 * way in.
 *
 * ## WHAT COUNTS AS "IN IT" IS THE NEAREST-ANCESTOR RULE, UNCHANGED
 *
 * A design landing in `Penpot/Team/wip` does NOT make `wip` a project when
 * `Team` already is one — §6.29 finds the nearest project ancestor and the design
 * belongs to that. A plain subfolder of a project is Nextcloud's layout, which
 * Penpot cannot see, and `designs/move.feature` pins it.
 *
 * And a design landing at the mapping ROOT is Drafts (§6.35), not a project named
 * after the root. {@see MembershipResolver::pathBelowMapping()} returns null there,
 * which is exactly the signal {@see adoptForContent()} needs — the same method
 * §C6.38 added to name a project by its path.
 *
 * ## LATE OPT-IN IS THE WHOLE POINT: THE CONTENTS COME TOO
 *
 * The interesting half is {@see fileExistingDesigns()}. A folder someone has
 * been filling with designs becomes a project *with those designs in it*, which
 * is the reason to allow opting in late rather than forcing the decision up
 * front. The designs are re-filed with one `move-files` — non-destructive and
 * reversible (§6.27/§6.34), so nothing is exported, re-imported or re-id'd.
 *
 * Which designs? Exactly the ones {@see MembershipResolver} will resolve to this
 * project a moment from now: every managed `.penpot` below the folder, stopping
 * at any subfolder that carries a project id of its own — a nearer ancestor, and
 * therefore not ours. Reading the tree the same way the resolver does is what
 * keeps the two from disagreeing.
 *
 * ## WHERE IT REFUSES, AND WHAT IT LEAVES BEHIND
 *
 *   - **Already a project** (carries `penpot_project_id`) — no-op. The pull
 *     stamps the tag on every folder it mirrors, so this is the common path and
 *     it must cost nothing.
 *   - **The mapped ROOT itself** — not a project and never can be, so the tag is
 *     taken off. Unlike the case below, this folder IS this app's business, and
 *     the pull tags only what it has stamped with a project id; a tagged root
 *     would be the one place the badge meant nothing. Removed silently — trying
 *     it is reasonable, not a mistake worth reporting.
 *   - **Outside every mapping** — nothing to do and nothing to be sorry about.
 *     Tags are instance-wide: a user can put `penpot` on a folder in their
 *     Documents, and no team could be resolved for it even in principle. The tag
 *     is left standing — stripping a user's own tag off a folder this app has no
 *     business touching would be a worse surprise than an inert label.
 *   - **Unusable name** — refused, the tag REMOVED, Penpot never contacted. The
 *     removal is the difference between a two-step the user controls (rename,
 *     re-tag) and a half-created state they have to discover (§6.39).
 *   - **Penpot rejected the call** — §6.18 rule 3: the local folder stands
 *     exactly as it was. The tag is removed for the same reason as above; the
 *     folder is simply not a project yet.
 */
final class ProjectFolderService {
	/**
	 * A ceiling on the descent when collecting designs to file, mirroring
	 * {@see MembershipResolver}'s upward seatbelt. A Nextcloud tree is finite and
	 * the walk terminates naturally; this only guards a pathological shape.
	 */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly PersonalTokenService $personalTokens,
		private readonly ProjectTags $tags,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Someone put the `penpot` tag on a folder. Make it a project if it can be
	 * one, and say why if it cannot.
	 */
	public function onTagged(Folder $folder): void {
		$markers = $this->metadata->readFolder($folder->getId());
		if ($markers->hasProject()) {
			// Already a project — either mirrored from Penpot (the pull stamps the
			// tag itself) or opted in earlier. Re-tagging is a no-op, not a second
			// create: two folders claiming one project is the exact failure
			// `project-folder.feature` refuses copies to avoid.
			return;
		}

		$teamId = $this->resolver->resolve($folder)->teamId;
		if ($teamId === null || $teamId === '') {
			// See the class docblock: no team, no project, no complaint.
			$this->logger->debug('penpot_sync project: tagged folder is outside every mapping; nothing to create', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
			]);

			return;
		}

		// THE NAME IS THE PATH BELOW THE MAPPING, not the folder's own name — see
		// MembershipResolver::pathBelowMapping(). Using the bare name made this
		// direction disagree with the pull, which has always spelt a project's
		// nesting into its name.
		$below = $this->resolver->pathBelowMapping($folder);
		if ($below === null) {
			// NOT THE SAME REFUSAL as an unusable name. Null means the folder has no
			// path below a mapping to be named by — it IS the mapping root. A team
			// root is not a project and never was, so saying "the folder name cannot
			// be used" would send someone off to rename a folder that is fine.
			//
			// THE TAG STILL COMES OFF, and this is the one place that differs from
			// "outside every mapping" above. There the tag is left standing because
			// the folder is none of this app's business; a mapped root is entirely
			// its business, and every other folder wearing this tag carries a
			// `penpot_project_id` — the pull only ever tags what it has stamped. A
			// root left tagged would be the single folder where the badge means
			// nothing, which is exactly the confusion the tag exists to prevent.
			//
			// Silently, though: no warning and no `refuse()`. Tagging the root is a
			// reasonable thing to try, not a mistake to report.
			$this->logger->debug('penpot_sync project: the mapped root is not a project; untagging', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
			]);
			$this->guard->run(fn () => $this->tags->remove($folder->getId()));

			return;
		}

		$name = trim($below);
		if ($name === '' || mb_strlen($name) > 250) {
			// Penpot's own rule is [:string {:max 250, :min 1}] — checked here so
			// the refusal is local and the tag comes off, rather than arriving as a
			// validation error after a round trip.
			$this->refuse($folder, 'the folder name cannot be used as a Penpot project name');

			return;
		}

		try {
			$created = $this->client->createProject($teamId, $name, $this->personalTokens->tokenForActor());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync project: could not create the Penpot project; the folder is unchanged', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'team' => $teamId,
				'exception' => $e,
			]);
			$this->refuse($folder, 'Penpot rejected the project');

			return;
		}

		$projectId = (string)($created['id'] ?? '');
		if ($projectId === '') {
			$this->logger->warning('penpot_sync project: create-project returned no id', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
			]);
			$this->refuse($folder, 'Penpot returned no project id');

			return;
		}

		// Stamp FIRST. The id is what every later lookup reads (§6.29); the tag is
		// only the visible half. If the re-filing below fails, a stamped folder is
		// a real project the next pull can reconcile — an unstamped one would be a
		// project in Penpot that nothing in Nextcloud points at.
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);

		$filed = $this->fileExistingDesigns($folder, $projectId);

		$this->logger->info('penpot_sync project: created a Penpot project from a tagged folder', [
			'app' => Application::APP_ID,
			'folder' => $folder->getPath(),
			'team' => $teamId,
			'project' => $projectId,
			'name' => $name,
			'designs_filed' => $filed,
		]);
	}

	/**
	 * A design has landed in this folder, so the folder is a project now.
	 *
	 * The content-driven twin of {@see onTagged()}, and deliberately the QUIETER
	 * of the two. Tagging is a person asking for something and being told when it
	 * cannot happen; this fires as a side effect of a drag or a "+ New", so every
	 * way out is a null and a log line. A design that cannot be promoted still
	 * lands somewhere — the caller falls back to the team's Drafts — and the user
	 * is never shown an error for a gesture that worked.
	 *
	 * @return string|null the project id to file the design into, or null when this
	 *                     folder is not a project and the caller should use Drafts
	 */
	public function adoptForContent(Folder $folder): ?string {
		$markers = $this->metadata->readFolder($folder->getId());
		if ($markers->hasProject()) {
			// Already one. The overwhelmingly common path — every design after the
			// first — so it costs a single metadata read and no round trip.
			return $markers->projectId;
		}

		$teamId = $this->resolver->resolve($folder)->teamId;
		if ($teamId === null || $teamId === '') {
			return null;
		}

		// NULL HERE MEANS THE MAPPING ROOT, WHICH IS DRAFTS AND NOT A PROJECT
		// (§6.35). The same signal `onTagged()` reads to know a root was tagged,
		// used here to know a design landed at one — a design dropped straight into
		// `Penpot/` belongs to the team's Drafts, and naming a project after the
		// mapped folder would invent a project nobody asked for on the first drag.
		$name = trim((string)$this->resolver->pathBelowMapping($folder));
		if ($name === '' || mb_strlen($name) > 250) {
			return null;
		}

		try {
			$created = $this->client->createProject($teamId, $name, $this->personalTokens->tokenForActor());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync project: could not promote a folder holding a design; it stays a folder', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'team' => $teamId,
				'exception' => $e,
			]);

			return null;
		}

		$projectId = (string)($created['id'] ?? '');
		if ($projectId === '') {
			return null;
		}

		// Stamp FIRST, for the reason `onTagged()` gives: the id is what every
		// later lookup reads, and the tag is only the visible half.
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
		$this->guard->run(fn () => $this->tags->apply($folder->getId()));

		$this->logger->info('penpot_sync project: a design arrived, so the folder is a project', [
			'app' => Application::APP_ID,
			'folder' => $folder->getPath(),
			'team' => $teamId,
			'project' => $projectId,
			'name' => $name,
		]);

		return $projectId;
	}

	/**
	 * Re-file the designs already sitting in the folder into the new project.
	 *
	 * One `move-files` for the lot: the command takes a set, so a folder holding
	 * forty designs costs one request, not forty. A failure here is logged and
	 * swallowed — the project exists and the folder is stamped, so the next pull
	 * sees the truth and the user has lost nothing.
	 *
	 * @return int how many designs were filed
	 */
	private function fileExistingDesigns(Folder $folder, string $projectId): int {
		$ids = $this->managedDesignsBelow($folder, 0);
		if ($ids === []) {
			return 0;
		}

		try {
			$this->client->moveFiles($projectId, $ids, $this->personalTokens->tokenForActor());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync project: the project was created but its existing designs could not be filed', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'project' => $projectId,
				'designs' => count($ids),
				'exception' => $e,
			]);

			return 0;
		}

		return count($ids);
	}

	/**
	 * Every managed `.penpot` below $folder whose NEAREST project ancestor is
	 * $folder — i.e. the descent stops at any subfolder carrying its own project
	 * id, because those designs belong to that project, not this one.
	 *
	 * This is {@see MembershipResolver::resolve()} read downwards. The two must
	 * agree, or the re-file would claim designs the resolver still attributes
	 * elsewhere.
	 *
	 * @return list<string> Penpot file ids, a LIST because `move-files` sends a
	 *                      JSON array (a keyed array would encode as an object)
	 */
	private function managedDesignsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$ids = [];
		foreach ($this->children($folder) as $child) {
			if ($child instanceof Folder) {
				if ($this->metadata->readFolder($child->getId())->hasProject()) {
					continue; // a nearer project ancestor for everything below it
				}
				foreach ($this->managedDesignsBelow($child, $depth + 1) as $id) {
					$ids[] = $id;
				}
				continue;
			}
			if (!$child instanceof File || !str_ends_with($child->getName(), PullService::EXTENSION)) {
				continue;
			}

			$meta = $this->metadata->readFile($child->getId());
			if ($meta === null || !$meta->isManaged()) {
				// An untracked `.penpot` — an upload, or a file this app has never
				// registered. Creating designs for those is CreationService's
				// carve-out, not something to infer from a folder tag.
				continue;
			}
			$ids[] = $meta->penpotId;
		}

		return $ids;
	}

	/** @return list<Node> */
	private function children(Folder $folder): array {
		try {
			return array_values($folder->getDirectoryListing());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync project: could not list a folder while collecting designs', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Take the tag back off and say why.
	 *
	 * Inside the guard so the resulting `TagUnassignedEvent` is unmistakably the
	 * app's own motion — belt and braces, since nothing subscribes to that event
	 * (see {@see \OCA\PenpotSync\Listener\ProjectTagListener}), but the day
	 * something does, this is already correct.
	 */
	private function refuse(Folder $folder, string $reason): void {
		$this->logger->warning('penpot_sync project: refused to make a project from a tagged folder', [
			'app' => Application::APP_ID,
			'folder' => $folder->getPath(),
			'reason' => $reason,
		]);

		$this->guard->run(fn () => $this->tags->remove($folder->getId()));
	}
}
