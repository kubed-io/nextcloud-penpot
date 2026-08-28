<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * `connection/sync-now.feature` — the instance-wide sync, in both directions.
 *
 * ## WHY THIS IS ITS OWN TRAIT AND NOT MORE OF {@see PullSteps}
 *
 * Every other feature in the suite asks about ONE mapping and asserts around one
 * design. This one asserts THE WHOLE TREE across three mappings at once, which
 * needs a different kind of step: a recursive listing, and a comparison that
 * fails on anything extra as loudly as on anything missing.
 *
 * `exactly` is the whole point of the tree table (features/AGENTS.md#the-tree-is-the-assertion).
 * A table that only checked its rows were present could not catch a stray
 * `Cogs (2)` sitting beside the real one — which is precisely the adoption bug
 * the scenario exists to prove is gone.
 */
trait SyncNowSteps {
	/**
	 * Where {@see syncNowArchive()} keeps the design it exports bytes from.
	 *
	 * Inside the mapping, because a design can only be born in one — and therefore
	 * inside what both `exactly` assertions walk, which is why they skip it.
	 */
	private const FIXTURE_FOLDER = 'Penpot/Sync Now Source';

	/**
	 * Rows the Background asked for, waiting for the mappings to be made.
	 *
	 * @var list<string>
	 */
	private array $syncNowPending = [];

	/**
	 * Penpot's side of the picture, across SEVERAL teams.
	 *
	 * {@see PullSteps::thePenpotTeamAlreadyContains()} is the one-team form and is
	 * deliberately left alone: it resolves projects against "the team most recently
	 * named", which is exactly right for a scenario about one mapping and cannot
	 * express a Background that seeds three teams before any of them is named.
	 *
	 * FIND-OR-CREATE AT EVERY LEVEL — team, project, design. The legs share one
	 * Penpot and this Background runs once per scenario, so the second run must see
	 * the state the first left and agree with it rather than building a second copy
	 * of everything. A `Given` states what is true; if it is already true, stop.
	 *
	 * @Given /^Penpot holds these resources:$/
	 */
	public function penpotHoldsTheseResources(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$team = trim((string)($row['team'] ?? ''));
			$project = trim((string)($row['project'] ?? ''));
			$design = trim((string)($row['design'] ?? ''));

			if ($team === '' || $project === '') {
				throw new \RuntimeException('every row needs a team and a project');
			}

			$teamId = $this->teamNamed($team);
			$projectId = $this->projectNamedInTeam($teamId, $project);

			if ($design !== '' && !$this->projectHoldsDesign($projectId, $design)) {
				$this->penpotRpc('create-file', ['project-id' => $projectId, 'name' => $design]);
				fwrite(STDERR, "PROBE seeded {$team}/{$project}/{$design}\n");
			} elseif ($design !== '') {
				fwrite(STDERR, "PROBE already there {$team}/{$project}/{$design}\n");
			}
		}
	}

	/**
	 * Nextcloud's side of the picture — what was already there before any sync.
	 *
	 * A path ending in `.penpot` is seeded as a REAL ARCHIVE, not an empty file,
	 * and that is what makes the push scenario mean anything: `Hand Made.penpot`
	 * has to hold bytes Penpot can import, or "make designs of the files already
	 * there" is testing that the app skips an empty file. Everything else is a
	 * plain folder (no extension) or an ordinary file.
	 *
	 * ## THE ROWS ARE RECORDED HERE AND WRITTEN AT SYNC TIME
	 *
	 * This step runs BEFORE `the following mappings were made` — the Background is
	 * a picture of the pre-state, and both siblings order it that way. But nothing
	 * is mapped yet when it runs, and the `.penpot` row cannot be written without a
	 * mapping: real archive bytes can only be OBTAINED by exporting a design, and a
	 * design can only be born inside a mapped folder (an empty one created anywhere
	 * else is refused by {@see \OCA\PenpotSync\Service\MoveRules}, correctly —
	 * `create-file` needs a project).
	 *
	 * {@see ArrangeSteps::theFollowingMappingsWereMade()} also EMPTIES each mapped
	 * folder, which used to delete these rows out from under the Background. That
	 * is fixed at the source now (the emptying is latched per scenario, as the
	 * unmap already was), so this deferral carries only the archive's weight.
	 *
	 * So the table is recorded and replayed at the START OF THE SYNC, by which time
	 * the mappings exist. The pre-state the scenario describes is true when the
	 * scenario acts, which is all a Background claims.
	 *
	 * @Given /^Nextcloud holds these resources:$/
	 */
	public function nextcloudHoldsTheseResources(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = ltrim(trim((string)($row['path'] ?? '')), '/');
			if ($path === '') {
				throw new \RuntimeException('every row needs a path');
			}

			$this->syncNowPending[] = $path;
		}
	}

	/**
	 * Write the rows the Background deferred, now that the mappings are made.
	 *
	 * Called from BOTH sync steps rather than one, because either direction may be
	 * the first thing a scenario does and the pre-state has to hold for both.
	 * Idempotent: the list is drained as it is replayed, and a row that already
	 * exists is left alone — the legs share one Nextcloud, so a second scenario
	 * finds what the first left and a `Given` that is already true stops.
	 */
	private function syncNowWritePending(): void {
		$pending = $this->syncNowPending;
		$this->syncNowPending = [];

		foreach ($pending as $path) {
			if ($this->davExists($path)) {
				continue;
			}

			$this->syncNowAncestors($path);

			if (str_ends_with($path, '.penpot')) {
				$this->davPut($path, $this->syncNowArchive());
				continue;
			}
			if (pathinfo($path, PATHINFO_EXTENSION) === '') {
				$this->davMkcol($path);
				continue;
			}
			$this->davPut($path, "not a design\n");
		}
	}

	/**
	 * A sync from Penpot, started by the admin or by the schedule.
	 *
	 * Delegates to {@see PullSteps::actorSyncsScope()} — the direction is in the
	 * sentence for the reader's sake, and "every mapping from Penpot" IS the pull.
	 * Writing a second implementation here would be a second answer to "what does
	 * a bulk pull do".
	 *
	 * @When /^(the admin|the schedule) syncs every mapping from Penpot$/
	 */
	public function actorSyncsEveryMappingFromPenpot(string $actor): void {
		$this->syncNowProbe('before writePending');
		$this->syncNowWritePending();
		$this->syncNowProbe('after writePending, before sync');
		$this->actorSyncsScope($actor, 'every mapping');
	}

	/**
	 * A sync TO Penpot — the archives already here become designs.
	 *
	 * SYNCHRONOUS, VIA `occ`, exactly as the pull's step is. The button queues a
	 * job and the CLI does not, and the CLI is the one a test can wait on: polling
	 * a queued job would be testing Nextcloud's cron, which is neither this app's
	 * behaviour nor reliable inside a leg.
	 *
	 * @When /^the admin syncs every mapping to Penpot$/
	 */
	public function theAdminSyncsEveryMappingToPenpot(): void {
		$this->syncNowWritePending();

		$res = $this->occ('penpot_sync:sync push');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("the push failed:\n{$res['output']}");
		}
	}

	/**
	 * The whole mirrored tree, and nothing besides.
	 *
	 * SCOPED TO THE MAPPED ROOTS the table itself names, never the user's whole
	 * home. The legs share one Nextcloud and earlier features leave folders like
	 * `Scratch` behind; a walk from the root would fail on those, which are not
	 * this scenario's business. The roots are the first segment of each expected
	 * path, so the table stays the only place the shape is written down.
	 *
	 * @Then /^Nextcloud holds exactly these resources:$/
	 */
	public function nextcloudHoldsExactlyTheseResources(TableNode $table): void {
		$expected = [];
		$roots = [];
		foreach ($table->getHash() as $row) {
			$path = trim(ltrim(trim((string)($row['path'] ?? '')), '/'));
			if ($path === '') {
				continue;
			}
			$expected[] = $path;
			$roots[explode('/', $path)[0]] = true;
		}
		sort($expected);

		$actual = [];
		foreach (array_keys($roots) as $root) {
			foreach ($this->syncNowWalk($root) as $found) {
				if ($this->syncNowIsFixture($found)) {
					continue;
				}
				$actual[] = $found;
			}
		}
		sort($actual);

		if ($actual === $expected) {
			return;
		}

		$missing = array_diff($expected, $actual);
		$extra = array_diff($actual, $expected);

		throw new \RuntimeException(
			"the mirrored tree is not what the table says.\n"
			. ($missing === [] ? '' : "missing:\n  " . implode("\n  ", $missing) . "\n")
			. ($extra === [] ? '' : "unexpected:\n  " . implode("\n  ", $extra) . "\n"),
		);
	}

	/**
	 * Penpot's whole shape, and nothing besides — the push's twin of the tree table.
	 *
	 * SCOPED TO THE PROJECTS THE TABLE NAMES, and the scope is narrower than it
	 * looks like it should be for a reason worth stating.
	 *
	 * Scoping to the TEAMS was the obvious reading of `exactly` and is wrong here:
	 * `Design Team` is shared, and `mapping/sync-now.feature` — in this same leg —
	 * seeds `Levers/Sprocket` into it. That design is in no table of this feature,
	 * so a team-wide sweep reports it as `unexpected:` and the leg goes red on a
	 * push that did exactly the right thing. It passes today only because Behat
	 * happens to run this file first, which is ordering luck rather than a property
	 * of the test.
	 *
	 * Within a named project, though, `exactly` is fully enforced — which is what
	 * the scenario is actually about. A push that invented a second `Hand Made`
	 * beside the real one, or filed one into the wrong project, still fails.
	 *
	 * @Then /^Penpot holds exactly these resources:$/
	 */
	public function penpotHoldsExactlyTheseResources(TableNode $table): void {
		$expected = [];
		$wanted = [];
		foreach ($table->getHash() as $row) {
			$team = trim((string)($row['team'] ?? ''));
			$project = trim((string)($row['project'] ?? ''));
			$design = trim((string)($row['design'] ?? ''));
			if ($team === '' || $project === '') {
				continue;
			}
			$wanted[$team][$project] = true;
			if ($design !== '') {
				$expected[] = $team . ' / ' . $project . ' / ' . $design;
			}
		}
		sort($expected);

		$actual = [];
		foreach ($wanted as $team => $projects) {
			$teamId = $this->teamNamed((string)$team);
			foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
				$projectId = (string)($project['id'] ?? '');
				$projectName = (string)($project['name'] ?? '');
				if ($projectId === '' || !isset($projects[$projectName])) {
					continue;
				}
				foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
					$name = (string)($file['name'] ?? '');
					if ($name !== '') {
						$actual[] = $team . ' / ' . $projectName . ' / ' . $name;
					}
				}
				// (the fixture's own project cannot appear here: the table names the
				// projects to look in, and no table names it — see the docblock)
			}
		}
		sort($actual);

		if ($actual === $expected) {
			return;
		}

		$missing = array_diff($expected, $actual);
		$extra = array_diff($actual, $expected);

		throw new \RuntimeException(
			"Penpot does not hold what the table says.\n"
			. ($missing === [] ? '' : "missing:\n  " . implode("\n  ", $missing) . "\n")
			. ($extra === [] ? '' : "unexpected:\n  " . implode("\n  ", $extra) . "\n"),
		);
	}

	/**
	 * Every path at or below $root, as paths relative to the user's home.
	 *
	 * @return list<string>
	 */
	private function syncNowWalk(string $root, int $depth = 0): array {
		if ($depth > 20 || !$this->davExists($root)) {
			return [];
		}

		$out = [];
		foreach ($this->davChildren($root) as $child) {
			$out[] = $child;
			// A CHILD IS A FOLDER IF IT HAS CHILDREN OF ITS OWN — or is empty, in
			// which case the recursive PROPFIND simply answers with nothing and the
			// leaf stands. Asking `resourcetype` per node would be one more round
			// trip per file for an answer this walk does not need.
			foreach ($this->syncNowWalk($child, $depth + 1) as $grandchild) {
				$out[] = $grandchild;
			}
		}

		return $out;
	}

	/** Find-or-create a project by name inside a known team. */
	private function projectNamedInTeam(string $teamId, string $name): string {
		$id = $this->projectIdInTeamOrNull($teamId, $name);
		if ($id !== null) {
			return $id;
		}

		$this->penpotRpc('create-project', ['team-id' => $teamId, 'name' => $name]);

		$id = $this->projectIdInTeamOrNull($teamId, $name);
		if ($id === null) {
			throw new \RuntimeException("created the project \"{$name}\" but it is not visible");
		}

		return $id;
	}

	private function projectIdInTeamOrNull(string $teamId, string $name): ?string {
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
			if (($project['name'] ?? null) === $name) {
				$id = (string)($project['id'] ?? '');

				return $id === '' ? null : $id;
			}
		}

		return null;
	}

	/** Every folder above a path, made as a bare folder. */
	private function syncNowAncestors(string $path): void {
		$parts = explode('/', $path);
		array_pop($parts);
		$soFar = '';
		foreach ($parts as $part) {
			$soFar = $soFar === '' ? $part : $soFar . '/' . $part;
			if (!$this->davExists($soFar)) {
				$this->davMkcol($soFar);
			}
		}
	}

	/**
	 * Real `.penpot` bytes, made in the mapping this feature already declares.
	 *
	 * ## THE SOURCE HAS TO BE BORN INSIDE A MAPPING
	 *
	 * An empty `.penpot` created where nothing mirrors a Penpot team is refused by
	 * {@see \OCA\PenpotSync\Service\MoveRules::refusalForCreating()} with a 403 —
	 * `create-file` needs a project, and outside a mapping there is none. So the
	 * source cannot simply be seeded somewhere out of the way; it has to start in a
	 * mapped folder and be cleaned up afterwards.
	 *
	 * ## AND IT USES THE FEATURE'S OWN MAPPING, NOT ONE OF ITS OWN
	 *
	 * An earlier version mapped a donor team here and tore it down again, which was
	 * quietly destructive: `remove-mapping` tears down a mapping's mirrors
	 * ({@see \OCA\PenpotSync\Service\MappingTeardownService}), and the teardown
	 * deleted the Background rows that had already been written — `notes.txt` and
	 * `plan.txt` vanished out of a Background that had just created them.
	 *
	 * The mapping the Background declares is enough, and this is only reachable at
	 * all because the caller defers until sync time: by then the mappings are made,
	 * and {@see \OCA\PenpotSync\Service\StorageService::ensureRoot()} has marked
	 * the root, so a design can be born in it straight away.
	 *
	 * CACHED PER SCENARIO, because it costs a create, a pull and an export.
	 */
	private function syncNowArchive(): string {
		if ($this->syncNowArchiveBytes !== '') {
			return $this->syncNowArchiveBytes;
		}

		// ONE FIXED NAME, shared by every scenario in the leg. It is never deleted
		// now, so there is no trash entry to collide with — and a stable name is
		// what lets the assertions skip it without guessing.
		$folder = self::FIXTURE_FOLDER;
		$path = $folder . '/Source.penpot';

		// ONLY CREATE IT ONCE PER LEG. The fixture is permanent now, so on the
		// second scenario it is already there holding its archive — and a blind
		// `PUT ''` would blank a real export and then pull over the wreckage, which
		// is the "arranging by overwriting" hazard {@see ArrangeSteps} documents.
		if (!$this->davExists($path)) {
			$this->syncNowAncestors($path);
			$this->davPut($path, '');
			$this->theAdminRunsAPull();
		}

		$bytes = $this->davGet($path);
		if (!str_starts_with($bytes, "PK\x03\x04")) {
			throw new \RuntimeException(
				"the harness could not produce a real .penpot archive: '{$path}' holds "
				. strlen($bytes) . ' bytes that are not a ZIP.',
			);
		}

		// NOT DELETED, AND THAT WAS THE LAST BUG IN THIS HELPER.
		//
		// Sweeping the folder up looked tidy and is a gesture inside a LIVE mapping:
		// a delete there reaches Penpot ({@see \OCA\PenpotSync\Listener\DeleteListener}),
		// and it took the Background's own designs with it — `Gizmo` and `Doohickey`
		// vanished out of Design Team between one scenario and the next, which is
		// the harness's own "unmap before touching any content" warning arriving
		// from the other direction.
		//
		// So the fixture STAYS, and the two `exactly` assertions skip it by name
		// instead ({@see syncNowIsFixture()}). An assertion that ignores one known
		// path is honest; a delete that silently trashes designs is not.

		return $this->syncNowArchiveBytes = $bytes;
	}

	/** The archive {@see syncNowArchive()} produced, for this scenario. */
	private string $syncNowArchiveBytes = '';

	/**
	 * Both caches are per-scenario, and the pending list MUST be: a scenario that
	 * never reaches a sync step would otherwise leave its rows for the next one to
	 * write into a tree that had not asked for them.
	 *
	 * @BeforeScenario
	 */
	public function armSyncNow(): void {
		$this->syncNowPending = [];
		$this->syncNowArchiveBytes = '';
	}

	/**
	 * Is this path the archive fixture, rather than something the table describes?
	 *
	 * {@see syncNowArchive()} has to keep a real design inside the mapping to
	 * export bytes from, and deleting it afterwards is what trashed the
	 * Background's own designs. So it stays, and the tree assertion steps over it —
	 * one named path, matched as a prefix so the folder and its contents both go.
	 */
	private function syncNowIsFixture(string $path): bool {
		return $path === self::FIXTURE_FOLDER
			|| str_starts_with($path, self::FIXTURE_FOLDER . '/');
	}

	/** TEMPORARY: what does Design Team actually hold right now? */
	private function syncNowProbe(string $when): void {
		$teamId = $this->teamNamed('Design Team');
		$seen = [];
		foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
			$pid = (string)($project['id'] ?? '');
			$pname = (string)($project['name'] ?? '');
			if ($pid === '') {
				continue;
			}
			foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $pid]) as $file) {
				$seen[] = $pname . '/' . (string)($file['name'] ?? '');
			}
		}
		sort($seen);
		fwrite(STDERR, "PROBE [{$when}] Design Team = " . implode(', ', $seen) . "\n");
	}
}
