<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Taking a mirror back out of the Nextcloud trash, and putting the design back
 * with it (`delete.feature`, `restore.feature`, saga §6.52).
 *
 * ## THE INVERSE OF {@see DeletionService}, GESTURE FOR GESTURE
 *
 *     Nextcloud delete  (→ NC trash)  →  delete-file                   (→ Penpot trash)
 *     Nextcloud restore (← NC trash)  →  restore-deleted-team-files    (← Penpot trash)
 *     Nextcloud purge   (empty trash) →  permanently-delete-team-files (irreversible)
 *
 * That symmetry is the whole design, and until this class existed it had a hole
 * in the middle: restoring a mirror put the file back in its folder while the
 * design stayed in Penpot's trash, so the next pull — seeing a design Penpot no
 * longer names — pruned the mirror again. Nothing was lost, but the file
 * appeared to delete itself a second time. `delete.feature` carried that as a
 * stated KNOWN GAP; this closes it.
 *
 * ## THREE LAYERS, CHEAPEST AND MOST LOSSLESS FIRST (saga §6.52)
 *
 * "Restore" means genuinely different things depending on what survived, and
 * conflating them would be a lie to the user:
 *
 *   1. **The design still exists in Penpot** — nothing was ever lost remotely.
 *      Penpot is not contacted at all; taking the file out of the trash IS the
 *      whole restore.
 *   2. **The design is in Penpot's own trash** (~7 days) — `restore-deleted-team-files`
 *      brings it back with its **id, revision, history and deep links intact**
 *      (§6.49, re-confirmed §C6.11). Lossless, and always preferred.
 *   3. **The design is gone for good** — the grace window closed, or someone
 *      purged it. All that is left is the archive in a `sync` mirror, and
 *      importing it mints a NEW id (§6.20: a purged id cannot be resurrected,
 *      tested directly). That is `restore.feature`'s territory and is **NOT
 *      BUILT YET** — so this class says so, in the log, naming what it would
 *      cost. It never quietly does nothing.
 *
 * ## `end` IS NOT SUCCESS. THE IDS ARE, AND THEN WE RE-READ ANYWAY.
 *
 * Two separate confirmations, because there are two separate ways this call has
 * been seen to lie:
 *
 *   - §C6.11: an id that is not in the trash gets **200 with an empty `end` set**
 *     — no error. So {@see PenpotClient::restoreDeletedFiles()} returns the ids
 *     Penpot claims, and a claim that omits ours is a failure.
 *   - §6.49: the `end` event once arrived while `deleted_at` was still set. That
 *     did not reproduce on 2.17.0, but one non-reproduction does not disprove a
 *     race — it is exactly the shape of thing that comes back under load. So the
 *     trash listing is read again afterwards, at the cost of one cheap call.
 *
 * Telling someone their work is back when it is not is worse than an error,
 * because they stop looking for it.
 *
 * ## FAILURE NEVER TOUCHES THE LOCAL FILE
 *
 * Same rule as the delete path (§6.18 rule 3): the Nextcloud restore has already
 * happened by the time this runs, and the user's file is theirs. A Penpot that
 * is down leaves a mirror whose design is still in Penpot's trash — visible,
 * recoverable, and fixed by restoring again once it is back. Undoing the user's
 * restore to "stay consistent" would be strictly worse.
 */
final class RestoreService {
	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly PersonalTokenService $personalTokens,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * A mirror just came back out of the Nextcloud trash. Put its design back too,
	 * by whichever layer applies.
	 *
	 * Never throws: every failure is logged and swallowed, for the reason in the
	 * class docblock — the local restore has already happened.
	 */
	public function onRestored(File $node): void {
		$stamped = $this->metadata->readFile($node->getId());
		$penpotId = $stamped?->penpotId ?? '';
		if ($penpotId === '') {
			// Untracked, or a mirror whose stamp was cleared. Nothing in Penpot
			// answers to this file, and inventing something for it to answer to is
			// team-import.feature's still-open fork, not a restore.
			return;
		}

		// The team comes off the file's own stamp first (§C6.7). Unlike the purge —
		// which has no mapped ancestor left to walk up to — a restored node IS back
		// in its folder, so the resolver is a real fallback here rather than a
		// formality, and it covers a mirror stamped before §C6.7 added the key.
		$teamId = $stamped->teamId !== '' ? $stamped->teamId : ($this->resolver->resolve($node)->teamId ?? '');
		if ($teamId === '') {
			$this->logger->warning('penpot_sync restore: no team on the restored mirror; leaving Penpot alone', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			]);

			return;
		}

		try {
			if (!$this->isInPenpotTrash($teamId, $penpotId)) {
				// Layer 1 or layer 3 — either way there is nothing for the restore
				// command to do, and which one it is decides what the user is told.
				$this->reportUnrestorable($node, $penpotId);

				return;
			}

			$this->restoreInPenpot($node, $teamId, $penpotId);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync restore: could not bring the design back in Penpot', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Layer 2: the design is in Penpot's trash. Bring it back, then prove it.
	 *
	 * Both confirmations live here rather than in the client, because "did the
	 * user get their design back" is a question about this gesture, not about the
	 * RPC. See the class docblock for why there are two of them.
	 *
	 * @throws PenpotApiException
	 */
	private function restoreInPenpot(File $node, string $teamId, string $penpotId): void {
		$restored = $this->client->restoreDeletedFiles($teamId, [$penpotId], $this->personalTokens->tokenForActor());

		if (!in_array($penpotId, $restored, true)) {
			// §C6.11: 200, an `end` event, and an empty set. The stream succeeded
			// and the work did not happen.
			$this->logger->warning(
				'penpot_sync restore: Penpot reported success but did not restore the design; '
				. 'the mirror is back in Nextcloud, the design is still in Penpot\'s trash',
				[
					'app' => Application::APP_ID,
					'penpot_id' => $penpotId,
					'restored' => $restored,
					'file' => $node->getName(),
				],
			);

			return;
		}

		if ($this->isInPenpotTrash($teamId, $penpotId)) {
			// §6.49's lying restore. Did not reproduce on 2.17.0; the check stays.
			$this->logger->warning(
				'penpot_sync restore: the design is still in Penpot\'s trash after a restore that '
				. 'reported success; re-read confirms it did not take effect',
				[
					'app' => Application::APP_ID,
					'penpot_id' => $penpotId,
					'file' => $node->getName(),
				],
			);

			return;
		}

		$this->logger->info('penpot_sync restore: brought a design back out of Penpot\'s trash, losslessly', [
			'app' => Application::APP_ID,
			'penpot_id' => $penpotId,
			'team_id' => $teamId,
			'file' => $node->getName(),
			// Its containing project comes back with it — Penpot clears `deleted_at`
			// on the project as well as the file, so the project folder reappears on
			// the next pull without a second call.
		]);
	}

	/**
	 * The design is not in Penpot's trash. Say which of the two reasons it is.
	 *
	 * This costs one project listing, and only on the uncommon path — the ordinary
	 * trash-then-restore round trip goes through layer 2 and never gets here. It
	 * buys the difference between "nothing to do, you are already whole" and "the
	 * design is gone and this file is now the only copy", which are not remotely
	 * the same message.
	 */
	private function reportUnrestorable(File $node, string $penpotId): void {
		$projectId = $this->resolver->resolve($node)->projectId;

		if ($projectId !== null && $this->isInProject($projectId, $penpotId)) {
			// Layer 1. The mirror was trashed while Penpot was unreachable, or
			// someone restored the design in Penpot's own UI first. Nothing to send.
			$this->logger->info('penpot_sync restore: the design still exists in Penpot; nothing to restore', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			]);

			return;
		}

		// Layer 3, and it is not built. Stated rather than swallowed: the archive
		// this file may hold is now the only copy of that design, and re-importing
		// it would mint a new id (§6.20). See restore.feature.
		$this->logger->warning(
			'penpot_sync restore: the design is gone from Penpot and is not in its trash — the '
			. 'grace window has closed or it was permanently deleted. The mirror is back in '
			. 'Nextcloud, but nothing was restored in Penpot; re-importing the archive is not '
			. 'built yet and would create a design with a NEW id.',
			[
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			],
		);
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

	/** Is this design a live file of that project — i.e. never actually gone? */
	private function isInProject(string $projectId, string $penpotId): bool {
		foreach ($this->client->getProjectFiles($projectId) as $file) {
			if (($file['id'] ?? null) === $penpotId) {
				return true;
			}
		}

		return false;
	}
}
