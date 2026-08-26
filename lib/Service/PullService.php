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
 *   - **The project-folder visible tag** (§6.32) — the human pill. Metadata is
 *     written now; the systemtag lands with the Files-app surface.
 *   - **Adopting a mirror out of the Nextcloud trash** (§6.37) — a design that
 *     comes back is currently re-created beside its trashed mirror rather than
 *     matched to it by `penpot_id`. That needs `files_trashbin` and is its own
 *     slice; nothing here hard-deletes, so no data is at risk in the meantime.
 *   - **The `/` guard as a reported skip** (§6.51) — a project or file whose
 *     Penpot name contains `/` (illegal as a single Nextcloud node name) is
 *     skipped and logged here; Course 4 turns that into the user-facing report.
 *
 * ## THE PRUNE, AND WHY IT IS GATED ON A COMPLETE LISTING (saga §6.25)
 *
 * A mirror whose Penpot design was deleted is moved to the **Nextcloud trash**,
 * never hard-deleted. It runs from a `penpot_id` seen-set built while walking the
 * team, and **only when every project in that team listed cleanly** — a failed,
 * partial, or skipped listing is indistinguishable from "everything was deleted",
 * and reading a network blip as evidence that a user's files are gone is the one
 * mistake this app must never make. Skipping is therefore *not* free: a project
 * passed over for an illegal name takes the whole prune down with it, because its
 * files are exactly as unseen as deleted ones.
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

	public function __construct(
		private readonly MappingService $mappings,
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly StorageService $storage,
		private readonly ArchiveService $archives,
		private readonly ProjectTags $tags,
		private readonly SyncGuard $guard,
		private readonly MirrorTimes $times,
		private readonly TrashControl $trash,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Pull one mapping, or every mapping when `$mappingId` is null/empty.
	 *
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, status:string, message:?string}
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
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, error:?string}
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
				$root = $this->storage->ensureRoot($mapping);
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

				// Every `penpot_id` Penpot named during this walk. Anything mirrored
				// under the root and NOT in here is a candidate for the prune — which
				// is why $complete has to travel with it.
				$seen = [];
				$complete = true;

				foreach ($this->teamProjects($mapping->teamId) as $project) {
					$processed++;
					$projectId = $this->str($project, 'id');
					if ($projectId === '') {
						$skipped++;
						$complete = false;
						continue;
					}

					$target = $root;
					if (!$this->isDefaultProject($project)) {
						$projectName = $this->str($project, 'name');
						if (!$this->isLegalName($projectName)) {
							$this->logger->warning('penpot_sync pull: skipping project with an illegal folder name', [
								'app' => Application::APP_ID,
								'project' => $projectId,
								'name' => $projectName,
							]);
							$skipped++;
							// A SKIPPED PROJECT IS AN INCOMPLETE LISTING. Its files were
							// never enumerated, so they are indistinguishable from files
							// Penpot no longer has — and pruning them would delete a whole
							// project's mirrors over a slash in a name.
							$complete = false;
							continue;
						}
						$target = $this->ensureProjectFolder($root, $folderIndex, $projectId, $projectName, $project);
						$folders++;
					}

					$files += $this->pullProjectFiles($target, $strays, $mapping, $projectId, $seen, $exported, $failed, $skipped, $complete);
				}

				// Only now, and only if nothing was missed. An exception on any listing
				// has already left this closure entirely, which is the same protection
				// stated as control flow rather than as a flag.
				if ($complete) {
					$this->prune($root, $mapping, $seen, $pruned, $rescued, $lost);
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
	 * @param array{processed?:int, folders?:int, files?:int, exported?:int, failed?:int, skipped?:int, pruned?:int, rescued?:int, lost?:int, error?:?string} $counts
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, error:?string}
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
	 * ## TWO MARKERS, ONE MACHINE-READABLE AND ONE HUMAN-READABLE
	 *
	 * `penpot_project_id` is authoritative — it is what {@see MembershipResolver}
	 * reads and what every other feature defers to. The `penpot` TAG is the same
	 * fact made visible in the Files app, and it is stamped here so that the two
	 * directions share ONE marker (§C6.18):
	 *
	 *   a project mirrored FROM Penpot   → tagged here
	 *   a folder opted IN from Nextcloud → tagged by the user, which is the opt-in
	 *
	 * A user cannot tell, and should not have to, which way round a given project
	 * folder came about. Both carry the tag; both are projects.
	 *
	 * Tagging is idempotent and this runs on every pull, which is deliberate: a
	 * folder whose tag someone removed gets it back on the next run, because the
	 * project id — the thing that actually decides — never went anywhere.
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
	 * @param array<string, Folder> $folderIndex penpot_project_id -> folder, built once by the caller
	 * @param array<string, mixed> $project the Penpot project record (carries `created-at`)
	 */
	private function ensureProjectFolder(Folder $root, array $folderIndex, string $projectId, string $name, array $project = []): Folder {
		$existing = $folderIndex[$projectId] ?? null;
		if ($existing !== null) {
			$this->tryRename($existing, $root, $name);
			$this->metadata->writeFolder($existing->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
			$this->tagProject($existing);
			$this->times->apply($existing, null, MirrorTimes::parse($project['created-at'] ?? null));
			return $existing;
		}

		// No folder yet carries this project id. Adopt a same-named folder if one
		// happens to sit there (a first pull over a hand-made tree), else create.
		$adopt = $root->nodeExists($name) ? $root->get($name) : null;
		$folder = $adopt instanceof Folder ? $adopt : $root->newFolder($this->freeName($root, $name));
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
		$this->tagProject($folder);
		$this->times->apply($folder, null, MirrorTimes::parse($project['created-at'] ?? null));
		return $folder;
	}

	/**
	 * Mark a project folder with the `penpot` tag, never at the cost of the pull.
	 *
	 * The tag is decoration over an authoritative id, so a tag backend that is
	 * unhappy must not be able to fail a mirror run that has otherwise worked.
	 * The folder is still a project; it is simply not wearing its badge yet, and
	 * the next pull tries again.
	 */
	private function tagProject(Folder $folder): void {
		try {
			$this->tags->apply($folder->getId());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync pull: could not tag a project folder', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);
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
			// and per-file mode changes were retired courses ago (`sync-mode.feature`
			// no longer exists). A link is a link for as long as it exists.
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
	 * @return array<string, File> penpot_id -> file
	 */
	private function collectMirrors(Folder $folder): array {
		$found = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$found += $this->collectMirrors($node);
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
	 * single directory walk, so a pull is O(children) not O(projects × children).
	 *
	 * @return array<string, Folder> penpot_project_id -> folder (last wins on the impossible dup)
	 */
	private function indexProjectFolders(Folder $root): array {
		$index = [];
		foreach ($root->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$projectId = $this->metadata->readFolder($node->getId())->projectId;
			if ($projectId !== '') {
				$index[$projectId] = $node;
			}
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
	 * files a mirror into a plain subfolder (which `move.feature` explicitly
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
	private function indexFilesByPenpotId(Folder $target): array {
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
				$index = array_replace($index, $this->indexFilesByPenpotId($node));
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

	/** A Penpot object name is a single Nextcloud node name — `/` is illegal here. */
	private function isLegalName(string $name): bool {
		return $name !== '' && !str_contains($name, '/');
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
	 * @param list<array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, error:?string}> $results
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, pruned:int, rescued:int, lost:int, status:string, message:?string}
	 */
	private function finalise(array $results): array {
		$total = ['processed' => 0, 'folders' => 0, 'files' => 0, 'exported' => 0, 'failed' => 0, 'skipped' => 0, 'pruned' => 0, 'rescued' => 0, 'lost' => 0];
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
