<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
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
	}

	/** @Then removing it reported that nothing was deleted */
	public function removingReportedNothingDeleted(): void {
		if (!str_contains($this->lastOutput, 'Nothing was deleted')) {
			throw new \RuntimeException(
				"expected the removal to state that nothing was deleted, got:\n" . $this->lastOutput,
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
