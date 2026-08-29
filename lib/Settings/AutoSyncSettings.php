<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\AppConfigReader;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

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
 *
 * ## WHY THIS FORM HANDLES ITS OWN STORAGE, AND WHY THE RADIO IS GONE
 *
 * The toggle used to be a RADIO with `yes`/`no` options. That was a workaround for
 * a real core bug, and the diagnosis was right as far as it went:
 * `DeclarativeManager::saveInternalValue()` hands an admin form's value straight to
 * `IAppConfig::setValueString()`, so the real `bool` a CHECKBOX posts raises a
 * TypeError, the save aborts, and the toggle springs back with nothing shown to the
 * admin. What the diagnosis MISSED is the second half, which both siblings found
 * afterwards: `getInternalValue()` passes the schema's `default` into
 * `IConfig::getAppValue()`, also typed `string`, so a `'default' => false` throws on
 * the way back OUT. Both spellings are broken, in opposite directions — and a radio
 * dodges both by never being a bool at all.
 *
 * So the radio worked, and the price was a settings panel that asked a yes/no
 * question with two fat radio buttons where every other app in the family — and
 * every other checkbox in Nextcloud — has a switch.
 *
 * `STORAGE_TYPE_EXTERNAL` + {@see IDeclarativeSettingsFormWithHandlers} is the fix
 * the siblings converged on: core calls {@see getValue}/{@see setValue} on this
 * object directly and NEVER touches the two typed core methods above, so the bug is
 * not worked around, it is stepped past. No listener class and no event wiring —
 * `DeclarativeManager::getValue()` prefers the interface and only falls back to
 * `DeclarativeSettingsGetValueEvent` for forms that do not implement it.
 *
 * THE KEY AND ITS PLACE ARE UNCHANGED. `occ config:app:set penpot_sync
 * schedule_enabled --value=1` still does exactly what it did, which is what the
 * integration suite has always used. Only who does the read and the write moves,
 * and {@see AppConfigReader} makes the read tolerate every spelling the key has
 * ever held — including the `yes` this app itself wrote all through the radio era.
 *
 * The interface is `@since 31.0.0`; `appinfo/info.xml` already requires more.
 */
final class AutoSyncSettings implements IDeclarativeSettingsFormWithHandlers {
	/** AppConfig key: whether the scheduled pull is enabled. */
	public const KEY_ENABLED = 'schedule_enabled';

	/** AppConfig key: how often to pull, as a duration string. */
	public const KEY_INTERVAL = 'schedule_interval';

	/** Fallback pull cadence, used as both the placeholder and the stored default. */
	public const DEFAULT_INTERVAL = '1h';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly AppConfigReader $reader,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			// Same id-prefix gotcha as the other cards — no app-id prefix.
			'id' => 'data_sync',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			// EXTERNAL so getValue()/setValue() below own the coercion — see the
			// class docblock for why INTERNAL cannot carry a checkbox either way.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Sync Settings',
			'description' => 'How often Nextcloud pulls from mapped Penpot teams. Use Sync Actions below to run either direction on demand.',
			'fields' => [
				[
					'id' => self::KEY_ENABLED,
					'title' => 'Pull from Penpot on a schedule',
					'description' => 'Nextcloud periodically refreshes mirrored files from Penpot; nothing in Penpot changes. When off, use Sync from Penpot in Sync Actions.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// A real bool: this is what the frontend round-trips. It is safe
					// here only because EXTERNAL storage never feeds it to
					// IConfig::getAppValue() (see the class docblock).
					'default' => false,
				],
				[
					'id' => self::KEY_INTERVAL,
					'title' => 'How often',
					'description' => 'Number + unit (s/m/h/d), e.g. 15m, 1h, 6h, 1d. A plain number means seconds. Minimum 5m.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_INTERVAL,
					'default' => self::DEFAULT_INTERVAL,
				],
			],
		];
	}

	/**
	 * Read one field for the settings UI, in the type that field actually means —
	 * a real `bool` for the toggle, a `string` for the interval.
	 *
	 * Both go through {@see AppConfigReader} rather than the typed getters,
	 * because a value stored by the old RADIO (`yes`/`no`) or by `occ` (`1`/`0`)
	 * is string-typed and `getValueBool()` would throw on it until the admin
	 * saved once — which is the toggle reading as OFF on every existing install.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::KEY_ENABLED => $this->reader->bool(self::KEY_ENABLED),
			self::KEY_INTERVAL => $this->reader->string(self::KEY_INTERVAL, self::DEFAULT_INTERVAL),
			default => null,
		};
	}

	/**
	 * Persist one field, normalising what the frontend sent.
	 *
	 * The interval is stored trimmed but otherwise verbatim, because
	 * {@see \OCA\PenpotSync\Service\ScheduleConfig} already owns parsing it and
	 * falls back to hourly on anything it cannot read. Validating here as well
	 * would put the rule in two places and let them disagree.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::KEY_ENABLED:
				// setValueBool, not a '1'/'0' string, so ScheduleConfig's primary
				// typed read succeeds instead of falling through the rescue path.
				$this->config->setValueBool(Application::APP_ID, self::KEY_ENABLED, AppConfigReader::coerceBool($value));
				break;
			case self::KEY_INTERVAL:
				$raw = is_string($value) ? trim($value) : '';
				$this->config->setValueString(
					Application::APP_ID,
					self::KEY_INTERVAL,
					$raw === '' ? self::DEFAULT_INTERVAL : $raw,
				);
				break;
		}
	}
}
