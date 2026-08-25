<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * A copy is a NEW design, never a second claim on the original.
 *
 * ## WHY THE COPY GETS ITS OWN TABLE STEP
 *
 * `the copy holds:` looks like {@see MetadataSteps::holds()} with a different
 * subject and is not. Two of its rows are not metadata at all — `filename` and
 * `name in Penpot` are the two halves of §6.4's invariant, asserted together
 * because the whole rule of this feature is that **Nextcloud names the copy**: the
 * suffix core adds on a collision is what the design ends up called in Penpot. A
 * table that could say one without the other would let the pair drift, which is
 * exactly the bug the rule exists to prevent.
 *
 * The third is `Created`, a clock rather than a stored value — a copy's own
 * creation time, which must be the DESIGN's rather than the moment the file was
 * written (§C6.24, and `a copy's clocks are its own` in AGENTS.md).
 *
 * ## WHY THE CURSOR MOVES TO THE COPY
 *
 * Every scenario here ends by checking the ORIGINAL survived, so both files are on
 * stage at once and "the file" would be ambiguous. The copy steps therefore keep
 * their own cursor and leave {@see ArrangeSteps}' pointing at the original, which
 * is what lets `the original file and its design are unchanged` stay a step about
 * "the file" and mean the right one.
 */
trait CopySteps {
	/** The copy this scenario made: its path, and the id it ended up carrying. */
	private string $copyPath = '';

	/** Penpot's design ids before the copy, so a refusal can prove it added none. */
	private array $designIdsBeforeCopy = [];

	/** What the destination folder held before, for the same claim locally. */
	private array $childrenBeforeCopy = [];

	/** Whether this scenario's duplicate fixture has already been made. */
	private bool $projectCleared = false;

	/** @BeforeScenario */
	public function armCopy(): void {
		$this->copyPath = '';
		$this->designIdsBeforeCopy = [];
		$this->childrenBeforeCopy = [];
		$this->projectCleared = false;
	}

	/**
	 * Copy THE file into a folder, which is what dragging with a modifier does.
	 *
	 * The destination folder is created if it is not there, for the same reason
	 * {@see GestureSteps::iCreateANewDesignIn()} makes one: a person copying into a
	 * folder is looking at it, so a scenario naming one is saying where they
	 * dropped it rather than asking for it to exist.
	 *
	 * @When /^I copy the file into "([^"]*)"$/
	 */
	public function iCopyTheFileInto(string $folder): void {
		$folder = trim($folder, '/');
		$this->makeAncestors($folder . '/x');
		$this->designIdsBeforeCopy = $this->penpotLiveDesignIds();

		$source = $this->currentFile();
		$target = $folder . '/' . basename($source);
		// CORE PICKS THE NAME ON A COLLISION, and that is the subject of half this
		// feature — so the harness must not pick one. A COPY onto an existing path
		// would overwrite; the free name is resolved the way core does it and the
		// scenario's `filename` row then asserts the answer.
		$this->copyPath = $this->davExists($target) ? $this->freeCopyName($folder, basename($source)) : $target;
		$this->davCopy($source, $this->copyPath);

		// The archive lands with the bytes, but the REVISION is the pull's stamp —
		// `CopyService` says so: "no revision is stamped, the copy has never been
		// pulled". Collapsing that wait here is the harness's job, exactly as it is
		// after a create; the scenario describes the state the person ends up in.
		$this->theAdminRunsAPull();
	}

	/**
	 * The same gesture, expected to be REFUSED.
	 *
	 * NO `makeAncestors()`, and no free-name search: every destination a refusal
	 * scenario names already exists, and creating one would be this step writing
	 * into a `link` tree the app refuses writes to.
	 *
	 * @When /^I try to copy the file into "([^"]*)"$/
	 */
	public function iTryToCopyTheFileInto(string $folder): void {
		$this->designIdsBeforeCopy = $this->penpotLiveDesignIds();
		// WHAT THE DESTINATION HELD BEFORE, because "no file is added" is about this
		// gesture and "Scratch" is never emptied between scenarios — only MAPPED
		// folders are (see ArrangeSteps). `Copy a design into an unmapped folder`
		// runs earlier in this very file and puts `Original.penpot` there on purpose,
		// so a bare existence check found ITS file and failed a refusal that worked.
		$this->childrenBeforeCopy = $this->davChildren(trim($folder, '/'));
		$source = $this->currentFile();
		$target = trim($folder, '/') . '/' . basename($source);
		$result = $this->davCopyResult($source, $target);
		$this->lastGestureStatus = $result['status'];
		$this->lastGestureBody = $result['body'];
		$this->copyPath = $target;
	}

	/**
	 * What the copy is, in one table.
	 *
	 * @Then /^the copy holds:$/
	 * @Then /^that file holds:$/
	 */
	public function theCopyHolds(TableNode $table): void {
		$path = $this->copyPath;
		if ($path === '' || !$this->davExists($path)) {
			throw new \RuntimeException("the scenario says \"the copy\" but there is nothing at '{$path}'");
		}

		$failures = [];
		foreach ($table->getRowsHash() as $property => $expected) {
			$problem = $this->checkCopyRow($path, $property, trim($expected, '"'));
			if ($problem !== null) {
				$failures[] = "  {$property}: {$problem}";
			}
		}

		if ($failures !== []) {
			throw new \RuntimeException(
				"the copy at '{$path}' is not what the scenario describes:\n" . implode("\n", $failures),
			);
		}
	}

	/** One row. Returns a human sentence on failure, or null when it holds. */
	private function checkCopyRow(string $path, string $property, string $expected): ?string {
		$id = $this->davReadMetadata($path, 'penpot_id') ?? '';

		switch ($property) {
			case 'filename':
				$actual = basename($path);
				return $actual === $expected ? null : "expected '{$expected}', found '{$actual}'";
			case 'name in Penpot':
				if ($id === '') {
					return 'the copy carries no Penpot id, so it names no design';
				}
				$named = $this->penpotDesignNameById($id);
				return $named === $expected
					? null : "expected the design to be called '{$expected}', Penpot calls it '" . ($named ?? '(gone)') . "'";
			case 'penpot_id':
				// `a new id` is the anti-hijack claim and the whole point of the
				// feature: the copy must not be a second file pointing at the
				// original's design.
				if ($expected !== 'a new id') {
					return "'{$expected}' is not a value this table knows for penpot_id";
				}
				if ($id === '') {
					return 'expected an id of its own, found nothing';
				}
				return $id === $this->currentFileId
					? "expected an id of its own, found the original's ({$id})" : null;
			case 'penpot_mode':
				$want = $this->modeOfMappingFor($path) === 'link' ? 'reference' : $this->modeOfMappingFor($path);
				$actual = $this->davReadMetadata($path, 'penpot_mode') ?? '';
				return $actual === $want ? null : "expected '{$want}', found '{$actual}'";
			case 'penpot_team_id':
				$want = $this->teamIdForPath($path);
				$actual = $this->davReadMetadata($path, 'penpot_team_id') ?? '';
				return $actual === $want ? null : "expected the mapped team ({$want}), found '{$actual}'";
			case 'penpot_revision':
				$actual = $this->davReadMetadata($path, 'penpot_revision') ?? '';
				return $actual !== '' ? null : 'expected a revision, found nothing';
			case 'Created':
				// A COPY'S CLOCKS ARE ITS OWN (§C6.24): its creation time is the
				// DESIGN's, not the moment the file was written.
				//
				// RESOLVED BY ID, and it cannot be resolved any other way here.
				// {@see PullSteps::penpotFileRecordFor()} reads the project out of the
				// PATH — `<mapped folder>/<project>/<design>.penpot` — which is true
				// for a mirror the pull placed and false for every row of this
				// outline: a copy landing in `Penpot` or `Shared` is in Drafts, one
				// landing in `…/wip` is in the project above it, and neither folder
				// name is a project at all. It failed on all six for exactly that.
				if ($id === '') {
					return 'the copy carries no Penpot id, so it names no design to date it from';
				}
				$created = $this->penpotCreatedAt($id);
				if ($created === null) {
					return "Penpot reported no creation time for the design {$id}";
				}
				$actual = $this->davTime($path, 'creation_time');
				return $actual === $created ? null : sprintf(
					"expected the design's creation time (%s), the file carries %s",
					gmdate('c', $created),
					$actual === null ? 'none' : gmdate('c', $actual),
				);
			default:
				throw new \RuntimeException(
					"'{$property}' is not a row this table knows. Known: filename, name in Penpot, "
					. 'penpot_id, penpot_mode, penpot_team_id, penpot_revision, Created.',
				);
		}
	}

	/**
	 * The copy is a design of its own, in the project the scenario names.
	 *
	 * BY ID AND SCOPED TO THE TEAM, because the Backgrounds deliberately put
	 * same-named projects in two teams and Penpot state accumulates across a leg.
	 *
	 * @Then /^the copy is a new design in the Penpot project "([^"]*)"$/
	 */
	public function theCopyIsANewDesignInThePenpotProject(string $project): void {
		$id = $this->davReadMetadata($this->copyPath, 'penpot_id') ?? '';
		if ($id === '') {
			throw new \RuntimeException("the copy at '{$this->copyPath}' carries no Penpot id");
		}
		if ($id === $this->currentFileId) {
			throw new \RuntimeException('the copy carries the ORIGINAL\'s id — it hijacked the design rather than duplicating it');
		}

		$team = $this->mappingTeamNames[$this->mappingRootOf($this->copyPath)] ?? '';
		if ($team === '') {
			throw new \RuntimeException("'{$this->copyPath}' is not under any declared mapping");
		}

		$this->until(
			fn (): bool => in_array($id, $this->penpotFileIdsIn($project, $team), true),
			fn (): string => sprintf(
				"expected the copy %s in the '%s' project of team '%s'; it holds: %s",
				$id,
				$project,
				$team,
				implode(', ', $this->penpotFileIdsIn($project, $team)) ?: '(nothing, or no such project)',
			),
		);
	}

	/**
	 * @Then /^the copy is refused with a message$/
	 */
	public function theCopyIsRefusedWithAMessage(): void {
		$this->refusedWithAMessage('copy');
	}

	/**
	 * @Then /^no file is added to "([^"]*)"$/
	 */
	public function noFileIsAddedTo(string $folder): void {
		$folder = trim($folder, '/');
		$added = array_values(array_diff($this->davChildren($folder), $this->childrenBeforeCopy));
		if ($added !== []) {
			throw new \RuntimeException(sprintf(
				"the copy was refused, and '%s' gained %s anyway — the refusal did not stop it",
				$folder,
				implode(', ', $added),
			));
		}
	}

	/**
	 * @Then /^no design is created in Penpot for the copy$/
	 */
	public function noDesignIsCreatedInPenpotForTheCopy(): void {
		$new = array_values(array_diff($this->penpotLiveDesignIds(), $this->designIdsBeforeCopy));
		if ($new !== []) {
			throw new \RuntimeException(sprintf(
				'the copy was supposed to reach no design, and Penpot gained %d: %s',
				count($new),
				implode(', ', $new),
			));
		}
	}

	/**
	 * The bytes came across untouched.
	 *
	 * THE POINT OF THE SCENARIO THIS SERVES: a `sync` archive copied out of every
	 * mapping is a valid `.penpot` on its own. Nothing re-exported it, nothing
	 * blanked it — the file someone dragged to their desktop opens in Penpot.
	 *
	 * @Then /^the copy's body is byte-for-byte the original's$/
	 */
	public function theCopysBodyIsByteForByteTheOriginals(): void {
		$original = $this->davGet($this->currentFile());
		$copy = $this->davGet($this->copyPath);
		if ($copy !== $original) {
			throw new \RuntimeException(sprintf(
				"the copy's body is not the original's: %d bytes against %d",
				strlen($copy),
				strlen($original),
			));
		}
	}

	/**
	 * A duplicate made in PENPOT, and the sync that carries it down.
	 *
	 * @When /^someone duplicates its design in Penpot$/
	 */
	public function someoneDuplicatesItsDesignInPenpot(): void {
		$this->duplicateInPenpot($this->penpotDesignNameById($this->cursorDesignId()) . ' (copy)');
	}

	/**
	 * The same, with the duplicate's name chosen — which is how a scenario arranges
	 * two designs wearing one name.
	 *
	 * @When /^someone duplicates its design in Penpot and names it "([^"]*)"$/
	 */
	public function someoneDuplicatesItsDesignInPenpotAndNamesIt(string $name): void {
		$this->duplicateInPenpot($name);
	}

	/** `duplicate-file` on the cursor's design, then the pull that mirrors it. */
	private function duplicateInPenpot(string $name): void {
		// ONCE PER SCENARIO, NOT ONCE PER CALL. Running it before every duplicate
		// meant the SECOND call deleted the design the FIRST had just made — the
		// folder came back holding two files where the scenario made three, and the
		// suffix had climbed past the one the spec names. The fixture is a statement
		// about the starting position, so it is made once, at the start.
		if (!$this->projectCleared) {
			$this->projectCleared = true;
			$this->leaveOnlyTheCursorInItsProject();
		}

		// KEBAB `file-id`, which corrects §6.28's record of a camelCase `fileId` —
		// see PenpotClient::PARAMS, where the same row carries the same warning.
		$this->penpotRpc('duplicate-file', ['file-id' => $this->cursorDesignId(), 'name' => $name]);
		$this->theAdminRunsAPull();
	}

	/** The design the scenario put on stage, or a readable failure. */
	private function cursorDesignId(): string {
		if ($this->currentFileId === '') {
			throw new \RuntimeException('no design is on stage to duplicate in Penpot');
		}

		return $this->currentFileId;
	}

	/**
	 * The duplicate arrived as its own file, beside the original.
	 *
	 * It also SEATS THE COPY CURSOR, because the pull chose the filename — nothing
	 * in the Gherkin could name the path, which is why the following table says
	 * `filename` rather than repeating one.
	 *
	 * @Then /^the copy arrives as its own file in "([^"]*)"$/
	 */
	public function theCopyArrivesAsItsOwnFileIn(string $folder): void {
		$folder = trim($folder, '/');
		$original = $this->currentFileId;

		$this->until(
			fn (): bool => $this->newDesignFileIn($folder, $original) !== null,
			fn (): string => sprintf(
				"no file under '%s' carries a design other than the original (%s); it holds: %s",
				$folder,
				$original,
				implode(', ', $this->davChildren($folder)) ?: '(nothing)',
			),
		);

		$this->copyPath = (string)$this->newDesignFileIn($folder, $original);
	}

	/** The first `.penpot` in a folder carrying a design id that is not $except. */
	private function newDesignFileIn(string $folder, string $except): ?string {
		foreach ($this->davChildren($folder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			$id = $this->davReadMetadata($child, 'penpot_id') ?? '';
			if ($id !== '' && $id !== $except) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * One file per design, and the filenames Nextcloud chose for them.
	 *
	 * THE CLAIM IS THE PAIRING, not the list. Three designs called `Original` in
	 * Penpot must be three files with three DIFFERENT names here, each pointing at
	 * a different design — so this checks the names match AND that the ids behind
	 * them are distinct. A name-only check would pass on three files sharing one id,
	 * which is precisely the state §6.4 forbids.
	 *
	 * @Then /^"([^"]*)" holds one file per design, named:$/
	 */
	public function holdsOneFilePerDesignNamed(string $folder, TableNode $table): void {
		$want = [];
		foreach ($table->getRows() as $row) {
			$want[] = trim($row[0]);
		}
		sort($want);

		$this->until(
			function () use ($folder, $want): bool {
				$have = $this->designFilesIn($folder);
				$names = array_keys($have);
				sort($names);

				return $names === $want && count(array_unique(array_values($have))) === count($have);
			},
			function () use ($folder, $want): string {
				$have = $this->designFilesIn($folder);
				$names = array_keys($have);
				sort($names);
				if ($names !== $want) {
					return sprintf(
						"expected '%s' to hold [%s]; it holds [%s]",
						$folder,
						implode(', ', $want),
						implode(', ', $names) ?: '(nothing)',
					);
				}

				return sprintf(
					'the filenames are right but the ids are not distinct — %s',
					json_encode($have),
				);
			},
		);
	}

	/** The `.penpot` files in a folder as filename => design id. */
	private function designFilesIn(string $folder): array {
		$found = [];
		foreach ($this->davChildren(trim($folder, '/')) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			$found[basename($child)] = $this->davReadMetadata($child, 'penpot_id') ?? '';
		}

		return $found;
	}

	/**
	 * Penpot kept calling all of them the same thing.
	 *
	 * The other half of the rule above: the suffix is NEXTCLOUD's alone, invented
	 * to keep one folder's filenames unique, and it must never be pushed back.
	 *
	 * @Then /^all three designs are still named "([^"]*)" in Penpot$/
	 */
	public function allThreeDesignsAreStillNamedInPenpot(string $name): void {
		$ids = array_values(array_filter($this->designFilesIn(dirname($this->currentFilePath))));
		if (count($ids) !== 3) {
			throw new \RuntimeException(sprintf('expected three tracked designs on stage, found %d', count($ids)));
		}

		$wrong = [];
		foreach ($ids as $id) {
			$named = $this->penpotDesignNameById($id);
			if ($named !== $name) {
				$wrong[] = "{$id} is '" . ($named ?? '(gone)') . "'";
			}
		}

		if ($wrong !== []) {
			throw new \RuntimeException(
				"Nextcloud's suffix reached Penpot — every design should still be '{$name}': "
				. implode(', ', $wrong),
			);
		}
	}

	/**
	 * The name core would pick for a collision: `Original (1).penpot`, then `(2)`.
	 *
	 * NOT a port of {@see \OCA\PenpotSync\Service\PullService::freeName()} — that one
	 * resolves a collision the PULL hit, and its numbering is its own. This mirrors
	 * what the FILES APP does when you copy a file onto its own folder, because
	 * that is the gesture the scenario describes and the `filename` row asserts the
	 * answer. A COPY over WebDAV adds no suffix by itself; it overwrites. So the
	 * harness has to place the copy where a person's client would have, and any
	 * drift between this and core's naming shows up as a failed `filename` row
	 * rather than being hidden.
	 */
	private function freeCopyName(string $folder, string $filename): string {
		$base = preg_replace('/\.penpot$/', '', $filename) ?? $filename;
		for ($n = 1; $n < 100; $n++) {
			$candidate = sprintf('%s/%s (%d).penpot', $folder, $base, $n);
			if (!$this->davExists($candidate)) {
				return $candidate;
			}
		}

		throw new \RuntimeException("could not find a free name for a copy of '{$filename}' in '{$folder}'");
	}
	/**
	 * When Penpot says a design was created, by id.
	 *
	 * Scanned out of the probe's own listing rather than a `get-file` call: the
	 * team is not known here (a copy may have crossed one) and the probe already
	 * walks every mapped team, which is the same reason `penpotLiveDesignIds()`
	 * reads it whole.
	 */
	private function penpotCreatedAt(string $id): ?int {
		foreach ($this->mappingTeamIds as $teamId) {
			foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
				$files = $this->penpotRpcRead('get-project-files', ['project-id' => (string)($project['id'] ?? '')]);
				foreach ($files as $file) {
					if (($file['id'] ?? null) === $id) {
						return self::penpotSecond($file['createdAt'] ?? $file['created-at'] ?? null);
					}
				}
			}
		}

		return null;
	}
	/**
	 * Clear every design in the cursor's project except the cursor's own.
	 *
	 * ## WHY A DUPLICATE SCENARIO NEEDS THIS AND THE OTHERS DO NOT
	 *
	 * `Three designs in Penpot wearing one name` asserts the exact filenames core
	 * chose — `Original.penpot`, `Original (1)`, `Original (2)` — and those suffixes
	 * are a function of WHAT WAS ALREADY IN THE FOLDER. One stray mirror and the
	 * numbering shifts, which is how that scenario reported holding `(2)` and `(3)`:
	 * a true statement about a fixture the Given did not describe.
	 *
	 * `Given a design file named "Original.penpot" in "Penpot/Crowded"` says the
	 * project holds that design. Penpot state accumulates across a leg — teams are
	 * find-or-create and the Background's clean-up runs while UNMAPPED so it never
	 * reaches Penpot — so making the sentence true means saying it to Penpot too.
	 *
	 * Idempotent, so the second duplicate call is free.
	 */
	private function leaveOnlyTheCursorInItsProject(): void {
		$keep = $this->cursorDesignId();
		$folder = dirname($this->currentFilePath);
		$root = $this->mappingRootOf($folder);
		$team = $this->mappingTeamNames[$root] ?? '';
		$project = trim(substr($folder, strlen($root)), '/');
		if ($team === '' || $project === '') {
			return;
		}

		// THE FOLDER TOO, and this is the half the first cut missed. Deleting the
		// designs in Penpot left their MIRRORS on disk, so core's free-name search
		// still stepped over them and the suffixes kept climbing — the folder came
		// back holding `Original` and `Original (3)`. Clearing the files locally is
		// also what sends their designs to Penpot's trash, so one pass does both.
		foreach ($this->davChildren($folder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			if (($this->davReadMetadata($child, 'penpot_id') ?? '') !== $keep) {
				$this->davDeleteStatus($child);
			}
		}

		foreach ($this->penpotFileIdsIn($project, $team) as $id) {
			if ($id !== $keep) {
				$this->penpotRpc('delete-file', ['id' => $id]);
			}
		}
	}
}
