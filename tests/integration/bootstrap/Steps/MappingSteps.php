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
	 * Map a named team, naming no folder — the case where the folder name is left
	 * to default to the team's own.
	 *
	 * DOES NOT THROW ON FAILURE, and neither does any other `When` here. Half these
	 * scenarios are asserting a REFUSAL, so the outcome belongs to the `Then` that
	 * follows; a step that threw first would make "the mapping is rejected"
	 * unreachable. `$this->lastExit` and `$this->lastOutput` carry the verdict.
	 *
	 * @When /^the admin maps the Penpot team "([^"]*)"$/
	 */
	public function theAdminMapsThePenpotTeam(string $team): void {
		$this->occ('penpot_sync:add-mapping ' . escapeshellarg($this->teamNamed($team)));
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

	/** @When /^the admin maps the team "([^"]*)" into the folder "([^"]*)"$/ */
	public function theAdminMapsTheTeamIntoTheFolder(string $team, string $folder): void {
		$this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s',
			escapeshellarg($this->teamNamed($team)),
			escapeshellarg($folder),
		));
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
	 * Does not throw on a non-zero exit: half the rows using this step expect a
	 * refusal, and that verdict belongs to the `Then`.
	 *
	 * @When /^the admin maps "([^"]*)" with:$/
	 */
	public function theAdminMapsWith(string $team, TableNode $form): void {
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
			escapeshellarg($this->teamNamed($team)),
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

	/**
	 * Map the team the scenario just named onto a NAMED BACKEND.
	 *
	 * Says "it" because the team is already established — see
	 * {@see aPenpotTeamNamedExists()}. Three short preconditions that each mean one
	 * thing beat one sentence that means three: every clause here is a sentence the
	 * suite can use on its own, and a scenario that wants a different starting
	 * point changes one line instead of needing its own compound step.
	 *
	 * Does not read {@see backendFlags()} — this is the one place the scenario
	 * chooses the backend rather than inheriting the leg's, because the backend is
	 * what it is testing. The admin leg installs groupfolders so both are reachable.
	 *
	 * The folder name comes from the KIND, so a Team Folder and a plain folder can
	 * never collide on one name. They must not: removing a mapping deletes nothing,
	 * so a folder outlives the mapping that made it and a later mapping reusing the
	 * name would inherit a folder of the wrong kind (§C6.32). Rows of the same kind
	 * DO share a folder, which is safe — ensureRoot() is idempotent.
	 *
	 * @Given /^it is mapped to a (Team Folder|plain folder)$/
	 */
	public function itIsMappedToA(string $kind): void {
		if ($this->namedTeamId === '') {
			throw new \RuntimeException('no team has been named yet — say which team is mapped first');
		}

		$res = $this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s %s',
			escapeshellarg($this->namedTeamId),
			escapeshellarg($kind === 'Team Folder' ? 'Groups On A Team Folder' : 'Groups On A Plain Folder'),
			$kind === 'Team Folder' ? '--team-folder' : '',
		));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not map \"{$this->namedTeam}\" onto a {$kind}:\n{$res['output']}");
		}
	}

	/**
	 * The starting sharing, as a precondition rather than an argument.
	 *
	 * Runs the same `set-groups` the `When` below does, and that is deliberate:
	 * this is the state a scenario needs to START in, and there is exactly one
	 * mechanism for reaching it. Making the seed a distinct code path — the flag on
	 * `add-mapping` — would mean the precondition and the action could disagree
	 * about what "shared with these" does, which is precisely the disagreement the
	 * scenario exists to catch.
	 *
	 * It throws where the `When` reports, because a fixture that did not take is
	 * not a result to assert on.
	 *
	 * @Given /^shared with "([^"]*)"$/
	 */
	public function sharedWith(string $groups): void {
		$res = $this->occ(sprintf(
			'penpot_sync:set-groups %s %s',
			escapeshellarg((string)($this->firstMapping()['id'] ?? '')),
			escapeshellarg($groups),
		));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not share the mapped folder with \"{$groups}\":\n{$res['output']}");
		}
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
