<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

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
 * ## STORAGE (open question #9, now answered)
 *
 * `SECTION_TYPE_PERSONAL` + `STORAGE_TYPE_INTERNAL` makes core persist to
 * `getUserValue`/`setUserValue` under the acting user's uid — verified in core's
 * `DeclarativeManager::getInternalValue()`, which branches on section type. With
 * `sensitive: true` the value is `ICrypto`-encrypted exactly as the admin
 * credential is. So one Nextcloud user's token cannot leak into another's read:
 * the uid is part of the storage key, not something this class has to enforce.
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
final class PersonalSettings implements IDeclarativeSettingsForm {
	/** User-scoped config key holding that user's personal Penpot token. */
	public const KEY_PERSONAL_TOKEN = 'personal_token';

	#[\Override]
	public function getSchema(): array {
		return [
			// No app-id prefix — same frontend-strips-the-prefix gotcha as the
			// admin cards, with the same sensitive-field consequence.
			'id' => 'personal',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
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
}
