<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * The pull (Penpot → Nextcloud) — the heart of the app (saga Ch2 Course 3).
 *
 * For each mapped team it provisions the root folder ({@see StorageService}),
 * then walks the team's Penpot hierarchy and mirrors it:
 *
 *     get-all-projects  (filtered to the team)      → a folder per project
 *       └ get-project-files                          → a `.penpot` link file each
 *
 * **1 + P calls per team, zero exports** for the whole tree (saga §5.5): the
 * per-file `revn` rides in `get-project-files`, so nothing needs a per-file
 * fetch, and `link` files never call `export-binfile`.
 *
 * ## WHAT EACH NODE CARRIES — THE METADATA IS THE CONTRACT
 *
 *   - the **root** folder is stamped `penpot_team_id`;
 *   - a **project** folder is stamped `penpot_project_id`;
 *   - a **`.penpot`** file is stamped `penpot_id` / `penpot_revision` /
 *     `penpot_mode` ({@see PenpotMetadata}).
 *
 * Those stamps — not folder names or positions — are what {@see MembershipResolver}
 * reads back, and what makes the pull idempotent: a project folder is matched by
 * its `penpot_project_id`, a file by its `penpot_id`, so a re-pull updates in
 * place and never duplicates.
 *
 * ## DRAFTS IS A STATE, NEVER A FOLDER (saga §6.35)
 *
 * Penpot's default (Drafts) project is real in the API, but no `Drafts` folder
 * is ever created — its files land directly at the team root. A file at the root
 * is therefore, by position, a draft; that is the resolver's team-only state.
 *
 * ## DELIBERATELY DEFERRED IN THIS INCREMENT (documented, not forgotten)
 *
 *   - **The project-folder visible tag** (§6.32) — WITHDRAWN, not deferred. See
 *     {@see ensureProjectFolder()}: the pull marks a project folder with metadata
 *     and nothing else.
 *   - **Adopting a mirror out of the Nextcloud trash** (§6.37) — HALF CLOSED. A
 *     whole project folder that comes back in Penpot is now taken back out of the
 *     trash rather than re-created beside it ({@see revivedProjectFolder()}). A
 *     single DESIGN whose mirror was trashed on its own is still re-created beside
 *     that mirror rather than matched to it by `penpot_id`, and no scenario asks
 *     for that half yet. Untidy, not lossy.
 *   - **The `/` guard as a reported skip** (§6.51) — a FILE whose Penpot name
 *     contains `/` is skipped and logged here, and Course 4 turns that into the
 *     user-facing report. A file is ONE node and no amount of nesting can express
 *     a slash inside its name, so that guard is permanent. A PROJECT's name is a
 *     PATH and is no longer guarded at all — see below.
 *
 * ## A PROJECT'S NAME IS A PATH, IN BOTH DIRECTIONS (§C6.38)
 *
 * {@see PushService} has always renamed a Penpot project to its path below the
 * mapping when its folder moves — dragging `Penpot/Traveller` into
 * `Penpot/Clients` names the project `Clients/Traveller`, because the bare name
 * cannot express where it went. The pull did not read that back: it rejected any
 * name holding a `/` as an illegal node name and skipped the project entirely.
 *
 * So the app WROTE names it then REFUSED TO READ, and the cost was not just the
 * unmirrored project. A skipped project clears `$complete`, which switches off
 * the prune AND {@see reapOrphanProjects()} for the whole mapping — so a single
 * nested project silently disabled every reconciliation that mapping had, for
 * good. Measured live: a project renamed to `Bubbles/foo` in Penpot produced no
 * folder, moved no design, and stopped its mapping pruning.
 *
 * A slash is therefore a LEVEL, not an illegal character. The path is provisioned
 * ({@see ensureFolderPath()}), the project folder is moved to the end of it
 * ({@see tryMoveProject()}), and the empty scaffolding a project moves out of is
 * cleared behind it ({@see tidy()}). Only the LAST segment is the project folder;
 * everything above it is an ordinary folder carrying no markers, which is exactly
 * what a hand-made `Clients/` folder is and what {@see MembershipResolver}'s
 * nearest-ancestor walk already expects.
 *
 * ## THE PRUNE, AND WHY IT IS GATED ON A COMPLETE LISTING (saga §6.25)
 *
 * A mirror whose Penpot design was deleted is moved to the **Nextcloud trash**,
 * never hard-deleted. It runs from a `penpot_id` seen-set built while walking the
 * team, and **only when every project in that team listed cleanly** — a failed,
 * partial, or skipped listing is indistinguishable from "everything was deleted",
 * and reading a network blip as evidence that a user's files are gone is the one
 * mistake this app must never make. Skipping is therefore *not* free: anything
 * passed over — a project Penpot named with no id, one whose folder would not
 * provision, a file with no id — takes the whole prune down with it, because
 * those files are exactly as unseen as deleted ones.
 *
 * ## THE FINAL SNAPSHOT (saga §6.42/§6.46)
 *
 * A pruned `link` points at something that no longer exists — the app's one
 * genuinely lossy moment. But `export-binfile` still exports a soft-deleted file
 * for as long as Penpot's own trash holds it, so a `link` gets **one last export**
 * on its way out and is trashed as a real archive. Best-effort by construction:
 * past the grace window the export fails, and the empty mirror is trashed anyway and
 * counted as lost rather than a snapshot being faked.
 *
 * ## `sync` MODE: THE ONLY THING THAT COSTS ANYTHING (saga §6.22)
 *
 * A `link` file's body is rewritten every pull because it is a few hundred bytes
 * of JSON. A `sync` file's body is a full export, so it is fetched only when
 * {@see driftedOrMissing()} says it must be:
 *
 *   - the stored `revn`+`modified-at` signal moved (the design changed), or
 *   - the file is stamped `sync` but holds no archive — a promotion whose export
 *     has not succeeded yet, which this makes self-healing.
 *
 * **The revision stamp only advances when the mirror is genuinely current.** A
 * failed export leaves the old signal in place, so the next pull retries instead
 * of recording a lie and never looking again. That is §6.18 rule 3 read from the
 * other direction: a remote failure must not silently rewrite local state.
 */
final class PullService {
	/** Nextcloud-side extension for a mirrored Penpot design (saga §6.4). */
	public const EXTENSION = '.penpot';

	/**
	 * A ceiling on every descent and every nested path here — {@see indexProjectFolders()},
	 * {@see orphanProjectFolders()}, {@see tidy()} and the segment count a project
	 * name may have — mirroring the seatbelts in {@see MembershipResolver},
	 * {@see DeletionService} and {@see PushService}.
	 */
	private const MAX_DEPTH = Walk::MAX_DEPTH;

	public function __construct(
		private readonly MappingService $mappings,
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly StorageService $storage,
		private readonly ArchiveService $archives,
		private readonly SyncGuard $guard,
		private readonly MirrorTimes $times,
		private readonly TrashControl $trash,
		private readonly TrashReconcileService $trashReconcile,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Cross-mapping project-folder index, built at most once per pull.
	 *
	 * Null means "not built yet" — distinct from an empty array, which means
	 * "built, and no other mapping holds a project folder". {@see pullOne()}
	 * resets it so one pull never answers with a tree another pull walked.
	 *
	 * @var array<string, Folder>|null
	 */
	private ?array $foreignIndex = null;

	/**
	 * Pull one mapping, or every mapping when `$mappingId` is null/empty.
	 *
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, reaped:int, orphaned:int, status:string, message:?string}
	 */
	public function pull(?string $mappingId): array {
		if ($mappingId !== null && $mappingId !== '') {
			$mapping = $this->mappings->getById($mappingId);
			if ($mapping === null) {
				throw new \OutOfBoundsException('Mapping not found: ' . $mappingId);
			}
			return $this->finalise([$this->pullOne($mapping)]);
		}
		$results = [];
		foreach ($this->mappings->list() as $mapping) {
			$results[] = $this->pullOne($mapping);
		}
		return $this->finalise($results);
	}

	/**
	 * Pull a single mapping into its Nextcloud root folder.
	 *
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, reaped:int, orphaned:int, error:?string}
	 */
	public function pullOne(Mapping $mapping): array {
		if (!$this->storage->isAvailable($mapping)) {
			$this->logger->warning('penpot_sync pull skipped: storage backend not available for this mapping', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'use_team_folder' => $mapping->useTeamFolder,
			]);
			return $this->tally(['skipped' => 1]);
		}

		try {
			// The pull renames mirror nodes to follow Penpot ({@see tryRename} →
			// Node::move), which fires the same NodeRenamedEvent a user's rename
			// does. Raise the guard so the write-path listener ignores our own
			// corrections instead of pushing them straight back to Penpot — the
			// wall between the pull (Penpot → NC) and the writeback (NC → Penpot).
			return $this->guard->run(function () use ($mapping): array {
				// Per-pull, not per-service: the tree it indexes is edited by the very
				// relocations it enables, so carrying one pull's answers into the next
				// would hand out folders that have since moved.
				$this->foreignIndex = null;
				// Same reasoning, one gesture further: this pull can take folders OUT of
				// the trash, so an index built by the last one names entries that are no
				// longer in it.
				$this->trashedProjectFolders = null;
				$root = $this->storage->ensureRoot($mapping);
				// REPAIR, NOT PROVISIONING. `ensureRoot()` marks the root itself now,
				// so this writes a value that is normally already there — kept because
				// the pull is the repair pass for a root whose marker was lost (cleared
				// by hand, or a provisioning stamp that could not write), the same way
				// it is the repair pass for a root that was deleted.
				$this->metadata->writeFolder($root->getId(), [PenpotMetadata::KEY_TEAM_ID => $mapping->teamId]);

				// Index the existing project folders ONCE (penpot_project_id -> folder)
				// rather than re-listing the root for every project below — the pull is
				// otherwise O(projects × children) on a big team.
				$folderIndex = $this->indexProjectFolders($root);

				// EVERY MIRROR UNDER THE ROOT, by id, so a design that changed project
				// in Penpot can be MOVED rather than duplicated. Built once per mapping
				// for the same reason the folder index is — the alternative is a
				// whole-tree walk per project.
				$strays = $this->collectMirrors($root);

				$folders = 0;
				$files = 0;
				$exported = 0;
				$failed = 0;
				$skipped = 0;
				$processed = 0;
				$pruned = 0;
				$rescued = 0;
				$lost = 0;
				$reaped = 0;
				$orphaned = 0;

				// Every `penpot_id` Penpot named during this walk. Anything mirrored
				// under the root and NOT in here is a candidate for the prune — which
				// is why $complete has to travel with it.
				$seen = [];
				// AND EVERY PROJECT ID PENPOT NAMED, for the folder-shaped half of the
				// same question. A folder under the root carrying a project id that is
				// NOT in here is a project somebody deleted in Penpot ({@see reapOrphanProjects}).
				$named = [];
				$complete = true;

				foreach ($this->teamProjects($mapping->teamId) as $project) {
					$processed++;
					$projectId = $this->str($project, 'id');
					if ($projectId === '') {
						$skipped++;
						$complete = false;
						continue;
					}
					$named[$projectId] = true;

					$target = $root;
					if (!$this->isDefaultProject($project)) {
						$projectName = $this->str($project, 'name');
						if (!$this->isLegalProjectName($projectName)) {
							$this->logger->warning('penpot_sync pull: skipping project with an illegal folder path', [
								'app' => Application::APP_ID,
								'project' => $projectId,
								'name' => $projectName,
							]);
							$skipped++;
							// A SKIPPED PROJECT IS AN INCOMPLETE LISTING. Its files were
							// never enumerated, so they are indistinguishable from files
							// Penpot no longer has — and pruning them would delete a whole
							// project's mirrors over one unusable name.
							$complete = false;
							continue;
						}

						try {
							$target = $this->ensureProjectFolder($root, $folderIndex, $projectId, $projectName, $mapping, $project);
						} catch (\Throwable $e) {
							// ONE PROJECT'S FOLDER FAILING IS ONE SKIPPED PROJECT, not a dead
							// mapping. Provisioning a project now walks and creates a PATH, so
							// it has more ways to fail than `newFolder()` did — a file standing
							// where a parent belongs, a quota, a permission — and letting any
							// of them out of this loop would abandon every project after it in
							// the listing. Recorded as a skip, which is what it is.
							$this->logger->warning('penpot_sync pull: could not provision a project folder', [
								'app' => Application::APP_ID,
								'project' => $projectId,
								'name' => $projectName,
								'exception' => $e,
							]);
							$skipped++;
							$complete = false;
							continue;
						}
						$folders++;
					}

					$files += $this->pullProjectFiles($target, $strays, $mapping, $projectId, $seen, $exported, $failed, $skipped, $complete);
				}

				// Only now, and only if nothing was missed. An exception on any listing
				// has already left this closure entirely, which is the same protection
				// stated as control flow rather than as a flag.
				if ($complete) {
					$this->prune($root, $mapping, $seen, $pruned, $rescued, $lost);

					// AND THE TRASH, which the prune never looks in. A mirror already in
					// the Nextcloud trash is not in `collectMirrors()`' listing, so the
					// prune above cannot see it at all — that is a separate pass over a
					// separate backend, with its own rules for what may be destroyed
					// ({@see TrashReconcileService}). Gated on `$complete` for exactly
					// the reason the prune is: an incomplete listing makes `$seen` an
					// understatement, and an understated `$seen` reads live designs as
					// gone.
					$reaped = $this->trashReconcile->reap($mapping, $seen);

					// AND THE FOLDERS THE PRUNE DOES NOT LOOK AT. `collectMirrors()`
					// gathers FILES, so a project deleted in Penpot had its designs
					// pruned and left its folder standing, still claiming an id nothing
					// answers to — see {@see reapOrphanProjects}.
					$orphaned = $this->reapOrphanProjects($root, $named, $mapping);
				}

				return $this->tally([
					'processed' => $processed,
					'folders' => $folders,
					'files' => $files,
					'exported' => $exported,
					'failed' => $failed,
					'skipped' => $skipped,
					'pruned' => $pruned,
					'rescued' => $rescued,
					'lost' => $lost,
					'reaped' => $reaped,
					'orphaned' => $orphaned,
				]);
			});
		} catch (PenpotApiException $e) {
			$this->logger->warning('penpot_sync pull failed', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return $this->tally(['error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			// A filesystem or metadata failure (ensureRoot, a folder write, a bad
			// node) must not abort every OTHER mapping in a bulk pull — contain it
			// to this mapping's error result, the same way an API failure is.
			$this->logger->error('penpot_sync pull failed unexpectedly', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return $this->tally(['error' => $e->getMessage()]);
		}
	}

	/**
	 * One per-mapping result, with every counter defaulted.
	 *
	 * Exists so a new counter is added in ONE place: the early returns above are
	 * three separate literal arrays, and the version of this that spelled them out
	 * had already drifted once.
	 *
	 * @param array{processed?:int, folders?:int, files?:int, exported?:int, failed?:int, skipped?:int, pruned?:int, rescued?:int, lost?:int, reaped?:int, orphaned?:int, error?:?string} $counts
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, reaped:int, orphaned:int, error:?string}
	 */
	private function tally(array $counts): array {
		return $counts + [
			'processed' => 0,
			'folders' => 0,
			'files' => 0,
			'exported' => 0,
			'failed' => 0,
			'skipped' => 0,
			'pruned' => 0,
			'rescued' => 0,
			'lost' => 0,
			'reaped' => 0,
			'orphaned' => 0,
			'error' => null,
		];
	}

	/**
	 * Mirror the files of one project into $target, upserting by `penpot_id`.
	 *
	 * @param array<string, true> $seen mutated in place: every `penpot_id` Penpot named
	 * @param int $exported mutated in place: incremented per archive downloaded
	 * @param int $failed mutated in place: incremented per export that failed
	 * @param int $skipped mutated in place: incremented for each illegally-named file
	 * @param bool $complete mutated in place: cleared if any file here was not enumerated
	 * @return int the number of files written (created or updated)
	 *
	 * @throws PenpotApiException
	 */
	private function pullProjectFiles(Folder $target, array $strays, Mapping $mapping, string $projectId, array &$seen, int &$exported, int &$failed, int &$skipped, bool &$complete): int {
		// Index this folder's existing `.penpot` files ONCE (penpot_id -> file)
		// instead of re-walking the directory listing for every Penpot file.
		$fileIndex = $this->indexFilesByPenpotId($target);
		$written = 0;
		foreach ($this->client->getProjectFiles($projectId) as $file) {
			$fileId = $this->str($file, 'id');
			$baseName = $this->str($file, 'name');
			if ($fileId !== '') {
				// Recorded BEFORE the legality check: an unmirrorable name is still
				// proof the design exists, and the prune must never read "we refused
				// to write this" as "Penpot no longer has this".
				$seen[$fileId] = true;
			}
			if ($fileId === '' || !$this->isLegalName($baseName)) {
				$this->logger->warning('penpot_sync pull: skipping file with a missing id or illegal name', [
					'app' => Application::APP_ID,
					'project' => $projectId,
					'name' => $baseName,
				]);
				$skipped++;
				if ($fileId === '') {
					// No id means nothing to record in the seen-set, so this file's
					// mirror — if it has one — would look deleted.
					$complete = false;
				}
				continue;
			}
			$this->upsertMirrorFile($target, $fileIndex, $strays, $mapping, $fileId, $baseName, $file, $exported, $failed);
			$written++;
		}
		return $written;
	}

	/**
	 * Find (by `penpot_project_id`) or create the folder for a project under the
	 * team root, and (re)stamp its markers. A rename upstream renames the folder.
	 *
	 * ## ONE MARKER, AND IT IS `penpot_project_id`
	 *
	 * There is no second marker and no system tag. {@see MembershipResolver} has
	 * only ever read the id, and the tag that once shadowed it is gone entirely
	 * (saga §D4.14).
	 *
	 * It was the last remnant of a withdrawn design in which the tag was what MADE
	 * a folder a project. §C6.38 replaced that with promotion by content, and once
	 * the tag stopped being the opt-in there was no reason for the pull to keep
	 * writing it — a second marker that some code has to remember to keep in step
	 * with the first, saying nothing the first does not.
	 *
	 * ## THE FOLDER GETS ITS CREATION TIME AND NOT ITS MTIME
	 *
	 * When the project was created in Penpot is a fact Nextcloud can never work out
	 * for itself, and `creation_time` survives a child write — measured. A folder's
	 * MTIME does not: core propagates it from the folder's children, so stamping it
	 * would mean losing that fight on every pull that writes any design, forever. It
	 * would also be worse information — a propagated mtime honestly says "something in
	 * this project changed", while Penpot's project `modified-at` only moves on a
	 * rename. See {@see MirrorTimes} for the measurements.
	 *
	 * ## `$name` IS A PATH, AND ONLY ITS LAST SEGMENT IS THE PROJECT FOLDER
	 *
	 * `Bubbles/foo` means an ordinary folder `Bubbles` holding the project folder
	 * `foo` — the exact shape {@see PushService} names when someone drags a project
	 * folder into another folder. The parents are provisioned as bare folders and
	 * carry no markers, because they are not projects and nothing may read them as
	 * one; only the leaf is stamped.
	 *
	 * THE ID STILL DECIDES, which is what makes a re-name cheap. An existing folder
	 * is found by `penpot_project_id` wherever it currently sits and MOVED to the
	 * new path ({@see tryMoveProject()}) — never re-created, so its designs, its
	 * user files and its history all travel with it.
	 *
	 * @param array<string, Folder> $folderIndex penpot_project_id -> folder, built once by the caller
	 * @param array<string, mixed> $project the Penpot project record (carries `created-at`)
	 */
	private function ensureProjectFolder(Folder $root, array $folderIndex, string $projectId, string $name, Mapping $mapping, array $project = []): Folder {
		$segments = self::segments($name);
		$leaf = array_pop($segments); // and what is left of $segments is the parents
		if ($leaf === null) {
			// Unreachable behind isLegalProjectName(), which is exactly why it is a
			// throw and not a fallback: a nameless project silently mirrored to some
			// invented folder is worse than one skipped and logged.
			throw new \RuntimeException('A project name resolved to no path segments at all');
		}

		// A PROJECT THAT ARRIVED FROM ANOTHER TEAM ALREADY HAS A FOLDER, and it is
		// standing under the mapping it just left. `$folderIndex` cannot see it —
		// it is built for THIS root — so a miss here used to mean "make a new
		// folder", which re-created the project and stranded everything in the old
		// folder that was not a design.
		//
		// Looked up only on a miss, and built at most once per pull, because a miss
		// is otherwise the ordinary "a genuinely new project" path.
		//
		// A PROJECT WHOSE FOLDER IS IN THE TRASH IS ALSO A MISS, and the last place
		// worth looking before giving up and making a new one — see
		// {@see revivedProjectFolder()}.
		$existing = $folderIndex[$projectId]
			?? $this->foreignProjectFolder($projectId, $mapping)
			?? $this->revivedProjectFolder($projectId, $root);
		if ($existing !== null) {
			$this->tryMoveProject($existing, $root, $segments, $leaf);
			$this->metadata->writeFolder($existing->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
			$this->times->apply($existing, null, MirrorTimes::parse($project['created-at'] ?? null));
			return $existing;
		}

		// No folder yet carries this project id. Adopt a same-named folder if one
		// happens to sit there (a first pull over a hand-made tree), else create.
		//
		// ONLY A **BARE** ONE. A folder already carrying markers belongs to something
		// — another project, or the mapping root itself — and adopting it would
		// re-stamp it with THIS project's id, quietly handing one project's folder,
		// designs and history to another. Two Penpot projects are allowed to share a
		// name (§31), so the answer there is a free name, not a theft. Raised by
		// Copilot on #50.
		$parent = $this->ensureFolderPath($root, $segments);
		$adopt = $parent->nodeExists($leaf) ? $parent->get($leaf) : null;
		$bare = $adopt instanceof Folder && $this->metadata->readFolder($adopt->getId())->isBare();
		$folder = $bare && $adopt instanceof Folder ? $adopt : $parent->newFolder($this->freeName($parent, $leaf));
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
		$this->times->apply($folder, null, MirrorTimes::parse($project['created-at'] ?? null));
		return $folder;
	}

	/**
	 * Every project folder sitting in the sync actor's Nextcloud trash, by project
	 * id — read at most once per request, and only ever on a miss.
	 *
	 * Entries are removed as they are used, so the index stays true after a restore
	 * without being read again.
	 *
	 * @var array<string, TrashedFolder>|null
	 */
	private ?array $trashedProjectFolders = null;

	/**
	 * The project's folder, taken back out of the NEXTCLOUD trash because Penpot is
	 * listing the project again (`projects/restore.feature`, saga §6.37).
	 *
	 * ## THE OTHER HALF OF A FORK THAT HAS BEEN OPEN SINCE THE TRASH BECAME READABLE
	 *
	 * §6.37 named this and left it: {@see TrashReconcileService} REAPS — it destroys
	 * a trashed mirror whose design Penpot has destroyed — and the symmetrical case,
	 * a trashed mirror whose design came BACK, had no scenario asking for it. It has
	 * one now, and this is the half that was missing.
	 *
	 * ## WHY A FOLDER AND NOT THE DESIGNS INSIDE IT
	 *
	 * Trashing `Penpot/Doomed` puts ONE thing in the trash: the folder. The designs
	 * under it are nested in that item, not beside it, so there is no trashed
	 * `Alpha.penpot` for a design-level revive to find — which is exactly why
	 * {@see TrashControl::listTrashed()} refuses to descend, and why the revive is
	 * written at the level the gesture actually happened at. The folder comes back
	 * whole, with everything that went in with it.
	 *
	 * A design that came back but whose SIBLINGS are still deleted needs nothing
	 * special here. Their mirrors return with the folder and {@see prune()} trashes
	 * them again on this same pull, which is the truthful answer: their designs are
	 * still in Penpot's trash.
	 *
	 * ## RESTORING IS NOT DESTROYING, SO THE FAILURE DIRECTION IS THE OTHER WAY UP
	 *
	 * The reap refuses to act on anything it cannot prove. This does the opposite
	 * and it is safe to: the worst outcome of a wrong revive is a folder back in the
	 * user's tree that they had thrown away, next to a project Penpot is telling us
	 * exists. Nothing is lost and they can trash it again. A trash we cannot read
	 * simply falls through to making a new folder, as it always did.
	 */
	private function revivedProjectFolder(string $projectId, Folder $root): ?Folder {
		$index = $this->trashedProjectFolders();
		$trashed = $index[$projectId] ?? null;
		if ($trashed === null) {
			return null;
		}
		// SPENT, whether or not the restore below works: a second project must not be
		// handed the same folder. Written back through the local rather than unset on
		// the property, which Psalm reads as nullable at this point and is right to.
		unset($index[$projectId]);
		$this->trashedProjectFolders = $index;

		try {
			$trashed->restore();
		} catch (\Throwable $e) {
			// A backend that refused, a quota, a folder whose original parent is gone.
			// The caller makes a fresh folder instead, which is what it did before this
			// method existed — untidy, not lossy.
			$this->logger->warning('penpot_sync: could not bring a project folder back out of the trash', [
				'app' => Application::APP_ID,
				'project' => $projectId,
				'name' => $trashed->name,
				'exception' => $e,
			]);

			return null;
		}

		// RE-INDEXED RATHER THAN RESOLVED BY PATH. The restore puts the folder back
		// where it was deleted from, and that path is the trash's business, not ours —
		// it can differ from the one the project's name spells today. Finding it by
		// its id is the same lookup every other branch of the caller does, and the
		// caller then moves it if the name has moved on.
		$found = $this->indexProjectFolders($root)[$projectId] ?? null;
		if ($found === null) {
			// Restored somewhere this mapping cannot see — a Team Folder the actor left,
			// a mapping that has since moved. The caller makes a new folder.
			return null;
		}

		$this->logger->info('penpot_sync: a project came back in Penpot, so its folder came back out of the trash', [
			'app' => Application::APP_ID,
			'project' => $projectId,
			'folder' => $found->getPath(),
		]);

		return $found;
	}

	/**
	 * @return array<string, TrashedFolder> penpot_project_id -> trashed folder
	 */
	private function trashedProjectFolders(): array {
		if ($this->trashedProjectFolders !== null) {
			return $this->trashedProjectFolders;
		}
		$this->trashedProjectFolders = [];

		try {
			$uid = $this->storage->resolveActorUid();
		} catch (\Throwable) {
			// No sync actor, no trash to read. The pull that asked has bigger problems
			// and will report them itself.
			return [];
		}

		foreach ($this->trash->listTrashedFolders($uid) as $folder) {
			$projectId = $this->metadata->readFolder($folder->fileId)->projectId;
			if ($projectId === '') {
				continue;
			}
			// A project id is a uuid, so it needs no team check to be unambiguous — and
			// a trashed folder has no path left to attribute it to a mapping by anyway.
			// Two trashed folders for one project would mean the same project mirrored
			// twice and both copies deleted; either is a correct answer, and the loser
			// stays in the trash for a pull that has somewhere to put it.
			$this->trashedProjectFolders[$projectId] = $folder;
		}

		return $this->trashedProjectFolders;
	}

	/**
	 * Every folder on the way down to a project, created as it goes.
	 *
	 * THEY ARE NOT PROJECTS AND ARE NOT MARKED AS ANY. A parent segment is
	 * scaffolding — the same thing a user's hand-made `Clients/` folder is — and
	 * {@see MembershipResolver} walking up past it to find the real markers is the
	 * behaviour that makes free nesting work at all. Stamping them would make every
	 * intermediate folder claim a project it is not.
	 *
	 * ## A FILE IN THE WAY IS A REFUSAL, NOT A RENAME
	 *
	 * Everywhere else here a name collision takes {@see freeName()} and moves on.
	 * Not here: mirroring the project under `foo (2)/` would put it at a path Penpot
	 * never named, and the NEXT pull would find neither `foo` nor the project's
	 * folder and do it again one suffix higher, forever. Throwing lands in the
	 * caller's skip arm, which leaves the project unmirrored and visible in the log
	 * — a fixable state instead of a growing pile.
	 *
	 * ## A FAILED PATH LEAVES NOTHING BEHIND
	 *
	 * `Clients/foo/bar` can create `Clients` and `foo` and only then discover that
	 * `bar` is a file. Without a rollback the project is skipped — correctly — and
	 * two empty folders nobody asked for stay in the user's tree, with nothing that
	 * ever looks at them again: {@see tidy()} only runs behind a move that
	 * happened, and the orphan reap only considers folders carrying a project id.
	 * So this undoes exactly what it made, deepest first, and only while still
	 * empty. Best-effort: a folder that will not go is left, which is no worse than
	 * not trying.
	 *
	 * @param list<string> $segments
	 *
	 * @throws \RuntimeException when a file occupies one of the names
	 */
	private function ensureFolderPath(Folder $root, array $segments): Folder {
		$parent = $root;
		/** @var list<Folder> $created */
		$created = [];

		try {
			foreach ($segments as $segment) {
				$node = $parent->nodeExists($segment) ? $parent->get($segment) : null;
				if ($node instanceof Folder) {
					$parent = $node;
					continue;
				}
				if ($node !== null) {
					throw new \RuntimeException(sprintf('"%s" is a file, so a project cannot be nested under it', $node->getPath()));
				}
				$parent = $parent->newFolder($segment);
				$created[] = $parent;
			}
		} catch (\Throwable $e) {
			$this->rollBack($created);

			throw $e;
		}

		return $parent;
	}

	/**
	 * Undo the folders {@see ensureFolderPath()} made before it failed.
	 *
	 * DEEPEST FIRST, so each one is empty by the time it is reached, and ONLY while
	 * still empty — a folder that somehow acquired contents between being made and
	 * this running is no longer ours to remove. Nothing here throws: this is the
	 * cleanup on a path that is already failing, and a second failure must not
	 * replace the exception the caller needs to see.
	 *
	 * @param list<Folder> $created
	 */
	private function rollBack(array $created): void {
		foreach (array_reverse($created) as $folder) {
			try {
				if ($folder->getDirectoryListing() !== []) {
					return;
				}
				$folder->delete();
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync pull: could not remove a folder made for a project that could not be provisioned', [
					'app' => Application::APP_ID,
					'folder' => $folder->getPath(),
					'exception' => $e,
				]);

				return;
			}
		}
	}

	/**
	 * Put a project folder where its Penpot name says it belongs.
	 *
	 * SEPARATE FROM {@see tryRename()} BECAUSE THE TARGET IS A PATH. `tryRename()`
	 * compares one name against one name and moves within a fixed parent; a project
	 * can change parent, gain a level or lose one without its own last segment
	 * changing at all, and none of that is expressible as a rename.
	 *
	 * ## THE TWO PATHS OVERLAP MORE OFTEN THAN THEY LOOK LIKE THEY WOULD
	 *
	 * `Bubbles` becoming `Bubbles/foo` asks a folder to be moved INSIDE ITSELF, and
	 * `Bubbles/foo` going back to `Bubbles` asks it to take the name its own parent
	 * is using. No filesystem will do either, and both are one Penpot rename away
	 * from any nested project. So when the old path and the new one are
	 * ancestor-and-descendant, the folder steps aside to a parked name under the
	 * root first — which also lets {@see tidy()} clear the ground it just vacated,
	 * so the second case does not collide with the empty shell of the first.
	 *
	 * A PARK THAT IS NEVER FOLLOWED BY ITS MOVE SELF-HEALS: the folder still carries
	 * its project id, so the next pull finds it by id, sees a path that no longer
	 * overlaps, and moves it home in one step.
	 *
	 * Best-effort like {@see tryRename()}: a move that will not go is logged and the
	 * folder keeps its old place rather than the whole pull failing over it.
	 *
	 * @param list<string> $segments the folders ABOVE the project, without the leaf
	 */
	private function tryMoveProject(Folder $node, Folder $root, array $segments, string $leaf): void {
		$base = rtrim($root->getPath(), '/');
		$wanted = $base . '/' . implode('/', [...$segments, $leaf]);
		$here = $node->getPath();

		if ($here === $wanted) {
			return;
		}

		$slash = strrpos($here, '/');
		$hereParent = $slash === false ? '' : substr($here, 0, $slash);
		$wantedParent = $segments === [] ? $base : $base . '/' . implode('/', $segments);

		try {
			$from = $node->getParent();

			// THE NAME IS TAKEN AND THE FOLDER IS ALREADY ON A SETTLED FORM OF IT — IT STAYS.
			//
			// Two Penpot projects may share a name (§31), and a user's own file may sit
			// on the one this project wants. Taking a free name here would rename
			// `Cogs (2)` to `Cogs (3)` on this pull and `Cogs (4)` on the next, forever:
			// the wanted name never frees up, and the suffix is computed against a
			// listing that includes this very node. Staying put is stable, and the ID —
			// never the name — is what anything reading this folder goes by.
			//
			// `isVariantOf()` IS WHAT KEEPS IT FROM STRANDING A PARKED FOLDER. Without
			// it this arm fires on ANY name in the right parent, `.penpot-moving-21`
			// included — so a park whose second move failed, and whose unpark failed
			// too, would be answered with "you are already where you belong" on every
			// pull afterwards and keep the temporary name for good. That is the exact
			// opposite of the self-healing this class promises. Both halves raised by
			// Copilot on #50.
			if ($wantedParent === $hereParent && $from->nodeExists($leaf) && self::isVariantOf($node->getName(), $leaf)) {
				return;
			}

			if (str_starts_with($wanted . '/', $here . '/') || str_starts_with($here . '/', $wanted . '/')) {
				$node->move($base . '/' . $this->freeName($root, '.penpot-moving-' . $node->getId()));
				$this->tidy($from, $root);
				$from = $root;
			}

			$parent = $this->ensureFolderPath($root, $segments);
			$node->move($parent->getPath() . '/' . ($parent->nodeExists($leaf) ? $this->freeName($parent, $leaf) : $leaf));
			$this->tidy($from, $root);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not move a project folder to follow its Penpot name', [
				'app' => Application::APP_ID,
				'from' => $here,
				'to' => $wanted,
				'exception' => $e,
			]);
			$this->unpark($node, $here);

			return;
		}

		$this->logger->info('penpot_sync pull: moved a project folder to follow its Penpot name', [
			'app' => Application::APP_ID,
			'from' => $here,
			'to' => $node->getPath(),
		]);
	}

	/**
	 * Put a parked folder back when the move it stepped aside for did not happen.
	 *
	 * A PARK IS ONLY EVER HALF A MOVE, and the half that fails is the second one —
	 * so without this a folder could be left sitting at `.penpot-moving-…` because
	 * a quota or a permission stopped its real destination from being made. Moving
	 * it back is the cheap, obvious repair.
	 *
	 * WHEN THE REPAIR ITSELF FAILS THE PARK IS STILL SURVIVABLE, which is why this
	 * swallows too: the folder kept its `penpot_project_id`, so the next pull finds
	 * it by id at its parked path, sees a path that no longer overlaps its
	 * destination, and moves it home in one step with no park at all.
	 */
	private function unpark(Folder $node, string $here): void {
		if ($node->getPath() === $here) {
			return;
		}

		try {
			$node->move($here);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: a project folder is parked mid-move; the next pull will place it', [
				'app' => Application::APP_ID,
				'parked' => $node->getPath(),
				'was' => $here,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Clear the empty scaffolding a project moved out of, walking up to the root.
	 *
	 * ONLY EMPTY, ONLY BARE, NEVER THE ROOT. A folder still holding anything is
	 * somebody's — a user's spreadsheet is reason enough for it to exist — and one
	 * carrying a project or team id is a mirror in its own right, which
	 * {@see reapOrphanProjects()} decides the fate of and this must not pre-empt.
	 * What is left is exactly the scaffolding {@see ensureFolderPath()} puts up,
	 * which would otherwise leave one dead empty folder behind per nested rename.
	 *
	 * It goes to the TRASH, like every folder this app removes; a folder that will
	 * not go is logged and left standing.
	 *
	 * ## NOTHING HERE THROWS, AND THE WHOLE BODY IS INSIDE THE TRY FOR THAT REASON
	 *
	 * The marker read used to sit outside it, which made the claim false in the one
	 * way that mattered: this runs from {@see tryMoveProject()}'s try block AFTER a
	 * move has already succeeded, so a Files-Metadata failure here would land in
	 * that catch and {@see unpark()} would UNDO a good move — a storage hiccup while
	 * sweeping up turning into a folder yanked back to where it no longer belongs.
	 *
	 */
	private function tidy(Folder $folder, Folder $root): void {
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			$path = '';
			try {
				if ($folder->getId() === $root->getId()) {
					return;
				}
				$path = $folder->getPath();
				if (!$this->metadata->readFolder($folder->getId())->isBare()) {
					return;
				}
				if ($folder->getDirectoryListing() !== []) {
					return;
				}
				$parent = $folder->getParent();
				$folder->delete();
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync pull: could not clear an empty folder a project moved out of', [
					'app' => Application::APP_ID,
					'folder' => $path,
					'exception' => $e,
				]);

				return;
			}

			$this->logger->info('penpot_sync pull: cleared the empty folder a project moved out of', [
				'app' => Application::APP_ID,
				'folder' => $path,
			]);
			$folder = $parent;
		}
	}

	/**
	 * Find (by `penpot_id`) or create the mirror file for a Penpot file, refresh
	 * its body according to its mode, and (re)stamp id / revision / mode.
	 *
	 * ## MODE IS PER-FILE, DEFAULTING PER-MAPPING (saga §6.22)
	 *
	 * An existing file keeps the mode stamped on it — that stamp is the user's
	 * promotion or demotion, and a mapping's default must never retroactively
	 * rewrite it (that would silently trigger a bulk download, or silently delete
	 * a pile of archives). Only a file we are creating right now takes the
	 * mapping's default.
	 *
	 * ## A NEW `sync` FILE IS BORN AS A LINK, THEN UPGRADED
	 *
	 * {@see ArchiveService::storeLink()} runs first, then the archive replaces
	 * what it left. Since C6.6 a `link` is zero bytes and a freshly created node
	 * already is, so for a NEW file this call is a no-op — the ordering survives
	 * for what it does to an EXISTING one: it truncates whatever the file held
	 * before, which is how a legacy JSON pointer body from an earlier version
	 * migrates itself away on the next pull, with no repair step.
	 *
	 * A first export that fails now leaves an empty file stamped `sync`, and that
	 * is a *better* failure than the old one: the stamp and the bytes disagree
	 * loudly, {@see driftedOrMissing()} sees no archive, and the next pull
	 * retries. The old behaviour left a pointer body stamped `sync` — a file
	 * whose own contents contradicted its mode.
	 *
	 * @param array<string, File> $fileIndex penpot_id -> file, built once by the caller
	 * @param array<string, mixed> $file the decoded Penpot file record (carries `revn` + `modified-at`)
	 * @param int $exported mutated in place
	 * @param int $failed mutated in place
	 */
	private function upsertMirrorFile(Folder $target, array $fileIndex, array $strays, Mapping $mapping, string $fileId, string $baseName, array $file, int &$exported, int &$failed): void {
		$name = $baseName . self::EXTENSION;
		$revn = (string)($file['revn'] ?? '');
		$modifiedAt = $this->str($file, 'modified-at');
		// The drift signal is `revn` + `modified-at` together (saga §5.5): revn
		// alone cannot tell "same revn, newer modified-at" apart, which the
		// scheduled-pull diff needs. Stored as one opaque string — callers
		// compare it whole, never parse it.
		$signal = ArchiveService::signal($revn, $modifiedAt);

		$existing = $fileIndex[$fileId] ?? null;
		if ($existing === null && isset($strays[$fileId])) {
			// THE DESIGN CHANGED PROJECT IN PENPOT, so its mirror follows.
			//
			// `$fileIndex` already recurses through plain subfolders and STOPS at a
			// nearer project ancestor, so a mirror the user filed into a subfolder of
			// this very project was found above and is left exactly where they put it
			// (§6.29 — the pull ensures membership, never a path). A miss here can
			// therefore only mean the mirror is sitting under a DIFFERENT project,
			// which is the one case where its position is now a lie.
			//
			// Without this the pull wrote a SECOND file into the new project folder
			// and left the old one behind — and the old one is in the seen-set, so
			// the prune never touched it. Two files, one design, forever.
			$existing = $this->relocate($strays[$fileId], $target, $name);
		}
		if ($existing !== null) {
			$this->tryRename($existing, $target, $name);
			$node = $existing;
			$stamped = $this->metadata->readFile($node->getId());
			// AN EXISTING FILE KEEPS ITS OWN MODE. The mapping's default only ever
			// reaches a file the moment it is created — changing a default must
			// never retroactively download (or delete) a pile of archives.
			$mode = $stamped !== null && $stamped->mode !== '' ? $stamped->mode : $mapping->mode;
			$stored = $stamped?->revision ?? '';
		} else {
			$node = $target->newFile($this->freeName($target, $name));
			$mode = $mapping->mode;
			$stored = '';
		}

		// Tracks whether this pass put bytes on disk, which is the same question as
		// "is the node's mtime `now`?" — and therefore whether comparing it against
		// Penpot's clock would be comparing against a value we just invalidated
		// ourselves. Creating the node counts: a fresh file is a write.
		$wroteBytes = $existing === null;

		$wantsArchive = $mode === Mapping::MODE_SYNC;
		if (!$wantsArchive || $existing === null) {
			$wroteBytes = $this->archives->storeLink($node) || $wroteBytes;
		}

		// `true` when the mirror is current and the revision stamp may advance.
		$current = true;
		if ($wantsArchive && $this->driftedOrMissing($node, $stored, $signal)) {
			try {
				$this->archives->storeArchive($node, $fileId);
				$exported++;
				$wroteBytes = true;
			} catch (PenpotApiException $e) {
				// ONE FILE'S EXPORT FAILING IS NOT A FAILED PULL. Everything else
				// about this file — its name, its placement, its ids — reconciled
				// fine and is worth keeping; only the bytes are stale. Leaving the
				// revision stamp alone is what makes the next pull retry.
				$this->logger->warning('penpot_sync pull: could not export a sync file, keeping the previous content', [
					'app' => Application::APP_ID,
					'file' => $name,
					'penpot_id' => $fileId,
					'exception' => $e,
				]);
				$failed++;
				$current = false;
			}
		}

		$values = [
			PenpotMetadata::KEY_ID => $fileId,
			PenpotMetadata::KEY_MODE => $mode,
			// The design's TEAM, stamped on the file itself (§C6.7). Penpot's
			// workspace route will not open a file without it, and the browser
			// cannot reach an ancestor Team Folder's marker without walking a
			// freely-nested tree on every render. Re-stamped every pull, so a
			// design that changes team upstream corrects itself.
			PenpotMetadata::KEY_TEAM_ID => $mapping->teamId,
		];
		if ($current) {
			$values[PenpotMetadata::KEY_REVISION] = $signal;
		}
		$this->metadata->writeFile($node->getId(), $values);

		// The design's own clocks, last: the body, the metadata and the tags are all
		// committed by now, so a clock that will not set costs nothing else. `$exported`
		// having moved means storeArchive() just wrote, so the node's mtime is `now` and
		// there is nothing meaningful to compare it against.
		$this->times->apply(
			$node,
			MirrorTimes::parse($file['modified-at'] ?? null),
			MirrorTimes::parse($file['created-at'] ?? null),
			$wroteBytes,
		);
	}

	/**
	 * Does this `sync` file need a fresh export?
	 *
	 * TWO REASONS, AND THE SECOND IS THE IMPORTANT ONE. Drift is obvious: the
	 * design changed upstream. Missing bytes are subtler — a file stamped `sync`
	 * that holds no archive is a promotion whose export never landed (or a pull
	 * that was interrupted), and checking for it is what makes that state heal
	 * itself on the next pass instead of persisting until someone notices the
	 * "backup" is empty.
	 *
	 * The cheap test runs first: an unchanged signal is a string compare, and
	 * only then do we touch the filesystem.
	 */
	private function driftedOrMissing(File $node, string $stored, string $signal): bool {
		return $stored !== $signal || !$this->archives->holdsArchive($node);
	}

	/**
	 * Move every mirror under $root whose design Penpot no longer has into the
	 * **Nextcloud trash** — the most dangerous thing this app does, and the
	 * reason the caller may only reach it on a complete listing.
	 *
	 * ## WHAT IS EVEN A CANDIDATE
	 *
	 * A file carrying a `penpot_id`. Not "a `.penpot` file", not "a file in a
	 * project folder" — the stamp is the only thing that says *we made this*, and
	 * under free nesting position proves nothing. Anything unstamped is a file the
	 * user put there and is never touched, which is what keeps a mapped folder
	 * usable as an ordinary folder.
	 *
	 * ## TRASH, NEVER DESTROY
	 *
	 * `delete()` on a user-visible node is a move to the trash, recoverable for as
	 * long as the instance's retention allows. Nothing in the pull hard-deletes;
	 * the only irreversible call in the whole app is an explicit, confirmed one.
	 *
	 * @param array<string, true> $seen every `penpot_id` this pull was told exists
	 * @param int $pruned mutated in place
	 * @param int $rescued mutated in place
	 * @param int $lost mutated in place
	 */
	private function prune(Folder $root, Mapping $mapping, array $seen, int &$pruned, int &$rescued, int &$lost): void {
		// ONE TRASH LISTING FOR THE WHOLE PRUNE. It is per-TEAM and identical for
		// every mirror in this loop, so asking per file was an N+1 against Penpot —
		// and the N is worst exactly when it hurts most, because a Penpot-side
		// reorganisation is what makes many mirrors vanish at once. Read lazily, so
		// a pull that prunes nothing still costs nothing. Raised in review on #44.
		//
		// @var list<string>|null|false $trashed
		$trashed = false;
		foreach ($this->collectMirrors($root) as $penpotId => $node) {
			if (isset($seen[$penpotId])) {
				continue;
			}

			// A LINK LEAVES WITHOUT A TRACE, and never becomes anything else.
			//
			// It holds no bytes, so there is nothing to snapshot and nothing a
			// restore could reconnect to. This branch used to fall through to the
			// rescue below, which — because a link is exactly the file that holds no
			// archive — meant every departing link was exported and RE-STAMPED
			// `sync`. That was the last surviving link→sync promotion in the app,
			// and mode is a property of the mapping, never of one file. A link is
			// a link for as long as it exists.
			if ($this->isLink($node)) {
				$pruned += $this->discard($node, $penpotId, 'a link whose design left the mapping') ? 1 : 0;
				continue;
			}

			// A DESIGN THAT MOVED IS NOT A DESIGN THAT DIED, and only Penpot can say
			// which happened.
			//
			// `=== true`, NOT `!== false`, and the difference is the whole safety
			// property. `fileExists()` has three answers and the third is "I could
			// not tell" — unreachable, unauthorised, a schema read wrong. Written as
			// `!== false`, an unknown counted as a YES, and an unknown paired with an
			// unreadable trash listing took the PERMANENT discard below. That is
			// precisely the data loss the three-valued return exists to prevent, and
			// it survived a unit test (`exists: null, trashed: true`) that only ever
			// exercised the other branch. Caught in review on #44.
			//
			// Only a design Penpot positively confirms is alive may be discarded.
			// Everything else keeps the recoverable path.
			// `false` is "not read yet"; `null` is "read and could not be trusted".
			// Two absent-ish states, and conflating them is how an unreadable listing
			// would have started discarding files.
			if ($trashed === false) {
				$trashed = $this->penpotTrashIds($mapping->teamId);
			}
			if (
				$this->client->fileExists($penpotId) === true
				&& $trashed !== null
				&& !in_array($penpotId, $trashed, true)
			) {
				$pruned += $this->discard($node, $penpotId, 'a design moved out of this mapping in Penpot') ? 1 : 0;
				continue;
			}

			// WHAT IS LEFT IS A DELETE — trashed in Penpot, or purged there. Either
			// way this file may be the last copy of that design in existence, so it
			// goes somewhere recoverable and gets one last chance at real bytes.
			$rescue = null;
			if (!$this->archives->holdsArchive($node)) {
				$rescue = $this->snapshot($node, $penpotId);
			}

			try {
				$node->delete();
			} catch (\Throwable $e) {
				// A mirror we could not trash is not a failed pull. It stays, Penpot
				// stops naming it, and the next pull tries again — the same shape as
				// every other per-file failure here.
				$this->logger->warning('penpot_sync pull: could not move a vanished mirror to the trash', [
					'app' => Application::APP_ID,
					'file' => $node->getName(),
					'penpot_id' => $penpotId,
					'exception' => $e,
				]);
				continue;
			}

			// COUNTED ONLY ONCE THE MIRROR IS ACTUALLY IN THE TRASH, because the CLI
			// reports `rescued` and `lost` as a breakdown OF `pruned` — three numbers
			// that must add up. A snapshot taken for a file that then failed to move
			// is not lost work (it is still on disk, and the next pull's delete is now
			// free) but it did not prune anything, so counting it here would print a
			// sum that does not reconcile.
			$pruned++;
			if ($rescue === true) {
				$rescued++;
			} elseif ($rescue === false) {
				$lost++;
			}

			// NAME WHAT WAS TRASHED. The CLI prints counts, which is right for a
			// summary and useless for the only question anyone ever asks afterwards:
			// *which* file, and why. This is the app's most dangerous operation —
			// driven entirely by "Penpot did not name this id" — and until this line
			// existed a prune left no record of its subjects at all. A CI run that
			// pruned one mirror unexpectedly could not be diagnosed from its log.
			$this->logger->info('penpot_sync pull: trashed a mirror whose design Penpot no longer lists', [
				'app' => Application::APP_ID,
				// The PATH, not just the name: which project folder a mirror was in is
				// half of any "why did this go" question, and two designs in different
				// projects can share a name.
				'file' => $node->getPath(),
				'penpot_id' => $penpotId,
				'final_archive' => $rescue,
				// How many ids Penpot named this run. A prune against a plausible
				// count is a real deletion; a prune against a suspiciously small one
				// is a listing that came back short, which is the failure mode the
				// $complete flag exists to catch and cannot always see.
				'ids_listed' => count($seen),
			]);
		}
	}

	/** Is this mirror a pointer rather than a copy? */
	private function isLink(File $node): bool {
		return $this->metadata->readFile($node->getId())?->isLink() ?? false;
	}

	/**
	 * Every design id in this team's Penpot trash, read once per prune.
	 *
	 * ASKED ONLY WHEN {@see PenpotClient::fileExists()} SAID YES, and that pairing is
	 * the whole subtlety: a design in Penpot's trash still EXISTS — `get-file-summary`
	 * answers for it happily — so existence alone would read a delete as a move and
	 * destroy the mirror. The trash listing is what separates them.
	 *
	 * The team comes from the MAPPING rather than any file's stamp: the mapping is
	 * what this root mirrors, it cannot be stale, and it needs no resolver walk.
	 *
	 * ## AN UNREADABLE LISTING KEEPS EVERY MIRROR
	 *
	 * A failure answers `null`, and the caller reads that as "do not discard
	 * anything" — the same rule as {@see PenpotClient::fileExists()}'s null. An
	 * EMPTY list would have been the disastrous spelling: it means "nothing is in
	 * the trash", which makes every vanished design look moved and every mirror a
	 * candidate for permanent removal.
	 *
	 * @return list<string>|null null when the answer cannot be trusted
	 */
	private function penpotTrashIds(string $teamId): ?array {
		// THE RAW LISTING ON PURPOSE, not {@see PenpotClient::recoverableFileIds()}.
		// That reading tells a destroyed design from a recoverable one, and the reap
		// needs it; the prune does not. Here a wider set means MORE mirrors kept, and
		// a mirror kept is the direction this method already fails in. Narrowing it
		// would make the prune start deleting mirrors on a rule `designs/purge` owns,
		// which is a different feature's decision to change.
		if ($teamId === '') {
			return null;
		}

		try {
			$ids = [];
			foreach ($this->client->deletedFiles($teamId) as $file) {
				if (isset($file['id']) && is_string($file['id'])) {
					$ids[] = $file['id'];
				}
			}

			return $ids;
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not read Penpot\'s trash; keeping every mirror it would have judged', [
				'app' => Application::APP_ID,
				'team_id' => $teamId,
				'exception' => $e,
			]);

			return null;
		}
	}

	/**
	 * Remove a mirror without leaving a Nextcloud trash entry.
	 *
	 * Reached only when the design is demonstrably fine on Penpot's side, so there is
	 * nothing to recover and a trashed file would misreport what happened. A delete
	 * that fails is logged and skipped, exactly like the trashing path — the mirror
	 * stays, and the next pull tries again.
	 *
	 * @return bool true when the mirror is actually gone, so the caller only counts
	 *              a prune that happened (the CLI's totals have to reconcile)
	 */
	private function discard(File $node, string $penpotId, string $because): bool {
		$path = $node->getPath();

		try {
			$this->trash->withoutTrash(static function () use ($node): void {
				$node->delete();
			});
		} catch (\Throwable $e) {
			// NOT "a mirror Penpot no longer lists" — every caller of this method has
			// established the opposite. The design is alive and well; it is this
			// mapping that stopped being the place it lives.
			$this->logger->warning('penpot_sync pull: could not remove a mirror whose design left this mapping', [
				'app' => Application::APP_ID,
				'file' => $path,
				'penpot_id' => $penpotId,
				'exception' => $e,
			]);

			return false;
		}

		$this->logger->info('penpot_sync pull: removed a mirror with no trash entry — ' . $because, [
			'app' => Application::APP_ID,
			'file' => $path,
			'penpot_id' => $penpotId,
		]);

		return true;
	}

	/**
	 * A PROJECT DELETED IN PENPOT LEAVES NO FOLDER CLAIMING ITS ID (`projects/delete.feature`).
	 *
	 * ## THE HALF OF THE PRUNE THAT DID NOT EXIST
	 *
	 * {@see prune()} works from {@see collectMirrors()}, which gathers FILES. So
	 * deleting a project in Penpot did the right thing to its designs — they went
	 * to the Nextcloud trash, each with a last-chance snapshot — and nothing at all
	 * to the FOLDER. It stayed, still carrying a `penpot_project_id` that named a
	 * project no longer in existence, and no pull ever looked at it again.
	 *
	 * That is not merely untidy. A dead marker is indistinguishable from a live one
	 * to everything that reads it: {@see MembershipResolver} resolves designs into a
	 * project that is gone, and {@see MoveRules::refusalForDeleting()} refuses to
	 * let the folder be deleted under a `link` mapping — permanently, because the
	 * reason it gives ("it would come back on the next sync") is not true and never
	 * becomes true. Measured on a live instance: a folder in exactly that state
	 * could not be removed by any route.
	 *
	 * ## TWO ENDINGS, DECIDED BY WHAT IS LEFT IN THE FOLDER
	 *
	 * The prune has already run, so the designs are in the trash and what remains
	 * is whatever was never Penpot's:
	 *
	 *   - **nothing remains** — the folder was only ever the project's mirror, and
	 *     with the project gone there is nothing left for it to be. It goes to the
	 *     Nextcloud trash, recoverable like any folder somebody deletes.
	 *   - **something remains** — a spreadsheet, a subfolder, anything. The folder
	 *     STAYS and merely stops being a project: the id is cleared and the `penpot`
	 *     tag comes off, so it reads as the ordinary folder it now is. Deleting a
	 *     user's files because a Penpot project went away is not this app's call.
	 *
	 * ## ONLY EVER CALLED BEHIND `$complete`
	 *
	 * Same gate as the prune, and for a sharper reason. A project skipped for an
	 * illegal name is absent from `$named` while being perfectly alive in Penpot —
	 * so without the gate, one project with a slash in its name would send its
	 * whole folder to the trash on the next pull.
	 *
	 * @param array<string, true> $named every project id Penpot listed this run
	 *
	 * @return int how many folders stopped claiming a project
	 */
	private function reapOrphanProjects(Folder $root, array $named, Mapping $mapping): int {
		$orphaned = 0;

		// COLLECTED BEFORE ANYTHING CHANGES. The walk reads folder metadata and the
		// endings below delete folders, so listing and mutating in one pass would
		// have the descent stepping through a tree it is editing.
		foreach ($this->orphanProjectFolders($root, $named, 0) as $folder) {
			$projectId = $this->metadata->readFolder($folder->getId())->projectId;
			if ($projectId !== '' && $this->movedToAnotherMappedTeam($projectId, $mapping)) {
				// NOT DELETED — MIGRATED. The receiving mapping relocates this folder
				// on its own pull; stripping its id here would make that impossible.
				$this->logger->info('penpot_sync pull: a project moved to another mapped team; its folder is left for that mapping to relocate', [
					'app' => Application::APP_ID,
					'projectId' => $projectId,
					'folder' => $folder->getPath(),
				]);

				continue;
			}
			$orphaned += $this->stopBeingAProject($folder) ? 1 : 0;
		}

		return $orphaned;
	}

	/**
	 * Every folder at or below $root carrying a project id Penpot did not name.
	 *
	 * DESCENDS PAST A PROJECT FOLDER, unlike {@see indexProjectFolders()} which
	 * reads the root's direct children only.
	 *
	 * A nested project arrives from either side now. From NEXTCLOUD: a project
	 * folder made inside another folder is a project whose Penpot name is its path
	 * (§C6.38), which `projects/delete.feature` spells out as `/Penpot/foo/bar`.
	 * And from PENPOT: {@see ensureProjectFolder()} provisions exactly that path
	 * when a project is named `foo/bar` upstream. Deleted in Penpot, one of those
	 * is as orphaned as any other, and a scan of the root's children would never
	 * see it.
	 *
	 * (An earlier draft of this docblock said the pull nests them, was corrected on
	 * #47 to say it cannot, and is now back to the first answer — because the code
	 * changed underneath it, not because the reading did. The skip that made the
	 * correction true is gone.)
	 *
	 * @param array<string, true> $named
	 *
	 * @return list<Folder>
	 */
	private function orphanProjectFolders(Folder $root, array $named, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		try {
			$children = $root->getDirectoryListing();
		} catch (\Throwable $e) {
			// An unreadable folder is not a folder to act on. The next pull tries again.
			$this->logger->warning('penpot_sync pull: could not list a folder while looking for orphaned projects', [
				'app' => Application::APP_ID,
				'folder' => $root->getPath(),
				'exception' => $e,
			]);

			return [];
		}

		$found = [];
		foreach ($children as $child) {
			if (!$child instanceof Folder) {
				continue;
			}
			$projectId = $this->metadata->readFolder($child->getId())->projectId;
			if ($projectId !== '' && !isset($named[$projectId])) {
				$found[] = $child;
			}
			foreach ($this->orphanProjectFolders($child, $named, $depth + 1) as $nested) {
				$found[] = $nested;
			}
		}

		return $found;
	}

	/**
	 * One orphaned folder reaches one of the two endings above.
	 *
	 * NEVER THROWS. A folder that will not move is not a failed pull — it keeps its
	 * dead id for one more tick and the next pull tries again, which is the same
	 * shape every other per-node failure in this class has.
	 *
	 * @return bool true when the folder stopped claiming a project
	 */
	private function stopBeingAProject(Folder $folder): bool {
		$path = $folder->getPath();

		try {
			$empty = $folder->getDirectoryListing() === [];
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not read an orphaned project folder', [
				'app' => Application::APP_ID,
				'folder' => $path,
				'exception' => $e,
			]);

			return false;
		}

		try {
			if ($empty) {
				// TO THE TRASH, NOT PAST IT. Unlike a link — which holds nothing, so a
				// restore of it restores nothing — a folder is a place someone made, and
				// its recoverability costs nothing to keep.
				$folder->delete();
				$this->logger->info('penpot_sync pull: a project was deleted in Penpot; its empty folder went to the trash', [
					'app' => Application::APP_ID,
					'folder' => $path,
				]);

				return true;
			}

			$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => '']);
			$this->logger->info('penpot_sync pull: a project was deleted in Penpot; its folder kept the other files and stopped being a project', [
				'app' => Application::APP_ID,
				'folder' => $path,
			]);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not retire an orphaned project folder', [
				'app' => Application::APP_ID,
				'folder' => $path,
				'exception' => $e,
			]);

			return false;
		}
	}

	/**
	 * One last `export-binfile` for a design that is already gone.
	 *
	 * BEST-EFFORT BY DESIGN (saga §6.42): Penpot keeps a deleted file exportable
	 * only while its own trash holds it, so past that window this simply fails and
	 * the caller trashes the empty mirror anyway. It never pretends — a snapshot is
	 * counted only when real bytes landed.
	 *
	 * @return bool true when an archive was stored
	 */
	private function snapshot(File $node, string $penpotId): bool {
		try {
			$this->archives->storeArchive($node, $penpotId);

			return true;
		} catch (PenpotApiException $e) {
			$this->logger->warning('penpot_sync pull: no final archive could be recovered for a vanished design', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'penpot_id' => $penpotId,
				'exception' => $e,
			]);

			return false;
		}
	}

	/**
	 * Every stamped mirror file anywhere under $root, keyed by `penpot_id`.
	 *
	 * RECURSIVE, because free nesting means a mirror may sit in any plain
	 * subfolder the user made (saga §6.29). A prune that only looked one level
	 * down would leave the moved ones behind forever — and, worse, would be
	 * *correct* often enough to look like it worked.
	 *
	 * {@see indexFilesByPenpotId()} is recursive too, as of §C6.20. It was not,
	 * and this docblock used to say so — the two halves of one question
	 * disagreeing about how hard to look is what produced silent duplicates.
	 *
	 * ## THE `+` IS FIRST-WINS, AND THAT IS THE SAFE DIRECTION HERE
	 *
	 * Two mirrors can carry the same `penpot_id`: nothing wipes a copy's metadata
	 * yet (the copy listener is Course 6's), and a design restored in Penpot's own
	 * UI is re-created beside its still-trashed mirror (§6.37). Array union keeps
	 * the first one found and drops the rest, so ONE duplicate is pruned per
	 * pass rather than all of them at once. That is deliberately the opposite of
	 * {@see indexFilesByPenpotId()}, which is last-wins because the upsert wants
	 * the newest node to receive the write.
	 *
	 * Prefer under-deleting: a duplicate that survives a pass is a visible extra
	 * file the next pull will take, while over-deleting is the one mistake this
	 * whole method is written to avoid.
	 *
	 * ## THE CEILING IS A LIMIT HERE, NOT A VERDICT
	 *
	 * `[]` from this walk does not permit anything — it prunes less. That is the
	 * safe direction this method already prefers, so the ceiling can simply end the
	 * walk, unlike {@see ExistingDesigns} where an empty answer clears a purge.
	 * What matters is that {@see indexFilesByPenpotId()} stops at the SAME rung:
	 * the two halves of one question disagreeing about how hard to look is §C6.20,
	 * and it is the reason both carry the guard rather than neither.
	 *
	 * @return array<string, File> penpot_id -> file
	 */
	private function collectMirrors(Folder $folder, int $depth = 0): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$found = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$found += $this->collectMirrors($node, $depth + 1);
				continue;
			}
			if (!$node instanceof File) {
				continue;
			}
			$penpotId = $this->metadata->readFile($node->getId())?->penpotId ?? '';
			if ($penpotId !== '') {
				$found[$penpotId] = $node;
			}
		}

		return $found;
	}

	/**
	 * The projects that belong to $teamId. `get-all-projects` (never
	 * `get-projects`, saga §6.42) already filters soft-deleted projects; we
	 * filter by team here.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	private function teamProjects(string $teamId): array {
		$out = [];
		foreach ($this->client->getAllProjects() as $project) {
			if ($this->str($project, 'team-id') === $teamId) {
				$out[] = $project;
			}
		}
		return $out;
	}

	/** A project's `.penpot` files live at the team root when it is the default (Drafts). */
	private function isDefaultProject(array $project): bool {
		return filter_var($project['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * Index a team root's project folders by their `penpot_project_id` in a
	 * single tree walk, so a pull is O(nodes) not O(projects × nodes).
	 *
	 * ## IT DESCENDS, AND IT HAS TO
	 *
	 * A project's name is a path, so a project folder can sit at any depth — and
	 * this index is the ONLY thing that says "we already have a folder for this
	 * project". Reading the root's direct children only, as this did, meant a
	 * nested project was invisible here and a SECOND folder was created for it at
	 * the root on every pull, each one stamped with the same project id.
	 *
	 * It does NOT stop at a project folder, unlike {@see indexFilesByPenpotId()}.
	 * That one stops because a nearer project ancestor OWNS the files below it;
	 * this one is asking a different question — where is the folder for each id —
	 * and `Clients/Traveller` under a `Clients` that is itself a project is a
	 * perfectly legal answer.
	 *
	 * @return array<string, Folder> penpot_project_id -> folder (last wins on the impossible dup)
	 */
	/**
	 * A folder carrying $projectId that lives under some OTHER mapping's root.
	 *
	 * ## THE OTHER HALF OF A CROSS-TEAM MOVE
	 *
	 * When a project is sent to another team in Penpot, its folder does not move
	 * itself: it is still standing under the mapping it left. The receiving
	 * mapping names the project on its next pull, misses it in its own index, and
	 * without this would CREATE a folder — re-mirroring the designs into it and
	 * leaving every ordinary file behind in the abandoned one. That is what
	 * `Move a project to another team in Penpot` asserts with `Budget.xlsx`.
	 *
	 * The relocation itself needs nothing new: {@see ensureProjectFolder()} hands
	 * whatever it finds to {@see tryMoveProject()}, which moves the folder whole
	 * into this root — the same call that re-paths a project renamed in place.
	 *
	 * ## BUILT AT MOST ONCE, AND ONLY WHEN SOMETHING MISSES
	 *
	 * A miss is normally just "a genuinely new project", so paying a walk of every
	 * other mapped tree per project would be a real cost for a rare answer. The
	 * index is therefore built on the first miss of a pull and reused after that;
	 * {@see pullOne()} clears it so one pull never answers with another's tree.
	 */
	private function foreignProjectFolder(string $projectId, Mapping $current): ?Folder {
		if ($this->foreignIndex === null) {
			$this->foreignIndex = [];
			foreach ($this->mappings->list() as $mapping) {
				if ($mapping->id === $current->id) {
					continue;
				}
				try {
					$root = $this->storage->ensureRoot($mapping);
				} catch (\Throwable $e) {
					// A mapping whose storage is not available cannot be searched, and
					// that must not fail the pull that is merely asking a question about
					// it. Logged rather than swallowed: a folder that should have been
					// relocated will be re-created instead, and this is the only trace.
					$this->logger->warning('penpot_sync pull: could not search a mapping for a relocated project folder', [
						'app' => Application::APP_ID,
						'mapping' => $mapping->id,
						'exception' => $e,
					]);
					continue;
				}
				$this->foreignIndex = array_replace($this->foreignIndex, $this->indexProjectFolders($root));
			}
		}

		return $this->foreignIndex[$projectId] ?? null;
	}

	/**
	 * True when $projectId still exists in a mapped team that is NOT $current.
	 *
	 * ## WHY THE REAP HAS TO ASK THIS
	 *
	 * `reapOrphanProjects()` means "Penpot no longer has this project", and it
	 * infers that from the project not being named by THIS mapping's team. A
	 * project that moved to another mapped team is not named here either, and is
	 * very much still alive.
	 *
	 * Without this, the outcome depended on the order the mappings happen to pull
	 * in: donor first and the folder is stripped of its id before the receiving
	 * mapping can relocate it — permanently, since nothing can find it again.
	 * That is not a race worth leaving in a reconciler.
	 *
	 * ASKED ONLY ABOUT FOLDERS ABOUT TO BE REAPED, which is normally none, so the
	 * team listings this costs are paid exactly when the answer matters.
	 */
	private function movedToAnotherMappedTeam(string $projectId, Mapping $current): bool {
		foreach ($this->mappings->list() as $mapping) {
			if ($mapping->id === $current->id) {
				continue;
			}
			try {
				foreach ($this->teamProjects($mapping->teamId) as $project) {
					if ($this->str($project, 'id') === $projectId) {
						return true;
					}
				}
			} catch (\Throwable $e) {
				// UNREACHABLE PENPOT MEANS "DO NOT REAP", not "reap anyway". The whole
				// point of this question is to avoid destroying an identity on a
				// guess, so a failure to answer it defers the reap to a later pull.
				$this->logger->warning('penpot_sync pull: could not check another team before reaping a project folder; leaving it alone', [
					'app' => Application::APP_ID,
					'mapping' => $mapping->id,
					'exception' => $e,
				]);

				return true;
			}
		}

		return false;
	}

	private function indexProjectFolders(Folder $root, int $depth = 0): array {
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$index = [];
		foreach ($root->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$projectId = $this->metadata->readFolder($node->getId())->projectId;
			if ($projectId !== '') {
				$index[$projectId] = $node;
			}
			$index = array_replace($index, $this->indexProjectFolders($node, $depth + 1));
		}
		return $index;
	}

	/**
	 * Index a project folder's mirrors by `penpot_id` in a single walk, so
	 * upserting N files is O(nodes) not O(N × nodes).
	 *
	 * ## IT MUST DESCEND, AND FOR A WHILE IT DID NOT (saga §C6.20)
	 *
	 * This used to read only the folder's DIRECT children, while
	 * {@see collectMirrors()} — the prune's half of the same question — has
	 * always walked the whole tree. That asymmetry produced duplicates: a user
	 * files a mirror into a plain subfolder (which `designs/move.feature` explicitly
	 * allows and §6.29 makes meaningless to Penpot), the next pull cannot see it
	 * here, and so it creates a SECOND mirror for the same design at the
	 * canonical path.
	 *
	 * The duplicate then persists forever. The prune walks recursively, finds the
	 * id, sees Penpot still lists it, and correctly prunes nothing — so nothing
	 * ever cleans up after the mistake. Two files, one design, no complaint.
	 *
	 * ## WHERE IT STOPS, AND WHY THAT IS THE SAME RULE AS EVERYWHERE ELSE
	 *
	 * The descent stops at any subfolder carrying its own `penpot_project_id`:
	 * those files have a NEARER project ancestor and belong to that project, not
	 * this one. That is {@see MembershipResolver} read downwards, and it is the
	 * identical rule {@see ProjectFolderService::managedDesignsBelow()} uses when
	 * the tag opt-in collects designs to re-file. All three have to agree, or one
	 * of them claims files another attributes elsewhere.
	 *
	 * @return array<string, File> penpot_id -> file
	 */
	private function indexFilesByPenpotId(Folder $target, int $depth = 0): array {
		// THE SAME RUNG {@see collectMirrors()} STOPS AT, and they have to match:
		// the prune and the upsert are two halves of one question, and a walk that
		// stopped shallower than its partner would attribute files the partner still
		// claims (§C6.20). A mirror below the ceiling is invisible to both, so the
		// upsert writes a fresh one beside it — a visible duplicate, which is what
		// every other walk in this app already trades an unbounded recursion for.
		if ($depth >= self::MAX_DEPTH) {
			return [];
		}

		$index = [];
		foreach ($target->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				if ($this->metadata->readFolder($node->getId())->hasProject()) {
					continue; // a nearer project ancestor owns everything below it
				}
				// A plain subfolder is ordinary Nextcloud organisation Penpot has
				// no concept of — the mirrors inside it are still ours.
				//
				// `array_replace`, NOT `+=`. Array union is FIRST-wins, and this
				// index is documented last-wins ({@see collectMirrors()}, which is
				// deliberately the other way): the upsert wants the newest node to
				// receive the write. Folding children in with `+=` would silently
				// invert that for exactly the installs that matter — the ones
				// carrying duplicates left by the bug this recursion fixes.
				$index = array_replace($index, $this->indexFilesByPenpotId($node, $depth + 1));
				continue;
			}
			if (!$node instanceof File) {
				continue;
			}
			$penpotId = $this->metadata->readFile($node->getId())?->penpotId ?? '';
			if ($penpotId !== '') {
				$index[$penpotId] = $node;
			}
		}
		return $index;
	}

	/**
	 * A node name not already taken under $parent — appends ` (2)`, ` (3)`, … on
	 * collision. Covers the duplicate-project-name / duplicate-file-name cases
	 * (saga #31) with the conservative "never clobber" choice; a smarter
	 * tie-break rides the same seam later.
	 */
	private function freeName(Folder $parent, string $name): string {
		if (!$parent->nodeExists($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		for ($i = 2; $i < 1000; $i++) {
			$candidate = $stem . ' (' . $i . ')' . $ext;
			if (!$parent->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return $stem . ' (' . uniqid() . ')' . $ext;
	}

	/**
	 * Best-effort rename of $node to follow an upstream Penpot rename. A failed
	 * move (permission, race, unexpected coordinate) is logged and swallowed —
	 * the mirror keeps its old name rather than aborting the whole pull over a
	 * cosmetic follow.
	 */
	/**
	 * Move a mirror into the folder its design now belongs to.
	 *
	 * SEPARATE FROM {@see tryRename()} ON PURPOSE, and the difference is one the
	 * spec cares about. `tryRename()` returns early when the name already matches,
	 * which is exactly the case here — the design kept its name and changed project
	 * — so it would do nothing. Teaching it to compare parents instead would make
	 * it relocate on EVERY mismatch, and that would yank a mirror out of a plain
	 * subfolder the user deliberately filed it into, which §6.29 forbids.
	 *
	 * So the two callers stay apart: the found-in-this-project path renames in
	 * place, and only a mirror found under a different project is moved.
	 *
	 * @return File|null the relocated node, or null when the move failed — in which
	 *                   case the caller writes a fresh mirror and the stale one is
	 *                   left for a later pull rather than the design going unmirrored
	 */
	private function relocate(File $node, Folder $target, string $name): ?File {
		$from = $node->getPath();
		$to = $target->getPath() . '/' . ($target->nodeExists($name) ? $this->freeName($target, $name) : $name);

		try {
			$node->move($to);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not follow a design that changed project; leaving the mirror where it is', [
				'app' => Application::APP_ID,
				'from' => $from,
				'to' => $to,
				'exception' => $e,
			]);

			return null;
		}

		$this->logger->info('penpot_sync pull: moved a mirror to follow its design into another project', [
			'app' => Application::APP_ID,
			'from' => $from,
			'to' => $to,
		]);

		return $node;
	}

	private function tryRename(File|Folder $node, Folder $parent, string $name): void {
		if ($node->getName() === $name) {
			return;
		}
		if ($parent->nodeExists($name)) {
			// ── TWO DESIGNS, ONE NAME, AND THE SUFFIX IS NEXTCLOUD'S ALONE ───────
			//
			// Penpot is perfectly happy with two designs called `Alpha`; a folder
			// cannot hold two files called `Alpha.penpot`. So the arriving one takes
			// the suffix core would have given it, exactly as a design that arrives
			// NEW into a crowded folder already does — `freeName()` is the same call.
			//
			// This used to keep the OLD name and log it, on the reasoning "keep the
			// old name rather than clobber". Clobbering was never the alternative:
			// a free name clobbers nothing, and the state it avoided instead was a
			// mirror stuck on a name its design no longer has — `Beta.penpot` for a
			// design called `Alpha`, which is the drift the rename exists to
			// prevent. `designs/rename.feature` spells the wanted outcome out.
			$name = $this->freeName($parent, $name);
		}
		try {
			$node->move($parent->getPath() . '/' . $name);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not rename mirror node to follow Penpot', [
				'app' => Application::APP_ID,
				'from' => $node->getName(),
				'to' => $name,
				'exception' => $e,
			]);
		}
	}

	/** A Penpot FILE's name is a single Nextcloud node name — `/` is illegal here. */
	private function isLegalName(string $name): bool {
		return $name !== '' && !str_contains($name, '/');
	}

	/**
	 * A Penpot PROJECT's name is a path below the mapping root (§C6.38), so a `/`
	 * in it is a level of nesting rather than an illegal character.
	 *
	 * WHAT IS STILL REFUSED is anything that cannot become a folder path at all: an
	 * empty name, an empty segment (`a//b`, `foo/`), a `.` or `..` segment — which
	 * would escape the mapping root or resolve to it — and a name nested past the
	 * depth ceiling every other walk here obeys.
	 *
	 * ## STRICTLY INSIDE THAT CEILING, NOT LEVEL WITH IT
	 *
	 * `>=`, not `>`, and the margin is deliberate. Every downward walk in this class
	 * — {@see indexProjectFolders()}, {@see orphanProjectFolders()},
	 * {@see collectMirrors()} and {@see indexFilesByPenpotId()} — bails at
	 * `$depth >= MAX_DEPTH`, and a call at depth `d` lists the nodes one below it,
	 * so they reach exactly `MAX_DEPTH` levels down. A name of exactly `MAX_DEPTH`
	 * segments landed on the last rung any of them could see. It fitted by an
	 * accident of where independently written guards sit rather than by anything
	 * any of them states.
	 *
	 * That is not a property to leave implicit, because being level with it is one
	 * `+ 1` away from being past it — and past it the failure is silent and awful:
	 * the folder is created, never found by id again, and re-created on every pull.
	 *
	 */
	private function isLegalProjectName(string $name): bool {
		$segments = explode('/', $name);
		if (count($segments) >= self::MAX_DEPTH) {
			return false;
		}

		foreach ($segments as $segment) {
			if (!$this->isLegalName($segment) || $segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}

	/**
	 * Is $name the leaf itself, or a name {@see freeName()} would have made from it?
	 *
	 * The test is deliberately shaped by `freeName()` rather than guessed at: the
	 * suffix goes before the extension, and the 1000th collision falls back to a
	 * `uniqid()` instead of a number — hence `\w+` rather than `\d+`. Anything else
	 * is a name that has nothing to do with this project's, which is the state a
	 * parked folder is in.
	 */
	private static function isVariantOf(string $name, string $leaf): bool {
		if ($name === $leaf) {
			return true;
		}

		$dot = strrpos($leaf, '.');
		$stem = $dot === false ? $leaf : substr($leaf, 0, $dot);
		$ext = $dot === false ? '' : substr($leaf, $dot);

		return preg_match(
			'/^' . preg_quote($stem, '/') . ' \(\w+\)' . preg_quote($ext, '/') . '$/',
			$name,
		) === 1;
	}

	/**
	 * A project name split into the Nextcloud path it denotes.
	 *
	 * Only ever called on a name {@see isLegalProjectName()} accepted, so the filter
	 * removes nothing — it is there so a future caller cannot hand this an empty
	 * segment and get a folder named `''`.
	 *
	 * @return list<string>
	 */
	private static function segments(string $name): array {
		return array_values(array_filter(
			explode('/', $name),
			static fn (string $segment): bool => $segment !== '',
		));
	}

	/** @param array<string, mixed> $record */
	private function str(array $record, string $key): string {
		return is_string($record[$key] ?? null) ? $record[$key] : '';
	}

	/**
	 * Fold the per-mapping results into one summary.
	 *
	 * A FAILED EXPORT DOES NOT MAKE THE PULL AN ERROR. `failed` is reported as
	 * its own count and the status stays `ok`: every other fact about those files
	 * reconciled, the previous archive is intact, and the next pull retries. Only
	 * a failure that stopped a whole mapping (`error`) is a failed pull.
	 *
	 * @param list<array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, reaped:int, orphaned:int, error:?string}> $results
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, reaped:int, orphaned:int, status:string, message:?string}
	 */
	private function finalise(array $results): array {
		$total = ['processed' => 0, 'folders' => 0, 'files' => 0, 'exported' => 0, 'failed' => 0, 'skipped' => 0, 'pruned' => 0, 'rescued' => 0, 'lost' => 0, 'reaped' => 0, 'orphaned' => 0];
		$errors = [];
		foreach ($results as $res) {
			foreach (array_keys($total) as $key) {
				$total[$key] += $res[$key];
			}
			if (is_string($res['error']) && $res['error'] !== '') {
				$errors[] = $res['error'];
			}
		}
		return $total + [
			'status' => $errors === [] ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}
}
