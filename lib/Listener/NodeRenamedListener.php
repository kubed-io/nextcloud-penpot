<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\MotionService;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\PushService;
use OCA\PenpotSync\Service\SyncGuard;
use OCA\PenpotSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Propagates a completed Nextcloud rename or move of a managed Penpot node up to
 * Penpot. A node here is a design OR a project folder, so this one listener sits
 * behind four features: `rename` and `move` under both `designs/` and `projects/`.
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
 * The move that *cannot* happen — anything crossing the edge of a `link` mapping
 * (§C6.38) — is refused before it happens by {@see MoveGuardListener}, so by the
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
 * reconciles it. It is also reported to the acting user through
 * {@see SyncNotifier}, because the log alone never reaches the person who made
 * the gesture. The two pushes are attempted independently so that a failed rename
 * cannot swallow the move.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class NodeRenamedListener implements IEventListener {
	public function __construct(
		private readonly PushService $push,
		private readonly MotionService $motion,
		private readonly SyncGuard $guard,
		private readonly SyncNotifier $notifier,
		private readonly PersonalTokenService $personalTokens,
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

		$renamed = $source->getName() !== $target->getName();
		// A move is "the parent changed" — the path minus the name. A rename in
		// place leaves this identical and costs nothing here.
		$moved = $this->parentPath($source) !== $this->parentPath($target);

		// A PROJECT FOLDER'S NAME IS ITS PATH BELOW THE MAPPING, so moving one
		// RENAMES it even though getName() never changed.
		//
		// This used to compare names alone, and said so: "a move changes the path
		// but not the name, and only the name reaches Penpot's rename commands."
		// That was true while a project was called after its folder. It is not any
		// more — dragging `Penpot/Traveller` into `Penpot/Clients` makes it the
		// project `Clients/Traveller`, and comparing names saw nothing to push.
		//
		// Files are unaffected: a design's Penpot name is its filename, so a move
		// really does leave it alone. Hence the `Folder` test rather than pushing a
		// rename for everything that moved.
		//
		// Safe to run for any folder: pushRename() no-ops on a folder carrying no
		// project id, and pathBelowMapping() returns null for a mapping root.
		if ($renamed || ($moved && $target instanceof Folder)) {
			$this->attempt('rename', $target, fn () => $this->push->pushRename($target));
		}

		if ($moved) {
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

			// AND TELL THE PERSON WHO DID IT. The log reaches an admin reading
			// nextcloud.log; the user who dragged the file is the one who can act on
			// it, and until the notifier existed they were never told at all. Their
			// file is exactly where they put it — the message says what Penpot did
			// not do, not that anything was lost.
			$this->notifier->moveNotPushed(
				$this->personalTokens->actingUserId(),
				$target->getId(),
				$target->getName(),
				$e->getMessage(),
			);
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
