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
 * ## IT CARRIES BOTH VERBS NOW, AND THAT USED TO BE THE ARGUMENT AGAINST IT
 *
 * This class shipped with `restore()` alone, and its docblock said so on purpose:
 * *"a `purge()` reachable from the revive path is a `purge()` that can be called by
 * accident — the type is the guard."* That held exactly as long as a trashed folder
 * had one thing that could happen to it. `projects/purge.feature` gives it the
 * other: empty Penpot's trash and the folder mirroring the destroyed project has
 * nothing left to be restored to, so it goes too.
 *
 * So the guard moves from the TYPE to the CALLER, where the rule actually lives —
 * {@see TrashReconcileService} purges only a folder it has proved holds nothing but
 * designs Penpot no longer has. Keeping two near-identical value objects to encode
 * a rule that is really about evidence would have been a worse lie than this
 * comment.
 *
 * {@see TrashedFile} still carries `purge()` alone, because a trashed mirror is
 * still only ever reached in order to destroy it.
 *
 * ## WHY THE CONTENTS COME WITH IT
 *
 * Deciding a folder's fate needs to know what is inside, and the answer is not
 * reachable from outside {@see TrashControl}: a trashed folder's children are not
 * separate trash entries and its node is not resolvable by path (the home trash and
 * a Team Folder's live on different mounts). The only door is
 * `ITrashItem::getTrashBackend()->listTrashFolder()`, which is the trash app's own
 * type dispatching on its own backend — exactly what this class exists to keep at
 * that boundary. So the walk happens there and the ANSWERS travel here.
 */
final class TrashedFolder {
	/**
	 * @param int $fileId the filecache id, unchanged by the trip through the trash —
	 *                    which is what makes the folder's `penpot_project_id` readable
	 *                    here, where the path is long gone
	 * @param string $name the ORIGINAL basename, not the `.d<timestamp>` spelling the
	 *                     trash stores it under
	 * @param list<int> $designIds the filecache id of every `.penpot` below it, at any
	 *                             depth — what the caller reads each design's stamp from
	 * @param bool $holdsOtherFiles whether anything down there is NOT a design. One
	 *                              spreadsheet is enough, and it makes the folder
	 *                              un-purgeable: a file with no far side may not be
	 *                              destroyed by something that happened in Penpot
	 * @param \Closure():void $restore put this folder back where it came from, through
	 *                                 whichever trash backend is holding it
	 * @param \Closure():void $purge destroy it, and everything that went in with it
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		public readonly array $designIds,
		public readonly bool $holdsOtherFiles,
		private readonly \Closure $restore,
		private readonly \Closure $purge,
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

	/**
	 * Destroy the folder and everything under it. There is no undo past here.
	 *
	 * WHOLE, for the same reason {@see restore()} is: a folder went into the trash as
	 * one item, and the trash offers no way to take part of it out or leave part of
	 * it behind. That is why {@see $holdsOtherFiles} exists — the caller cannot spare
	 * the spreadsheet, so it has to spare the folder.
	 */
	public function purge(): void {
		($this->purge)();
	}
}
