<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * The Penpot **instance** card — the base URL, and nothing else.
 *
 * URL-ONLY, DELIBERATELY (saga §6.11, locked). The two sibling apps solve this
 * differently and for stated reasons: Grafana bundles URL + token in one card
 * because it has exactly one API and one credential; n8n splits them because its
 * URL scopes multiple credential channels. Penpot lands on n8n's shape for a
 * third reason — its access tokens are personal-account-scoped with no service
 * account concept (saga §6.8), so the credential model ends up with **two**
 * credentials owned by different people doing different jobs (saga §6.18: a
 * required service-account token that does all mirroring, and an optional
 * per-user token that only attributes writes). The URL belongs to neither, so it
 * gets its own card.
 *
 * Those credential cards are NOT in this slice. This is the minimal base: point
 * the app at a Penpot instance, over the CLI or this form, and stop.
 *
 * Values land in appconfig under app `penpot_sync`. Nothing here is sensitive —
 * a base URL is not a secret — so no encryption or blank-on-reload handling is
 * needed, unlike the token fields the siblings have on their instance cards.
 */
final class InstanceSettings implements IDeclarativeSettingsForm {
	/** AppConfig key holding the Penpot base URL. Shared with the CLI commands. */
	public const KEY_URL = 'penpot_url';

	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API, so
			// a prefixed id (penpot_sync_instance -> instance) fails the
			// backend's exact-match lookup. A clean id keeps both sides in
			// agreement. (Inherited verbatim from the siblings' hard-won note.)
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The Penpot instance this app is scoped to. Credentials are configured separately — see the project docs.',
			'fields' => [
				[
					'id' => self::KEY_URL,
					'title' => 'Penpot base URL',
					'description' => 'e.g. https://penpot.example.com (no trailing slash). In-cluster URLs like http://penpot.cloud.svc.cluster.local:8080 also work.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://penpot.example.com',
					'default' => '',
				],
			],
		];
	}

	/**
	 * Read the configured base URL, normalised (no trailing slash).
	 *
	 * Lives here rather than in a service because it is the only piece of state
	 * this slice has; when the client lands it will move to a proper service.
	 */
	public function getUrl(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, self::KEY_URL, ''), '/');
	}
}
