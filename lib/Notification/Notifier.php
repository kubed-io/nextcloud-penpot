<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Notification;

use OCA\PenpotSync\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns this app's stored notifications into the text a person reads.
 *
 * NO UI IS BUILT HERE. The Notifications app draws the bell entry and the toast;
 * this class only maps `{subject, parameters}` to strings, which is the whole
 * reason the notification channel costs two small classes rather than a feature.
 *
 * Subjects, both raised by {@see \OCA\PenpotSync\Service\SyncNotifier}:
 *
 *   - `import_failed` — a `.penpot` file arrived in a mapped folder and Penpot
 *     would not take it. Carries Penpot's own complaint, because that is the part
 *     the user can act on.
 *   - `move_not_pushed` — a move happened in Nextcloud that Penpot never heard
 *     about. Nothing is wrong with the file and there is nothing to fix; the
 *     message says so, so it does not read as lost work.
 *   - `restored_without_design` — a mirror came back out of the Nextcloud trash
 *     and its design did not, because Penpot's grace window had closed or someone
 *     erased it (§6.20 — a purged id cannot be resurrected). NOT a failure: the
 *     file is whole, and the point of telling anyone is that it is now the only
 *     copy of a design the restore looked like it had brought back.
 */
final class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Penpot sync');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$file = (string)($notification->getSubjectParameters()['file'] ?? $l->t('design'));
		$reason = (string)($notification->getMessageParameters()['reason'] ?? '');

		switch ($notification->getSubject()) {
			case 'import_failed':
				// A RICH subject with a plain fallback: clients that cannot render
				// rich objects still get a readable line rather than a template.
				$notification->setRichSubject(
					$l->t('Couldn’t add {file} to Penpot'),
					['file' => ['type' => 'highlight', 'id' => $file, 'name' => $file]],
				);
				$notification->setParsedSubject($l->t('Couldn’t add “%s” to Penpot', [$file]));
				$notification->setParsedMessage(
					$reason !== ''
						// Penpot's own words first — they are the actionable half —
						// then what it means for the file, which is the reassuring half.
						? $l->t('Penpot said: %s. The file is still in your folder, and it is unchanged.', [$reason])
						: $l->t('The file is still in your folder, and it is unchanged.'),
				);

				return $notification;
			case 'move_not_pushed':
				$notification->setRichSubject(
					$l->t('Penpot didn’t hear about the move of {file}'),
					['file' => ['type' => 'highlight', 'id' => $file, 'name' => $file]],
				);
				$notification->setParsedSubject($l->t('Penpot didn’t hear about the move of “%s”', [$file]));
				$notification->setParsedMessage(
					$reason !== ''
						? $l->t('Penpot said: %s. Your file has moved and the design is still in its old project — the next sync will catch up.', [$reason])
						: $l->t('Your file has moved and the design is still in its old project — the next sync will catch up.'),
				);

				return $notification;
			case 'restored_without_design':
				// DELIBERATELY NOT PHRASED AS AN ERROR. Nothing failed, and there is
				// nothing to retry — the file is whole and the design is gone.
				$notification->setRichSubject(
					$l->t('{file} is back, but its design is gone from Penpot'),
					['file' => ['type' => 'highlight', 'id' => $file, 'name' => $file]],
				);
				$notification->setParsedSubject($l->t('“%s” is back, but its design is gone from Penpot', [$file]));
				// ONE STRING LITERAL, NOT A CONCATENATION. Nextcloud's l10n extractor
				// reads the argument to `t()` statically, so a message assembled with
				// `.` is not picked up and ships untranslatable — silently, because it
				// still renders perfectly in English.
				$notification->setParsedMessage(
					$l->t('Penpot no longer has that design and it cannot be brought back there. Your file is complete and is now the only copy of it.'),
				);

				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}
