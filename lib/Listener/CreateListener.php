<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\CreationService;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Routes a newly written `.penpot` file to {@see CreationService}
 * (`create-design.feature`).
 *
 * ## WHY NodeWrittenEvent AND NOT A CONTROLLER
 *
 * The "+ New → Penpot design" entry in the Files app writes a file over WebDAV
 * and nothing more — that is the whole Nextcloud-sanctioned pattern (its Files
 * maintainer: *"Any Entry is responsible for nothing but themselves"*). So the
 * server side has to notice the file, exactly as both siblings do. It also means
 * the same thing happens whether the file arrived from the menu, the desktop
 * client, or a script — one behaviour, one place.
 *
 * ## THE GUARD IS LOAD-BEARING HERE, NOT DECORATIVE
 *
 * The pull writes `.penpot` files constantly — every mirror it creates, every
 * link body it empties, every archive it stores. Without the fence this listener
 * would try to create a Penpot design for each of them, on every pull. The
 * SyncGuard is what makes "a file was written" mean "a user wrote a file".
 *
 * @implements IEventListener<NodeWrittenEvent>
 */
final class CreateListener implements IEventListener {
	public function __construct(
		private CreationService $creations,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeWrittenEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}
		if (!str_ends_with($node->getName(), PullService::EXTENSION)) {
			return;
		}

		try {
			$this->creations->onWritten($node);
		} catch (\Throwable $e) {
			// The file exists and is the user's. A failure here leaves it
			// untracked, which is honest and recoverable; rethrowing would turn a
			// Penpot problem into a failed file write.
			$this->logger->warning('penpot_sync: create handling failed; the file is untracked', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}
}
