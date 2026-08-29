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
 *      destroyed it. All that is left is the archive in a `sync` mirror, so the
 *      archive is IMPORTED into the project the file came back into and the
 *      restore finishes ({@see ImportService}). The new design necessarily wears
 *      a NEW id (§6.20: a purged id cannot be resurrected, tested directly),
 *      which the spec states rather than hides.
 *
 * Layer 3 used to stop at a log line and a bell entry saying the design was gone.
 * `features/AGENTS.md#a-restore-into-a-mapping-imports-what-penpot-no-longer-has`
 * has the reversal in full; the short version is that the file lands back INSIDE
 * A MAPPING, and an archive arriving inside a mapping is an import whatever
 * gesture carried it (§6.33) — refusing here was the one arrival that left a
 * mapped folder holding a design Penpot had never heard of.
 *
 * ## `end` IS NOT SUCCESS. THE IDS ARE. AND THE IDS ARE NOT SUCCESS EITHER.
 *
 * This command has been caught reporting work it did not do in two unrelated
 * ways, so it takes two confirmations to believe it:
 *
 *   - §C6.11: an id that is not in the trash gets **200 with an empty `end` set**
 *     — no error. So {@see PenpotClient::restoreDeletedFiles()} returns the ids
 *     Penpot claims, and a claim that omits ours is a failure.
 *   - §6.49: **the SSE returns before the transaction settles**, so the `end`
 *     event can arrive while `deleted_at` is still set. A second call cleared it.
 *
 * §C6.11 failed to reproduce the second one and this class's first draft took
 * that as licence to check something cheaper — "is it out of the trash?" — which
 * sounds equivalent and is not. See {@see isListedAgain()}: the two listings
 * disagree inside that window, and the integration suite duly failed on the one
 * scenario the whole slice exists for, about half the time. §6.49's remedy was
 * always a second call; it is now what this class does.
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
	/**
	 * How long a confirmed restore must survive before it is believed.
	 *
	 * ## MEASURED AGAINST A LIVE PENPOT, TWICE — AND THE FIRST MEASUREMENT WAS SHORT
	 *
	 * This was 2 500 000 (2.5s), from an observation that "the delete landed
	 * between one and two seconds after `delete-file` answered". That number was
	 * taken from CI log timings; a direct probe against a live Penpot says it is
	 * too small, and the gap is exactly the flake.
	 *
	 * What `delete-file` actually does: it answers immediately, lists the design in
	 * the trash within ~0.1–0.3s, and then — about **3.8 seconds later** — runs a
	 * delayed job that removes the file AGAIN, even if it was restored in the
	 * meantime. Measured by issuing the restore at three different gaps after the
	 * delete:
	 *
	 *   gap 0.0s → undone 5/5, at +3.0…3.9s after the restore
	 *   gap 1.0s → undone 5/5, at +2.5…3.0s
	 *   gap 2.0s → undone 5/5, at +1.7…1.9s
	 *
	 * The undo lands at a fixed ~3.8s after the DELETE regardless of when the
	 * restore happened, so it is a scheduled job rather than a race — and it is
	 * 100% reproducible, not occasional.
	 *
	 * At 2.5s this class therefore confirmed the restore BEFORE the undo could
	 * arrive, logged "losslessly", and returned. The design vanished a second
	 * later and the next pull trashed the mirror — the exact bug this slice exists
	 * to prevent, reported as a success.
	 *
	 * Six seconds covers the whole observed window with margin. Waiting it out
	 * before restoring instead is NOT the fix: a 4s pre-delay still failed 2 of 6,
	 * and it would delay every restore rather than only the ones at risk. Issuing
	 * the second restore AFTER the undo is durable — 6 of 6 — which is what the
	 * retry in {@see restoreInPenpot()} already does, and all it needed was to be
	 * told the truth about whether the first one held.
	 *
	 * ## THE COST, AND THE OPTIMISATION NOT TAKEN
	 *
	 * Every restore now pays this window, and one that gets undone pays it twice —
	 * measured at 8–9s end to end against a live Penpot, inside the WebDAV request.
	 * That is the price of not lying about the outcome, and restoring from the
	 * trash is a deliberate, occasional gesture rather than a hot path.
	 *
	 * It could be skipped when the delete is OLD, because the delayed job has long
	 * since fired — a user restoring something they trashed yesterday is never at
	 * risk. `get-team-deleted-files` even carries `willBeDeletedAt`, and the delete
	 * instant is that minus Penpot's grace period, so the age is computable today.
	 * It is not used because the grace period is a SERVER SETTING (7 days on this
	 * instance, `deletion-delay`), and reading it wrong shortens the wait — the
	 * unsafe direction. Recording the deletion time ourselves when
	 * {@see DeletionService} makes the call would be exact and is the better fix;
	 * it is more moving parts than this bug needs.
	 */
	private const SETTLE_MICROSECONDS = 6_000_000;

	/**
	 * How often to re-ask while waiting out the undo window.
	 *
	 * POLLED, NOT SLEPT: the undo is what we are looking for, so seeing it early
	 * ends the wait early and the second restore goes out sooner. Only a restore
	 * that is genuinely durable pays the full window.
	 */
	private const SETTLE_POLL_MICROSECONDS = 250_000;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly PersonalTokenService $personalTokens,
		private readonly ImportService $imports,
		private readonly SyncNotifier $notifier,
		private readonly LoggerInterface $logger,
		// Injectable ONLY so the unit tests need not pay it. The settle is a real
		// wait on a real Penpot; a suite whose Penpot is a mock has nothing to wait
		// for. Nextcloud's container falls back to this default, so it needs no
		// registration — and nothing in production should pass anything else.
		private readonly int $settleMicroseconds = self::SETTLE_MICROSECONDS,
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
		// Both conditions in one guard, so what follows knows `$stamped` is real —
		// the `?->` form reads more neatly and then costs a possibly-null on every
		// field below it, which is how DeletionService acquired two static-analysis
		// findings for a state it had already ruled out.
		$stamped = $this->metadata->readFile($node->getId());
		if ($stamped === null || $stamped->penpotId === '') {
			// Untracked, or a mirror whose stamp was cleared. Nothing in Penpot
			// answers to this file, and inventing something for it to answer to is
			// mapping/sync-now.feature's still-open fork, not a restore.
			return;
		}
		$penpotId = $stamped->penpotId;

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
				// command to do, and which one it is decides what happens instead.
				$this->settleOutsideThePenpotTrash($node, $teamId, $penpotId);

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
		$actor = $this->personalTokens->tokenForActor();
		$restored = $this->client->restoreDeletedFiles($teamId, [$penpotId], $actor);

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

		if ($this->staysListed($node, $teamId, $penpotId)) {
			$this->logger->info('penpot_sync restore: brought a design back out of Penpot\'s trash, losslessly', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'team_id' => $teamId,
				'file' => $node->getName(),
				// Its containing project comes back with it — Penpot clears `deleted_at`
				// on the project as well as the file, so the project folder reappears on
				// the next pull without a second call.
			]);

			return;
		}

		// §6.49, REPRODUCED — AND ITS REMEDY IS A SECOND CALL, NOT A COMPLAINT.
		//
		// "The SSE returns before the transaction settles. A second call cleared
		// it." That is the saga's own live transcript, and §C6.11's later
		// non-reproduction persuaded this method's first draft to keep only a
		// token check. The integration suite then failed on the one scenario the
		// whole slice exists for, about half the time: the restore reported the
		// id, the design stayed unlisted, and the next pull pruned the mirror all
		// over again — the exact bug this slice was built to close.
		$this->client->restoreDeletedFiles($teamId, [$penpotId], $actor);

		if ($this->staysListed($node, $teamId, $penpotId)) {
			$this->logger->info('penpot_sync restore: the design came back on a second call (saga §6.49)', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'team_id' => $teamId,
				'file' => $node->getName(),
			]);

			return;
		}

		$this->logger->warning(
			'penpot_sync restore: Penpot reported the design restored twice and it is still not listed; '
			. 'the mirror is back in Nextcloud but a pull may trash it again',
			[
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
			],
		);
	}

	/**
	 * Is the design back in the listing **the pull reads**?
	 *
	 * ## THE ORACLE MATTERS MORE THAN THE CHECK, AND THIS IS THE SECOND ORACLE
	 *
	 * The first draft asked `get-team-deleted-files` — "is it out of the trash?"
	 * — which sounds equivalent and is not. Penpot's restore returns before its
	 * transaction settles (§6.49), and in that window the trash listing can
	 * already have stopped naming the design while `get-project-files` still
	 * omits it. Every other decision in this app is made from the project
	 * listing: {@see PullService} builds its seen-set from it and trashes any
	 * mirror missing from it. Confirming against anything else confirms nothing
	 * that matters.
	 *
	 * So this asks the same question the pull will ask, a few seconds earlier.
	 *
	 * The project id comes from the folder the mirror was restored into, exactly
	 * as membership is resolved everywhere else (§6.29). A mirror at the team
	 * root is in that team's **Drafts** — a real project with no folder of its
	 * own (§6.35) — so its id is read off the team's default project.
	 */
	/**
	 * Listed — and STILL listed once Penpot has stopped changing its mind.
	 *
	 * ## ONE CONFIRMATION WAS NOT A CONFIRMATION (§C6.15, finally explained)
	 *
	 * {@see isListedAgain()} answers "is it back *now*", and the pull one second
	 * later kept disagreeing with it: two `get-project-files` calls for the same
	 * project, seconds apart, returning different answers. That was recorded as an
	 * unexplained defect and tagged `@todo` in `designs/restore.feature`.
	 *
	 * The RPC trace explains it. `delete-file` does not finish when it answers —
	 * Penpot lands the deletion asynchronously, a beat later. A restore issued
	 * inside that beat is confirmed against a listing the pending delete has not
	 * reached yet, and is then overwritten by it. The design goes back into the
	 * trash after we reported it restored, and the next pull, seeing a design
	 * Penpot no longer names, trashes the mirror all over again.
	 *
	 * It also explains why the sibling scenario passed while this one failed: that
	 * one happened to need a second restore call, which landed AFTER the delete had
	 * settled and therefore stuck. The bug was never about the second call — it was
	 * about WHEN the last call lands, which is why "call twice" fixed it by
	 * accident and only sometimes.
	 *
	 * So the question is not "is it listed" but "is it listed and does it stay
	 * listed", and the caller retries the restore when it does not. This is the
	 * same discipline as the rest of the class — success is not proof of success —
	 * applied to a write that can be undone by something already in flight.
	 */
	private function staysListed(File $node, string $teamId, string $penpotId): bool {
		if (!$this->isListedAgain($node, $teamId, $penpotId)) {
			return false;
		}

		// A zero settle still RE-READS, it just does not wait between the two: the
		// unit suite injects 0 because its Penpot is a mock with no in-flight
		// delete to outlast, and "confirmation is two reads" is the behaviour those
		// tests are pinning. Returning true here instead would quietly reduce it to
		// one read and let a service that cannot detect an undo pass them.
		if ($this->settleMicroseconds <= 0) {
			return $this->isListedAgain($node, $teamId, $penpotId);
		}

		// WATCH FOR THE UNDO, rather than sleeping through it and asking once at
		// the end. Same total worst case, but a restore that gets undone is
		// detected the moment it happens instead of at the end of the window — and
		// the answer is the same either way, because once the delayed delete has
		// removed the file it does not put it back.
		//
		// This runs in the WebDAV request that took the file out of the trash, and
		// only ever when a design really was restored. It is the one slow path in
		// the app, and it is slow on purpose: the alternative is telling the user
		// their design is back when it is about to disappear.
		// Both operands float, deliberately: `microtime(true)` is a float and an
		// int/int division is int|float, which Psalm's strict binary operands mode
		// refuses to mix. Casting here rather than suppressing keeps the arithmetic
		// honest — a settle of 500_000 really is half a second, not zero.
		$deadline = microtime(true) + ((float)$this->settleMicroseconds / 1_000_000.0);
		do {
			usleep(self::SETTLE_POLL_MICROSECONDS);

			if (!$this->isListedAgain($node, $teamId, $penpotId)) {
				return false;
			}
		} while (microtime(true) < $deadline);

		return true;
	}

	private function isListedAgain(File $node, string $teamId, string $penpotId): bool {
		$projectId = $this->projectFor($node, $teamId);
		if ($projectId === null) {
			// Nothing to ask. Fall back to the weaker oracle rather than reporting a
			// failure we have no evidence for — the restore may well have worked.
			return !$this->isInPenpotTrash($teamId, $penpotId);
		}

		return $this->isInProject($projectId, $penpotId);
	}

	/**
	 * Which Penpot project this mirror's design should be listed in.
	 *
	 * The folder it was restored into names it, exactly as membership is resolved
	 * everywhere else (§6.29). A mirror at the team ROOT is in that team's
	 * **Drafts** — a real project with no folder of its own (§6.35) — so the
	 * fallback is the team's default project, not "no project".
	 */
	private function projectFor(File $node, string $teamId): ?string {
		$projectId = $this->resolver->resolve($node)->projectId;
		if ($projectId !== null && $projectId !== '') {
			return $projectId;
		}

		return $this->draftsProjectFor($teamId);
	}

	/**
	 * The team's default project — Drafts. `get-all-projects`, never
	 * `get-projects`, which does not filter soft-deleted projects (§6.42).
	 */
	private function draftsProjectFor(string $teamId): ?string {
		foreach ($this->client->getAllProjects() as $project) {
			$isDefault = filter_var($project['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN);
			if ($isDefault && ($project['team-id'] ?? null) === $teamId && is_string($project['id'] ?? null)) {
				return $project['id'];
			}
		}

		return null;
	}

	/**
	 * The design is not in Penpot's trash. Tell the two reasons apart and act.
	 *
	 * This costs one project listing, and only on the uncommon path — the ordinary
	 * trash-then-restore round trip goes through layer 2 and never gets here. It
	 * buys the difference between "nothing to do, you are already whole" (layer 1)
	 * and "there is no design left, so make one from the archive" (layer 3), which
	 * are not remotely the same act.
	 */
	private function settleOutsideThePenpotTrash(File $node, string $teamId, string $penpotId): void {
		// The SAME resolution layer 2 confirms with, Drafts fallback and all. An
		// earlier version asked the resolver alone, which reads `null` for a mirror
		// at the team root — and then told the user their perfectly healthy Drafts
		// design was gone forever (§6.35: the team root IS a project, it just has
		// no folder).
		$projectId = $this->projectFor($node, $teamId);

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

		// LAYER 3, AND IT FINISHES THE RESTORE RATHER THAN REPORTING IT UNFINISHED.
		//
		// The file is back inside a mapping and it holds the archive, so this is the
		// §6.33 import — the same act `move.feature` performs for an arrival whose id
		// names nothing. `adopt()` re-stamps the file with the id that comes back;
		// nothing here has to clear the dead one first.
		//
		// $projectId can only be null when the team has no default project, which
		// means the resolver could not name anywhere to put it. Importing into a
		// guess is worse than not importing.
		if ($projectId !== null && $this->imports->adopt($node, $projectId, $teamId) !== null) {
			$this->logger->info('penpot_sync restore: Penpot had nothing left to restore, so the archive was imported', [
				'app' => Application::APP_ID,
				'was' => $penpotId,
				'project' => $projectId,
				'file' => $node->getName(),
			]);

			return;
		}

		// NOTHING TO IMPORT, so this really is the outcome the old layer 3 always
		// reported: the design is gone and the file is what is left of it. Reached by
		// a mirror whose export never landed (an empty `.penpot` is a create, not an
		// archive), or by a Penpot that refused the bytes — {@see ImportService} has
		// already said which, in the log and in the bell.
		$this->logger->warning(
			'penpot_sync restore: the design is gone from Penpot and is not in its trash, and the '
			. 'mirror holds no archive to import — the file is back in Nextcloud on its own',
			[
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'project' => $projectId,
				'file' => $node->getName(),
			],
		);

		// AND TOLD TO THE PERSON WHO RESTORED IT. This is the one restore outcome a
		// user must actually be told about: their file came back and looks entirely
		// normal, and the thing it used to mirror no longer exists.
		$this->notifier->restoredWithoutItsDesign(
			$this->personalTokens->actingUserId(),
			$node->getId(),
			$node->getName(),
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
