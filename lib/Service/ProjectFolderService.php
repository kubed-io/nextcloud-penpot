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
 * A Nextcloud folder becomes a Penpot project — **by opt-in, never by accident**
 * (`project-folder.feature`, saga §C6.18).
 *
 * ## THE ASYMMETRY THIS SERVICE EXISTS TO CREATE
 *
 *     every Penpot project      →  a folder in Nextcloud     (automatic)
 *     SOME Nextcloud folders    →  a project in Penpot       (opt-in only)
 *
 * A folder created inside a mapped folder is an ORDINARY FOLDER. Nothing is
 * sent, nothing is inferred, and it can hold whatever the user likes — notes,
 * exports, a subfolder of references. That is not a gap: a mapped folder that
 * silently turned every subfolder into a Penpot project would be unusable for
 * anything else, and this app has refused inference everywhere it could (§6.33
 * on creation, §C6.4 on the drag-in).
 *
 * The opt-in is the `penpot` tag ({@see ProjectTags}) — a deliberate act with a
 * name, exactly as "+ New → Penpot design" is for a file.
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
			// root is not a project and never was, so this is the no-complaint case
			// above rather than something the user typed wrongly; saying "the folder
			// name cannot be used" would send them to rename a folder that is fine.
			$this->logger->debug('penpot_sync project: the mapped root is not a project; nothing to create', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
			]);

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
