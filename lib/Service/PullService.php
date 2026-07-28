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
 *   - **Prune of stale mirror files** whose Penpot object vanished — the pull is
 *     currently upsert-only. A file whose project or file was deleted upstream
 *     is left until the trash-aware reconciler (Course 5).
 *   - **The `/` guard as a reported skip** (§6.51) — a project or file whose
 *     Penpot name contains `/` (illegal as a single Nextcloud node name) is
 *     skipped and logged here; Course 4 turns that into the user-facing report.
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
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Pull one mapping, or every mapping when `$mappingId` is null/empty.
	 *
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, status:string, message:?string}
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
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, error:?string}
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

				$folders = 0;
				$files = 0;
				$exported = 0;
				$failed = 0;
				$skipped = 0;
				$processed = 0;

				foreach ($this->teamProjects($mapping->teamId) as $project) {
					$processed++;
					$projectId = $this->str($project, 'id');
					if ($projectId === '') {
						$skipped++;
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
							continue;
						}
						$target = $this->ensureProjectFolder($root, $folderIndex, $projectId, $projectName);
						$folders++;
					}

					$files += $this->pullProjectFiles($target, $mapping, $projectId, $exported, $failed, $skipped);
				}

				return $this->tally([
					'processed' => $processed,
					'folders' => $folders,
					'files' => $files,
					'exported' => $exported,
					'failed' => $failed,
					'skipped' => $skipped,
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
	 * @param array{processed?:int, folders?:int, files?:int, exported?:int, failed?:int, skipped?:int, error?:?string} $counts
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, error:?string}
	 */
	private function tally(array $counts): array {
		return $counts + [
			'processed' => 0,
			'folders' => 0,
			'files' => 0,
			'exported' => 0,
			'failed' => 0,
			'skipped' => 0,
			'error' => null,
		];
	}

	/**
	 * Mirror the files of one project into $target, upserting by `penpot_id`.
	 *
	 * @param int $exported mutated in place: incremented per archive downloaded
	 * @param int $failed mutated in place: incremented per export that failed
	 * @param int $skipped mutated in place: incremented for each illegally-named file
	 * @return int the number of files written (created or updated)
	 *
	 * @throws PenpotApiException
	 */
	private function pullProjectFiles(Folder $target, Mapping $mapping, string $projectId, int &$exported, int &$failed, int &$skipped): int {
		// Index this folder's existing `.penpot` files ONCE (penpot_id -> file)
		// instead of re-walking the directory listing for every Penpot file.
		$fileIndex = $this->indexFilesByPenpotId($target);
		$written = 0;
		foreach ($this->client->getProjectFiles($projectId) as $file) {
			$fileId = $this->str($file, 'id');
			$baseName = $this->str($file, 'name');
			if ($fileId === '' || !$this->isLegalName($baseName)) {
				$this->logger->warning('penpot_sync pull: skipping file with a missing id or illegal name', [
					'app' => Application::APP_ID,
					'project' => $projectId,
					'name' => $baseName,
				]);
				$skipped++;
				continue;
			}
			$this->upsertMirrorFile($target, $fileIndex, $mapping, $fileId, $baseName, $file, $exported, $failed);
			$written++;
		}
		return $written;
	}

	/**
	 * Find (by `penpot_project_id`) or create the folder for a project under the
	 * team root, and (re)stamp its marker. A rename upstream renames the folder.
	 *
	 * @param array<string, Folder> $folderIndex penpot_project_id -> folder, built once by the caller
	 */
	private function ensureProjectFolder(Folder $root, array $folderIndex, string $projectId, string $name): Folder {
		$existing = $folderIndex[$projectId] ?? null;
		if ($existing !== null) {
			$this->tryRename($existing, $root, $name);
			$this->metadata->writeFolder($existing->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
			return $existing;
		}

		// No folder yet carries this project id. Adopt a same-named folder if one
		// happens to sit there (a first pull over a hand-made tree), else create.
		$adopt = $root->nodeExists($name) ? $root->get($name) : null;
		$folder = $adopt instanceof Folder ? $adopt : $root->newFolder($this->freeName($root, $name));
		$this->metadata->writeFolder($folder->getId(), [PenpotMetadata::KEY_PROJECT_ID => $projectId]);
		return $folder;
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
	 * The pointer body is written first, unconditionally, and the archive
	 * replaces it. That ordering is what makes a failed first export leave a
	 * usable pointer rather than an empty file — the user still gets a mirror
	 * that names the design and opens it, and the next pull retries the bytes.
	 *
	 * @param array<string, File> $fileIndex penpot_id -> file, built once by the caller
	 * @param array<string, mixed> $file the decoded Penpot file record (carries `revn` + `modified-at`)
	 * @param int $exported mutated in place
	 * @param int $failed mutated in place
	 */
	private function upsertMirrorFile(Folder $target, array $fileIndex, Mapping $mapping, string $fileId, string $baseName, array $file, int &$exported, int &$failed): void {
		$name = $baseName . self::EXTENSION;
		$revn = (string)($file['revn'] ?? '');
		$modifiedAt = $this->str($file, 'modified-at');
		// The drift signal is `revn` + `modified-at` together (saga §5.5): revn
		// alone cannot tell "same revn, newer modified-at" apart, which the
		// scheduled-pull diff needs. Stored as one opaque string — callers
		// compare it whole, never parse it.
		$signal = $this->revisionSignal($revn, $modifiedAt);

		$existing = $fileIndex[$fileId] ?? null;
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

		$wantsArchive = $mode === Mapping::MODE_SYNC;
		if (!$wantsArchive || $existing === null) {
			$this->archives->storeLink($node, $fileId, $baseName, $revn, $modifiedAt, $mapping->teamId);
		}

		// `true` when the mirror is current and the revision stamp may advance.
		$current = true;
		if ($wantsArchive && $this->driftedOrMissing($node, $stored, $signal)) {
			try {
				$this->archives->storeArchive($node, $fileId);
				$exported++;
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
		];
		if ($current) {
			$values[PenpotMetadata::KEY_REVISION] = $signal;
		}
		$this->metadata->writeFile($node->getId(), $values);
	}

	/**
	 * Does this `sync` file need a fresh export?
	 *
	 * TWO REASONS, AND THE SECOND IS THE IMPORTANT ONE. Drift is obvious: the
	 * design changed upstream. Missing bytes are subtler — a file stamped `sync`
	 * that holds no archive is a promotion whose export never landed (or a pull
	 * that was interrupted), and checking for it is what makes that state heal
	 * itself on the next pass instead of persisting until someone notices the
	 * "backup" is a pointer.
	 *
	 * The cheap test runs first: an unchanged signal is a string compare, and
	 * only then do we touch the filesystem.
	 */
	private function driftedOrMissing(File $node, string $stored, string $signal): bool {
		return $stored !== $signal || !$this->archives->holdsArchive($node);
	}

	/** The opaque `revn` + `modified-at` drift signal stored as `penpot_revision`. */
	private function revisionSignal(string $revn, string $modifiedAt): string {
		if ($modifiedAt === '') {
			return $revn;
		}
		return $revn . '@' . $modifiedAt;
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
	 * Index a folder's `.penpot` link files by their `penpot_id` in a single
	 * directory walk, so upserting N files is O(children) not O(N × children).
	 *
	 * @return array<string, File> penpot_id -> file
	 */
	private function indexFilesByPenpotId(Folder $target): array {
		$index = [];
		foreach ($target->getDirectoryListing() as $node) {
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
	private function tryRename(File|Folder $node, Folder $parent, string $name): void {
		if ($node->getName() === $name) {
			return;
		}
		if ($parent->nodeExists($name)) {
			// The desired name is already taken by a different node, so this rename
			// would collide. Keep the old name rather than clobber — but say so, or
			// a mirror stuck on a stale name looks like a silent bug.
			$this->logger->debug('penpot_sync pull: skipping rename, target name already exists', [
				'app' => Application::APP_ID,
				'from' => $node->getName(),
				'to' => $name,
			]);
			return;
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
	 * @param list<array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, error:?string}> $results
	 * @return array{processed:int, folders:int, files:int, exported:int, failed:int, skipped:int, status:string, message:?string}
	 */
	private function finalise(array $results): array {
		$total = ['processed' => 0, 'folders' => 0, 'files' => 0, 'exported' => 0, 'failed' => 0, 'skipped' => 0];
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
