<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

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
 * The token comes from `bin/mint-penpot-token.sh` (saga §6.47) — Penpot can mint
 * one headlessly, so this suite needs no repository secret.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}.
 */
trait ConnectionSteps {
	/** @Given the Penpot base URL points at the test instance */
	public function thePenpotBaseUrlPointsAtTheTestInstance(): void {
		$url = getenv('PENPOT_URL');
		Assert::assertNotFalse($url, 'PENPOT_URL is not set — the Penpot service is not running.');

		$res = $this->occ('penpot_sync:set-url ' . escapeshellarg((string)$url));
		Assert::assertSame(0, $res['exit'], "set-url failed:\n{$res['output']}");
	}

	/** @Given the admin has configured the service-account token */
	public function theAdminHasConfiguredTheServiceAccountToken(): void {
		$token = getenv('PENPOT_TOKEN');
		Assert::assertNotFalse($token, 'PENPOT_TOKEN is not set — the mint step did not run.');

		$res = $this->occ('penpot_sync:set-token ' . escapeshellarg((string)$token));
		Assert::assertSame(0, $res['exit'], "set-token failed:\n{$res['output']}");
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

	/** @Then the connection succeeds */
	public function theConnectionSucceeds(): void {
		Assert::assertSame(
			0,
			$this->lastExit,
			"probe failed:\n{$this->lastOutput}",
		);
		Assert::assertStringContainsString('Connected', $this->lastOutput);
	}

	/** @Then the connection fails */
	public function theConnectionFails(): void {
		Assert::assertNotSame(
			0,
			$this->lastExit,
			"expected probe to fail, got:\n{$this->lastOutput}",
		);
	}

	/**
	 * The whole reason `probe` reports teams rather than "OK" (saga §6.12/§6.18):
	 * Penpot visibility is always membership-scoped, so *which* teams a token can
	 * see is the fact that decides what can be mapped.
	 *
	 * @Then at least one Penpot team is listed
	 */
	public function atLeastOnePenpotTeamIsListed(): void {
		Assert::assertMatchesRegularExpression(
			'/Visible teams: \S/',
			$this->lastOutput,
			"no teams were listed:\n{$this->lastOutput}",
		);
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
		Assert::assertMatchesRegularExpression(
			'/Projects \(([1-9][0-9]*)\)/',
			$this->lastOutput,
			"no projects were listed:\n{$this->lastOutput}",
		);
	}

	/** @Then the failure explains that no token is configured */
	public function theFailureExplainsThatNoTokenIsConfigured(): void {
		Assert::assertStringContainsString('token', strtolower($this->lastOutput));
	}

	/** @Then the failure names the connection as unreachable */
	public function theFailureNamesTheConnectionAsUnreachable(): void {
		Assert::assertStringContainsString('unreachable', strtolower($this->lastOutput));
	}
}
