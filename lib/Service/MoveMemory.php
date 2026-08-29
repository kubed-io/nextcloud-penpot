<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * What a `.penpot` file was carrying just before it moved — held for the length
 * of one request, so a CROSS-STORAGE move can be recognised as a move at all.
 *
 * ## THE PROBLEM THIS EXISTS FOR (`designs/move.feature`, "Move a design into another team")
 *
 * Nextcloud destroys a file's Files-Metadata when it crosses a storage boundary.
 * Not as a side effect of re-creating the file — **the file id survives** — but
 * deliberately: the source cache entries are removed, that raises
 * `CacheEntriesRemovedEvent`, and core's own `MetadataDelete` listener drops
 * every `files_metadata` row for those ids.
 *
 * MEASURED ON A LIVE INSTANCE, with a same-storage rename as the control in the
 * same script and the same run:
 *
 *   same storage:  id preserved, `penpot_id` still readable afterwards
 *   cross storage: id preserved, `penpot_id` GONE
 *
 * By the time {@see MotionService::onMove()} runs, then, a design dragged from a
 * home folder into a Team Folder looks exactly like a stranger: a `.penpot` with
 * no id, which the §6.33 branch imports as a BRAND NEW DESIGN. The user asked for
 * a move and got a duplicate, with a new id and no history — the one outcome
 * `designs/move.feature` says a cross-team move must never produce.
 *
 * ## WHY A MEMORY AND NOT A LOOKUP
 *
 * There is nothing left to look up. The row is gone, synchronously, during the
 * move; the file id it was keyed on is now the TARGET's id and answers nothing.
 * The last moment the metadata exists is `BeforeNodeRenamedEvent`, so that is
 * where {@see \OCA\PenpotSync\Listener\MoveMemoryListener} reads it, and this is
 * where it waits until the completed-move listener asks.
 *
 * ## IN-PROCESS, LIKE {@see SyncGuard}, AND FOR THE SAME REASON
 *
 * Both halves of one gesture — the before event and the after event — run in a
 * single request, in a single process. Nothing here needs to outlive that, and
 * anything that DID would be a cache with an invalidation problem instead of a
 * variable with a lifetime. A worker that somehow saw only the second half reads
 * an empty memory and takes the import branch, which is exactly today's
 * behaviour.
 */
final class MoveMemory {
	/**
	 * A ceiling, in the same spirit as the depth seatbelts elsewhere in the
	 * service layer. A request that moves this many `.penpot` files is not a
	 * gesture, and an `occ` process that lives for hours must not accumulate a
	 * row per file it ever touched. Oldest entries go first — the fresh ones are
	 * the ones about to be asked for.
	 */
	private const MAX_ENTRIES = 512;

	/** @var array<int, PenpotFileMetadata> file id => what it carried before the move */
	private array $remembered = [];

	/** Note what $fileId was carrying, replacing any earlier note for it. */
	public function remember(int $fileId, PenpotFileMetadata $meta): void {
		// Re-inserting moves the key to the end, so the eviction below stays
		// insertion-ordered even when the same file moves twice in one request.
		unset($this->remembered[$fileId]);
		$this->remembered[$fileId] = $meta;

		// NOT `array_shift()`: it REINDEXES an integer-keyed array, which would
		// renumber every remaining file id to 0, 1, 2 and make each note describe
		// a file it has nothing to do with. Unsetting the first key leaves the
		// rest exactly as they are.
		while (count($this->remembered) > self::MAX_ENTRIES) {
			$oldest = array_key_first($this->remembered);
			if ($oldest === null) {
				break;
			}
			unset($this->remembered[$oldest]);
		}
	}

	/** What $fileId was carrying, or null when nothing noted it. */
	public function recall(int $fileId): ?PenpotFileMetadata {
		return $this->remembered[$fileId] ?? null;
	}

	/** Drop the note for $fileId — the move it belonged to is over. */
	public function forget(int $fileId): void {
		unset($this->remembered[$fileId]);
	}
}
