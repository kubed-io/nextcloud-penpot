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
 * **A delete is not a no-op, so it is gated and it is reversible.** A key that can
 * already take a bool write is left completely alone, and a retype that fails
 * between the two halves puts the old string back — because a MISSING key reads as
 * OFF, which would silently stop a schedule that had been running and look like
 * nothing to do with an upgrade. Idempotent, and free on every run after the first.
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

		if ($this->alreadyBoolTyped()) {
			// The ordinary case on every upgrade after the first, and on any instance
			// that never ran the radio. Nothing to fix, and the delete below is not a
			// no-op — so it must not run.
			return;
		}

		// THROUGH THE READER, so every spelling the key has ever held is understood —
		// `yes`/`no` from the radio, `1`/`0` from `occ`. It is the same read the app
		// makes at runtime, so this step cannot disagree with the behaviour it is
		// preserving.
		$enabled = $this->reader->bool(AutoSyncSettings::KEY_ENABLED);

		try {
			$this->config->deleteKey(Application::APP_ID, AutoSyncSettings::KEY_ENABLED);
		} catch (\Throwable $e) {
			// Nothing has changed yet, so there is nothing to undo.
			$this->giveUp($output, $e);

			return;
		}

		try {
			$this->config->setValueBool(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, $enabled);
		} catch (\Throwable $e) {
			// THE ONE WINDOW WORTH GUARDING. The key is deleted and the replacement
			// did not land, which is strictly worse than the bug being repaired: a
			// missing key reads as OFF, so an instance that was pulling on a schedule
			// would silently stop — and nobody would connect that to an upgrade.
			//
			// So it goes back exactly as it was, in meaning AND in type: a string the
			// reader understands, which leaves the instance no better and no worse
			// than it started. Raised in review on #46.
			$this->putItBack($enabled);
			$this->giveUp($output, $e);

			return;
		}

		$output->info(sprintf(
			'Scheduled-sync toggle stored as a boolean (%s).',
			$enabled ? 'on' : 'off',
		));
	}

	/**
	 * Can this key take a bool write already?
	 *
	 * ASKED BY READING, because that is the only way to find out: `IAppConfig`
	 * exposes no "what type is this key" accessor, and `getValueBool()` raises
	 * `AppConfigTypeConflictException` on precisely the keys `setValueBool()` would
	 * refuse. A read that succeeds therefore proves the write will — including for a
	 * `VALUE_MIXED` key, which both accept.
	 */
	private function alreadyBoolTyped(): bool {
		try {
			$this->config->getValueBool(Application::APP_ID, AutoSyncSettings::KEY_ENABLED);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Restore the pre-repair state after a failed retype.
	 *
	 * `yes`/`no` rather than `1`/`0` only because it is what this app's own radio
	 * wrote; {@see AppConfigReader::bool()} reads either. What matters is that it is
	 * a STRING, so the key comes back the same shape it was — and if this throws
	 * too, there is nothing further to try and the warning above is what the admin
	 * gets.
	 */
	private function putItBack(bool $enabled): void {
		try {
			$this->config->setValueString(
				Application::APP_ID,
				AutoSyncSettings::KEY_ENABLED,
				$enabled ? 'yes' : 'no',
			);
		} catch (\Throwable) {
			// Deliberately swallowed: this is already the recovery path.
		}
	}

	/**
	 * A repair step that THROWS aborts the whole upgrade, and the worst outcome here
	 * is a settings toggle that keeps misbehaving — nowhere near bad enough to
	 * refuse to install the app over. So it reports and returns.
	 */
	private function giveUp(IOutput $output, \Throwable $e): void {
		$output->warning(
			'Could not re-store the scheduled-sync toggle as a boolean: ' . $e->getMessage()
			. '. The setting was left as it was, so the schedule behaves exactly as before '
			. 'this upgrade; the checkbox in Settings may still fail to save.',
		);
	}
}
