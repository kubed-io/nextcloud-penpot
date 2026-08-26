<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\PenpotSync\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting a file so that it does NOT land in the Nextcloud trash.
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
 * Ported from the n8n sibling, where `SyncService::removeMirror` first needed it.
 */
final class TrashControl {
	public function __construct(
		private readonly ContainerInterface $container,
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
