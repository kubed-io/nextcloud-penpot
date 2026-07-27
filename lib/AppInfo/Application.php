<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\AppInfo;

use OCA\PenpotSync\Settings\CredentialSettings;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCA\PenpotSync\Settings\ScheduleSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * App bootstrap.
 *
 * ## WHAT IS REGISTERED HERE, AND WHAT IS DELIBERATELY STILL ABSENT
 *
 * The admin surface is now **complete** (saga Ch2, Course 2): the instance URL,
 * the service-account credential, the scheduled-pull settings, the team-mapping
 * list, and a per-user personal token card. Every one of them persists,
 * round-trips, and has an `occ` twin.
 *
 * There is still **no sync engine**, no file actions, and no listeners. That is
 * the ordering the siblings earned the hard way and this app inherits: *finish
 * the room before lighting the stove.* Configuration that arrives after the
 * feature means every feature ships twice — once wired to nothing, once wired
 * for real — and the second pass is where the settings bugs live.
 *
 * The visible consequence is that some controls here configure something that
 * does not exist yet (the pull schedule, most obviously). Each one says so in
 * its own description rather than implying a sync that is not running.
 *
 * The background job, the resolver, the Files-app surface and the write paths
 * land in Courses 3–6. Don't scaffold them here ahead of the code that uses them.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'penpot_sync';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Admin cards, in the order they appear in the section. Their sidebar
		// entries come from AdminSection / PersonalSection, wired in
		// appinfo/info.xml's <settings> block.
		//
		// The team-mapping list is NOT here: declarative settings have no
		// array-of-objects field type, so it is a server-rendered
		// IDelegatedSettings panel instead — the same split both siblings use for
		// the same reason. See MappingSettings.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(CredentialSettings::class);
		$context->registerDeclarativeSettings(ScheduleSettings::class);

		// Per-user, attribution-only (saga §6.18). Registered the same way, but
		// core stores it per-uid because the form declares a PERSONAL section
		// type — see PersonalSettings.
		$context->registerDeclarativeSettings(PersonalSettings::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Nothing to boot. The metadata-key registration, the trash purge hook,
		// and the background job all belong to later courses.
	}
}
