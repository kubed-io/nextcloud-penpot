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
final class AutoSyncSettings implements IDeclarativeSettingsForm {
	/** AppConfig key: whether the scheduled pull is enabled. */
	public const KEY_ENABLED = 'schedule_enabled';

	/** AppConfig key: how often to pull, as a duration string. */
	public const KEY_INTERVAL = 'schedule_interval';

	#[\Override]
	public function getSchema(): array {
		return [
			// Same id-prefix gotcha as the other cards — no app-id prefix.
			'id' => 'data_sync',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Sync Settings',
			'description' => 'How often Nextcloud mirrors mapped Penpot teams. The pull is read-only — '
				. 'it never changes anything in Penpot. Saved here now; the background job that '
				. 'reads these values is not built yet, so nothing is mirrored regardless of this setting.',
			'fields' => [
				[
					'id' => self::KEY_ENABLED,
					'title' => 'Pull from Penpot on a schedule',
					'description' => 'When on, Nextcloud periodically refreshes mirrored files from Penpot.',
					// RADIO, not CHECKBOX — and this is a bug workaround, not a
					// style choice.
					//
					// A declarative CHECKBOX sends a real PHP bool, and core's
					// DeclarativeManager::saveInternalValue() hands that straight
					// to IAppConfig::setValueString(), which is typed `string`:
					//
					//   TypeError: setValueString(): Argument #3 ($value) must be
					//   of type string, true given
					//
					// The save aborts, nothing persists, and the toggle springs
					// back to its default on the next page load with no error the
					// admin can see. Reproduced on this NC 33 instance by driving
					// the manager directly — and BOTH sibling apps have the same
					// bug: n8n_sync's stored value came from `occ`, not from its
					// toggle, and grafana_sync's key is simply unset.
					//
					// A RADIO sends a string, which survives that path — verified
					// against n8n's own `timing` radio, which round-trips fine.
					// Same two choices for the admin, one that actually saves.
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => 'no',
					'options' => [
						['name' => 'Off — mirror only when run manually', 'value' => 'no'],
						['name' => 'On — pull from Penpot automatically', 'value' => 'yes'],
					],
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
