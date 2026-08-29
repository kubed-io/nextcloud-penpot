<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PenpotClient;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

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
 *
 * ## WHY THIS CARD HANDLES ITS OWN STORAGE, THOUGH IT HAS NOTHING THAT NEEDS IT
 *
 * A URL and a token are strings, so `STORAGE_TYPE_INTERNAL` worked perfectly —
 * for THIS card. It was poisoning the other one.
 *
 * `DeclarativeManager::getStorageType($app, $fieldId)` answers with the FIRST
 * schema registered for the app that declares a `storage_type`, whatever field
 * was asked about — the schema-level `return` sits in the OUTER loop, so it never
 * reaches the schema that actually owns the field:
 *
 *     foreach ($this->appSchemas[$app] as $schema) {
 *         foreach ($schema['fields'] as $field) { ... per-field override ... }
 *         if (array_key_exists('storage_type', $schema)) {
 *             return $schema['storage_type'];   // <- first schema wins, always
 *         }
 *     }
 *
 * This card registers first, so its INTERNAL answer came back for every field in
 * the app — including {@see AutoSyncSettings}'s checkbox, whose handlers were
 * therefore never called. Core's internal path then did both halves in `string`:
 * `IConfig::getAppValue($app, $key, false)` on the read (the schema default is a
 * real `bool`) and `IAppConfig::setValueString($app, $key, true)` on the write.
 * Under `strict_types=1` both are a TypeError, which is precisely the reported
 * symptom — the toggle springs back to off and reads back off forever, while
 * `occ` sets the same key without trouble.
 *
 * **AutoSyncSettings declaring EXTERNAL could never have helped, because nothing
 * ever asked it.** The fix has to be that NO form in this app declares INTERNAL,
 * since any one that does answers for all the others. Dispatch is unaffected: the
 * EXTERNAL branch resolves the form by `formId`, so each card still gets its own
 * values. The per-field override is not a way out either — it is only reached
 * while iterating the schema that holds the field, and the first schema returns
 * before that — and the key cannot simply be dropped, because `validateSchema()`
 * requires it and discards the whole form without it.
 *
 * `nextcloud-grafana` found this first and its `InstanceSettings` carries the same
 * note; this app kept INTERNAL and kept the bug. That is the whole difference.
 */
final class InstanceSettings implements IDeclarativeSettingsFormWithHandlers {
	/** AppConfig key holding the Penpot base URL. Shared with the CLI commands. */
	public const KEY_URL = 'penpot_url';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly ICrypto $crypto,
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
			? '✓ A token is stored. Paste a new one to replace it, or use Test connection to check it still works.'
			: 'No token stored yet. Create one in Penpot under Profile → Access tokens (the instance needs enable-access-tokens).';

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
			// EXTERNAL so it cannot answer INTERNAL for another card's checkbox —
			// see the class docblock. The handlers below do exactly what core's
			// internal path did: a plain string for the URL, ICrypto for the token.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Instance',
			'description' => 'The Penpot instance this app talks to, and the service-account token it authenticates with.',
			'fields' => [
				[
					'id' => self::KEY_URL,
					'title' => 'Penpot base URL',
					'description' => 'No trailing slash.',
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
	 * Read one field for the settings UI.
	 *
	 * THE TOKEN IS NEVER ECHOED BACK, which is what core's internal path did for a
	 * `sensitive` field and what this card's copy promises. Returning the decrypted
	 * value would put a live credential in an HTML response for a field the admin
	 * cannot even see.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::KEY_URL => $this->config->getValueString(Application::APP_ID, self::KEY_URL, ''),
			PenpotClient::KEY_TOKEN => '',
			default => null,
		};
	}

	/**
	 * Persist one field.
	 *
	 * The token is encrypted HERE because EXTERNAL storage means core no longer
	 * does it — the same `ICrypto` round trip {@see \OCA\PenpotSync\Command\SetToken}
	 * performs, so a token set from this panel and one set from `occ` are identical
	 * on disk and {@see PenpotClient} decrypts either.
	 *
	 * AN EMPTY SUBMISSION LEAVES THE STORED TOKEN ALONE. The field renders blank on
	 * every page load (see {@see getValue()}), so saving the card after editing only
	 * the URL posts an empty token — and reading that as "clear it" would delete a
	 * working credential as a side effect of an unrelated edit.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::KEY_URL:
				// Normalised on the way in, exactly as {@see getUrl()} does on the way
				// out, so the two can never disagree about a trailing slash.
				$url = is_string($value) ? rtrim(trim($value), '/') : '';
				$this->config->setValueString(Application::APP_ID, self::KEY_URL, $url);
				break;
			case PenpotClient::KEY_TOKEN:
				$token = is_string($value) ? trim($value) : '';
				if ($token === '') {
					return;
				}
				$this->config->setValueString(
					Application::APP_ID,
					PenpotClient::KEY_TOKEN,
					$this->crypto->encrypt($token),
				);
				break;
		}
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
