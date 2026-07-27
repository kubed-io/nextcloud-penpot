<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PushService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use Psr\Log\LoggerInterface;

/**
 * Propagates a completed Nextcloud rename of a managed Penpot node up to Penpot
 * (rename.feature). Nextcloud fires {@see NodeRenamedEvent} for both renames and
 * moves; this slice acts only on the rename (same folder, new name) —
 * {@see PushService} decides whether the node is one of ours and which Penpot
 * RPC it maps to. Move-between-projects is a later slice of the same course.
 *
 * ## THE GUARD IS THE WALL (saga Ch2 Course 4)
 *
 * The pull renames mirror nodes to follow Penpot, and that fires this very event.
 * {@see SyncGuard::active()} is raised for the whole pull, so this listener bails
 * on the app's own writes — otherwise the pull's follow-rename would be pushed
 * straight back, an app arguing with itself over a name it just set.
 *
 * ## A FAILURE CANNOT ABORT THE RENAME (saga §6.18 rule 3)
 *
 * The NC rename has already committed by the time this runs, so a Penpot failure
 * is logged, not raised: the local name stands and the next pull reconciles it.
 * There is no notifier yet (penpot has no `SyncNotifier` — a later course), so a
 * failure surfaces in the log only.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class NodeRenamedListener implements IEventListener {
	public function __construct(
		private readonly PushService $push,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}
		if ($this->guard->active()) {
			// Our own pull-driven rename — never push it back.
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();

		// A pure MOVE (same name, new parent) is a later slice; act only when the
		// name actually changed. Comparing names, not paths, is deliberate: a move
		// changes the path but not the name, and only the name reaches Penpot.
		if ($source->getName() === $target->getName()) {
			return;
		}

		try {
			$this->push->pushRename($target);
		} catch (\Throwable $e) {
			// The NC rename already happened; we cannot undo it here. Log and let
			// the next pull reconcile the Penpot side.
			$this->logger->warning('penpot_sync writeback: rename push failed', [
				'app' => Application::APP_ID,
				'nodeId' => $target->getId(),
				'path' => $target->getPath(),
				'exception' => $e,
			]);
		}
	}
}
