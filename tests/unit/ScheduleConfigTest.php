<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ScheduleConfig;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The interval parser.
 *
 * The card takes free text, so this has to have a defined answer for every kind
 * of nonsense a human can type — and the *right* answer differs by caller:
 * {@see ScheduleConfig::parseInterval()} returns null so the CLI can reject bad
 * input, while {@see ScheduleConfig::getIntervalSeconds()} falls back and clamps
 * so a background job never refuses to run over a stored typo.
 *
 * Written now, with the job still unbuilt, precisely so Course 3 inherits a
 * settled contract instead of re-deriving one from whatever string it finds.
 */
final class ScheduleConfigTest extends TestCase {
	/** @return array<string, array{string, ?int}> */
	public static function intervals(): array {
		return [
			'seconds' => ['30s', 30],
			'minutes' => ['15m', 900],
			'hours' => ['1h', 3600],
			'days' => ['2d', 172800],
			'bare number means seconds' => ['90', 90],
			'uppercase unit' => ['1H', 3600],
			'internal space' => ['15 m', 900],
			'surrounding whitespace' => ['  1h  ', 3600],
			'empty' => ['', null],
			'unknown unit' => ['5w', null],
			'not a number' => ['banana', null],
			'negative' => ['-5m', null],
			// "0" is not a schedule. Turning the pull off is what the enabled
			// checkbox is for, and conflating the two would make the checkbox
			// lie about the actual state.
			'zero' => ['0', null],
			'zero with a unit' => ['0h', null],
			// `(int)` SATURATES at PHP_INT_MAX rather than wrapping, so without a
			// guard the multiply overflows to a float and the ?int return type
			// throws a TypeError — crashing every READ of a stored junk value,
			// not just its first use. Nonsense input takes the "banana" path.
			'saturating overflow' => ['99999999999999999999d', null],
			'exactly PHP_INT_MAX seconds still parses' => [(string)PHP_INT_MAX, PHP_INT_MAX],
		];
	}

	#[DataProvider('intervals')]
	public function testParsesIntervals(string $input, ?int $expected): void {
		self::assertSame($expected, ScheduleConfig::parseInterval($input));
	}

	public function testClampsBelowTheFloor(): void {
		// A typo like "5" (meaning 5 minutes, parsed as 5 seconds) would otherwise
		// hammer Penpot with 1 + P requests every five seconds.
		self::assertSame(ScheduleConfig::MIN_INTERVAL, $this->config('5s')->getIntervalSeconds());
	}

	public function testFallsBackWhenUnparseable(): void {
		// Degrade the schedule, never disable it: a stored typo must not silently
		// stop the pull, because nothing would ever say why it stopped.
		self::assertSame(ScheduleConfig::DEFAULT_INTERVAL, $this->config('banana')->getIntervalSeconds());
	}

	public function testFallsBackWhenUnset(): void {
		self::assertSame(ScheduleConfig::DEFAULT_INTERVAL, $this->config('')->getIntervalSeconds());
	}

	public function testHonoursAValidInterval(): void {
		self::assertSame(21600, $this->config('6h')->getIntervalSeconds());
	}

	public function testReadsTheEnabledFlagAsABool(): void {
		self::assertTrue($this->config('1h', true)->isEnabled());
		self::assertFalse($this->config('1h', false)->isEnabled());
	}

	/** @return array<string, array{int, string}> */
	public static function formats(): array {
		return [
			'exact days' => [172800, '2d'],
			'exact hours' => [3600, '1h'],
			'exact minutes' => [900, '15m'],
			'not a round unit' => [90, '90s'],
			'below a minute' => [30, '30s'],
		];
	}

	#[DataProvider('formats')]
	public function testFormatsBackToTheShortestExactString(int $seconds, string $expected): void {
		self::assertSame($expected, ScheduleConfig::formatInterval($seconds));
	}

	public function testFormatRoundTripsThroughParse(): void {
		foreach ([300, 900, 3600, 21600, 86400] as $seconds) {
			self::assertSame(
				$seconds,
				ScheduleConfig::parseInterval(ScheduleConfig::formatInterval($seconds)),
				'formatting then parsing must be lossless',
			);
		}
	}

	private function config(string $interval, bool $enabled = false): ScheduleConfig {
		/** @var IAppConfig&Stub $config */
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === AutoSyncSettings::KEY_INTERVAL
				? $interval
				: $default,
		);
		$config->method('getValueBool')->willReturn($enabled);

		return new ScheduleConfig($config);
	}
}
