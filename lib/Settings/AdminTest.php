<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * NOT a rendered panel — the `#[AuthorizedAdminSetting]` target for the
 * connection-test endpoint, and nothing else.
 *
 * Nextcloud gates admin REST endpoints by naming an `IDelegatedSettings` class,
 * so an endpoint that belongs to no visible panel still needs one to point at.
 * The **Test connection** button itself lives in {@see SyncSettings} (the Sync
 * Actions panel), with every other button.
 *
 * Deliberately identical to `nextcloud-grafana`'s class of the same name,
 * including this reason for existing. It is registered in `appinfo/info.xml`
 * only as far as the settings manager needs to resolve it — it is not listed as
 * an `<admin>` panel, so it renders nothing.
 */
final class AdminTest implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// Never rendered. Returning an empty blank-layout response keeps the
		// interface satisfied without inventing a panel.
		return new TemplateResponse(Application::APP_ID, 'admin_test', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 22;
	}

	#[\Override]
	public function getName(): ?string {
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// The test endpoint carries its own #[AuthorizedAdminSetting]; no
		// generic appconfig writes are delegated through this class.
		return [];
	}
}
