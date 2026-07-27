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
 * Dedicated admin settings section, so the Instance card gets its own sidebar
 * entry instead of living under "Additional settings".
 *
 * Mechanically identical to both sibling apps' version — there is no
 * Penpot-specific content in this class at all, only the namespace and the icon
 * asset differ. Uses the `-dark` (black) app mark per Nextcloud convention; core
 * themes it to the sidebar colour.
 */
final class AdminSection implements IIconSection {
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
