<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The channel a failure travels on, which this app did not have.
 *
 * ## WHY TWO SCENARIOS WERE `@unbuilt` FOR WANT OF THIS CLASS
 *
 * `designs/move.feature` asks twice for *"the failure is reported to the user"* —
 * once for an archive Penpot will not accept, once for a move made while Penpot is
 * unreachable — and `features/AGENTS.md` recorded the reason neither could run as
 * *"there is nowhere for a failure to be reported to"*. That was true: every
 * failure in this app ends in `$this->logger->warning()`, which reaches an admin
 * reading `nextcloud.log` and nobody else.
 *
 * The user who dragged the file is the person who can do something about it, and
 * they never heard. Both siblings solved this the same way and it is the native
 * channel for exactly this shape of problem — work that happens after the gesture
 * has already committed.
 *
 * ## THE GESTURE IS NEVER UNDONE TO REPORT ON IT (§6.18 rule 3)
 *
 * The Nextcloud move has already happened by the time any of this runs. The file
 * is where the user put it, and it stays there; the notification says what did not
 * happen on Penpot's side. Aborting the move to "stay consistent" would take work
 * away from someone to tell them about a remote failure they did not cause.
 *
 * ## A NOTIFICATION MUST NEVER BE THE THING THAT BREAKS
 *
 * Every method here swallows its own failures into the log. A bell entry is a
 * courtesy on top of an operation that has already decided its outcome — if
 * raising it throws, the outcome must not change.
 *
 * Keyed on the FILE ID so repeated failures on one file collapse onto a single
 * entry rather than filling the bell.
 *
 * NOTHING RETRACTS AN ENTRY, and that is a gap rather than a design. A `cleared()`
 * lived here to mark a file's notification processed once a later attempt
 * succeeded — and no caller was ever written, so a fixed file kept its stale error
 * in the bell exactly as if the method did not exist. It did not, in every sense
 * that mattered; removed in the #50 sweep. Wiring it is a behaviour to spec, not a
 * method to restore.
 */
final class SyncNotifier {
	/** The notification object type — one per file, whatever went wrong with it. */
	private const OBJECT_TYPE = 'design';

	/** Penpot's own complaint, capped: notification storage is not a log. */
	private const MAX_REASON = 320;

	/**
	 * What a URL in a user-facing message is replaced with.
	 *
	 * THE REASON COMES FROM AN EXCEPTION MESSAGE, and this app's carry the instance
	 * address by design — `PenpotClient` throws *"Could not reach Penpot at
	 * https://penpot.internal:9001/api/rpc/…"*, which is precisely the detail an
	 * admin wants in the log. A notification is not the log: it is shown to whoever
	 * dragged the file, who may be an ordinary user on a shared instance, and an
	 * in-cluster hostname is infrastructure they were never told about and cannot
	 * act on. The full message is still logged at the call site.
	 */
	private const REDACTED_URL = '[Penpot]';

	public function __construct(
		private readonly IManager $manager,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * A design could not be created in Penpot from the file that arrived —
	 * the archive was refused, or the create failed.
	 *
	 * NAMES WHAT PENPOT SAID, which is the whole value of telling the user at all:
	 * "it didn't work" is something they can already see. The file stays exactly
	 * where they put it, holding exactly what it held.
	 */
	public function importFailed(string $userId, int $fileId, string $fileName, string $reason): void {
		$this->raise(
			$userId,
			$fileId,
			'import_failed',
			['file' => $fileName],
			['reason' => self::readable($reason)],
			'penpot_sync: could not raise an import-failure notification',
		);
	}

	/**
	 * A move was made in Nextcloud that Penpot never heard about, because Penpot
	 * could not be reached.
	 *
	 * DELIBERATELY NOT THE SAME SUBJECT as an import failure: nothing is wrong with
	 * the file here and there is nothing for the user to fix. The design is still
	 * in its old project and the next pull reconciles it — so the message says that
	 * rather than implying lost work.
	 */
	public function moveNotPushed(string $userId, int $fileId, string $fileName, string $reason): void {
		$this->raise(
			$userId,
			$fileId,
			'move_not_pushed',
			['file' => $fileName],
			['reason' => self::readable($reason)],
			'penpot_sync: could not raise a move-failure notification',
		);
	}

	/**
	 * A mirror came back out of the Nextcloud trash, and its design did not.
	 *
	 * NOT A FAILURE, WHICH IS WHY IT IS ITS OWN SUBJECT. Nothing went wrong and
	 * nothing can be retried: the design is past Penpot's grace window or was
	 * erased, and §6.20 is clear that a purged id cannot be resurrected. The file
	 * is whole and it is now the ONLY copy of that design in existence.
	 *
	 * That last part is the reason this is worth a notification at all. The restore
	 * looks like a complete success from the Files app — the file is right back
	 * where it was, holding a valid `.penpot` — and the one thing the user cannot
	 * see is that opening it in Penpot will find nothing there.
	 */
	public function restoredWithoutItsDesign(string $userId, int $fileId, string $fileName): void {
		$this->raise(
			$userId,
			$fileId,
			'restored_without_design',
			['file' => $fileName],
			null,
			'penpot_sync: could not raise a restored-without-design notification',
		);
	}

	/**
	 * One exception message, made fit to show a person.
	 *
	 * TWO EDITS, BOTH ABOUT AUDIENCE. URLs go because the instance address is
	 * infrastructure the reader did not ask about and cannot act on (see
	 * {@see REDACTED_URL}); the length cap is because notification storage is a
	 * bell entry, not a log, and a stack-trace-length string in it helps nobody.
	 *
	 * The unedited message is written to `nextcloud.log` at every call site, so
	 * nothing is lost to whoever is actually debugging.
	 */
	private static function readable(string $reason): string {
		$clean = preg_replace('~\bhttps?://\S+~i', self::REDACTED_URL, $reason) ?? $reason;

		return mb_substr(trim($clean), 0, self::MAX_REASON);
	}

	/**
	 * Build, address and send one notification.
	 *
	 * No-ops on an empty user id: with nobody to address there is nothing to raise,
	 * and that is an ordinary state — a pull running on the schedule has no acting
	 * user at all.
	 *
	 * @param array<string, string> $subjectParams
	 * @param array<string, string>|null $messageParams
	 */
	private function raise(
		string $userId,
		int $fileId,
		string $subject,
		array $subjectParams,
		?array $messageParams,
		string $failureLog,
	): void {
		if ($userId === '') {
			return;
		}

		try {
			$notification = $this->manager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject(self::OBJECT_TYPE, (string)$fileId)
				->setSubject($subject, $subjectParams);
			if ($messageParams !== null) {
				$notification->setMessage($subject, $messageParams);
			}
			$this->manager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning($failureLog, [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
