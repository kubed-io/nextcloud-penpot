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
 *   gone for good   → `Penpot has no design for it`
 *
 * The middle one is deliberately an ASSERTION rather than an arrangement. The app
 * put the design there when the file was trashed, so a step that "made" it true
 * would be re-doing the app's work and could pass while the app did nothing.
 *
 * ## PENPOT'S PURGE LEAVES THE ROW, AND THE LISTING STILL DROPS IT (§C6.11)
 *
 * Both halves matter and they are easy to conflate. `permanently-delete-team-files`
 * stamps `deleted-at` to NOW and queues a worker to collect the row later, so the
 * record survives the call — which is why `delete-file` on a destroyed id
 * RESURRECTS it into the trash (the `@blocked` scenario in `delete.feature`).
 *
 * But `get-team-deleted-files` filters `f.deleted_at > now`, so the destroyed
 * design leaves the trash listing immediately while an ordinarily trashed one
 * (a week out) stays. Read off `app/rpc/commands/files.clj` in the running backend
 * rather than inferred, because the app's reap now turns on exactly that gap.
 *
 * What follows from it: a design absent from the trash listing is EITHER live OR
 * destroyed, never parked — so where the spec means erased, the check is that the
 * design is absent from its PROJECT too, which is the listing the pull reads.
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

	/**
	 * A NAMED path is in the Nextcloud trash — by being put there.
	 *
	 * The same sentence as the cursored form above and the same reasoning: a
	 * `Given` states what is true, and a folder gets into the trash exactly one
	 * way. It takes a path because the subject is a FOLDER, and the cursor is a
	 * design's — there is nothing for it to point at here.
	 *
	 * ## WHAT HAPPENS IN PENPOT IS THE APP'S DOING, AND IS NOT ARRANGED HERE
	 *
	 * Trashing a project folder makes the app delete the project
	 * ({@see \OCA\PenpotSync\Service\DeletionService::onFolderTrashed()}), which
	 * soft-deletes it and parks its designs in the team's trash. That is the state
	 * every scenario after this line depends on, and it is deliberately NOT set up
	 * by hand: an arrange that deleted the project itself would prove the restore
	 * against a state no gesture can produce, and would go green on a build where
	 * trashing a folder had stopped reaching Penpot at all.
	 *
	 * @Given /^"([^"]*)" is in the Nextcloud trash$/
	 */
	public function theNamedPathIsInTheNextcloudTrash(string $path): void {
		$path = ltrim($path, '/');
		if (!$this->davExists($path)) {
			throw new \RuntimeException("'{$path}' is not there to be trashed");
		}

		// BEFORE the delete, because afterwards there is nothing left to read them
		// off — the same capture {@see GestureSteps::iMoveToTheTrash()} makes, and for
		// the same reason: `no design it held is left in Penpot's trash` has no other
		// referent once the folder is in the trash and its paths are gone.
		$this->designIdsBeforeGesture = $this->designIdsBelow($path, 0);
		// AND THE TEAM, for the same reason. `cursorTeamId()` falls back to the
		// `Penpot` mapping, which is right for these scenarios by luck rather than by
		// construction; a folder trashed out of `Shared` would have quietly asked the
		// wrong team.
		$this->teamBeforeTrashing = $this->teamId(explode('/', $path)[0]);
		$this->gestureTarget = $path;

		$this->davDelete($path);

		// POLLED, because the entry appears through the trashbin's own machinery
		// after the DELETE returns — the same race {@see GestureSteps::theFileIsNotInTheNextcloudTrash()}
		// documents from the other side, and on a Team Folder it is a second storage
		// catching up rather than an app doing anything.
		$this->until(
			fn (): bool => $this->trashbinPathFor($path) !== null,
			fn (): string => "'{$path}' was deleted but never appeared in the Nextcloud trash",
		);
	}

	/**
	 * It is NOT in the Nextcloud trash — because something took it back out.
	 *
	 * The assertion the revive exists for: the pull found the project alive in
	 * Penpot again and lifted its folder out of the trash rather than building a
	 * second one beside it ({@see \OCA\PenpotSync\Service\PullService::revivedProjectFolder()}).
	 *
	 * "Not in the trash" and "back where it was" are the same claim HERE and only
	 * here, because a restore has no other destination — Nextcloud puts a trashed
	 * node back where it came from and offers no say in the matter.
	 *
	 * @Then /^"([^"]*)" is not in the Nextcloud trash$/
	 */
	public function theNamedPathIsNotInTheNextcloudTrash(string $path): void {
		$this->theFileIsNotInTheNextcloudTrash(ltrim($path, '/'));
	}

	/**
	 * Someone restores ONE design of a deleted project in Penpot.
	 *
	 * ## "ONLY" IS THE WHOLE POINT OF THE SENTENCE
	 *
	 * The claim under test is that a project needs just one of its designs back to
	 * come back itself — Penpot clears the PROJECT's `deleted_at` as a side effect
	 * of clearing the file's. Restoring both designs would make the scenario pass
	 * for a reason that proves nothing, so the step names one and the assertion
	 * that its sibling is still in the trash guards the difference.
	 *
	 * ## CONFIRMED AGAINST THE PROJECT LISTING, NEVER AGAINST THE TRASH
	 *
	 * The two disagree, and §6.49 is the whole reason
	 * {@see \OCA\PenpotSync\Service\RestoreService} exists in the shape it does:
	 * the restore's SSE returns before Penpot's transaction settles, so the design
	 * leaves `get-team-deleted-files` while its project is still deleted. A second
	 * call settles it — the app logs *"the design came back on a second call"* doing
	 * exactly this.
	 *
	 * The first cut of this step confirmed against the TRASH, returned after one
	 * call, and pulled into that window. The pull then saw no such project, so it
	 * never looked for its folder, and the failure surfaced two steps later as
	 * "Penpot holds no project named …". `RestoreServiceTest` has a test pinning
	 * this exact distinction for the app; the harness owed it the same discipline.
	 *
	 * `penpotLiveDesignIds()` is the right oracle because the probe prints designs
	 * UNDER their projects: a design whose project is still deleted is not in it, so
	 * one check answers "the design is back" and "its project is back" together.
	 *
	 * Then a pull, because reviving the folder is the PULL's work, not the RPC's.
	 *
	 * @When /^someone restores only "([^"]*)" in Penpot$/
	 */
	public function someoneRestoresOnlyInPenpot(string $name): void {
		[$team, $id] = $this->parkedDesignNamed($name);

		for ($attempt = 0; $attempt < 3; $attempt++) {
			$this->penpotRpc('restore-deleted-team-files', ['team-id' => $team, 'ids' => [$id]]);
			if (in_array($id, $this->penpotLiveDesignIds(), true)) {
				$this->theAdminRunsAPull();

				return;
			}
		}

		throw new \RuntimeException(
			"Penpot accepted restore-deleted-team-files for {$id} three times and '{$name}' is still not "
			. "listed in a live project of team {$team}",
		);
	}

	/**
	 * The team and design id of a design sitting in some mapped team's Penpot
	 * trash, for the design the scenario NAMED.
	 *
	 * NOT THE CURSOR, because the design's mirror went into the trash inside a
	 * FOLDER — its path is gone and the cursor points at whatever the arrange
	 * touched last. The scenario says which design it means, and the arrange
	 * already wrote that name's id down.
	 *
	 * ## THE NAME PICKS THE ID OUT OF THE ARRANGE, IT DOES NOT SEARCH THE TRASH
	 *
	 * A trash listing searched by name answers with whatever is in there wearing
	 * that name, and by the time this runs plenty is. Penpot state accumulates
	 * across a leg and nothing empties either trash, so every earlier scenario in
	 * the file has parked its own `Alpha` — the Background alone declares one and
	 * the next scenario's `emptyMappedFolder()` throws it away. A by-name search
	 * finds all of them and can only refuse; the first cut of this method did
	 * exactly that and would have thrown on its own fixture every run.
	 *
	 * `declaredDesignIds` is keyed by filename and holds the id the arrange read
	 * back, which is the one thing that says THIS `Alpha` rather than a leg's worth
	 * of dead ones. Penpot's listing is then only asked which team holds it, which
	 * is a question a uuid can answer unambiguously — the same reason
	 * {@see ArrangeSteps::penpotProjectIn()} is team-scoped, reached from the other
	 * end.
	 *
	 * POLLED, like every other read that follows a mutation here: the design gets
	 * into Penpot's trash because trashing the FOLDER made the app delete the
	 * project, and the files of a deleted project are not listed as deleted the
	 * instant the RPC returns.
	 *
	 * @return array{0: string, 1: string} team id, design id
	 */
	private function parkedDesignNamed(string $name): array {
		$id = $this->declaredDesignIds[$name . '.penpot'] ?? '';
		if ($id === '') {
			throw new \RuntimeException(
				"the scenario names the design '{$name}', but no arrange declared one — "
				. 'say `the following items in the mappings` first so its id is known',
			);
		}

		$team = '';
		$this->until(
			function () use ($id, &$team): bool {
				foreach (array_unique($this->mappingTeamIds) as $candidate) {
					if (in_array($id, $this->penpotTrashIds($candidate), true)) {
						$team = $candidate;

						return true;
					}
				}

				return false;
			},
			fn (): string => "the design '{$name}' ({$id}) is in no mapped team's Penpot trash — "
				. 'trashing its folder was supposed to put it there',
		);

		return [$team, $id];
	}

	/**
	 * Empty a NAMED path out of the Nextcloud trash, for good.
	 *
	 * The path twin of {@see iPurgeItFromTheTrash()}, and it exists for the same
	 * reason the trash-arrange above does: the subject here is a FOLDER, and the
	 * cursor is a design's.
	 *
	 * EVERY ENTRY FOR THE PATH, exactly as the cursor form does — see its comment
	 * for why one deletion is not one entry. And it REFUSES an empty trash rather
	 * than passing: "I purge X" that found nothing to purge is a fixture that did
	 * not arrange what it said, and the assertions after it would all be trivially
	 * true.
	 *
	 * @When /^I purge "([^"]*)" from the trash$/
	 */
	public function iPurgeTheNamedPathFromTheTrash(string $path): void {
		$path = ltrim($path, '/');
		$purged = 0;
		while ($this->trashbinPathFor($path) !== null) {
			$this->iPurgeFromTheNextcloudTrash($path);
			if (++$purged > 10) {
				throw new \RuntimeException("the trashbin keeps producing entries for '{$path}'");
			}
		}

		if ($purged === 0) {
			throw new \RuntimeException("'{$path}' is not in the Nextcloud trash, so there was nothing to purge");
		}
		$this->gestureTarget = $path;
	}

	/**
	 * The purge reached every design the folder held — none of them can be brought
	 * back any more.
	 *
	 * ## IT ASKS BY TRYING, BECAUSE THE TRASH LISTING CANNOT ANSWER
	 *
	 * The obvious check — "no design it held is left in Penpot's trash" — is not
	 * writable, and that took a live instance to establish. While the PROJECT is
	 * deleted, `get-team-deleted-files` lists its files whatever their own state, so
	 * a design destroyed a second ago sits in that listing beside one that is
	 * perfectly recoverable. The two are indistinguishable there, and `fileExists()`
	 * cannot separate them either — `get-file-summary` answers NOT-FOUND for any row
	 * carrying a `deleted_at`, past or future.
	 *
	 * What DOES separate them is whether Penpot will give the design back. So this
	 * asks it to: one `restore-deleted-team-files` for every id the folder held, and
	 * then the claim is that none of them became live. A design that was really
	 * destroyed is a no-op; one that was not comes back AND revives its project,
	 * which is exactly the failure this is here to catch.
	 *
	 * A MUTATING `Then`, named so the reader can see it. Ordinarily that would be a
	 * gesture smuggled into an assertion; here the mutation IS the question, and the
	 * step says `can be brought back` rather than `is gone` for that reason.
	 *
	 * BY ID, AND EVERY ONE OF THEM. The ids were captured before the folder went
	 * into the trash ({@see theNamedPathIsInTheNextcloudTrash()}), which is the only
	 * moment they were readable. A name check could not do this job at all: Penpot
	 * state accumulates across a leg, so an earlier scenario's `Alpha` is sitting in
	 * the same trash and would answer for this one's.
	 *
	 * @Then /^no design it held can be brought back in Penpot$/
	 */
	public function noDesignItHeldCanBeBroughtBackInPenpot(): void {
		if ($this->designIdsBeforeGesture === []) {
			throw new \RuntimeException(
				'the scenario says "no design it held" but the trash arrange captured none — '
				. 'nothing under the folder carried a penpot_id.',
			);
		}

		$team = $this->cursorTeamId();
		$ids = array_values($this->designIdsBeforeGesture);
		$this->penpotRpc('restore-deleted-team-files', ['team-id' => $team, 'ids' => $ids]);

		$this->until(
			fn (): bool => array_intersect($ids, $this->penpotLiveDesignIds()) === [],
			function () use ($ids): string {
				$back = array_intersect($ids, $this->penpotLiveDesignIds());
				$named = [];
				foreach ($this->designIdsBeforeGesture as $path => $id) {
					if (in_array($id, $back, true)) {
						$named[] = "{$path} ({$id})";
					}
				}

				return 'expected the purge to have destroyed every design the folder held, but '
					. 'Penpot restored: ' . implode(', ', $named);
			},
		);
	}

	/**
	 * A NAMED path is out of the Nextcloud trash — gone for good.
	 *
	 * @Then /^"([^"]*)" is gone from the Nextcloud trash$/
	 */
	public function theNamedPathIsGoneFromTheNextcloudTrash(string $path): void {
		$this->theFileIsNotInTheNextcloudTrash(ltrim($path, '/'));
	}

	/**
	 * The trash entry is still there, and it still holds the thing that spared it.
	 *
	 * ## BOTH HALVES, BECAUSE EITHER ALONE PASSES FOR THE WRONG REASON
	 *
	 * "Still in the trash" alone would pass on a folder the reap had emptied of
	 * everything and left standing, which is precisely the outcome this scenario
	 * exists to rule out — a spreadsheet destroyed because a project it happened to
	 * sit beside was purged in Penpot. Naming the survivor is the claim.
	 *
	 * NOT POLLED, and that is deliberate rather than an oversight. The reap runs
	 * inside the pull that the `When` already ran and returned from, so the decision
	 * is made by the time this reads. Polling a "still" claim only re-reads a state
	 * that is already true and would hide nothing — while a poll that waited for it
	 * to BECOME true would be asserting the opposite of the sentence.
	 *
	 * @Then /^"([^"]*)" is still in the Nextcloud trash, holding "([^"]*)"$/
	 */
	public function theNamedPathIsStillInTheNextcloudTrashHolding(string $path, string $child): void {
		$path = ltrim($path, '/');
		$entry = $this->trashbinPathFor($path);
		if ($entry === null) {
			throw new \RuntimeException(
				"'{$path}' was supposed to stay in the Nextcloud trash, and there is no entry for it — "
				. 'something purged a folder that still held a file Penpot never had.',
			);
		}

		$held = $this->trashbinChildren($entry);
		if (!in_array($child, $held, true)) {
			throw new \RuntimeException(sprintf(
				"'%s' is still in the trash but no longer holds '%s'; it holds: %s",
				$path,
				$child,
				implode(', ', $held) ?: '(nothing)',
			));
		}
	}

	/**
	 * The names directly inside one trash entry.
	 *
	 * A trashed folder is browsable over the trashbin DAV endpoint exactly like any
	 * collection, which is the only way to see inside one from out here — its
	 * children are not trash entries of their own, so they never appear in a listing
	 * of the trash root.
	 *
	 * @return list<string>
	 */
	private function trashbinChildren(string $entry): array {
		$res = $this->davClient()->request('PROPFIND', $this->trashHref($entry), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
				. '<d:prop><nc:trashbin-filename/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "trashbin PROPFIND {$entry}");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', 'http://nextcloud.org/ns');

		$names = [];
		foreach ($doc->xpath('//d:response') ?: [] as $resp) {
			$resp->registerXPathNamespace('d', 'DAV:');
			$resp->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
			$href = rawurldecode(trim((string)(($resp->xpath('d:href') ?: [])[0] ?? '')));
			$name = basename(rtrim($href, '/'));
			// The collection answers for ITSELF as well as its children at Depth 1.
			if ($name === '' || $name === $entry) {
				continue;
			}
			$names[] = $name;
		}

		return $names;
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
	 * Nothing in Penpot answers to this file's id any more.
	 *
	 * SAID AS A STATE, NOT AS A HISTORY. The scenario turns on what Penpot can be
	 * asked — the design is not live and not in the trash — and never on how it got
	 * that way; erased past the grace window and destroyed by hand are the same
	 * question to the app, which is why they are not two scenarios.
	 *
	 * Arranged by {@see GestureSteps::itsDesignIsPermanentlyDeletedInPenpot()}
	 * because that is the only way to reach the state on a live Penpot. One claim,
	 * so this is an alias and not a second implementation.
	 *
	 * @Given /^Penpot has no design for it$/
	 */
	public function penpotHasNoDesignForIt(): void {
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
		// EVERY ENTRY FOR THIS PATH, not the first one.
		//
		// `trashbinPathFor()` narrows to the folder the file was deleted FROM, so a
		// same-named file from anywhere else is already excluded. What it cannot
		// separate is two deletions of the SAME path — an Outline row that trashes
		// `Penpot/Purge Me/X.penpot`, and the next row trashing a fresh file at that
		// identical path. Nextcloud keeps both, distinguished only by the `.dNNNNN`
		// stamp it appends, and neither is more "the" entry than the other.
		//
		// Emptying every entry for the path is both what the scenario means and the
		// only thing that can be asserted afterwards: leaving one behind made
		// `the file is gone from the Nextcloud trash` fail against a leftover the
		// purge was never given.
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
	 * Somebody empties Penpot's whole trash, from Penpot.
	 *
	 * THE BULK SENTENCE IS STILL A REAL ONE, and it is not the individual purge
	 * below wearing different words. `projects/purge.feature` needs every design
	 * under a trashed project folder to go at once — one design going is not that
	 * claim, and would leave the folder with something left to be restored to.
	 *
	 * Its two scenarios are `@todo`, so nothing runs this yet; it exists because a
	 * step the spec still says has to have a definition, or promoting those rows
	 * becomes an undefined-step failure rather than a test. Raised in review on #46.
	 *
	 * EVERY ID IN THE LISTING, which is both what emptying means and what keeps the
	 * suite to the rule it holds the app to (§C6.11: the destroy command has no
	 * safety of its own, so its ids may only ever come from a real trash listing).
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
	 * Somebody destroys the cursor's design in Penpot, and the sync carries the news.
	 *
	 * ONE DESIGN, NOT THE WHOLE BIN. Penpot's UI offers both, and the scenario means
	 * the individual one — a person selecting a design in the trash and deleting it
	 * for good. Emptying the bin is the same act repeated, so the app cannot tell
	 * them apart and neither should the spec.
	 *
	 * THE ID COMES OFF THE TRASH LISTING, which is the rule the app holds itself to
	 * (§C6.11: the destroy command has no safety of its own, and will happily
	 * destroy a LIVE design if handed one). This suite holds itself to it too.
	 *
	 * NAMED FOR THE CURSOR, not for the sentence: {@see PruneSteps} already has a
	 * `someonePermanentlyDeletesTheDesignInPenpot()` for the path form, and two
	 * traits cannot contribute one method name to the same class — PHP fatals when
	 * FeatureContext composes them, taking every leg out at once.
	 *
	 * @When /^someone permanently deletes the design in Penpot$/
	 */
	public function someonePermanentlyDeletesTheCursoredDesignInPenpot(): void {
		$team = $this->cursorTeamId();
		$id = $this->cursorDesignId();
		if (!in_array($id, $this->penpotTrashIds($team), true)) {
			throw new \RuntimeException("the design {$id} is not in team {$team}'s trash, so it cannot be destroyed from there");
		}

		// TWICE IF NEED BE, AND CONFIRMED BY RE-READING. §6.49 recorded this shape on
		// the restore twin — Penpot answered `end` while the row was unchanged, and a
		// second call settled it. Success is not proof of success on these commands.
		for ($attempt = 0; $attempt < 3; $attempt++) {
			$this->penpotRpc('permanently-delete-team-files', ['team-id' => $team, 'ids' => [$id]]);
			if (!in_array($id, $this->penpotTrashIds($team), true)) {
				$this->theAdminRunsAPull();

				return;
			}
		}

		throw new \RuntimeException(
			"Penpot accepted permanently-delete-team-files for {$id} three times and it is still in team {$team}'s trash",
		);
	}

	/**
	 * Somebody restores the cursor's design in Penpot, and the sync carries the news.
	 *
	 * ## CONFIRMED BEFORE THE PULL, OR THE PULL HAS NOTHING TO CARRY
	 *
	 * §6.49's gotcha, which this step was the last call site not to hold itself to:
	 * `restore-deleted-team-files` answers 200 with an `end` event while
	 * `deleted_at` is STILL SET, and a pull run in that window reads a design that
	 * is still trashed and — correctly — brings nothing back. The assertion that
	 * follows then polls WebDAV for ten seconds, which can never turn true: the
	 * pull is one `occ` invocation that has already finished and decided. So the
	 * wait is dead time and the leg goes red on an app that did the right thing.
	 *
	 * The fix is the shape {@see someonePermanentlyDeletesTheCursoredDesignInPenpot()}
	 * already uses on the delete twin — ask again until a RE-READ of the trash
	 * agrees, never the `end` event — and only then pull. {@see itsDesignIsNotInPenpotsTrash()}
	 * confirms the same way; this step was the odd one out.
	 *
	 * @When /^someone restores the design in Penpot$/
	 */
	public function someoneRestoresTheDesignInPenpot(): void {
		$team = $this->cursorTeamId();
		$id = $this->cursorDesignId();

		for ($attempt = 0; $attempt < 3; $attempt++) {
			$this->penpotRpc('restore-deleted-team-files', ['team-id' => $team, 'ids' => [$id]]);
			if (!in_array($id, $this->penpotTrashIds($team), true)) {
				// The design is live again. NOW the pull has something to find.
				$this->theAdminRunsAPull();

				return;
			}
		}

		throw new \RuntimeException(
			"Penpot accepted restore-deleted-team-files for {$id} three times and it is still in team {$team}'s trash",
		);
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
	 * AND RE-READS THE ID, exactly as {@see GestureSteps::iMoveTheFileInto()} does
	 * after a drag. Layers 1 and 2 bring the file back wearing the id it went in
	 * with, and reading it back is how the next assertion can prove that; layer 3
	 * imports, so the id it comes back with is a NEW one and every step after this
	 * has to mean that design rather than the dead id the cursor was holding.
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
		$this->currentFileId = $this->davReadMetadata($path, 'penpot_id') ?? '';
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
