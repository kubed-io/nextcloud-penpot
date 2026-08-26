<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The per-user personal Penpot token — genuinely new territory for this app
 * family. Neither sibling has a per-user credential: both store one admin-wide
 * key and only read `IUserSession` inside listeners to know who is acting.
 *
 * ## WHAT THIS PAGE IS FOR, PRECISELY (saga §6.18): ATTRIBUTION. NOTHING ELSE.
 *
 * It is NOT how the app reads Penpot — the service account does every read,
 * always. It is NOT required for anything to work. It exists so that when a
 * human renames or restores a design from Nextcloud, Penpot's history records
 * **that human** rather than recording every action by every user as
 * "nextcloud" forever. Penpot's file history is append-only from our side: a
 * change attributed to a robot can never be re-attributed later. That is the
 * entire case for this page, and it is a good one.
 *
 * ## WHY IT STAYS OPTIONAL
 *
 * The complete set of writes this app can perform is short (§6.19): rename,
 * move, create, restore, delete. Requiring a personal token before a user could
 * rename a file would be a terrible first-run experience for zero functional
 * gain — the rename works fine as the service account, it is just attributed
 * less precisely. Optional is the honest shape.
 *
 * ## STORAGE — USER-SCOPED, AND HANDLED HERE RATHER THAN BY CORE
 *
 * `SECTION_TYPE_PERSONAL` + `STORAGE_TYPE_INTERNAL` used to make core persist to
 * `getUserValue`/`setUserValue` under the acting user's uid, encrypting the
 * `sensitive` value with `ICrypto`. {@see PersonalTokenService} already does
 * exactly that for the `occ` twin, so the handlers below simply call it and the
 * two write paths stay identical on disk.
 *
 * **The reason it moved is not about this card.** A form declaring INTERNAL
 * answers `getStorageType()` for every OTHER form in the app as well — core's
 * lookup returns from the outer loop, so the first schema wins whatever field was
 * asked about. {@see InstanceSettings} carries that finding in full; it is what
 * broke {@see AutoSyncSettings}'s checkbox twice. This card is EXTERNAL so it can
 * never become the next one to answer for somebody else's field, whatever order
 * the forms happen to register in.
 *
 * One Nextcloud user's token still cannot leak into another's read: the uid is
 * part of the storage key, and the handlers are handed the acting `IUser`.
 *
 * ## THE 1:1 ASSUMPTION (saga §6.9), STATED NOT ENFORCED
 *
 * One Nextcloud user, one Penpot account, one token. Nothing stops two users
 * pasting the same token — it just defeats the page's only purpose, since both
 * users' changes would then be attributed identically. Documented in the
 * description rather than blocked, because enforcing it would mean the app
 * inspecting whose account a token belongs to, which is a real API call on every
 * save for a misconfiguration nobody makes by accident.
 */
final class PersonalSettings implements IDeclarativeSettingsFormWithHandlers {
	/** User-scoped config key holding that user's personal Penpot token. */
	public const KEY_PERSONAL_TOKEN = 'personal_token';

	public function __construct(
		private readonly PersonalTokenService $tokens,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			// No app-id prefix — same frontend-strips-the-prefix gotcha as the
			// admin cards, with the same sensitive-field consequence.
			'id' => 'personal',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			'section_id' => Application::APP_ID,
			// EXTERNAL, and never INTERNAL — see the class docblock. Nothing about
			// this card needs handlers; a form that declares INTERNAL answers for
			// every other form in the app, which is what kept breaking the
			// scheduled-sync checkbox.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Penpot',
			'description' => 'Optional. Your own Penpot access token, used only to attribute changes '
				. 'you make from Nextcloud to you in Penpot\'s history — instead of to the shared '
				. 'service account. Everything works without it; mirroring never uses your token. '
				. 'This assumes one Penpot account per Nextcloud user.',
			'fields' => [
				[
					'id' => self::KEY_PERSONAL_TOKEN,
					'title' => 'Your Penpot access token',
					'description' => 'Create one in Penpot under Profile → Access tokens. '
						. 'Stored encrypted, visible only to you, and never shown again after saving. '
						. 'Clear the field and save to remove it — your mirrored files keep working.',
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => 'Paste your personal Penpot access token',
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}

	/**
	 * NEVER ECHOED BACK, which is what core's internal path did for a `sensitive`
	 * field and what the field's own copy promises ("never shown again after
	 * saving"). Handing the decrypted token to the browser would put a live
	 * credential in a response for a box the user cannot read anyway.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return $fieldId === self::KEY_PERSONAL_TOKEN ? '' : null;
	}

	/**
	 * AN EMPTY SUBMISSION CLEARS IT, and that is the documented behaviour of this
	 * card rather than an oversight — the field says *"clear the field and save to
	 * remove it"*, and it is the only field here, so an empty post cannot be the
	 * side effect of editing something else. {@see InstanceSettings} takes the
	 * opposite decision for the admin token, which shares its card with the URL.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		if ($fieldId !== self::KEY_PERSONAL_TOKEN) {
			return;
		}

		$token = is_string($value) ? trim($value) : '';
		if ($token === '') {
			$this->tokens->clearToken($user->getUID());

			return;
		}

		// Through the service, so the card and `occ penpot_sync:set-personal-token`
		// write the same encrypted shape under the same user-scoped key.
		$this->tokens->setToken($user->getUID(), $token);
	}
}
