<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Command\SetToken;
use OCA\PenpotSync\Service\PenpotClient;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ penpot_sync:set-token` — storing the service-account credential.
 *
 * TWO PROPERTIES CARRY REAL WEIGHT HERE, and both are asserted rather than
 * assumed, because both are silent when wrong:
 *
 *  1. **The value is encrypted before it is stored.** A test that only checked
 *     "something was written" would pass on a plaintext token.
 *  2. **It is stored with `sensitive: true`.** That flag is what keeps the token
 *     out of `occ config:list` output and support dumps — a leak that would look
 *     exactly like correct behaviour until someone pasted a config dump.
 */
final class SetTokenTest extends TestCase {
	/**
	 * STUBS by default, for the tests that only assert on exit code and output.
	 * The tests where the interaction IS the behaviour — "was it encrypted?",
	 * "was it stored sensitive?", "was nothing written at all?" — build real
	 * mocks via {@see mocked()}. PHPUnit 12 notices a mock with no expectations,
	 * and it is right to: a mock claims the call pattern matters.
	 *
	 * @var IAppConfig&\PHPUnit\Framework\MockObject\Stub
	 */
	private IAppConfig $config;
	/** @var ICrypto&\PHPUnit\Framework\MockObject\Stub */
	private ICrypto $crypto;
	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createStub(IAppConfig::class);
		$this->crypto = $this->createStub(ICrypto::class);
		$this->crypto->method('encrypt')->willReturn('ENCRYPTED');
		$this->tester = new CommandTester(new SetToken($this->config, $this->crypto));
	}

	/**
	 * Swap in real mocks for the tests that assert on calls.
	 *
	 * Each side is mocked only when that test actually constrains it; the other
	 * stays a stub. Mocking both and expecting on one is what produces PHPUnit's
	 * "no expectations configured" notice — and the notice is correct, so the fix
	 * is to be precise rather than to silence it.
	 *
	 * @return array{IAppConfig, ICrypto}
	 */
	private function mocked(bool $config = true, bool $crypto = true): array {
		$configDouble = $config ? $this->createMock(IAppConfig::class) : $this->createStub(IAppConfig::class);
		$cryptoDouble = $crypto ? $this->createMock(ICrypto::class) : $this->createStub(ICrypto::class);

		if (!$crypto) {
			$cryptoDouble->method('encrypt')->willReturn('ENCRYPTED');
		}

		$this->tester = new CommandTester(new SetToken($configDouble, $cryptoDouble));

		return [$configDouble, $cryptoDouble];
	}

	public function testEncryptsTheTokenBeforeStoringIt(): void {
		[$config, $crypto] = $this->mocked();

		$crypto->expects(self::once())
			->method('encrypt')
			->with('penpot-token-value')
			->willReturn('ENCRYPTED');

		$config->expects(self::once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				PenpotClient::KEY_TOKEN,
				'ENCRYPTED',
				false,
				true, // sensitive — keeps it out of config dumps
			);

		self::assertSame(0, $this->tester->execute(['token' => 'penpot-token-value']));
	}

	public function testStoresTheTokenAsSensitive(): void {
		[$config] = $this->mocked(crypto: false);

		$config->expects(self::once())
			->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value, bool $lazy, bool $sensitive): bool {
					self::assertTrue($sensitive, 'the token must be stored as a sensitive value');

					return true;
				},
			);

		$this->tester->execute(['token' => 'penpot-token-value']);
	}

	public function testTrimsSurroundingWhitespace(): void {
		// Tokens get pasted, and a trailing newline from `$(cat token.txt)` would
		// otherwise be sent as part of the Authorization header.
		[, $crypto] = $this->mocked(config: false);
		$crypto->expects(self::once())
			->method('encrypt')
			->with('penpot-token-value')
			->willReturn('ENCRYPTED');

		self::assertSame(0, $this->tester->execute(['token' => "  penpot-token-value\n"]));
	}

	public function testRejectsAnEmptyTokenWithoutStoringAnything(): void {
		[$config, $crypto] = $this->mocked();
		$crypto->expects(self::never())->method('encrypt');
		$config->expects(self::never())->method('setValueString');

		self::assertSame(1, $this->tester->execute(['token' => '   ']));
	}

	public function testPointsTheUserAtTheVerificationCommand(): void {
		$this->tester->execute(['token' => 'penpot-token-value']);

		// Storing a token proves nothing about whether it works — the next step
		// has to be discoverable from here.
		self::assertStringContainsString('penpot_sync:probe', $this->tester->getDisplay());
	}

	public function testNeverEchoesTheTokenBack(): void {
		$this->tester->execute(['token' => 'super-secret-token']);

		self::assertStringNotContainsString('super-secret-token', $this->tester->getDisplay());
	}
}
