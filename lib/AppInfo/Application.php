<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\AppInfo;

use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * App bootstrap — deliberately the smallest thing that can work.
 *
 * This is the first slice of a much larger design (see `saga/` and `features/`).
 * Right now it registers exactly ONE thing: the admin **Instance** card holding
 * the Penpot base URL. No credential storage, no sync engine, no file actions,
 * no listeners.
 *
 * Why the URL alone, and why first: it's the one setting every version of this
 * app needs regardless of how the still-evolving pieces resolve (saga §6.11,
 * locked). Modelled on the n8n master's URL-only `InstanceSettings` rather than
 * Grafana's bundled URL+token card, because Penpot's credential model has *two*
 * credentials with different owners and different jobs (saga §6.18) — so the URL
 * belongs to neither card and gets its own.
 *
 * Everything else in the design — the service-account token, per-user tokens,
 * mappings, the pull job, file actions — lands in later slices. Don't scaffold
 * them here ahead of the code that uses them.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'penpot_sync';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// The only surface this slice ships: a declarative admin card with the
		// Penpot base URL. Its sidebar entry comes from AdminSection, wired in
		// appinfo/info.xml's <settings> block.
		$context->registerDeclarativeSettings(InstanceSettings::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Nothing to boot yet. The metadata-key registration, the trash purge
		// hook, and the background job all belong to later slices.
	}
}
