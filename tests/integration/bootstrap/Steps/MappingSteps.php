<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * Team-mapping steps, driven over `occ` against a real Nextcloud and a real
 * Penpot.
 *
 * ## NO PHPUnit ASSERTIONS IN HERE, DELIBERATELY (saga R1.6)
 *
 * PHPUnit builds an assertion's failure *message* through
 * `TextUI\Configuration\Registry`, which only exists when PHPUnit bootstrapped
 * the run. Under Behat it is null, so a **failing** assertion dies with
 * `Registry::get(): Return value must be of type Configuration, null returned`
 * — replacing the diagnostic exactly when it matters. Passing assertions are
 * unaffected, which is why the bug hides until the first real failure.
 *
 * So every check here throws a plain `RuntimeException` carrying the command's
 * own output. That output is usually the whole answer: the app's error messages
 * are written to name the fix.
 *
 * ## WHY THESE RUN AGAINST A REAL PENPOT
 *
 * Mapping is gated on `get-teams` (saga §6.18), so a mocked transport would only
 * prove that the mock returns what the mock was told to return. The token is
 * minted per run (§6.47), so CI has a genuine team to map.
 */
trait MappingSteps {
	/** @Given no Penpot teams are mapped */
	public function noPenpotTeamsAreMapped(): void {
		foreach ($this->mappingIds() as $id) {
			$this->occ('penpot_sync:remove-mapping ' . escapeshellarg($id));
		}

		if ($this->mappingIds() !== []) {
			throw new \RuntimeException("could not clear the existing mappings:\n" . $this->lastOutput);
		}
	}

	/** @When the admin maps the first team the service account can see */
	public function theAdminMapsTheFirstVisibleTeam(): void {
		$teamId = $this->firstVisibleTeamId();

		$res = $this->occ('penpot_sync:add-mapping ' . escapeshellarg($teamId));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("add-mapping failed for {$teamId}:\n{$res['output']}");
		}
	}

	/** @When /^the admin tries to map the Penpot team "([^"]*)"$/ */
	public function theAdminTriesToMapTheTeam(string $teamId): void {
		$this->occ('penpot_sync:add-mapping ' . escapeshellarg($teamId));
	}

	/** @When /^the admin tries to map the first visible team with folder mode "([^"]*)"$/ */
	public function theAdminTriesToMapTheFirstVisibleTeamWithFolderMode(string $folderMode): void {
		$this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder-mode=%s',
			escapeshellarg($this->firstVisibleTeamId()),
			escapeshellarg($folderMode),
		));
	}

	/** @When the admin maps the same team again */
	public function theAdminMapsTheSameTeamAgain(): void {
		$this->occ('penpot_sync:add-mapping ' . escapeshellarg($this->firstVisibleTeamId()));
	}

	/** @Then /^there (?:is|are) exactly (\d+) configured team mappings?$/ */
	public function thereAreExactlyNMappings(int $expected): void {
		$actual = count($this->mappingIds());

		if ($actual !== $expected) {
			throw new \RuntimeException(
				"expected {$expected} mapping(s), found {$actual}:\n" . $this->lastOutput,
			);
		}
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		if ($this->lastExit === 0) {
			throw new \RuntimeException("expected the mapping to be refused, but it succeeded:\n" . $this->lastOutput);
		}
	}

	/** @Then /^the refusal explains "([^"]*)"$/ */
	public function theRefusalExplains(string $needle): void {
		if (!str_contains(strtolower($this->lastOutput), strtolower($needle))) {
			throw new \RuntimeException("expected the message to mention '{$needle}', got:\n" . $this->lastOutput);
		}
	}

	/** @When /^the admin maps the first visible team into the folder "([^"]*)"$/ */
	public function theAdminMapsTheFirstVisibleTeamIntoTheFolder(string $folder): void {
		$this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s',
			escapeshellarg($this->firstVisibleTeamId()),
			escapeshellarg($folder),
		));
	}

	/**
	 * Seed the team a scenario names, so the scenario can say which team it means
	 * instead of inheriting whichever one the instance happens to have.
	 *
	 * @Given /^a Penpot team named "([^"]*)" exists$/
	 */
	public function aPenpotTeamNamedExists(string $team): void {
		$this->teamNamed($team);
	}

	/** @When /^the admin maps the team "([^"]*)" into the folder "([^"]*)"$/ */
	public function theAdminMapsTheTeamIntoTheFolder(string $team, string $folder): void {
		$this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s',
			escapeshellarg($this->teamNamed($team)),
			escapeshellarg($folder),
		));
	}

	/**
	 * The team name the mapping kept, by name.
	 *
	 * Distinct from "records the team's own name separately", which asserts only
	 * that the two names DIFFER — a check that cannot run on the same-name row of
	 * an Examples table, and that would pass on any non-empty string. This one
	 * names the team it expects, so it holds on both rows and pins the value.
	 *
	 * @Then /^the mapping records the Penpot team "([^"]*)"$/
	 */
	public function theMappingRecordsThePenpotTeam(string $expected): void {
		$actual = (string)($this->firstMapping()['team_name'] ?? '');

		if ($actual !== $expected) {
			throw new \RuntimeException("expected the mapping to record the team '{$expected}', got '{$actual}'");
		}
	}

	/** @Given /^the first visible team is mapped into the folder "([^"]*)"$/ */
	public function theFirstVisibleTeamIsMappedIntoTheFolder(string $folder): void {
		$this->noPenpotTeamsAreMapped();
		$this->theAdminMapsTheFirstVisibleTeamIntoTheFolder($folder);

		if ($this->lastExit !== 0) {
			throw new \RuntimeException("could not map the team into {$folder}:\n" . $this->lastOutput);
		}
	}

	/** @When /^the admin maps another team into the folder "([^"]*)"$/ */
	public function theAdminMapsAnotherTeamIntoTheFolder(string $folder): void {
		$mapped = $this->mappings();
		$taken = $mapped === [] ? '' : (string)($mapped[0]['team_id'] ?? '');

		foreach ($this->visibleTeamIds() as $id) {
			if ($id !== $taken) {
				$this->occ(sprintf(
					'penpot_sync:add-mapping %s --folder=%s',
					escapeshellarg($id),
					escapeshellarg($folder),
				));

				return;
			}
		}

		throw new \RuntimeException('needs a second visible Penpot team, found only one');
	}

	/** @When /^the admin maps the first visible team shared with the group "([^"]*)"$/ */
	public function theAdminMapsTheFirstVisibleTeamSharedWith(string $groups): void {
		$this->occ(sprintf(
			'penpot_sync:add-mapping %s --groups=%s',
			escapeshellarg($this->firstVisibleTeamId()),
			escapeshellarg($groups),
		));
	}

	/** @Then the mapping's Nextcloud folder is named after the Penpot team */
	public function theFolderIsNamedAfterTheTeam(): void {
		$mapping = $this->firstMapping();
		$teamName = (string)($mapping['team_name'] ?? '');
		$ncFolder = (string)($mapping['nc_folder'] ?? '');

		if ($teamName === '' || $ncFolder !== $teamName) {
			throw new \RuntimeException(
				"expected the folder to default to the team name '{$teamName}', got '{$ncFolder}'",
			);
		}
	}

	/** @Then /^the mapping's Nextcloud folder is "([^"]*)"$/ */
	public function theMappingsFolderIs(string $expected): void {
		$actual = (string)($this->firstMapping()['nc_folder'] ?? '');

		if ($actual !== $expected) {
			throw new \RuntimeException("expected the folder to be '{$expected}', got '{$actual}'");
		}
	}

	/** @Then /^the mapping's Nextcloud folder is still "([^"]*)"$/ */
	public function theMappingsFolderIsStill(string $expected): void {
		$this->theMappingsFolderIs($expected);
	}

	/** @Then /^the mapping's groups are "([^"]*)"$/ */
	public function theMappingsGroupsAre(string $expected): void {
		$actual = $this->firstMapping()['nc_groups'] ?? [];
		$actual = is_array($actual) ? implode(',', $actual) : '';

		if ($actual !== $expected) {
			throw new \RuntimeException("expected the groups to be '{$expected}', got '{$actual}'");
		}
	}

	/** @Then the mapping uses a Team Folder */
	public function theMappingUsesATeamFolder(): void {
		if (($this->firstMapping()['use_team_folder'] ?? null) !== true) {
			throw new \RuntimeException("expected the mapping to use a Team Folder:\n" . $this->lastOutput);
		}
	}

	/** @Then the mapping has no groups */
	public function theMappingHasNoGroups(): void {
		if (($this->firstMapping()['nc_groups'] ?? []) !== []) {
			throw new \RuntimeException("expected no groups:\n" . $this->lastOutput);
		}
	}

	/** @Then the mapping's default mode is "link" */
	public function theMappingsDefaultModeIsLink(): void {
		$mappings = $this->mappings();

		if ($mappings === [] || ($mappings[0]['mode'] ?? null) !== 'link') {
			throw new \RuntimeException("expected a mapping with mode=link, got:\n" . $this->lastOutput);
		}
	}

	/** @Then the mapping's folder mode is "nested" */
	public function theMappingsFolderModeIsNested(): void {
		$mappings = $this->mappings();

		if ($mappings === [] || ($mappings[0]['folder_mode'] ?? null) !== 'nested') {
			throw new \RuntimeException("expected a mapping with folder_mode=nested, got:\n" . $this->lastOutput);
		}
	}

	/** @Then the mapping records the team name from Penpot */
	public function theMappingRecordsTheTeamName(): void {
		$mappings = $this->mappings();
		$name = $mappings[0]['team_name'] ?? '';

		// The name is server-authoritative (§6.13): the app must have read it
		// back from Penpot rather than storing whatever the caller supplied —
		// and `add-mapping` never takes a name at all.
		if (!is_string($name) || $name === '') {
			throw new \RuntimeException("expected the mapping to carry a team name from Penpot, got:\n" . $this->lastOutput);
		}
	}

	/**
	 * Held across steps because `$this->lastOutput` is shared and every later
	 * step clobbers it — the assertion below runs after a `list-mappings` call
	 * that would otherwise leave it holding `[]`. Anything asserted about a
	 * command's output *after* an intervening step has to be captured when it
	 * happens, not read back later.
	 */
	private string $removalOutput = '';

	/** @When the admin removes that mapping */
	public function theAdminRemovesThatMapping(): void {
		$ids = $this->mappingIds();

		if ($ids === []) {
			throw new \RuntimeException('there is no mapping to remove');
		}

		$res = $this->occ('penpot_sync:remove-mapping ' . escapeshellarg($ids[0]));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("remove-mapping failed:\n{$res['output']}");
		}

		$this->removalOutput = $res['output'];
	}

	/** @Then removing it reported that nothing was deleted */
	public function removingReportedNothingDeleted(): void {
		if (!str_contains($this->removalOutput, 'Nothing was deleted')) {
			throw new \RuntimeException(
				"expected the removal to state that nothing was deleted, got:\n" . $this->removalOutput,
			);
		}
	}

	/** @Then the service account can see at least one Penpot team */
	public function theServiceAccountCanSeeATeam(): void {
		$this->firstVisibleTeamId();
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * The first team id `list-teams` reports.
	 *
	 * Throws rather than skipping when none is visible: in CI the token is minted
	 * against a fresh Penpot that always has a Default team, so "no teams" means
	 * the connection or the invite is broken — a real failure, not a reason to
	 * silently pass.
	 */
	private function firstVisibleTeamId(): string {
		$res = $this->occ('penpot_sync:list-teams');

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("list-teams failed:\n{$res['output']}");
		}

		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^([0-9a-f-]{36})\s/i', trim($line), $m) === 1) {
				return $m[1];
			}
		}

		throw new \RuntimeException("no visible Penpot team to map:\n{$res['output']}");
	}

	/**
	 * The single mapping under test.
	 *
	 * Throws rather than returning an empty array: every caller is asserting a
	 * property OF a mapping, so "there isn't one" is a failure, not a value.
	 *
	 * @return array<string, mixed>
	 */
	private function firstMapping(): array {
		$mappings = $this->mappings();

		if ($mappings === []) {
			throw new \RuntimeException("expected a configured mapping, found none:\n" . $this->lastOutput);
		}

		return $mappings[0];
	}

	/** @return list<string> */
	private function visibleTeamIds(): array {
		$res = $this->occ('penpot_sync:list-teams');
		$ids = [];

		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^([0-9a-f-]{36})\s/i', trim($line), $m) === 1) {
				$ids[] = $m[1];
			}
		}

		return $ids;
	}

	/** @return list<array<string, mixed>> */
	private function mappings(): array {
		$res = $this->occ('penpot_sync:list-mappings --json');

		if ($res['exit'] !== 0) {
			return [];
		}

		$decoded = json_decode($res['output'], true);

		return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
	}

	/** @return list<string> */
	private function mappingIds(): array {
		$ids = [];

		foreach ($this->mappings() as $mapping) {
			if (isset($mapping['id']) && is_string($mapping['id'])) {
				$ids[] = $mapping['id'];
			}
		}

		return $ids;
	}
}
