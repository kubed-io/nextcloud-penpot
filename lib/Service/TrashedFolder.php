<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * One FOLDER sitting in a Nextcloud trash, reduced to what this app needs to
 * decide whether to bring it back: which node it is, what it was called, and how
 * to restore it.
 *
 * The sibling of {@see TrashedFile} and it exists for the same reason — see that
 * class for why `OCA\Files_Trashbin\Trash\ITrashItem` stops at
 * {@see TrashControl}'s boundary instead of being passed around.
 *
 * ## THE VERB IS THE DIFFERENCE, AND IT IS THE WHOLE DIFFERENCE
 *
 * A trashed FILE is only ever reached in order to destroy it: a mirror whose
 * design Penpot no longer has is the last copy of something already deleted twice
 * ({@see TrashReconcileService}). A trashed FOLDER is only ever reached in order
 * to bring it back — its project is live in Penpot again, so the folder that
 * mirrors it belongs in the user's tree rather than in their trash
 * (`projects/restore.feature`, saga §6.37).
 *
 * Two value objects rather than one carrying both closures, because nothing in
 * this app has a reason to hold a handle to both operations at once, and a
 * `purge()` reachable from the revive path is a `purge()` that can be called by
 * accident. The type is the guard.
 */
final class TrashedFolder {
	/**
	 * @param int $fileId the filecache id, unchanged by the trip through the trash —
	 *                    which is what makes the folder's `penpot_project_id` readable
	 *                    here, where the path is long gone
	 * @param string $name the ORIGINAL basename, not the `.d<timestamp>` spelling the
	 *                     trash stores it under
	 * @param \Closure():void $restore put this folder back where it came from, through
	 *                                 whichever trash backend is holding it
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		private readonly \Closure $restore,
	) {
	}

	/**
	 * Put the folder back, with everything that went in with it.
	 *
	 * WHOLE, and that is not this class's choice — a folder went into the trash as
	 * one item and comes out as one. {@see TrashControl::listTrashed()} spells out
	 * the same rule from the destroying side: a file that went in as part of a
	 * folder is never reached on its own.
	 */
	public function restore(): void {
		($this->restore)();
	}
}
