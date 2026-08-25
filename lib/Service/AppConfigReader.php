<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Type-tolerant reads of this app's own appconfig keys.
 *
 * ## WHY A READ NEEDS A RESCUE AT ALL
 *
 * The same key can have been written three ways over this app's life: as a STRING
 * by the old declarative-INTERNAL path (`'yes'`/`'no'`), as a string by
 * `occ config:app:set` (`'1'`/`'0'`, and the integration suite does exactly this),
 * and as a real BOOL by {@see \OCA\PenpotSync\Settings\AutoSyncSettings::setValue}
 * now. `IAppConfig::getValueBool()` throws `AppConfigTypeConflict` on the first two
 * rather than coercing, so a typed read would report the schedule as OFF — or
 * crash — purely because of how the value was stored.
 *
 * Reporting a setting as off because of its STORAGE is a silent behaviour change,
 * and this one has teeth: it is the difference between the scheduled pull running
 * and not. So every read tries the natural getter first and falls back to parsing
 * whatever string is actually in there.
 *
 * Ported verbatim in shape from the grafana and n8n siblings, which each grew this
 * class for the same reason and after the same bug.
 *
 * READS ONLY. Writes stay with their owners, which each pick the stored type
 * deliberately — see `AutoSyncSettings::setValue()` on why the toggle must be
 * written bool-typed.
 */
final class AppConfigReader {
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	/**
	 * A bool-typed read with a string-parse rescue.
	 *
	 * The rescue's default is false, and the PARSE is what decides — so an
	 * unreadable value reads as off, matching what the typed getter would have
	 * said about a missing key.
	 */
	public function bool(string $key): bool {
		try {
			return $this->config->getValueBool(Application::APP_ID, $key, false);
		} catch (\Throwable) {
			return self::coerceBool($this->string($key, ''));
		}
	}

	public function string(string $key, string $default): string {
		try {
			return $this->config->getValueString(Application::APP_ID, $key, $default);
		} catch (\Throwable) {
			return $default;
		}
	}

	/**
	 * What the settings frontend may round-trip for a checkbox: a real bool, an
	 * int, or one of the usual string spellings.
	 *
	 * `yes` is in the list because it is what THIS app stored while the toggle was
	 * a RADIO — every instance upgrading past that release has one in appconfig,
	 * and dropping it here would switch their schedule off on upgrade.
	 */
	public static function coerceBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value !== 0;
		}

		return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}
}
