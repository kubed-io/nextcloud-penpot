<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\AppConfigReader;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;
use PHPUnit\Framework\TestCase;

/**
 * THE APP-WIDE RULE: no declarative form in this app may declare INTERNAL storage.
 *
 * ## WHY THIS IS A TEST, AND WHY IT COVERS EVERY FORM RATHER THAN THE TOGGLE'S
 *
 * The scheduled-sync toggle has been "fixed" several times. Every fix was made on
 * {@see AutoSyncSettings} — the card the checkbox is on — and the bug was never
 * there. It is in core's lookup:
 *
 *     DeclarativeManager::getStorageType($app, $fieldId)
 *       foreach ($this->appSchemas[$app] as $schema) {
 *           foreach ($schema['fields'] as $field) { ... per-field override ... }
 *           if (array_key_exists('storage_type', $schema)) {
 *               return $schema['storage_type'];   // <- FIRST schema wins, always
 *           }
 *       }
 *
 * The schema-level `return` sits in the OUTER loop, so the first registered form
 * that declares a `storage_type` answers for **every field in the app**, whichever
 * card actually owns it. {@see InstanceSettings} registers first and said
 * INTERNAL, so the checkbox's handlers were never called at all: core's internal
 * path read it with `IConfig::getAppValue($app, $key, false)` and wrote it with
 * `IAppConfig::setValueString($app, $key, true)` — both typed `string`, both a
 * TypeError under `strict_types=1`. The toggle sprang back to off and read back
 * off forever, while `occ`, which writes a string, set the same key happily.
 *
 * So asserting EXTERNAL on the checkbox's own card proves nothing: nothing asks
 * it. The invariant that holds the behaviour up is app-wide, and that is what
 * this asserts. `nextcloud-grafana` reached the same conclusion first, and every
 * one of its forms is EXTERNAL.
 *
 * ## AND IT ENUMERATES THE DIRECTORY, DELIBERATELY
 *
 * A hand-written list of three classes would pass forever while a fourth form
 * added later quietly re-breaks the toggle — and it would break it in the card
 * nobody touched, which is exactly the diagnosis loop this file exists to end. So
 * the forms are discovered from `lib/Settings/`, and an unknown one fails loudly
 * rather than being skipped.
 */
final class DeclarativeStorageTypeTest extends TestCase {
	public function testNoFormDeclaresInternalStorage(): void {
		foreach ($this->forms() as $class => $form) {
			self::assertSame(
				DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
				$form->getSchema()['storage_type'] ?? null,
				$class . ' declares INTERNAL storage. Core answers getStorageType() from the FIRST '
				. 'registered form, so this one would answer for every other card in the app — '
				. 'including the scheduled-sync checkbox, whose bool cannot survive the internal path.',
			);
		}
	}

	/**
	 * A form declaring EXTERNAL and NOT implementing the handler interface is
	 * silently read-only: core falls through to a `DeclarativeSettingsGetValueEvent`
	 * nothing listens for, so the card renders empty and saves nothing.
	 *
	 * That is the trap the rule above walks straight into — "just change INTERNAL to
	 * EXTERNAL" is half a fix — so the two are asserted together.
	 */
	public function testEveryFormCanActuallyHandleItsOwnStorage(): void {
		foreach ($this->forms() as $class => $form) {
			self::assertInstanceOf(
				IDeclarativeSettingsFormWithHandlers::class,
				$form,
				$class . ' declares EXTERNAL storage but implements no handlers, so core has '
				. 'nowhere to read or write its fields.',
			);
		}
	}

	/**
	 * Every card the app registers, discovered rather than listed.
	 *
	 * Each takes different collaborators and none of them are exercised here —
	 * `getSchema()` is a declaration — so everything is a stub.
	 *
	 * ## FILTERED ON THE SOURCE TEXT, NOT ON `is_a()`
	 *
	 * `lib/Settings/` also holds the CLASSIC panels (`AdminTest`, `MappingSettings`,
	 * `SyncSettings`) and the two sections. `is_a($class, …, true)` autoloads every
	 * one of them to answer, and `AdminTest` implements `OCP\Settings\IDelegatedSettings`,
	 * which `nextcloud/ocp` does not ship — so the reflection fatals before any
	 * assertion runs. Reading the file first is not a shortcut around that: a
	 * declarative form has to NAME the interface to implement it, so the text is a
	 * sound filter, and it keeps this test from loading classes it has no business
	 * touching.
	 *
	 * @return array<string, IDeclarativeSettingsForm>
	 */
	private function forms(): array {
		$config = $this->createStub(IAppConfig::class);

		$built = [];
		foreach (glob(__DIR__ . '/../../lib/Settings/*.php') ?: [] as $path) {
			if (!str_contains((string)file_get_contents($path), 'IDeclarativeSettingsForm')) {
				continue;
			}

			/** @var class-string<IDeclarativeSettingsForm> $class */
			$class = 'OCA\\PenpotSync\\Settings\\' . basename($path, '.php');

			$built[$class] = match ($class) {
				InstanceSettings::class => new InstanceSettings($config, $this->createStub(ICrypto::class)),
				PersonalSettings::class => new PersonalSettings($this->createStub(PersonalTokenService::class)),
				AutoSyncSettings::class => new AutoSyncSettings($config, new AppConfigReader($config)),
				default => self::fail(
					"{$class} is a declarative settings form this test does not know how to build. "
					. 'Add it below — the storage-type rule is app-wide and has to cover it.',
				),
			};
		}

		self::assertNotEmpty($built, 'no declarative settings forms were discovered — has the path moved?');

		return $built;
	}
}
