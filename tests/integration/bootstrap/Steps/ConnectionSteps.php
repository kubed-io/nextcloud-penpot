<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * Steps that exercise the client against a REAL Penpot.
 *
 * WHY THESE ARE INTEGRATION STEPS AND NOT UNIT TESTS. The unit suite covers the
 * param table and the guards — pure logic that must be right before a request is
 * built. It deliberately does NOT mock the transport, because a mock of a
 * protocol we have repeatedly misread would only encode the misreading. Chapter
 * 1 has the cautionary tale (§6.26: a confident conclusion drawn from guessing
 * instead of calling), and the client itself shipped three bugs that only a real
 * response could reveal — two Transit cache-tracking bugs, and `json_encode([])`
 * producing `[]` where Penpot demands `{}`.
 *
 * So the contract with CI is: **the wire format is only ever asserted against a
 * live Penpot.** These steps are what make that true.
 *
 * The token is minted per run by the integration workflow (saga §6.47) — Penpot
 * can mint one headlessly, so this suite needs no repository secret.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}.
 */
trait ConnectionSteps {
	/** @Given the Penpot base URL points at the test instance */
	public function thePenpotBaseUrlPointsAtTheTestInstance(): void {
		$url = getenv('PENPOT_URL');
		if ($url === false || $url === '') {
			throw new \RuntimeException('PENPOT_URL is not set — the Penpot service is not running.');
		}

		$res = $this->occ('penpot_sync:set-url ' . escapeshellarg($url));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("set-url failed:\n{$res['output']}");
		}
	}

	/** @Given the admin has configured the service-account token */
	public function theAdminHasConfiguredTheServiceAccountToken(): void {
		$token = getenv('PENPOT_TOKEN');
		if ($token === false || $token === '') {
			throw new \RuntimeException('PENPOT_TOKEN is not set — the mint step did not run.');
		}

		$res = $this->occ('penpot_sync:set-token ' . escapeshellarg($token));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("set-token failed:\n{$res['output']}");
		}
	}

	/** @Given no service-account token is configured */
	public function noServiceAccountTokenIsConfigured(): void {
		// app:config is the honest way to clear it — there is no unset command,
		// and reaching into the DB would test something other than the app.
		$this->occ('config:app:delete penpot_sync penpot_token');
	}

	/** @When the connection is checked */
	public function theConnectionIsChecked(): void {
		$this->occ('penpot_sync:probe');
	}

	/** @When the connection is checked including files */
	public function theConnectionIsCheckedIncludingFiles(): void {
		$this->occ('penpot_sync:probe --files');
	}

	/**
	 * @Then the connection succeeds
	 *
	 * NOTE ON WHY THIS THROWS RATHER THAN ASSERTS. PHPUnit's assertions build
	 * their failure message through `TextUI\Configuration\Registry`, which only
	 * exists when PHPUnit itself bootstrapped the run. Under Behat it is null, so
	 * a FAILING assertion dies with
	 *
	 *   Type error: Registry::get(): Return value must be of type Configuration,
	 *   null returned
	 *
	 * — which replaces the real diagnostic with a harness error and hides what
	 * actually went wrong. Assertions that PASS are unaffected, which is why this
	 * only shows up on the failure path, i.e. exactly when the message matters.
	 *
	 * Plain exceptions carry the probe's own output straight into Behat's report.
	 */
	public function theConnectionSucceeds(): void {
		if ($this->lastExit !== 0 || !str_contains($this->lastOutput, 'Connected')) {
			throw new \RuntimeException(
				"probe did not report a successful connection (exit {$this->lastExit}):\n{$this->lastOutput}",
			);
		}
	}

	/** @Then the connection fails */
	public function theConnectionFails(): void {
		if ($this->lastExit === 0) {
			throw new \RuntimeException("expected probe to fail, but it succeeded:\n{$this->lastOutput}");
		}
	}

	/**
	 * The whole reason `probe` reports teams rather than "OK" (saga §6.12/§6.18):
	 * Penpot visibility is always membership-scoped, so *which* teams a token can
	 * see is the fact that decides what can be mapped.
	 *
	 * @Then at least one Penpot team is listed
	 */
	public function atLeastOnePenpotTeamIsListed(): void {
		if (preg_match('/Visible teams: \S/', $this->lastOutput) !== 1) {
			throw new \RuntimeException("no teams were listed:\n{$this->lastOutput}");
		}
	}

	/**
	 * A freshly minted account always has a personal team with a Drafts project
	 * (saga §6.9/§6.35), so the project listing is never empty for a real token —
	 * which makes this a genuine assertion that Transit decoding survived a
	 * multi-record response, not just that the call returned 200.
	 *
	 * @Then at least one Penpot project is listed
	 */
	public function atLeastOnePenpotProjectIsListed(): void {
		if (preg_match('/Projects \(([1-9][0-9]*)\)/', $this->lastOutput) !== 1) {
			throw new \RuntimeException("no projects were listed:\n{$this->lastOutput}");
		}
	}

	/** @Then the failure explains that no token is configured */
	public function theFailureExplainsThatNoTokenIsConfigured(): void {
		if (!str_contains(strtolower($this->lastOutput), 'token')) {
			throw new \RuntimeException("the failure did not mention the token:\n{$this->lastOutput}");
		}
	}
}
