<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * The last third of the delete story: a design destroyed in Penpot purges its
 * trashed mirror (`designs/purge.feature`).
 *
 *     mirror purged from the Nextcloud trash → the design is destroyed in Penpot
 *                                              ({@see DeletionService::onPurged})
 *     design destroyed in Penpot             → its trashed mirror is purged (here)
 *
 * A mirror belongs in the Nextcloud trash only while there is a design in Penpot
 * for it to be a mirror OF. Trashing and deleting are already the same gesture seen
 * from two sides, and restoring and un-trashing are already each other's undo; the
 * trash itself was the piece that only worked one way.
 *
 * ## WHY A TRASHED MIRROR IS REACHED INTO AT ALL
 *
 * Once Penpot has destroyed the design, the trashed file is the last copy of it in
 * existence, so deleting it on a schedule sounds like the most destructive thing
 * this app could do. It is not, because of what the trigger is: destroying a design
 * in Penpot is the second, deliberate half of a two-step delete, by someone who
 * already trashed it once — the same gesture Nextcloud spells "empty the trash",
 * which this app has always answered by destroying the design.
 * `features/AGENTS.md#a-design-destroyed-in-penpot-purges-its-trashed-mirror`
 * carries the argument in full.
 *
 * The constraint that comes with it: **never guess.**
 * {@see isGone()} refuses to purge unless every source agrees the design is gone,
 * and an unreachable Penpot is not agreement.
 *
 * ## WHAT IT WILL NOT TOUCH
 *
 *   - a trash entry with no `penpot_id` — never ours, never was
 *   - a mirror stamped with a DIFFERENT team — that mapping's own pull will judge it
 *   - a mirror stamped before `penpot_team_id` existed (§C6.7), which cannot be
 *     attributed to a mapping at all
 *   - anything whose mode is not `sync`: an `unmapped` file left its mapping and its
 *     design is not this app's business any more (`purge.feature` says the same of
 *     the user-driven purge), and a `link` is never trashed in the first place
 *   - anything at all while the answer from Penpot is uncertain
 *
 * ## THE EXISTENCE CHECK IS USUALLY FREE
 *
 * The pull has just listed every project in the team, so `$seen` is a ready-made
 * "still live here" set, and the team's trash listing is one call the prune wanted
 * anyway. Between them they answer the two ordinary states — the design is back, or
 * the design is still in Penpot's trash — without a single extra call.
 *
 * Only an id in NEITHER list needs asking about, and it needs asking rather than
 * assuming: absent from both means "destroyed OR moved to a team we do not mirror",
 * and those two must not share an outcome.
 */
final class TrashReconcileService {
	/**
	 * How long to wait before asking a second time, for a mirror about to be reaped.
	 *
	 * ## PENPOT'S RESTORE RETURNS BEFORE ITS TRANSACTION SETTLES (§6.49, §C6.15)
	 *
	 * That is recorded, reproduced, and the reason {@see RestoreService} confirms
	 * everything twice. Inside that window a design being restored reads as GONE from
	 * all three sources at once: the project listing does not name it yet, the trash
	 * listing has already stopped naming it, and `get-file-summary` answers NOT-FOUND
	 * because `deleted_at` is still set. Every check this class makes agrees, and
	 * every one of them is wrong.
	 *
	 * Which is not hypothetical here: a design restored in Penpot is followed by a
	 * pull carrying this pass, so the window is one the app walks into on purpose.
	 * Reaping inside it would destroy the last copy of a design somebody had just
	 * asked to have back — the single worst outcome this app can produce.
	 *
	 * So the answer is asked again after the window has passed, and only agreement
	 * counts. Paid ONCE PER CANDIDATE and only by a mirror already judged gone, which
	 * in the steady state is never: a pull whose trash holds nothing to reap never
	 * waits at all.
	 */
	private const SETTLE_MICROSECONDS = 2_000_000;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly TrashControl $trash,
		private readonly StorageService $storage,
		private readonly LoggerInterface $logger,
		// Injectable ONLY so the unit suite need not pay it: its Penpot is a mock
		// with no transaction to settle. Nextcloud's container falls back to this
		// default, so it needs no registration, and nothing in production should pass
		// anything else.
		private readonly int $settleMicroseconds = self::SETTLE_MICROSECONDS,
	) {
	}

	/**
	 * Purge $mapping's trashed mirrors whose design Penpot no longer has, and return
	 * how many were destroyed.
	 *
	 * WHOSE TRASH IT LOOKS IN is the sync actor's — the same user the pull writes
	 * through. That is the right and the only available answer: a pull has no
	 * session, and the actor is by construction a member of every Team Folder this
	 * app manages and the owner of every admin-folder mapping, so their
	 * `listTrashRoot` covers exactly the folders a mapping can write into. Who did
	 * the deleting does not matter — a Team Folder's trash belongs to the folder.
	 *
	 * ALREADY UNDER THE SYNC GUARD, because {@see PullService::pullOne()} raises it
	 * around the whole pass. That matters here rather than being incidental: the home
	 * trash's purge fires the legacy `preDelete` hook and {@see \OCA\PenpotSync\Listener\TrashPurgeHook}
	 * would answer it by destroying the design in Penpot — the thing that is already
	 * gone. Harmless in itself, but it would put a "destroying the design" line in
	 * the log for a purge doing the exact opposite, and this app has lost days to
	 * trash diagnostics that said the wrong thing.
	 *
	 * Never throws. A trash reconcile that fails must not take down the pull that
	 * carried it; the mirrors are still there and the next tick tries again.
	 *
	 * @param array<string, bool> $seen every design id Penpot named for this mapping
	 *                                  during the pull that is calling — already
	 *                                  proof of existence for everything in it
	 */
	public function reap(Mapping $mapping, array $seen): int {
		if ($mapping->mode !== Mapping::MODE_SYNC) {
			// A link is never trashed, so a link mapping has no trashed mirrors to
			// judge — and reading a whole trash to find that out every pull is a cost
			// with no case behind it.
			return 0;
		}

		try {
			$uid = $this->storage->resolveActorUid();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync trash: no sync actor, so no trash to reconcile', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);

			return 0;
		}

		$mirrors = $this->mirrors($uid, $mapping);
		if ($mirrors === []) {
			// The overwhelmingly common case, and it costs nothing past the listing:
			// no trashed mirrors means no reason to ask Penpot anything at all.
			return 0;
		}

		$parked = $this->parkedIds($mapping->teamId);
		if ($parked === null) {
			// A TRASH LISTING WE COULD NOT READ SPARES EVERY MIRROR. Without it, "not
			// in Penpot's trash" is unprovable, and that sentence is the whole
			// difference between a design someone can still get back and one nobody
			// can. Same discipline as {@see PullService::prune()}.
			return 0;
		}

		$purged = 0;
		foreach ($mirrors as $penpotId => $trashed) {
			if (!$this->isGone($penpotId, $mapping->teamId, $seen, $parked)) {
				continue;
			}

			try {
				$trashed->purge();
				$purged++;
				$this->logger->info('penpot_sync trash: purged a mirror whose design Penpot no longer has', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'penpot_id' => $penpotId,
					'mapping' => $mapping->id,
				]);
			} catch (\Throwable $e) {
				// A member without delete permission on the Team Folder, a backend that
				// refused: leave the entry alone and say so. It is still recoverable,
				// which is the failure direction to prefer.
				$this->logger->warning('penpot_sync trash: could not purge a trashed mirror', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'penpot_id' => $penpotId,
					'exception' => $e,
				]);
			}
		}

		return $purged;
	}

	/**
	 * $mapping's trashed `sync` mirrors in $uid's trash, keyed by the design each
	 * one mirrors.
	 *
	 * The NAME is tested before the metadata because it costs nothing and answers
	 * almost everything: this is a whole user's trash, and the overwhelming majority
	 * of what is in it has never had anything to do with this app. Only the entries
	 * that look like ours cost a query.
	 *
	 * TWO MIRRORS OF ONE DESIGN CANNOT BOTH BE KEYED, and the later one wins. That
	 * needs the same design mirrored twice in one mapping AND both copies trashed,
	 * and either survivor is a correct answer — the loser stays in the trash for the
	 * next pull, which keys it once the winner is out.
	 *
	 * @return array<string, TrashedFile>
	 */
	private function mirrors(string $uid, Mapping $mapping): array {
		$index = [];
		foreach ($this->trash->listTrashed($uid) as $trashed) {
			if (!str_ends_with($trashed->name, PullService::EXTENSION)) {
				continue;
			}

			$stamped = $this->metadata->readFile($trashed->fileId);
			if ($stamped === null || !$stamped->isSync() || $stamped->penpotId === '') {
				continue;
			}
			// THE TEAM IS THE ONLY LINK BACK TO A MAPPING a trashed file still has —
			// its path is gone, so nothing can be resolved from where it sat. A mirror
			// stamped before §C6.7 added the key carries no team and is therefore never
			// attributed, which is the safe direction: unattributed means unreaped.
			if ($stamped->teamId !== $mapping->teamId) {
				continue;
			}

			$index[$stamped->penpotId] = $trashed;
		}

		return $index;
	}

	/**
	 * Is $penpotId really gone from Penpot — not live, not in its trash, GONE?
	 *
	 * Answers **false whenever it cannot tell**, and that asymmetry is the safety
	 * property of this whole class. A wrong "no" leaves a trash entry the next pull
	 * looks at again; a wrong "yes" destroys the last copy of somebody's design.
	 *
	 * The three sources, cheapest first:
	 *
	 *   1. **`$seen`** — the pull just listed every project in this team. In it means
	 *      the design is live, which is the "someone restored it in Penpot" case.
	 *   2. **the team's trash listing** — in it means Penpot still holds the design
	 *      recoverably, so the mirror is still a mirror of something.
	 *   3. **{@see PenpotClient::fileExists()}** — for an id in neither. This is the
	 *      call that separates DESTROYED from MOVED-TO-AN-UNMAPPED-TEAM, and it is
	 *      three-valued on purpose: `null` means the probe could not tell, and that
	 *      spares the entry exactly as a `true` does.
	 *
	 * Step 3 could not do the job alone, and the reason is worth stating: Penpot's
	 * `get-file-summary` answers NOT-FOUND for a design that is merely in the trash
	 * — `db/get` drops any row with a `deleted-at` on it, past or future — so on its
	 * own it reads a perfectly recoverable design as gone. Step 2 is what makes step
	 * 3 safe to believe, and step 2 works because `get-team-deleted-files` filters
	 * `deleted_at > now`: a destroyed design leaves that listing at once, a trashed
	 * one stays for the week.
	 *
	 * ## AND THEN IT ASKS AGAIN, because all three can be wrong together
	 *
	 * See {@see SETTLE_MICROSECONDS}. A design mid-restore satisfies every check
	 * above. Only the second look, after the window, tells that apart from a design
	 * that is really gone.
	 *
	 * @param array<string, bool> $seen ids Penpot named for this mapping's projects
	 * @param array<string, bool> $parked ids in this team's Penpot trash
	 */
	private function isGone(string $penpotId, string $teamId, array $seen, array $parked): bool {
		if (isset($seen[$penpotId]) || isset($parked[$penpotId])) {
			return false;
		}
		if ($this->client->fileExists($penpotId) !== false) {
			return false;
		}

		if ($this->settleMicroseconds > 0) {
			usleep($this->settleMicroseconds);
		}

		// A ZERO SETTLE STILL RE-READS, it just does not wait between the two: the
		// unit suite injects 0 because its Penpot is a mock with nothing in flight,
		// and "the answer is confirmed twice" is the behaviour those tests pin.
		// Skipping the second read at zero would quietly reduce it to one and let a
		// service that cannot survive the window pass them.
		$parkedAgain = $this->parkedIds($teamId);
		if ($parkedAgain === null || isset($parkedAgain[$penpotId])) {
			return false;
		}

		return $this->client->fileExists($penpotId) === false;
	}

	/**
	 * Every id in this team's Penpot trash, or null when the listing could not be
	 * read — which is a different answer from "the trash is empty" and must stay so.
	 *
	 * @return array<string, bool>|null
	 */
	private function parkedIds(string $teamId): ?array {
		try {
			$ids = [];
			foreach ($this->client->deletedFiles($teamId) as $file) {
				$id = $file['id'] ?? null;
				if (is_string($id) && $id !== '') {
					$ids[$id] = true;
				}
			}

			return $ids;
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync trash: could not read Penpot\'s trash; sparing every trashed mirror', [
				'app' => Application::APP_ID,
				'team_id' => $teamId,
				'exception' => $e,
			]);

			return null;
		}
	}
}
