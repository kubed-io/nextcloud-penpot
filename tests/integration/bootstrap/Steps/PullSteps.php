<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;

/**
 * The pull, end-to-end: `occ penpot_sync:sync pull` against a real Nextcloud and
 * a real Penpot, asserted through `occ penpot_sync:status` (saga Ch2 Course 3).
 *
 * ## WHY THIS SUITE IS WORTH A LIVE PENPOT
 *
 * The pull is the one place three unmocked things meet: the Penpot wire read
 * (`get-all-projects` / `get-project-files`), real Nextcloud folder writes in a
 * bare `occ` context, and the {@see \OCA\PenpotSync\Service\MembershipResolver}
 * walk over a tree the pull actually built. The unit suite mocks each in
 * isolation; only here do they have to agree. In particular the resolver — *the
 * single most load-bearing rule in the app* (saga §6.29) — is exercised against
 * a real folder tree for the first time.
 *
 * ## THE FIXTURE IS CREATED DIRECTLY IN PENPOT (not through the app)
 *
 * A pull has nothing to mirror unless Penpot has projects, so a scenario seeds
 * one over Penpot's own RPC bus with the same minted token the app uses — the
 * "direct Penpot channel" the FeatureContext reserved for the assertion/seed
 * side. `create-project` is confirmed live and kebab-cased (`team-id`, saga
 * §6.38); we do not read its Transit response, only assert HTTP 200.
 *
 * ## PLAIN FOLDER, NOT A TEAM FOLDER
 *
 * The `plain` legs take the DEFAULT backend — a plain shared folder, which is
 * core and always present. The `team` legs install groupfolders and ask for a
 * Team Folder explicitly with `--team-folder`. {@see OccTrait::backendFlags()}.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reuses
 * the occ transport and the team helpers from the other traits.
 */
trait PullSteps {
	/**
	 * What a design made in Penpot is called.
	 *
	 * NOT `New design`, which is core's name for a file made from the Files app —
	 * keeping the two apart is what lets a failure say which SIDE authored the
	 * thing it found, in a suite where both directions write into the same project.
	 */
	private const MADE_IN_PENPOT = 'Made in Penpot';

	/** The Penpot id of the team the current scenario mapped. */
	private string $pulledTeamId = '';

	/**
	 * Map a NAMED team to a NAMED folder, on WHICHEVER BACKEND this leg runs.
	 *
	 * TWO NAMES, BECAUSE THERE ARE TWO NAMES. A mapping is a row holding a team id
	 * and a folder name; nothing in it requires the two to read alike. The earlier
	 * phrasing ("a Penpot team is mapped to the folder …") could not say so — it
	 * named one side and left the other to the fixture, so every scenario built on
	 * it was silently a same-instance-team scenario. Carrying both lets a scenario
	 * state which team it means, and lets an Examples table put the same-name and
	 * different-name cases side by side (see admin-mapping.feature).
	 *
	 * PROJECTS HAVE NO SECOND NAME. Only a team gets this freedom, and the reason
	 * is structural rather than stylistic: a team has a mapping row to remember the
	 * pairing in, a project has none. A project folder's NAME is the only thing
	 * tying it to its Penpot project, so the two are pinned equal in both
	 * directions (saga §6.36): a rename on either side PROPAGATES to the other
	 * rather than producing a second name (rename-project.feature).
	 *
	 * The step deliberately does not say which BACKEND. Every behaviour here is
	 * valid on both a plain (admin-owned) folder and a Team Folder, so naming one
	 * in the Gherkin would either duplicate every scenario or quietly cover only
	 * half of what ships. {@see backendFlags()} reads the matrix leg instead.
	 *
	 * ONE ATOMIC PRE-STATE, AND IT ALREADY RESETS. The Background used to say "no
	 * Penpot teams are mapped" and then map one on the next line — a statement
	 * contradicted by the line beneath it, and a redundant one, because this step
	 * calls noPenpotTeamsAreMapped() itself. A Background is pre-state: it says how
	 * the world IS so the scenario is doable, not what was done to get there.
	 *
	 * ## THE MODE IS THE MAPPING'S, AND ONLY THE MAPPING'S
	 *
	 * The optional `in "sync" mode` tail is how a scenario gets a design whose
	 * archive is really on disk. It used to get one by promoting a single file with
	 * `occ penpot_sync:set-mode`, which no longer exists and should never have: the
	 * mode is an immutable field of the MAPPING, so a file's mode is decided
	 * entirely by the mapping it was mirrored under. To change it you remove the
	 * mapping and map the team again — which is exactly what a scenario re-stating
	 * this Given does, since the step resets the mappings first.
	 *
	 * @Given /^a Penpot team named "([^"]*)" is mapped to the folder "([^"]*)"$/
	 * @Given /^a Penpot team named "([^"]*)" is mapped to the folder "([^"]*)" in "(sync|link)" mode$/
	 */
	public function aPenpotTeamNamedIsMappedToTheFolder(string $team, string $folder, string $mode = ''): void {
		$this->noPenpotTeamsAreMapped();

		// NAMES THE TEAM as well as mapping it, so a scenario that starts from this
		// one sentence can still say "it" afterwards — which is how the refusals in
		// admin-mapping.feature reach a team without repeating its name. Same reason
		// its Team-Folder twin does it.
		$this->aPenpotTeamNamedExists($team);
		$this->pulledTeamId = $this->namedTeamId;

		$res = $this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s %s%s',
			escapeshellarg($this->pulledTeamId),
			escapeshellarg($folder),
			$this->backendFlags(),
			$mode === '' ? '' : ' --mode=' . escapeshellarg($mode),
		));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not map \"{$team}\" to the folder \"{$folder}\":\n{$res['output']}");
		}
	}

	/**
	 * The id of the team with this name, creating it in Penpot if it is not there.
	 *
	 * FIND-OR-CREATE, NOT CREATE. A leg runs many scenarios against one Penpot, and
	 * they mostly name the same team; making a fresh one each time would leave a
	 * drift of near-identical teams and slow every scenario down for nothing.
	 *
	 * Creating it is the service account's own doing, so it is a member and the
	 * team is visible to `get-teams` — which is what mapping is gated on (§6.18).
	 * No invite dance is needed to satisfy the gate, because the seeder is already
	 * on the inside of it.
	 *
	 * {@see firstVisibleTeamId()} survives alongside this for the steps that never
	 * named a team at all — seeding a project into "that team", and finding *a
	 * second* team to collide a folder name with.
	 */
	private function teamNamed(string $name): string {
		$id = $this->visibleTeamIdNamed($name);
		if ($id !== null) {
			return $id;
		}

		$this->penpotRpc('create-team', ['name' => $name]);

		$id = $this->visibleTeamIdNamed($name);
		if ($id === null) {
			throw new \RuntimeException("created the team \"{$name}\" but it is not visible:\n" . $this->lastOutput);
		}

		return $id;
	}

	/**
	 * The id of a visible team with this name, or null if there is none.
	 *
	 * A FAILED `list-teams` IS NOT "NO SUCH TEAM". Parsing the output regardless of
	 * the exit code would turn a broken connection into "created the team but it is
	 * not visible" — a message pointing at the wrong half of the system, with the
	 * real error nowhere in it. Whatever went wrong, the command already said so.
	 */
	private function visibleTeamIdNamed(string $name): ?string {
		$res = $this->occ('penpot_sync:list-teams');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("list-teams failed:\n{$res['output']}");
		}

		// `%-38s %-28s %s` — id, name, then "yes" or "-" for whether it is mapped.
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^([0-9a-f-]{36})\s+(.+?)\s+(?:yes|-)\s*$/i', trim($line), $m) === 1
				&& $m[2] === $name) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * The Penpot side of a first sync, as one table.
	 *
	 * ## THE PRE-STATE IS WHAT PENPOT HOLDS, NOT WHAT WE DID TO PUT IT THERE
	 *
	 * A first sync is only interesting when the team already has something in it,
	 * and "something" is a shape — projects, each with designs — not a sequence of
	 * seeding calls. One table says the shape; repeating "a project named X exists"
	 * and "a file named Y exists in the project X" says how it was built, which is
	 * not what a `Given` is for.
	 *
	 * FIND-OR-CREATE PER PROJECT, so a name may repeat down the column to give one
	 * project several designs — and so `Drafts` resolves to the team's REAL default
	 * project instead of making a second project that happens to share its name.
	 * That is what lets the Drafts scenario be written in the same table as every
	 * other one.
	 *
	 * A row with no design seeds an empty project, which is a legitimate thing for
	 * a team to contain.
	 *
	 * @Given /^the Penpot team already contains:$/
	 */
	public function thePenpotTeamAlreadyContains(TableNode $contents): void {
		foreach ($contents->getHash() as $row) {
			$project = trim((string)($row['project'] ?? ''));
			$design = trim((string)($row['design'] ?? ''));

			if ($project === '') {
				throw new \RuntimeException('every row needs a project — leave only the design blank');
			}

			$projectId = $this->projectIdNamedOrNull($project);
			if ($projectId === null) {
				$this->aPenpotProjectExistsInThatTeam($project);
				$projectId = $this->projectIdNamedOrNull($project);
			}

			if ($projectId === null) {
				throw new \RuntimeException("created the Penpot project \"{$project}\" but it is not visible");
			}

			// FIND-OR-CREATE THE DESIGN TOO, so the step is honestly a STATE. Created
			// unconditionally it was a recipe: re-stating the same pre-state — which
			// is what a second Examples row does — left the team holding two designs
			// with one name, and the mirror holding "Gizmo.penpot" beside
			// "Gizmo (2).penpot".
			if ($design !== '' && !$this->projectHoldsDesign($projectId, $design)) {
				$this->penpotRpc('create-file', ['project-id' => $projectId, 'name' => $design]);
			}
		}
	}

	/**
	 * A folder somebody made by hand, before anything was mirrored into it.
	 *
	 * The path is relative to the actor's root exactly as every other path in the
	 * suite is, so a scenario names it the same way it later asserts on it.
	 *
	 * davMkcol(), not davMkdir(): the folder sits INSIDE the mapped folder, and
	 * davMkdir only makes a top-level one.
	 *
	 * @Given /^a folder "([^"]*)" already exists$/
	 */
	public function aFolderAlreadyExists(string $path): void {
		$this->davMkcol($path);
	}

	/**
	 * The id of a project with this name, or null when the team has none.
	 *
	 * Reads Penpot directly rather than `probe --files`: this runs BEFORE the first
	 * pull, when the probe has no mirrored tree to describe, and the answer has to
	 * come from the team itself.
	 */
	private function projectHoldsDesign(string $projectId, string $design): bool {
		foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
			if (($file['name'] ?? null) === $design) {
				return true;
			}
		}

		return false;
	}

	private function projectIdNamedOrNull(string $name): ?string {
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $this->pullTeamId()]) as $project) {
			if (($project['name'] ?? null) === $name) {
				$id = (string)($project['id'] ?? '');

				return $id === '' ? null : $id;
			}
		}

		return null;
	}

	/** @Given /^a Penpot project named "([^"]*)" exists in that team$/ */
	public function aPenpotProjectExistsInThatTeam(string $name): void {
		// "THAT TEAM" IS THE ONE MOST RECENTLY NAMED, which is what lets a scenario
		// step outside the Background's default mapping by naming another team first.
		$teamId = $this->namedTeamId !== ''
			? $this->namedTeamId
			: ($this->pulledTeamId !== '' ? $this->pulledTeamId : $this->firstVisibleTeamId());
		$this->penpotRpc('create-project', ['team-id' => $teamId, 'name' => $name]);
	}

	/**
	 * The sync run, as an ACTION — an admin clicking the button or running the
	 * command, which is what `reconcile.feature` is about.
	 *
	 * ## TWO PHRASINGS, ONE FUNCTION — AND THAT IS THE POINT
	 *
	 * Cucumber and Behat ignore the KEYWORD when matching a step, so the same
	 * text can be a Given in one scenario and a When in another. What they do not
	 * ignore is the text, and the text is what a reader believes.
	 *
	 * "The admin runs a pull" as setup made it read as though an admin were
	 * permanently on call, standing by to run a sync before every gesture a user
	 * makes. That is not the system being described — it is scaffolding wearing a
	 * behaviour's clothes. Setup says what IS TRUE ("the team has been mirrored"),
	 * not who did what to make it true.
	 *
	 * So: use the ACTION phrasing where the run is the behaviour under test
	 * (reconcile.feature), and the STATE phrasing everywhere the mirror merely has
	 * to exist first. One implementation, because it is one operation.
	 *
	 * THREE PHRASINGS, ONE OPERATION:
	 *
	 *   "the admin runs a pull"                  the run IS the behaviour under
	 *                                            test — reconcile.feature only
	 *   "the team has been mirrored into
	 *    Nextcloud"                              setup: a mirror has to exist
	 *                                            before a gesture can touch it
	 *   "the team is mirrored again"             the EVENT in a Penpot-origin
	 *                                            scenario: someone changed
	 *                                            something upstream, and nothing
	 *                                            happens in Nextcloud until the
	 *                                            next sync notices
	 *
	 * That last one matters more than it looks. A Penpot-side change is context —
	 * it already happened, elsewhere, possibly by someone else. The event this
	 * app is responsible for is the sync seeing it.
	 *
	 * @When /^the admin runs a pull$/
	 * @Given /^the team has been mirrored into Nextcloud$/
	 * @When /^the team is mirrored again$/
	 */
	public function theAdminRunsAPull(): void {
		$this->occ('penpot_sync:sync pull');
	}

	/**
	 * A sync, named by its ACTOR and its SCOPE — the two things that actually
	 * differ between the four ways one starts.
	 *
	 * ## THE TRIGGER IS DATA, NOT A BEHAVIOUR
	 *
	 * The card's "Sync now", the section's "Sync from Penpot", the schedule and a
	 * user's personal sync all have the same pre-state and the same post-state.
	 * Four scenarios each asserting that in its own words was four chances to say
	 * it differently — and they had taken all four. As columns, the sameness is
	 * the point of the table.
	 *
	 * The personal sync is not built, so that one row still sits in a tagged
	 * scenario of its own rather than being quietly skipped inside a green
	 * outline — hence the throw, which is a bug if it is ever reached.
	 *
	 * @When /^(the admin|the schedule|the user) syncs (one mapping|every mapping|their personal team)$/
	 */
	public function actorSyncsScope(string $actor, string $scope): void {
		if ($actor === 'the schedule') {
			$this->theScheduleFires();

			return;
		}

		if ($actor !== 'the admin') {
			throw new \RuntimeException(
				"this harness cannot start a sync as \"{$actor}\" — that row belongs in a tagged scenario",
			);
		}

		if ($scope === 'one mapping') {
			$ids = $this->mappingIds();
			if ($ids === []) {
				throw new \RuntimeException('there is no mapping to sync');
			}

			$this->occ('penpot_sync:sync pull --mapping=' . escapeshellarg($ids[0]));

			return;
		}

		$this->occ('penpot_sync:sync pull');
	}

	/**
	 * Time as the actor, without waiting for any.
	 *
	 * ## THE INTERVAL IS NOT THE THING TO SHORTEN
	 *
	 * The obvious idea is to set the schedule to a few seconds and sleep. It does
	 * not work and would be the wrong test anyway: {@see ScheduleConfig} clamps to
	 * 300s and the job clamps again to 60s, both deliberately, because a job that
	 * re-enters faster than a pull can finish is a bug rather than a feature. A
	 * test that had to defeat two safety floors would be testing the floors.
	 *
	 * `background-job:execute --force-execute` runs the registered job NOW,
	 * ignoring its interval — which is exactly "the schedule came round" with the
	 * waiting taken out. What runs is the real {@see ScheduledPullJob}, so the row
	 * proves what it claims: the same tree appears when nobody pressed anything.
	 *
	 * The schedule has to be ENABLED first or `run()` returns immediately by
	 * design — "disabled means do nothing, not do not tick". Setting it is part of
	 * the trigger, not a fixture: a schedule nobody turned on has no actor.
	 */
	private function theScheduleFires(): void {
		$this->occ('config:app:set penpot_sync schedule_enabled --value=yes');

		$res = $this->occ('background-job:list --output=json');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not list the background jobs:\n{$res['output']}");
		}

		$jobs = json_decode($res['output'], true);
		$id = null;
		foreach (is_array($jobs) ? $jobs : [] as $job) {
			if (is_array($job) && str_contains((string)($job['class'] ?? ''), 'ScheduledPullJob')) {
				$id = (string)($job['id'] ?? '');
				break;
			}
		}

		if ($id === null || $id === '') {
			throw new \RuntimeException("the scheduled pull job is not registered:\n{$res['output']}");
		}

		$run = $this->occ('background-job:execute ' . escapeshellarg($id) . ' --force-execute');
		if ($run['exit'] !== 0) {
			throw new \RuntimeException("the scheduled pull job failed:\n{$run['output']}");
		}
	}

	/**
	 * A mirror's dates are PENPOT'S, and that is an end state rather than a
	 * behaviour — so it is a sentence any feature can end with, not a scenario.
	 *
	 * It had a scenario of its own here, which made "the dates are right" look
	 * like something syncing does rather than something every mirror is true of.
	 * Every Penpot-origin behaviour — a design created, renamed, restored — wants
	 * to end by saying this, and now each can.
	 *
	 * @Then /^"([^"]*)" carries its Penpot dates$/
	 */
	public function carriesItsPenpotDates(string $path): void {
		$this->theDesignIsDatedWhenItChanged($path);
		$this->theDesignWasCreatedWhenItWasCreated($path);
	}

	/**
	 * The folder twin. A project folder gets its project's CREATION time only —
	 * its mtime is propagated from its children by core, so asserting one would be
	 * asserting core's propagation rather than this app's behaviour (§C6.24).
	 *
	 * @Then /^the folder "([^"]*)" carries its Penpot dates$/
	 */
	public function theFolderCarriesItsPenpotDates(string $path): void {
		$this->theFolderWasCreatedWhenItsProjectWas($path);
	}

	/**
	 * The mirrored tree, as one assertion.
	 *
	 * ## A TREE IS ONE FACT TOO
	 *
	 * Every path a sync should have produced, and whether it wears a tag. It
	 * replaces a column of "the folder X carries…" / "the file Y carries…" lines
	 * that said one thing each and made a six-node tree into six assertions, none
	 * of which showed the SHAPE the sync was supposed to build.
	 *
	 * `-` in the tagged column means "no tag expected" and is not checked; naming
	 * a tag checks it is there. The tag lives here rather than in a scenario of
	 * its own because it is a property of a node in the tree, the same as the
	 * node existing at all.
	 *
	 * @Then /^the mapped folder holds:$/
	 */
	public function theMappedFolderHolds(TableNode $tree): void {
		foreach ($tree->getHash() as $row) {
			$path = trim((string)($row['path'] ?? ''));
			$tag = trim((string)($row['tagged'] ?? ''));

			if ($path === '') {
				continue;
			}

			if (!$this->davExists($path)) {
				throw new \RuntimeException("the sync did not produce \"{$path}\"");
			}

			if ($tag === '' || $tag === '-') {
				continue;
			}

			$tags = $this->davSystemTags($path);
			if (!in_array($tag, $tags, true)) {
				throw new \RuntimeException(sprintf(
					'"%s" should carry the "%s" tag, carries: %s',
					$path,
					$tag,
					$tags === [] ? '(none)' : implode(', ', $tags),
				));
			}
		}
	}

	/**
	 * A design that already exists in Penpot AND is already mirrored.
	 *
	 * ## THE SETUP PULL SHOULD NOT BE A STEP AT ALL
	 *
	 * Almost every scenario here needs a mirror before it can do anything, and
	 * spelling that out cost three lines: seed a project, seed a file, run a pull.
	 * The pull in that trio is not something the scenario DOES — it is how the
	 * precondition comes to be true, which is the step definition's business, not
	 * the reader's.
	 *
	 * Left visible it also lied about the system: it read as though an admin were
	 * on call to run a sync before every gesture a user makes. Nobody does that;
	 * the mirror is simply there, because a sync ran at some point.
	 *
	 * So the pull survives in the Gherkin in exactly two places — where the run IS
	 * the behaviour (`reconcile.feature`), and where it is the EVENT that lets
	 * Nextcloud notice an upstream change ("when the team is mirrored again").
	 * Everywhere else it belongs in here.
	 *
	 * @Given /^a mirrored design "([^"]*)" in the project "([^"]*)"$/
	 */
	public function aMirroredDesignInTheProject(string $design, string $project): void {
		$this->aPenpotProjectExistsInThatTeam($project);
		$this->aPenpotFileExistsInTheProject($design, $project);
		$this->theAdminRunsAPull();
	}

	/**
	 * The same, for a project with no design in it yet — "+ New" scenarios need
	 * the folder to exist but must create the design themselves.
	 *
	 * @Given /^a mirrored project "([^"]*)"$/
	 */
	public function aMirroredProject(string $project): void {
		$this->aPenpotProjectExistsInThatTeam($project);
		$this->theAdminRunsAPull();
	}

	/**
	 * A finished run leaves a record of itself.
	 *
	 * AN END STATE OF SYNCING, which is why it rides the outline rather than
	 * having a scenario. It came from a retired `admin-section.feature` scenario
	 * — "the panel reports the outcome of the last run" — which described a panel
	 * rather than an outcome. The panel and the API read the same stored status
	 * ({@see \OCA\PenpotSync\Service\PullStatus}); `show-config` is simply the
	 * surface this harness can reach.
	 *
	 * The timestamp and the counts are asserted as PRESENT, not as values: a
	 * clock and a tally are the run's own bookkeeping, and pinning either would
	 * assert the engine's internals instead of the fact that it kept a record.
	 *
	 * @Then /^the run is recorded with when it ran and what it did$/
	 */
	public function theRunIsRecorded(): void {
		$res = $this->occ('penpot_sync:show-config');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("show-config failed:\n{$res['output']}");
		}
		if (preg_match('/last run: ok at \S+ \(\d+ processed, \d+ exported\)/', $res['output']) !== 1) {
			throw new \RuntimeException(
				"the sync left no usable record of itself. `show-config` said:\n{$res['output']}\n"
				. 'Expected a "last run:" line naming the outcome, when it finished, and what it processed.',
			);
		}
	}

	/**
	 * TWO PHRASINGS, ONE FUNCTION. "The sync succeeds" is the word the product
	 * uses — the button says Sync, the command is `penpot_sync:sync`, the file is
	 * sync-now.feature. "The pull succeeds" is the mechanism's word and is kept
	 * because a dozen other feature files say it; new scenarios should say sync.
	 *
	 * @Then /^the pull succeeds$/
	 * @Then /^the sync succeeds$/
	 */
	public function thePullSucceeds(): void {
		if ($this->lastExit !== 0 || !str_contains($this->lastOutput, 'Pull complete')) {
			throw new \RuntimeException("the pull did not complete cleanly (exit {$this->lastExit}):\n{$this->lastOutput}");
		}
	}

	/** @Then /^the folder "([^"]*)" carries the team's Penpot id$/ */
	public function theFolderCarriesTheTeamId(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: folder', $path);
		$this->mustContain($out, 'penpot_team_id: ' . $this->pulledTeamId, $path);
	}

	/**
	 * A project folder's creation date is its Penpot project's.
	 *
	 * Only the creation time — a folder's mtime is propagated by Nextcloud from its
	 * children, so the app deliberately does not set it (§C6.24). Asserting one here
	 * would be asserting core's propagation, not our behaviour.
	 *
	 * @Then /^the folder "([^"]*)" was created when its Penpot project was$/
	 */
	public function theFolderWasCreatedWhenItsProjectWas(string $path): void {
		$name = basename($path);
		$expected = null;
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $this->pullTeamId()]) as $project) {
			if (($project['name'] ?? null) === $name) {
				$expected = self::penpotSecond($project['createdAt'] ?? null);
				break;
			}
		}
		if ($expected === null) {
			throw new \RuntimeException("no Penpot project named '{$name}' with a usable created-at");
		}
		$this->assertClock($path, 'creation_time', $expected, "the project folder's creation time");
	}

	/** @Then /^"([^"]*)" is dated when the design changed in Penpot$/ */
	public function theDesignIsDatedWhenItChanged(string $path): void {
		$file = $this->penpotFileRecordFor($path);
		$this->assertClock($path, 'getlastmodified', self::penpotSecond($file['modifiedAt'] ?? null), "the design's modified-at");
	}

	/** @Then /^"([^"]*)" was created when the design was created in Penpot$/ */
	public function theDesignWasCreatedWhenItWasCreated(string $path): void {
		$file = $this->penpotFileRecordFor($path);
		$this->assertClock($path, 'creation_time', self::penpotSecond($file['createdAt'] ?? null), "the design's created-at");
	}

	/** The team this scenario's mapping points at — set by the pull, else resolved. */
	private function pullTeamId(): string {
		return $this->pulledTeamId !== '' ? $this->pulledTeamId : $this->firstVisibleTeamId();
	}

	/**
	 * The Penpot file record behind a mirrored path, matched on the design name —
	 * the mirror's basename minus `.penpot`.
	 *
	 * @return array<string, mixed>
	 */
	private function penpotFileRecordFor(string $path): array {
		$design = preg_replace('/\.penpot$/', '', basename($path));
		// SCOPED TO THE PROJECT, not just the design name. A mirror's path is
		// `<mapped folder>/<project>/<design>.penpot`, so the project is right there —
		// and two projects in one team may hold designs with the same name. Matching on
		// the name alone could read the wrong record and then either fail for a reason
		// that has nothing to do with the mirror, or pass while validating a different
		// design entirely.
		$projectName = basename(dirname($path));
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $this->pullTeamId()]) as $project) {
			if (($project['name'] ?? null) !== $projectName) {
				continue;
			}
			foreach ($this->penpotRpcRead('get-project-files', ['project-id' => (string)($project['id'] ?? '')]) as $file) {
				if (($file['name'] ?? null) === $design) {
					return $file;
				}
			}
			throw new \RuntimeException("Penpot project '{$projectName}' holds no design named '{$design}' (from '{$path}')");
		}
		throw new \RuntimeException("no Penpot project named '{$projectName}' behind '{$path}'");
	}

	/**
	 * A Penpot timestamp from the RAW RPC channel, as a Unix second.
	 *
	 * ## THE SAME FIELD HAS TWO WIRE FORMATS, AND WHICH ONE YOU GET IS NEGOTIATED
	 *
	 * Confirmed by dumping both responses rather than reasoning about them, because
	 * two successive guesses here were wrong:
	 *
	 *   the app  (Transit)  `modified-at`  "1785467414002"              epoch millis
	 *   this test (JSON)    `modifiedAt`   "2026-08-01T01:55:42.434Z"   ISO-8601
	 *
	 * {@see penpotRpcRead} asks for `application/json`, so Penpot answers in camelCase
	 * with ISO strings; {@see \OCA\PenpotSync\Service\PenpotClient} asks for Transit
	 * and gets kebab-case with epoch millis. Neither is more correct — but a test that
	 * assumes the app's shape reads absent keys and reports "no timestamp" for records
	 * it actually found, which is exactly how this failed twice.
	 *
	 * So the app parses millis ({@see \OCA\PenpotSync\Service\MirrorTimes::parse})
	 * and this parses ISO, and the duplication is load-bearing rather than sloppy: a
	 * test sharing the app's parser could not have caught the app using the wrong one.
	 */
	private static function penpotSecond(mixed $value): ?int {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		$ts = strtotime(trim($value));
		return $ts === false ? null : $ts;
	}

	/** Compare one DAV clock on $path against $expected, or explain what it read. */
	private function assertClock(string $path, string $property, ?int $expected, string $what): void {
		if ($expected === null) {
			throw new \RuntimeException("Penpot reported no usable timestamp for {$what} on '{$path}'");
		}
		$actual = $this->davTime($path, $property);
		if ($actual !== $expected) {
			throw new \RuntimeException(
				"'{$path}' does not carry {$what}.\n"
				. '  expected: ' . gmdate('c', $expected) . "\n"
				. '  actual:   ' . ($actual === null ? 'unset' : gmdate('c', $actual)) . "\n"
				. 'A mirror whose dates are the sync run\'s tells a user nothing about the design.',
			);
		}
	}

	/** @Then /^the folder "([^"]*)" carries a Penpot project id$/ */
	public function theFolderCarriesAProjectId(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: folder', $path);
		if (preg_match('/penpot_project_id: \S/', $out) !== 1) {
			throw new \RuntimeException("expected '{$path}' to carry a Penpot project id, got:\n{$out}");
		}
	}

	/** @Then /^resolving "([^"]*)" reports the team$/ */
	public function resolvingReportsTheTeam(string $path): void {
		$this->mustContain($this->status($path), 'team=' . $this->pulledTeamId, $path);
	}

	/** @Then /^resolving "([^"]*)" reports it is inside a Penpot project$/ */
	public function resolvingReportsInProject(string $path): void {
		$this->mustContain($this->status($path), 'Membership: in_project', $path);
	}

	/**
	 * Nothing is there — the same claim whether the scenario calls it a node or a
	 * folder.
	 *
	 * `there is no folder at "X"` is the phrasing a project scenario reaches for
	 * after a move or a rename, and it means the stronger thing: not "there is
	 * something there but it is a file" but "the folder is gone". So it shares this
	 * definition rather than getting a weaker one of its own, and
	 * {@see mustNotExist()} is what makes the absence trustworthy — it insists on
	 * `No such node` rather than accepting any non-zero exit.
	 *
	 * @Then /^there is no node at "([^"]*)"$/
	 * @Then /^there is no folder at "([^"]*)"$/
	 */
	public function thereIsNoNodeAt(string $path): void {
		$this->mustNotExist($path);
	}

	/**
	 * Assert a path does not exist, ON THE SPECIFIC FAILURE that means that.
	 *
	 * A non-zero exit is NOT enough. `penpot_sync:status` also exits 1 when it
	 * cannot resolve the sync actor's home at all, so "any failure" would read a
	 * broken fixture as a passing absence assertion — a test that goes green
	 * precisely when the thing it depends on has stopped working. The command
	 * prints `No such node: <path>` for the one case we mean.
	 */
	private function mustNotExist(string $path): void {
		$res = $this->occ('penpot_sync:status ' . escapeshellarg($path));
		if ($res['exit'] === 0) {
			throw new \RuntimeException("expected no node at '{$path}', but one exists:\n{$res['output']}");
		}
		if (!str_contains($res['output'], 'No such node')) {
			throw new \RuntimeException(
				"status failed for '{$path}', but NOT because the node is absent — so this "
				. "assertion proves nothing:\n{$res['output']}",
			);
		}
	}

	// ── resolution: what the folder walk says a node belongs to ──────────────

	/**
	 * The resolver's answer for a node, as `penpot_sync:status` reports it.
	 *
	 * ASSERTED ON THE PROJECT'S REAL PENPOT ID, not on a folder name. The whole
	 * claim of `mapping-membership.feature` is that membership comes from
	 * METADATA rather than from position or naming, so an assertion that only
	 * compared paths would pass for a resolver that had never read a marker.
	 * Resolving the name through Penpot's own listing first is what makes this
	 * test the rule rather than a restatement of the folder tree.
	 *
	 * @Then /^"([^"]*)" resolves to the project "([^"]*)"$/
	 */
	public function resolvesToTheProject(string $path, string $project): void {
		$this->mustContain($this->status($path), 'project=' . $this->penpotProjectId($project), $path);
	}

	/**
	 * In a team, in no project — which is Penpot's Drafts (§6.35), and NOT an
	 * error state. The distinction this asserts is the one three separate bugs
	 * lived in (§C6.8/§C6.9/§C6.10): "no project ancestor" means Drafts, not
	 * "outside every mapping".
	 *
	 * @Then /^"([^"]*)" is in the team's Drafts$/
	 */
	public function isInTheTeamsDrafts(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Membership: drafts', $path);
		$this->mustContain($out, 'team=' . $this->pulledTeamId, $path);
		// `project=)` — the CLOSING PAREN is the assertion. The line is
		// "Membership: drafts (team=<id> project=)", so an empty project is
		// `project=` immediately followed by `)`. Matching `project=\S` instead
		// matched that paren and failed on correct output.
		$this->mustContain($out, 'project=)', $path);
	}

	/** @Then /^"([^"]*)" resolves to no Penpot mapping at all$/ */
	public function resolvesToNoMapping(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Membership: none', $path);
		// Both ids empty: "(team= project=)" exactly. See the paren note above.
		$this->mustContain($out, '(team= project=)', $path);
	}

	/**
	 * Membership is DERIVED, never stored (§6.29). There is no `penpot_mapping`
	 * key, and a file must not carry a copy of its project — a copy would have to
	 * be rewritten on every move, which is the drift the derived design exists to
	 * avoid. Asserted by checking the file's own stamp list, not the resolved
	 * line below it.
	 *
	 * @Then /^the file "([^"]*)" stores no copy of its project$/
	 */
	public function storesNoCopyOfItsProject(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: file', $path);
		if (preg_match('/^penpot_project_id: \S/m', $out) === 1) {
			throw new \RuntimeException(
				"'{$path}' carries a penpot_project_id of its own. Membership is derived from "
				. "the folder walk; a copy on the file would go stale on the first move:\n{$out}",
			);
		}
	}

	/** @Then /^no folder named "([^"]*)" exists under the mapped folder$/ */
	public function noFolderNamedExists(string $name): void {
		$this->mustNotExist('Penpot/' . $name);
	}

	/** @Then /^the file "([^"]*)" is still there and untouched$/ */
	public function isStillThereAndUntouched(string $path): void {
		if (!$this->davExists($path)) {
			throw new \RuntimeException("'{$path}' is gone — the pull pruned a file it does not manage");
		}
	}

	/**
	 * A project's Penpot id, looked up by name through the app's own probe.
	 *
	 * The probe prints `  <name>  <uuid>  [<team>]` per project — the same
	 * listing {@see GestureSteps::penpotFileNamesIn()} parses for designs.
	 */
	private function penpotProjectId(string $name): string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while resolving project '{$name}':\n{$res['output']}");
		}
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}([0-9a-f-]{36})\s+\[/', $line, $m) === 1 && trim($m[1]) === $name) {
				return $m[2];
			}
		}

		throw new \RuntimeException("Penpot has no project named '{$name}':\n{$res['output']}");
	}

	// ── the DAV surface: what a client actually sees ─────────────────────────

	/**
	 * A Penpot metadata property, read over PROPFIND (`view-design.feature`).
	 *
	 * READ THROUGH DAV, NOT THROUGH THE APP, and that is the whole point of these
	 * scenarios: the README promises these keys are visible to any WebDAV client,
	 * and `occ penpot_sync:status` cannot answer whether DAV advertises them. The
	 * keys are registered in `Application::boot()` precisely so they ride the
	 * directory PROPFIND, and nothing had ever checked that they do.
	 *
	 * @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is set$/
	 */
	public function theDavPropertyIsSet(string $key, string $path): void {
		if (($this->davReadMetadata($path, $key) ?? '') === '') {
			throw new \RuntimeException(
				"PROPFIND on '{$path}' returned no nc:metadata-{$key}. The key is registered in "
				. 'Application::boot() so DAV advertises it; a client that cannot read it has no '
				. 'way to tell a mirror from an ordinary file.',
			);
		}
	}

	/** @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is "([^"]*)"$/ */
	public function theDavPropertyEquals(string $key, string $path, string $expected): void {
		$actual = $this->davReadMetadata($path, $key) ?? '';
		if ($actual !== $expected) {
			throw new \RuntimeException(
				"expected nc:metadata-{$key} of '{$path}' to be '{$expected}', got '{$actual}'",
			);
		}
	}

	/** @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is absent$/ */
	public function theDavPropertyIsAbsent(string $key, string $path): void {
		$actual = $this->davReadMetadata($path, $key) ?? '';
		if ($actual !== '') {
			throw new \RuntimeException(
				"expected '{$path}' to carry NO nc:metadata-{$key}, got '{$actual}'",
			);
		}
	}

	/**
	 * The custom mimetype, read off the same PROPFIND a Files client uses.
	 *
	 * NOT `application/zip`, which is what a `.penpot` archive would otherwise be
	 * sniffed as — the whole reason the app registers a mimetype and ships a
	 * repair step for it (§C6.1). Asserted over DAV because that is where the
	 * Files app reads it from; the mapping file on disk being right proves
	 * nothing about what a client is told.
	 *
	 * @Then /^the DAV content type of "([^"]*)" is "([^"]*)"$/
	 */
	public function theDavContentTypeIs(string $path, string $expected): void {
		$actual = $this->davContentType($path);
		if ($actual !== $expected) {
			throw new \RuntimeException(
				"expected '{$path}' to be served as '{$expected}', got '{$actual}'. "
				. 'A generic type means the mimetype repair step did not run, and the Files app '
				. 'shows a zip icon with no Open in Penpot action.',
			);
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/** Run `penpot_sync:status <path>`, requiring success, and return its output. */
	private function status(string $path): string {
		$res = $this->occ('penpot_sync:status ' . escapeshellarg($path));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("status failed for '{$path}':\n{$res['output']}");
		}
		return $res['output'];
	}

	private function mustContain(string $haystack, string $needle, string $path): void {
		if (!str_contains($haystack, $needle)) {
			throw new \RuntimeException("expected status of '{$path}' to contain '{$needle}', got:\n{$haystack}");
		}
	}

	/**
	 * Post a command straight to Penpot's RPC bus with the minted service-account
	 * token — the seed/assertion channel, bypassing the app. The Transit response
	 * body is NOT decoded — and because this asks for `application/json` rather than
	 * Transit, Penpot answers in a DIFFERENT SHAPE from the one the app sees:
	 * camelCase keys (`modifiedAt`, not `modified-at`) carrying ISO-8601 strings, not
	 * the epoch millis the Transit channel returns. Read a value out of here and you
	 * are reading Penpot's JSON dialect, not the app's.
	 *
	 * SUCCESS IS 200 **OR** 204, because Penpot's own answers disagree: the
	 * creators return a body, `delete-file` returns nothing at all. Asserting 200
	 * alone would have failed a delete that worked perfectly — the same "there is
	 * no convention, only a table" lesson the client's param table records.
	 *
	 * PARAM CASING IS PER-COMMAND, NOT A HOUSE RULE. Most creators take
	 * kebab-case on the wire (`project-id`, saga §6.38), but `delete-file` and
	 * `rename-file` take a plain `id` (§6.54) — so callers spell each key the way
	 * that one command's schema does, and this helper passes them through
	 * untouched rather than normalising anything.
	 *
	 * @param array<string, string> $params the command's own wire params, verbatim
	 */
	/**
	 * The same channel, but returning the DECODED response.
	 *
	 * `penpotRpc()` deliberately throws away the body — every existing caller
	 * only needs "did it work". The trash assertions need to READ, so this is the
	 * read twin rather than a change to the write one.
	 *
	 * `Accept: application/json` is sent here on purpose: the app must never do
	 * that (it breaks Transit decoding, §R1.4), but a TEST asserting on Penpot's
	 * state wants plain JSON and is not exercising the decoder.
	 *
	 * @param array<string, string> $params
	 *
	 * @return list<array<string, mixed>>
	 */
	/**
	 * Encode a command's params for the wire.
	 *
	 * `JSON_FORCE_OBJECT` ONLY WHEN THE MAP IS EMPTY, and the distinction is not
	 * cosmetic. Penpot 500s on `[]` where it wants `{}` (§R1.3), which is why the
	 * flag was here — but applied unconditionally it also rewrites every nested
	 * LIST into an object, so a set param like `ids: ["<uuid>"]` goes out as
	 * `{"0":"<uuid>"}` and the command fails validation. A non-empty PHP map
	 * already encodes as a JSON object without any help; the flag is needed for
	 * exactly one case and harmful in another.
	 *
	 * Latent until the first list-valued param — `permanently-delete-team-files`,
	 * whose whole payload is a set of ids — needed to go through here.
	 *
	 * @param array<string, string|list<string>> $params
	 */
	private function encodeParams(array $params): string {
		return json_encode($params, JSON_THROW_ON_ERROR | ($params === [] ? JSON_FORCE_OBJECT : 0));
	}

	private function penpotRpcRead(string $command, array $params): array {
		$url = getenv('PENPOT_URL');
		$token = getenv('PENPOT_TOKEN');
		if ($url === false || $url === '' || $token === false || $token === '') {
			throw new \RuntimeException('PENPOT_URL / PENPOT_TOKEN are not set.');
		}

		$response = (new Client())->post(
			rtrim($url, '/') . '/api/rpc/command/' . $command,
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body' => $this->encodeParams($params),
				'http_errors' => false,
				'connect_timeout' => 10,
				'timeout' => 30,
			],
		);

		$body = (string)$response->getBody();
		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException("{$command} failed: HTTP {$response->getStatusCode()}\n{$body}");
		}

		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function penpotRpc(string $command, array $params): void {
		$url = getenv('PENPOT_URL');
		$token = getenv('PENPOT_TOKEN');
		if ($url === false || $url === '' || $token === false || $token === '') {
			throw new \RuntimeException('PENPOT_URL / PENPOT_TOKEN are not set — the Penpot fixture cannot be created.');
		}

		$response = (new Client())->post(
			rtrim($url, '/') . '/api/rpc/command/' . $command,
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Content-Type' => 'application/json',
				],
				'body' => $this->encodeParams($params),
				'http_errors' => false,
				// Bound the wait: a wedged Penpot must fail the scenario, not hang
				// the whole integration job until the CI runner's global timeout.
				'connect_timeout' => 10,
				'timeout' => 30,
			],
		);

		if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 204) {
			throw new \RuntimeException(sprintf(
				"Penpot %s failed: HTTP %d\n%s",
				$command,
				$response->getStatusCode(),
				(string)$response->getBody(),
			));
		}
	}

	// ── a design born in Penpot, arriving as a file ──────────────────────────

	/** The design {@see someoneCreatesADesignInThePenpotProject()} just made. */
	private string $designMadeInPenpot = '';

	/**
	 * Someone opens Penpot and makes a design in one of the mapped projects.
	 *
	 * ## THE PROJECT IS RESOLVED FROM WHAT THE BACKGROUND DECLARED, NOT BY NAME
	 *
	 * A by-name lookup across the probe is the obvious implementation and it is the
	 * bug this suite has been bitten by three times: Penpot state accumulates across
	 * a leg, teams are find-or-create, and the Backgrounds deliberately put projects
	 * of the same name in two teams. `Nested` in the link team and a `Nested` some
	 * earlier scenario left in the sync team are indistinguishable by name.
	 *
	 * {@see ArrangeSteps::$declaredProjectIds} is keyed by the FOLDER the Background
	 * declared, which carries the team in its first segment — so the folder whose
	 * basename is this project name identifies the project exactly. The by-name
	 * probe stays as a fallback for a scenario that names a project it did not
	 * declare, and it raises rather than guessing when the name is ambiguous.
	 *
	 * The pull follows in the same step because the spec says "someone creates a
	 * design" and then asserts a FILE: the sync is how one becomes the other, and it
	 * is not a gesture the person in the scenario performs.
	 *
	 * @When /^someone creates a design in the "([^"]*)" Penpot project$/
	 */
	public function someoneCreatesADesignInThePenpotProject(string $project): void {
		$projectId = $this->declaredProjectIdNamed($project);

		$before = $this->penpotFileIdsInProject($projectId);
		$this->penpotRpc('create-file', ['project-id' => $projectId, 'name' => self::MADE_IN_PENPOT]);

		$made = array_values(array_diff($this->penpotFileIdsInProject($projectId), $before));
		if (count($made) !== 1) {
			throw new \RuntimeException(sprintf(
				"creating a design in '%s' should have added exactly one; it added %d",
				$project,
				count($made),
			));
		}
		$this->designMadeInPenpot = $made[0];

		$this->theAdminRunsAPull();
	}

	/**
	 * The pull brought it down, as a file carrying that design's id.
	 *
	 * BY ID, so this cannot pass on a file an earlier scenario left in the folder,
	 * and so the `Then the file holds:` that follows is talking about the arrival
	 * rather than about whatever else is there. Finding it is also what seats the
	 * cursor — the scenario never names the filename because Penpot chose it.
	 *
	 * @Then /^a matching file is created in "([^"]*)"$/
	 */
	public function aMatchingFileIsCreatedIn(string $folder): void {
		if ($this->designMadeInPenpot === '') {
			throw new \RuntimeException(
				'the scenario says "a matching file" but nothing was created in Penpot first.',
			);
		}

		$folder = trim($folder, '/');
		$this->until(
			fn (): bool => $this->fileInCarrying($folder, $this->designMadeInPenpot) !== null,
			fn (): string => sprintf(
				"no file under '%s' carries the design %s; it holds: %s",
				$folder,
				$this->designMadeInPenpot,
				implode(', ', $this->davChildren($folder)) ?: '(nothing)',
			),
		);

		$this->currentFilePath = (string)$this->fileInCarrying($folder, $this->designMadeInPenpot);
		$this->currentFolder = $folder;
		$this->currentFileId = $this->designMadeInPenpot;
	}

	/** The `.penpot` in a folder carrying this design id, or null. */
	private function fileInCarrying(string $folder, string $id): ?string {
		foreach ($this->davChildren($folder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			if (($this->davReadMetadata($child, 'penpot_id') ?? '') === $id) {
				return $child;
			}
		}

		return null;
	}

	/** The ids in a project, straight from Penpot. @return list<string> */
	private function penpotFileIdsInProject(string $projectId): array {
		$ids = [];
		foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
			if (isset($file['id']) && is_string($file['id'])) {
				$ids[] = $file['id'];
			}
		}

		return $ids;
	}

	/** A declared project's id by its folder's basename; see the caller for why. */
	private function declaredProjectIdNamed(string $project): string {
		foreach ($this->declaredProjectIds as $folder => $id) {
			if (basename($folder) === $project) {
				return $id;
			}
		}

		$teams = $this->penpotProjectTeams($project);
		if (count($teams) === 1) {
			return (string)$this->penpotProjectIdInTeam($project, $teams[0]);
		}

		throw new \RuntimeException(sprintf(
			"'%s' is not a project this scenario declared, and Penpot has it in %s — "
			. 'declare it in the Background so the team is unambiguous.',
			$project,
			$teams === [] ? 'no team at all' : count($teams) . ' teams (' . implode(', ', $teams) . ')',
		));
	}
}
