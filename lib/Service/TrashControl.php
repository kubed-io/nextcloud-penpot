<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\FileInfo;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every conversation this app has with the Nextcloud trash: deleting a file so it
 * does NOT land there, reading what is in there, and destroying one entry.
 *
 * ## THE TWO GESTURES THAT NEED THIS, AND WHY THE TRASH IS WRONG FOR THEM
 *
 * Both live in {@see PullService}'s prune, and both are "Penpot stopped naming this
 * design, but nobody deleted anything":
 *
 *   - **a link whose design left the mapping.** A `link` file holds no bytes at all
 *     — it is a pointer. Trashing it offers the user a restore that reconnects to
 *     nothing, because the design is alive and well in a team we no longer mirror.
 *   - **a `sync` mirror whose design was MOVED in Penpot.** The design still
 *     exists; a trashed mirror would read as a design somebody deleted, which is
 *     the one thing that did not happen.
 *
 * The mirrors that DO keep the trash are the ones where the local file may be the
 * last copy in existence — a design deleted in Penpot, or purged there. That
 * asymmetry is the whole point, and {@see PullService::prune()} owns the decision.
 *
 * ## `pauseTrash()` IS THE SUPPORTED BYPASS, AND THE ONLY ONE
 *
 * `Files_Trashbin\Storage::unlink()` consults a private `$trashEnabled`, and
 * `Trashbin::move2trash()` offers no opt-out — neither is reachable from an app.
 * The one public seam is {@see ITrashManager::pauseTrash()}: `moveToTrash()` returns
 * false while paused, and the storage wrapper then performs a real unlink.
 *
 * It is also **backend-agnostic**, which is why it beats trashing and then purging
 * the entry afterwards. Every trash backend registers with the same manager, so this
 * covers a Team Folder's trash exactly as it covers a user's home — and Team Folders
 * are what half this app's mappings actually use. A `Trashbin::`-based purge would
 * have quietly missed them.
 *
 * ## RESOLVED LAZILY, BECAUSE THE TRASH IS AN APP
 *
 * `files_trashbin` ships with Nextcloud but is removable, and `ITrashManager` lives
 * in ITS namespace rather than OCP — so a constructor dependency would make this app
 * fail to boot on an instance without it. When it is absent there is no trash to
 * pause and `delete()` is already permanent, so the fallback is to run the callback
 * unchanged.
 *
 * ## THE TRASH APP'S TYPES STOP HERE
 *
 * {@see listTrashed()} and the operation it hands back are the READING half, used by
 * {@see TrashReconcileService} to reap mirrors whose design Penpot no longer has.
 * They answer in {@see TrashedFile}, this app's own shape, for the reason above: a
 * signature naming `ITrashItem` is a file the unit suite cannot load and psalm
 * cannot resolve. One class pays that cost; everything downstream is ordinary code.
 *
 * Both halves are backend-agnostic in the same way and for the same reason.
 *
 * Ported from the n8n sibling, where `SyncService::removeMirror` first needed it.
 */
final class TrashControl {
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Run $fn with the trash paused, so any delete inside it is permanent.
	 *
	 * The pause is PROCESS-WIDE while it is held, so $fn must be exactly the delete
	 * and nothing else. `finally` restores it even when the delete throws — leaving
	 * the trash paused would silently make every later delete on the request
	 * unrecoverable, including ones the user made themselves.
	 *
	 * @template T
	 *
	 * @param callable():T $fn
	 *
	 * @return T
	 */
	public function withoutTrash(callable $fn): mixed {
		$manager = $this->trashManager();
		if ($manager === null) {
			return $fn();
		}

		$manager->pauseTrash();
		try {
			return $fn();
		} finally {
			$manager->resumeTrash();
		}
	}

	/**
	 * Every file in the ROOT of $uid's trash — their home trash AND the trash of
	 * every Team Folder they can see, because `ITrashManager::listTrashRoot()` folds
	 * in each registered backend.
	 *
	 * ROOT ONLY, DELIBERATELY. A file trashed on its own is a root item; one that
	 * went in as part of a deleted FOLDER is nested inside it, and this does not
	 * recurse into those. Descending would mean destroying single files out of the
	 * middle of a folder the user trashed as a unit, leaving them a restore that
	 * silently comes back incomplete. A folder is restored or purged whole.
	 *
	 * Costs one query per backend — a directory listing for the home, one indexed
	 * lookup for the Team Folders — not one per entry. The caller filters by name and
	 * metadata before spending anything on what comes back.
	 *
	 * Answers `[]` for an unknown user, or when there is no trash app at all: an
	 * instance without `files_trashbin` cannot have a trashed mirror to reap. The
	 * filesystem setup a Team Folder's trash silently depends on is {@see roots()}'.
	 *
	 * @return list<TrashedFile>
	 */
	public function listTrashed(string $uid): array {
		$manager = $this->trashManager();
		if ($manager === null) {
			return [];
		}

		$out = [];
		foreach ($this->roots($manager, $uid) as $item) {
			if (!$item instanceof ITrashItem || $item->getType() !== FileInfo::TYPE_FILE) {
				continue;
			}
			// `FileInfo::getId()` is `int|null`. Without an id there is no metadata to
			// read, so there is no way to know whether this is one of ours — and a file
			// this app cannot identify is never a file it may destroy.
			$fileId = $item->getId();
			if ($fileId === null) {
				continue;
			}

			$out[] = new TrashedFile(
				$fileId,
				// The ORIGINAL name. `getName()` answers the trash's own spelling, which
				// carries the deletion timestamp AFTER the extension — the exact shape
				// that makes `str_ends_with($name, '.penpot')` false for every trashed
				// file, and that already cost the purge hook a release in the sibling.
				basename($item->getOriginalLocation()),
				static function () use ($manager, $item): void {
					$manager->removeItem($item);
				},
			);
		}

		return $out;
	}

	/**
	 * Every FOLDER in the root of $uid's trash — the same listing {@see listTrashed()}
	 * reads, filtered the other way.
	 *
	 * ## WHY THE TYPE THAT LISTING SKIPS IS THE ONLY TYPE THIS ONE WANTS
	 *
	 * A trashed folder is where a whole project went. Trashing `Penpot/Doomed` puts
	 * ONE item in the trash — the folder — and the designs inside it are nested in
	 * that item, not beside it. So the reap, which destroys single mirrors, must never
	 * see them; and the revive, which brings a project's folder back when Penpot lists
	 * the project again, can see nothing else. Same listing, opposite halves, and
	 * neither can reach the other's entries by accident.
	 *
	 * Root-only for the same reason as {@see listTrashed()}, and here it needs no
	 * argument at all: a folder nested inside a trashed parent came back the moment
	 * the parent did.
	 *
	 * The filesystem setup, the unreadable-trash answer, and the `basename()` of the
	 * original location are all {@see listTrashed()}'s — read {@see roots()}.
	 *
	 * @return list<TrashedFolder>
	 */
	public function listTrashedFolders(string $uid): array {
		$manager = $this->trashManager();
		if ($manager === null) {
			return [];
		}

		$out = [];
		foreach ($this->roots($manager, $uid) as $item) {
			if (!$item instanceof ITrashItem || $item->getType() !== FileInfo::TYPE_FOLDER) {
				continue;
			}
			// No id, no metadata, no way to know whose folder this is — and a folder
			// this app cannot identify is never a folder it may move.
			$fileId = $item->getId();
			if ($fileId === null) {
				continue;
			}

			$out[] = new TrashedFolder(
				$fileId,
				basename($item->getOriginalLocation()),
				static function () use ($manager, $item): void {
					$manager->restoreItem($item);
				},
			);
		}

		return $out;
	}

	/**
	 * The raw root entries of $uid's trash, across every registered backend.
	 *
	 * Holds the three things both listings need and neither should restate: the
	 * unknown-user answer, the decision that a trash we cannot read is not a reason
	 * to fail the pull that asked, and the filesystem setup below.
	 *
	 * ## THE FILESYSTEM HAS TO BE SET UP FIRST, OR A TEAM FOLDER'S TRASH IS INVISIBLE
	 *
	 * `listTrashRoot()` reads nothing from the Team Folders backend until the user's
	 * mounts exist — and it answers an EMPTY LIST rather than failing, which is the
	 * worst possible shape for a bug: the reconcile then decides there is nothing to
	 * reap, reports zero, and looks like it is working. The n8n sibling measured this
	 * on a live instance, where the same trash answered 0 entries without the setup
	 * and 4 with it, while every scenario stayed green in CI — because all of them
	 * ran against the plain admin folder.
	 *
	 * The pull happens to satisfy it already ({@see StorageService::ensureRoot()}
	 * sets the actor's filesystem up first), but a feature standing on a side effect
	 * of an unrelated call is a regression waiting for the day that call moves. It is
	 * idempotent and it is one line, so it is stated here rather than assumed.
	 *
	 * @return list<mixed> whatever the backends answered, unfiltered; the callers
	 *                     narrow to `ITrashItem` and to the type they want
	 */
	private function roots(ITrashManager $manager, string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}
		\OC_Util::setupFS($uid);

		try {
			return array_values($manager->listTrashRoot($user));
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not list the trash', [
				'app' => Application::APP_ID,
				'user' => $uid,
				'exception' => $e,
			]);

			return [];
		}
	}

	private function trashManager(): ?ITrashManager {
		if (!interface_exists(ITrashManager::class)) {
			return null;
		}

		try {
			$manager = $this->container->get(ITrashManager::class);
		} catch (\Throwable $e) {
			$this->logger->debug('penpot_sync: no trash manager available; a delete will be permanent anyway', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return null;
		}

		return $manager instanceof ITrashManager ? $manager : null;
	}
}
