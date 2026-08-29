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
	/**
	 * A `.penpot` file this app is not mirroring, and nothing more.
	 *
	 * NO ID, ON PURPOSE — the sibling below is the one that carries one, and it
	 * costs a mapped folder, a create in Penpot and a drag to produce. A scenario
	 * that only needs "there is a design here that no mapping owns" should not pay
	 * for provenance it never asserts: `mapping/create.feature` purges the file
	 * either way, so whether it once had an id changes nothing about its fate.
	 *
	 * A REAL EXPORT WHERE ONE CAN BE MADE, because an arriving archive is now
	 * IMPORTED. This wrote `PK\x03\x04` and padding on the reasoning that "nothing
	 * imports these bytes" — true while a design arriving in a mapping was ignored,
	 * and false the moment it stopped being. Penpot answers `import-binfile` with a
	 * 500 for anything that is not a genuine archive, the app catches it and leaves
	 * the file untracked, and the scenario then fails saying no design is on stage:
	 * a description of the fixture rather than of the app.
	 *
	 * ## BUT A REAL EXPORT NEEDS A MAPPING TO COME FROM
	 *
	 * {@see GestureSteps::aRealPenpotArchive()} makes one by writing a design into
	 * `Penpot/Archive Source` and letting the pull mirror it, which needs the
	 * `Penpot` mapping this feature's Background declares. `mapping/create.feature`
	 * has no mapping at all — it is the file that MAKES them — so there the export
	 * cannot be produced and the PUT lands wherever `Penpot` happens to be, which in
	 * that feature is nothing, or a `link` folder that refuses writes.
	 *
	 * Nothing imports the bytes there either: that scenario purges the file. So the
	 * plausible header is still the right fixture when no mapping can produce a real
	 * one, and the difference is invisible to every scenario that does not import.
	 *
	 * @Given /^an unmapped design file at "([^"]*)"$/
	 */
	public function anUnmappedDesignFileAt(string $path): void {
		$this->makeAncestors($path);
		$this->davPut($path, $this->anImportableArchiveOrAPlausibleOne());

		// ON STAGE, so `the file` and `that file` mean this one. Without it a
		// scenario whose only Given is this step has no cursor at all, and the first
		// `When I move the file` fails with "no file is on stage" — which reads like
		// a missing arrange rather than an arrange that forgot to point at itself.
		//
		// The design a collision scenario is duplicating is whatever was named
		// BEFORE this, so the cursor is captured first: {@see MoveConflictSteps}
		// reads it as the destination, and it is the only moment both files are
		// identified.
		$this->collisionDestinationPath = $this->currentFilePath;
		$this->currentFilePath = $path;
		$this->currentFolder = dirname($path);
		$this->currentFileId = '';
	}

	/**
	 * Real bytes when a mapping can export them; a plausible header otherwise.
	 *
	 * Never fails the arrange over it: a scenario that imports needs the real thing
	 * and will say so loudly when it does not get it, and one that does not import
	 * cannot tell the difference.
	 */
	private function anImportableArchiveOrAPlausibleOne(): string {
		try {
			return $this->aRealPenpotArchive();
		} catch (\Throwable) {
			return "PK\x03\x04" . str_repeat("\0", 64);
		}
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
	 * Gone, and Nextcloud kept no copy — said of a path rather than of "the file".
	 *
	 * THE SECOND HALF IS THE WHOLE CLAIM, as in the sibling above. "Gone" is true of
	 * a trashing too, and a trashed design is exactly what `mapping/create.feature`
	 * refuses to create: restoring one into a link mapping cannot work, so it must
	 * never be offered. A status-only check would pass against the bug.
	 *
	 * @Then /^"([^"]*)" left no trash entry$/
	 */
	public function leftNoTrashEntry(string $path): void {
		$path = trim($path, '/');
		if ($this->davExists($path)) {
			throw new \RuntimeException("'{$path}' is still there; it was supposed to be purged");
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
