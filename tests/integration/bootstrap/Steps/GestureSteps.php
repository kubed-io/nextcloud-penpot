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
	/** What "+ New → Penpot design" names the file it writes. */
	private const NEW_DESIGN = 'New design.penpot';

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

	/**
	 * The designs that were under the node a gesture acted on, captured before it
	 * ran. Keyed by PATH, so two designs sharing a filename in two subfolders stay
	 * two entries; see {@see thoseDesignsAreInPenpotsTrash()}.
	 *
	 * @var array<string, string>
	 */
	private array $designIdsBeforeGesture = [];

	/**
	 * Every design in the whole instance before a REFUSED create, so
	 * {@see ProjectFolderSteps::noDesignIsCreatedInPenpot()} can prove the refusal
	 * added nothing anywhere.
	 *
	 * A flat list rather than the keyed map above, because there is no node to key
	 * it by: the question is "did Penpot gain anything?", not "did these survive?".
	 *
	 * @var list<string>
	 */
	private array $designIdsBeforeRefusal = [];

	/**
	 * The app's keys on the cursor's file the instant before it was trashed, since
	 * a trashed file's properties are no longer readable at its old path.
	 *
	 * @var list<string>
	 */
	private array $penpotKeysAtTrashTime = [];

	/**
	 * Read the design's id BEFORE a gesture, which is the last moment the old path
	 * resolves. Every claim of the form "the id it had before …" rests on this, so
	 * every gesture those specs cover has to record it: a rename, a move, and a
	 * trashing all promise the design survived rather than being replaced.
	 */
	private function captureIdBeforeGesture(string $path): void {
		$this->idBeforeGesture = (string)$this->davReadMetadata($path, 'penpot_id');
	}

	// ── the gestures ────────────────────────────────────────────────────────

	/** @When /^I copy "([^"]*)" to "([^"]*)"$/ */
	public function iCopyTo(string $from, string $to): void {
		$this->davCopy($from, $to);
		$this->gestureTarget = $to;
		// The listeners run in the same request as the DAV call, so by the time
		// COPY has answered 201 the design either exists in Penpot or does not.
		// Nothing to wait for, which is what makes these assertions stable.
	}

	/**
	 * Copy something INTO a folder — the folder-shaped twin of
	 * {@see CopySteps::iCopyTheFileInto()}.
	 *
	 * `into` names the destination FOLDER and keeps the thing's own name, exactly
	 * as it does for a move. That distinction is the whole point here: copying a
	 * project beside itself is a scenario ABOUT the name core picks, so the
	 * sentence must not contain one.
	 *
	 * THE HARNESS RESOLVES CORE'S NAME RATHER THAN CHOOSING ONE. DAV COPY needs an
	 * explicit `Destination`, so something has to spell the target path — but
	 * `freeCopyPath()` reproduces `Folder::getNonExistingName()` (from 2, not 1),
	 * so the scenario asserts core's rule and not the harness's preference.
	 *
	 * @When /^I copy "([^"]*)" into "([^"]*)"$/
	 */
	public function iCopyInto(string $path, string $folder): void {
		$folder = trim($folder, '/');
		$target = $folder . '/' . basename($path);
		$this->gestureTarget = $this->davExists($target)
			? $this->freeCopyPath($folder, basename($path))
			: $target;

		$this->davCopy($path, $this->gestureTarget);
	}

	/**
	 * Move something INTO a folder, which is what a drag actually is.
	 *
	 * ## WHY THIS IS NOT THE SAME STEP AS `… to "…"`
	 *
	 * `to` names the destination PATH; `into` names the destination FOLDER and
	 * keeps the thing's own name. `I move "Penpot/Traveller" into "Penpot/Clients"`
	 * ends at `Penpot/Clients/Traveller`, and spelling that out as a path would
	 * make every such sentence repeat the name it just said — which is exactly what
	 * the rewritten spec stopped doing. Every path-form move in the spec now says
	 * `into`, because every one of them is a drag.
	 *
	 * @When /^I move "([^"]*)" into "([^"]*)"$/
	 */
	public function iMoveInto(string $path, string $folder): void {
		$this->captureIdBeforeGesture($path);
		$target = trim($folder, '/') . '/' . basename($path);
		$this->davMove($path, $target);
		$this->gestureTarget = $target;
	}

	/**
	 * Drag THE file — the cursor's — into a folder.
	 *
	 * The path form says which thing moves; this one says it about the design the
	 * scenario has already put on stage, which is how a reader describes the
	 * second sentence of a story rather than the first. The cursor follows the
	 * move, so anything after it still means the same file.
	 *
	 * @When /^I move the file into "([^"]*)"$/
	 */
	public function iMoveTheFileInto(string $folder): void {
		if ($this->currentFilePath === '') {
			throw new \RuntimeException('the scenario says "the file" but no file is on stage.');
		}

		$folder = trim($folder, '/');
		$this->makeAncestors($folder . '/x');
		$target = $folder . '/' . basename($this->currentFilePath);

		$this->captureIdBeforeGesture($this->currentFilePath);
		$this->davMove($this->currentFilePath, $target);

		$this->gestureTarget = $target;
		$this->currentFilePath = $target;
		$this->currentFolder = $folder;
		// RE-READ rather than keep the old value: the id must not change across a
		// move, and reading it back is how the next assertion can prove it did not.
		$this->currentFileId = $this->davReadMetadata($target, 'penpot_id') ?? '';
	}

	/**
	 * The same drag, expected to be REFUSED — see {@see iTryToMove()} for why a
	 * refusal needs its own step rather than a flag on this one.
	 *
	 * @When /^I try to move "([^"]*)" into "([^"]*)"$/
	 */
	public function iTryToMoveInto(string $path, string $folder): void {
		$target = trim($folder, '/') . '/' . basename($path);
		$result = $this->davMoveResult($path, $target);
		$this->lastGestureStatus = $result['status'];
		$this->lastGestureBody = $result['body'];
		$this->gestureTarget = $path;
	}

	/**
	 * The cursor's file, dragged where it is not allowed to go.
	 *
	 * The cursor twin of {@see iTryToMoveInto()}, for the same reason
	 * {@see iMoveTheFileInto()} is the twin of {@see iMoveInto()}: a scenario that
	 * has already said `a design file named "Pointer.penpot" in "Pointers/Confined"`
	 * should not have to say the path again to try moving it.
	 *
	 * NO `makeAncestors()` HERE, and that is the difference that matters. The
	 * allowed twin creates the destination because a drag in the Files app lands in
	 * a folder that exists; this one must not, because several of the destinations
	 * it is pointed at are inside a `link` mapping — and creating them would be
	 * this step arranging a write into a tree the app refuses writes to. Every
	 * destination a refusal scenario names already exists, or is the mapping root.
	 *
	 * The cursor deliberately does NOT follow: the whole claim is that the file did
	 * not move, so `the file stays in "…"` has to be asking about the old path.
	 *
	 * @When /^I try to move the file into "([^"]*)"$/
	 */
	public function iTryToMoveTheFileInto(string $folder): void {
		if ($this->currentFilePath === '') {
			throw new \RuntimeException('the scenario says "the file" but no file is on stage.');
		}

		$target = trim($folder, '/') . '/' . basename($this->currentFilePath);
		$result = $this->davMoveResult($this->currentFilePath, $target);
		$this->lastGestureStatus = $result['status'];
		$this->lastGestureBody = $result['body'];
		$this->gestureTarget = $this->currentFilePath;
	}

	/**
	 * The refusal actually stopped it — the cursor's file is still in the folder
	 * the scenario put it in.
	 *
	 * The cursor twin of {@see staysWhereItWas()}, and it asserts one thing more
	 * than "something is there": the file at that path still carries the id the
	 * arrange recorded. A refused move that somehow left a DIFFERENT file behind
	 * would pass a bare existence check, and a copy-then-fail is exactly the shape
	 * a half-done move takes.
	 *
	 * @Then /^the file stays in "([^"]*)"$/
	 */
	public function theFileStaysIn(string $folder): void {
		$folder = trim($folder, '/');
		$expected = $folder . '/' . basename($this->currentFilePath);

		if (!$this->davExists($expected)) {
			throw new \RuntimeException(sprintf(
				"'%s' was supposed to stay put, but there is nothing there any more — the refusal "
				. "was reported and the move happened anyway. '%s' now holds: %s",
				$expected,
				$folder,
				implode(', ', $this->davChildren($folder)) ?: '(nothing)',
			));
		}

		if ($this->currentFileId === '') {
			return;
		}

		$now = $this->davReadMetadata($expected, 'penpot_id') ?? '';
		if ($now !== $this->currentFileId) {
			throw new \RuntimeException(
				"something is at '{$expected}', but it is not the file the scenario put there: "
				. "expected the design {$this->currentFileId}, found '" . ($now ?: '(untracked)') . "'",
			);
		}
	}

	/**
	 * A refused move changed NEITHER side.
	 *
	 * ## WHY BOTH HALVES, AND WHY THIS IS NOT THE SAME AS "IT STAYED PUT"
	 *
	 * `the file stays in "…"` is about Nextcloud: the node is where it was. This is
	 * about the two things a half-applied move would have damaged anyway —
	 *
	 *   - the LOCAL bytes, because the guard runs on `method:MOVE` and a MOVE that
	 *     got as far as its copy leg would leave the original readable but wrong;
	 *   - the REMOTE design, because `MotionService` re-files in Penpot and a
	 *     refusal that fired after the RPC would leave Penpot moved and Nextcloud
	 *     not — the silent desync this whole rule exists to prevent.
	 *
	 * The body is checked against what the file's MAPPING implies rather than
	 * against a snapshot, which is the stronger claim: a `link` still holds zero
	 * bytes and a `sync` still holds an archive, so a refusal that blanked a mirror
	 * on its way out is caught even though the file never moved.
	 *
	 * @Then /^the original file and its design are unchanged$/
	 */
	public function theOriginalFileAndItsDesignAreUnchanged(): void {
		$path = $this->currentFilePath;
		if ($path === '' || !$this->davExists($path)) {
			throw new \RuntimeException(
				"the scenario says the original file is unchanged, but there is nothing at '{$path}'.",
			);
		}

		// OUTSIDE EVERY MAPPING THERE IS NO IMPLIED BODY, and asserting one is how
		// this step failed a scenario it should have passed. `modeOfMappingFor()`
		// answers `link` for an unmapped path — a safe default where a MODE is
		// wanted, and a wrong one here, because it made an untracked archive in
		// "Scratch" read as "should be empty". A file the app does not manage holds
		// whatever its owner put in it.
		if (isset($this->mappingModes[$this->mappingRootOf($path)])) {
			$want = $this->modeOfMappingFor($path) === 'link' ? 'empty' : 'archive';
			$body = $this->contentKind($path);
			if ($body !== $want) {
				throw new \RuntimeException(
					"'{$path}' survived the refusal but its content did not: expected '{$want}' "
					. "(what its mapping implies), found '{$body}'.",
				);
			}
		}

		if ($this->currentFileId === '') {
			return;
		}
		$this->theDesignStillExistsInPenpot();
	}

	/**
	 * The cursor's design is still a live design in Penpot.
	 *
	 * BY ID ACROSS THE WHOLE PROBE, not by name inside one project, and both halves
	 * of that are deliberate. By id, because Penpot state accumulates across a leg
	 * and a same-named leftover from an earlier scenario would answer for a design
	 * this one destroyed. Across the whole listing, because the scenarios saying
	 * this sentence are precisely the ones where the design's project is in
	 * question — "it is still SOMEWHERE" is the claim, and naming a project would
	 * turn it into a different, narrower one.
	 *
	 * @Then /^the design still exists in Penpot$/
	 */
	public function theDesignStillExistsInPenpot(): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException(
				'the scenario says "the design" but the arrange put none on stage — '
				. 'nothing it named carried a penpot_id.',
			);
		}

		$this->until(
			fn (): bool => in_array($this->currentFileId, $this->penpotLiveDesignIds(), true),
			fn (): string => sprintf(
				'the design %s is gone from Penpot; the teams on stage now hold: %s',
				$this->currentFileId,
				implode(', ', $this->penpotLiveDesignIds()) ?: '(none)',
			),
		);
	}

	/**
	 * Every design id the probe can see, across every mapped team.
	 *
	 * The probe nests `<design>  revn=<n>  <uuid>` under its project, and the
	 * trailing uuid on those lines is the only one this needs — a project line
	 * carries a uuid too, which is why the `revn=` is part of the match rather
	 * than a bare uuid grep.
	 *
	 * @return list<string>
	 */
	private function penpotLiveDesignIds(): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while listing Penpot's designs:\n{$res['output']}");
		}

		$ids = [];
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/\srevn=\S+\s+([0-9a-f-]{36})\s*$/', $line, $m) === 1) {
				$ids[] = $m[1];
			}
		}

		return $ids;
	}

	/**
	 * A rename is a MOVE to a sibling path — the same DAV verb and the same
	 * Nextcloud event. Telling the two apart is the listener's job, not the
	 * transport's, so this step deliberately goes through the same call.
	 *
	 * ## THE DESTINATION IS A PATH WHEN IT SPELLS ONE, AND A SIBLING NAME OTHERWISE
	 *
	 * The rewritten spec says `I rename "Penpot/Old" to "Penpot/New"` — both sides
	 * full paths, which is how a reader wants to see a rename that happens three
	 * folders deep. This step used to append the second argument to the first's
	 * parent unconditionally, so that sentence moved the folder to
	 * `Penpot/Penpot/New` and the scenario failed somewhere else entirely.
	 *
	 * Both spellings are accepted because both are wanted: a rename in the files
	 * root has no slash to give (`I rename "Scratch" to "Junk"`), and one below the
	 * root reads better stated in full. A slash is the whole test, and it is not
	 * ambiguous — a sibling name cannot contain one, because that is precisely what
	 * makes it a sibling.
	 *
	 * The implicit-file form is a DIFFERENT step and always takes a bare name
	 * ({@see ArrangeSteps} for the cursor it renames), because there the folder is
	 * not in the sentence to be repeated.
	 *
	 * @When /^I rename "([^"]*)" to "([^"]*)"$/
	 */
	public function iRenameTo(string $path, string $newName): void {
		$this->captureIdBeforeGesture($path);
		$parent = dirname($path);
		$target = str_contains($newName, '/') || $parent === '.' || $parent === ''
			? $newName
			: $parent . '/' . $newName;
		$this->davMove($path, $target);
		$this->gestureTarget = $target;
	}

	// ── create ──────────────────────────────────────────────────────────────

	/**
	 * The gesture, and its past tense for scenarios that merely need the file to
	 * be there before the behaviour starts. See {@see iDelete()}.
	 *
	 * A GIVEN STATES WHAT IS TRUE. As an arrange the sentence is "there is an
	 * untracked archive here", not "I uploaded one" — putting it there is the
	 * step's problem, not the scenario's.
	 *
	 * @Given /^an untracked design file at "([^"]*)"$/
	 */
	public function iUploadAnArchiveAt(string $path): void {
		// THE FOLDER MAY NOT BE THERE YET. `an untracked design file at
		// "Scratch/Adopt Me/Alpha.penpot"` names a folder the scenario never
		// created, and a PUT into a missing collection is a 404 — which reads as a
		// broken upload rather than a missing arrange.
		$this->makeAncestors($path);
		// Real ZIP magic — enough for holdsArchive() to recognise it, which is the
		// only thing the upload-vs-create guard looks at.
		$this->davPut($path, $this->aRealPenpotArchive());
		// AND IT IS ON STAGE NOW. An arrange that puts a file in the world seats the
		// cursor, exactly as `a design file named … in …` does — otherwise the very
		// next line, `When I move it to the trash`, has nothing to act on. Every
		// untracked scenario in delete.feature and rename.feature failed on that.
		$this->currentFilePath = $path;
		$this->currentFolder = dirname($path);
		$this->currentFileId = '';
		$this->gestureTarget = $path;
	}

	// ── delete ──────────────────────────────────────────────────────────────

	/**
	 * The SECOND step: empty the Nextcloud trash for this file. Fires the same
	 * event as the first delete, distinguished only by the node already living
	 * under files_trashbin — which is what makes this the irreversible one.
	 *
	 * NOT A STEP ANY MORE — no scenario in the suite says this sentence. It
	 * stays as the plain helper 1 other step calls.
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

	/**
	 * The last refused gesture, for the assertion to read.
	 *
	 * BOTH HALVES, FROM BOTH ENTRY POINTS. The body used to be filled in by the
	 * `into` form alone, so an assertion about the REASON would have read whatever
	 * the previous scenario left behind after a `to`-form refusal — stale, and
	 * passing for the wrong reason. Since #32 the reason is the interesting half,
	 * so neither form may leave it behind.
	 */
	private int $lastGestureStatus = 0;
	private string $lastGestureBody = '';

	// ── Nextcloud's trash ───────────────────────────────────────────────────

	/**
	 * One check, two sentences, because a purge and a restore both leave no trashbin
	 * entry and mean opposite things. "Gone from" reads for the destroyed case.
	 *
	 * NOT A STEP ANY MORE — no scenario in the suite says this sentence. It
	 * stays as the plain helper 4 other steps call.
	 */
	public function theFileIsNotInTheNextcloudTrash(string $path): void {
		// POLLS, for the reason the four Penpot assertions below poll — this is a
		// mutate-then-read of exactly that shape, and it was the one left out.
		//
		// The purge that removes the entry runs through Nextcloud's own trashbin
		// machinery after the gesture returns, so a single read can arrive while the
		// row is still there. Measured on `purge.feature`'s "Permanently delete a
		// design in Penpot", which failed on its Team Folder row while the identical
		// admin-folder row above it passed — the difference being how long a second
		// storage takes to catch up, which is a race and not a behaviour.
		//
		// Same message on failure as before, so a state that never arrives still
		// fails; it just stops failing when the state was merely late.
		$this->until(
			fn (): bool => $this->trashbinPathFor($path) === null,
			fn (): string => "expected no trashbin entry for '{$path}', but one is there",
		);
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
	 * The designs that were under the folder just trashed are in Penpot's trash.
	 *
	 * BY ID, NOT BY NAME, and that is the whole reliability of this assertion.
	 * Penpot state accumulates across a leg (teams are find-or-create and survive
	 * the scenario), so an earlier scenario's discarded `Alpha` is still sitting
	 * in the team's deleted list — and a name check would pass against a design
	 * this scenario never touched. This feature's own Background declares an
	 * `Alpha.penpot` that must NOT be trashed, so that is not a hypothetical.
	 *
	 * @Then /^those designs are in Penpot's trash$/
	 */
	public function thoseDesignsAreInPenpotsTrash(): void {
		if ($this->designIdsBeforeGesture === []) {
			throw new \RuntimeException(
				'the scenario says "those designs" but the gesture captured none — '
				. 'nothing under the trashed folder carried a penpot_id.',
			);
		}

		// The team the gesture happened in, not the default: this scenario is an
		// Outline over both a plain folder and a Team Folder.
		$team = $this->teamId(explode('/', trim($this->gestureTarget, '/'))[0]);

		$this->until(
			function () use ($team): bool {
				$trashed = $this->penpotTrashIds($team);
				foreach ($this->designIdsBeforeGesture as $id) {
					if (!in_array($id, $trashed, true)) {
						return false;
					}
				}

				return true;
			},
			function () use ($team): string {
				$trashed = $this->penpotTrashIds($team);
				$missing = [];
				foreach ($this->designIdsBeforeGesture as $path => $id) {
					if (!in_array($id, $trashed, true)) {
						$missing[] = "{$path} ({$id})";
					}
				}

				return 'expected every design under the trashed folder to be in Penpot\'s trash; '
					. 'still absent: ' . implode(', ', $missing);
			},
		);
	}

	/** @return list<string> the ids Penpot lists as deleted for this team */
	private function penpotTrashIds(string $teamId): array {
		$ids = [];
		foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $teamId]) as $file) {
			if (isset($file['id']) && is_string($file['id'])) {
				$ids[] = $file['id'];
			}
		}

		return $ids;
	}

	/**
	 * The ids in a team's Penpot trash that Penpot will actually GIVE BACK.
	 *
	 * NOT THE SAME SET AS {@see penpotTrashIds()}, and the difference is the whole
	 * reason `projects/purge.feature`'s Penpot-side scenarios can be asserted at all.
	 * `get-team-deleted-files` names the files of a DELETED PROJECT as well as the
	 * files deleted in their own right, and it goes on naming a design that has been
	 * destroyed for as long as its project stays deleted. Presence there is not
	 * recoverability.
	 *
	 * `will-be-deleted-at` is what separates them — absent means the file itself was
	 * never deleted, a future stamp means it is in the trash proper, and a stamp that
	 * has PASSED means it was destroyed and is not coming back. Measured live against
	 * a restore that Penpot claimed had succeeded and which returned nothing.
	 *
	 * This reads Penpot directly rather than asking the app, so it is an independent
	 * oracle for the same rule {@see \OCA\PenpotSync\Service\PenpotClient::recoverableFileIds()}
	 * implements.
	 *
	 * @return list<string>
	 */
	private function penpotRecoverableIds(string $teamId): array {
		$now = (int)(microtime(true) * 1000);

		$ids = [];
		foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $teamId]) as $file) {
			if (!isset($file['id']) || !is_string($file['id'])) {
				continue;
			}
			$due = $file['will-be-deleted-at'] ?? null;
			if ((is_string($due) || is_int($due) || is_float($due)) && (int)$due <= $now) {
				continue;
			}
			$ids[] = $file['id'];
		}

		return $ids;
	}

	/**
	 * The assertion that separates a soft delete from a destroyed one — and the
	 * one the purge guard exists to keep honest.
	 *
	 * @Then /^the design "([^"]*)" is not in Penpot's trash$/
	 */
	public function theDesignIsNotInPenpotsTrash(string $name): void {
		// BY ID WHEN THE SCENARIO HAS THAT DESIGN ON STAGE, and this is not an
		// optimisation — a name lookup cannot express the claim at all.
		//
		// Penpot's trash accumulates across a whole leg and nothing empties it: the
		// teardown after each scenario deletes the mapped project folders, and
		// deleting a project trashes its designs. So by the second row of an Outline
		// the FIRST row's design is sitting in the trash wearing the same name, and
		// the assertion answers about it instead. That cost four CI rounds on
		// `designs/move.feature`, every one of them read as the untrash failing while
		// the app's own log said plainly that it had worked.
		//
		// Purging the debris does not help either: `permanently-delete-team-files`
		// stamps `deleted_at` and leaves the record (§C6.11), so a destroyed design
		// is still listed. There is no way to make a name unique in that listing —
		// only to stop asking by name.
		//
		// The cursor's id is checked only when the cursor IS this design, so every
		// caller that names a design it never put on stage keeps the old behaviour.
		$onStage = $this->currentFilePath !== ''
			&& basename($this->currentFilePath, '.penpot') === $name
			&& $this->currentFileId !== '';

		if ($onStage) {
			$id = $this->currentFileId;
			$this->until(
				fn (): bool => !in_array($id, $this->penpotTrashIds($this->teamId()), true),
				fn (): string => sprintf(
					"expected the design '%s' (%s) to be gone from Penpot's trash, but it is still listed",
					$name,
					$id,
				),
			);

			return;
		}

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

	// ── what PENPOT actually holds ──────────────────────────────────────────

	// ── helpers ─────────────────────────────────────────────────────────────

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

	// ── the trash, said the way the rewritten spec says it ───────────────────

	/**
	 * "Move to the trash" is what the Files app calls deleting, and the spec now
	 * uses that wording because the recoverability is half the claim — a project
	 * folder's designs going to Penpot's trash is only safe if the folder can come
	 * back.
	 *
	 * @When /^I move "([^"]*)" to the trash$/
	 */
	public function iMoveToTheTrash(string $path): void {
		$this->captureIdBeforeGesture($path);
		// BEFORE the delete, because afterwards there is nothing left to read them
		// off. A folder trash is a gesture on every design below it, and "those
		// designs" in the Then has no other referent.
		$this->designIdsBeforeGesture = $this->designIdsBelow($path, 0);
		$this->davDelete($path);
		$this->gestureTarget = $path;
	}

	/**
	 * Every design id at or below $path, read from the app's own DAV properties.
	 *
	 * @return array<string, string> penpot_id keyed by the file's PATH, so a
	 *                               failure can say WHICH design is missing rather
	 *                               than printing a bare uuid.
	 *
	 *                               The path and not the basename: `+` merges by
	 *                               key, so two designs sharing a filename in two
	 *                               subfolders would collapse to one and this
	 *                               assertion would silently check less of the
	 *                               subtree than it claims to.
	 */
	private function designIdsBelow(string $path, int $depth): array {
		if ($depth > 20) {
			return [];
		}

		$path = trim($path, '/');
		if (str_ends_with($path, '.penpot')) {
			$id = (string)$this->davReadMetadata($path, 'penpot_id');

			return $id === '' ? [] : [$path => $id];
		}

		$found = [];
		try {
			$children = $this->davChildren($path);
		} catch (\Throwable) {
			// Not a folder, or gone. Either way it contributes no designs.
			return [];
		}

		foreach ($children as $child) {
			$found += $this->designIdsBelow($child, $depth + 1);
		}

		return $found;
	}

	/**
	 * The same gesture, expected to be REFUSED — see {@see iTryToMove()} for why a
	 * refusal needs its own step.
	 *
	 * @When /^I try to move "([^"]*)" to the trash$/
	 */
	public function iTryToMoveToTheTrash(string $path): void {
		$result = $this->davDeleteResult($path);
		$this->lastGestureStatus = $result['status'];
		$this->lastGestureBody = $result['body'];
		$this->gestureTarget = $path;
	}

	/**
	 * @Then /^the move is refused with a message$/
	 */
	public function theMoveIsRefusedWithAMessage(): void {
		$this->refusedWithAMessage('move');
	}

	/**
	 * The refusal actually stopped it — the node is still at the path it started.
	 *
	 * PAIRED WITH THE STATUS, NEVER INSTEAD OF IT. A 403 is what the guard says;
	 * this is whether Nextcloud listened. The two came apart once already in the
	 * Grafana sibling, where swapping the exception type for one that carried a
	 * message turned nine refusals into HTTP 201 — the message was perfect and the
	 * move went through. So a "refused" scenario asserts both halves.
	 *
	 * @Then /^"([^"]*)" stays where it was$/
	 */
	public function staysWhereItWas(string $path): void {
		if (!$this->davExists(trim($path, '/'))) {
			throw new \RuntimeException(
				"'{$path}' was supposed to stay put, but there is nothing there any more — "
				. 'the refusal was reported and the move happened anyway.',
			);
		}
	}

	/**
	 * @Then /^the trash is refused with a message$/
	 */
	public function theTrashIsRefusedWithAMessage(): void {
		$this->refusedWithAMessage('trash');
	}

	/**
	 * @Then /^the creation is refused with a message$/
	 */
	public function theCreationIsRefusedWithAMessage(): void {
		$this->refusedWithAMessage('creation');
	}

	/**
	 * One refusal check for every gesture that can be refused.
	 *
	 * THE BODY IS HALF THE CLAIM. Both of this app's refusals were invisible until
	 * #32 — the reason reached the log and the person got an empty 403 — so a
	 * scenario saying "with a message" is asserting that repair still holds, and a
	 * status-only check would pass against the bug.
	 */
	private function refusedWithAMessage(string $what): void {
		if ($this->lastGestureStatus < 400 || $this->lastGestureStatus >= 500) {
			throw new \RuntimeException(
				"expected the {$what} to be refused, got HTTP {$this->lastGestureStatus}",
			);
		}
		if (trim(strip_tags($this->lastGestureBody)) === '') {
			throw new \RuntimeException(
				"the {$what} was refused with HTTP {$this->lastGestureStatus} but an EMPTY body — "
				. 'that is the #32 bug, where the reason reached the log and never the client.',
			);
		}
	}

	/**
	 * The trash still holds it, so the gesture was recoverable.
	 *
	 * Matched through `nc:trashbin-filename` rather than by path, because core
	 * appends a `.dNNNNN` deletion stamp to the entry — see {@see trashbinPathFor()}.
	 *
	 * @Then /^"([^"]*)" is recoverable from the Nextcloud trash$/
	 */
	public function isRecoverableFromTheNextcloudTrash(string $path): void {
		if ($this->trashbinPathFor($path) === null) {
			throw new \RuntimeException("nothing in the Nextcloud trash came from '{$path}'");
		}
	}

	/**
	 * Every design the scenario declared is in the Nextcloud trash.
	 *
	 * THE PLURAL MATTERS, which is why this is not the singular step repeated. A
	 * project deleted in Penpot is one gesture over many designs, and "the designs
	 * are recoverable" is the claim that ALL of them landed somewhere recoverable —
	 * a check on one would pass while the rest were destroyed.
	 *
	 * Matched through `nc:trashbin-filename` and the original location, so a design
	 * of the same name trashed by an earlier scenario cannot answer for this one
	 * ({@see trashbinPathFor()}).
	 *
	 * @Then /^the designs are recoverable from the Nextcloud trash$/
	 */
	public function theDesignsAreRecoverableFromTheNextcloudTrash(): void {
		if ($this->lastDeclaredDesigns === []) {
			throw new \RuntimeException(
				'"the designs are recoverable" has no designs to talk about — the scenario '
				. 'declared none.',
			);
		}

		$missing = [];
		foreach ($this->lastDeclaredDesigns as $path) {
			if ($this->trashbinPathFor($path) === null) {
				$missing[] = $path;
			}
		}

		if ($missing !== []) {
			throw new \RuntimeException(
				'nothing in the Nextcloud trash came from: ' . implode(', ', $missing),
			);
		}
	}

	// ── "+ New → Penpot design", by folder rather than by path ───────────────

	/**
	 * The spec names the FOLDER and lets the app pick the filename, because that is
	 * what the button does — `New design.penpot` is core's, not the scenario's.
	 *
	 * THE FOLDER IS MADE IF IT IS NOT THERE, the same way {@see iMoveTheFileInto()}
	 * makes a drag's destination. A person clicking "+ New" is standing IN a
	 * folder, so a scenario naming one is describing where they are, not asking for
	 * it to be created — and `Penpot/Make Here/wip` is a plain subfolder that no
	 * Background declares, because the whole point of that row is that a plain
	 * subfolder is not a project. Without this the step 404s on the PUT and the
	 * scenario fails at the arrange rather than at its claim.
	 *
	 * @When /^I create a new design in "([^"]*)"$/
	 */
	public function iCreateANewDesignIn(string $folder): void {
		$folder = trim($folder, '/');
		if ($folder !== '') {
			$this->makeAncestors($folder . '/x');
		}
		$path = ($folder === '' ? '' : $folder . '/') . self::NEW_DESIGN;
		$this->davPut($path, '');
		$this->gestureTarget = $path;
		$this->currentFilePath = $path;
		$this->currentFolder = $folder;
		$this->currentFileId = $this->davReadMetadata($path, 'penpot_id') ?? '';
	}

	/**
	 * The same act where the app is expected to say no.
	 *
	 * IT SNAPSHOTS PENPOT FIRST, because the scenarios using it go on to say "no
	 * design is created in Penpot" and there is no sentence in the spec where a
	 * snapshot would belong. Taking it here keeps harness bookkeeping out of the
	 * specification; the alternative is a `Given` that exists only for the harness,
	 * which is the thing this suite's Backgrounds were rewritten to stop doing.
	 *
	 * @When /^I try to create a new design in "([^"]*)"$/
	 */
	public function iTryToCreateANewDesignIn(string $folder): void {
		$this->designIdsBeforeRefusal = $this->penpotLiveDesignIds();
		$path = trim($folder, '/') . '/' . self::NEW_DESIGN;
		$res = $this->davClient()->request('PUT', $this->davEncode($path), ['body' => '']);
		$this->lastGestureStatus = $res->getStatusCode();
		$this->lastGestureBody = (string)$res->getBody();
		$this->gestureTarget = $path;
	}
	// ── the trash, said about the file the scenario has on stage ─────────────

	/**
	 * Trash THE file — the cursor's.
	 *
	 * The cursor twin of {@see iMoveToTheTrash()}, and it snapshots Penpot's design
	 * ids for the same reason the create refusal does: the untracked scenarios go on
	 * to say "no design is deleted in Penpot", and there is no sentence in the spec
	 * where a "before" would belong.
	 *
	 * @When /^I move it to the trash$/
	 */
	public function iMoveItToTheTrash(): void {
		$path = $this->currentFile();
		$this->designIdsBeforeRefusal = $this->penpotLiveDesignIds();
		// READ BEFORE THE DELETE, because afterwards the path resolves to nothing
		// and the trashbin endpoint is a different DAV root that does not carry
		// these properties. `it still holds no Penpot metadata` is a claim about
		// what the app stamped on the way out, and this is the last moment it can
		// be read.
		$this->penpotKeysAtTrashTime = $this->penpotKeysOn($path);
		$this->captureIdBeforeGesture($path);
		$this->davDelete($path);
		$this->gestureTarget = $path;
	}

	/**
	 * The same gesture, expected to be REFUSED.
	 *
	 * @When /^I try to move it to the trash$/
	 */
	public function iTryToMoveItToTheTrash(): void {
		$path = $this->currentFile();
		$result = $this->davDeleteResult($path);
		$this->lastGestureStatus = $result['status'];
		$this->lastGestureBody = $result['body'];
		$this->gestureTarget = $path;
	}

	/**
	 * The cursor's file survived, in the Nextcloud trash.
	 *
	 * MATCHED THROUGH `nc:trashbin-filename`, like its path-form twin, because core
	 * appends a `.dNNNNN` deletion stamp to the entry.
	 *
	 * @Then /^the file is recoverable from the Nextcloud trash$/
	 */
	public function theFileIsRecoverableFromTheNextcloudTrash(): void {
		$path = $this->currentFilePath;
		if ($this->trashbinPathFor($path) === null) {
			throw new \RuntimeException("nothing in the Nextcloud trash came from '{$path}'");
		}
	}

	/**
	 * The delete reached nothing on the far side.
	 *
	 * COUNTED ACROSS THE WHOLE INSTANCE and BY ID: an untracked file names no
	 * project, so there is nowhere narrower to look, and Penpot state accumulates
	 * across a leg so a name check would answer about the wrong design.
	 *
	 * @Then /^no design is deleted in Penpot$/
	 */
	public function noDesignIsDeletedInPenpot(): void {
		$gone = array_values(array_diff($this->designIdsBeforeRefusal, $this->penpotLiveDesignIds()));
		if ($gone !== []) {
			throw new \RuntimeException(sprintf(
				'the trash was supposed to reach no design, and Penpot lost %d: %s',
				count($gone),
				implode(', ', $gone),
			));
		}
	}

	/**
	 * A project holds no design by this name — said the way `designs/delete.feature`
	 * says it, with the project first.
	 *
	 * A SECOND SPELLING OF ONE CLAIM, and deliberately not deduplicated into
	 * `Penpot project "X" holds no design named "Y"`. The two read differently in
	 * their own scenarios ("the `Bin Me` Penpot project" is a noun phrase mid
	 * sentence; the other opens one) and Behat matches on text, so collapsing them
	 * would mean rewriting a Gherkin line to suit a regex. That is the wrong way
	 * round — see features/README.md on the vocabulary.
	 *
	 * @Then /^the "([^"]*)" Penpot project holds no design named "([^"]*)"$/
	 */
	public function theNamedPenpotProjectHoldsNoDesignNamed(string $project, string $design): void {
		$this->until(
			fn (): bool => !in_array($design, $this->penpotFileNamesIn($project), true),
			fn (): string => sprintf(
				"the Penpot project '%s' still holds a design named '%s'; it holds: %s",
				$project,
				$design,
				implode(', ', $this->penpotFileNamesIn($project)) ?: '(nothing)',
			),
		);
	}

	/**
	 * The design behind the cursor is erased in Penpot, past its trash.
	 *
	 * @Given /^its design is permanently deleted in Penpot$/
	 */
	public function itsDesignIsPermanentlyDeletedInPenpot(): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException('no design is on stage to delete in Penpot');
		}
		$this->permanentlyDeleteDesignById($this->currentFileId);
	}

	/**
	 * Someone deletes the cursor's design in Penpot, and the sync carries the news.
	 *
	 * NAMED FOR THE CURSOR, not for the sentence. {@see PruneSteps} already has a
	 * `someoneDeletesTheDesignInPenpot()` for the path form, and two traits cannot
	 * contribute one method name to the same class — PHP fatals on the collision
	 * before Behat sees a single scenario, which is how this took out all four legs
	 * at once rather than failing one test.
	 *
	 * @When /^someone deletes the design in Penpot$/
	 */
	public function someoneDeletesTheCursoredDesignInPenpot(): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException('no design is on stage to delete in Penpot');
		}
		$this->penpotRpc('delete-file', ['id' => $this->currentFileId]);
		$this->theAdminRunsAPull();
	}

	/**
	 * The file is no longer at that path.
	 *
	 * @Then /^the file is gone from "([^"]*)"$/
	 */
	public function theFileIsGoneFrom(string $folder): void {
		$folder = trim($folder, '/');
		$name = basename($this->currentFilePath);
		$this->until(
			fn (): bool => !$this->davExists($folder . '/' . $name),
			fn (): string => sprintf("'%s/%s' is still there", $folder, $name),
		);
	}

	/**
	 * The file still carries what this app stored on it.
	 *
	 * The mirror image of `the file holds no Penpot metadata at all`: after a
	 * gesture the app could not complete, the identity must survive intact, because
	 * an id lost here is a design nothing points at any more.
	 *
	 * @Then /^the file keeps its Penpot metadata$/
	 */
	public function theFileKeepsItsPenpotMetadata(): void {
		$path = $this->currentFile();
		$id = $this->davReadMetadata($path, 'penpot_id') ?? '';
		if ($id === '') {
			throw new \RuntimeException("'{$path}' survived the gesture but lost its Penpot id");
		}
		if ($this->currentFileId !== '' && $id !== $this->currentFileId) {
			throw new \RuntimeException(
				"'{$path}' carries {$id}, but the scenario put {$this->currentFileId} on stage",
			);
		}
	}
	/**
	 * Bytes that are genuinely a `.penpot` export, not a ZIP header and padding.
	 *
	 * ## WHY THE FAKE STOPPED BEING GOOD ENOUGH
	 *
	 * `PK\x03\x04` plus nulls satisfies {@see ArchiveService::holdsArchive()},
	 * which reads four magic bytes — and that was all any scenario needed while an
	 * archive arriving in a mapping was ignored. §6.33 changed what those bytes
	 * MEAN: they are now imported, and Penpot refuses anything that is not a real
	 * export. A fixture that cannot be imported would make every import scenario
	 * fail for the fixture's reason rather than the app's.
	 *
	 * So a real one is produced the only way this suite can produce one: a design
	 * is made in Penpot, the pull mirrors it as a `sync` file, and those bytes are
	 * read back off disk. The mirror is left where it is — it belongs to a project
	 * no scenario asserts on, and removing it would delete the design in Penpot.
	 *
	 * Cached for the scenario, because it costs a create, a pull and an export.
	 */
	private function aRealPenpotArchive(): string {
		if ($this->realArchive !== '') {
			return $this->realArchive;
		}

		$root = 'Penpot';
		$folder = $root . '/Archive Source';
		$path = $folder . '/Source.penpot';
		if (!$this->davExists($path)) {
			$this->makeAncestors($path);
			$this->davPut($path, '');
			$this->theAdminRunsAPull();
		}

		$bytes = $this->davGet($path);
		if (!str_starts_with($bytes, "PK\x03\x04")) {
			throw new \RuntimeException(
				"the harness could not produce a real .penpot archive: '{$path}' holds "
				. strlen($bytes) . ' bytes that are not a ZIP. Every import fixture depends on this.',
			);
		}

		return $this->realArchive = $bytes;
	}

	/** The archive {@see aRealPenpotArchive()} produced, for this scenario. */
	private string $realArchive = '';
}
