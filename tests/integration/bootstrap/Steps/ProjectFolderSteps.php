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
 * ## THE TAG GOES ON OVER occ, NOT DAV — AND THAT IS A REAL CHANNEL
 *
 * Assigning a system tag over WebDAV means a PROPPATCH against
 * `systemtags-relations`, which needs the tag's numeric id and a second round
 * trip to find it. `occ tag:files:add` fires the very same `TagAssignedEvent`
 * through the very same `ISystemTagObjectMapper::assignTags()` — verified in the
 * live 33.0.4 tree — so it exercises the listener identically at a fraction of
 * the setup.
 *
 * It also covers something the browser path does not: `occ` runs with **no user
 * session**, which is exactly the case the listener's sync-actor fallback exists
 * for. A listener written to require a session would pass every unit test and do
 * nothing here, which is the failure mode this suite keeps catching.
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
	 * @Given /^a folder at "([^"]*)" that is not a project$/
	 * @Given /^a folder at "([^"]*)" that is not mapped$/
	 * @Given /^a folder at "([^"]*)" in the user's home that is not a project$/
	 * @When /^I create a folder at "([^"]*)"$/
	 * @When /^I create the folder "([^"]*)"$/
	 */
	public function iCreateAFolderAt(string $path): void {
		$this->davMkcol($path);
	}

	/**
	 * @When /^I assign the "penpot" tag to "([^"]*)"$/
	 * @Given /^the folder "([^"]*)" has been tagged "penpot"$/
	 */
	public function iAssignThePenpotTagTo(string $path): void {
		$res = $this->occ(sprintf('tag:files:add %s penpot public', escapeshellarg($this->rootPath($path))));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not tag '{$path}':\n{$res['output']}");
		}
	}

	/** @When /^I remove the "penpot" tag from "([^"]*)"$/ */
	public function iRemoveThePenpotTagFrom(string $path): void {
		// `tag:files:delete` takes the access level too, exactly like its `add`
		// twin — it is how the tag is LOOKED UP, not just how it would be made.
		$res = $this->occ(sprintf('tag:files:delete %s penpot public', escapeshellarg($this->rootPath($path))));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not untag '{$path}':\n{$res['output']}");
		}
	}

	// ── what the APP believes ───────────────────────────────────────────────

	/**
	 * The negative the permissive half needs: an ordinary folder inside a mapped
	 * folder must carry NO project id, or every subfolder a user makes has
	 * quietly become a Penpot project.
	 *
	 * @Then /^the folder "([^"]*)" carries no Penpot project id$/
	 */
	public function theFolderCarriesNoProjectId(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: folder', $path);
		if (preg_match('/penpot_project_id: \S/', $out) === 1) {
			throw new \RuntimeException("expected '{$path}' to carry NO Penpot project id, got:\n{$out}");
		}
	}

	/** @Then /^the folder "([^"]*)" carries the "penpot" tag$/ */
	public function theFolderCarriesThePenpotTag(string $path): void {
		$tags = $this->davSystemTags($path);
		if (!in_array('penpot', $tags, true)) {
			throw new \RuntimeException(sprintf(
				"expected '%s' to carry the 'penpot' tag; it carries: %s",
				$path,
				implode(', ', $tags) ?: '(none)',
			));
		}
	}

	/** @Then /^the folder "([^"]*)" does not carry the "penpot" tag$/ */
	public function theFolderDoesNotCarryThePenpotTag(string $path): void {
		if (in_array('penpot', $this->davSystemTags($path), true)) {
			throw new \RuntimeException("expected '{$path}' NOT to carry the 'penpot' tag, but it does");
		}
	}

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

	// ── helpers ─────────────────────────────────────────────────────────────

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
	 * Where a design ended up, named rather than cursored.
	 *
	 * The `Drafts` row is the point of the scenario using this: a design whose
	 * folder cannot be a project still has to land somewhere, and Drafts is where.
	 *
	 * @Then /^the design "([^"]*)" is in the "([^"]*)" Penpot project$/
	 */
	public function theDesignIsInThePenpotProject(string $design, string $project): void {
		$this->until(
			fn (): bool => in_array($design, $this->penpotFileNamesIn($project), true),
			fn (): string => sprintf(
				"expected the design '%s' in the Penpot project '%s'; it holds: %s",
				$design,
				$project,
				implode(', ', $this->penpotFileNamesIn($project)) ?: '(nothing)',
			),
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
	 * The Penpot file ids in a named project of a named team.
	 *
	 * Team-scoped, because two teams may hold a project with the same name and the
	 * Backgrounds arrange exactly that on purpose.
	 *
	 * @return list<string>
	 */
	private function penpotFileIdsIn(string $project, string $team): array {
		$projectId = $this->penpotProjectIdInTeam($project, $team);
		if ($projectId === null) {
			return [];
		}

		$ids = [];
		foreach ($this->penpotRpcRead('get-project-files', ['project-id' => $projectId]) as $file) {
			if (isset($file['id']) && is_string($file['id'])) {
				$ids[] = $file['id'];
			}
		}

		return $ids;
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
