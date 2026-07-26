<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\Transit;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `PenpotClient` — the param table and the guards that run before the wire.
 *
 * WHAT THIS SUITE IS FOR, AND WHAT IT DELIBERATELY IS NOT. The transport is
 * exercised against a REAL Penpot in the integration suite, because a mock of a
 * protocol we have repeatedly misread would only encode the misreading (saga
 * §6.26 is the cautionary tale — a confident conclusion drawn without calling
 * the thing). So this suite covers the parts that are pure logic and must be
 * right *before* a request is built:
 *
 *   - the param table, which exists because Penpot has FOUR different parameter
 *     conventions across four commands and no rule connecting them (saga §6.54);
 *   - the name guard, which saves a round trip on a rule Penpot enforces anyway;
 *   - the unconfigured states, which must be a clear message rather than a
 *     confusing failure deep in the HTTP stack.
 */
final class PenpotClientTest extends TestCase {
	/** @var IAppConfig&\PHPUnit\Framework\MockObject\Stub */
	private IAppConfig $config;
	/** @var ICrypto&\PHPUnit\Framework\MockObject\Stub */
	private ICrypto $crypto;
	private PenpotClient $client;

	protected function setUp(): void {
		parent::setUp();

		// STUBS, not mocks: nothing here asserts on how a collaborator was
		// called — the assertions are all on what the client RETURNS or REFUSES.
		// PHPUnit 12 emits a notice for a mock with no configured expectations,
		// and it is right to: a mock says "I care about the interaction", which
		// would be a false claim here.
		$this->config = $this->createStub(IAppConfig::class);
		$this->crypto = $this->createStub(ICrypto::class);

		$this->client = new PenpotClient(
			$this->config,
			$this->crypto,
			$this->createStub(IClientService::class),
			new Transit(),
			$this->createStub(LoggerInterface::class),
		);
	}

	/**
	 * Reach the private translator directly. It is the single most consequential
	 * piece of logic in the class and it is deliberately not public — testing it
	 * through a mocked HTTP stack would assert on the mock, not on the table.
	 *
	 * @param array<string, string> $args
	 *
	 * @return array<string, string>
	 */
	private function wireParams(string $command, array $args): array {
		$method = new \ReflectionMethod(PenpotClient::class, 'wireParams');
		$method->setAccessible(true);

		/** @var array<string, string> $result */
		$result = $method->invoke($this->client, $command, $args);

		return $result;
	}

	// ── the param table (saga §6.54) ────────────────────────────────────────

	/**
	 * THE HEADLINE CASE. `rename-file` takes the file id under bare `id`, while
	 * every neighbouring command uses a qualified name. Confirmed live: sending
	 * `file-id` returns HTTP 400 `:params-validation` with `missing-key [:id]`.
	 */
	public function testRenameFileSendsTheIdUnderPlainId(): void {
		self::assertSame(
			['id' => 'abc', 'name' => 'New Name'],
			$this->wireParams('rename-file', ['file' => 'abc', 'name' => 'New Name']),
		);
	}

	/**
	 * The four confirmed conventions, in one place. If this test ever needs
	 * "fixing" by relaxing it, the fix is wrong — each row was established by
	 * calling the command live.
	 *
	 * @return iterable<string, array{string, array<string, string>, array<string, string>}>
	 */
	public static function paramTableProvider(): iterable {
		yield 'rename-file uses bare id' => [
			'rename-file', ['file' => 'f1', 'name' => 'N'], ['id' => 'f1', 'name' => 'N'],
		];
		yield 'rename-project also uses bare id' => [
			'rename-project', ['project' => 'p1', 'name' => 'N'], ['id' => 'p1', 'name' => 'N'],
		];
		yield 'create-project uses kebab team-id' => [
			'create-project', ['team' => 't1', 'name' => 'N'], ['team-id' => 't1', 'name' => 'N'],
		];
		yield 'get-project-files uses kebab project-id' => [
			'get-project-files', ['project' => 'p1'], ['project-id' => 'p1'],
		];
		yield 'get-team-deleted-files uses kebab team-id' => [
			'get-team-deleted-files', ['team' => 't1'], ['team-id' => 't1'],
		];
		yield 'no-arg commands send nothing' => [
			'get-teams', [], [],
		];
	}

	/**
	 * @param array<string, string> $args
	 * @param array<string, string> $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('paramTableProvider')]
	public function testTheParamTableMatchesWhatPenpotConfirmedLive(
		string $command,
		array $args,
		array $expected,
	): void {
		self::assertSame($expected, $this->wireParams($command, $args));
	}

	/**
	 * Passing a wire param name directly is a programmer error, and it must fail
	 * HERE rather than as a puzzling Penpot 400 — that indirection is the entire
	 * reason the table exists.
	 */
	public function testAWireParamNamePassedDirectlyIsRefused(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/has no parameter "file-id"/');

		$this->wireParams('rename-file', ['file-id' => 'abc']);
	}

	public function testAnUnknownCommandIsRefused(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/Unknown Penpot command/');

		$this->wireParams('delete-everything', []);
	}

	// ── the name guard ──────────────────────────────────────────────────────

	public function testAnEmptyNameIsRefusedBeforeAnyRequest(): void {
		$this->givenConfigured();

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/cannot be empty/');

		$this->client->renameFile('abc', '   ');
	}

	public function testAnOverlongNameIsRefusedBeforeAnyRequest(): void {
		$this->givenConfigured();

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/250/');

		$this->client->renameFile('abc', str_repeat('x', 251));
	}

	/**
	 * A "/" is NOT rejected here. Whether it is legal depends on the mapping's
	 * folder mode (saga §6.53), which this class knows nothing about — so the
	 * guard belongs to the pull, and putting it here would silently break
	 * `keyed` mode later.
	 */
	public function testASlashIsNotTheClientsBusiness(): void {
		$method = new \ReflectionMethod(PenpotClient::class, 'assertName');
		$method->setAccessible(true);

		$method->invoke($this->client, 'foo/bar');

		self::assertTrue(true, 'assertName must not reject "/" — folder mode decides that.');
	}

	// ── unconfigured states ─────────────────────────────────────────────────

	public function testAMissingBaseUrlIsAClearMessage(): void {
		$this->config->method('getValueString')->willReturn('');

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/No Penpot base URL is configured/');

		$this->client->getTeams();
	}

	public function testAMissingTokenIsAClearMessage(): void {
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => $key === 'penpot_url' ? 'https://penpot.example.com' : '');

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/No Penpot service-account token/');

		$this->client->getTeams();
	}

	public function testAnUndecryptableTokenAsksForItToBeSetAgain(): void {
		$this->givenConfigured();
		$this->crypto->method('decrypt')->willThrowException(new \RuntimeException('bad key'));

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/could not be decrypted/');

		$this->client->getTeams();
	}

	/** All three unconfigured states share one kind, so callers branch once. */
	public function testUnconfiguredStatesShareOneKind(): void {
		$this->config->method('getValueString')->willReturn('');

		try {
			$this->client->getTeams();
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			self::assertSame(PenpotApiException::KIND_UNCONFIGURED, $e->getKind());
			self::assertFalse($e->isRetryable(), 'a setup problem never fixes itself on retry');
		}
	}

	private function givenConfigured(): void {
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => $key === 'penpot_url'
				? 'https://penpot.example.com'
				: 'encrypted-token');
	}
}
