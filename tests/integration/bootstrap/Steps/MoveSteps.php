<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * The leaving-and-returning half of `designs/move.feature`.
 *
 * ## WHAT THESE STEPS ARE ACTUALLY PINNING
 *
 * A design that leaves every mapping is PARKED in Penpot's trash rather than left
 * sitting in a project nobody mirrors, and the `penpot_id` stays on the file so
 * that coming back is a reattach rather than an import. The whole slice turns on
 * one question — *does the id on this file still name a design?* — and these steps
 * arrange each of the three answers.
 *
 * ## THE ARRANGEMENTS USE REAL GESTURES, NOT STAMPED METADATA
 *
 * "An unmapped design file carrying its Penpot id" could be faked by writing DAV
 * properties onto a file directly, and that would be quicker and worthless: the
 * point of the assertion is that the app produced that state, so a fixture that
 * hand-writes it proves only that the fixture can write properties.
 *
 * So the file gets there the way a person would put it there — created in a mapped
 * folder, then dragged out to `Scratch`. That does mean the arrange for the RETURN
 * scenarios exercises the same `park()` the LEAVE scenario asserts on. That is not
 * circular in a way that matters: `Move a design out of every mapping` proves the
 * leave independently, and if park() ever stops parking, that scenario fails first
 * and loudest. What these get in exchange is that no state is asserted here which
 * the app cannot actually produce.
 *
 * ## `permanently-delete-team-files` IS THE ONLY WAY TO KILL AN ID
 *
 * Penpot's trash keeps a design exportable and restorable, so "an id no design
 * answers to" cannot be arranged by deleting — only by purging. That command is
 * the one irreversible call in the app and it takes ids from a trash listing and
 * nowhere else ({@see \OCA\PenpotSync\Service\PenpotClient::permanentlyDeleteFiles}),
 * which is exactly the shape of the arrangement here: park it first, then purge
 * what the parking put in the trash.
 */
trait MoveSteps {
	/** The id the file on stage arrived carrying, before the app replaced it. */
	private string $idArrivedWith = '';

	/**
	 * The team the parked design belongs to.
	 *
	 * CAPTURED DURING THE ARRANGE, because by the time a step needs it the file is
	 * in `Scratch` and `teamIdForPath()` has nothing to resolve — Scratch is mapped
	 * to nothing, which is the entire point of it. Reading it late sent a `nil`
	 * team to `restore-deleted-team-files` and Penpot answered with a schema error.
	 */
	private string $parkedTeamId = '';

	/** @BeforeScenario */
	public function forgetTheArrivalId(): void {
		$this->idArrivedWith = '';
		$this->parkedTeamId = '';
	}

	/**
	 * A design file sitting outside every mapping, still carrying its Penpot id.
	 *
	 * Reached the only way the app can produce it: made in a mapped folder, then
	 * dragged out. After that drag the file holds its archive, its `penpot_id`,
	 * `penpot_mode | unmapped` and no team — and its design is in Penpot's trash.
	 *
	 * @Given /^an unmapped design file at "([^"]*)" carrying its Penpot id$/
	 */
	public function anUnmappedDesignFileCarryingItsPenpotId(string $path): void {
		$folder = dirname($path);
		$filename = basename($path);

		// Born inside a mapping, because that is the only place a design can be
		// born. `Penpot` is the admin-folder sync mapping every scenario in this
		// feature's Background declares.
		$this->aDesignFileNamedIn($filename, 'Penpot/Letting Go');
		$this->idArrivedWith = $this->currentFileId;
		$this->parkedTeamId = $this->teamIdForPath($this->currentFilePath);

		$this->makeAncestors($path);
		$this->clearTheWay($path);
		$this->iMoveTheFileInto($folder);
	}

	/**
	 * Remove anything already sitting at the path this arrange is about to fill.
	 *
	 * UNMAPPED SPACE IS NOT CLEANED BETWEEN SCENARIOS. Every mapped root is rebuilt
	 * by the mapping reset, but `Scratch` is by definition mapped to nothing, so a
	 * file left there by one Examples row is still there for the next — and a DAV
	 * MOVE onto an existing path is a 412, which fails the ARRANGE and reports as
	 * though the behaviour were broken. Measured: the second row of two identical
	 * arrangements died this way while the first row's real failure scrolled past.
	 */
	private function clearTheWay(string $path): void {
		if (!$this->davExists($path)) {
			return;
		}

		// ITS DESIGN GOES TOO, AND THAT IS THE WHOLE POINT OF THIS METHOD.
		//
		// Deleting the file alone is what the first version did, and it made the
		// scenario fail in a way that looked exactly like the app misbehaving: the
		// leftover is still a TRACKED file, so the delete listener dutifully moved
		// its design into Penpot's trash — a design carrying the same NAME as the one
		// this row is about. `the design "Going Loose" is not in Penpot's trash` is a
		// name lookup, so it found the ghost and reported that the untrash had not
		// worked, while the log said plainly that it had.
		//
		// So the debris is destroyed rather than trashed. The id comes off the file
		// before it goes, because after the delete there is nothing left to ask.
		$id = $this->davReadMetadata($path, 'penpot_id') ?? '';
		$this->davDelete($path);

		if ($id === '' || $this->parkedTeamId === '') {
			return;
		}

		// WAITED FOR, not assumed. `delete-file` answers before the design shows up
		// in the trash listing (~0.1–0.3s, measured in RestoreService), so reading it
		// straight away can miss — and a miss here leaves the ghost behind, which is
		// the exact failure this method exists to stop.
		$this->until(
			fn (): bool => $this->inTeamTrash($this->parkedTeamId, $id),
			fn (): string => "the leftover design {$id} never reached Penpot's trash to be purged",
			5.0,
		);

		// Only ever an id this suite just put in the trash itself — the same rule the
		// app holds itself to (§C6.11: `permanently-delete-team-files` has no safety
		// of its own and will destroy a LIVE design if handed one).
		if ($this->inTeamTrash($this->parkedTeamId, $id)) {
			$this->penpotRpc('permanently-delete-team-files', [
				'team-id' => $this->parkedTeamId,
				'ids' => [$id],
			]);
		}
	}

	/**
	 * The far side of the arrival question, as the two states that reach the same
	 * outcome.
	 *
	 * `trashed` is where {@see anUnmappedDesignFileCarryingItsPenpotId()} already
	 * left it, so that row is a no-op — stated rather than skipped, because a step
	 * that silently means nothing is worse than one that says it means nothing.
	 * `live` is somebody restoring the design in Penpot's own UI in the meantime.
	 *
	 * @Given /^its design is (trashed|live) in Penpot$/
	 */
	public function itsDesignIsInPenpot(string $where): void {
		if ($where === 'trashed') {
			return;
		}

		$this->penpotRpc('restore-deleted-team-files', [
			'team-id' => $this->parkedTeamId,
			'ids' => [$this->idArrivedWith],
		]);
	}

	/**
	 * A design file outside every mapping whose id names nothing — or which never
	 * had one.
	 *
	 * TWO STORED STATES, ONE QUESTION. Penpot has no design for this file either
	 * way, so the app imports the archive and mints a fresh id; the row that
	 * carries a stale id is the one that proves the stale id is REPLACED rather
	 * than pushed at Penpot and failing.
	 *
	 * @Given /^a design file at "([^"]*)" carrying (an id no design answers to|no Penpot id at all)$/
	 */
	public function aDesignFileCarrying(string $path, string $what): void {
		if ($what === 'no Penpot id at all') {
			// An ordinary upload: real archive bytes, none of this app's keys.
			$this->iUploadAnArchiveAt($path);
			$this->idArrivedWith = '';

			return;
		}

		// PARK IT, THEN DESTROY WHAT THE PARKING PARKED.
		//
		// Stamping a dead uuid on the file would be simpler and is NOT AVAILABLE:
		// this app's metadata is app-owned, and a PROPPATCH from a client is refused
		// with *"you do not have enough rights to update 'penpot_id' on this node"*.
		// That is correct of the app — a client that could forge a design's identity
		// could re-point any mirror at any design — so the fixture has to reach the
		// state the same way a person would.
		//
		// Purging is the only thing that makes an id unresolvable: Penpot's trash
		// keeps a design both restorable and exportable, so a delete is not enough.
		$this->anUnmappedDesignFileCarryingItsPenpotId($path);
		$this->theDesignIsPurgedFromPenpotsTrash(basename($path, '.penpot'));
	}

	/**
	 * Someone drags a design into a team this app has no folder for.
	 *
	 * The design moves to that team's Drafts, which is a real project (§6.35) and
	 * needs no folder — so this is one `move-files` and nothing in Nextcloud has
	 * happened at all. The pull that follows is what the scenario is really about:
	 * it has to tell this from a delete.
	 *
	 * @When /^someone moves the design into the "([^"]*)" Penpot team$/
	 */
	public function someoneMovesTheDesignIntoThePenpotTeam(string $team): void {
		$this->penpotRpc('move-files', [
			'project-id' => $this->draftsProjectOf($this->teamNamed($team)),
			'ids' => [$this->cursorDesignId()],
		]);
		$this->theAdminRunsAPull();
	}

	/**
	 * The file is gone, and Nextcloud kept no copy of it either.
	 *
	 * THE SECOND HALF IS THE CLAIM. "Gone from the folder" is true of a trashing
	 * as well, so a scenario asserting only that would pass against the behaviour
	 * it exists to rule out — a mirror in the trash, reading to the user as a
	 * design somebody deleted when all that happened was a move.
	 *
	 * @Then /^the file is gone from "([^"]*)", leaving no trash entry$/
	 */
	public function theFileIsGoneFromLeavingNoTrashEntry(string $folder): void {
		$path = trim($folder, '/') . '/' . basename($this->currentFilePath);
		if ($this->davExists($path)) {
			throw new \RuntimeException("'{$path}' is still there; the mirror was supposed to go");
		}

		$this->theFileIsNotInTheNextcloudTrash($path);
	}

	/**
	 * Someone re-files a design in Penpot's own UI, inside the mapped team.
	 *
	 * `Drafts` IS A PROJECT, not the absence of one (§6.35) — so this resolves the
	 * team's default project rather than treating the word as a special case, and
	 * the mirror it produces surfaces at the team ROOT because Drafts is the one
	 * project with no folder of its own.
	 *
	 * Any other name is created if the scenario has not already produced it: the
	 * Given only makes the project the design starts in, and a destination that
	 * does not exist yet would be a fixture failure wearing the shape of a bug.
	 *
	 * @When /^someone moves the design into the "([^"]*)" Penpot project$/
	 */
	public function someoneMovesTheDesignIntoThePenpotProject(string $project): void {
		$teamId = $this->teamIdForPath($this->currentFilePath);
		$target = $project === 'Drafts'
			? $this->draftsProjectOf($teamId)
			: $this->projectInTeam($teamId, $project) ?? $this->makeProjectIn($teamId, $project);

		$this->penpotRpc('move-files', [
			'project-id' => $target,
			'ids' => [$this->cursorDesignId()],
		]);
		$this->theAdminRunsAPull();
	}

	/**
	 * The mirror turned up at its new path, carrying the table.
	 *
	 * MOVES THE CURSOR, and that is the point of the step rather than an aside: the
	 * app chose this path, so every assertion after it has to be told where the
	 * file went. Reusing `the file holds:` afterwards is then the same vocabulary
	 * every other scenario uses instead of a second table checker.
	 *
	 * @Then /^the file arrives at "([^"]*)", holding:$/
	 */
	public function theFileArrivesAtHolding(string $path, TableNode $table): void {
		$path = trim($path, '/');
		$this->until(
			fn (): bool => $this->davExists($path),
			fn (): string => "nothing arrived at '{$path}'",
		);

		$this->currentFilePath = $path;
		$this->currentFolder = dirname($path);

		$this->holds($path, $table);
	}

	/** A project the scenario needs as a destination but never asked for by hand. */
	private function makeProjectIn(string $teamId, string $name): string {
		$this->penpotRpc('create-project', ['team-id' => $teamId, 'name' => $name]);
		$id = $this->projectInTeam($teamId, $name);
		if ($id === null) {
			throw new \RuntimeException("created the project '{$name}' in {$teamId} and Penpot does not list it");
		}

		return $id;
	}

	/**
	 * A project by name, IN A NAMED TEAM.
	 *
	 * Not {@see PullSteps::projectIdNamedOrNull()}, which looks in whichever team
	 * was last PULLED. That is the right default for the pull steps and the wrong
	 * one here: these scenarios name the team through the file's own mapping, and
	 * asking the wrong team returned null for a project that had just been created
	 * — reported as "Penpot does not list it", which sounds like a Penpot bug and
	 * was a lookup in the wrong place.
	 */
	private function projectInTeam(string $teamId, string $name): ?string {
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
			if (($project['name'] ?? null) === $name && is_string($project['id'] ?? null)) {
				return $project['id'];
			}
		}

		return null;
	}

	/**
	 * The team's default project — Drafts, which every team has and no folder
	 * mirrors. `get-all-projects` rather than `get-projects`, for the reason
	 * §6.42 records: the latter does not filter soft-deleted projects.
	 */
	private function draftsProjectOf(string $teamId): string {
		foreach ($this->penpotRpcRead('get-all-projects', []) as $project) {
			$isDefault = filter_var($project['isDefault'] ?? $project['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$owner = $project['teamId'] ?? $project['team-id'] ?? null;
			if ($isDefault && $owner === $teamId && is_string($project['id'] ?? null)) {
				return $project['id'];
			}
		}

		throw new \RuntimeException("the team {$teamId} has no default project, which should be impossible");
	}
}
