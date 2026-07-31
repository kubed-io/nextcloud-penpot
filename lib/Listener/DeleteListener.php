<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\DeletionService;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Routes a deleted `.penpot` file to {@see DeletionService} (`delete.feature`).
 *
 * ## ONE EVENT, TWO STEPS, TOLD APART BY PATH
 *
 * Nextcloud fires `BeforeNodeDeletedEvent` for BOTH halves of a delete, and the
 * only thing that distinguishes them is where the node lives when it fires:
 *
 *     <uid>/files/…                 → the first delete, on its way to the trash
 *     <uid>/files_trashbin/files/…  → the purge, the irreversible one
 *
 * That discrimination is ported from nextcloud-n8n, which learned it the same
 * way. Getting it backwards would be the worst bug available here: it would
 * permanently destroy a design on an ordinary delete.
 *
 * ## A TRASH-BYPASSED DELETE COUNTS AS THE PURGE
 *
 * If the instance has the trash disabled, or the client sends
 * `X-NC-Skip-Trashbin`, or another app disabled the bin for this delete, there
 * IS no soft step — the file never reaches a trash. Treating that as the soft
 * step would mean turning the Nextcloud trash off quietly stops deletes reaching
 * Penpot at all, so it is treated as the hard step instead. Detected the same
 * way: a node outside the trashbin path that is being deleted outright.
 *
 * ## WHY THIS ONE NEVER ABORTS
 *
 * n8n's equivalent can throw `AbortedEventException` to PREVENT the delete when
 * the remote is unreachable. This one does not, and the difference is Penpot's
 * real trash: n8n's soft step is the only chance to archive something, so
 * failing it loudly is right. Here a failed soft step leaves a design in Penpot
 * that the next pull will simply re-mirror — recoverable, visible, and far less
 * hostile than refusing to let someone delete their own file because a remote
 * service is down.
 *
 * @implements IEventListener<BeforeNodeDeletedEvent>
 */
final class DeleteListener implements IEventListener {
	/** Where Nextcloud parks a trashed node — the marker for the second step. */
	private const TRASHBIN_SEGMENT = '/files_trashbin/';

	/**
	 * A trashed entry's name, which is NOT `<name>.penpot`.
	 *
	 * Nextcloud renames a node on its way into the trash, appending the deletion
	 * time: `Gone For Good.penpot.d1785457295`. So by the time the PURGE fires,
	 * the extension is no longer last and a plain `str_ends_with` rejects the
	 * very file it is meant to catch.
	 *
	 * This cost a green unit suite and a red integration one: nothing that mocks
	 * a node ever sees the rename, because the rename is Nextcloud's, not ours.
	 */
	private const TRASHED_SUFFIX = '/\.penpot\.d\d+$/';

	public function __construct(
		private DeletionService $deletions,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Is this one of ours, at EITHER step?
	 *
	 * The soft step sees `Login.penpot`; the purge sees
	 * `Login.penpot.d1785457295`, because Nextcloud stamps the deletion time onto
	 * the name on the way into the trash. Both are the same file.
	 */
	private function isOurs(string $name): bool {
		return str_ends_with($name, PullService::EXTENSION)
			|| preg_match(self::TRASHED_SUFFIX, $name) === 1;
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent) {
			return;
		}

		// The prune deletes mirrors too (C5.1), and it must never be mistaken for
		// a user deleting a file — it is already acting on Penpot's own answer.
		if ($this->guard->active()) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}
		if (!$this->isOurs($node->getName())) {
			return;
		}

		try {
			if (str_contains($node->getPath(), self::TRASHBIN_SEGMENT)) {
				$this->deletions->onPurged($node);
			} else {
				$this->deletions->onTrashed($node);
			}
		} catch (\Throwable $e) {
			// Never abort the delete. See the class docblock: the user's file is
			// theirs, and a remote failure is the next pull's problem.
			$this->logger->warning('penpot_sync: delete handling failed; the local delete stands', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}
}
