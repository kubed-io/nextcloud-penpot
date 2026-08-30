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
use Psr\Log\LoggerInterface;

/**
 * Deleting a mirror reaches Penpot — in two steps, mirroring the two trashes
 * (`designs/delete.feature`, saga §C6.11).
 *
 * ## EACH GESTURE GETS THE OPERATION WITH THE SAME REVERSIBILITY
 *
 *     Nextcloud delete (→ NC trash)  →  delete-file  (→ Penpot trash, ~7 days)
 *     Nextcloud purge  (empty trash) →  permanently-delete-team-files
 *
 * That symmetry is the whole design. `designs/delete.feature` used to say a local delete
 * was "purely local, ALWAYS" — written under §6.34, which believed Penpot's
 * trash was unreachable and `delete-file` therefore destructive. §6.52 disproved
 * both and §C6.11 confirmed it live: a deleted design keeps its id, revision and
 * history, and comes back whole. Once that is true, refusing to pass the delete
 * on is not caution — it is a user deleting a design and finding it still there.
 *
 * ## THE PURGE GUARD, WHICH IS THE ONLY SAFETY THAT EXISTS
 *
 * `permanently-delete-team-files` does NOT check that a file is in the trash
 * (§C6.11, proven live on a restored design that was destroyed anyway). It is
 * not "empty the trash", it is "destroy these". So this service reads the trash
 * listing first and purges **only** ids that come back in it.
 *
 * An id that is absent means the design was already purged, or someone restored
 * it in Penpot's own UI — and in both cases destroying it is not what the user
 * asked for. That check is not belt-and-braces; it is the entire seatbelt.
 *
 * ## A FOLDER IS A GESTURE ON EVERY PROJECT ITS NAME SPELLED (§C6.38)
 *
 * {@see onFolderTrashed()} is the delete-shaped twin of
 * {@see PushService::pushFolderRename()}, and it is a subtree walk for the same
 * reason: a project's name is its PATH below the mapping, so `Penpot/foo` is not
 * merely a folder that happens to contain projects — `foo/bar` and `foo/bar/baz`
 * are named THROUGH it and stop meaning anything the moment it goes.
 *
 * This was the last verb still acting on the node it touched alone. Until now
 * nothing deleted a project at all: `DeleteListener` returned on anything that
 * was not a `File`, and `PenpotClient` had no `delete-project`. The scenario for
 * it was tagged `@todo` — *the code exists, only the test is missing* — which was
 * simply untrue, and is the kind of claim only running the thing disproves.
 *
 * SOFT ON BOTH SIDES, which is what makes one gesture over many designs
 * acceptable at all: the folder goes to Nextcloud's trash, the projects go to
 * Penpot's, and both come back. See {@see PenpotClient::deleteProject()}.
 *
 * The one folder this refuses to act on is the MAPPING ROOT — see
 * {@see onFolderTrashed()}. A walk that starts there reaches every project in the
 * team, which is not something any Files gesture should be able to do.
 */
final class DeletionService {
	/**
	 * A ceiling on the descent, mirroring the seatbelts in
	 * {@see MembershipResolver} and {@see PushService}.
	 */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly PersonalTokenService $personalTokens,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The SOFT step: the user deleted a mirror, and it is on its way to the
	 * Nextcloud trash. Put the design in Penpot's trash to match.
	 *
	 * Failure is logged, never thrown. The Nextcloud delete is already happening
	 * and blocking it would be a worse answer than a divergence the next pull can
	 * see — and a design that is ALREADY gone from Penpot is not an error at all,
	 * it is the outcome the user wanted.
	 */
	public function onTrashed(File $node): void {
		$penpotId = $this->metadata->readFile($node->getId())?->penpotId ?? '';
		if ($penpotId === '') {
			// Untracked. No id, nothing to delete — and this is also what keeps a
			// mapped folder usable as an ordinary folder.
			return;
		}

		try {
			$this->client->deleteFile($penpotId, $this->personalTokens->tokenForActor());
			$this->logger->info('penpot_sync delete: moved a design to Penpot\'s trash', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync delete: could not move the design to Penpot\'s trash', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * A FOLDER was trashed: every project named through it goes to Penpot's trash.
	 *
	 * Collected first, deleted second, and deliberately in that order — the walk
	 * reads metadata off nodes that are on their way out, and doing it while
	 * issuing network calls would interleave a filesystem read with a round trip
	 * per project. It also makes the log line able to say how many.
	 *
	 * ONE FAILURE DOES NOT STOP THE REST. Each project is its own call and its own
	 * try: a project Penpot will not delete (the team's default, say) must not
	 * take its siblings down with it, and the local trash has already happened
	 * either way (§6.18 rule 3).
	 */
	public function onFolderTrashed(Folder $node): void {
		if ($this->metadata->readFolder($node->getId())->hasTeam()) {
			// THE MAPPING ROOT, AND THE ONE PLACE THIS WALK MUST NOT START.
			//
			// The root carries a team marker and no project of its own, so without
			// this the descent would reach every project in the team and delete the
			// lot — one local folder delete quietly destroying an entire Penpot
			// team's work. It is the same carve-out
			// {@see PushService::pushFolderRename()} makes, and it belongs here far
			// more urgently: there, missing it costs a batch of no-op renames.
			//
			// Tearing a mapping down is `occ penpot:remove-mapping`, which is
			// deliberately non-destructive. A gesture in the Files app must not be a
			// more powerful version of the command that exists to do this.
			$this->logger->info('penpot_sync delete: the mapped root was trashed; Penpot is left alone', [
				'app' => Application::APP_ID,
				'folder' => $node->getName(),
			]);

			return;
		}

		$projectIds = $this->projectsBelow($node, 0);
		if ($projectIds === []) {
			// A plain folder that names no project — an ordinary folder inside a
			// mapping, which is exactly what a mapped folder must stay usable as.
			return;
		}

		$token = $this->personalTokens->tokenForActor();

		// ── THE DESIGNS GO FIRST, AND THE ORDER IS THE WHOLE OF IT ───────────────
		//
		// Deleting the project alone makes its designs LOOK deleted —
		// `get-team-deleted-files` lists the files of a deleted project — but leaves
		// their own `deleted_at` null. And a file in that state cannot be permanently
		// deleted: `permanently-delete-team-files` reports success and does nothing,
		// so emptying the Nextcloud trash afterwards destroyed nothing at all
		// (`projects/purge.feature`).
		//
		// MEASURED, three runs and a control on a live Penpot. Give the file its own
		// `deleted_at` while the project is still live and the destroy works and the
		// design is unrecoverable; do it in the other order and the design comes back
		// from a restore, bringing the project with it. See
		// features/AGENTS.md#a-designs-own-deletion-is-what-makes-it-destroyable.
		//
		// Costs one call per design on a gesture that already costs one per project,
		// and buys the purge that follows it something to act on. Best effort per
		// design: one that will not go must not take the project delete down with it,
		// because the project delete is what the user actually asked for.
		foreach ($this->designsBelow($node, 0) as $design) {
			$penpotId = $this->metadata->readFile($design->getId())?->penpotId ?? '';
			if ($penpotId === '') {
				continue;
			}
			try {
				$this->client->deleteFile($penpotId, $token);
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync delete: could not move a design to Penpot\'s trash before its project', [
					'app' => Application::APP_ID,
					'penpot_id' => $penpotId,
					'folder' => $node->getName(),
					'exception' => $e,
				]);
			}
		}

		foreach ($projectIds as $projectId) {
			try {
				$this->client->deleteProject($projectId, $token);
				$this->logger->info('penpot_sync delete: moved a project to Penpot\'s trash', [
					'app' => Application::APP_ID,
					'project_id' => $projectId,
					'folder' => $node->getName(),
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync delete: could not move the project to Penpot\'s trash', [
					'app' => Application::APP_ID,
					'project_id' => $projectId,
					'folder' => $node->getName(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Every project id at or below $folder.
	 *
	 * {@see MembershipResolver} read downwards, and it does NOT stop at a marked
	 * folder the way {@see ProjectFolderService::managedDesignsBelow()} does. That
	 * method is asking *which designs belong to this project*, so a nearer project
	 * ancestor ends the descent. This one is asking *what is about to stop
	 * existing*, and a project nested inside a trashed one is going too.
	 *
	 * @return list<string>
	 */
	private function projectsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$ids = [];
		$markers = $this->metadata->readFolder($folder->getId());
		if ($markers->hasProject()) {
			$ids[] = $markers->projectId;
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync delete: could not list a trashed folder; a project below it may survive', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return $ids;
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->projectsBelow($child, $depth + 1) as $id) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * The HARD step: the user emptied the Nextcloud trash. Destroy the design —
	 * but only if Penpot's own trash still holds it.
	 *
	 * THE LISTING IS READ FIRST, EVERY TIME. See the class docblock: the command
	 * this calls has no safety of its own, so the listing is what turns "destroy
	 * these ids" into "empty this trash".
	 */
	public function onPurged(File $node): void {
		// One guard for both conditions, so the team read below knows the stamp is
		// real. The `?->` form it replaced left every later field possibly-null for
		// a state this line has already ruled out.
		$stamped = $this->metadata->readFile($node->getId());
		if ($stamped === null || $stamped->penpotId === '') {
			return;
		}
		$penpotId = $stamped->penpotId;

		// The team comes off the file's own stamp (§C6.7) rather than the folder
		// tree, because a node being purged lives under files_trashbin and has no
		// mapped ancestor left to walk up to.
		$teamId = $stamped->teamId !== '' ? $stamped->teamId : ($this->resolver->resolve($node)->teamId ?? '');
		if ($teamId === '') {
			$this->logger->warning('penpot_sync purge: no team on the trashed mirror; leaving Penpot alone', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			]);

			return;
		}

		try {
			if (!$this->isInPenpotTrash($teamId, $penpotId)) {
				// Already purged, or restored in Penpot's UI. Either way this is not
				// a design the user just asked to destroy.
				$this->logger->info('penpot_sync purge: design is not in Penpot\'s trash; nothing destroyed', [
					'app' => Application::APP_ID,
					'penpot_id' => $penpotId,
				]);

				return;
			}

			$this->client->permanentlyDeleteFiles($teamId, [$penpotId], $this->personalTokens->tokenForActor());
			$this->logger->info('penpot_sync purge: permanently deleted a design in Penpot', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'team_id' => $teamId,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync purge: could not permanently delete the design', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * A whole project FOLDER was emptied out of the Nextcloud trash, so finish the
	 * delete for every design that went in with it (`projects/purge.feature`).
	 *
	 * Never throws, for the same reason {@see onPurged()} does not: a legacy hook
	 * cannot cleanly abort a purge, and a design left in Penpot's trash is a leak
	 * that expires on Penpot's own schedule — never data loss.
	 *
	 * ## ONE HOOK FIRES, FOR THE FOLDER, SO THE WALK IS OURS
	 *
	 * The same wall as every other folder gesture in this app: nothing is announced
	 * per child, so `preDelete` on `Penpot/Team.d1788…` is all the notice there is
	 * that two projects' worth of designs are about to stop existing. This is
	 * {@see onFolderTrashed()} again, one step further along — that one moves the
	 * projects to Penpot's trash, this one empties it of what they held.
	 *
	 * ## BATCHED PER TEAM, WHICH IS THE ONE PLACE THIS DIVERGES FROM THE FILE PATH
	 *
	 * {@see \OCA\PenpotSync\Service\RestoreService::onFolderRestored()} hands each
	 * design to the ordinary single-file door, and says why. Here that would read
	 * the same team's trash listing once per design and issue one destroy call per
	 * design — for a gesture whose whole nature is a set, against an RPC that takes
	 * an array of ids because Penpot expects sets. So the listing is read once per
	 * team and the intersection destroyed in one call.
	 *
	 * §C6.11'S RULE IS KEPT, and batching is how it is expressed rather than a thing
	 * it costs: `permanently-delete-team-files` has no safety of its own and will
	 * destroy a LIVE design if handed one, so every id must come from a real trash
	 * listing. An intersection IS that check, applied to the whole set at once.
	 */
	public function onFolderPurged(Folder $node): void {
		$byTeam = [];
		foreach ($this->designsBelow($node, 0) as $design) {
			$stamped = $this->metadata->readFile($design->getId());
			if ($stamped === null || $stamped->penpotId === '' || $stamped->teamId === '') {
				// No stamp, or one from before §C6.7 recorded the team: there is no
				// mapped ancestor left to resolve it from under files_trashbin, so
				// nothing can be safely destroyed for it.
				continue;
			}
			$byTeam[$stamped->teamId][$stamped->penpotId] = true;
		}

		// ONE TOKEN FOR THE WHOLE PURGE. It is scoped to the actor, not the team, so
		// fetching it inside the loop was re-reading config and re-running the crypto
		// once per team for an answer that cannot change between iterations.
		$actorToken = $this->personalTokens->tokenForActor();

		foreach ($byTeam as $teamId => $ids) {
			try {
				$parked = [];
				foreach (array_keys($this->client->recoverableFileIds($teamId)) as $id) {
					if (isset($ids[$id])) {
						$parked[] = $id;
					}
				}
				if ($parked === []) {
					// Already destroyed, or restored in Penpot while the folder sat in
					// the trash. Either way there is nothing here the user just asked
					// to destroy.
					continue;
				}

				$this->client->permanentlyDeleteFiles($teamId, $parked, $actorToken);
				$this->logger->info('penpot_sync purge: permanently deleted a trashed project\'s designs', [
					'app' => Application::APP_ID,
					'team_id' => $teamId,
					'designs' => count($parked),
					'folder' => $node->getName(),
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync purge: could not permanently delete a trashed project\'s designs', [
					'app' => Application::APP_ID,
					'team_id' => $teamId,
					'folder' => $node->getName(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Every `.penpot` below a folder, through nested project folders and all.
	 *
	 * THROUGH them, not stopping at them, and that matches {@see projectsBelow()}
	 * exactly: a project nested inside a purged one is being destroyed too, so its
	 * designs are as much part of this gesture as the top folder's.
	 *
	 * @return list<File>
	 */
	private function designsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$out = [];
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync purge: could not read a purged folder; a design below it may survive', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return [];
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->designsBelow($child, $depth + 1) as $design) {
					$out[] = $design;
				}
				continue;
			}
			if ($child instanceof File && str_ends_with($child->getName(), PullService::EXTENSION)) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/** Is this design still recoverable from the team's Penpot trash right now? */
	private function isInPenpotTrash(string $teamId, string $penpotId): bool {
		return isset($this->client->recoverableFileIds($teamId)[$penpotId]);
	}
}
