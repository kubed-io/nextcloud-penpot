<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * What becomes of the mirrors when a mapping is removed (`mapping/delete.feature`).
 *
 * ## THE FILE'S BYTES DECIDE, NOT THE MAPPING'S MODE
 *
 * The spec states the rule per mode — a `link` mapping's designs go, a `sync`
 * mapping's designs stay and become unmapped — and this asks the question one
 * level down, of each file: **does it hold an archive?** The two are the same
 * answer for every tree the pull builds, because mode is what decides whether a
 * mirror gets bytes ({@see ArchiveService}), and they stay the same answer for a
 * tree that is mixed, which reading the mapping's mode would get wrong.
 *
 * It also makes the rule say what it means:
 *
 *   - **no archive** — a zero-byte pointer whose only meaning was the mapping.
 *     Once that is gone there is nothing left for it to be, and offering a
 *     restore that reconnects to nothing is worse than not offering one. So it
 *     goes, permanently, exactly as {@see PullService::prune()} discards a link
 *     whose design left the mapping.
 *   - **an archive** — the design itself, and possibly the last copy of it in
 *     existence. It stays, and stops being a mirror: `penpot_mode` becomes
 *     `unmapped` and the team id is dropped. The `penpot_id` stays so a later
 *     arrival can be told apart from a stranger's file — never to be reattached
 *     to, since re-mapping imports what the file holds ({@see MotionService}).
 *
 * ## PENPOT IS NEVER CONTACTED, AND THAT IS A CONSTRAINT ON THE CODE
 *
 * Removing a mapping is an administrative act about a connection; nothing about
 * a mapping exists on Penpot's side, so there is nothing there to tear down. It
 * is why the unmapped file gets NO last-chance export the way
 * {@see MotionService::park()} does — an export is a Penpot call, and a mapping
 * removal that phoned Penpot would be doing something the admin did not ask for.
 * A file with no archive has nothing to lose by going; a file with one needs no
 * export.
 *
 * ## IT RUNS UNDER THE GUARD, OR IT DELETES THE TEAM'S WORK
 *
 * Every removal here is a `Node::delete()`, which fires the same
 * `BeforeNodeDeletedEvent` a person's delete does — and
 * {@see \OCA\PenpotSync\Listener\DeleteListener} answers that by putting the
 * design in Penpot's trash. Without {@see SyncGuard} raised, removing a link
 * mapping would delete every design it mirrored, in Penpot, from an action whose
 * whole promise is that it touches nothing there.
 *
 * ## THE TREE IS LEFT STANDING
 *
 * Only files are acted on. The project folders stay, keep their `penpot_project_id`
 * and their `penpot` tag, and go on being ordinary folders holding whatever else
 * was in them — a mapping removal is not a licence to delete somebody's folders.
 * Re-mapping the team walks back into the same tree and adopts it.
 */
final class MappingTeardownService {
	/** A ceiling on the descent, mirroring the seatbelts in {@see DeletionService}. */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly StorageService $storage,
		private readonly PenpotMetadata $metadata,
		private readonly ArchiveService $archives,
		private readonly TrashControl $trash,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Tear the mirrors down for a mapping that is about to be (or has just been)
	 * removed. Answers what it did, for the caller to report.
	 *
	 * NEVER THROWS. The mapping's removal is the act the admin asked for and it
	 * must not fail because a file would not move; each mirror is its own try, and
	 * one that resists is logged and left where it is.
	 *
	 * @return array{removed:int, unmapped:int}
	 */
	public function tearDown(Mapping $mapping): array {
		$removed = 0;
		$unmapped = 0;

		$root = $this->storage->findRoot($mapping);
		if ($root === null) {
			// Nothing was ever provisioned, or it has already been deleted by hand.
			// Either way there are no mirrors to answer for.
			return ['removed' => 0, 'unmapped' => 0];
		}

		// THE WHOLE WALK INSIDE ONE GUARD, not one guard per file: the guard is a
		// depth counter and re-entering it per node would cost nothing but say less
		// — this is one operation, and a delete that escaped the fence would reach
		// Penpot.
		$this->guard->run(function () use ($root, &$removed, &$unmapped): void {
			foreach ($this->mirrorsBelow($root, 0) as $node) {
				if ($this->archives->holdsArchive($node)) {
					$unmapped += $this->unmap($node) ? 1 : 0;
					continue;
				}
				$removed += $this->remove($node) ? 1 : 0;
			}
		});

		$this->logger->info('penpot_sync: tore down a mapping\'s mirrors', [
			'app' => Application::APP_ID,
			'mapping' => $mapping->id,
			'team' => $mapping->teamName !== '' ? $mapping->teamName : $mapping->teamId,
			'removed' => $removed,
			'unmapped' => $unmapped,
		]);

		return ['removed' => $removed, 'unmapped' => $unmapped];
	}

	/**
	 * Every mirror at or below $folder — a `.penpot` file carrying a `penpot_id`.
	 *
	 * THE ID IS WHAT MAKES IT OURS. A `.penpot` somebody dropped into the mapped
	 * folder themselves carries none, and it is not this app's to remove or to
	 * re-label: the whole point of a mapped folder is that it stays usable as a
	 * folder. Same test {@see PullService::collectMirrors()} applies, for the same
	 * reason.
	 *
	 * Collected before anything is deleted, so the walk is never reading a listing
	 * it is concurrently changing.
	 *
	 * @return list<File>
	 */
	private function mirrorsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not list a folder while tearing a mapping down', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return [];
		}

		$found = [];
		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->mirrorsBelow($child, $depth + 1) as $mirror) {
					$found[] = $mirror;
				}
				continue;
			}
			if ($child instanceof File && $this->isMirror($child)) {
				$found[] = $child;
			}
		}

		return $found;
	}

	/** A `.penpot` this app mirrored, as opposed to one somebody put there. */
	private function isMirror(Node $node): bool {
		if (!str_ends_with($node->getName(), PullService::EXTENSION)) {
			return false;
		}

		try {
			return ($this->metadata->readFile($node->getId())?->penpotId ?? '') !== '';
		} catch (\Throwable) {
			// A file this app cannot identify is never a file it may act on.
			return false;
		}
	}

	/**
	 * A pointer with the mapping gone. Remove it, WITH NO TRASH ENTRY.
	 *
	 * The same call and the same reasoning as {@see PullService::prune()}'s
	 * discard: the file holds nothing, so a trash entry would offer a restore of
	 * an empty file — which is not a recovery, it is a way to be confused later.
	 */
	private function remove(File $node): bool {
		$path = $node->getPath();

		try {
			$this->trash->withoutTrash(static function () use ($node): void {
				$node->delete();
			});
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not remove a pointer whose mapping was removed', [
				'app' => Application::APP_ID,
				'file' => $path,
				'exception' => $e,
			]);

			return false;
		}

		return true;
	}

	/**
	 * An archive with the mapping gone. Keep the file, drop the connection.
	 *
	 * THE `penpot_id` STAYS, but NOT as a claim on the design it names. Re-mapping
	 * the team imports what this file holds and mints a fresh id, exactly as moving
	 * one back into a mapping does ({@see MotionService::onMove()}) — the bytes here
	 * are what the user has, so they are what must survive.
	 *
	 * It stays because a file carrying an id is distinguishable from one that was
	 * never a mirror, which is what {@see MotionService::idIsSpokenFor()} reads to
	 * stop two files claiming one design. An unmap is still not a wipe; the id
	 * simply stopped meaning "reattach me".
	 */
	private function unmap(File $node): bool {
		try {
			$this->metadata->writeFile($node->getId(), [
				PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
				PenpotMetadata::KEY_TEAM_ID => '',
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not unmap a design whose mapping was removed', [
				'app' => Application::APP_ID,
				'file' => $node->getPath(),
				'exception' => $e,
			]);

			return false;
		}

		return true;
	}
}
