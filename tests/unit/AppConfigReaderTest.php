<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\AppConfigReader;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The type-tolerant appconfig read behind the schedule toggle.
 *
 * WHAT IS ACTUALLY AT STAKE. `schedule_enabled` decides whether the scheduled pull
 * runs at all, and the same key can be bool-typed (the settings card, since the
 * checkbox landed), or string-typed with four different spellings (`yes`/`no` from
 * the RADIO this app shipped before that, `1`/`0` from `occ config:app:set`, which
 * is what the integration suite uses). `IAppConfig::getValueBool()` THROWS on a
 * string-typed key rather than coercing it, so a naive typed read reports the
 * schedule as off on every instance that has not re-saved the form — a silent
 * behaviour change caused purely by how the value was written.
 *
 * So the two cases below are not symmetric decoration: the first is the path a
 * fresh install takes, and the second is the path every UPGRADE takes.
 */
final class AppConfigReaderTest extends TestCase {
	public function testReadsARealBoolWithoutTheRescue(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturn(true);
		// If the rescue fired anyway it would land here and read false, so this
		// asserts the typed read is genuinely preferred rather than incidental.
		$config->method('getValueString')->willReturn('');

		self::assertTrue((new AppConfigReader($config))->bool('schedule_enabled'));
	}

	#[DataProvider('storedStrings')]
	public function testRescuesEveryStringSpelling(string $stored, bool $expected): void {
		self::assertSame($expected, $this->readerOverAStringKey($stored)->bool('schedule_enabled'));
	}

	/** @return array<string, array{string, bool}> */
	public static function storedStrings(): array {
		return [
			'yes from the radio era' => ['yes', true],
			'no from the radio era' => ['no', false],
			'1 from occ' => ['1', true],
			'0 from occ' => ['0', false],
			'true by hand' => ['true', true],
			'false by hand' => ['false', false],
			'on by hand' => ['on', true],
			'padded and shouted' => ['  YES  ', true],
			// Both of these are truthy to a bare (bool) cast. Reading either as ON
			// would start an unattended pull nobody asked for.
			'unset is off' => ['', false],
			'nonsense is off, never on' => ['banana', false],
		];
	}

	public function testStringReadsFallBackToTheDefaultWhenTheKeyIsUnreadable(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new \RuntimeException('AppConfigTypeConflict'));

		self::assertSame('1h', (new AppConfigReader($config))->string('schedule_interval', '1h'));
	}

	#[DataProvider('coercions')]
	public function testCoercesWhateverTheFrontendSends(mixed $sent, bool $expected): void {
		self::assertSame($expected, AppConfigReader::coerceBool($sent));
	}

	/** @return array<string, array{mixed, bool}> */
	public static function coercions(): array {
		return [
			'a real true' => [true, true],
			'a real false' => [false, false],
			'one as an int' => [1, true],
			'zero as an int' => [0, false],
			'a string from a form post' => ['1', true],
			'null means off' => [null, false],
			'an array is not a checkbox' => [[], false],
		];
	}

	/** A reader over a key that is string-typed, so the bool getter throws. */
	private function readerOverAStringKey(string $stored): AppConfigReader {
		/** @var IAppConfig&Stub $config */
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willThrowException(new \RuntimeException('AppConfigTypeConflict'));
		$config->method('getValueString')->willReturn($stored);

		return new AppConfigReader($config);
	}
}
