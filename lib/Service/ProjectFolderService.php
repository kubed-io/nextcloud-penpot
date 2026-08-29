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
 * This class used to say **by opt-in, never by accident**: *"a folder created
 * inside a mapped folder is an ORDINARY FOLDER. Nothing is sent, nothing is
 * inferred."* The reasoning was that a mapped folder which silently turned every
 * subfolder into a project would be unusable for anything else.
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
 * opt-in that used to sit beside this has since been removed outright (saga
 * §D4.14): `penpot_project_id` is the only thing that makes a folder a project,
 * and a design arriving is the only thing on this side that writes one.
 *
 * ## WHAT COUNTS AS "IN IT" IS THE FOLDER ITSELF — READING IS STILL THE ANCESTOR
 *
 * A design landing in `Penpot/Team/wip` makes `wip` the project `Team/wip`, even
 * when `Team` is already one. This used to stop at the nearest project ANCESTOR,
 * so `wip` became nothing and the design stayed in `Team` — which made two
 * identical-looking folders behave differently on a marker nobody can see, and
 * made this class's own headline false of every folder below a project. See
 * {@see DestinationResolver::projectForContentIn()} for the reversal.
 *
 * READING DID NOT CHANGE, and the pair has to be held apart. §6.29 still resolves
 * a node to the nearest project ABOVE it, so a design already sitting in a plain
 * subfolder belongs to the project above until something ARRIVES in that
 * subfolder. Arriving promotes; sitting there does not. That is what lets
 * {@see fileExistingDesigns()} sweep a plain subfolder into the project being
 * promoted and still be correct.
 *
 * A LINK MAPPING IS THE EXCEPTION, and not to this rule so much as to promotion
 * itself: under a link the tree is filled FROM Penpot, so nothing here creates
 * anything and an arrival belongs to the project it lands under.
 *
 * And a design landing at the mapping ROOT is Drafts (§6.35), not a project named
 * after the root. {@see MembershipResolver::pathBelowMapping()} returns null there,
 * which is exactly the signal {@see adoptForContent()} needs — the same method
 * §C6.38 added to name a project by its path.
 *
 * ## LATE OPT-IN IS THE WHOLE POINT: THE CONTENTS COME TOO
 *
 * {@see adoptForContent()} leans on {@see fileExistingDesigns()} deliberately: a
 * folder promoted by a design arriving may already hold managed designs — one
 * that left a mapping and came back, one whose own promotion Penpot refused —
 * and filing only the newcomer would leave two designs in one folder showing up
 * in two projects.
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
 * Every one of these returns null and lets the caller fall back to the team's
 * Drafts. Nothing here is an error the user is shown: this fires as a side
 * effect of a drag or a "+ New", and the design always lands somewhere.
 *
 *   - **Already a project** (carries `penpot_project_id`) — no-op, and the
 *     overwhelmingly common path, so it must cost nothing.
 *   - **The mapped ROOT itself** — Drafts, not a project named after the root
 *     (§6.35). {@see MembershipResolver::pathBelowMapping()} returns null there.
 *   - **Outside every mapping** — no team can be resolved, so there is no
 *     project it could belong to.
 *   - **A link mapping** — the tree is filled FROM Penpot; nothing on this side
 *     creates anything.
 *   - **Unusable name, or Penpot rejected the call** — §6.18 rule 3: the local
 *     folder stands exactly as it was, unstamped. It is simply not a project.
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
		private readonly MappingService $mappings,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * A design has landed in this folder, so the folder is a project now.
	 *
	 * The ONLY way a folder becomes a project from this side, and deliberately a
	 * quiet one: it fires as a side effect of a drag or a "+ New", so every way
	 * out is a null and a log line. A design that cannot be promoted still lands
	 * somewhere — the caller falls back to the team's Drafts — and the user is
	 * never shown an error for a gesture that worked.
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

		if ($this->mappings->getByTeamId($teamId)?->mode === Mapping::MODE_LINK) {
			// A LINK MAPPING'S TREE IS PENPOT'S, and promotion is a write. Under a
			// link the folders are filled FROM Penpot and mirror it read-only, so
			// creating a project because a file appeared would be this app inventing
			// structure in a team it is only supposed to be reading.
			//
			// NOT THE WHOLE HOLE, and worth being exact: a brand-new `.penpot` PUT
			// into a link mapping is still created as a design, because
			// `LinkWriteGuardPlugin` classifies from the file's OWN metadata and a new
			// file has none. That is older than this rule and is
			// `designs/create.feature`'s `Creating a design in a link-mapped folder is
			// refused`, still @todo. This guard stops promotion making it worse — a
			// stray design is one thing, a stray design plus a project nobody asked
			// for is another.
			$this->logger->debug('penpot_sync project: not promoting a folder in a link mapping', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
			]);

			return null;
		}

		// NULL HERE MEANS THE MAPPING ROOT, WHICH IS DRAFTS AND NOT A PROJECT
		// (§6.35) — a design dropped straight into `Penpot/` belongs to the team's
		// Drafts, and naming a project after the mapped folder would invent a
		// project nobody asked for on the first drag.
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

		// ── THE RACE, AND WHY THE RE-READ IS HERE RATHER THAN A LOCK ────────────
		//
		// Dragging three designs into a new folder is three concurrent DAV requests
		// in three PHP processes, and nothing serialises them. All three can read
		// "no marker", and Penpot happily holds two projects with the same name in
		// one team (measured — see `The project name is the path below the mapping`),
		// so without this the designs end up SPLIT across duplicate projects while
		// the folder marker records whichever write landed last.
		//
		// Re-reading here does not close the window; it changes what happens when
		// the window is hit. A loser now returns the WINNER's id, so every design
		// is filed into one project and the only casualty is an empty project
		// nobody references. Files together and one stray project beats files
		// scattered across two, and it costs one metadata read on a path that has
		// just made a network round trip.
		//
		// The real fix is serialising per folder through `ILockingProvider`, which
		// is a dependency and a failure mode of its own; deliberately not taken on
		// the round that introduced the exposure.
		$landed = $this->metadata->readFolder($folder->getId());
		if ($landed->hasProject()) {
			$this->logger->warning('penpot_sync project: another arrival promoted this folder first; using theirs', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'kept' => $landed->projectId,
				'orphaned' => $projectId,
			]);

			return $landed->projectId;
		}

		// The stamp is the whole record. `penpot_project_id` is what every later
		// lookup reads (§C6.18), and nothing else marks a folder as a project.
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);

		// THE CONTENTS COME TOO, exactly as they do for a tag. A managed design can
		// already be sitting below a plain folder — one that left a mapping and came
		// back, or one whose own promotion Penpot refused a moment earlier — and
		// filing only the design that happened to arrive last would leave the others
		// in whichever project they were in before. Two designs in one folder,
		// showing up in two projects, is precisely what this class's late-opt-in
		// contract exists to prevent, and it must not depend on WHICH way the folder
		// was promoted.
		//
		// The triggering design is not a special case. On a create it carries no id
		// yet, so it is not collected; on a move-in it is, and it is filed here and
		// then again by the caller — `move-files` is idempotent and non-destructive
		// (§6.27/§6.34), so the cost is one redundant request in one branch and the
		// benefit is that the two adoption paths cannot drift apart.
		$filed = $this->fileExistingDesigns($folder, $projectId);

		$this->logger->info('penpot_sync project: a design arrived, so the folder is a project', [
			'app' => Application::APP_ID,
			'folder' => $folder->getPath(),
			'team' => $teamId,
			'project' => $projectId,
			'name' => $name,
			'designs_filed' => $filed,
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

}
