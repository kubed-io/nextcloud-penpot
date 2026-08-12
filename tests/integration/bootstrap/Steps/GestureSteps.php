<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * The file-manager gestures: COPY, MOVE and RENAME (`copy-design.feature`,
 * `move-design.feature`, `rename-design.feature`).
 *
 * ## THE THREE PATHS THAT SHIPPED WITHOUT A LIVE TEST
 *
 * These are the app's write-backs, and every one of them is driven by an event
 * Nextcloud emits from its Files API. `occ` cannot perform a single one, so the
 * suite could configure the app and pull with it but never *use* it — the three
 * features sat @todo while the code they describe ran only ever by hand.
 *
 * Three separate bugs came out of that gap in one sitting:
 *
 *   §C6.8   a `move-files` param bug was believed for an hour, because nothing
 *           red contradicted it;
 *   §C6.9   a copy silently failed to record its id — which presents as a broken
 *           RENAME, one gesture later, and reached a human before a test;
 *   §C6.10  a copy to the team root did nothing at all in Penpot, while its unit
 *           test passed against a mock handed a shape the resolver never emits.
 *
 * Every one of those fails on the first run of a real gesture against a real
 * Penpot. That is what these steps are for.
 *
 * ## ASSERTED THROUGH THE APP, SEEDED THROUGH THE WIRE
 *
 * Same cross-check the rest of the suite uses: the gesture goes in over WebDAV
 * (what a browser does), and the result is read back BOTH through the app
 * (`penpot_sync:status`, so we see what the app believes) and through Penpot's
 * own RPC (so we see what actually exists). A test that only asked the app would
 * have passed for every bug listed above.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; uses the
 * DAV transport from {@see \OCA\PenpotSync\Tests\Integration\Support\WebDavTrait}
 * and the Penpot RPC channel from {@see PullSteps}.
 */
trait GestureSteps {
	/** Where the most recent gesture put the file, for the assertions to read. */
	private string $gestureTarget = '';

	/**
	 * The Penpot id the file carried BEFORE the gesture.
	 *
	 * Read at gesture time because that is the last moment the old path resolves,
	 * and because resolving an id from the NEW name afterwards proves only that
	 * some design has that name — not that it is the same one.
	 */
	private string $idBeforeGesture = '';

	// ── the gestures ────────────────────────────────────────────────────────

	/** @When /^I copy "([^"]*)" to "([^"]*)"$/ */
	public function iCopyTo(string $from, string $to): void {
		$this->davCopy($from, $to);
		$this->gestureTarget = $to;
		// The listeners run in the same request as the DAV call, so by the time
		// COPY has answered 201 the design either exists in Penpot or does not.
		// Nothing to wait for, which is what makes these assertions stable.
	}

	/** @When /^I move "([^"]*)" to "([^"]*)"$/ */
	public function iMoveTo(string $from, string $to): void {
		$this->davMove($from, $to);
		$this->gestureTarget = $to;
	}

	/**
	 * A rename is a MOVE to a sibling path — the same DAV verb and the same
	 * Nextcloud event. Telling the two apart is the listener's job, not the
	 * transport's, so this step deliberately goes through the same call.
	 *
	 * @When /^I rename "([^"]*)" to "([^"]*)"$/
	 */
	public function iRenameTo(string $path, string $newName): void {
		// THE ID BEFORE THE GESTURE, so a Then can claim the design MOVED rather than
		// being replaced by a new one wearing the new name. Resolving the id from the
		// new name afterwards cannot tell those apart.
		$this->idBeforeGesture = (string)$this->davReadMetadata($path, 'penpot_id');
		$parent = dirname($path);
		$target = ($parent === '.' || $parent === '') ? $newName : $parent . '/' . $newName;
		$this->davMove($path, $target);
		$this->gestureTarget = $target;
	}

	// ── create ──────────────────────────────────────────────────────────────

	/**
	 * What "+ New → Penpot design" does: write an EMPTY file and stop.
	 *
	 * Empty is the whole point — the server tells a CREATE from an UPLOAD by
	 * exactly this, because a `.penpot` that already holds an archive is a design
	 * someone dragged in, not one to invent.
	 *
	 * @When /^I create a new design file at "([^"]*)"$/
	 */
	public function iCreateANewDesignFileAt(string $path): void {
		$this->davPut($path, '');
		$this->gestureTarget = $path;
	}

	/**
	 * Ordinary Nextcloud content in a mapped folder — a note, an export, whatever
	 * the user likes. Written through the same PUT as a design so the same
	 * listeners see it; the ONLY thing that makes it not ours is the extension.
	 *
	 * @When /^I create an unrelated file at "([^"]*)"$/
	 */
	public function iCreateAnUnrelatedFileAt(string $path): void {
		$this->davPut($path, "not a design\n");
		$this->gestureTarget = $path;
	}

	/**
	 * The gesture, and its past tense for scenarios that merely need the file to
	 * be there before the behaviour starts. See {@see iDelete()}.
	 *
	 * A GIVEN STATES WHAT IS TRUE. As an arrange the sentence is "there is an
	 * untracked archive here", not "I uploaded one" — putting it there is the
	 * step's problem, not the scenario's.
	 *
	 * @Given /^an untracked "\.penpot" archive at "([^"]*)"$/
	 * @When /^I upload a ".penpot" archive at "([^"]*)"$/
	 * @Given /^an uploaded ".penpot" archive at "([^"]*)"$/
	 */
	public function iUploadAnArchiveAt(string $path): void {
		// Real ZIP magic — enough for holdsArchive() to recognise it, which is the
		// only thing the upload-vs-create guard looks at.
		$this->davPut($path, "PK\x03\x04" . str_repeat("\0", 64));
		$this->gestureTarget = $path;
	}

	// ── delete ──────────────────────────────────────────────────────────────

	/**
	 * The gesture — and, in the past tense, the PRE-STATE for anything that starts
	 * from a trashed file.
	 *
	 * A scenario about restoring does not want "And I delete …" in its Given
	 * block: the delete is not something the reader is being shown, it is how the
	 * precondition came to be true. "… is in the trash" says what is true before
	 * the behaviour and leaves the mechanism here, which is the same reason the
	 * setup pull lives in {@see PullSteps} rather than in the Gherkin.
	 *
	 * @When /^I delete "([^"]*)"$/
	 * @Given /^"([^"]*)" is in the trash$/
	 */
	public function iDelete(string $path): void {
		$this->davDelete($path);
		$this->gestureTarget = $path;
	}

	/**
	 * The SECOND step: empty the Nextcloud trash for this file. Fires the same
	 * event as the first delete, distinguished only by the node already living
	 * under files_trashbin — which is what makes this the irreversible one.
	 *
	 * @When /^I purge "([^"]*)" from the Nextcloud trash$/
	 */
	public function iPurgeFromTheNextcloudTrash(string $path): void {
		$entry = $this->trashbinPathFor($path);
		if ($entry === null) {
			throw new \RuntimeException("no trashbin entry found for '{$path}' — was it actually deleted?");
		}
		$res = $this->davClient()->request('DELETE', $this->trashHref($entry));
		$this->assertStatus($res, [204, 200], "purge {$entry}");
	}

	/**
	 * The OTHER second step, and the one that undoes the first: take the file back
	 * out of the Nextcloud trash.
	 *
	 * Looked up by the ORIGINAL path even though the trash entry is named
	 * `<name>.dTIMESTAMP` — {@see trashbinPathFor()} matches on the trashbin's own
	 * `nc:trashbin-filename` property, so the scenarios stay readable and never
	 * have to know about the suffix. That suffix is also exactly what makes
	 * matching trashed files by NAME wrong (#43); the app matches by fileid.
	 *
	 * @When /^I restore "([^"]*)" from the Nextcloud trash$/
	 */
	public function iRestoreFromTheNextcloudTrash(string $path): void {
		$entry = $this->trashbinPathFor($path);
		if ($entry === null) {
			throw new \RuntimeException("no trashbin entry found for '{$path}' — was it actually deleted?");
		}
		$this->davRestoreFromTrash($entry);
		$this->gestureTarget = $path;
	}

	/** The status of the last refused gesture, for the assertion to read. */
	private int $lastGestureStatus = 0;

	/**
	 * A gesture the app is expected to REFUSE.
	 *
	 * `MoveGuardListener` aborts the event before the move happens, which Sabre
	 * turns into a 4xx — so unlike every other gesture here the interesting result
	 * is the STATUS, not what ended up in Penpot. `davMoveStatus()` has existed
	 * since the harness was written, for exactly this, and had never been called.
	 *
	 * @When /^I try to move "([^"]*)" to "([^"]*)"$/
	 */
	public function iTryToMove(string $from, string $to): void {
		$this->lastGestureStatus = $this->davMoveStatus($from, $to);
		$this->gestureTarget = $from;
	}

	/**
	 * THE GUARD IS THE ONLY THING IN THIS APP THAT SAYS NO, and until now nothing
	 * proved it ever does. A guard that silently stopped refusing would let a
	 * `link` leave its project — handing someone an empty husk that looks like a
	 * design — and no test would have noticed.
	 *
	 * @Then /^the move is refused$/
	 */
	public function theMoveIsRefused(): void {
		if ($this->lastGestureStatus < 400 || $this->lastGestureStatus >= 500) {
			throw new \RuntimeException(
				"expected the move to be refused, but Nextcloud answered {$this->lastGestureStatus}",
			);
		}
	}

	// ── Nextcloud's trash ───────────────────────────────────────────────────

	/**
	 * THE HALF THE PRUNE SCENARIOS WERE MISSING. They asserted the mirror was gone
	 * from its folder and stopped there — which is equally true of a hard delete,
	 * the one outcome the prune must never produce. "Trash, never destroy" was a
	 * comment in a feature header for three courses and an assertion in none of
	 * them, and the gap surfaced as a user report: *the file left the folder and I
	 * cannot find it in the trash.*
	 *
	 * @Then /^the file "([^"]*)" is in the Nextcloud trash$/
	 */
	public function theFileIsInTheNextcloudTrash(string $path): void {
		if ($this->trashbinPathFor($path) === null) {
			throw new \RuntimeException(
				"expected '{$path}' in the Nextcloud trash; it is not there — a prune that "
				. 'hard-deletes looks exactly like this from the folder side',
			);
		}
	}

	/**
	 * THE SNAPSHOT IS THE WHOLE POINT of pruning a vanished design (saga §6.46):
	 * the archive is written into the mirror while the design is still readable, so
	 * the bytes in the trash are the last thing that could bring it back at all. A
	 * trashed file's own path no longer resolves, so this reads the trashbin entry.
	 *
	 * @Then /^the trashed file "([^"]*)" holds the design's final archive$/
	 */
	public function theTrashedFileHoldsItsFinalArchive(string $path): void {
		$entry = $this->trashbinPathFor($path);
		if ($entry === null) {
			throw new \RuntimeException("'{$path}' is not in the Nextcloud trash");
		}
		$bytes = (string)$this->davClient()
			->request('GET', $this->trashHref($entry))
			->getBody();
		if (substr($bytes, 0, 2) !== 'PK') {
			throw new \RuntimeException(
				'the trashed mirror holds ' . strlen($bytes) . ' bytes that are not a ZIP archive — '
				. 'the final snapshot was never written',
			);
		}
	}

	/**
	 * One check, two sentences, because a purge and a restore both leave no trashbin
	 * entry and mean opposite things. "Gone from" reads for the destroyed case.
	 *
	 * @Then /^the file "([^"]*)" is gone from the Nextcloud trash$/
	 * @Then /^the file "([^"]*)" is not in the Nextcloud trash$/
	 */
	public function theFileIsNotInTheNextcloudTrash(string $path): void {
		if ($this->trashbinPathFor($path) !== null) {
			throw new \RuntimeException("expected no trashbin entry for '{$path}', but one is there");
		}
	}

	// ── Penpot's trash ──────────────────────────────────────────────────────

	/**
	 * THESE FOUR ASSERTIONS POLL, AND THE REASON IS MEASURED, NOT DEFENSIVE.
	 *
	 * Each one reads a Penpot listing immediately after a gesture MUTATED it, and
	 * those listings are not instantaneous — a delete or a restore is applied
	 * through a worker task, so the row can still be there (or still be missing) a
	 * moment after the call that changed it returned success.
	 *
	 * The evidence is that the suite failed on THREE DIFFERENT scenarios across
	 * four runs of one unchanged commit, every one of them a mutate-then-read of
	 * this shape, and `main` was failing roughly half its runs the same way. A
	 * suite that goes red for reasons unrelated to the change teaches everyone to
	 * re-run instead of read, which is how a real failure gets waved through.
	 *
	 * A poll is the honest fix rather than a sleep: it returns the instant the
	 * state is right, so the common case costs one request, and it fails with the
	 * SAME message as before once the window closes. It cannot mask a real bug —
	 * a state that never arrives still fails, just later.
	 *
	 * @Then /^the design "([^"]*)" is in Penpot's trash$/
	 */
	public function theDesignIsInPenpotsTrash(string $name): void {
		$this->until(
			fn (): bool => in_array($name, $this->penpotTrashNames(), true),
			fn (): string => sprintf("expected '%s' in Penpot's trash; found: %s", $name, implode(', ', $this->penpotTrashNames()) ?: '(none)'),
		);
	}

	/**
	 * The Team Folder counterpart: the design is STILL there, because the purge
	 * could not be observed at all (delete-design.feature, saga §C6.27).
	 *
	 * Deliberately NOT a poll. The other three wait for a state to arrive; this one
	 * asserts a state that is not going to change, so waiting would only make the
	 * suite slower and would turn a genuine future FIX into a ten-second failure
	 * instead of an instant one.
	 *
	 * @Then /^the design "([^"]*)" is still in Penpot's trash$/
	 */
	public function theDesignIsStillInPenpotsTrash(string $name): void {
		if (!in_array($name, $this->penpotTrashNames(), true)) {
			throw new \RuntimeException(sprintf(
				"expected '%s' to STILL be in Penpot's trash on a Team Folder, but it is gone — "
				. 'if the purge now reaches Penpot, this gap is closed and the @team-folder '
				. 'scenario should be deleted in favour of the @plain-folder one.',
				$name,
			));
		}
	}

	/**
	 * The assertion that separates a soft delete from a destroyed one — and the
	 * one the purge guard exists to keep honest.
	 *
	 * @Then /^the design "([^"]*)" is not in Penpot's trash$/
	 */
	public function theDesignIsNotInPenpotsTrash(string $name): void {
		$this->until(
			fn (): bool => !in_array($name, $this->penpotTrashNames(), true),
			fn (): string => sprintf("expected '%s' to be gone from Penpot's trash, but it is still listed", $name),
		);
	}

	/**
	 * Poll $condition until it holds, or fail with $describe once the window
	 * closes. Ten seconds in 250ms steps: long enough for a worker task to land,
	 * short enough that a genuine failure is still reported inside one scenario.
	 *
	 * The failure message is produced LAZILY and only on the last attempt, so it
	 * describes the state that actually persisted rather than the first sample.
	 */
	private function until(callable $condition, callable $describe, float $seconds = 10.0): void {
		$deadline = microtime(true) + $seconds;
		do {
			if ($condition()) {
				return;
			}
			usleep(250_000);
		} while (microtime(true) < $deadline);

		// Describes the WAIT, not the truth value: this helper is used for both
		// positive and negative conditions, so "still true" would be backwards
		// half the time.
		throw new \RuntimeException($describe() . sprintf(' (condition never held within %.0fs)', $seconds));
	}

	/** @return list<string> */
	private function penpotTrashNames(): array {
		$names = [];
		foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $this->teamId()]) as $file) {
			if (isset($file['name']) && is_string($file['name'])) {
				$names[] = $file['name'];
			}
		}

		return $names;
	}

	/**
	 * The mapped team's Penpot id, read off the mapped ROOT FOLDER's own stamp.
	 *
	 * Not from `list-mappings`, which prints the team's NAME and an internal
	 * mapping hash — no Penpot uuid anywhere. The folder carries the real one
	 * (`penpot_team_id`, §6.21), and reading it through `status` keeps this
	 * assertion on the app's own read path like every other one here.
	 */
	private function teamId(string $mappedFolder = 'Penpot'): string {
		if (preg_match('/penpot_team_id: (\S+)/', $this->status($mappedFolder), $m) === 1) {
			return $m[1];
		}

		throw new \RuntimeException("no penpot_team_id on '{$mappedFolder}':\n" . $this->status($mappedFolder));
	}

	// ── what the APP believes ───────────────────────────────────────────────

	/** @Then /^the file "([^"]*)" carries a Penpot id$/ */
	public function theFileCarriesAPenpotId(string $path): void {
		if (preg_match('/penpot_id: \S/', $this->status($path)) !== 1) {
			throw new \RuntimeException(
				"expected '{$path}' to carry a penpot_id, got:\n" . $this->status($path),
			);
		}
	}

	/**
	 * The negative that the copy bug needed: a file that exists and is untracked
	 * looks completely normal in the Files app, so only the stamp shows it.
	 *
	 * @Then /^the file "([^"]*)" carries no Penpot id$/
	 */
	public function theFileCarriesNoPenpotId(string $path): void {
		if (preg_match('/penpot_id: \S/', $this->status($path)) === 1) {
			throw new \RuntimeException(
				"expected '{$path}' to carry NO penpot_id, got:\n" . $this->status($path),
			);
		}
	}

	/** @Then /^the files "([^"]*)" and "([^"]*)" carry different Penpot ids$/ */
	public function theFilesCarryDifferentPenpotIds(string $a, string $b): void {
		$idA = $this->penpotIdOfFile($a);
		$idB = $this->penpotIdOfFile($b);
		if ($idA === $idB) {
			throw new \RuntimeException(
				"expected '{$a}' and '{$b}' to be different designs, but both carry {$idA}. "
				. 'Two files claiming one design is the ambiguity copy-design.feature exists to prevent.',
			);
		}
	}

	// ── what PENPOT actually holds ──────────────────────────────────────────

	/** @Then /^Penpot project "([^"]*)" holds (\d+) designs?$/ */
	public function penpotProjectHoldsDesigns(string $projectName, int $count): void {
		$found = count($this->penpotFileNamesIn($projectName));
		if ($found !== $count) {
			throw new \RuntimeException(
				sprintf("expected %d design(s) in Penpot project '%s', found %d: %s", $count, $projectName, $found, implode(', ', $this->penpotFileNamesIn($projectName))),
			);
		}
	}

	/** @Then /^Penpot project "([^"]*)" holds a design named "([^"]*)"$/ */
	public function penpotProjectHoldsADesignNamed(string $projectName, string $designName): void {
		$this->until(
			fn (): bool => in_array($designName, $this->penpotFileNamesIn($projectName), true),
			fn (): string => sprintf(
				"expected a design named '%s' in Penpot project '%s'; found: %s",
				$designName, $projectName, implode(', ', $this->penpotFileNamesIn($projectName)) ?: '(none)',
			),
		);
	}

	/** @Then /^Penpot project "([^"]*)" holds no design named "([^"]*)"$/ */
	public function penpotProjectHoldsNoDesignNamed(string $projectName, string $designName): void {
		$this->until(
			fn (): bool => !in_array($designName, $this->penpotFileNamesIn($projectName), true),
			fn (): string => sprintf("expected NO design named '%s' in Penpot project '%s', but it is there", $designName, $projectName),
		);
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/** The `penpot_id` the app has stamped on a file, or '' when untracked. */
	private function penpotIdOfFile(string $path): string {
		if (preg_match('/penpot_id: (\S+)/', $this->status($path), $m) === 1) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Design names in a Penpot project, read through the app's own probe so the
	 * seed channel and the read channel keep cross-checking each other — the same
	 * trick PruneSteps uses.
	 *
	 * A project line ends in `[<team>]`; a file line under it carries `revn=`.
	 *
	 * ## AN ERROR IS NOT AN EMPTY PROJECT, AND THIS USED TO REPORT IT AS ONE
	 *
	 * `probe --files` catches a per-project listing failure and prints
	 * `<error>…</error>` where the files would be, exiting 0 — so a transient
	 * Penpot 502 for ONE project used to reach the assertion as the flat, wrong
	 * `found: (none)`, indistinguishable from "the design really is gone". That
	 * sent an hour after a restore bug that had not happened: the restore had
	 * logged success, the pull had listed the design, and only the probe failed.
	 *
	 * The error line is now raised as itself.
	 *
	 * @return list<string>
	 */
	private function penpotFileNamesIn(string $projectName): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while listing '{$projectName}':\n{$res['output']}");
		}

		$names = [];
		$inProject = false;
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}[0-9a-f-]{36}\s+\[/', $line, $m) === 1) {
				$inProject = trim($m[1]) === $projectName;
				continue;
			}
			if ($inProject && str_contains($line, '<error>')) {
				throw new \RuntimeException(
					"Penpot could not list project '{$projectName}' — this is a LISTING failure, "
					. "not an empty project:\n" . trim(strip_tags($line)),
				);
			}
			if ($inProject && preg_match('/^\s+(.*?)\s+revn=/', $line, $m) === 1) {
				$names[] = trim($m[1]);
			}
		}

		return $names;
	}
}
