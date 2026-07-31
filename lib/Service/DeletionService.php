<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\File;
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
 */
final class DeletionService {
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
