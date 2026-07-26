<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Command\Probe;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\PenpotClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ penpot_sync:probe` — the connection check.
 *
 * WHAT IS ACTUALLY BEING TESTED. Not "can we reach Penpot" — that is the
 * integration suite's job, against a real instance. This covers the command's
 * REPORTING, which carries a design decision worth protecting:
 *
 * **A token that sees no teams exits 0, not 1.** Penpot's visibility is always
 * membership-scoped (saga §6.12) — there is no admin view — so "authenticated
 * but a member of nothing" is an ordinary state, not a fault. It is also the
 * exact state that blocks mapping (saga §6.18), so it must be reported clearly
 * rather than either hidden behind "Connection OK" or misreported as an error.
 */
final class ProbeTest extends TestCase {
	/**
	 * A STUB for the tests that only assert on what the command PRINTS, and a
	 * MOCK (built per test via {@see mockClient()}) for the ones where the
	 * interaction itself is the behaviour — "was getProjectFiles called at all?".
	 *
	 * PHPUnit 12 emits a notice for a mock with no configured expectations, and
	 * it is right to: a mock declares "I care how this was called", which would
	 * be a false claim in the output-only tests.
	 *
	 * PenpotClient is final; dg/bypass-finals (loaded in tests/bootstrap.php)
	 * strips the keyword at autoload time so it can still be doubled either way.
	 *
	 * @var PenpotClient&\PHPUnit\Framework\MockObject\Stub
	 */
	private PenpotClient $client;
	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createStub(PenpotClient::class);
		$this->tester = new CommandTester(new Probe($this->client));
	}

	/**
	 * Swap in a real mock for the tests that assert on calls, rebuilding the
	 * tester around it.
	 *
	 * @return PenpotClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockClient(): PenpotClient {
		$mock = $this->createMock(PenpotClient::class);
		$this->tester = new CommandTester(new Probe($mock));

		return $mock;
	}

	public function testReportsTheVisibleTeams(): void {
		$this->client->method('ping')->willReturn(['Ferronescotia', 'Default']);
		$this->client->method('getAllProjects')->willReturn([]);

		self::assertSame(0, $this->tester->execute([]));

		$display = $this->tester->getDisplay();
		self::assertStringContainsString('Connected', $display);
		self::assertStringContainsString('Ferronescotia', $display);
	}

	public function testListsProjectsWithTheirTeam(): void {
		$this->client->method('ping')->willReturn(['Ferronescotia']);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'p1', 'name' => 'My Stuff', 'team-name' => 'Ferronescotia'],
		]);

		$this->tester->execute([]);
		$display = $this->tester->getDisplay();

		self::assertStringContainsString('Projects (1)', $display);
		self::assertStringContainsString('My Stuff', $display);
		self::assertStringContainsString('Ferronescotia', $display);
	}

	/**
	 * The design decision this whole command exists to express — see the class
	 * docblock. Exiting non-zero here would make a normal setup state look like a
	 * broken connection.
	 */
	public function testATokenThatSeesNoTeamsSucceedsButSaysSo(): void {
		$client = $this->mockClient();
		$client->method('ping')->willReturn([]);
		$client->expects(self::never())->method('getAllProjects');

		self::assertSame(0, $this->tester->execute([]));

		$display = $this->tester->getDisplay();
		self::assertStringContainsString('no teams', $display);
		self::assertStringContainsString('viewer', $display, 'the fix must be discoverable from the message');
	}

	public function testAFailedConnectionExitsNonZeroAndNamesTheKind(): void {
		$this->client->method('ping')->willThrowException(new PenpotApiException(
			'No Penpot service-account token is configured.',
			0,
			null,
			PenpotApiException::KIND_UNCONFIGURED,
		));

		self::assertSame(1, $this->tester->execute([]));

		$display = $this->tester->getDisplay();
		self::assertStringContainsString('token is configured', $display);
		// The kind is what tells an operator whether to fix config or fix the network.
		self::assertStringContainsString(PenpotApiException::KIND_UNCONFIGURED, $display);
	}

	public function testAFailureListingProjectsExitsNonZero(): void {
		$this->client->method('ping')->willReturn(['Ferronescotia']);
		$this->client->method('getAllProjects')->willThrowException(new PenpotApiException(
			'boom',
			0,
			null,
			PenpotApiException::KIND_UNREACHABLE,
		));

		self::assertSame(1, $this->tester->execute([]));
	}

	public function testFilesAreOnlyFetchedWhenAsked(): void {
		$client = $this->mockClient();
		$client->method('ping')->willReturn(['Ferronescotia']);
		$client->method('getAllProjects')->willReturn([
			['id' => 'p1', 'name' => 'My Stuff', 'team-name' => 'Ferronescotia'],
		]);
		$client->expects(self::never())->method('getProjectFiles');

		$this->tester->execute([]);
	}

	public function testFilesAreListedWithRevisionWhenAsked(): void {
		$client = $this->mockClient();
		$client->method('ping')->willReturn(['Ferronescotia']);
		$client->method('getAllProjects')->willReturn([
			['id' => 'p1', 'name' => 'My Stuff', 'team-name' => 'Ferronescotia'],
		]);
		$client->expects(self::once())
			->method('getProjectFiles')
			->with('p1')
			->willReturn([['id' => 'f1', 'name' => 'My firsty', 'revn' => 5]]);

		$this->tester->execute(['--files' => true]);

		$display = $this->tester->getDisplay();
		self::assertStringContainsString('My firsty', $display);
		// revn is the pull's drift signal (saga §5.5) — worth seeing in a probe.
		self::assertStringContainsString('revn=5', $display);
	}

	/**
	 * One unreadable project must not abort the whole listing — the same
	 * skip-and-report posture the pull itself takes (saga §6.25).
	 */
	public function testOneUnreadableProjectDoesNotAbortTheListing(): void {
		$this->client->method('ping')->willReturn(['Ferronescotia']);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'p1', 'name' => 'Broken', 'team-name' => 'Ferronescotia'],
			['id' => 'p2', 'name' => 'Fine', 'team-name' => 'Ferronescotia'],
		]);
		$this->client->method('getProjectFiles')->willReturnCallback(
			static function (string $id): array {
				if ($id === 'p1') {
					throw new PenpotApiException('nope', 0, null, PenpotApiException::KIND_FORBIDDEN);
				}

				return [['id' => 'f1', 'name' => 'Good file', 'revn' => 1]];
			},
		);

		self::assertSame(0, $this->tester->execute(['--files' => true]));
		self::assertStringContainsString('Good file', $this->tester->getDisplay());
	}
}
