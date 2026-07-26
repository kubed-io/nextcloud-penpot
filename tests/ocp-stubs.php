<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Declaration-only stubs for the handful of OCP symbols the unit suite needs.
 *
 * `nextcloud/ocp` ships the interfaces but has no autoload block, so base
 * symbols don't resolve when running standalone. Rather than stub all of OCP,
 * this file covers **exactly what this slice's classes reference** and nothing
 * more — it should grow one entry at a time as real code lands, so it stays a
 * readable record of the app's actual surface rather than a dumping ground.
 *
 * Every declaration is guarded by an `*_exists(..., false)` check so that if the
 * real symbol is ever autoloadable (a full server tree, a future ocp release),
 * the real one wins and these become inert.
 */

namespace OCP\AppFramework {
	if (!class_exists(App::class, false)) {
		class App {
			public function __construct(string $appName, array $urlParams = []) {
			}
		}
	}
}

namespace OCP\AppFramework\Bootstrap {
	if (!interface_exists(IBootstrap::class, false)) {
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;

			public function boot(IBootContext $context): void;
		}
	}
	if (!interface_exists(IRegistrationContext::class, false)) {
		interface IRegistrationContext {
		}
	}
	if (!interface_exists(IBootContext::class, false)) {
		interface IBootContext {
		}
	}
}

namespace OCP\Settings {
	// InstanceSettings implements IDeclarativeSettingsForm and reads
	// DeclarativeSettingsTypes constants. Declaration-only: the constant *values*
	// are irrelevant to the assertions (they check the schema's id/type/default),
	// so any stable strings suffice.
	if (!interface_exists(IDeclarativeSettingsForm::class, false)) {
		interface IDeclarativeSettingsForm {
			public function getSchema(): array;
		}
	}
	if (!class_exists(DeclarativeSettingsTypes::class, false)) {
		final class DeclarativeSettingsTypes {
			public const SECTION_TYPE_ADMIN = 'admin';
			public const STORAGE_TYPE_INTERNAL = 'internal';
			public const TEXT = 'text';
			public const URL = 'url';
		}
	}
	// AdminSection implements IIconSection (which extends ISection upstream —
	// flattened here since the split doesn't matter to the tests).
	if (!interface_exists(IIconSection::class, false)) {
		interface IIconSection {
			public function getID(): string;

			public function getName(): string;

			public function getPriority(): int;

			public function getIcon(): string;
		}
	}
}
