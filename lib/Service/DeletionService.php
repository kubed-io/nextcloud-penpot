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
 * (`delete.feature`, saga §C6.11).
 *
 * ## EACH GESTURE GETS THE OPERATION WITH THE SAME REVERSIBILITY
 *
 *     Nextcloud delete (→ NC trash)  →  delete-file  (→ Penpot trash, ~7 days)
 *     Nextcloud purge  (empty trash) →  permanently-delete-team-files
 *
 * That symmetry is the whole design. `delete.feature` used to say a local delete
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
		$projectIds = $this->projectsBelow($node, 0);
		if ($projectIds === []) {
			// A plain folder that names no project — an ordinary folder inside a
			// mapping, which is exactly what a mapped folder must stay usable as.
			return;
		}

		$token = $this->personalTokens->tokenForActor();
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

	/** Is this design in the team's Penpot trash right now? */
	private function isInPenpotTrash(string $teamId, string $penpotId): bool {
		foreach ($this->client->deletedFiles($teamId) as $file) {
			if (($file['id'] ?? null) === $penpotId) {
				return true;
			}
		}

		return false;
	}
}
