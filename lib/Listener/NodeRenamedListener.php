<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\MotionService;
use OCA\PenpotSync\Service\PushService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Propagates a completed Nextcloud rename or move of a managed Penpot node up to
 * Penpot (`rename.feature`, `move.feature`).
 *
 * ## ONE EVENT, TWO GESTURES
 *
 * Nextcloud fires a single {@see NodeRenamedEvent} for both, and the two are
 * told apart by what actually changed:
 *
 *   - **the name changed** → {@see PushService} decides whether the node is one
 *     of ours and which Penpot RPC it maps to (`rename-file` / `rename-project`);
 *   - **the parent changed** → {@see MotionService} resolves where the node
 *     landed (§6.29) and re-files it in Penpot with `move-files` if — and only
 *     if — its project actually changed.
 *
 * A WebDAV `MOVE` can do both at once, so both are checked, independently: a drag
 * that also renames must not silently lose one half. They run rename-first, so
 * that if the move then fails the Penpot name is at least already correct and the
 * next pull has less to reconcile.
 *
 * The move that *cannot* happen — a project folder leaving its team folder
 * (§6.30) — is refused before it happens by {@see MoveGuardListener}, so by the
 * time this runs every move is already a legal one.
 *
 * ## THE GUARD IS THE WALL (saga Ch2 Course 4)
 *
 * The pull renames mirror nodes to follow Penpot, and that fires this very event.
 * {@see SyncGuard::active()} is raised for the whole pull, so this listener bails
 * on the app's own writes — otherwise the pull's follow-rename would be pushed
 * straight back, an app arguing with itself over a name it just set.
 *
 * ## A FAILURE CANNOT ABORT THE GESTURE (saga §6.18 rule 3)
 *
 * The NC rename/move has already committed by the time this runs, so a Penpot
 * failure is logged, not raised: the local state stands and the next pull
 * reconciles it. There is no notifier yet (penpot has no `SyncNotifier` — a later
 * course), so a failure surfaces in the log only. The two pushes are attempted
 * independently for the same reason: a failed rename must not swallow the move.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class NodeRenamedListener implements IEventListener {
	public function __construct(
		private readonly PushService $push,
		private readonly MotionService $motion,
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

		// Comparing names, not paths, is deliberate: a move changes the path but
		// not the name, and only the name reaches Penpot's rename commands.
		if ($source->getName() !== $target->getName()) {
			$this->attempt('rename', $target, fn () => $this->push->pushRename($target));
		}

		// A move is "the parent changed" — the path minus the name. A rename in
		// place leaves this identical and costs nothing here.
		if ($this->parentPath($source) !== $this->parentPath($target)) {
			$this->attempt('move', $target, fn () => $this->motion->onMove($source, $target));
		}
	}

	/**
	 * Run one push, absorbing any failure into the log.
	 *
	 * The local change has already committed and cannot be undone here, so a throw
	 * would only break the *other* push and, on some paths, surface a server error
	 * over an action the user already completed successfully.
	 */
	private function attempt(string $what, Node $target, callable $push): void {
		try {
			$push();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync writeback: {what} push failed', [
				'app' => Application::APP_ID,
				'what' => $what,
				'nodeId' => $target->getId(),
				'path' => $target->getPath(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * The node's containing folder path.
	 *
	 * Derived from the path string rather than by calling `getParent()`: the source
	 * node no longer exists at that path, and this comparison must not cost two
	 * filesystem lookups on every rename in the instance.
	 */
	private function parentPath(Node $node): string {
		return dirname($node->getPath());
	}
}
