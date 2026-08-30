<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * The opt-in that makes a Nextcloud folder a Penpot project, and the tag that
 * marks every project folder whichever way round it came about
 * (`create-project.feature`, saga §C6.18).
 *
 * ## THE ASYMMETRY THESE STEPS EXIST TO PROVE
 *
 *     every Penpot project      →  a folder in Nextcloud     (automatic)
 *     SOME Nextcloud folders    →  a project in Penpot       (opt-in only)
 *
 * The permissive half is the one that needs a live test most: "a new folder
 * inside a mapped folder is just a folder" is a claim about something NOT
 * happening, and the only way to be sure is to make one and look at both sides.
 *
 * ## AN EMPTY PROJECT FOLDER COMES FROM PENPOT
 *
 * There is no gesture on this side that promotes a folder holding no design —
 * the tag opt-in that used to provide one is gone (saga §D4.14). So a scenario
 * asking for `kind: project` gets it the way a real user does: the project is
 * created in Penpot and the pull mirrors it, stamped with `penpot_project_id`.
 * See {@see ArrangeSteps::ensureProjectFolder()}.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; uses the
 * occ transport, the DAV transport, and the probe helpers from {@see PullSteps}
 * and {@see GestureSteps}.
 */
trait ProjectFolderSteps {
	/**
	 * A GIVEN STATES WHAT IS TRUE. As an arrange the sentence is "there is a plain
	 * folder here", not "I created one" — and "not a project" is the load-bearing
	 * half, because a folder only becomes a project by carrying a project id.
	 *
	 * THE BARE FORM CARRIES NO CLAIM, and that is the point of it. "that is not a
	 * project" says two things in one sentence, and the second one belongs to
	 * Penpot rather than to Nextcloud — so a scenario that needs both states them
	 * separately and lets `Penpot holds no project named` speak for Penpot.
	 *
	 * @Given /^a folder at "([^"]*)"$/
	 * @Given /^a folder at "([^"]*)" that is not mapped$/
	 * @Given /^a folder at "([^"]*)" in the user's home that is not a project$/
	 * @When /^I create the folder "([^"]*)"$/
	 */
	public function iCreateAFolderAt(string $path): void {
		$this->davMkcol($path);
	}

	/**
	 * SOMEONE MADE A PROJECT IN PENPOT, and the pull is the app noticing.
	 *
	 * The Penpot-origin twin of `a design file named … in …`: the gesture happens
	 * upstream, possibly by someone else, and the event this app is responsible for
	 * is the sync seeing it. The pull is collapsed in here for the same reason
	 * {@see ArrangeSteps::declareDesign()} collapses it — a scheduled job is not a
	 * step a person performs, and a `When the admin syncs` wedged between the two
	 * `Then`s would be an admin's button in a story about somebody drawing.
	 *
	 * THE TEAM IS NAMED, NEVER "THAT TEAM". Every Background here maps three teams
	 * and the outline creates a project in each in turn, so the cursor form would
	 * make the row's own team column decorative. {@see ArrangeSteps::teamIdNamed()}
	 * resolves it from the mappings table, which is the only place a team's name and
	 * its id are both known.
	 *
	 * The id is kept because it is what the folder has to carry: `the project's id`
	 * in a `holds:` table means THIS project, not merely some project.
	 *
	 * @When /^someone creates the "([^"]*)" project in the "([^"]*)" Penpot team$/
	 */
	public function someoneCreatesTheProjectInThePenpotTeam(string $name, string $team): void {
		$this->penpotRpc('create-project', [
			'team-id' => $this->teamIdNamed($team),
			'name' => $name,
		]);

		// READ BACK RATHER THAN TRUSTED: `create-project` answers, but the id the
		// assertions need is the one the probe channel reports, and the two channels
		// cross-checking each other is the whole point of the split.
		$id = $this->penpotProjectIdInTeam($name, $team);
		if ($id === null) {
			throw new \RuntimeException(
				"created the project '{$name}' in the team '{$team}' but Penpot does not list it",
			);
		}

		$this->createdProjectId = $id;
		$this->createdProjectName = $name;
		$this->createdProjectTeam = $team;

		$this->theAdminRunsAPull();
	}

	/**
	 * A project made in Penpot ARRIVED, as a folder carrying its id.
	 *
	 * NOT `"…" holds:`, though it ends in the same assertion. That step reports a
	 * missing path by listing what the PARENT holds, which is the right answer for
	 * a gesture made in Nextcloud and the wrong one here — the parent is a folder
	 * the same pull was supposed to create, so when this fails there is frequently
	 * nothing at either end and "the parent holds nothing" says nothing. What a
	 * reader needs is whether Penpot lists the project at all, which separates "the
	 * pull did not mirror it" from "the create never landed".
	 *
	 * NO POLLING, unlike the Penpot-side assertions above.
	 * {@see someoneCreatesTheProjectInThePenpotTeam()} reads the project back and
	 * then runs the pull to completion, so by the time this sentence is read the
	 * folder either exists or the pull declined to make it. Waiting would turn a
	 * decision into a ten-second timeout.
	 *
	 * @Then /^"([^"]*)" exists in Nextcloud, holding:$/
	 */
	public function existsInNextcloudHolding(string $path, TableNode $table): void {
		$path = trim($path, '/');

		if (!$this->davExists($path)) {
			throw new \RuntimeException(sprintf(
				"the project '%s' was created in the '%s' Penpot team, and nothing stands at '%s'. "
				. 'Penpot holds the projects: %s',
				$this->createdProjectName,
				$this->createdProjectTeam,
				$path,
				implode(', ', $this->penpotProjectNames()) ?: '(none)',
			));
		}

		$this->holds($path, $table);
	}

	/**
	 * The scaffolding a project's name spelled on the way down carries no project
	 * id — it is a folder like any the user might have made (§C6.38).
	 *
	 * READS THE NAME, NOT THE PATH THE PREVIOUS STEP ASSERTED. The claim is about
	 * what the NAME spelled, so deriving the chain from the name is the claim
	 * itself rather than a restatement of the row's `folder` column — and it keeps
	 * this step honest if the two ever disagree.
	 *
	 * A ONE-SEGMENT NAME SPELLS NOTHING, so this checks nothing for `Team` and
	 * `Pinned` — correct rather than lax: those rows are in the outline to prove a
	 * flat name still arrives, and there is no scaffolding for them to have. The
	 * referent guard below is what stops that being indistinguishable from a step
	 * that quietly derived an empty chain for every row.
	 *
	 * @Then /^the folders its name spelled on the way down hold:$/
	 */
	public function theFoldersItsNameSpelledHold(TableNode $table): void {
		if ($this->createdProjectName === '') {
			throw new \RuntimeException(
				'"the folders its name spelled" has no project to talk about — nothing in this '
				. 'scenario created one in Penpot.',
			);
		}

		$root = $this->mappedFolderForTeam($this->createdProjectTeam);
		$segments = array_values(array_filter(
			explode('/', trim($this->createdProjectName, '/')),
			static fn (string $segment): bool => $segment !== '',
		));
		array_pop($segments); // the last one is the project folder, not scaffolding

		$path = $root;
		foreach ($segments as $segment) {
			$path .= '/' . $segment;
			$this->holds($path, $table);
		}
	}

	/**
	 * The folder a team's mapping is rooted at, by the team's NAME.
	 *
	 * The reverse of {@see ArrangeSteps::teamIdNamed()}, and it reads the same
	 * array for the same reason: the mappings table is the only place a team name
	 * and its folder are both known, and asking Penpot would match by name across
	 * every team the account can see.
	 */
	private function mappedFolderForTeam(string $team): string {
		foreach ($this->mappingTeamNames as $folder => $name) {
			if ($name === $team) {
				return $folder;
			}
		}

		throw new \RuntimeException(
			"no mapping declares a team named '{$team}' — the Background has to map it before a step can name it",
		);
	}

	// ── what the APP believes ───────────────────────────────────────────────

	// ── what PENPOT actually holds ──────────────────────────────────────────

	/**
	 * Both of these POLL — same reasoning as the trash assertions in
	 * {@see GestureSteps::until()}: a tag gesture creates or removes the project
	 * through Penpot, and the listing that proves it is not instantaneous.
	 *
	 * @Then /^Penpot holds a project named "([^"]*)"$/
	 */
	public function penpotHoldsAProjectNamed(string $name): void {
		$this->until(
			fn (): bool => in_array($name, $this->penpotProjectNames(), true),
			fn (): string => sprintf(
				"expected a Penpot project named '%s'; found: %s",
				$name,
				implode(', ', $this->penpotProjectNames()) ?: '(none)',
			),
		);
	}

	/** @Then /^Penpot holds no project named "([^"]*)"$/ */
	public function penpotHoldsNoProjectNamed(string $name): void {
		$this->until(
			fn (): bool => !in_array($name, $this->penpotProjectNames(), true),
			fn (): string => "expected NO Penpot project named '{$name}', but it is there",
		);
	}

	/**
	 * The folder went, because the project it mirrored went.
	 *
	 * "Gone from Nextcloud" and not "gone", deliberately: the folder is in the
	 * Nextcloud trash, and the scenario beside this one asserts its designs are
	 * recoverable from there. What this claims is that nothing stands at the path
	 * any more.
	 *
	 * @Then /^"([^"]*)" is gone from Nextcloud$/
	 */
	public function isGoneFromNextcloud(string $path): void {
		if ($this->davExists(trim($path, '/'))) {
			throw new \RuntimeException(
				"'{$path}' was supposed to be gone, but it is still there — the project was "
				. 'deleted in Penpot and its folder outlived it.',
			);
		}
	}

	/**
	 * The other ending: the folder stayed, because it held something that was
	 * never Penpot's.
	 *
	 * NAMES THE SURVIVOR rather than describing an absence. "Still exists" alone
	 * would pass on a folder that had been emptied of everything, which is the
	 * failure this pair exists to rule out — deleting a user's spreadsheets
	 * because a Penpot project went away is not the app's call.
	 *
	 * @Then /^"([^"]*)" still exists in Nextcloud, holding "([^"]*)"$/
	 */
	public function stillExistsHolding(string $path, string $child): void {
		$path = trim($path, '/');
		if (!$this->davExists($path)) {
			throw new \RuntimeException("'{$path}' was supposed to survive, but there is nothing there.");
		}
		$this->assertedFolder = $path;

		$want = $path . '/' . $child;
		if (!$this->davExists($want)) {
			throw new \RuntimeException(
				"'{$path}' survived but '{$child}' did not — the folder was kept and then emptied.",
			);
		}
	}

	/**
	 * The folder that survived kept everything EXCEPT the designs.
	 *
	 * Reads "it" from the folder the step above named, which is the only sentence
	 * that can have put one on stage — a bare "it holds no design files" with no
	 * preceding claim has no referent, and this says so rather than passing.
	 *
	 * @Then /^it holds no design files$/
	 */
	public function itHoldsNoDesignFiles(): void {
		if ($this->assertedFolder === '') {
			throw new \RuntimeException(
				'"it holds no design files" has no folder to talk about — say which folder '
				. 'survived first.',
			);
		}

		$designs = [];
		foreach ($this->davChildren($this->assertedFolder) as $child) {
			if (str_ends_with($child, '.penpot')) {
				$designs[] = basename($child);
			}
		}

		if ($designs !== []) {
			throw new \RuntimeException(
				"'{$this->assertedFolder}' still holds " . implode(', ', $designs)
				. " — the project's designs were supposed to go with it.",
			);
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * The folder {@see stillExistsHolding()} put on stage, so the sentence after it
	 * can say "it". Held rather than re-derived because the two steps are one claim
	 * split across two lines, and the second one carries no path of its own.
	 */
	private string $assertedFolder = '';

	/**
	 * The project {@see someoneCreatesTheProjectInThePenpotTeam()} made, so the
	 * assertions after it can say "the project's id" and "its name".
	 *
	 * Three fields rather than one because the two claims need different halves:
	 * the id is what the leaf folder must carry, and the name is what spelled the
	 * folders above it — in the team whose mapping decides where the whole path
	 * starts.
	 */
	private string $createdProjectId = '';
	private string $createdProjectName = '';
	private string $createdProjectTeam = '';

	/**
	 * A path in the acting user's files, as the ROOT-relative form `occ` wants.
	 *
	 * `FileUtils::getNode()` takes either a numeric fileid or an absolute path
	 * through the storage root — not the DAV-relative path every other step in
	 * this suite speaks. One place to translate, so the Gherkin stays in the one
	 * vocabulary a reader already knows.
	 */
	private function rootPath(string $path): string {
		return '/' . $this->ncUser . '/files/' . ltrim($path, '/');
	}

	/**
	 * Project names Penpot actually holds, read through the app's own probe so
	 * the seed channel and the read channel keep cross-checking each other — the
	 * same trick {@see GestureSteps::penpotFileNamesIn()} uses.
	 *
	 * A project line is `  <name>  <uuid>  [<team>]`.
	 *
	 * @return list<string>
	 */
	private function penpotProjectNames(): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while listing projects:\n{$res['output']}");
		}

		$names = [];
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}[0-9a-f-]{36}\s+\[/', $line, $m) === 1) {
				$names[] = trim($m[1]);
			}
		}

		return $names;
	}

	/**
	 * Which TEAM a Penpot project belongs to.
	 *
	 * The claim a cross-team copy or move is really making: the project did not
	 * merely appear with the right name, it appeared on the other side of a team
	 * boundary. `probe --files` prints `  <name>  <uuid>  [<team>]`, so the team is
	 * already there — the same listing {@see penpotProjectNames()} reads.
	 *
	 * @Then /^the "([^"]*)" Penpot project is in the "([^"]*)" team$/
	 */
	public function thePenpotProjectIsInTheTeam(string $project, string $team): void {
		$this->until(
			fn (): bool => in_array($team, $this->penpotProjectTeams($project), true),
			fn (): string => sprintf(
				"expected the Penpot project '%s' to be in the team '%s'; found it in: %s",
				$project,
				$team,
				implode(', ', $this->penpotProjectTeams($project)) ?: '(no project of that name)',
			),
		);
	}

	/**
	 * A project holds exactly these designs, one per file, by name.
	 *
	 * EXACTLY, because the interesting failure is a duplicate. A copy is a create
	 * plus one duplicate per design, and the way that goes wrong is a design copied
	 * twice or a name silently suffixed — both of which leave the right names
	 * present and the count wrong.
	 *
	 * @Then /^the "([^"]*)" Penpot project holds one design per file, named:$/
	 */
	public function theProjectHoldsOneDesignPerFileNamed(string $project, TableNode $names): void {
		$want = [];
		foreach ($names->getRows() as $row) {
			$name = trim($row[0] ?? '');
			if ($name !== '') {
				$want[] = $name;
			}
		}
		sort($want);

		$this->until(
			function () use ($project, $want): bool {
				$have = $this->penpotFileNamesIn($project);
				sort($have);

				return $have === $want;
			},
			function () use ($project, $want): string {
				$have = $this->penpotFileNamesIn($project);
				sort($have);

				return sprintf(
					"expected the Penpot project '%s' to hold exactly [%s]; it holds [%s]",
					$project,
					implode(', ', $want),
					implode(', ', $have),
				);
			},
		);
	}

	/**
	 * Where THE design — the cursor's — ended up, asserted by id.
	 *
	 * The named sibling above matches on NAME, which is right for a scenario that
	 * says which design it means. This one is used where the scenario has already
	 * put one design on stage and the interesting fact is the PROJECT, often a
	 * project that did not exist a moment ago. Matching by id is what makes that
	 * claim honest: Penpot state accumulates across a leg, so a design of the same
	 * name from an earlier scenario is genuinely sitting in the team.
	 *
	 * @Then /^the design is in the "([^"]*)" Penpot project$/
	 */
	public function theCursoredDesignIsInThePenpotProject(string $project): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException(
				'the scenario says "the design" but no design is on stage, or the one that is '
				. 'carries no penpot_id — so there is nothing to look for in Penpot.',
			);
		}

		$team = $this->mappingTeamNames[$this->mappingRootOf($this->currentFilePath)] ?? '';
		if ($team === '') {
			throw new \RuntimeException(
				"'{$this->currentFilePath}' is not under any declared mapping, so there is no team "
				. "to look for the project '{$project}' in.",
			);
		}

		$this->until(
			fn (): bool => in_array($this->currentFileId, $this->penpotFileIdsIn($project, $team), true),
			fn (): string => sprintf(
				"expected the design %s in the '%s' project of team '%s'; it holds: %s",
				$this->currentFileId,
				$project,
				$team,
				implode(', ', $this->penpotFileIdsIn($project, $team)) ?: '(nothing, or no such project)',
			),
		);
	}

	/**
	 * The design the gesture just made exists in Penpot, and the file knows its id.
	 *
	 * THE WEAKEST HALF OF THE CLAIM ON PURPOSE — the scenario says this first and
	 * then says WHERE, so this one only asks whether anything was created at all.
	 * Splitting it that way makes the two failures read differently: "the app never
	 * called `create-file`" and "it created the design in the wrong project" are
	 * different bugs, and a single combined step would report them identically.
	 *
	 * ## IT RUNS THE SYNC, AND THAT IS THE HARNESS'S JOB RATHER THAN THE SPEC'S
	 *
	 * A create cannot write the design's bytes itself — `CreateListener` runs
	 * inside the DAV write handler, where the file is under a shared lock and
	 * `putContent()` cannot take the exclusive one it needs (see
	 * `features/AGENTS.md`, and nextcloud-n8n's `CreateService`, which measured the
	 * same wall). The archive therefore lands on the next scheduled sync, down
	 * {@see \OCA\PenpotSync\Service\ArchiveService}'s self-healing path.
	 *
	 * None of that belongs in the Gherkin. The scenario describes what the person
	 * ends up with; how long the app takes to get there is an implementation
	 * detail, and a scheduled job they never see is not a step they perform. So the
	 * wait is collapsed HERE — exactly as {@see ArrangeSteps::declareDesign()}
	 * already does after seeding a design, and for the same reason.
	 *
	 * The alternative is a `When the admin syncs every mapping` wedged between two
	 * `Then`s, which is invalid Gherkin twice over: a `When` after a `Then`, and an
	 * admin's button in a story about a user making a file.
	 *
	 * @Then /^a matching design is created in Penpot$/
	 */
	public function aMatchingDesignIsCreatedInPenpot(): void {
		$this->theAdminRunsAPull();

		$path = $this->currentFilePath;
		$id = $this->davReadMetadata($path, 'penpot_id') ?? '';
		if ($id === '') {
			throw new \RuntimeException(
				"'{$path}' was created but carries no Penpot id — the app did not make a design "
				. "for it:\n" . $this->status($path),
			);
		}
		// Re-seat the cursor: the arrange read the id before the listener had
		// finished on some routes, and every later assertion in these scenarios is
		// about THIS design.
		$this->currentFileId = $id;

		$this->until(
			fn (): bool => in_array($id, $this->penpotLiveDesignIds(), true),
			fn (): string => sprintf(
				"'%s' carries the id %s, but Penpot has no such design; it holds: %s",
				$path,
				$id,
				implode(', ', $this->penpotLiveDesignIds()) ?: '(none)',
			),
		);
	}

	/**
	 * …and it is that project's, under the name the FILE chose.
	 *
	 * ## BOTH FACTS AT ONCE, BECAUSE EITHER ALONE PASSES FOR THE WRONG REASON
	 *
	 * Penpot state accumulates across a leg, and every scenario using this creates
	 * a design called `New design`. So a name check alone goes green against a
	 * leftover from a scenario that finished minutes ago, and an id check alone
	 * cannot see that the app named the design after the wrong thing — §6.4 says a
	 * design's name is its filename minus the extension Penpot never carries, and
	 * that invariant is half of what "a matching design" means.
	 *
	 * Pairing them is why this reads the project listing as id → name rather than
	 * as two lists.
	 *
	 * @Then /^the design is named after the file, in the "([^"]*)" Penpot project$/
	 */
	public function theDesignIsNamedAfterTheFileIn(string $project): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException(
				'the scenario says "the design" but nothing on stage carries a penpot_id.',
			);
		}

		$team = $this->mappingTeamNames[$this->mappingRootOf($this->currentFilePath)] ?? '';
		if ($team === '') {
			throw new \RuntimeException(
				"'{$this->currentFilePath}' is not under any declared mapping, so there is no team "
				. "to look for the project '{$project}' in.",
			);
		}

		$want = preg_replace('/\.penpot$/', '', basename($this->currentFilePath)) ?? '';

		$this->until(
			fn (): bool => ($this->penpotFileEntriesIn($project, $team)[$this->currentFileId] ?? null) === $want,
			function () use ($project, $team, $want): string {
				$entries = $this->penpotFileEntriesIn($project, $team);
				$named = $entries[$this->currentFileId] ?? null;

				return $named === null
					? sprintf(
						"the design %s is not in the '%s' project of team '%s'; it holds: %s",
						$this->currentFileId,
						$project,
						$team,
						implode(', ', $entries) ?: '(nothing, or no such project)',
					)
					: sprintf(
						"the design %s is in '%s' as expected, but Penpot named it '%s' and the file "
						. "is '%s' — a design's name is its filename without the extension (§6.4)",
						$this->currentFileId,
						$project,
						$named,
						basename($this->currentFilePath),
					);
			},
		);
	}

	/**
	 * The refused gesture created nothing on the far side.
	 *
	 * COUNTED ACROSS THE WHOLE INSTANCE, not inside one project, because a refusal
	 * that leaked would put the design wherever the app THOUGHT it belonged — and
	 * naming a project here would be assuming the answer to that. The "before" is
	 * snapshotted by {@see GestureSteps::iTryToCreateANewDesignIn()}, which is the
	 * only step that can precede this one.
	 *
	 * @Then /^no design is created in Penpot$/
	 */
	public function noDesignIsCreatedInPenpot(): void {
		$now = $this->penpotLiveDesignIds();
		$new = array_values(array_diff($now, $this->designIdsBeforeRefusal));
		if ($new !== []) {
			throw new \RuntimeException(sprintf(
				'the creation was refused, but Penpot gained %d design(s) anyway: %s',
				count($new),
				implode(', ', $new),
			));
		}
	}

	/**
	 * The Penpot file ids in a named project of a named team.
	 *
	 * Team-scoped, because two teams may hold a project with the same name and the
	 * Backgrounds arrange exactly that on purpose.
	 *
	 * @return list<string>
	 */
	private function penpotFileIdsIn(string $project, string $team): array {
		return array_keys($this->penpotFileEntriesIn($project, $team));
	}

	/**
	 * The same listing as id → name, for the assertions that need both.
	 *
	 * @return array<string, string>
	 */
	private function penpotFileEntriesIn(string $project, string $team): array {
		$projectId = $this->penpotProjectIdInTeam($project, $team);
		if ($projectId === null) {
			return [];
		}

		$entries = [];
		foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
			if (isset($file['id']) && is_string($file['id'])) {
				$entries[$file['id']] = is_string($file['name'] ?? null) ? $file['name'] : '';
			}
		}

		return $entries;
	}

	/**
	 * Every team a project of this name appears in.
	 *
	 * A LIST, not a single answer, because two teams may hold a project with the
	 * same name — the Backgrounds arrange exactly that on purpose.
	 *
	 * @return list<string>
	 */
	private function penpotProjectTeams(string $project): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while resolving '{$project}':\n{$res['output']}");
		}

		$teams = [];
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}[0-9a-f-]{36}\s+\[(.*)\]\s*$/', $line, $m) !== 1) {
				continue;
			}
			if (trim($m[1]) === $project) {
				$teams[] = trim($m[2]);
			}
		}

		return $teams;
	}
}
