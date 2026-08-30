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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The PURGE step — emptying the Nextcloud trash — which is **not** an event.
 *
 * ## NEXTCLOUD DOES NOT FIRE BeforeNodeDeletedEvent FOR A TRASH PURGE
 *
 * This listener exists because the obvious design is wrong, and it took an
 * integration test to prove it (saga §C6.13). The delete listener discriminated
 * the two steps by path — `<uid>/files/…` for the first delete,
 * `<uid>/files_trashbin/files/…` for the purge — and the second half never ran,
 * because the trashbin's `removeItem` **emits nothing typed at all**.
 *
 * The purge signal is the legacy `\OCP\Trashbin` `preDelete` hook, wired with
 * `\OCP\Util::connectHook` in {@see Application::boot()}. Its deprecation is
 * unavoidable: it is the only entry point that exists.
 *
 * nextcloud-grafana already had this, stated in its own docblock as *"proven
 * live: the trashbin's removeItem fires nothing typed"*. nextcloud-n8n's
 * listener claims the opposite in a comment, and that comment is what this app
 * followed. Two siblings disagreed and the wrong one was believed.
 *
 * ## THE NODE STILL EXISTS WHEN THIS RUNS
 *
 * `preDelete` fires just BEFORE the unlink, so the trashed node is still
 * resolvable and still carries its metadata — which is the only reason the
 * design's id is available at the one moment it is needed.
 *
 * ## WHOSE TRASH IS IT
 *
 * An interactive purge has a session user. A background retention cleanup
 * (`Files_Trashbin`'s ExpireTrash job) has none — but it sets up the filesystem
 * for the user it is processing, so `\OC_User::getUser()` names them. Both are
 * tried, because otherwise a design would survive in Penpot's trash whenever
 * Nextcloud expired the mirror on its own schedule, which is exactly the case
 * nobody is watching.
 */
final class TrashPurgeHook {
	public function __construct(
		private DeletionService $deletions,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Slot for the legacy `\OCP\Trashbin` `preDelete` hook.
	 *
	 * `$params['path']` is the trash-relative path of the node about to be
	 * unlinked: `/files_trashbin/files/<name>.penpot.d<timestamp>`.
	 *
	 * @param array{path?: string} $params
	 */
	public function preDelete(array $params): void {
		if ($this->guard->active()) {
			return;
		}

		$path = $params['path'] ?? '';
		if ($path === '') {
			return;
		}
		// NO EXTENSION PRE-FILTER ANY MORE, and losing it is the price of seeing
		// folders at all. It used to read `str_contains($path, '.penpot')` — cheap,
		// and correct for a mirror, whose trashed name carries the deletion stamp
		// AFTER the extension. A trashed project FOLDER is `Team.d1788055907`: no
		// extension anywhere in it, so the hook returned before it could look, and
		// emptying the trash on a whole project left every design of it sitting in
		// Penpot's trash (`projects/purge.feature`).
		//
		// A path cannot say whether it names a file or a directory, so the node has
		// to be resolved before anything can be decided — the same trade
		// {@see RestoreFromTrashListener} makes on the same kind of gesture, and
		// affordable for the same reason: emptying a trash is deliberate and rare.

		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			$fsUser = \OC_User::getUser();
			$uid = $fsUser === false ? '' : $fsUser;
		}
		if ($uid === '') {
			$this->logger->warning('penpot_sync purge: no user context for the trashed node; skipping', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);

			return;
		}

		try {
			// The home is …/<uid>/files and the trash is …/<uid>/files_trashbin,
			// so the hook path resolves against the home's PARENT.
			$node = $this->rootFolder->getUserFolder($uid)->getParent()->get(ltrim($path, '/'));
		} catch (\Throwable) {
			return;
		}
		try {
			if ($node instanceof Folder) {
				// A whole project on its way out. Nothing is announced per child, so
				// this one hook is the only notice that everything under it is about
				// to stop existing — {@see DeletionService::onFolderPurged()} walks it.
				$this->deletions->onFolderPurged($node);

				return;
			}
			if (!$node instanceof File || !str_contains($node->getName(), PullService::EXTENSION)) {
				return;
			}
			$this->deletions->onPurged($node);
		} catch (\Throwable $e) {
			// Log and swallow: a legacy hook cannot cleanly abort the purge, and a
			// design left in Penpot's trash is a recoverable leak — it expires on
			// Penpot's own schedule — never data loss.
			$this->logger->warning('penpot_sync purge: could not permanently delete the design', [
				'app' => Application::APP_ID,
				'path' => $path,
				'exception' => $e,
			]);
		}
	}
}
