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
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Routes a deleted `.penpot` file to {@see DeletionService} (`delete.feature`).
 *
 * ## THIS IS THE SOFT STEP ONLY. THE PURGE IS NOT AN EVENT.
 *
 * The first version of this class handled both halves, discriminating by path
 * (`<uid>/files/…` vs `<uid>/files_trashbin/files/…`) on the strength of a
 * comment in nextcloud-n8n. The second half never ran: Nextcloud fires **no
 * typed event at all** when a file is purged from the trash — the trashbin's
 * `removeItem` emits the legacy `\OCP\Trashbin` `preDelete` hook instead.
 *
 * nextcloud-grafana already knew, and says so in {@see TrashPurgeHook}'s own
 * docblock ("proven live"). Two siblings disagreed and the wrong one was
 * followed. The purge now lives in that hook; this class does one thing.
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
	/**
	 * Nodes already in the trash are not ours to act on here.
	 *
	 * A purge does not reach this class at all (see the docblock), but a node
	 * under the trashbin can still arrive here by other routes, and a second
	 * `delete-file` for a design already in Penpot's trash is a wasted call at
	 * best. {@see TrashPurgeHook} owns everything on that side of the line.
	 */
	/**
	 * The real trashbin mount, spelled tightly enough that a user cannot fake it.
	 *
	 * `/files_trashbin/` alone is a SUBSTRING a user can create: a folder called
	 * `files_trashbin` in their home gives `/alice/files/files_trashbin/…`, which
	 * matched — and silently stopped every delete inside it from reaching Penpot.
	 * Core's own path is `/<uid>/files_trashbin/files/<name>.dNNNNNNNNNN`, so the
	 * `files/` is what makes this the mount rather than a name.
	 */
	private const TRASHBIN_SEGMENT = '/files_trashbin/files/';

	public function __construct(
		private DeletionService $deletions,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
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
		if (str_contains($node->getPath(), self::TRASHBIN_SEGMENT)) {
			return;
		}

		// A FOLDER IS A GESTURE ON EVERY PROJECT ITS NAME SPELLED (§C6.38), and
		// this branch is the one that did not exist: the class returned on
		// anything that was not a File, so trashing a project folder reached
		// Penpot not at all. The scenario for it was tagged `@todo`.
		if ($node instanceof Folder) {
			$this->attempt('folder delete', $node, fn () => $this->deletions->onFolderTrashed($node));

			return;
		}

		if (!$node instanceof File) {
			return;
		}
		if (!str_ends_with($node->getName(), PullService::EXTENSION)) {
			return;
		}

		$this->attempt('delete', $node, fn () => $this->deletions->onTrashed($node));
	}

	/**
	 * Run one delete handler, absorbing any failure into the log.
	 *
	 * NEVER ABORT THE DELETE. See the class docblock: the user's file is theirs,
	 * and a remote failure is the next pull's problem. Shared by both branches so
	 * the folder path cannot quietly acquire different behaviour from the file
	 * one — this event fires BEFORE the delete, so a throw here would cancel a
	 * gesture the user has every right to make.
	 */
	private function attempt(string $what, Node $node, callable $handle): void {
		try {
			$handle();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: {what} handling failed; the local delete stands', [
				'app' => Application::APP_ID,
				'what' => $what,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}
}
