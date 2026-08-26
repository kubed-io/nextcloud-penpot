<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Migration\RetypeScheduleToggle;
use OCA\PenpotSync\Service\AppConfigReader;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that re-stores `schedule_enabled` as a bool.
 *
 * ## THE DELETE IS THE WHOLE RISK, AND IT IS WHY THIS FILE EXISTS
 *
 * `IAppConfig` cannot change a key's type in place, so the only way to turn a
 * radio-era `yes` into a real bool is to remove the row and write it again. That
 * makes an upgrade-time step that DELETES A USER'S SETTING — and a missing
 * `schedule_enabled` reads as OFF, so getting it wrong silently stops a schedule
 * that had been running, on an instance whose admin has no reason to connect that
 * to an upgrade.
 *
 * So the interesting tests are not "does it retype" — that is the easy half — but
 * the two that keep the delete honest: it must not fire on a key that never needed
 * it, and if the replacement write fails the old value must come back.
 */
final class RetypeScheduleToggleTest extends TestCase {
	/** A key that is STRING-typed, which is what the bool getter refuses to read. */
	private function stringTyped(string $stored): IAppConfig&\PHPUnit\Framework\MockObject\MockObject {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueBool')->willThrowException(new \RuntimeException('AppConfigTypeConflict'));
		$config->method('getValueString')->willReturn($stored);

		return $config;
	}

	private function run(IAppConfig $config): void {
		(new RetypeScheduleToggle($config, new AppConfigReader($config)))
			->run($this->createStub(IOutput::class));
	}

	public function testARadioEraValueIsRewrittenAsABool(): void {
		$config = $this->stringTyped('yes');

		$config->expects(self::once())->method('deleteKey')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED);
		// THE MEANING SURVIVES THE RETYPE. An instance that was pulling on a schedule
		// has to still be pulling afterwards — this step changes how the answer is
		// stored, never what the answer is.
		$config->expects(self::once())->method('setValueBool')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, true);

		$this->run($config);
	}

	public function testAnOffValueStaysOff(): void {
		$config = $this->stringTyped('no');

		$config->expects(self::once())->method('setValueBool')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, false);

		$this->run($config);
	}

	/**
	 * A HEALTHY KEY IS NOT TOUCHED, which matters because this runs on every single
	 * upgrade. The delete is not a no-op, so "idempotent" cannot mean "delete and
	 * rewrite each time" — it has to mean the step notices there is nothing to do.
	 */
	public function testAKeyThatAlreadyTakesABoolIsLeftAlone(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueBool')->willReturn(true);

		$config->expects(self::never())->method('deleteKey');
		$config->expects(self::never())->method('setValueBool');
		$config->expects(self::never())->method('setValueString');

		$this->run($config);
	}

	/**
	 * A FRESH INSTALL HAS NO KEY, and must not acquire one. Writing a `false` here
	 * would look harmless and would replace "the admin has never answered" with "the
	 * admin said no" — the checkbox's own default already covers the first.
	 */
	public function testAnUnsetKeyIsNotCreated(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(false);

		$config->expects(self::never())->method('deleteKey');
		$config->expects(self::never())->method('setValueBool');

		$this->run($config);
	}

	/**
	 * THE WINDOW COPILOT FOUND ON #46: deleted, and the replacement did not land.
	 *
	 * Left there, the instance is strictly worse off than before the repair — the
	 * key is gone, which reads as OFF, so a schedule that had been running silently
	 * stops. The old value goes back instead, as a STRING, so the key returns to the
	 * exact shape and meaning it had.
	 */
	public function testAFailedRetypePutsTheOldValueBack(): void {
		$config = $this->stringTyped('yes');
		$config->method('setValueBool')->willThrowException(new \RuntimeException('database is gone'));

		$config->expects(self::once())->method('setValueString')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, 'yes');

		$this->run($config);
	}

	/** And it says so, rather than reporting a repair that did not happen. */
	public function testAFailedRetypeIsReportedRatherThanThrown(): void {
		$config = $this->stringTyped('yes');
		$config->method('setValueBool')->willThrowException(new \RuntimeException('database is gone'));

		$output = $this->createMock(IOutput::class);
		// A repair step that throws aborts the whole upgrade, and a misbehaving
		// settings checkbox is nowhere near worth refusing to install the app over.
		$output->expects(self::once())->method('warning');
		$output->expects(self::never())->method('info');

		(new RetypeScheduleToggle($config, new AppConfigReader($config)))->run($output);
	}
}
