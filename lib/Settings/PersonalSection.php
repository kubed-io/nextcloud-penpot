<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Sidebar entry for the personal Penpot settings, so the per-user token card
 * gets its own place instead of landing under "Additional settings".
 *
 * Structurally identical to {@see AdminSection} — the only differences are the
 * section type it is registered under (declared in `appinfo/info.xml`) and the
 * priority. Neither sibling app has one of these at all, since neither has any
 * per-user credential.
 */
final class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Penpot');
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
