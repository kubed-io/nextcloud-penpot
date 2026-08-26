<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\AppConfigReader;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled-pull card, and specifically the toggle that has broken twice.
 *
 * ## WHY A SETTINGS SCHEMA IS WORTH ASSERTING ON
 *
 * Normally it would not be — a schema is a declaration, and testing that a literal
 * equals itself proves nothing. This one is different, because THREE of its fields
 * are load-bearing in combination and the failure is invisible:
 *
 *   EXTERNAL storage + CHECKBOX + a real-bool default
 *
 * Get any one wrong and the admin flips the switch, the save aborts inside core with
 * a TypeError nobody surfaces, and the toggle springs back to off on reload with no
 * error shown. Nothing throws in this app, no log line is written here, and every
 * test still passes — the only symptom is that the scheduled pull silently never
 * runs. That is the exact bug this app shipped, worked around with a RADIO, and
 * that both siblings hit independently.
 *
 * So these are pinning tests in the strict sense: they exist because the
 * combination is correct for reasons that are not visible in the code, and a
 * plausible-looking edit (INTERNAL storage, a `'0'` default, dropping the handlers)
 * would revert it silently. {@see AutoSyncSettings} carries the full reasoning.
 *
 * ## AND THEY ARE NOT ENOUGH ON THEIR OWN — THE REAL RULE IS APP-WIDE
 *
 * This whole file passed while the toggle was broken, twice, because core answers
 * `getStorageType()` from the FIRST registered form in the app whatever field was
 * asked about. `InstanceSettings` said INTERNAL, so nothing ever asked this card
 * what its storage was and every assertion below was true and irrelevant.
 * {@see DeclarativeStorageTypeTest} pins the invariant that actually holds the
 * behaviour up; these stay because this card's own shape still has to be right.
 */
final class AutoSyncSettingsTest extends TestCase {
	private IAppConfig $config;
	private AutoSyncSettings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->settings = new AutoSyncSettings($this->config, new AppConfigReader($this->config));
	}

	public function testSchemaIdIsNotAppPrefixed(): void {
		// A prefixed id gets mangled by the frontend, which strips a leading
		// "<app>_" before the save call, and then fails the backend's exact match.
		$schema = $this->settings->getSchema();

		self::assertSame('data_sync', $schema['id']);
		self::assertStringStartsNotWith(Application::APP_ID, $schema['id']);
		self::assertSame(Application::APP_ID, $schema['section_id']);
	}

	/**
	 * The three-part combination, asserted together because that is how it fails.
	 */
	public function testTheToggleIsACheckboxOverExternalStorage(): void {
		$schema = $this->settings->getSchema();

		// EXTERNAL is what keeps core away from setValueString()/getAppValue(),
		// both of which are typed `string` and reject a checkbox's real bool.
		self::assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, $schema['storage_type']);

		$enabled = $this->field($schema, AutoSyncSettings::KEY_ENABLED);
		self::assertSame(DeclarativeSettingsTypes::CHECKBOX, $enabled['type']);
		// A real bool, not '0'/'no' — the frontend round-trips this value, and a
		// string here is what made the old card need a RADIO in the first place.
		self::assertFalse($enabled['default']);
	}

	public function testTheScheduleIsOffUntilSomebodyTurnsItOn(): void {
		$this->config->method('getValueBool')->willReturn(false);

		self::assertFalse($this->settings->getValue(AutoSyncSettings::KEY_ENABLED, $this->user()));
	}

	public function testTheToggleReadsBackAsARealBool(): void {
		$this->config->method('getValueBool')->willReturn(true);

		$value = $this->settings->getValue(AutoSyncSettings::KEY_ENABLED, $this->user());

		// Not just truthy: the frontend renders a checkbox from this, and '1' or
		// 'yes' would come back as a string the Vue component cannot bind to.
		self::assertTrue($value);
	}

	public function testTheToggleIsStoredBoolTyped(): void {
		// setValueBool, never setValueString: ScheduleConfig's primary read is the
		// typed one, and a string here would send every read down the rescue path.
		$this->config->expects(self::once())
			->method('setValueBool')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, true);
		$this->config->expects(self::never())->method('setValueString');

		$this->settings->setValue(AutoSyncSettings::KEY_ENABLED, true, $this->user());
	}

	public function testAnUncheckedBoxIsStoredAsFalseRatherThanLeftAlone(): void {
		// Turning the schedule OFF has to write something. Skipping the write on a
		// falsy value would leave a previous `true` in place and the pull running.
		$this->config->expects(self::once())
			->method('setValueBool')
			->with(Application::APP_ID, AutoSyncSettings::KEY_ENABLED, false);

		$this->settings->setValue(AutoSyncSettings::KEY_ENABLED, false, $this->user());
	}

	public function testAClearedIntervalFallsBackToTheDefault(): void {
		// An empty box must not persist as "" — ScheduleConfig would fall back
		// anyway, but storing the empty string makes the UI show a blank field
		// while the job runs hourly, which reads as broken.
		$this->config->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, AutoSyncSettings::KEY_INTERVAL, AutoSyncSettings::DEFAULT_INTERVAL);

		$this->settings->setValue(AutoSyncSettings::KEY_INTERVAL, '   ', $this->user());
	}

	public function testAnIntervalIsStoredTrimmedButNotValidated(): void {
		// Validation belongs to ScheduleConfig, which clamps and falls back. Doing
		// it here as well would put one rule in two places, free to disagree.
		$this->config->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, AutoSyncSettings::KEY_INTERVAL, 'banana');

		$this->settings->setValue(AutoSyncSettings::KEY_INTERVAL, '  banana  ', $this->user());
	}

	public function testAnUnknownFieldIsIgnoredRatherThanStored(): void {
		$this->config->expects(self::never())->method('setValueBool');
		$this->config->expects(self::never())->method('setValueString');

		$this->settings->setValue('not_a_field', 'whatever', $this->user());
		self::assertNull($this->settings->getValue('not_a_field', $this->user()));
	}

	/** @param array<string, mixed> $schema @return array<string, mixed> */
	private function field(array $schema, string $id): array {
		foreach ($schema['fields'] as $field) {
			if ($field['id'] === $id) {
				return $field;
			}
		}

		self::fail("the card declares no field '$id'");
	}

	private function user(): IUser {
		// Every handler ignores it — the settings are app-wide, not per-user — but
		// the interface requires one.
		return $this->createStub(IUser::class);
	}
}
