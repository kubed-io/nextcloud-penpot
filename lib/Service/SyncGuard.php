<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * A request-scoped flag that says "the app itself is moving files right now".
 *
 * ## THE ECHO THIS EXISTS TO KILL
 *
 * The pull renames Nextcloud nodes to follow Penpot ({@see PullService} calls
 * `Node::move()` in its `tryRename`). Every such rename fires the same
 * `NodeRenamedEvent` a *user's* rename does — so without this guard the pull's
 * own corrections would be caught by {@see \OCA\PenpotSync\Listener\NodeRenamedListener}
 * and pushed straight back to Penpot: a write loop where the app argues with
 * itself over a name it just set. The write path (Course 4) reacts to human
 * edits only; the pull (Course 3) reacts to Penpot only. This is the wall
 * between them.
 *
 * ## WHY A SHARED INSTANCE, NOT A PARAMETER
 *
 * The pull and the listener never call each other — the event bus connects them
 * behind the scenes, so there is no argument to thread the state through. A
 * single autowired instance (Nextcloud shares one per request) is the only place
 * both sides can see. The scope is exactly right: a user rename and a pull never
 * share a request, and if they somehow overlapped the pull's transaction would
 * serialise them anyway.
 *
 * ## RE-ENTRANT ON PURPOSE
 *
 * A depth counter, not a boolean, so nested `run()` calls (a pull that later
 * grows an inner guarded step) each pop their own frame and only the outermost
 * `leave()` clears the flag. `run()` is `finally`-safe: a throw mid-pull still
 * lowers the flag, so one failed pull can never wedge the listener off for the
 * rest of the request.
 */
final class SyncGuard {
	private int $depth = 0;

	public function enter(): void {
		$this->depth++;
	}

	public function leave(): void {
		if ($this->depth > 0) {
			$this->depth--;
		}
	}

	/** True while the app is mid-sync and its own filesystem writes must be ignored. */
	public function active(): bool {
		return $this->depth > 0;
	}

	/**
	 * Run $fn with the guard raised, lowering it again even if $fn throws.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function run(callable $fn): mixed {
		$this->enter();
		try {
			return $fn();
		} finally {
			$this->leave();
		}
	}
}
