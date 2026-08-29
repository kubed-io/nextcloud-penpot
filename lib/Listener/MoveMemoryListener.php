<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\Service\MoveMemory;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;

/**
 * Reads a moving `.penpot` file's metadata while it still HAS any, and hands it
 * to {@see MoveMemory} for {@see \OCA\PenpotSync\Service\MotionService} to find
 * on the other side of the gesture.
 *
 * Nextcloud drops a file's `files_metadata` when it crosses a storage boundary,
 * so a design dragged between two mappings on different storages arrives looking
 * untracked — see {@see MoveMemory} for the measurement. This event is the last
 * moment the record exists.
 *
 * ## IT RUNS FOR EVERY MOVE, NOT ONLY THE CROSSINGS
 *
 * Telling a crossing apart here would mean asking the TARGET for its storage,
 * and the target of a *before* event is a node that does not exist yet. The test
 * is also unnecessary: a same-storage move keeps its metadata, so
 * {@see MotionService} reads the file itself and never consults the memory at
 * all. Remembering unconditionally costs one metadata read per `.penpot` rename
 * — a read the completed-move listener was always going to make anyway.
 *
 * ## THE GUARD, FOR THE SAME REASON AS EVERY OTHER LISTENER
 *
 * The pull's own follow-renames raise {@see SyncGuard}, and they are reconciling
 * TO Penpot — there is no push to prepare for, so there is nothing to remember.
 *
 * Registered alongside {@see MoveGuardListener} on the same event. Order does not
 * matter: if the guard refuses the move first, this never runs and no note is
 * left; if this runs first, the note is simply never asked for.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveMemoryListener implements IEventListener {
	public function __construct(
		private readonly PenpotMetadata $metadata,
		private readonly MoveMemory $memory,
		private readonly SyncGuard $guard,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}

		$source = $event->getSource();
		if (!$source instanceof File) {
			return;
		}
		if (!str_ends_with($source->getName(), PullService::EXTENSION)) {
			return;
		}

		$meta = $this->metadata->readFile($source->getId());
		if ($meta === null || !$meta->isManaged()) {
			// Nothing worth carrying: an untracked `.penpot` is an import wherever
			// it lands, which is what the destination side already does with it.
			return;
		}

		$this->memory->remember($source->getId(), $meta);
	}
}
