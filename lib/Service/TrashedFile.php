<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * One file sitting in a Nextcloud trash, reduced to the three things this app has
 * any business knowing about it: which file it is, what it was called, and how to
 * destroy it.
 *
 * ## WHY THIS EXISTS INSTEAD OF PASSING `ITrashItem` AROUND
 *
 * `OCA\Files_Trashbin\Trash\ITrashItem` lives in the trashbin APP's namespace, not
 * OCP, and `files_trashbin` is removable — the same fact that already makes
 * {@see TrashControl} resolve its manager lazily. Every signature naming the
 * interface is a file psalm cannot resolve and a class the unit suite cannot load,
 * which here is every machine: the unit suite runs against `nextcloud/ocp` alone.
 *
 * So the interface stops at {@see TrashControl}'s boundary and callers get this.
 * The decision of WHICH trashed mirrors to destroy — the part with the rules in it,
 * and the part worth testing — then lives in ordinary code with no trash types in
 * sight ({@see TrashReconcileService}).
 *
 * ## THE PURGE IS A CLOSURE, NOT AN ID TO LOOK UP AGAIN
 *
 * `ITrashManager::removeItem()` needs the item OBJECT, because it dispatches on the
 * item's own backend — a Team Folder's trash and a home trash destroy through
 * entirely different code. There is no stable "purge by file id" call to hand back
 * instead, and re-finding the item by name would race the listing it came from.
 * Holding the bound call keeps the dispatch inside the trash app's own types.
 */
final class TrashedFile {
	/**
	 * @param int $fileId the filecache id, unchanged by the trip through the trash —
	 *                    which is what makes the file's `penpot_*` metadata readable
	 *                    here, where the path is long gone
	 * @param string $name the ORIGINAL basename, not the `.penpot.d<timestamp>`
	 *                     spelling the trash stores it under
	 * @param \Closure():void $purge permanently delete this item, through whichever
	 *                               trash backend is holding it
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		private readonly \Closure $purge,
	) {
	}

	/** Destroy this trash entry. There is no undo past here — that is the point of it. */
	public function purge(): void {
		($this->purge)();
	}
}
