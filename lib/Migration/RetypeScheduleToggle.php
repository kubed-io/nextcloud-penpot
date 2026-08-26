<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Migration;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\AppConfigReader;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-store `schedule_enabled` as a BOOL, once, for instances carrying the value
 * the RADIO era wrote.
 *
 * ## WHY A STORED `yes` IS NOT MERELY UNTIDY
 *
 * `IAppConfig` remembers a key's TYPE, and refuses to change it. The toggle was a
 * radio before it was a checkbox, so on any instance that ran that version the key
 * exists as `VALUE_STRING` holding `yes` or `no` — and
 * {@see AutoSyncSettings::setValue()} writes with `setValueBool()`, which walks
 * into core's own guard:
 *
 *     throw new AppConfigTypeConflictException(
 *         'conflict between new type (bool) and old type (string)')
 *
 * So every save from the settings panel throws and the toggle springs back, while
 * `occ config:app:set` — which writes a string — works perfectly. That asymmetry
 * is the tell, and it survives {@see \OCA\PenpotSync\Settings\InstanceSettings}'s
 * storage-type fix: that one makes the handlers get CALLED, this one makes the
 * write they perform land.
 *
 * READS were never broken, which is why the app looked half-fine: the schedule ran
 * and `show-config` reported it enabled, because {@see AppConfigReader::bool()}
 * catches the same conflict on the way in and parses the string. Only the person
 * looking at the checkbox saw a lie.
 *
 * ## DELETE-THEN-WRITE, BECAUSE THERE IS NO RETYPE
 *
 * `IAppConfig` offers no way to change a key's type in place — the conflict guard
 * is unconditional — so the row is removed and written afresh, which inserts it
 * with the type of whatever setter created it. The value is read FIRST and carried
 * across, so an instance that had the schedule switched on stays switched on: this
 * step changes how the answer is stored and never what the answer is.
 *
 * Idempotent, and cheap enough to run on every upgrade: an instance whose key is
 * already bool-typed reads it, writes the same value back, and `setTypedValue()`
 * returns early without touching the database.
 */
final class RetypeScheduleToggle implements IRepairStep {
	public function __construct(
		private readonly IAppConfig $config,
		private readonly AppConfigReader $reader,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Penpot Sync: store the scheduled-sync toggle as a boolean';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if (!$this->config->hasKey(Application::APP_ID, AutoSyncSettings::KEY_ENABLED)) {
			// Never set. Leave it absent rather than writing a false: the checkbox's
			// own default already answers "off", and an absent key is what a fresh
			// install has.
			return;
		}

		// THROUGH THE READER, so every spelling the key has ever held is understood —
		// `yes`/`no` from the radio, `1`/`0` from `occ`, or a real bool that needs no
		// rescue at all. It is the same read the app makes at runtime, so this step
		// cannot disagree with the behaviour it is preserving.
		$enabled = $this->reader->bool(AutoSyncSettings::KEY_ENABLED);

		try {
			$this->config->deleteKey(Application::APP_ID, AutoSyncSettings::KEY_ENABLED);
			$this->config->setValueBool(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, $enabled);
		} catch (\Throwable $e) {
			// A repair step that throws aborts the whole upgrade. The failure mode
			// here is a settings toggle that keeps misbehaving — bad, and nowhere near
			// bad enough to refuse to install the app over.
			$output->warning(
				'Could not re-store the scheduled-sync toggle as a boolean: ' . $e->getMessage()
				. '. The schedule still runs; the checkbox in Settings may not save.',
			);

			return;
		}

		$output->info(sprintf(
			'Scheduled-sync toggle stored as a boolean (%s).',
			$enabled ? 'on' : 'off',
		));
	}
}
