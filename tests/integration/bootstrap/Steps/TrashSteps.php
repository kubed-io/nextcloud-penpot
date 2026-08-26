<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * The trash round trip: restoring a mirror, and emptying the trash for good
 * (`designs/restore.feature`, `designs/purge.feature`).
 *
 * ## THESE ARE CURSOR STEPS, AND THAT IS THE WHOLE JOB
 *
 * Almost every sentence here has a path-shaped twin in {@see GestureSteps} —
 * `I purge "…" from the Nextcloud trash` beside `I purge it from the trash`.
 * The spec stopped repeating paths (a scenario names its file once, in the
 * `Given`, and says "the file" thereafter), so what was missing was not
 * behaviour but the pronoun. Each step below resolves the cursor and hands off;
 * none of them re-implements a check that already exists, because two checkers
 * for one claim is two ways for the same row to drift.
 *
 * ## THE THREE LAYERS A RESTORE CAN LAND IN (saga §6.52)
 *
 * `restore.feature` turns on which of three states the far side is in, and the
 * arranges here are what put it in each:
 *
 *   still live      → `its design has been restored in Penpot`
 *   in Penpot's trash → the ordinary state after a trashing; asserted, not made
 *   gone for good   → `its design has been permanently deleted in Penpot`
 *
 * The middle one is deliberately an ASSERTION rather than an arrangement. The app
 * put the design there when the file was trashed, so a step that "made" it true
 * would be re-doing the app's work and could pass while the app did nothing.
 *
 * ## PENPOT'S PURGE LEAVES THE ROW (§C6.11)
 *
 * `permanently-delete-team-files` stamps `deleted_at` and leaves the record, so a
 * destroyed design is STILL LISTED by `get-team-deleted-files`. Nothing here may
 * assert "gone" by asking the trash listing; where the spec means erased, the
 * check is that the design is absent from its PROJECT, which is the same listing
 * the pull reads.
 */
trait TrashSteps {
	/** The instance URL, kept so an unreachable-Penpot scenario can put it back. */
	private string $urlBeforeOutage = '';

	/**
	 * The team the cursor's design belonged to, read BEFORE its file was trashed.
	 *
	 * `teamId()` answers for the `Penpot` mapping and nothing else, which is wrong
	 * the moment a scenario runs its Team Folder row — and `teamIdForPath()` cannot
	 * help either, because by the time these steps ask, the file is in the trash and
	 * has no path left to resolve. So it is captured on the way past.
	 */
	private string $teamBeforeTrashing = '';

	/** @BeforeScenario */
	public function armTrashSteps(): void {
		$this->urlBeforeOutage = '';
		$this->teamBeforeTrashing = '';
	}

	/**
	 * Put Penpot's URL back after a scenario deliberately broke it.
	 *
	 * WITHOUT THIS THE NEXT SCENARIO INHERITS THE OUTAGE. The Background's `the app
	 * is connected to Penpot` does re-set it, but only on the scenarios that say so
	 * — and a leg whose remaining scenarios all fail for a reason the previous one
	 * caused is the least diagnosable failure this suite can produce.
	 *
	 * ## AND IT IS CALLED AT THE END OF THE GESTURE, NOT ONLY AFTER THE SCENARIO
	 *
	 * `Penpot is unreachable` describes what the APP could reach while it acted.
	 * Every assertion that follows has to reach Penpot itself to check that nothing
	 * changed — and `no design is deleted in Penpot` asks through `occ
	 * penpot_sync:probe`, which is the app, pointed at a closed port. It came back
	 * with no designs at all, so the assertion read every design in the instance as
	 * destroyed by a purge that had in fact done nothing.
	 *
	 * So the outage ends with the gesture it was arranged for. The `@AfterScenario`
	 * stays as the seatbelt for a scenario that never reaches its `When`.
	 *
	 * @AfterScenario
	 */
	public function healThePenpotUrl(): void {
		if ($this->urlBeforeOutage !== '') {
			$this->occ('penpot_sync:set-url ' . escapeshellarg($this->urlBeforeOutage));
			$this->urlBeforeOutage = '';
		}
	}

	/**
	 * The cursor's file is in the Nextcloud trash — by being put there.
	 *
	 * A `Given` states what is true, and the only way this becomes true is the
	 * trash gesture, so it delegates to it. That the design follows into Penpot's
	 * trash is the app's doing and is asserted separately by the next line.
	 *
	 * @Given /^the file is in the Nextcloud trash$/
	 */
	public function theCursoredFileIsInTheNextcloudTrash(): void {
		$this->teamBeforeTrashing = $this->teamIdForPath($this->currentFilePath);
		$this->iMoveItToTheTrash();
	}

	/** The cursor design's team, resolved before the trashing took its path away. */
	private function cursorTeamId(): string {
		return $this->teamBeforeTrashing !== '' ? $this->teamBeforeTrashing : $this->teamId();
	}

	/**
	 * Its design followed it into Penpot's trash.
	 *
	 * ASSERTED, NOT ARRANGED — see the class docblock. The trashing above is what
	 * put it there; a step that deleted it again would prove nothing and would hide
	 * an app that had failed to.
	 *
	 * @Given /^its design is in Penpot's trash$/
	 */
	public function itsDesignIsInPenpotsTrash(): void {
		$id = $this->cursorDesignId();
		$this->until(
			fn (): bool => $this->inTeamTrash($this->cursorTeamId(), $id),
			fn (): string => "the design {$id} never reached Penpot's trash",
		);
	}

	/**
	 * Its design is NOT in Penpot's trash — somebody took it back out over there.
	 *
	 * The live half of the same fork: `Empty the trash when the design is not in
	 * Penpot's trash` is about a purge finding nothing to destroy, and a restored
	 * design is the reachable way to be in that state. The erased way is
	 * `permanently deleted`, and it is NOT interchangeable — Penpot's purge leaves
	 * the row listed (§C6.11), so it would not satisfy this sentence at all.
	 *
	 * @Given /^its design is not in Penpot's trash$/
	 */
	public function itsDesignIsNotInPenpotsTrash(): void {
		$id = $this->cursorDesignId();
		$this->penpotRpc('restore-deleted-team-files', ['team-id' => $this->cursorTeamId(), 'ids' => [$id]]);
		$this->until(
			fn (): bool => !$this->inTeamTrash($this->cursorTeamId(), $id),
			fn (): string => "the design {$id} is still in Penpot's trash after a restore",
		);
	}

	/**
	 * Somebody restored it in Penpot while its mirror sat in the Nextcloud trash.
	 *
	 * @Given /^its design has been restored in Penpot$/
	 */
	public function itsDesignHasBeenRestoredInPenpot(): void {
		$this->itsDesignIsNotInPenpotsTrash();
	}

	/**
	 * Erased over there, past the grace window.
	 *
	 * The spec says "has been", {@see GestureSteps::itsDesignIsPermanentlyDeletedInPenpot()}
	 * answers "is". One claim, and the tense is the scenario's business rather than
	 * the harness's — so this is an alias and not a second implementation.
	 *
	 * @Given /^its design has been permanently deleted in Penpot$/
	 */
	public function itsDesignHasBeenPermanentlyDeletedInPenpot(): void {
		$this->itsDesignIsPermanentlyDeletedInPenpot();
	}

	/**
	 * Penpot cannot be reached at all.
	 *
	 * POINTED AT A CLOSED PORT rather than a bad hostname: a name that does not
	 * resolve can take a DNS timeout per call, and this suite would rather fail in
	 * milliseconds. Port 9 is `discard`, reserved and never listening.
	 *
	 * @Given /^Penpot is unreachable$/
	 */
	public function penpotIsUnreachable(): void {
		// RE-SNAPSHOT WHAT PENPOT HOLDS, because "no design is deleted in Penpot"
		// means "from HERE on". The arrange above has already trashed one on purpose,
		// and the snapshot taken by the trash gesture predates it — so the assertion
		// would report the arrange's own delete as damage done by the purge.
		//
		// AND WAIT FOR THAT TRASHING TO REACH THE LIVE LISTING FIRST. Two listings are
		// involved and they do not move together: `get-team-deleted-files` names the
		// design as trashed a moment before `probe --files` stops naming it as live.
		// The step above waits on the FORMER, so a snapshot taken immediately after it
		// can still contain a design that is on its way out — and then the assertion
		// watches it leave and calls it damage. Measured: exactly one design "lost",
		// every run, always the one the arrange had just trashed.
		$id = $this->cursorDesignId();
		$this->until(
			fn (): bool => !in_array($id, $this->penpotLiveDesignIds(), true),
			fn (): string => "the trashed design {$id} is still listed as live in Penpot",
		);

		$this->designIdsBeforeRefusal = $this->penpotLiveDesignIds();

		$current = $this->occ('penpot_sync:show-config');
		if (preg_match('/Penpot base URL: (\S+)/', $current['output'], $m) === 1) {
			$this->urlBeforeOutage = $m[1];
		}

		$this->occ('penpot_sync:set-url http://127.0.0.1:9/');
	}

	/**
	 * Empty the cursor's file out of the Nextcloud trash, for good.
	 *
	 * @When /^I purge it from the trash$/
	 */
	public function iPurgeItFromTheTrash(): void {
		// EVERY ENTRY FOR THIS NAME, not the first one.
		//
		// `trashbinPathFor()` matches on BASENAME, so a file another scenario trashed
		// under the same name is an equally good match and there is no way to tell
		// them apart from the outside. Purging one and asserting "gone from the
		// Nextcloud trash" then failed against a leftover the purge was never given —
		// the fixture's debris answering for the gesture, again.
		//
		// Emptying the trash of every entry by that name is both what the scenario
		// means and the only thing that can be asserted afterwards.
		$purged = 0;
		while ($this->trashbinPathFor($this->currentFilePath) !== null) {
			$this->iPurgeFromTheNextcloudTrash($this->currentFilePath);
			if (++$purged > 10) {
				throw new \RuntimeException("the trashbin keeps producing entries for '{$this->currentFilePath}'");
			}
		}

		if ($purged === 0) {
			throw new \RuntimeException("nothing in the Nextcloud trash came from '{$this->currentFilePath}'");
		}

		$this->healThePenpotUrl();
	}

	/**
	 * Take the cursor's file back out of the Nextcloud trash.
	 *
	 * @When /^I restore it from the trash$/
	 */
	public function iRestoreItFromTheTrash(): void {
		$this->iRestoreFromTheNextcloudTrash($this->currentFilePath);
		$this->healThePenpotUrl();
	}

	/**
	 * Somebody empties Penpot's own trash, from Penpot.
	 *
	 * EVERY ID IN THE LISTING, because that is what emptying means — and because
	 * the ids come from `get-team-deleted-files` it satisfies the rule the app
	 * holds itself to (§C6.11: the destroy command has no safety of its own).
	 *
	 * @When /^someone empties Penpot's trash$/
	 */
	public function someoneEmptiesPenpotsTrash(): void {
		$team = $this->cursorTeamId();
		$ids = $this->penpotTrashIds($team);
		if ($ids !== []) {
			$this->penpotRpc('permanently-delete-team-files', ['team-id' => $team, 'ids' => $ids]);
		}
		$this->theAdminRunsAPull();
	}

	/**
	 * Somebody restores the cursor's design in Penpot, and the sync carries the news.
	 *
	 * @When /^someone restores the design in Penpot$/
	 */
	public function someoneRestoresTheDesignInPenpot(): void {
		$this->penpotRpc('restore-deleted-team-files', [
			'team-id' => $this->cursorTeamId(),
			'ids' => [$this->cursorDesignId()],
		]);
		$this->theAdminRunsAPull();
	}

	/**
	 * That design is erased in Penpot, not merely trashed.
	 *
	 * ASKED OF THE PROJECT, NEVER THE TRASH LISTING. Penpot's permanent delete
	 * stamps `deleted_at` and leaves the row (§C6.11), so a destroyed design is
	 * still listed as deleted — a check phrased "gone from the trash" would fail
	 * for a purge that worked perfectly. Absent from its project is what the pull
	 * reads and what "gone" means here.
	 *
	 * @Then /^that file's design is permanently deleted from Penpot$/
	 */
	public function thatFilesDesignIsPermanentlyDeletedFromPenpot(): void {
		$id = $this->cursorDesignId();
		$this->until(
			fn (): bool => !$this->penpotHasLiveDesign($id),
			fn (): string => "the design {$id} is still a live file in Penpot",
		);
	}

	/**
	 * The cursor's file is out of the Nextcloud trash — gone for good.
	 *
	 * @Then /^the file is gone from the Nextcloud trash$/
	 */
	public function theFileIsGoneFromTheNextcloudTrash(): void {
		$this->theFileIsNotInTheNextcloudTrash($this->currentFilePath);
	}

	/**
	 * The file came back to the folder it was trashed from.
	 *
	 * @Then /^the file is back in "([^"]*)"$/
	 */
	public function theFileIsBackIn(string $folder): void {
		$this->theFileIsBackAt(trim($folder, '/') . '/' . basename($this->currentFilePath));
	}

	/**
	 * The file is back at exactly this path.
	 *
	 * MOVES THE CURSOR, so a `the file holds:` after it reads the restored node
	 * rather than a path that no longer exists.
	 *
	 * @Then /^the file is back at "([^"]*)"$/
	 */
	public function theFileIsBackAt(string $path): void {
		$path = trim($path, '/');
		$this->until(
			fn (): bool => $this->davExists($path),
			fn (): string => "'{$path}' did not come back out of the trash",
		);

		$this->currentFilePath = $path;
		$this->currentFolder = dirname($path);
	}

	/**
	 * The restore reached nothing on the far side.
	 *
	 * The twin of `no design is deleted in Penpot`, and it exists for the untracked
	 * scenarios: a file this app never mirrored names no design, so restoring it
	 * must leave Penpot's live set exactly as it was.
	 *
	 * @Then /^no design is restored in Penpot$/
	 */
	public function noDesignIsRestoredInPenpot(): void {
		$this->noDesignIsDeletedInPenpot();
	}

	/** Is this id a LIVE file of some project — i.e. not trashed and not erased? */
	private function penpotHasLiveDesign(string $id): bool {
		return in_array($id, $this->penpotLiveDesignIds(), true);
	}
}
