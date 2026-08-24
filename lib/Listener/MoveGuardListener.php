<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\Service\MoveRules;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;

/**
 * STOPS a refused move, on every route there is. What it cannot do is say why.
 *
 * The two rules — nothing moves in or out of a `link` mapping (§C6.38), a `link`
 * file stays inside its project (§6.43) — live in {@see MoveRules}, which states
 * them once and is asked twice. This is the half
 * that reaches `occ`, another app and a script, none of which go near Sabre; the
 * half a person actually reads is
 * {@see \OCA\PenpotSync\DAV\LinkWriteGuardPlugin} on `method:MOVE`.
 *
 * ## THE MESSAGE NEVER ARRIVED, AND `AbortedEventException` IS STILL CORRECT
 *
 * This class used to promise that throwing "shows the message to the user". It
 * does not. `HookConnector::rename()` catches `AbortedEventException` by name,
 * logs it and sets `run = false`; `View::rename()` then returns false and
 * `Directory::moveInto()` answers `throw new Forbidden('')` — an empty string,
 * by literal. Both of this app's refusals reached the Files app as a 403 with
 * nothing in it, which is the one outcome the refusals were written to avoid:
 * a person told no, without being told why, who therefore retries it.
 *
 * It is still thrown here, because nothing else refuses anything. `OC_Hook::emit()`
 * wraps every slot in `catch (Throwable)` and carries on — only `HintException`
 * and `ServerNotAvailableException` survive — so a listener that throws any other
 * exception is logged and the move SUCCEEDS. Abort here; speak in the DAV plugin.
 *
 * The *consequences* of an allowed move belong to
 * {@see \OCA\PenpotSync\Service\MotionService}, as they always did.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
		private readonly MoveRules $rules,
		private readonly SyncGuard $guard,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		if ($this->guard->active()) {
			// The pull's own follow-renames are never refused — it is reconciling
			// TO Penpot, so by definition it cannot desync from it.
			return;
		}

		$refusal = $this->rules->refusalFor($event->getSource(), $event->getTarget());
		if ($refusal !== null) {
			throw new AbortedEventException($refusal);
		}
	}
}
