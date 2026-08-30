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
use OCP\Util;

/**
 * "Sync Actions" — the single home for every action button in the section,
 * rendered last. Kept parallel to both sibling apps' panel of the same name.
 *
 * ## THE HOUSE RULE THIS IMPLEMENTS
 *
 * Nextcloud's declarative settings cannot host buttons, and giving each button
 * its own panel beside its data card turns the section into a stack of thin
 * strips. So the rule, settled on the n8n master and inherited by
 * `nextcloud-grafana`: **one classic panel for every button, rendered last**.
 *
 *   Instance (5) → Sync Settings (20) → Team mappings (30) → Sync Actions (45)
 *
 * That ordering is the same in all three apps, so an admin who has configured
 * one already knows where to look in the others.
 *
 * ## HONEST BUTTONS (saga Ch2)
 *
 * **Every button in this panel works.** It used to hold one that did not: "Purge
 * Nextcloud files", rendered `disabled`, waiting on a delete machine the spec has
 * since decided never to build (saga/Chapter_3_Building_To_Plan.md#retired--the-admin-purge). The
 * present-but-disabled argument is sound only while somebody still means to enable
 * the thing — the sync button earned it and went live. Once the feature is
 * cancelled the same button is just a promise nobody is keeping, so it is gone
 * rather than greyed out.
 *
 * ## THIS PARAGRAPH USED TO DENY A BUTTON THIS PANEL RENDERS
 *
 * It said there was "deliberately no 'Sync to Penpot' button, and there never
 * will be", on the grounds that the app is read-only for file content. Both
 * halves went stale: `templates/sync_settings.php` renders that button, `js/`
 * drives it, {@see \OCA\PenpotSync\Command\Sync} is its CLI twin, and §6.1 never
 * said read-only — what it locks is that this app never overwrites the CONTENT of
 * a design Penpot already has, which is a much narrower claim than "no push".
 *
 * `features/README.md` names this exact sentence as its cautionary tale about
 * decisions that outlive their reasons, which is why it is corrected here in
 * place rather than quietly deleted.
 */
final class SyncSettings implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// Loaded via Util so they pick up the CSP nonce — inline <script>/<style>
		// in templates is blocked by Nextcloud's strict CSP.
		Util::addStyle(Application::APP_ID, 'sync-settings');
		// THE SHARED DIALOG HELPER FIRST, and on every admin section rather than
		// only the one that has a modal today. `js/` is unbundled, so this is a
		// `window` global and the page script needs it defined by the time a button
		// is pressed — registering it here means a modal added to this section
		// later is the same modal as everywhere else, with no second answer to "are
		// you sure" ever getting written.
		Util::addScript(Application::APP_ID, 'dialogs');
		Util::addScript(Application::APP_ID, 'sync-settings');

		return new TemplateResponse(Application::APP_ID, 'sync_settings', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 45 — the **last** panel, below Team mappings (30), so every action
	 * button sits beneath the data it acts on. Matches both siblings.
	 */
	#[\Override]
	public function getPriority(): int {
		return 45;
	}

	#[\Override]
	public function getName(): ?string {
		// The heading is rendered inside the template.
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Buttons hit controllers gated by their own #[AuthorizedAdminSetting].
		return [];
	}
}
