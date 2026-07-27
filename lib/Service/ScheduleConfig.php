<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Settings\ScheduleSettings;
use OCP\IAppConfig;

/**
 * Reads the scheduled-pull settings and turns the interval into seconds.
 *
 * ## WHY THIS EXISTS BEFORE THE JOB DOES
 *
 * The card ({@see ScheduleSettings}) accepts a free-text duration like `1h`,
 * because presets age badly and a text box is honest about being a duration.
 * But free text needs parsing, and parsing needs a defined answer for `""`,
 * `"0"`, `"banana"`, and `"1s"`. Deciding that here — with tests — means the
 * Course 3 background job inherits a settled contract instead of re-deriving it
 * from a string it happens to find in appconfig.
 *
 * ## THE FLOOR IS REAL, NOT DECORATIVE
 *
 * A pull costs 1 + P requests per team (§5.5). Sub-minute intervals spend
 * requests without catching fresher designs, and a typo (`5` meaning "5
 * minutes", parsed as 5 seconds) would hammer Penpot. Anything below the floor
 * is CLAMPED UP rather than rejected: the value is already stored by the time
 * anything reads it, and a background job that refuses to run because of a bad
 * setting is worse than one that runs a bit less often than asked.
 *
 * Rejection is the *card's* job at input time; clamping is this reader's job at
 * use time. Different moments, different correct answers.
 */
class ScheduleConfig {
	/**
	 * The slowest anything may be asked to run, in seconds.
	 *
	 * Not a Penpot limit — a homelab-scale sanity floor. See the class docblock.
	 */
	public const MIN_INTERVAL = 300;

	/** Used when the stored value is absent or unparseable. */
	public const DEFAULT_INTERVAL = 3600;

	private const UNITS = [
		's' => 1,
		'm' => 60,
		'h' => 3600,
		'd' => 86400,
	];

	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, ScheduleSettings::KEY_ENABLED, false);
	}

	/**
	 * The configured interval in seconds, clamped to {@see MIN_INTERVAL}.
	 *
	 * Never throws and never returns something a `TimedJob` cannot use — an
	 * unparseable value falls back to the default rather than disabling the pull,
	 * because a stored typo should degrade the schedule, not silently stop it.
	 */
	public function getIntervalSeconds(): int {
		$raw = $this->config->getValueString(
			Application::APP_ID,
			ScheduleSettings::KEY_INTERVAL,
			'',
		);

		$parsed = self::parseInterval($raw);

		return max(self::MIN_INTERVAL, $parsed ?? self::DEFAULT_INTERVAL);
	}

	/**
	 * Parse a duration like `30s`, `15m`, `1h`, `2d`, or a bare number (seconds).
	 *
	 * Returns null when the input means nothing — the caller decides whether that
	 * is a fallback (this class) or a validation error (the CLI).
	 */
	public static function parseInterval(string $raw): ?int {
		$value = strtolower(trim($raw));

		if ($value === '') {
			return null;
		}

		if (preg_match('/^(\d+)\s*([smhd])?$/', $value, $m) !== 1) {
			return null;
		}

		$amount = (int)$m[1];

		if ($amount <= 0) {
			// "0" and "0h" are not a schedule. Turning the pull off is what the
			// enabled checkbox is for, and conflating the two would make the
			// checkbox lie about the state.
			return null;
		}

		// A bare number means seconds — the same convention the n8n sibling uses,
		// kept identical so an admin who knows one app knows this one.
		$unit = $m[2] ?? 's';

		return $amount * self::UNITS[$unit];
	}

	/** Render seconds back as the shortest exact duration string. */
	public static function formatInterval(int $seconds): string {
		foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $suffix => $size) {
			if ($seconds % $size === 0 && $seconds >= $size) {
				return ((int)($seconds / $size)) . $suffix;
			}
		}

		return $seconds . 's';
	}
}
