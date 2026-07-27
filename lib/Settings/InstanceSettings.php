<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PenpotClient;
use OCP\IAppConfig;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * The **Instance** card — the Penpot base URL and the service-account token, in
 * one place. Priority 5, the first card in the section, exactly as in both
 * sibling apps.
 *
 * ## ONE CARD, NOT TWO (revises an earlier split)
 *
 * An earlier cut of this app put the URL and the token on separate cards,
 * reasoning that Penpot has *two* credentials — a required service-account token
 * and an optional per-user one (saga §6.18) — so the URL belonged to neither.
 *
 * That was wrong in practice. The per-user token lives on a **personal**
 * settings page, not this one, so the admin section has exactly one credential,
 * exactly like `nextcloud-grafana`. The split produced an admin page shaped
 * unlike either sibling to express a distinction the admin never sees. All three
 * apps now open with the same card: *where is it, and how do we authenticate.*
 *
 * ## THE BLANK-FIELD PROBLEM, AND THE SIBLINGS' FIX
 *
 * A `sensitive` field renders **blank** even when a value is stored, because
 * core never echoes it back. So an admin cannot tell "no token yet" from "a
 * token is saved" by looking. The token's description and placeholder are
 * therefore rendered *dynamically* from whether one is currently stored — a
 * plain "is it set?" signal that does not depend on the framework showing
 * anything. Same trick both siblings use.
 *
 * Whether the stored token actually *works* is a different question, answered by
 * **Test connection** in the Sync Actions panel. Storing and working are
 * genuinely separate states: an unset token and a token rejected because the
 * instance lacks `enable-access-tokens` both look like "not connected", and they
 * need completely different fixes.
 *
 * ## TWO WRITE PATHS, ONE STORED SHAPE — verified against core, not assumed
 *
 * This card and {@see \OCA\PenpotSync\Command\SetToken} both write the same
 * appconfig key, so they must agree on the stored shape or one silently breaks
 * the other. Core's `DeclarativeManager` encrypts a `sensitive` field with
 * `ICrypto` on save and decrypts it on read, which is exactly what
 * {@see PenpotClient} expects — so the two paths ARE compatible.
 *
 * One asymmetry, deliberate and harmless: core persists with a plain
 * `setValueString()`, without appconfig's own `sensitive: true` flag, while
 * `SetToken` passes it. That flag only controls redaction in config *dumps* — it
 * is not encryption (proven live: `config:app:set --sensitive` stores and
 * returns plaintext). A token saved through this card is still encrypted and
 * still works; it just would not be redacted in `config:app:get` output until
 * the CLI rewrites it.
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
		$hasToken = $this->config->getValueString(Application::APP_ID, PenpotClient::KEY_TOKEN, '') !== '';

		// Deliberately says "stored", not "stored (encrypted)": this flag is
		// derived purely from the key being non-empty, which does not prove the
		// value is decryptable or usable. An instance secret rotation, or a token
		// written by some other route, both leave a non-empty value the app
		// cannot actually use. Test connection is what answers that — so the copy
		// claims only what it can see and points there for the rest.
		$tokenDescription = $hasToken
			? '✓ A service-account token is stored. Paste a new one to replace it, '
				. 'or use Test connection below to check it still works.'
			: 'No token stored yet. Create an access token on the Penpot service account '
				. '(Profile → Access tokens) and paste it here. The Penpot instance needs '
				. '`enable-access-tokens` — it is off by default.';

		$tokenPlaceholder = $hasToken
			? '•••••••••••••• — a token is stored (paste to replace)'
			: 'Paste the Penpot access token';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API, so
			// a prefixed id (penpot_sync_instance -> instance) fails the
			// backend's exact-match lookup — and for a SENSITIVE field the
			// consequence is worse than a lost value: it gets stored
			// unencrypted. Inherited verbatim from the siblings' hard-won note.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The Penpot instance this app is scoped to — its base URL and the '
				. 'service-account token used to reach it. That account only sees teams it has '
				. 'been invited to, so each team must invite it before it can be mapped.',
			'fields' => [
				[
					'id' => self::KEY_URL,
					'title' => 'Penpot base URL',
					'description' => 'e.g. https://penpot.example.com (no trailing slash). In-cluster URLs like http://penpot.cloud.svc.cluster.local:8080 also work.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://penpot.example.com',
					'default' => '',
				],
				[
					'id' => PenpotClient::KEY_TOKEN,
					'title' => 'Service-account token',
					'description' => $tokenDescription,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $tokenPlaceholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}

	/**
	 * Read the configured base URL, normalised (no trailing slash).
	 *
	 * Lives here rather than in a service because it is the only piece of state
	 * this class owns; the token is read by {@see PenpotClient}, which has to
	 * decrypt it anyway.
	 */
	public function getUrl(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, self::KEY_URL, ''), '/');
	}
}
