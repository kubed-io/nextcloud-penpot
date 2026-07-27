<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
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
 *   - **`sync` mode's archive download** (`export-binfile`) — Course 4. Both
 *     modes write a link marker for now; the stamped mode still differs.
 *   - **Prune of stale mirror files** whose Penpot object vanished — the pull is
 *     currently upsert-only. A file whose project or file was deleted upstream
 *     is left until the trash-aware reconciler (Course 5).
 *   - **The `/` guard as a reported skip** (§6.51) — a project or file whose
 *     Penpot name contains `/` (illegal as a single Nextcloud node name) is
 *     skipped and logged here; Course 4 turns that into the user-facing report.
 */
final class PullService {
	/** Nextcloud-side extension for a mirrored Penpot design (saga §6.4). */
	public const EXTENSION = '.penpot';

	public function __construct(
		private readonly MappingService $mappings,
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly StorageService $storage,
		private readonly SyncGuard $guard,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Pull one mapping, or every mapping when `$mappingId` is null/empty.
	 *
	 * @return array{processed:int, folders:int, files:int, skipped:int, status:string, message:?string}
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
	 * @return array{processed:int, folders:int, files:int, skipped:int, error:?string}
	 */
	public function pullOne(Mapping $mapping): array {
		if (!$this->storage->isAvailable($mapping)) {
			$this->logger->warning('penpot_sync pull skipped: storage backend not available for this mapping', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'use_team_folder' => $mapping->useTeamFolder,
			]);
			return ['processed' => 0, 'folders' => 0, 'files' => 0, 'skipped' => 1, 'error' => null];
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

					$files += $this->pullProjectFiles($target, $mapping, $projectId, $skipped);
				}

				return ['processed' => $processed, 'folders' => $folders, 'files' => $files, 'skipped' => $skipped, 'error' => null];
			});
		} catch (PenpotApiException $e) {
			$this->logger->warning('penpot_sync pull failed', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return ['processed' => 0, 'folders' => 0, 'files' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
		} catch (\Throwable $e) {
			// A filesystem or metadata failure (ensureRoot, a folder write, a bad
			// node) must not abort every OTHER mapping in a bulk pull — contain it
			// to this mapping's error result, the same way an API failure is.
			$this->logger->error('penpot_sync pull failed unexpectedly', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return ['processed' => 0, 'folders' => 0, 'files' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Mirror the files of one project into $target, upserting by `penpot_id`.
	 *
	 * @param int $skipped mutated in place: incremented for each illegally-named file
	 * @return int the number of files written (created or updated)
	 *
	 * @throws PenpotApiException
	 */
	private function pullProjectFiles(Folder $target, Mapping $mapping, string $projectId, int &$skipped): int {
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
			$this->upsertLinkFile($target, $fileIndex, $mapping, $fileId, $baseName, $file);
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
	 * Find (by `penpot_id`) or create the `.penpot` link file for a Penpot file,
	 * refresh its body, and (re)stamp id / revision / mode.
	 *
	 * @param array<string, File> $fileIndex penpot_id -> file, built once by the caller
	 * @param array<string, mixed> $file the decoded Penpot file record (carries `revn` + `modified-at`)
	 */
	private function upsertLinkFile(Folder $target, array $fileIndex, Mapping $mapping, string $fileId, string $baseName, array $file): void {
		$name = $baseName . self::EXTENSION;
		$revn = (string)($file['revn'] ?? '');
		$modifiedAt = $this->str($file, 'modified-at');
		$body = $this->linkBody($mapping, $fileId, $baseName, $revn, $modifiedAt);

		$existing = $fileIndex[$fileId] ?? null;
		if ($existing !== null) {
			$this->tryRename($existing, $target, $name);
			$existing->putContent($body);
			$node = $existing;
		} else {
			$node = $target->newFile($this->freeName($target, $name), $body);
		}

		$this->metadata->writeFile($node->getId(), [
			PenpotMetadata::KEY_ID => $fileId,
			// The drift signal is `revn` + `modified-at` together (saga §5.5): revn
			// alone cannot tell "same revn, newer modified-at" apart, which the
			// scheduled-pull diff needs. Stored as one opaque string — callers
			// compare it whole, never parse it.
			PenpotMetadata::KEY_REVISION => $this->revisionSignal($revn, $modifiedAt),
			PenpotMetadata::KEY_MODE => $mapping->mode,
		]);
	}

	/**
	 * The `link` file body: a small JSON pointer carrying the Penpot ids and the
	 * instance URL. The metadata keys are the machine contract; this body is a
	 * human-readable copy plus enough to build a browser deep-link later.
	 *
	 * NO deep-link URL is fabricated here. Penpot's workspace route has not been
	 * confirmed live (saga doctrine: call it before you design around it), so the
	 * body carries the ids and the instance base and leaves the exact link to
	 * Course 4, which will verify it against a running Penpot.
	 */
	private function linkBody(Mapping $mapping, string $fileId, string $baseName, string $revn, string $modifiedAt): string {
		$payload = [
			'penpot' => 'reference/v1',
			'id' => $fileId,
			'name' => $baseName,
			'revn' => $revn,
			'modified_at' => $modifiedAt,
			'team_id' => $mapping->teamId,
			'instance_url' => $this->config->getValueString(Application::APP_ID, InstanceSettings::KEY_URL, ''),
		];
		// JSON_THROW_ON_ERROR, not a silent (string) cast: json_encode can return
		// false (e.g. malformed UTF-8 in a file name), and writing an empty body
		// would be a silently corrupt mirror file. Matches PenpotClient's encoding.
		return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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
	 * @param list<array{processed:int, folders:int, files:int, skipped:int, error:?string}> $results
	 * @return array{processed:int, folders:int, files:int, skipped:int, status:string, message:?string}
	 */
	private function finalise(array $results): array {
		$total = ['processed' => 0, 'folders' => 0, 'files' => 0, 'skipped' => 0];
		$errors = [];
		foreach ($results as $res) {
			$total['processed'] += $res['processed'];
			$total['folders'] += $res['folders'];
			$total['files'] += $res['files'];
			$total['skipped'] += $res['skipped'];
			if (is_string($res['error']) && $res['error'] !== '') {
				$errors[] = $res['error'];
			}
		}
		return [
			'processed' => $total['processed'],
			'folders' => $total['folders'],
			'files' => $total['files'],
			'skipped' => $total['skipped'],
			'status' => $errors === [] ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}
}
