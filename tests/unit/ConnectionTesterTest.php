<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ConnectionResult;
use OCA\PenpotSync\Service\ConnectionTester;
use OCA\PenpotSync\Service\PenpotClient;
use PHPUnit\Framework\TestCase;

/**
 * "Test connection" — the states it must tell apart.
 *
 * admin-connection.feature is explicit that an *unset* token and a *rejected*
 * token must report differently, because they send an admin to completely
 * different fixes. These tests pin that, plus the case the feature file calls
 * out specifically: `enable-access-tokens` being off upstream produces a plain
 * 401 that is indistinguishable from a typo'd token unless the message names it.
 */
final class ConnectionTesterTest extends TestCase {
	private function tester(PenpotClient $client): ConnectionTester {
		return new ConnectionTester($client);
	}

	private function clientReturning(array $teams): PenpotClient {
		$client = $this->createStub(PenpotClient::class);
		$client->method('ping')->willReturn($teams);

		return $client;
	}

	private function clientThrowing(string $kind, string $message = 'boom'): PenpotClient {
		$client = $this->createStub(PenpotClient::class);
		$client->method('ping')->willThrowException(
			new PenpotApiException($message, 0, null, $kind),
		);

		return $client;
	}

	public function testReportsTheVisibleTeams(): void {
		$result = $this->tester($this->clientReturning(['Ferronescotia', 'Default']))->test();

		self::assertTrue($result->success);
		self::assertSame(ConnectionResult::KIND_OK, $result->kind);
		self::assertSame(['Ferronescotia', 'Default'], $result->teams);
		// Naming the teams is the point (§6.12/§6.18) — "OK" would hide the one
		// fact that decides what can be mapped.
		self::assertStringContainsString('Ferronescotia', $result->message);
		self::assertTrue($result->canMap());
	}

	public function testPluralisesASingleTeamCorrectly(): void {
		$result = $this->tester($this->clientReturning(['Ferronescotia']))->test();

		self::assertStringContainsString('Visible team:', $result->message);
		self::assertStringNotContainsString('teams:', $result->message);
	}

	/**
	 * Authenticated, member of nothing. This is a SUCCESS — the URL is right and
	 * the token works — that nonetheless blocks every mapping. Reporting it as a
	 * failure would send an admin to fix a connection that is not broken.
	 */
	public function testNoVisibleTeamsIsASuccessThatStillBlocksMapping(): void {
		$result = $this->tester($this->clientReturning([]))->test();

		self::assertTrue($result->success);
		self::assertSame(ConnectionResult::KIND_NO_TEAMS, $result->kind);
		self::assertFalse($result->canMap());
		self::assertStringContainsString('Invite the service account', $result->message);
	}

	public function testAnUnconfiguredTokenKeepsTheClientsOwnMessage(): void {
		$result = $this->tester(
			$this->clientThrowing(PenpotApiException::KIND_UNCONFIGURED, 'No Penpot service-account token is configured.'),
		)->test();

		self::assertFalse($result->success);
		self::assertSame(PenpotApiException::KIND_UNCONFIGURED, $result->kind);
		self::assertStringContainsString('No Penpot service-account token', $result->message);
	}

	/**
	 * The feature file's specific requirement: a rejected token must NOT read the
	 * same as an unset one, and the message must name `enable-access-tokens` —
	 * the flag is off by default upstream and its absence looks exactly like a
	 * bad token.
	 */
	public function testARejectedTokenNamesTheInstanceFlag(): void {
		$result = $this->tester($this->clientThrowing(PenpotApiException::KIND_UNAUTHORIZED))->test();

		self::assertFalse($result->success);
		self::assertStringContainsStringIgnoringCase('rejected', $result->message);
		self::assertStringContainsString('enable-access-tokens', $result->message);
	}

	public function testAnUnreachableInstanceKeepsTheDiagnosticMessage(): void {
		// The client's unreachable message is the one that names
		// `allow_local_remote_servers` — losing it here would discard the actual
		// fix (saga R1.7).
		$result = $this->tester($this->clientThrowing(
			PenpotApiException::KIND_UNREACHABLE,
			'Nextcloud refused to connect to a local address. Set `allow_local_remote_servers`',
		))->test();

		self::assertFalse($result->success);
		self::assertStringContainsString('allow_local_remote_servers', $result->message);
	}

	public function testNeverThrows(): void {
		// Every front door renders this result; an exception escaping here would
		// turn a diagnosable failure into a 500.
		$result = $this->tester($this->clientThrowing(PenpotApiException::KIND_PROTOCOL))->test();

		self::assertFalse($result->success);
	}

	public function testSerialisesForTheFrontend(): void {
		$json = $this->tester($this->clientReturning(['Ferronescotia']))->test()->jsonSerialize();

		self::assertSame(
			['success', 'kind', 'message', 'teams'],
			array_keys($json),
		);
	}
}
