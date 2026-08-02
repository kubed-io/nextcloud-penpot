<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\RestoreService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Routes a mirror coming back out of the Nextcloud trash to
 * {@see RestoreService} (`delete.feature`).
 *
 * ## THIS ONE *IS* A TYPED EVENT — UNLIKE ITS OPPOSITE NUMBER
 *
 * Worth stating plainly next to {@see TrashPurgeHook}, which exists because the
 * purge fires **nothing typed at all** and had to be caught with a legacy
 * `\OCP\Trashbin` hook (§C6.13). The restore is not like that: files_trashbin
 * dispatches `NodeRestoredEvent` after the rename completes, carrying the source
 * (in the trash) and the target (back in the user's files). Both siblings
 * already listen to exactly this event, and this is their mechanism ported.
 *
 * The TARGET is what we want: the node at its restored path, with the same
 * fileid — and therefore the same Files-Metadata — it carried before it was
 * trashed. That id-stability through the trash is what makes the whole thing
 * work without a shred of extra state (saga §6.44/§6.45).
 *
 * ## IT NEVER REFUSES THE RESTORE
 *
 * `BeforeNodeRestoredEvent` exists and can veto. This listens to the one that
 * fires AFTER, deliberately: refusing to let someone take their own file out of
 * the trash because Penpot is unreachable would be the worst possible answer.
 * Same reasoning as {@see DeleteListener}, which for the same reason never
 * aborts a delete.
 *
 * @implements IEventListener<NodeRestoredEvent>
 */
final class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private RestoreService $restores,
		private SyncGuard $guard,
		// Only the legacy Team Folder path needs these: that hook hands over a
		// path, where the typed event hands over the node itself.
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The SAME restore, arriving by the other door — a Team Folder.
	 *
	 * groupfolders does not use files_trashbin. It registers its own
	 * `ITrashBackend`, and its `restoreItem()` emits the LEGACY hook
	 * `\OCA\Files_Trashbin\Trashbin` / `post_restore` instead of the typed
	 * {@see NodeRestoredEvent} this class is registered on. So on the backend that
	 * shared teams actually use, restoring a mirror reached Penpot not at all: the
	 * file came back in Nextcloud while the design stayed in Penpot's trash, and
	 * the next pull pruned it a second time.
	 *
	 * Found by running the existing scenarios against both backends (saga §C6.26)
	 * — no new scenario was needed, which is the whole argument for the backend
	 * being a dimension.
	 *
	 * The hook hands us a PATH rather than a node, so we resolve it through the
	 * acting user's view. A path we cannot resolve is not an error worth failing a
	 * restore over: the file is back either way, and the next pull reconciles.
	 *
	 * @param array{filePath?: string, trashPath?: string} $params
	 */
	public function postRestore(array $params): void {
		if ($this->guard->active()) {
			return;
		}
		$path = $params['filePath'] ?? '';
		if ($path === '' || !str_ends_with($path, PullService::EXTENSION)) {
			return;
		}
		try {
			$uid = $this->userSession->getUser()?->getUID();
			if ($uid === null) {
				return;
			}
			$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
			if ($node instanceof File) {
				$this->restores->onRestored($node);
			}
		} catch (\Throwable $e) {
			// Same contract as handle(): a remote problem must never break the
			// local restore the user just performed.
			$this->logger->warning('penpot_sync: restore-from-trash (Team Folder) failed', [
				'app' => Application::APP_ID,
				'path' => $path,
				'exception' => $e,
			]);
		}
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRestoredEvent) {
			return;
		}

		// The app moves mirrors in and out of the trash itself (the prune, C5.1).
		// Its own motion must never be read as a user's gesture.
		if ($this->guard->active()) {
			return;
		}

		$node = $event->getTarget();
		if (!$node instanceof File) {
			return;
		}
		if (!str_ends_with($node->getName(), PullService::EXTENSION)) {
			return;
		}

		try {
			$this->restores->onRestored($node);
		} catch (\Throwable $e) {
			// Belt and braces: the service swallows its own failures, and this is
			// here so a bug in that promise cannot surface as a failed restore.
			$this->logger->warning('penpot_sync: restore handling failed; the local restore stands', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}
}
