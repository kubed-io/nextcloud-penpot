<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

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

	// ── the Test connection button's occ twin ───────────────────────────────
	// Deliberately a DIFFERENT command from `probe` above: probe is the deep
	// diagnostic (teams, projects, optionally files), this is the operator's
	// one-line verdict. Both exist, and both must agree about what failed.

	/**
	 * A blank slate — no URL, no token.
	 *
	 * NEEDED BECAUSE A ROW MAY LEAVE A FIELD UNSET, and an unset field must mean
	 * unset rather than "whatever the previous scenario stored". The bad-URL row
	 * is the case that forces it: `set-url` REFUSES a URL it cannot build
	 * requests from, so nothing is written, and the health check has to fail on a
	 * missing URL rather than on one left behind by the row above.
	 *
	 * @Given /^nothing is configured yet$/
	 */
	public function nothingIsConfiguredYet(): void {
		$this->occ('config:app:delete penpot_sync penpot_url');
		$this->occ('config:app:delete penpot_sync penpot_token');
	}

	/**
	 * THE CONNECTION IS ONE FACT, so it is one sentence and a table.
	 *
	 * The URL, the credential and the schedule are all inputs to "the app is
	 * connected" — they used to be three cards, and before that a scenario each,
	 * which made configuring the app look like three behaviours instead of one.
	 * The schedule rows especially: an interval is a setting, not something a
	 * person performs.
	 *
	 * A cell names what KIND of value it is rather than the value itself, because
	 * the real URL and the real token come from the environment the mint step
	 * built — a scenario cannot know them, and pinning them would tie the spec to
	 * one CI fixture.
	 *
	 * @When /^the admin fills in the connection details:$/
	 */
	public function theAdminFillsInTheConnectionDetails(TableNode $table): void {
		foreach ($table->getRowsHash() as $field => $value) {
			switch ($field) {
				case 'url':
					// NOT asserted to succeed. A URL the app cannot build requests
					// from is refused at SET time (see the outline above), so this
					// row leaves no URL stored — which is exactly the state whose
					// health check has to name the url field.
					$this->occ('penpot_sync:set-url ' . escapeshellarg($this->urlFor($value)));
					break;
				case 'token':
					if ($value === '') {
						$this->noServiceAccountTokenIsConfigured();
						break;
					}
					$this->occ('penpot_sync:set-token ' . escapeshellarg($this->tokenFor($value)));
					break;
				case 'enable sync':
					$this->occ('config:app:set penpot_sync schedule_enabled --value=' . ($value === 'true' ? '1' : '0'));
					break;
				case 'schedule':
					$this->occ('config:app:set penpot_sync schedule_interval --value=' . escapeshellarg($value));
					break;
				default:
					throw new \RuntimeException("no connection field called '{$field}'");
			}
		}
	}

	/** A URL cell, resolved against what the running Penpot actually is. */
	private function urlFor(string $value): string {
		if ($value !== 'the test instance') {
			return $value;
		}
		$url = getenv('PENPOT_URL');
		if ($url === false || $url === '') {
			throw new \RuntimeException('PENPOT_URL is not set — the Penpot service is not running.');
		}
		return $url;
	}

	/**
	 * A token cell. "a bad token" is the only marker that means an invalid one —
	 * every other non-empty phrase reaches for the minted one, so a row exercising
	 * a DIFFERENT field can say "a good token" and mean it.
	 */
	private function tokenFor(string $value): string {
		if ($value === 'a bad token') {
			return 'not-a-real-token';
		}
		$token = getenv('PENPOT_TOKEN');
		if ($token === false || $token === '') {
			throw new \RuntimeException('PENPOT_TOKEN is not set — the mint step did not run.');
		}
		return $token;
	}

	/** @Then the health check reports success */
	public function theHealthCheckReportsSuccess(): void {
		$this->theConnectionTestReportsSuccess();
	}

	/** @Then the health check reports an error */
	public function theHealthCheckReportsAnError(): void {
		$this->theConnectionTestReportsAFailure();
	}

	/** @Then the health check lists at least one Penpot team */
	public function theHealthCheckListsATeam(): void {
		$this->theConnectionTestListsATeam();
	}

	/**
	 * The message must say WHICH field to go and fix.
	 *
	 * "It did not work" is the failure mode this asserts against: an admin with a
	 * URL, a token and a schedule in front of them needs to be told which one is
	 * wrong, and the two token failures (absent vs rejected) have different fixes.
	 *
	 * @Then /^the message names "([^"]*)" as the field causing it$/
	 */
	public function theMessageNamesTheField(string $field): void {
		if (!str_contains(strtolower($this->lastOutput), strtolower($field))) {
			throw new \RuntimeException(
				"expected the failure to name the '{$field}' field, got:\n{$this->lastOutput}",
			);
		}
	}

	/** @When the admin tests the connection */
	public function theAdminTestsTheConnection(): void {
		$this->occ('penpot_sync:test-connection');
	}

	public function theConnectionTestReportsAFailure(): void {
		if ($this->lastExit === 0) {
			throw new \RuntimeException("expected the connection test to fail:\n{$this->lastOutput}");
		}
	}

	public function theConnectionTestReportsSuccess(): void {
		if ($this->lastExit !== 0) {
			throw new \RuntimeException("expected the connection test to succeed:\n{$this->lastOutput}");
		}
	}

	public function theConnectionTestListsATeam(): void {
		if (!str_contains($this->lastOutput, 'Visible team')) {
			throw new \RuntimeException("expected the visible teams to be listed, got:\n{$this->lastOutput}");
		}
	}
}
