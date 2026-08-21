<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * The ARRANGE SPINE the rewritten spec is built on.
 *
 * Every `designs/` and `projects/` feature now opens with the same three
 * sentences — the app is connected, these mappings exist, these items are in
 * them — and then acts on "the file" or a path. That shape came from
 * nextcloud-grafana, whose suite is built the same way; this is the penpot half
 * of it, and it is deliberately ONE trait because the three sentences share
 * state that only makes sense together.
 *
 * ## WHY A PLURAL TABLE RATHER THAN A SENTENCE PER MAPPING
 *
 * The old vocabulary said "a Penpot team named X is mapped to the folder Y", one
 * mapping per line, and every scenario that needed a second mapping said it
 * twice with a different subset of the fact. The rewritten Background needs
 * THREE mappings at once — a sync one, a Team-Folder one and a link one — so that
 * a single outline can send the same gesture into each. Three sentences cannot
 * express that without the reader counting lines; one table can.
 *
 * ## WHY THE RESET IS ONCE PER SCENARIO AND NOT ONCE PER ROW
 *
 * The table declares a SET of mappings, so clearing on every row would leave only
 * the last one standing — silently, which is the part that matters. Both siblings
 * were fixed for exactly this (see {@see MappingSteps::aMappingWithTheFollowingValues()},
 * which shares the `$mappingsDeclared` latch for the same reason).
 *
 * ## WHY THE FOLDERS ARE EMPTIED AND NOT DELETED
 *
 * A leg runs many scenarios against one Nextcloud, and the new spec REUSES folder
 * names — "Penpot", "Shared" and "Pointers" appear in a dozen files. Nothing in
 * this harness ever removed a mapped folder, so scenario N inherited whatever
 * N-1 mirrored into it; Grafana hit this and it showed up as files named
 * `Pinned (1)`, `(2)`, `(3)`.
 *
 * Emptying rather than deleting, because a Team Folder's root is a MOUNT POINT:
 * `TeamFolderService::ensure()` is idempotent and find-or-creates by mount point,
 * so deleting the mount would either fail or strand the groupfolder row. Clearing
 * the children reaches the same state for both storage kinds with one code path.
 *
 * And it happens AFTER the unmap, never before: while the mapping still exists a
 * delete inside it is a gesture the app acts on, and it would cascade into Penpot.
 * Unmapping first makes the same delete ordinary Nextcloud housekeeping.
 *
 * ## WHY NOTHING HERE DRAINS A JOB
 *
 * Worth stating because the Grafana port this follows drains two on every step.
 * penpot has no deferred write: `lib/BackgroundJob/` holds only the pull, and
 * creation, rename, move and delete all run inline in their listeners. So an id
 * can be read on the line after the gesture, and an assertion that seems to be
 * racing something is a real failure rather than a missing drain.
 */
trait ArrangeSteps {
	/**
	 * The mode each mapped folder was created with, so an arrange can tell a link
	 * from a sync WITHOUT asking the Gherkin to repeat it. Writing into a link
	 * mapping is refused by design, so a link mirror has to be seeded through a
	 * pull instead of a PUT — the mode is how this trait knows which it is.
	 *
	 * @var array<string, string>
	 */
	private array $mappingModes = [];

	/**
	 * The Penpot team id behind each mapped folder.
	 *
	 * NEEDED BECAUSE "THAT TEAM" IS AMBIGUOUS BY THE TIME AN ITEM IS SEEDED. The
	 * mappings table leaves `namedTeamId` pointing at whichever row came LAST, and
	 * the link-seeding path below has to create its project in the team that owns
	 * the folder it is seeding into. That happens to be the same team today —
	 * `Pointers` is the last row in every Background — which is exactly the kind of
	 * accident that works until someone reorders a table.
	 *
	 * @var array<string, string>
	 */
	private array $mappingTeamIds = [];

	/**
	 * The Penpot project id each declared folder had AT DECLARE TIME, keyed by the
	 * path it was declared at.
	 *
	 * @var array<string, string>
	 */
	private array $declaredProjectIds = [];

	/**
	 * The Penpot design id each declared design had at declare time, keyed by
	 * FILENAME rather than path.
	 *
	 * KEYED BY NAME BECAUSE THE PATH IS WHAT MOVES. Every scenario using
	 * `the mappings hold:` is asserting across a rename or a move, so the path in
	 * the `Then` is deliberately not the path in the `Given` — and the filename is
	 * the one part the app promises does not change (§6.4: the filename is the
	 * design's name plus the extension Penpot never carries).
	 *
	 * @var array<string, string>
	 */
	private array $declaredDesignIds = [];

	/**
	 * The last folder declared as a project, so a `Then` can say "the original id"
	 * about a folder whose NAME has just changed.
	 *
	 * A design keeps its filename across a rename; a project folder does not — the
	 * whole point of `Rename a project folder` is that `Old` becomes `New`. So a
	 * folder's original id cannot be looked up by the path being asserted, and the
	 * only unambiguous referent is the project the scenario most recently put on
	 * stage. Feature files are written so that this is the project under test: the
	 * Background's items come first, and the scenario's own `Given` re-declares the
	 * one it is about.
	 */
	private string $lastDeclaredProject = '';

	/**
	 * THE CURSOR: the file "the file" means.
	 *
	 * The rewritten spec stopped repeating paths. It says `a design file named
	 * "Old Name.penpot" in "Penpot/Rename Live"` once and then talks about "the
	 * file" — which is how a person describes what they are doing, and it is the
	 * only way a rename outline can stay readable when the name is the parameter
	 * being varied.
	 *
	 * Three values, because a rename moves the path out from under the scenario:
	 * the folder does not change, the id does not change, and those two are what
	 * let the path be re-resolved after the name has changed underneath it.
	 */
	private string $currentFilePath = '';
	private string $currentFolder = '';
	private string $currentFileId = '';

	/** @BeforeScenario */
	public function armArrange(): void {
		$this->mappingModes = [];
		$this->mappingTeamIds = [];
		$this->declaredProjectIds = [];
		$this->declaredDesignIds = [];
		$this->lastDeclaredProject = '';
		$this->currentFilePath = '';
		$this->currentFolder = '';
		$this->currentFileId = '';
	}

	/**
	 * One sentence for the three the Background used to open with.
	 *
	 * The app being installed, the URL being set and the token being stored are not
	 * three things a reader of `designs/move.feature` cares about — they are the
	 * single precondition "this app can talk to Penpot". The three older sentences
	 * still exist and `connection/` still uses them, because there the connection
	 * IS the subject.
	 *
	 * @Given the app is connected to Penpot
	 */
	public function theAppIsConnectedToPenpot(): void {
		// givenTheAppIsEnabled(), NOT theAppIsEnabled() — the latter is the `@Then`
		// assertion, and its PHPUnit matcher reaches into a config registry that only
		// exists inside a PHPUnit run, so calling it from an arrange fails with
		// "Registry::get(): Return value must be of type Configuration, null returned"
		// and says nothing about the app.
		$this->givenTheAppIsEnabled();
		$this->thePenpotBaseUrlPointsAtTheTestInstance();
		$this->theAdminHasConfiguredTheServiceAccountToken();
	}

	/**
	 * Every mapping the scenario needs, as one table.
	 *
	 * Columns are the mapping form's own fields, so this reads identically to
	 * `mapping/create.feature`'s and goes through the same {@see MappingSteps::flagFor()}.
	 * `storage` says `admin folder` here where the creation form says
	 * `plain shared folder`; both mean the absence of `--team-folder`, which is why
	 * `flagFor()` opts IN to the Team Folder and treats everything else as plain.
	 *
	 * @Given /^the following mappings were made:$/
	 */
	public function theFollowingMappingsWereMade(TableNode $table): void {
		if (!$this->mappingsDeclared) {
			// UNMAP BEFORE TOUCHING ANY CONTENT — see the trait docblock. While a
			// mapping is live, deleting inside it is a gesture that reaches Penpot.
			$this->noPenpotTeamsAreMapped();
			$this->mappingsDeclared = true;
		}

		foreach ($table->getHash() as $row) {
			$folder = trim($row['folder'] ?? '');
			if ($folder === '') {
				throw new \RuntimeException('a mapping needs a folder — add a "folder" cell to the table');
			}

			$groups = trim($row['groups'] ?? '');
			if ($groups !== '') {
				$this->theNextcloudGroupsExist($groups);
			}

			$this->emptyMappedFolder($folder);

			$team = trim($row['team'] ?? '');
			if ($team === '') {
				throw new \RuntimeException('a mapping needs a team — add a "team" cell to the table');
			}
			$this->aPenpotTeamNamedExists($team);
			$this->pulledTeamId = $this->namedTeamId;

			$flags = [];
			foreach (['folder' => $folder, 'mode' => trim($row['mode'] ?? ''),
				'groups' => $groups, 'storage' => trim($row['storage'] ?? '')] as $field => $value) {
				if ($value !== '') {
					$flags[] = $this->flagFor($field, $value);
				}
			}

			$res = $this->occ(sprintf(
				'penpot_sync:add-mapping %s %s',
				escapeshellarg($this->namedTeamId),
				implode(' ', $flags),
			));
			if ($res['exit'] !== 0) {
				throw new \RuntimeException("could not map \"{$team}\" to \"{$folder}\":\n{$res['output']}");
			}

			$this->mappingModes[$folder] = trim($row['mode'] ?? '') ?: 'link';
			$this->mappingTeamIds[$folder] = $this->namedTeamId;
		}

		// ── A MAPPING IS NOT LIVE UNTIL A PULL HAS RUN ───────────────────────────
		//
		// `add-mapping` provisions the folder but does NOT mark it. Membership is
		// derived by walking UP the tree for a folder carrying `penpot_team_id`
		// (MembershipResolver, saga §6.29 — "the single most load-bearing rule in
		// the app"), and the only thing that ever writes that marker is
		// `PullService`. Grep for `writeFolder(` if this ever looks doubtful: every
		// call site is in PullService.
		//
		// So without this pull, a design written into a freshly mapped folder
		// resolves to `Membership: none` and the app correctly declines to track
		// it — which is what CI reported, and it took a `status` dump to see that
		// the fixture was wrong rather than the app.
		//
		// It also matches what the sentence CLAIMS. "The following mappings were
		// made" means the mappings are usable, and a real instance reaches that
		// state the same way: you map a team, then it syncs.
		$this->theAdminRunsAPull();
	}

	/**
	 * Take everything out of a mapped folder, best effort.
	 *
	 * BEST EFFORT ON PURPOSE. This is housekeeping between scenarios, not a claim
	 * the spec makes, so a child that refuses to go must not fail the scenario that
	 * was merely trying to start clean — it will fail on its own assertion instead,
	 * which is the failure worth reading. The residue it removes is only ever a
	 * problem for the NEXT scenario, so silence here costs nothing that is not
	 * already visible there.
	 */
	private function emptyMappedFolder(string $folder): void {
		try {
			if (!$this->davExists($folder)) {
				return;
			}
			foreach ($this->davChildren($folder) as $child) {
				$this->davDeleteStatus($child);
			}
		} catch (\Throwable) {
			// see above — a folder that cannot be cleared is not this step's failure
		}
	}

	/**
	 * The items a scenario needs inside its mappings, as paths.
	 *
	 * Paths are absolute-looking (`/Penpot/Old/Inside.penpot`) because they read as
	 * locations rather than as arguments; the leading slash is stripped here since
	 * every DAV helper works from the user's files root.
	 *
	 * `kind` is optional and only earns a cell where the path cannot say it: a
	 * `.penpot` file is a design and anything else with an extension is an ordinary
	 * file, but a bare folder could be either a PROJECT or a plain folder, and the
	 * difference is the whole subject of `projects/create.feature`.
	 *
	 * ## THE ANCESTORS ARE MADE, THEN THE LEAF, AND THAT ORDER IS THE POINT
	 *
	 * Writing a design into a folder Penpot has never seen is what MAKES that
	 * folder a project (`projects/create.feature`), so the ancestors are created as
	 * bare folders and the design creates the project on its way in. Doing it the
	 * other way — declaring the project first — would arrange the thing several
	 * scenarios exist to prove.
	 *
	 * @Given /^the following items in the mappings:$/
	 */
	public function theFollowingItemsInTheMappings(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = ltrim(trim($row['path'] ?? ''), '/');
			if ($path === '') {
				throw new \RuntimeException('an item needs a path — add a "path" cell to the table');
			}

			$kind = trim($row['kind'] ?? '');
			if ($kind === '') {
				$kind = str_ends_with($path, '.penpot') ? 'design'
					: (pathinfo($path, PATHINFO_EXTENSION) === '' ? 'plain folder' : 'file');
			}

			$this->makeAncestors($path);

			switch ($kind) {
				case 'design':
					$this->declareDesign($path);
					break;
				case 'project':
					$this->davMkcol($path);
					$this->iAssignThePenpotTagTo($path);
					$this->rememberProject($path);
					break;
				case 'plain folder':
					$this->davMkcol($path);
					break;
				default:
					// An ordinary Nextcloud file living inside a project folder. It is
					// here so a scenario can prove the app leaves it alone — see
					// `projects/rename.feature`'s "Budget.xlsx".
					$this->davPut($path, "not a design\n");
			}
		}
	}

	/** Every folder above a path, made as a bare folder. */
	private function makeAncestors(string $path): void {
		$parts = explode('/', $path);
		array_pop($parts);
		$so_far = '';
		foreach ($parts as $part) {
			$so_far = $so_far === '' ? $part : $so_far . '/' . $part;
			$this->davMkcol($so_far);
		}
	}

	/**
	 * Put a design where the scenario asked for one, and remember what it became.
	 *
	 * A LINK MAPPING CANNOT BE WRITTEN INTO, and that refusal is a shipped feature
	 * rather than an obstacle: `LinkWriteGuardPlugin` answers a PUT with 403 on
	 * purpose. So a link mirror is seeded the only way one ever really appears —
	 * the design is made in Penpot and pulled down. Same reasoning as the Grafana
	 * sibling's `seedMirrorViaPull()`.
	 */
	private function declareDesign(string $path): void {
		$folder = dirname($path);
		$name = preg_replace('/\.penpot$/', '', basename($path)) ?? basename($path);

		// ALREADY THERE IS A VALID ANSWER TO "IS THIS ITEM IN THE MAPPING?".
		//
		// Penpot state accumulates across a leg: teams are find-or-create by name
		// and there is no delete-project RPC in this app to tear one down with, so
		// the pull above re-mirrors every project an earlier scenario left in the
		// team. A second row asking for the same path would then PUT an empty body
		// over a mirrored archive — blanking a sync file to arrange it.
		//
		// A `Given` states what is true, so if it is already true, stop.
		if ($this->davExists($path)) {
			$existing = $this->davReadMetadata($path, 'penpot_id') ?? '';
			if ($existing !== '') {
				$this->declaredDesignIds[basename($path)] = $existing;
				$this->rememberProject($folder);

				return;
			}
		}

		if (($this->mappingModes[$this->mappingRootOf($path)] ?? '') === 'link') {
			$this->seedDesignViaPull($path, $name);
		} else {
			// Empty body: that is what "+ New → Penpot design" writes, and the app
			// tells a CREATE from an UPLOAD by exactly this (see GestureSteps).
			$this->davPut($path, '');
			// ...AND THEN A PULL, BECAUSE A CREATE STORES NO ARCHIVE.
			//
			// `CreationService` says so in as many words: the design was just made
			// empty in Penpot, so there is nothing worth exporting yet and no
			// revision is stamped. The bytes arrive on the next pull, down
			// ArchiveService's self-healing path — the same one that repairs a sync
			// file whose archive went missing.
			//
			// So without this, `a design file named … in "<a sync folder>"` produces a
			// mirror that is a sync file with an EMPTY body, which is not a state the
			// app leaves lying around and not what any scenario means by the
			// sentence. `designs/rename.feature` asks for `| content | an archive |`
			// straight after this arrange, and would have failed on a fixture rather
			// than on the app.
			$this->theAdminRunsAPull();
		}

		$id = $this->davReadMetadata($path, 'penpot_id') ?? '';
		if ($id === '') {
			throw new \RuntimeException(
				"the design '{$path}' was written but carries no Penpot id — "
				. "the app did not track it:\n" . $this->status($path),
			);
		}
		$this->declaredDesignIds[basename($path)] = $id;
		$this->rememberProject($folder);
	}

	/**
	 * Seed a design into a LINK mapping by creating it in Penpot and pulling.
	 *
	 * The project has to exist in Penpot first, and its name is the path below the
	 * mapped folder — that pairing is pinned in both directions (§6.36), which is
	 * what makes it safe to spell a Penpot project name out of a Nextcloud path.
	 */
	private function seedDesignViaPull(string $path, string $name): void {
		$root = $this->mappingRootOf($path);
		$project = trim(substr(dirname($path), strlen($root)), '/');
		if ($project === '') {
			throw new \RuntimeException("a link mirror needs a project folder, got '{$path}'");
		}

		// Point "that team" at the mapping being seeded, rather than relying on
		// whichever row the mappings table named last.
		$team = $this->mappingTeamIds[$root] ?? '';
		if ($team === '') {
			throw new \RuntimeException("no mapping is declared for the folder '{$root}'");
		}
		$this->namedTeamId = $team;
		$this->pulledTeamId = $team;

		$this->aPenpotProjectExistsInThatTeam($project);
		$this->penpotRpc('create-file', [
			'project-id' => $this->penpotProjectId($project),
			'name' => $name,
		]);
		$this->theAdminRunsAPull();

		if (!$this->davExists($path)) {
			throw new \RuntimeException("the pull did not mirror '{$name}' into the link folder '{$root}'");
		}
	}

	/** The mapped folder a path sits under — its first segment. */
	private function mappingRootOf(string $path): string {
		return explode('/', ltrim($path, '/'))[0];
	}

	/** Record a folder's project id, if it has one, as its ORIGINAL id. */
	private function rememberProject(string $folder): void {
		$id = $this->projectIdOf($folder);
		if ($id === '') {
			return;
		}
		$this->declaredProjectIds[$folder] = $id;
		$this->lastDeclaredProject = $folder;
	}

	/** A folder's Penpot project id, or '' when it carries none. */
	private function projectIdOf(string $path): string {
		if (preg_match('/^penpot_project_id: (\S+)$/m', $this->status($path), $m) === 1) {
			return $m[1];
		}
		return '';
	}

	/**
	 * What the mappings hold now, one row per path.
	 *
	 * THE IDENTITY COLUMN IS THE WHOLE ASSERTION. Every scenario using this table
	 * is proving that something survived a gesture — a rename, a move, a copy — and
	 * "survived" means the id did not change. A row saying `the original id` is the
	 * anti-break claim: this is the same project, not a new one wearing the name.
	 *
	 * @Then /^the mappings hold:$/
	 */
	public function theMappingsHold(TableNode $table): void {
		$failures = [];

		foreach ($table->getHash() as $row) {
			$path = ltrim(trim($row['path'] ?? ''), '/');
			$want = trim($row['identity'] ?? '');
			$isDesign = str_ends_with($path, '.penpot');

			if (!$this->davExists($path)) {
				$failures[] = "  {$path}: nothing is there";
				continue;
			}

			$actual = $isDesign
				? ($this->davReadMetadata($path, 'penpot_id') ?? '')
				: $this->projectIdOf($path);

			$problem = $this->checkIdentity($path, $isDesign, $want, $actual);
			if ($problem !== null) {
				$failures[] = "  {$path}: {$problem}";
			}
		}

		if ($failures !== []) {
			throw new \RuntimeException(
				"the mappings do not hold what the scenario describes:\n" . implode("\n", $failures),
			);
		}
	}

	/** One identity row. Returns a human sentence on failure, or null when it holds. */
	private function checkIdentity(string $path, bool $isDesign, string $want, string $actual): ?string {
		switch ($want) {
			case 'the original id':
				$original = $isDesign
					? ($this->declaredDesignIds[basename($path)] ?? '')
					: $this->originalProjectIdFor($path);
				if ($original === '') {
					return 'the arrange recorded no original id to compare against';
				}
				return $actual === $original
					? null : "expected the id it already had ({$original}), found '{$actual}'";

			case 'a new id':
				if ($actual === '') {
					return 'expected a new id, found nothing';
				}
				$known = array_merge(
					array_values($this->declaredDesignIds),
					array_values($this->declaredProjectIds),
				);
				return in_array($actual, $known, true)
					? "expected an id of its own, found the original ({$actual})" : null;

			case 'set':
				return $actual !== '' ? null : 'expected an id, found nothing';
			case 'absent':
				return $actual === '' ? null : "expected no id to be stored, found '{$actual}'";
			default:
				throw new \RuntimeException(
					"the identity column says '{$want}', which is not a value this vocabulary knows."
					. ' Use "the original id", "a new id", "set" or "absent".',
				);
		}
	}

	/**
	 * A design the scenario named, in a folder it named — and the cursor "the
	 * file" points at from here on.
	 *
	 * The extension is stripped to get the Penpot name: a design's filename is its
	 * name plus the `.penpot` Penpot never carries (§6.4), an invariant
	 * `designs/rename.feature` pins in both directions. That is what makes it safe
	 * to resolve one from the other rather than storing a second copy of the name.
	 *
	 * @Given /^a design file named "([^"]*)" in "([^"]*)"$/
	 */
	public function aDesignFileNamedIn(string $filename, string $folder): void {
		$folder = trim($folder, '/');
		$path = $folder . '/' . $filename;

		$this->makeAncestors($path);
		$this->declareDesign($path);

		$this->currentFilePath = $path;
		$this->currentFolder = $folder;
		$this->currentFileId = $this->declaredDesignIds[$filename] ?? '';
	}

	/**
	 * Where the cursor is now, re-resolved if the name moved under it.
	 *
	 * A rename made in Penpot arrives through a pull, so nothing in the scenario
	 * knows the new filename — the app chose it. Rather than have every assertion
	 * spell that out, the cursor is re-found BY ID inside its folder, which is the
	 * one thing a rename never changes.
	 */
	private function currentFile(): string {
		if ($this->currentFilePath !== '' && $this->davExists($this->currentFilePath)) {
			return $this->currentFilePath;
		}
		if ($this->currentFileId === '' || $this->currentFolder === '') {
			throw new \RuntimeException(
				'no design file is on stage — a scenario must name one before it says "the file"',
			);
		}
		foreach ($this->davChildren($this->currentFolder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			if (($this->davReadMetadata($child, 'penpot_id') ?? '') === $this->currentFileId) {
				$this->currentFilePath = $child;
				return $child;
			}
		}
		throw new \RuntimeException(sprintf(
			"no file under '%s' carries the design id %s any more; it held: %s",
			$this->currentFolder,
			$this->currentFileId,
			implode(', ', $this->davChildren($this->currentFolder)) ?: '(nothing)',
		));
	}

	/**
	 * The mode of the mapping a path sits under, in the ADMIN's vocabulary.
	 *
	 * `the mapping's mode` is how a metadata table avoids saying `sync` or `link`
	 * in a scenario that runs against all three mappings — the point of those rows
	 * is that the mode is whatever the mapping said, not a particular value.
	 */
	private function modeOfMappingFor(string $path): string {
		$root = $this->mappingRootOf($path);
		return $this->mappingModes[$root] ?? 'link';
	}

	/**
	 * Which declared project a `Then` path is talking about.
	 *
	 * ## WHY THIS IS NOT JUST A LOOKUP
	 *
	 * A design keeps its filename across every gesture, so its original id is a
	 * dictionary hit. A project folder does not, and the two gestures break it in
	 * OPPOSITE ways:
	 *
	 * - a RENAME changes the last segment and keeps the rest —
	 *   `Penpot/foo/Old` → `Penpot/foo/New`;
	 * - a MOVE keeps the last segments and changes the front —
	 *   `Penpot/foo/bar` → `Penpot/Clients/foo/bar`.
	 *
	 * So neither the head nor the tail of the path is stable on its own, and the
	 * first cut of this — "fall back to the project declared most recently" — got
	 * `Move a folder that other projects are named through` wrong: that scenario
	 * declares TWO projects and asserts both, so the fallback compared `…/foo/bar`
	 * against the id belonging to `…/foo/bar/baz` and failed for a reason that had
	 * nothing to do with the app.
	 *
	 * Three rules, most specific first:
	 *   1. the exact path, for anything that did not move at all;
	 *   2. the declared project sharing the longest run of TRAILING segments, which
	 *      is what a move preserves and is unambiguous when several are on stage;
	 *   3. the most recently declared project, which is what a rename leaves — and
	 *      is safe there precisely because a rename scenario has one project under
	 *      test and the file re-declares it after the Background.
	 */
	private function originalProjectIdFor(string $path): string {
		if (isset($this->declaredProjectIds[$path])) {
			return $this->declaredProjectIds[$path];
		}

		$want = explode('/', $path);
		$best = '';
		$bestRun = 0;
		foreach ($this->declaredProjectIds as $declared => $id) {
			$have = explode('/', $declared);
			$run = 0;
			while ($run < count($want) && $run < count($have)
				&& $want[count($want) - 1 - $run] === $have[count($have) - 1 - $run]) {
				$run++;
			}
			if ($run > $bestRun) {
				$bestRun = $run;
				$best = $id;
			}
		}
		if ($bestRun > 0) {
			return $best;
		}

		return $this->declaredProjectIds[$this->lastDeclaredProject] ?? '';
	}
}
