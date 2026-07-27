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
 * The **service-account token** card — the app's one required credential.
 *
 * ## WHY THIS IS A SEPARATE CARD FROM THE URL (saga §6.11/§6.18, locked)
 *
 * Grafana bundles URL + token because it has exactly one credential. Penpot has
 * **two**, owned by different people doing different jobs: this required
 * service-account token, which performs every read; and an optional per-user
 * token that only attributes writes. The URL belongs to neither, so it keeps its
 * own card and this one holds the credential that is genuinely instance-wide.
 *
 * ## WHY A SERVICE ACCOUNT AT ALL (saga §6.16 found it, §6.18 acted on it)
 *
 * Penpot has no admin API and no service-account concept — every token belongs
 * to a personal account (§6.8). So "service account" here means an ordinary
 * Penpot account created for the purpose. It is required because the alternative
 * is a per-user pull, and two Nextcloud users in the same Penpot team would
 * resolve to the SAME Team Folder — two uncoordinated jobs writing one mirror.
 * That is a data race, not an inefficiency. One puller, one credential.
 *
 * ## THE BLANK-FIELD PROBLEM, AND THE FIX BOTH SIBLINGS ALREADY FOUND
 *
 * A `sensitive` field renders **blank** even when a value is stored, because
 * core never echoes it back. So the admin cannot tell "no token yet" from "a
 * token is saved" by looking. The description and placeholder are therefore
 * rendered *dynamically* from whether a token is currently stored — a plain
 * "is it set?" signal that does not depend on the framework showing anything.
 *
 * Whether the stored token actually *works* is a different question, answered by
 * Test connection / `occ penpot_sync:test-connection`. Storing and working are
 * genuinely separate states here: an unset token and a token rejected because
 * the instance lacks `enable-access-tokens` both look like "not connected", and
 * they need completely different fixes.
 *
 * ## TWO WRITE PATHS, ONE STORED SHAPE — verified against core, not assumed
 *
 * This card and {@see \OCA\PenpotSync\Command\SetToken} both write the same
 * appconfig key, so they must agree on the stored shape or one silently breaks
 * the other. Core's `DeclarativeManager` encrypts a `sensitive` field with
 * `ICrypto` on save and decrypts it on read, which is exactly what
 * {@see PenpotClient::getToken()} expects — so the two paths ARE compatible.
 *
 * One asymmetry, deliberate and harmless: core persists with a plain
 * `setValueString()`, without appconfig's own `sensitive: true` flag, while
 * `SetToken` passes it. That flag only controls redaction in config *dumps* — it
 * is not encryption (proven live: `config:app:set --sensitive` stores and
 * returns plaintext). So a token saved through this card is still encrypted and
 * still works; it just would not be redacted in `config:app:get` output until
 * the CLI rewrites it. Worth knowing before "fixing" either path to match.
 */
final class CredentialSettings implements IDeclarativeSettingsForm {
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		$hasToken = $this->config->getValueString(Application::APP_ID, PenpotClient::KEY_TOKEN, '') !== '';

		$description = $hasToken
			? '✓ A service-account token is stored (encrypted). Paste a new one to replace it, '
				. 'or run `occ penpot_sync:test-connection` to check it still works.'
			: 'No token stored yet. Create a Penpot access token on the service account '
				. '(Profile → Access tokens) and paste it here. The Penpot instance needs '
				. '`enable-access-tokens` — it is off by default.';

		$placeholder = $hasToken
			? '•••••••••••••• — a token is stored (paste to replace)'
			: 'Paste the Penpot access token';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API, so
			// a prefixed id fails the backend's exact-match lookup — and for a
			// SENSITIVE field the consequence is worse than a lost value: it
			// gets stored unencrypted. Inherited verbatim from the siblings.
			'id' => 'credentials',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Service account',
			'description' => 'The Penpot account this app reads as. It only sees teams it has been '
				. 'invited to — Penpot has no instance-wide view — so each team must invite it '
				. 'before that team can be mapped.',
			'fields' => [
				[
					'id' => PenpotClient::KEY_TOKEN,
					'title' => 'Penpot access token',
					'description' => $description,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $placeholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
