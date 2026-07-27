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
 * The scheduled-pull card: whether the pull runs on a timer, and how often.
 *
 * ## PERSISTED NOW, HONOURED IN COURSE 3
 *
 * The background job does not exist yet. That is deliberate and it is the whole
 * point of this course (saga Ch2, Course 2): **finish the room before lighting
 * the stove.** Configuration that arrives after the feature means every feature
 * ships twice — once wired to nothing, once wired for real — and the second
 * pass is where the settings bugs live.
 *
 * So these values round-trip and are readable by `occ` today; the job reads them
 * unchanged when it lands. The description says so plainly rather than implying
 * a sync that is not running — an admin who flips this on and sees nothing
 * happen should be told why, in the UI, at the moment they do it.
 *
 * ## CRON IS THE ONLY TRIGGER, AND THAT IS A FINDING (saga §6.17, #19)
 *
 * Penpot HAS webhooks, and creating one works. **Delivery has never been
 * observed** — two confirmed mutations produced zero POSTs. Until that is
 * explained there is no webhook card and no event-driven path, because shipping
 * a "real-time sync" toggle backed by a delivery mechanism we have never seen
 * fire would be a lie in a checkbox. The interval below is the sole trigger.
 *
 * Nextcloud schedules by interval (`TimedJob`), not cron expressions — hence a
 * duration rather than a crontab line.
 */
final class ScheduleSettings implements IDeclarativeSettingsForm {
	/** AppConfig key: whether the scheduled pull is enabled. */
	public const KEY_ENABLED = 'schedule_enabled';

	/** AppConfig key: how often to pull, as a duration string. */
	public const KEY_INTERVAL = 'schedule_interval';

	#[\Override]
	public function getSchema(): array {
		return [
			// Same id-prefix gotcha as the other cards — no app-id prefix.
			'id' => 'schedule',
			'priority' => 30,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Scheduled pull',
			'description' => 'How often Nextcloud mirrors mapped Penpot teams. The pull is read-only — '
				. 'it never changes anything in Penpot. Saved here now; the background job that '
				. 'reads these values is not built yet, so nothing is mirrored regardless of this setting.',
			'fields' => [
				[
					'id' => self::KEY_ENABLED,
					'title' => 'Pull from Penpot on a schedule',
					'description' => 'When on, Nextcloud periodically refreshes mirrored files from Penpot.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// A CHECKBOX default MUST be a real bool. Core's
					// DeclarativeManager does no type coercion, so a string '0'
					// breaks the frontend's boolean round-trip and the toggle
					// silently never persists — it reads as off forever. The n8n
					// sibling hit exactly this; core's own apps use real bools.
					'default' => false,
				],
				[
					'id' => self::KEY_INTERVAL,
					'title' => 'How often',
					'description' => 'A number plus a unit (s/m/h/d) — for example 15m, 1h, 6h, 1d. '
						. 'A plain number means seconds. Minimum 5m: a Penpot pull costs one '
						. 'request per team plus one per project, and anything faster spends '
						. 'requests without catching meaningfully fresher designs.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '1h',
					'default' => '1h',
				],
			],
		];
	}
}
