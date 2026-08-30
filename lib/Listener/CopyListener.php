<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\CopyService;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Routes a copied `.penpot` file to {@see CopyService} (`designs/copy.feature`).
 *
 * `NodeCopiedEvent` is its own event — a copy fires neither `NodeWrittenEvent`
 * nor `NodeRenamedEvent`, so without this listener a copied design is simply
 * never noticed. Both siblings learned the same thing and have the same class.
 *
 * Thin on purpose, exactly like {@see NodeRenamedListener}: the routing rules
 * live here, every decision about Penpot lives in the service, and the service
 * is what the unit tests drive.
 *
 * @implements IEventListener<NodeCopiedEvent>
 */
final class CopyListener implements IEventListener {
	public function __construct(
		private CopyService $copies,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeCopiedEvent) {
			return;
		}

		// The pull's own writes must never look like a user copying something.
		// Same fence as the rename and move paths, for the same reason: without
		// it the app would argue with itself about a file it just created.
		if ($this->guard->active()) {
			return;
		}

		$target = $event->getTarget();

		// A FOLDER COPY IS ONE EVENT AND NO CHILDREN, measured on a live instance:
		// copying a project folder fires this once for the folder and nothing at
		// all per design, so the copies land with no `penpot_id`, no mode and no
		// revision. Filtering to `File` here is what made a copied project a pile
		// of inert files — `projects/copy.feature` was @unbuilt on exactly that.
		//
		// The folder branch walks the SOURCE's designs itself for that reason:
		// there is no per-child event to wait for.
		if ($target instanceof Folder) {
			try {
				$this->copies->onFolderCopy($event->getSource(), $target);
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync: folder copy handling failed; the copy is untracked', [
					'app' => Application::APP_ID,
					'folder' => $target->getName(),
					'exception' => $e,
				]);
			}

			return;
		}

		if (!$target instanceof File) {
			return;
		}
		if (!str_ends_with($target->getName(), PullService::EXTENSION)) {
			return;
		}

		try {
			$this->copies->onCopy($event->getSource(), $target);
		} catch (\Throwable $e) {
			// The Nextcloud copy has already happened and is the user's file. A
			// failure here leaves it untracked, which is recoverable and honest;
			// rethrowing would surface a Penpot problem as a failed file copy.
			$this->logger->warning('penpot_sync: copy handling failed; the copy is untracked', [
				'app' => Application::APP_ID,
				'file' => $target->getName(),
				'exception' => $e,
			]);
		}
	}
}
