<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

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

	/**
	 * Map by RAW ID, for the ids that resolve to nothing.
	 *
	 * The one step here that does not take a NAME, and deliberately so: it exists
	 * to hand `add-mapping` something no lookup could have produced. Naming it
	 * "team id" rather than "Penpot team" keeps it from reading like the named
	 * steps above, which seed the team they name.
	 *
	 * @When /^the admin tries to map the team id "([^"]*)"$/
	 */
	public function theAdminTriesToMapTheTeamId(string $teamId): void {
		$this->occ('penpot_sync:add-mapping ' . escapeshellarg($teamId));
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

	/**
	 * The team a scenario names, whether or not it was already there.
	 *
	 * Seeding it here is what lets a scenario say which team it means instead of
	 * inheriting whichever one the instance happens to have. It also REMEMBERS the
	 * team, so the steps that follow can say "it" rather than repeating the name
	 * — which is the whole reason this is a precondition of its own rather than
	 * the first clause of a longer sentence.
	 *
	 * @Given /^a Penpot team named "([^"]*)" exists$/
	 */
	public function aPenpotTeamNamedExists(string $team): void {
		$this->namedTeamId = $this->teamNamed($team);
		$this->namedTeam = $team;
	}

	/** The team the scenario last named, and its Penpot id. */
	private string $namedTeam = '';
	private string $namedTeamId = '';

	/**
	 * The team the scenario is talking about when it says "it".
	 *
	 * Every sentence that names a team sets this, INCLUDING the two that map one
	 * in the same breath, so "it" always means the team most recently named. That
	 * is what lets one `When` serve both refusals: map the same team again (no
	 * second team named) or map a different one into a taken folder (a second team
	 * named first).
	 */
	private function theNamedTeam(): string {
		if ($this->namedTeamId === '') {
			throw new \RuntimeException(
				'this step says "it", but no team has been named yet — name one first',
			);
		}

		return $this->namedTeamId;
	}

	/**
	 * A whole mapping, stated as the state it is rather than the steps that reach
	 * it — the one pre-state sentence every scenario in admin-mapping.feature
	 * starts from.
	 *
	 * ## ONE VOCABULARY FOR THE PRE-STATE AND THE ACTION
	 *
	 * The table takes the SAME fields as the creation form ({@see theAdminMapsWith()}),
	 * and both run them through {@see flagFor()}, so there is one definition of what
	 * "storage" or "groups" means and a scenario reads the same whether a value is
	 * being set up or submitted. An omitted or blank row is the app's own default,
	 * exactly as a blank cell is in the form.
	 *
	 * It replaced a family of near-identical sentences — "…is mapped to the folder
	 * X", "…is mapped to a Team Folder", "…shared with Y" — each of which said a
	 * DIFFERENT SUBSET of the same fact, so a scenario needing two of them said the
	 * mapping twice and a scenario needing a third had to grow a new step. A table
	 * has no subsets.
	 *
	 * NAMES THE TEAM, so everything after can say "it". Naming another team
	 * afterwards re-points "it", which is how a scenario reaches a second team
	 * without a second sentence for mapping one.
	 *
	 * @Given /^a mapping with the following values:$/
	 */
	public function aMappingWithTheFollowingValues(TableNode $values): void {
		$fields = $values->getRowsHash();
		$team = trim($fields['team'] ?? '');
		unset($fields['team']);

		if ($team === '') {
			throw new \RuntimeException('a mapping needs a team — add a "team" row to the table');
		}

		$this->noPenpotTeamsAreMapped();
		$this->aPenpotTeamNamedExists($team);

		$flags = [];
		foreach ($fields as $field => $value) {
			$value = trim($value);
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
			throw new \RuntimeException("could not map \"{$team}\":\n{$res['output']}");
		}
	}

	/**
	 * The Nextcloud groups a scenario is about to share a folder with.
	 *
	 * WITHOUT THIS THE GROUP SCENARIOS PROVED NOTHING, and nothing could tell.
	 * Only `admin` exists on a fresh instance — `design` and `sales` were never
	 * created, so no share or assignment was ever made for them. The suite passed
	 * regardless, because it read the groups back out of the app's own stored copy,
	 * which recorded what we INTENDED. Sourcing them from the folder (§C6.35)
	 * turned four green scenarios red on the first run. That is the argument for
	 * reading through rather than remembering, made by the suite itself.
	 *
	 * Idempotent: a leg runs many scenarios against one Nextcloud, so this
	 * find-or-creates exactly as {@see teamNamed()} does for Penpot teams.
	 *
	 * @Given /^the Nextcloud groups "([^"]*)" exist$/
	 */
	public function theNextcloudGroupsExist(string $groups): void {
		foreach (explode(',', $groups) as $gid) {
			$gid = trim($gid);
			if ($gid === '') {
				continue;
			}

			// `group:add` exits non-zero when the group is already there, which is
			// success for our purposes. So the exit code is ignored and existence is
			// confirmed below instead — that check is the one that matters, and it
			// covers both paths.
			$this->occ('group:add ' . escapeshellarg($gid));

			$res = $this->occ('group:list --output=json');
			if ($res['exit'] !== 0 || !str_contains($res['output'], '"' . $gid . '"')) {
				throw new \RuntimeException("could not create the Nextcloud group \"{$gid}\":\n{$res['output']}");
			}
		}
	}

	/**
	 * The defaults the form applies to whatever the admin left alone.
	 *
	 * DECLARED IN THE SPEC, NOT IN HERE. Five scenarios used to assert one default
	 * each, and one of them was wrong for as long as it took someone to notice
	 * (§C6.31). Written down as a table they are read as a set, and a change to one
	 * is a one-word diff in the feature file instead of a new scenario title.
	 *
	 * @var array<string, string>
	 */
	private array $formDefaults = [];

	/** What the admin actually typed. @var array<string, string> */
	private array $submittedForm = [];

	/** @Given an unset field on the mapping form defaults to: */
	public function anUnsetFieldOnTheMappingFormDefaultsTo(TableNode $defaults): void {
		$this->formDefaults = $defaults->getRowsHash();
	}

	/**
	 * Fill in the mapping form and submit it.
	 *
	 * A BLANK CELL IS AN UNTOUCHED FIELD, not an empty value — it produces no flag
	 * at all, which is the only way a row can exercise a DEFAULT. Behat substitutes
	 * Examples placeholders inside a step's table argument exactly as it does in the
	 * step text, so one table serves every row.
	 *
	 * THE ONLY WAY A MAPPING IS CREATED IN THIS FILE. Saving the form, being
	 * refused because the folder is taken, and being refused because the team is
	 * already mapped are ONE action against three pre-states — so they are one
	 * `When`, and the scenarios differ in their `Given` and their `Then`. An
	 * earlier draft had a second sentence, "the admin maps it into the folder X",
	 * which was this step with one field and no table.
	 *
	 * Does not throw on a non-zero exit: two of the three scenarios using it expect
	 * a refusal, and that verdict belongs to the `Then`.
	 *
	 * @When /^the admin maps it with:$/
	 */
	public function theAdminMapsWith(TableNode $form): void {
		$this->submittedForm = $form->getRowsHash();

		$flags = [];
		foreach ($this->submittedForm as $field => $value) {
			$value = trim($value);
			if ($value !== '') {
				$flags[] = $this->flagFor($field, $value);
			}
		}

		$this->occ(sprintf(
			'penpot_sync:add-mapping %s %s',
			escapeshellarg($this->theNamedTeam()),
			implode(' ', $flags),
		));
	}

	/**
	 * EVERY FIELD, EVERY ROW — including the ones this row did not touch.
	 *
	 * A row that sets the mode is also proving it did not disturb the folder. The
	 * drafts this replaced asserted only the field under test, so nothing in the
	 * suite would have caught one option quietly overwriting another.
	 *
	 * @Then the mapping matches the form, unset fields at their defaults
	 */
	public function theMappingMatchesTheForm(): void {
		// Exit code FIRST: reading the mapping runs `list-mappings`, which replaces
		// the output a refusal would have been explained in.
		if ($this->lastExit !== 0) {
			throw new \RuntimeException("expected the mapping to be created, it was refused:\n" . $this->lastOutput);
		}

		if ($this->formDefaults === []) {
			throw new \RuntimeException('no form defaults were declared; the assertion would check nothing');
		}

		$mapping = $this->firstMapping();

		foreach ($this->formDefaults as $field => $default) {
			$typed = trim($this->submittedForm[$field] ?? '');
			$expected = $typed === '' ? trim($default) : $typed;
			// Groups come back from the FOLDER in whatever order it stores them
			// (§C6.35), so both sides are canonicalised. Every other field is a
			// single value and compares as typed.
			if ($field === 'groups') {
				$expected = self::canonicalGroups($expected);
			}
			$actual = $this->settingOf($mapping, $field);

			if ($actual !== $expected) {
				throw new \RuntimeException(sprintf(
					"expected the mapping's %s to be '%s'%s, got '%s'",
					$field,
					$expected,
					$typed === '' ? ' (the default, the field was left unset)' : '',
					$actual,
				));
			}
		}
	}

	/** The CLI flag one filled-in field comes down to. */
	private function flagFor(string $field, string $value): string {
		return match ($field) {
			'folder' => '--folder=' . escapeshellarg($value),
			'mode' => '--mode=' . escapeshellarg($value),
			'groups' => '--groups=' . escapeshellarg($value),
			// The only storage worth a flag is the one you opt into; the plain
			// shared folder IS the absence of it (§C6.31).
			'storage' => $value === 'team folder' ? '--team-folder' : '',
			default => throw new \RuntimeException("the mapping form has no field called \"{$field}\""),
		};
	}

	/**
	 * One saved setting, under the name the FORM calls it.
	 *
	 * @param array<string, mixed> $mapping
	 */
	private function settingOf(array $mapping, string $field): string {
		$groups = $mapping['nc_groups'] ?? [];

		return match ($field) {
			'folder' => (string)($mapping['nc_folder'] ?? ''),
			'mode' => (string)($mapping['mode'] ?? ''),
			'groups' => self::canonicalGroups($groups),
			'storage' => ($mapping['use_team_folder'] ?? null) === true ? 'team folder' : 'plain shared folder',
			default => throw new \RuntimeException("the mapping form has no field called \"{$field}\""),
		};
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

	/** @When /^the admin changes that mapping's groups to "([^"]*)"$/ */
	public function theAdminChangesThatMappingsGroupsTo(string $groups): void {
		$res = $this->occ(sprintf(
			'penpot_sync:set-groups %s %s',
			escapeshellarg($this->firstMapping()['id'] ?? ''),
			escapeshellarg($groups),
		));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not change the groups:\n{$res['output']}");
		}
	}

	/** @Then /^the mapping's groups are "([^"]*)"$/ */
	public function theMappingsGroupsAre(string $expected): void {
		$actual = self::canonicalGroups($this->firstMapping()['nc_groups'] ?? []);
		$want = self::canonicalGroups($expected);

		if ($actual !== $want) {
			throw new \RuntimeException("expected the groups to be '{$want}', got '{$actual}'");
		}
	}

	/**
	 * A comparable rendering of a group list: sorted, comma-joined.
	 *
	 * SORTED, BECAUSE GROUPS ARE A SET. Since §C6.35 these are read back out of the
	 * folder — a groupfolders assignment table or the share table — and neither
	 * query orders its rows. What comes back is insertion order if the database
	 * feels like it, which would make an assertion pass or fail on the order the
	 * scenario happened to list its groups in. That is not a fact about the app.
	 *
	 * @param mixed $groups a list from JSON, or a comma-separated string
	 */
	private static function canonicalGroups(mixed $groups): string {
		if (is_string($groups)) {
			$groups = $groups === '' ? [] : explode(',', $groups);
		}
		if (!is_array($groups)) {
			return '';
		}

		$out = array_values(array_filter(array_map(
			static fn (mixed $g): string => trim((string)$g),
			$groups,
		), static fn (string $g): bool => $g !== ''));
		sort($out);

		return implode(',', $out);
	}

	/** @Then the mapping's default mode is "link" */
	public function theMappingsDefaultModeIsLink(): void {
		$mappings = $this->mappings();

		if ($mappings === [] || ($mappings[0]['mode'] ?? null) !== 'link') {
			throw new \RuntimeException("expected a mapping with mode=link, got:\n" . $this->lastOutput);
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
