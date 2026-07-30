<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

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
 * The CI Nextcloud has no groupfolders app, so mappings here use
 * `--no-team-folder` — the admin-owned backend {@see \OCA\PenpotSync\Service\StorageService}
 * builds. The groupfolders backend is out of scope for this suite until the CI
 * image ships it.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reuses
 * the occ transport and the team helpers from the other traits.
 */
trait PullSteps {
	/** The Penpot id of the team the current scenario mapped. */
	private string $pulledTeamId = '';

	/** @Given /^the first visible team is mapped as a plain folder "([^"]*)"$/ */
	public function theFirstVisibleTeamIsMappedAsAPlainFolder(string $folder): void {
		$this->noPenpotTeamsAreMapped();
		$this->pulledTeamId = $this->firstVisibleTeamId();

		$res = $this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s --no-team-folder',
			escapeshellarg($this->pulledTeamId),
			escapeshellarg($folder),
		));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not map the team as a plain folder:\n{$res['output']}");
		}
	}

	/** @Given /^a Penpot project named "([^"]*)" exists in that team$/ */
	public function aPenpotProjectExistsInThatTeam(string $name): void {
		$teamId = $this->pulledTeamId !== '' ? $this->pulledTeamId : $this->firstVisibleTeamId();
		$this->penpotRpc('create-project', ['team-id' => $teamId, 'name' => $name]);
	}

	/** @When /^the admin runs a pull$/ */
	public function theAdminRunsAPull(): void {
		$this->occ('penpot_sync:sync pull');
	}

	/** @Then /^the pull succeeds$/ */
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

	/** @Then /^there is no node at "([^"]*)"$/ */
	public function thereIsNoNodeAt(string $path): void {
		$res = $this->occ('penpot_sync:status ' . escapeshellarg($path));
		if ($res['exit'] === 0) {
			throw new \RuntimeException("expected no node at '{$path}', but one exists:\n{$res['output']}");
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
	 * body is not decoded (nothing here needs it).
	 *
	 * SUCCESS IS 200 **OR** 204, because Penpot's own answers disagree: the
	 * creators return a body, `delete-file` returns nothing at all. Asserting 200
	 * alone would have failed a delete that worked perfectly — the same "there is
	 * no convention, only a table" lesson the client's param table records.
	 *
	 * @param array<string, string> $params kebab-cased wire params (saga §6.38)
	 */
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
				// FORCE_OBJECT for the same reason PenpotClient uses it: an empty
				// param map must serialise as `{}`, not `[]` (Penpot 500s on `[]`).
				'body' => json_encode($params, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
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
}
