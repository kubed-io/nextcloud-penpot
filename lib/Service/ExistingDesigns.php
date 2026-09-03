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
use Psr\Log\LoggerInterface;

/**
 * The `.penpot` files already sitting under a folder a mapping is about to claim
 * (`mapping/create.feature`).
 *
 * ## WHAT THIS PREVENTS IS A STATE THE APP HAS NO ANSWER FOR
 *
 * A `link` mirror is a zero-byte pointer. A `.penpot` holding an archive inside a
 * link mapping is a contradiction, and every rule that reads one has to guess
 * which it is — {@see MappingTeardownService} keys on the bytes and keeps it,
 * `mapping/delete.feature` says a link mapping's designs all go, and both are
 * right about a tree that should not exist.
 *
 * It is not hypothetical. A live instance reached it in three steps: a folder
 * mapped `sync`, the mapping removed (leaving real archives behind, unmapped),
 * then re-mapped `link` over them. Removing that mapping took the pointers and
 * kept the archives — the teardown working exactly as written and the spec's
 * promise failing, at the same time. CI could not have caught it, because every
 * scenario there builds a clean tree.
 *
 * So the contradiction is designed out at the only moment it can be created.
 *
 * ## PURGED, NOT TRASHED — THE ONE PLACE THIS APP DESTROYS SOMETHING
 *
 * A trashed design offers a restore, and restoring INTO a link mapping is already
 * ruled out: Penpot has no write path for design content, so there is nowhere for
 * the bytes to go. Rather than invent an answer for a restore that cannot work,
 * the files never reach the trash.
 *
 * Which is why {@see under()} exists as its own call. Nothing here purges without
 * the admin having been told HOW MANY and that they are not recoverable, and the
 * count has to come from the same walk that does the deleting or the number in the
 * warning is a different question's answer.
 *
 * ## IT RUNS UNDER THE GUARD, OR IT DELETES THE DESIGNS IN PENPOT
 *
 * The files this destroys are `unmapped`, and an unmapped design KEEPS its
 * `penpot_id` — that is the whole point of the state ({@see MotionService}). So
 * each `delete()` here fires the same `BeforeNodeDeletedEvent` a person's delete
 * does, and {@see \OCA\PenpotSync\Listener\DeleteListener} answers that by putting
 * the design in Penpot's trash. Without {@see SyncGuard} raised, clearing a folder
 * so it can be mirrored would delete the very designs it is about to mirror.
 *
 * ## ONLY `link`, AND ONLY UNMAPPED
 *
 * A `sync` mapping adopts what it finds and imports it (§6.33), so nothing is
 * destroyed and nothing is confirmed — the caller decides that, not this class.
 * And a tree that already belongs to a mapping never reaches here: a folder in use
 * is refused first ({@see MappingService::assertFolderUnique()}), and a mapping may
 * not be made under or over another. So "no `.penpot` anywhere in the tree" holds
 * implicitly for every mapped tree without being checked.
 */
final class ExistingDesigns {
	/**
	 * The ceiling every downward walk in this app shares. {@see Walk::MAX_DEPTH}
	 *
	 * REACHING IT REFUSES THE MAPPING, it does not quietly end the walk. See
	 * {@see designsBelow()}: not knowing what is down there is the same answer as
	 * not being able to read it, and both have to fail closed or this guard has a
	 * door left open in it.
	 */
	private const MAX_DEPTH = Walk::MAX_DEPTH;

	public function __construct(
		private readonly StorageService $storage,
		private readonly TrashControl $trash,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Every `.penpot` at or below the folder this mapping would claim.
	 *
	 * ANSWERS `[]` FOR A FOLDER THAT IS NOT THERE, which is the ordinary case: most
	 * mappings are made against a name nothing has used yet, and a folder that does
	 * not exist holds no designs to warn about.
	 *
	 * COUNTS EVERY `.penpot`, TRACKED OR NOT, and that is deliberate. The mapped
	 * tree is about to be filled from Penpot, and a design the app has never heard
	 * of is no more able to survive there than one it has: both are archives in a
	 * folder that may only hold pointers. The distinction {@see PullService} draws
	 * between mirrored and untracked is about ownership, and ownership is not the
	 * question here.
	 *
	 * @return list<File>
	 *
	 * @throws \InvalidArgumentException when the tree cannot be scanned to the
	 *                                   bottom — unreadable, or deeper than the
	 *                                   ceiling. Not knowing is never `[]` here;
	 *                                   see {@see MAX_DEPTH}.
	 */
	public function under(Mapping $mapping): array {
		$root = $this->storage->findRoot($mapping);

		return $root === null ? [] : $this->designsBelow($root, 0);
	}

	/**
	 * Destroy them, permanently, and answer how many went.
	 *
	 * NEVER THROWS. A file that will not delete is logged and stepped over: the
	 * mapping this clears the way for has already been created, and failing here
	 * would leave the admin with a mapping they cannot see and an error they cannot
	 * act on. The survivor is visible in the folder and in the log.
	 *
	 * @param list<File> $designs from {@see under()}, so the count the admin
	 *                            acknowledged is the set that is destroyed
	 */
	public function purge(array $designs): int {
		if ($designs === []) {
			return 0;
		}

		$purged = 0;

		// ONE GUARD FOR THE WHOLE SWEEP. See the class docblock: without it every
		// delete below reaches Penpot and deletes the design the file points at.
		$this->guard->run(function () use ($designs, &$purged): void {
			foreach ($designs as $design) {
				$path = $design->getPath();

				try {
					$this->trash->withoutTrash(static function () use ($design): void {
						$design->delete();
					});
				} catch (\Throwable $e) {
					$this->logger->warning('penpot_sync: could not purge a design to make way for a link mapping', [
						'app' => Application::APP_ID,
						'file' => $path,
						'exception' => $e,
					]);

					continue;
				}

				$purged++;
				$this->logger->info('penpot_sync: purged a design to make way for a link mapping', [
					'app' => Application::APP_ID,
					'file' => $path,
				]);
			}
		});

		return $purged;
	}

	/**
	 * @return list<File>
	 */
	private function designsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			// A FOLDER TOO DEEP TO SCAN IS NOT AN EMPTY FOLDER, which is word for word
			// the reasoning of the unreadable case below — and this branch answered
			// `[]` while that one threw, so the class failed closed on one way of not
			// knowing and open on the other. Copilot caught the same split in the
			// sibling, in code ported from here.
			//
			// `[]` FROM THIS METHOD IS A VERDICT, not a stopping point: it says the
			// folder holds no designs, so a `link` mapping may be made over it and the
			// purge has nothing to destroy. Below the ceiling the designs really are
			// there, and the mapping is created over them — the exact state this class
			// exists to prevent, reached through the one door left unlocked.
			$this->logger->error('penpot_sync: a folder tree was too deep to scan for existing designs', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'depth' => $depth,
			]);

			// `%d levels deep`, NOT `more than %d`. The guard is `>=`, so this fires on
			// a folder sitting at exactly the ceiling — the last rung the walk can
			// still see. Claiming it is deeper than that is an off-by-one in a
			// sentence an admin has to act on.
			throw new \InvalidArgumentException(sprintf(
				'"%s" is nested %d levels deep, which is as far as this app scans, so it is not '
				. 'possible to tell whether it already holds designs. Nothing was changed — map a '
				. 'folder nearer the top, or flatten the tree.',
				$folder->getName(),
				self::MAX_DEPTH,
			));
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			// AN UNREADABLE FOLDER IS NOT AN EMPTY ONE, and this used to say so and
			// then return `[]` anyway — the comment and the code disagreeing, which
			// Copilot caught on #48. Answering "nothing found" here would let the
			// mapping be created over designs nobody could see, which is precisely
			// the state this class exists to prevent.
			//
			// SO IT FAILS CLOSED, as an `InvalidArgumentException` — the type both
			// front doors already turn into a refusal the admin can read, rather than
			// a 500 from the panel and a stack trace from `occ`. A folder that cannot
			// be listed is a folder nothing should be mapped over.
			$this->logger->error('penpot_sync: could not read a folder while looking for existing designs', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			throw new \InvalidArgumentException(sprintf(
				'The contents of "%s" could not be read, so it is not possible to tell whether it '
				. 'already holds designs. Nothing was changed — try again, and check the folder\'s '
				. 'permissions if this persists.',
				$folder->getName(),
			), 0, $e);
		}

		$found = [];
		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->designsBelow($child, $depth + 1) as $nested) {
					$found[] = $nested;
				}
				continue;
			}
			if ($child instanceof File && str_ends_with($child->getName(), PullService::EXTENSION)) {
				$found[] = $child;
			}
		}

		return $found;
	}
}
