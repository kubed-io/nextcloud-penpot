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
 * "Sync to Penpot" — the archives already sitting in a mapped folder become
 * designs (`connection/sync-now.feature`).
 *
 * ## NOT {@see PushService}, WHICH IS THE RENAME WRITEBACK
 *
 * That one reacts to a single completed gesture and sends the one write Penpot
 * permits on an object it already has: a rename. This one is the bulk button —
 * it walks every mapping and CREATES designs out of archives Penpot has never
 * seen. Neither touches the shape data of an existing design, which is the line
 * §6.1 actually draws.
 *
 * ## THE ABSENCE THIS REPLACES WAS A MISREADING, NOT A BOUNDARY
 *
 * Three files used to say, in almost identical words, that this app is read-only
 * for design content (§6.1) and that a push was therefore never coming. That
 * over-read the rule. What §6.1 forbids is pushing SHAPE DATA into a design
 * Penpot already holds — editing someone's file underneath them. It says nothing
 * about a `.penpot` Penpot has never seen, and the app already creates projects,
 * renames them, and imports whole archives as new designs on every drag-and-drop.
 *
 * So the boundary is real and this is on the safe side of it: an archive that
 * names no design becomes one; a file that already names a design is LEFT ALONE.
 * Nothing here ever overwrites Penpot's copy of anything.
 *
 * ## IT IS THE IMPORT DOOR, NOT A SECOND ONE
 *
 * A file that has never been pushed is exactly a file that was dragged into a
 * mapped folder, one press of a button later. Both go through
 * {@see ImportService::adopt()}, and the destination is resolved by the same
 * {@see DestinationResolver::projectForContentIn()} every other arrival uses —
 * so a folder that is not a project yet BECOMES one, named by its path below the
 * mapping (§C6.38), and a file at the mapping root lands in Drafts (§6.35).
 *
 * Writing a bespoke walk here would have meant a second answer to "which project
 * does this file belong to", and the two would drift.
 *
 * ## `link` MAPPINGS ARE SKIPPED WHOLE, NOT FILE BY FILE
 *
 * A `link` mapping's contents come from Penpot and nowhere else: its mirrors are
 * zero-byte pointers by construction ({@see ArchiveService}), so there is nothing
 * for a push to send. The skip is at the MAPPING level rather than per file
 * because that is where the fact lives — an archive under a link mapping is a
 * state the app has no way to produce, and quietly importing whatever turned up
 * there would be inventing a design out of a file whose provenance we do not know.
 *
 * ## IT RUNS UNDER THE GUARD
 *
 * Every stamp {@see ImportService::adopt()} writes fires a write event, and
 * {@see \OCA\PenpotSync\Listener\WriteListener} answers those. Without
 * {@see SyncGuard} raised, a push would re-enter itself through the very
 * listeners it is imitating.
 */
final class BulkPushService {
	/** A ceiling on the descent, mirroring the seatbelts in {@see PullService}. */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly MappingService $mappings,
		private readonly StorageService $storage,
		private readonly PenpotMetadata $metadata,
		private readonly ArchiveService $archives,
		private readonly MembershipResolver $resolver,
		private readonly DestinationResolver $destinations,
		private readonly ImportService $imports,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Push every mapping — the bulk "Sync to Penpot" action.
	 *
	 * NEVER THROWS. One mapping that will not answer must not cost the rest; each
	 * failure is counted and named, and the caller reports the summary.
	 *
	 * @return array{processed:int, pushed:int, failed:int, skipped:int, status:string, message:?string}
	 */
	public function push(?string $mappingId = null): array {
		$processed = 0;
		$pushed = 0;
		$failed = 0;
		$skipped = 0;
		$errors = [];

		foreach ($this->mappings->list() as $mapping) {
			if ($mappingId !== null && $mapping->id !== $mappingId) {
				continue;
			}

			$res = $this->pushOne($mapping);
			$processed += $res['processed'];
			$pushed += $res['pushed'];
			$failed += $res['failed'];
			$skipped += $res['skipped'];
			if ($res['error'] !== null) {
				$errors[] = $res['error'];
			}
		}

		$this->logger->info('penpot_sync push: finished', [
			'app' => Application::APP_ID,
			'processed' => $processed,
			'pushed' => $pushed,
			'failed' => $failed,
			'skipped' => $skipped,
		]);

		return [
			'processed' => $processed,
			'pushed' => $pushed,
			'failed' => $failed,
			'skipped' => $skipped,
			'status' => $failed === 0 ? PullStatus::OK : PullStatus::ERROR,
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * One mapping's archives.
	 *
	 * PRIVATE, unlike the sibling's equivalent: every caller reaches the push
	 * through {@see push()}, which is where the per-mapping filter lives. A public
	 * second door would be a second answer to "what does a push do", and the two
	 * would drift.
	 *
	 * @return array{processed:int, pushed:int, failed:int, skipped:int, error:?string}
	 */
	private function pushOne(Mapping $mapping): array {
		$nothing = ['processed' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0, 'error' => null];

		// THE WHOLE POINT OF THE `skipped` COUNTER. A link mapping is not an error
		// and not a no-op worth hiding — the admin pressed a button and this mapping
		// deliberately did nothing, which the panel should be able to say.
		if ($mapping->mode !== Mapping::MODE_SYNC) {
			return ['processed' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 1, 'error' => null];
		}

		$root = $this->storage->findRoot($mapping);
		if ($root === null) {
			// Never provisioned, or deleted by hand. Nothing to walk.
			return $nothing;
		}

		// THE ROOT IS ALREADY MARKED, and this used to stamp it here.
		//
		// `penpot_team_id` on the root is what the nearest-ancestor walk starts
		// from, so an unmarked root makes every file below resolve to no team and
		// this whole run a silent no-op. That was true of a mapping nobody had
		// pulled, because the pull was the only writer of it — which is a property
		// of provisioning masquerading as a property of syncing.
		//
		// Fixed where it belongs instead: {@see StorageService::ensureRoot()} marks
		// the root, so the folder means something from the moment the mapping is
		// saved. `findRoot()` above therefore answers an already-marked folder, and
		// a push over a brand-new mapping works with no pull first.
		$candidates = [];
		$processed = 0;
		$pushed = 0;
		$failed = 0;
		$errors = [];

		$this->guard->run(function () use ($root, &$candidates, &$processed, &$pushed, &$failed, &$errors): void {
			$this->collectPushable($root, $candidates, 0);

			foreach ($candidates as $node) {
				$processed++;
				try {
					if ($this->pushFile($node)) {
						$pushed++;
					}
				} catch (\Throwable $e) {
					$failed++;
					$errors[] = $node->getName() . ': ' . $e->getMessage();
					$this->logger->warning('penpot_sync push: could not make a design of a file', [
						'app' => Application::APP_ID,
						'file' => $node->getPath(),
						'exception' => $e,
					]);
				}
			}
		});

		return [
			'processed' => $processed,
			'pushed' => $pushed,
			'failed' => $failed,
			'skipped' => 0,
			'error' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * One archive becomes one design, through the door every other arrival uses.
	 *
	 * Answers false when the file turned out not to be pushable after all — which
	 * is not a failure. {@see ImportService::adopt()} declines a `.penpot` that is
	 * not really a ZIP, and a folder that cannot be promoted to a project resolves
	 * to no destination; both leave an ordinary file, which is exactly what it was.
	 */
	private function pushFile(File $node): bool {
		$membership = $this->resolver->resolve($node);
		if (!$membership->belongsToPenpot()) {
			// The tree said this file is in the mapping and the markers disagree.
			// Reachable while a mapping is provisioned but not yet pulled, and the
			// honest answer is to leave it for the pull that will mark the folders.
			return false;
		}

		$project = $this->destinations->projectForContentIn($node, $membership);
		if ($project === null) {
			return false;
		}

		return $this->imports->adopt($node, $project, $membership->teamId) !== null;
	}

	/**
	 * Every `.penpot` below $folder that names no design yet.
	 *
	 * Collected BEFORE anything is imported, so the walk never reads a listing it
	 * is concurrently stamping.
	 *
	 * THE TEST IS "HOLDS BYTES, AND IS NOT ALREADY A MIRROR OF THIS MAPPING":
	 *
	 *   - a file whose metadata is `sync` or `link` is a MIRROR — a design Penpot
	 *     already has, which §6.1 forbids pushing content into. Skipped.
	 *   - an `unmapped` file is NOT that, and this is the half that is easy to get
	 *     backwards. It carries a `penpot_id`, but the id names a design that was
	 *     trashed when the file left the mapping and must never be reattached to
	 *     (`designs/move.feature`, "an arrival becomes its own design, whatever it
	 *     arrived carrying"). Its bytes are somebody's work sitting in a mapped
	 *     folder with nothing in Penpot answering to them, which is exactly what
	 *     this button is for. Imported, minting a new id — the same answer a drag
	 *     into the folder would have given.
	 *   - an UNTRACKED file (no metadata at all) is the ordinary case: a `.penpot`
	 *     somebody uploaded. Imported.
	 *   - a file holding NO archive is a zero-byte pointer or an empty create.
	 *     There is nothing to import, and inventing an empty design beside it is
	 *     the destructive act {@see ImportService} exists to avoid.
	 *
	 * ## THE SIBLINGS SKIP `unmapped` HERE, AND COPYING THEM WOULD BE WRONG
	 *
	 * Grafana's push lists no unmapped file at all, and it is right to: a Grafana
	 * file KEEPS ITS UID when it leaves a mapping and restores to the SAME
	 * dashboard, so its push upserts on that uid and a re-arriving file reattaches
	 * with nothing to decide.
	 *
	 * Penpot cannot do that. A design trashed on the way out cannot be resurrected
	 * (§6.20) and `import-binfile` always mints a new id — which is why the
	 * reattach was removed from this app entirely and `designs/move.feature` now
	 * says an arrival becomes its own design whatever it arrived carrying. Skipping
	 * unmapped files here would leave real bytes sitting in a mapped folder that
	 * this app has decided never to make a design of, which is the state the button
	 * exists to clear.
	 *
	 * @param list<File> $out
	 */
	private function collectPushable(Folder $folder, array &$out, int $depth): void {
		if ($depth >= self::MAX_DEPTH) {
			return;
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync push: could not list a folder', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return;
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				$this->collectPushable($child, $out, $depth + 1);
				continue;
			}
			if (!$child instanceof File || !str_ends_with($child->getName(), PullService::EXTENSION)) {
				continue;
			}

			try {
				$meta = $this->metadata->readFile($child->getId());
				// A live mirror of a design Penpot already holds. `unmapped` falls
				// through deliberately — see the docblock.
				if ($meta !== null && $meta->isManaged() && !$meta->isUnmapped()) {
					continue;
				}
				if (!$this->archives->holdsArchive($child)) {
					continue;
				}
			} catch (\Throwable) {
				// A file this app cannot read is never a file it may act on.
				continue;
			}

			$out[] = $child;
		}
	}
}
