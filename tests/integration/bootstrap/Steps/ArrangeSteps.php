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
	 * The Penpot team NAME behind each mapped folder.
	 *
	 * Kept beside the id because the two answer different questions: an id is what
	 * an RPC takes, and a name is what `probe --files` prints — and resolving a
	 * project needs the name, because the probe groups by team and two teams may
	 * hold a project with the same name. They do, deliberately: several Backgrounds
	 * put an `Existing` project in both the sync team and the link team.
	 *
	 * @var array<string, string>
	 */
	private array $mappingTeamNames = [];

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
	 * The design PATHS the most recent items table named, in order.
	 *
	 * {@see $declaredDesignIds} is keyed by basename and answers "what id did this
	 * design have"; this answers "which designs is the scenario talking about",
	 * which is a different question and the one a `Then` saying "the designs" needs.
	 *
	 * @var list<string>
	 */
	private array $lastDeclaredDesigns = [];

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
		$this->mappingTeamNames = [];
		$this->declaredProjectIds = [];
		$this->declaredDesignIds = [];
		$this->lastDeclaredDesigns = [];
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
			$this->mappingTeamNames[$folder] = $team;
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
		// RESET PER TABLE, so "the designs" below means the ones THIS table named.
		// The Background declares items too, and a scenario's own `Given` re-declares
		// what it is about — the same convention {@see $lastDeclaredProject} relies
		// on, for the same reason: the most recent sentence is the subject.
		$this->lastDeclaredDesigns = [];

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
					$this->lastDeclaredDesigns[] = $path;
					break;
				case 'project':
					// No MKCOL: the pull creates the folder when it mirrors the
					// project, which is the only route to an empty project folder
					// now the tag opt-in is gone (§D4.14).
					$this->ensureProjectFolder($path);
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

		$mapped = isset($this->mappingModes[$this->mappingRootOf($path)]);

		if ($mapped && ($this->mappingModes[$this->mappingRootOf($path)] ?? '') === 'link') {
			$this->seedDesignViaPull($path, $name);
		} elseif (!$mapped) {
			// OUTSIDE EVERY MAPPING, which is a thing a scenario may legitimately ask
			// for — `a design file named "Travelling.penpot" in "Scratch"` is the
			// starting position for every "and then I drag it in" story. There is no
			// project to be in and no id to record; it is an ordinary file that
			// happens to end in `.penpot`, and demanding an id below would fail the
			// arrange for doing exactly what the sentence said.
			//
			// WITH REAL ARCHIVE BYTES. Two rules landed on this one line. An empty
			// `.penpot` outside every mapping is the "+ New" gesture and is refused
			// (§6.44: there is no rootless design), so it cannot be empty. And since
			// §6.33 these bytes get IMPORTED the moment the file is dragged into a
			// mapping — Penpot refuses anything that is not a genuine export, so a
			// ZIP header and padding would fail the import and the scenario would
			// read as an app bug. This arrange means "a design that exists, sitting
			// outside a mapping", and only a real archive says that.
			$this->davPut($path, $this->aRealPenpotArchive());
			$this->currentFilePath = $path;
			$this->currentFolder = $folder;
			$this->currentFileId = '';

			return;
		} else {
			// ── THE WRITE IS ALL IT TAKES ────────────────────────────────────────
			//
			// Writing the design promotes the folder: that is what §C6.38's
			// promotion-by-content means, and it is the whole of the arrange. A
			// design at the mapping ROOT arranges itself too — that is Drafts
			// (§6.35), not a project named after the mapped folder.
			//
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

		// Point "that team" at the mapping being seeded, rather than relying on
		// whichever row the mappings table named last.
		$team = $this->mappingTeamIds[$root] ?? '';
		if ($team === '') {
			throw new \RuntimeException("no mapping is declared for the folder '{$root}'");
		}
		$this->namedTeamId = $team;
		$this->pulledTeamId = $team;

		// AT THE MAPPING ROOT THERE IS NO PROJECT FOLDER, AND THAT IS NOT AN ERROR.
		// The root IS the team's Drafts (§6.35), which is a real Penpot project —
		// the `is-default` one. This used to throw "a link mirror needs a project
		// folder", which made `a design file named "…" in "Pointers"` unarrangeable
		// and took out every link row of `designs/copy.feature`'s refusal outline.
		$this->penpotRpc('create-file', [
			'project-id' => $project === ''
				? $this->penpotDraftsProjectIn($root)
				: $this->penpotProjectIn($root, $project),
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
	 * A folder that exists and holds no design — the pre-state for every scenario
	 * about what a design's ARRIVAL makes of the folder it lands in.
	 *
	 * "Holding no designs" is the whole point rather than incidental: an empty
	 * folder inside a mapping is not a project (`Create a folder in a mapping`
	 * pins that, live), so the project appearing afterwards can only be the
	 * design's doing.
	 *
	 * ## THIS DELETE REACHES PENPOT, AND THAT IS THE POINT
	 *
	 * Unlike {@see emptyMappedFolder()}, which unmaps first precisely so its
	 * clean-up stays local, this runs with the mapping LIVE — so a `.penpot` it
	 * removes goes to Penpot's trash as well. Deliberate, and the alternative is
	 * worse: Penpot state accumulates across a leg, so a project an earlier
	 * scenario left behind is re-mirrored by the Background's pull, and a "folder
	 * holding no designs" that quietly held one would arrange the opposite of what
	 * it says.
	 *
	 * Safe because of WHAT it deletes: a mirror the pull restored a moment ago,
	 * whose design is leftover state from a scenario that has already finished.
	 * Both trashes are soft, so nothing is destroyed on either side.
	 *
	 * It cannot hide a failure either, which is the part worth checking rather
	 * than assuming: a surviving project of the same name would make
	 * `Penpot holds a project named "…"` pass on its own, but
	 * {@see ProjectFolderSteps::theCursoredDesignIsInThePenpotProject()} matches by
	 * ID — so the design has to be in THAT project, which only this round's
	 * adoption puts it in.
	 *
	 * @Given /^the folder "([^"]*)" holding no designs$/
	 */
	public function theFolderHoldingNoDesigns(string $folder): void {
		$folder = trim($folder, '/');
		$this->makeAncestors($folder . '/x');
		if (!$this->davExists($folder)) {
			$this->davMkcol($folder);
		}

		foreach ($this->davChildren($folder) as $child) {
			if (str_ends_with($child, '.penpot')) {
				$this->davDeleteStatus($child);
			}
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

	/**
	 * Make a folder a Penpot project, if it is not one already.
	 *
	 * ## THE PROJECT IS MADE IN PENPOT, AND THE PULL MIRRORS IT
	 *
	 * This used to assign the `penpot` tag and let the opt-in listener promote the
	 * folder. That opt-in is gone (§D4.14): a folder is a project because it
	 * carries `penpot_project_id`, and only two things write one — a design
	 * landing in the folder, and the pull mirroring a project that exists in
	 * Penpot. An EMPTY project folder can only come from the second, which is
	 * also how a real user gets one.
	 *
	 * Idempotent by reading the stamp, which is the only marker there has ever
	 * been worth reading (§6.29).
	 */
	private function ensureProjectFolder(string $folder): void {
		if ($this->projectIdOf($folder) !== '') {
			return;
		}

		$root = $this->mappingRootOf($folder);
		$name = trim(substr($folder, strlen($root)), '/');
		if ($root === '' || $name === '') {
			throw new \RuntimeException(
				"'{$folder}' is not below a mapping, so nothing can make it a Penpot project",
			);
		}

		$this->penpotProjectIn($root, $name);
		$this->theAdminRunsAPull();

		if ($this->projectIdOf($folder) === '') {
			throw new \RuntimeException(
				"created the project '{$name}' in Penpot but the pull did not mirror it "
				. "to '{$folder}':\n" . $this->status($folder),
			);
		}
	}

	/**
	 * The id of a project with this name IN THIS MAPPING'S TEAM, creating it if it
	 * is not there.
	 *
	 * ## WHY THE TEAM IS PART OF THE QUESTION
	 *
	 * {@see PullSteps::penpotProjectId()} matches a project by NAME across the whole
	 * probe listing and takes the first hit. That is fine where one team is on
	 * stage, and wrong here: the rewritten Backgrounds map three teams at once and
	 * deliberately give two of them a project with the SAME name — `Existing` sits
	 * in both the sync team and the link team, and `designs/rename.feature` puts a
	 * `Renamed` project in all three.
	 *
	 * So an unscoped lookup resolved the link folder's project to the SYNC team's
	 * project of that name, seeded the design over there, and the pull then quite
	 * correctly mirrored it into the sync folder. The symptom was
	 * "the pull did not mirror 'Old Name' into the link folder 'Pointers'" — which
	 * reads like a broken pull and was a fixture asking for the wrong project.
	 *
	 * The probe prints `  <name>  <uuid>  [<team>]`, so the team is right there;
	 * this is the same parse as PullSteps', with the bracketed team no longer
	 * thrown away.
	 */
	private function penpotProjectIn(string $mappedFolder, string $project): string {
		$team = $this->mappingTeamNames[$mappedFolder] ?? '';
		if ($team === '') {
			throw new \RuntimeException("no mapping is declared for the folder '{$mappedFolder}'");
		}

		$found = $this->penpotProjectIdInTeam($project, $team);
		if ($found !== null) {
			return $found;
		}

		$this->penpotRpc('create-project', [
			'team-id' => $this->mappingTeamIds[$mappedFolder] ?? '',
			'name' => $project,
		]);

		$found = $this->penpotProjectIdInTeam($project, $team);
		if ($found === null) {
			throw new \RuntimeException(
				"created the project '{$project}' in the team '{$team}' but cannot find it again",
			);
		}

		return $found;
	}

	/** A project id by name AND team, or null when that pair is not listed. */
	private function penpotProjectIdInTeam(string $project, string $team): ?string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while resolving '{$project}':\n{$res['output']}");
		}
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}([0-9a-f-]{36})\s+\[(.*)\]\s*$/', $line, $m) !== 1) {
				continue;
			}
			if (trim($m[1]) === $project && trim($m[3]) === $team) {
				return $m[2];
			}
		}

		return null;
	}

	/**
	 * The Penpot team behind the mapping a PATH sits under.
	 *
	 * `the team's id` used to mean "the team the scenario named last", which is the
	 * last row of the mappings table — `Reference Team` in every Background here.
	 * A file in `Penpot/Brand` then compared against the LINK team's id and failed
	 * for a reason that had nothing to do with the app.
	 */
	private function teamIdForPath(string $path): string {
		return $this->mappingTeamIds[$this->mappingRootOf($path)] ?? '';
	}

	/**
	 * A design's id, resolved in the team AND project its path implies.
	 *
	 * ONE NAME IS NOT AN ADDRESS. `designs/view.feature` deliberately puts a
	 * `Brand Kit` in `Penpot/Brand` and another in `Pointers/Brand` — same design
	 * name, same project name, different teams — because the two modes are the
	 * point of the scenario. A by-name lookup over the whole probe listing returns
	 * whichever came first, so the sync row was asserted against the link row's id.
	 *
	 * The probe nests its output, so the scoping is free once it is read as a tree:
	 *
	 *     <project>  <uuid>  [<team>]
	 *       <design>  revn=<n>  <uuid>
	 *
	 * Returns null when the path is not under a declared mapping, so the caller can
	 * fall back to the older unscoped lookup rather than lose an assertion.
	 */
	private function designIdInMapping(string $path): ?string {
		$root = $this->mappingRootOf($path);
		$team = $this->mappingTeamNames[$root] ?? '';
		if ($team === '') {
			return null;
		}

		$project = trim(substr(dirname($path), strlen($root)), '/');
		$design = preg_replace('/\.penpot$/', '', basename($path)) ?? basename($path);

		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			return null;
		}

		$inProject = false;
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}[0-9a-f-]{36}\s+\[(.*)\]\s*$/', $line, $m) === 1) {
				$inProject = trim($m[1]) === $project && trim($m[2]) === $team;
				continue;
			}
			if (!$inProject) {
				continue;
			}
			if (preg_match('/^\s+(.*?)\s+revn=\S+\s+([0-9a-f-]{36})\s*$/', $line, $m) === 1
				&& trim($m[1]) === $design) {
				return $m[2];
			}
		}

		return null;
	}
	/**
	 * A mapped team's Drafts — the project Penpot flags `is-default`.
	 *
	 * NOT resolved by the name "Drafts": that is the label a person sees, it is
	 * localised, and §6.35 is explicit that the flag is the only reliable handle.
	 * The same read {@see \OCA\PenpotSync\Service\DestinationResolver} does.
	 */
	private function penpotDraftsProjectIn(string $mappedFolder): string {
		$team = $this->mappingTeamIds[$mappedFolder] ?? '';
		if ($team === '') {
			throw new \RuntimeException("no mapping is declared for the folder '{$mappedFolder}'");
		}

		// `get-all-projects`, NOT `get-projects`. Only the former carries
		// `is-default` — the same read {@see \OCA\PenpotSync\Service\DestinationResolver}
		// does, and the reason the first cut of this reported "no default (Drafts)
		// project" for a team that plainly has one.
		// CAMELCASE, NOT KEBAB, and that is the whole reason the first cut of this
		// found nothing. `penpotRpcRead()` sends `Accept: application/json`, which
		// makes Penpot answer plain JSON with camelCase keys instead of Transit —
		// the content negotiation PenpotClient's docblock warns about, seen from the
		// other side. `lib/` reads `team-id` because it never sends that header.
		foreach ($this->penpotRpcRead('get-all-projects', []) as $project) {
			if (($project['teamId'] ?? $project['team-id'] ?? null) !== $team) {
				continue;
			}
			if (filter_var($project['isDefault'] ?? $project['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
				$id = $project['id'] ?? '';
				if (is_string($id) && $id !== '') {
					return $id;
				}
			}
		}

		throw new \RuntimeException("the team behind '{$mappedFolder}' reports no default (Drafts) project");
	}
}
