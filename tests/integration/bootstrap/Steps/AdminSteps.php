<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Admin connection steps — in this slice, the base URL and nothing else.
 *
 * Steps read in plain English and stay medium-agnostic; occ is an implementation
 * detail of the step definitions, not of the feature. That matters here because
 * the same scenarios should keep passing unchanged once a UI exists — the
 * feature says "the admin sets the Penpot base URL", not "the admin runs occ".
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}.
 */
trait AdminSteps {
	/** @When the admin sets the Penpot base URL */
	public function theAdminSetsThePenpotBaseUrl(): void {
		$res = $this->occ('penpot_sync:set-url https://penpot.example.com');
		Assert::assertSame(0, $res['exit'], "set-url failed:\n{$res['output']}");
	}

	/**
	 * Pinned to a quoted argument for the same reason as the `is "..."` step
	 * below — otherwise this also matches the no-argument phrasing above.
	 *
	 * @When /^the admin sets the Penpot base URL to "([^"]*)"$/
	 */
	public function theAdminSetsThePenpotBaseUrlTo(string $url): void {
		$this->occ('penpot_sync:set-url ' . escapeshellarg($url));
	}

	/**
	 * NOTE ON THE TWO PHRASINGS BELOW. Behat turns `:url` into a permissive
	 * pattern that also matches a bare word, so "the Penpot base URL is stored"
	 * is ALSO a match for "the Penpot base URL is :url" (binding "stored" as the
	 * argument) — an ambiguous step, which Behat fails rather than guesses at.
	 * Renaming the no-argument step isn't enough; the parameterised one has to be
	 * pinned to a QUOTED argument, which is what the explicit regex does.
	 *
	 * @Then the Penpot base URL is stored
	 */
	public function thePenpotBaseUrlIsStored(): void {
		$res = $this->occ('penpot_sync:show-config');
		Assert::assertSame(0, $res['exit'], "show-config reported nothing configured:\n{$res['output']}");
		Assert::assertStringContainsString(
			'https://penpot.example.com',
			$res['output'],
			'the configured URL did not come back from show-config',
		);
	}

	/** @Then /^the Penpot base URL is "([^"]+)"$/ */
	public function thePenpotBaseUrlIs(string $url): void {
		$res = $this->occ('penpot_sync:show-config');
		Assert::assertStringContainsString($url, $res['output']);
	}

	/** @Then no Penpot base URL is configured */
	public function noPenpotBaseUrlIsConfigured(): void {
		$res = $this->occ('penpot_sync:show-config');
		Assert::assertNotSame(0, $res['exit'], 'expected show-config to report nothing configured');
	}

	/** @Then setting the URL is rejected */
	public function settingTheUrlIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "expected the URL to be rejected, got:\n{$this->lastOutput}");
	}

	/**
	 * The URL must survive a round trip through storage unchanged apart from the
	 * documented normalisation (trailing slash stripped) — a trailing slash here
	 * is what would produce "…//api/rpc" once the client lands.
	 *
	 * @Then the stored URL has no trailing slash
	 */
	public function theStoredUrlHasNoTrailingSlash(): void {
		$res = $this->occ('penpot_sync:show-config');
		Assert::assertDoesNotMatchRegularExpression(
			'#https?://\S+/\s*$#m',
			trim($res['output']),
			'the stored URL kept a trailing slash',
		);
	}
}
