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

namespace OCP {
	// InstanceSettings and SetUrl read/write the base URL through AppConfig.
	// Declaration-only: both tests mock it.
	if (!interface_exists(IAppConfig::class, false)) {
		interface IAppConfig {
			public function getValueString(string $app, string $key, string $default = '', bool $lazy = false, bool $sensitive = false): string;

			public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
		}
	}
	// AdminSection's constructor deps. Nothing asserts on them yet, but the class
	// must be loadable for a future AdminSection test.
	if (!interface_exists(IL10N::class, false)) {
		interface IL10N {
			public function t(string $text, array $parameters = []): string;
		}
	}
	if (!interface_exists(IURLGenerator::class, false)) {
		interface IURLGenerator {
			public function imagePath(string $appName, string $file): string;
		}
	}
}

namespace OCP\Security {
	// PenpotClient stores the service-account token encrypted at rest, and
	// SetToken writes it. Declaration-only: the unit suite mocks both directions
	// (a decrypt that throws is one of the states under test).
	if (!interface_exists(ICrypto::class, false)) {
		interface ICrypto {
			public function encrypt(string $input, string $password = ''): string;

			public function decrypt(string $input, string $password = ''): string;
		}
	}
}

namespace OCP\Http\Client {
	// PenpotClient issues every request through IClientService so that HTTP
	// proxying, `allow_local_address` and TLS settings stay consistent with the
	// rest of the platform. The unit suite never exercises the transport — that
	// is the integration suite's job against a real Penpot — so these exist only
	// so the class is constructible.
	if (!interface_exists(IResponse::class, false)) {
		interface IResponse {
			/**
			 * Upstream declares `string|resource`. `resource` is not a builtin
			 * type PHP accepts in a signature (it would be read as a class name),
			 * so the stub widens it to `mixed` and documents the real contract
			 * here. PenpotClient only ever casts the result to string.
			 *
			 * @return string|resource
			 */
			public function getBody(): mixed;

			public function getStatusCode(): int;

			public function getHeader(string $key): string;

			public function getHeaders(): array;
		}
	}
	if (!interface_exists(IClient::class, false)) {
		interface IClient {
			public function post(string $uri, array $options = []): IResponse;

			public function get(string $uri, array $options = []): IResponse;
		}
	}
	if (!interface_exists(IClientService::class, false)) {
		interface IClientService {
			public function newClient(): IClient;
		}
	}
	// Thrown when Nextcloud's own egress guard refuses a local address — common
	// in a homelab, where Penpot is reached in-cluster. PenpotClient catches it
	// specifically so the message can name `allow_local_remote_servers`.
	if (!class_exists(LocalServerException::class, false)) {
		class LocalServerException extends \Exception {
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
