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
	/**
	 * A team id that is syntactically valid and that no lookup can ever return —
	 * so it reaches the app's own visibility check rather than being refused as
	 * malformed on the way in.
	 */
	private const UNREACHABLE_TEAM_ID = '11111111-2222-3333-4444-555555555555';

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
	 * The admin accepts the warning, and the submission goes through.
	 *
	 * ## TWO BEATS, AND THE ORDER IS THE INTERACTION
	 *
	 * This follows the `When` rather than sitting inside its table, because that is
	 * the shape of the real gesture: the admin submits, the app answers with a count
	 * and the word "permanently", and only then does the admin accept. Consent
	 * expressed as a form field would arrive before the app had said what it costs.
	 *
	 * So the first submission is EXPECTED to have been refused, and this re-sends
	 * the same form with the acknowledgement attached. Asserting that refusal here
	 * is what stops the scenario passing on an app that purges without asking —
	 * which would satisfy every `Then` below while being the exact behaviour the
	 * confirmation exists to prevent.
	 *
	 * @When /^allows the existing unmapped designs to be purged$/
	 */
	public function allowsTheExistingDesignsToBePurged(): void {
		if ($this->lastExit === 0) {
			throw new \RuntimeException(
				'the mapping was created without asking about the designs already in the '
				. "folder — the acknowledgement is not being required:\n" . $this->lastOutput,
			);
		}

		$flags = [];
		foreach ($this->submittedForm as $field => $value) {
			$value = trim($value);
			if ($value !== '') {
				$flags[] = $this->flagFor($field, $value);
			}
		}
		$flags[] = '--purge-designs';

		$this->occ(sprintf(
			'penpot_sync:add-mapping %s %s',
			escapeshellarg($this->theNamedTeam()),
			implode(' ', $flags),
		));
	}

	/**
	 * The attempt was refused, and said why — ONE sentence, because it is one claim.
	 *
	 * It was two (`the mapping is rejected` / `the refusal explains "…"`), which
	 * read as two facts about a refusal when a refusal that does not say why is not
	 * a thing this app ships. Splitting them also invited a third line to say
	 * nothing was created, and the one that used to be there counted the mappings —
	 * the arrange's fact, not the behaviour's.
	 *
	 * ## THE THIRD CHECK IS HERE, AND IT IS NOT SPECIFICATION
	 *
	 * "Rejected" already means "nothing was created" — a spec does not have to say
	 * it, and `create.feature` no longer does. But a rejection that stored the
	 * mapping anyway would satisfy both halves above, so the guarantee is asserted
	 * where it belongs: in the step, against a snapshot taken before the attempt.
	 *
	 * A SNAPSHOT AND NOT A COUNT, because `A mapping may not reuse a team or a
	 * folder` is refused with a mapping ALREADY configured — "no mapping for this
	 * team" would be false there, and "exactly one" would be counting the arrange.
	 * What is actually claimed is that the store did not move, which is what this
	 * compares.
	 *
	 * @Then /^the mapping is rejected, explaining "([^"]*)"$/
	 */
	public function theMappingIsRejectedExplaining(string $needle): void {
		// THE OUTPUT IS READ BEFORE ANYTHING ELSE RUNS. `mappingIds()` shells out to
		// `list-mappings`, which replaces `$this->lastOutput` — so the refusal has to
		// be examined first or the message is gone by the time it is asserted.
		$output = $this->lastOutput;

		if ($this->lastExit === 0) {
			throw new \RuntimeException("expected the mapping to be refused, but it succeeded:\n" . $output);
		}
		if (!str_contains(strtolower($output), strtolower($needle))) {
			throw new \RuntimeException("expected the message to mention '{$needle}', got:\n" . $output);
		}

		$after = $this->mappingIds();
		if ($after !== $this->mappingsBeforeAttempt) {
			throw new \RuntimeException(sprintf(
				'the mapping was refused and the store changed anyway: %d mapping(s) before, %d after',
				count($this->mappingsBeforeAttempt),
				count($after),
			));
		}
	}

	/**
	 * The mapping store as it stood before the last attempt to add one.
	 *
	 * @var list<string>
	 */
	private array $mappingsBeforeAttempt = [];

	/**
	 * A Penpot team this app deliberately does NOT map.
	 *
	 * The far side of every "leaves this mapping" scenario: somewhere real for a
	 * design to be moved TO in Penpot that Nextcloud has no folder for. Without
	 * one, "moved out of the mapping" and "deleted" are indistinguishable from
	 * this side, which is the confusion those scenarios exist to settle.
	 *
	 * Created but never mapped, and pointedly NOT recorded as the team the
	 * scenario is talking about — `aPenpotTeamNamedExists()` sets that cursor, and
	 * a Background sentence about scenery must not steal it from the mapping the
	 * scenario is actually about.
	 *
	 * @Given /^a Penpot team "([^"]*)" that this app does not map$/
	 */
	public function aPenpotTeamThatThisAppDoesNotMap(string $team): void {
		$this->teamNamed($team);
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
	 * CASE-TOLERANT ON THE PRODUCT NAME. The spec is the requirement and the code
	 * follows it, so a scenario writing "a penpot team" is not a typo to correct in
	 * the feature file — it is a sentence this step has to answer to. One regex
	 * rather than a second annotation, so there is still exactly one definition to
	 * find and no near-duplicate pattern for the checker to trip over.
	 *
	 * @Given /^a [Pp]enpot team named "([^"]*)" exists$/
	 */
	public function aPenpotTeamNamedExists(string $team): void {
		$this->namedTeamId = $this->teamNamed($team);
		$this->namedTeam = $team;
	}

	/** Whether this scenario has already reset the mapping store. */
	private bool $mappingsDeclared = false;

	/**
	 * EVERY SCENARIO STARTS WITH NOTHING MAPPED, and the harness is where that
	 * belongs rather than in a `Given`.
	 *
	 * `mapping/create.feature` used to open with `Given no Penpot teams are
	 * mapped`, which is false as specification: an admin can map a team with ten
	 * mappings already configured, and a scenario about creating one that insists
	 * nothing is mapped reads as "you may only ever make the first". It was only
	 * ever there for isolation — without it the second row of an Outline re-maps
	 * the same team and is correctly rejected as already mapped.
	 *
	 * Isolation is the harness's job, so it moved here. Nothing about the app
	 * changed: this is the same clear the two mapping-declaring arranges already
	 * performed lazily, now done once, up front, for every scenario — which is why
	 * the flag below is set rather than cleared, so those two do not repeat it.
	 *
	 * @BeforeScenario
	 */
	public function armMappingReset(): void {
		$this->noPenpotTeamsAreMapped();
		$this->mappingsDeclared = true;
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
	 * The table takes the SAME fields as the creation form ({@see theAdminSubmitsThisMapping()}),
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

		// RESET ONCE PER SCENARIO, on the first mapping declared. Resetting on every
		// call meant a Background could only ever describe ONE mapping, and silently:
		// the second table wiped the first. The sibling n8n and grafana apps were both
		// fixed the same way.
		if (!$this->mappingsDeclared) {
			$this->noPenpotTeamsAreMapped();
			$this->mappingsDeclared = true;
		}
		$this->aPenpotTeamNamedExists($team);
		// THE TEAM THE SCENARIO IS NOW TALKING ABOUT. The prose form of this step sets
		// it too; without it every "…in that team" arrange fell back to whichever team
		// Penpot listed first, and built its projects somewhere the mapping did not
		// point — which is why every path under the mapped folder 404'd.
		$this->pulledTeamId = $this->namedTeamId;

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
	 * @Given /^the Nextcloud groups "([^"]*)" exists?$/
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
	 * NO LONGER "maps it with", AND NO LONGER "it". The table names the team now,
	 * so the sentence has a subject of its own and does not lean on a cursor set
	 * three lines earlier — which is what "it" was, and what made a reader carry
	 * state to know which team was being mapped.
	 *
	 * SUBMITS, NOT CREATES. Three of the five scenarios using this sentence end in
	 * a refusal, so a `When` that says "creates" states an outcome the `Then` has
	 * not decided yet — and reads as a contradiction on every row where the mapping
	 * is rejected. What the admin does is submit; what happens next is the claim.
	 *
	 * @When /^the admin submits this mapping:$/
	 */
	public function theAdminSubmitsThisMapping(TableNode $form): void {
		$this->submittedForm = $form->getRowsHash();

		// THE TEAM IS THE ARGUMENT, NOT A FLAG, which is why it comes out of the
		// table before the loop rather than earning a `flagFor()` case:
		// `add-mapping` takes the team positionally and every other field as an
		// option.
		//
		// OPTIONAL, because "it" still works. Most scenarios here name a team in a
		// `Given` and then say "it", which reads well while only one team is on
		// stage. Naming it in the table is for the ones where a reader should not
		// have to carry a cursor in their head to know which team is being mapped.
		$team = trim($this->submittedForm['team'] ?? '');
		unset($this->submittedForm['team']);

		$flags = [];
		foreach ($this->submittedForm as $field => $value) {
			$value = trim($value);
			if ($value !== '') {
				$flags[] = $this->flagFor($field, $value);
			}
		}

		// BEFORE the attempt, so {@see theMappingIsRejectedExplaining()} can prove the
		// store did not move. Taken here rather than in the assertion because by then
		// the attempt has already happened.
		$this->mappingsBeforeAttempt = $this->mappingIds();

		$this->occ(sprintf(
			'penpot_sync:add-mapping %s %s',
			escapeshellarg($team === '' ? $this->theNamedTeam() : $this->idOfTeamNamed($team)),
			implode(' ', $flags),
		));
	}

	/**
	 * A team the app cannot reach, WITHOUT SAYING WHY IT CANNOT.
	 *
	 * `get-teams` is membership-scoped (§6.12), so "this team does not exist" and
	 * "the service account was never invited to it" are ONE case on this side of
	 * the wire: the lookup returns nothing either way. There is no behaviour that
	 * separates them, so the spec does not either — and this step reaches the state
	 * by the cheap route rather than standing up a second Penpot account to own a
	 * team the first cannot see.
	 *
	 * IT NAMES THE TEAM SO THE SUBMISSION CAN, and that is load-bearing rather than
	 * decorative. {@see theAdminSubmitsThisMapping()} resolves a `team` cell through
	 * {@see idOfTeamNamed()}, which prefers this cursor — so naming it here is what
	 * stops the submission going to a lookup for a team that is supposed to be
	 * missing.
	 *
	 * @Given /^the penpot team "([^"]*)" does not exist$/
	 */
	public function thePenpotTeamDoesNotExist(string $team): void {
		$this->namedTeam = $team;
		// Syntactically a uuid, so it reaches the lookup rather than being refused
		// as malformed — and no lookup could ever return it.
		$this->namedTeamId = self::UNREACHABLE_TEAM_ID;
	}

	/**
	 * The id a submission should carry for a team of this name.
	 *
	 * ## IT LOOKS UP; IT NEVER CREATES
	 *
	 * {@see teamNamed()} is find-or-CREATE, and calling it from a `When` would make
	 * the submission a fixture: `A mapping using invalid combinations is rejected`
	 * has a row that maps `Outsiders`, a team whose whole point is that it is not
	 * there, and find-or-create would conjure it and then map it successfully. So a
	 * name nothing answers to becomes an id nothing answers to — which is the state
	 * that row describes, reached without the `When` building anything.
	 *
	 * Seeding belongs in a `Given`, and the rows that need a real team have one.
	 *
	 * THE CURSOR COMES FIRST, so a team a `Given` just named is used without a
	 * second round trip — and so a scenario that has deliberately broken the app's
	 * view of Penpot (no token, say) still submits the id it meant rather than one
	 * a now-failing lookup could not return.
	 */
	private function idOfTeamNamed(string $team): string {
		if ($team === $this->namedTeam && $this->namedTeamId !== '') {
			return $this->namedTeamId;
		}

		// Syntactically a uuid so it reaches the app's own lookup rather than being
		// refused as malformed, and one no lookup could ever return.
		return $this->visibleTeamIdNamed($team) ?? self::UNREACHABLE_TEAM_ID;
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

		// EVERY FIELD WITH AN EXPECTATION, from either side. A scenario that declared
		// defaults is asserting the untouched fields too, which is the whole point of
		// that table; one that did not is still asserting what it submitted. Only a
		// scenario that declared neither would check nothing, and that is the case
		// worth refusing.
		$fields = array_keys($this->formDefaults + $this->submittedForm);
		if ($fields === []) {
			throw new \RuntimeException('nothing was declared or submitted; the assertion would check nothing');
		}

		$mapping = $this->firstMapping();

		foreach ($fields as $field) {
			$default = $this->formDefaults[$field] ?? '';
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

	/**
	 * Remove one NAMED mapping, which is what a scenario with more than one needs.
	 *
	 * "that mapping" above resolves to `mappingIds()[0]` — fine when a scenario
	 * declares exactly one, and quietly wrong the moment it declares two. A
	 * teardown scenario is precisely where that matters: the claim is that THIS
	 * mapping's mirrors were dealt with, and picking the wrong one would prove it
	 * about somebody else's.
	 *
	 * @When /^the admin removes the "([^"]*)" mapping$/
	 */
	public function theAdminRemovesTheNamedMapping(string $team): void {
		$id = $this->mappingIdForTeam($team);
		if ($id === null) {
			throw new \RuntimeException("there is no mapping for the team '{$team}' to remove");
		}

		$res = $this->occ('penpot_sync:remove-mapping ' . escapeshellarg($id));

		if ($res['exit'] !== 0) {
			throw new \RuntimeException("remove-mapping failed:\n{$res['output']}");
		}

		$this->removalOutput = $res['output'];
	}

	/**
	 * THAT mapping is gone — and says nothing about how many others there are.
	 *
	 * It replaced `there are exactly 0 configured team mappings`, which only held
	 * because the arrange declared exactly one. Removing one of ten mappings must
	 * not read as an assertion that the other nine went — and the same objection
	 * retired that sentence everywhere else too: a COUNT is the arrange's fact, not
	 * the behaviour's, so a create that was refused proves nothing by the total
	 * being unchanged. The step it named is gone with the last scenario using it.
	 *
	 * @Then /^the "([^"]*)" mapping is no longer configured$/
	 */
	public function theNamedMappingIsNoLongerConfigured(string $team): void {
		if ($this->mappingIdForTeam($team) !== null) {
			throw new \RuntimeException("the '{$team}' mapping is still configured after being removed");
		}
	}

	/**
	 * Nothing under this path is a design any more — asked of the whole subtree.
	 *
	 * A SWEEP, NOT A LIST, and that is stronger than naming the two files the
	 * arrange made: it also catches a design the pull put there that the scenario
	 * never mentioned. The teardown's claim is that a link mapping leaves NO
	 * pointers behind, and only a sweep says "no".
	 *
	 * @Then /^no "\.penpot" designs exist under "([^"]*)" in Nextcloud$/
	 */
	public function noDesignsExistUnder(string $path): void {
		$found = $this->designsBelow(trim($path, '/'), 0);
		if ($found !== []) {
			throw new \RuntimeException(
				"'{$path}' still holds " . implode(', ', $found)
				. ' — removing the mapping was supposed to take every pointer with it.',
			);
		}
	}

	/**
	 * Every `.penpot` at or below $path, as full paths so a failure says WHERE.
	 *
	 * @return list<string>
	 */
	private function designsBelow(string $path, int $depth): array {
		if ($depth > 20) {
			return [];
		}

		try {
			$children = $this->davChildren($path);
		} catch (\Throwable) {
			// Not a folder, or gone. Either way it holds no designs.
			return [];
		}

		$found = [];
		foreach ($children as $child) {
			if (str_ends_with($child, '.penpot')) {
				$found[] = $child;
				continue;
			}
			foreach ($this->designsBelow($child, $depth + 1) as $nested) {
				$found[] = $nested;
			}
		}

		return $found;
	}

	/** The id of the mapping for a team NAME, or null when none is configured. */
	private function mappingIdForTeam(string $team): ?string {
		$res = $this->occ('penpot_sync:list-mappings');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("list-mappings failed:\n{$res['output']}");
		}

		// `<id>  <team>  <folder>  <mode>  <groups>` — the id is the first column and
		// the team name the second. Anchored on the id's shape so a team called
		// "TEAM" in a header row cannot match.
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^([0-9a-f]{8,})\s+' . preg_quote($team, '/') . '\s/', trim($line), $m) === 1) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * @Then removing it reported that nothing was deleted in Penpot
	 *
	 * NARROWED WHEN THE TEARDOWN LANDED. It used to look for "Nothing was deleted",
	 * which was the whole truth then and is half of it now: removing a mapping does
	 * remove the pointers it left in Nextcloud. Penpot is the half that stays true,
	 * and the half worth a step.
	 */
	public function removingReportedNothingDeleted(): void {
		if (!str_contains($this->removalOutput, 'Nothing was deleted in Penpot')) {
			throw new \RuntimeException(
				"expected the removal to state that nothing was deleted in Penpot, got:\n" . $this->removalOutput,
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
