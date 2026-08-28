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
	 * @Given /^Nextcloud holds these resources:$/
	 */
	public function nextcloudHoldsTheseResources(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = ltrim(trim((string)($row['path'] ?? '')), '/');
			if ($path === '') {
				throw new \RuntimeException('every row needs a path');
			}

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
	 * SCOPED TO THE TEAMS THE TABLE NAMES, for the same reason the tree walk is
	 * scoped to its roots: the teams are shared across every leg, and a design some
	 * other feature created is not this scenario's business. Within a named team,
	 * though, `exactly` is enforced — a push that invented a second `Hand Made`
	 * beside the real one is precisely the failure worth catching.
	 *
	 * @Then /^Penpot holds exactly these resources:$/
	 */
	public function penpotHoldsExactlyTheseResources(TableNode $table): void {
		$expected = [];
		$teams = [];
		foreach ($table->getHash() as $row) {
			$team = trim((string)($row['team'] ?? ''));
			$project = trim((string)($row['project'] ?? ''));
			$design = trim((string)($row['design'] ?? ''));
			if ($team === '' || $project === '') {
				continue;
			}
			$teams[$team] = true;
			if ($design !== '') {
				$expected[] = $team . ' / ' . $project . ' / ' . $design;
			}
		}
		sort($expected);

		$actual = [];
		foreach (array_keys($teams) as $team) {
			$teamId = $this->teamNamed($team);
			foreach ($this->penpotRpcRead('get-projects', ['team-id' => $teamId]) as $project) {
				$projectId = (string)($project['id'] ?? '');
				$projectName = (string)($project['name'] ?? '');
				if ($projectId === '') {
					continue;
				}
				foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
					$name = (string)($file['name'] ?? '');
					if ($name !== '') {
						$actual[] = $team . ' / ' . $projectName . ' / ' . $name;
					}
				}
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
	 * Real `.penpot` bytes, produced where NEITHER assertion in this feature looks.
	 *
	 * ## THIS IS THE HARDEST FIXTURE IN THE FEATURE, AND WHY
	 *
	 * The archive has to come from Penpot — only a real export is importable, and
	 * `Hand Made.penpot` holding a fake ZIP would make the push scenario prove
	 * nothing. But producing one means creating a design SOMEWHERE, and this feature
	 * asserts both sides with `exactly`:
	 *
	 *   - seeded in a mapped folder, the source appears in the pull's tree table;
	 *   - seeded in a mapped TEAM, its project appears in the push's Penpot table.
	 *
	 * {@see GestureSteps::aRealPenpotArchive()} does the first — it leaves
	 * `Penpot/Archive Source/Source.penpot` standing on purpose, since removing it
	 * would delete the design in Penpot. Harmless in every other feature, fatal here.
	 *
	 * So the source lives in a team this feature never maps and never names. Its
	 * projects are nobody's business and its designs are in no table, so both
	 * `exactly` assertions stay blind to it.
	 *
	 * ## AND THE DONOR MAPPING IS TORN DOWN AGAIN
	 *
	 * The bytes have to be read back through a mapping — that is the only way an
	 * export reaches Nextcloud — but a mapping left standing is a FOURTH mapping
	 * the push would walk, whose archive would then be imported into the donor
	 * team and appear in nothing. So it is removed as soon as the bytes are in
	 * hand. The file survives the removal holding its archive (a `sync` mapping's
	 * designs are kept and unmapped, `mapping/delete.feature`), which is exactly
	 * what makes the cached bytes readable on a later scenario.
	 *
	 * ORDER MATTERS AND IS NOT LEFT TO LUCK: this runs inside `Nextcloud holds
	 * these resources`, which the Background states BEFORE `the following mappings
	 * were made`. It is called out because
	 * {@see PullSteps::aPenpotTeamNamedIsMappedToTheFolder()} clears every existing
	 * mapping first — harmless here, and destructive if this ever ran later.
	 *
	 * CACHED PER SCENARIO, because it costs a team, a design, a pull and an export.
	 */
	private function syncNowArchive(): string {
		if ($this->syncNowArchiveBytes !== '') {
			return $this->syncNowArchiveBytes;
		}

		$path = 'Sync Now Source/Source.penpot';
		if (!$this->davExists($path)) {
			// A TEAM NO TABLE NAMES. `teamNamed()` is find-or-create, so the legs
			// share one of these rather than accumulating them.
			$this->aPenpotTeamNamedIsMappedToTheFolder('Archive Donor', 'Sync Now Source', 'sync');
			$this->davPut($path, '');
			$this->theAdminRunsAPull();
		}

		$bytes = $this->davGet($path);

		// The mapping has served its purpose; the bytes outlive it.
		$this->noPenpotTeamsAreMapped();
		if (!str_starts_with($bytes, "PK\x03\x04")) {
			throw new \RuntimeException(
				"the harness could not produce a real .penpot archive: '{$path}' holds "
				. strlen($bytes) . ' bytes that are not a ZIP.',
			);
		}

		return $this->syncNowArchiveBytes = $bytes;
	}

	/** The archive {@see syncNowArchive()} produced, for this scenario. */
	private string $syncNowArchiveBytes = '';
}
