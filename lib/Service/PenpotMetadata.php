<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;

/**
 * Wraps Nextcloud's Files Metadata API for the Penpot mirror — the master's
 * {@see \OCA\N8nSync\Service\WorkflowMetadata} / the apprentice's
 * {@see \OCA\GrafanaSync\Service\DashboardMetadata}, re-cut for our ingredient.
 * This is the **metadata contract** the pull and the resolver build against.
 *
 * ## TWO KINDS OF NODE CARRY METADATA HERE — BOTH SIBLINGS ONLY STAMP FILES
 *
 * The one real shape difference from both siblings: identity lives on FOLDERS as
 * well as files (saga §6.21, §6.29). A `.penpot` file carries its own id; the
 * PROJECT and TEAM it belongs to are read off ancestor *folders*, because Penpot
 * is flat (team → project → file) while Nextcloud nests freely.
 *
 *   FILE keys (on a mirrored `.penpot`):
 *     penpot_id       — the Penpot file id. Stable across renames/moves. INDEXED.
 *     penpot_revision — `revn` + `modifiedAt` drift signal (saga §5.5).
 *     penpot_mode     — sync | reference(=link) | unmapped. INDEXED.
 *   FOLDER keys:
 *     penpot_project_id — on a project folder. INDEXED.
 *     penpot_team_id    — on a Team Folder. INDEXED.
 *
 * The folder ids are INDEXED so the reconciler can find "the folder carrying
 * project id X" with a search instead of a full-tree walk — which is exactly
 * what the duplicate-project-id conflict check (mapping-membership.feature,
 * saga open question #30) will need. The resolver's own upward walk does not use
 * the index; the index is for the reverse lookup.
 *
 * ## THE `link` ⇄ `reference` WIRE TRANSLATION (carried over from BOTH siblings)
 *
 * NC core's FilesPlugin feeds metadata values straight into PropFind::handle(),
 * which invokes them as callbacks when `is_callable($value)` is true. The string
 * `link` matches PHP's builtin `link()`, so storing it **explodes every
 * PROPFIND** on the folder tree. Both siblings hit this and store link mode as
 * the value `reference`, translating back on read. This app inherits the exact
 * same hazard the moment `penpot_mode` can be `link`, so it inherits the exact
 * same fix. This is the ONLY place `reference` appears; everywhere else the mode
 * is `link`. `sync` / `unmapped` are not callable and store as-is. Any future
 * mode value MUST clear `is_callable()`.
 *
 * ## WHY THIS IS THE CLEANEST LAYER (same argument as both siblings)
 *
 *  - **Server-side reads** (the resolver, the pull, `occ`) call the read methods
 *    directly — zero DAV plumbing, zero round-trips.
 *  - **DAV/PROPFIND exposure is automatic.** Once registered with
 *    `initMetadata()`, every key is advertised at `{nc:}metadata-<key>`, and the
 *    indexed keys are SEARCH/REPORT-queryable.
 *
 * All keys are EDIT_FORBIDDEN: clients cannot mutate them via PROPPATCH. Only
 * this app writes them, from the pull reconciler (a later course).
 */
final class PenpotMetadata {
	// ── file keys ────────────────────────────────────────────────────────────
	/** The Penpot file id — the stable thread. INDEXED. */
	public const KEY_ID = 'penpot_id';
	/** `revn` + `modifiedAt` drift signal (saga §5.5). Not indexed. */
	public const KEY_REVISION = 'penpot_revision';
	/** sync | reference(=link) | unmapped — INDEXED. */
	public const KEY_MODE = 'penpot_mode';

	// ── folder keys (saga §6.21) ─────────────────────────────────────────────
	/** On a project folder — the authoritative machine marker. INDEXED. */
	public const KEY_PROJECT_ID = 'penpot_project_id';
	/** On a Team Folder. INDEXED. */
	public const KEY_TEAM_ID = 'penpot_team_id';

	/**
	 * File-mode value not covered by {@see Mapping} (which only configures
	 * sync/link): a file that carries a `penpot_id` but resolves to no Penpot
	 * ancestor. Distinct from *untracked* — a file with no record at all.
	 */
	public const MODE_UNMAPPED = 'unmapped';

	/**
	 * The on-the-wire (stored) value for {@see Mapping::MODE_LINK}. `link` itself
	 * is `is_callable()` and crashes core PROPFIND, so it is stored as
	 * `reference` and translated back by {@see readFile()}. THE ONLY place
	 * `reference` appears.
	 */
	private const WIRE_LINK = 'reference';

	/** Every key, in a stable order suitable for diagnostics. */
	public const KEYS = [
		self::KEY_ID,
		self::KEY_REVISION,
		self::KEY_MODE,
		self::KEY_PROJECT_ID,
		self::KEY_TEAM_ID,
	];

	/** Keys stored as searchable indexes (the rest are plain, read-only props). */
	private const INDEXED_KEYS = [
		self::KEY_ID,
		self::KEY_MODE,
		self::KEY_PROJECT_ID,
		self::KEY_TEAM_ID,
	];

	public function __construct(
		private readonly IFilesMetadataManager $manager,
	) {
	}

	/**
	 * Idempotently register every key with the Files Metadata system.
	 *
	 * Called once from {@see \OCA\PenpotSync\AppInfo\Application::boot()}. After
	 * this runs, the keys are surfaced over DAV as `{nc:}metadata-<key>`, and the
	 * INDEXED_KEYS are SEARCH/REPORT-queryable — so "find the folder carrying
	 * project id X" and "find every sync / link / unmapped file" are fast indexed
	 * queries, not folder walks.
	 */
	public function register(): void {
		foreach (self::KEYS as $key) {
			$this->manager->initMetadata(
				$key,
				IMetadataValueWrapper::TYPE_STRING,
				in_array($key, self::INDEXED_KEYS, true), // indexed → searchable
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}
	}

	/**
	 * Upsert the FILE keys for a mirrored `.penpot` file. Any key omitted from
	 * `$values` is left as-is; pass an explicit empty string to overwrite. The
	 * mode is given in the canonical vocabulary (`sync`/`link`/`unmapped`);
	 * `link` is stored as `reference` on the wire (see class docblock).
	 *
	 * @param array{penpot_id?:string, penpot_revision?:string, penpot_mode?:string} $values
	 */
	public function writeFile(int $fileId, array $values): void {
		$this->writeKeys($fileId, $values, [self::KEY_ID, self::KEY_REVISION, self::KEY_MODE]);
	}

	/**
	 * Upsert the FOLDER markers for a project folder and/or a Team Folder.
	 *
	 * @param array{penpot_project_id?:string, penpot_team_id?:string} $values
	 */
	public function writeFolder(int $folderId, array $values): void {
		$this->writeKeys($folderId, $values, [self::KEY_PROJECT_ID, self::KEY_TEAM_ID]);
	}

	/**
	 * Read the FILE keys for a node as a typed {@see PenpotFileMetadata}.
	 *
	 * Returns null if the node has no metadata record at all — that absence is
	 * exactly the *untracked* state (no `penpot_id`), so callers must not treat
	 * null as an error. Otherwise a view whose unset keys read back as `''`. The
	 * mode is returned canonical (the stored `reference` becomes `link`).
	 */
	public function readFile(int $fileId): ?PenpotFileMetadata {
		$metadata = $this->readRecord($fileId);
		if ($metadata === null) {
			return null;
		}
		$get = fn (string $key): string => $metadata->hasKey($key) ? $metadata->getString($key) : '';
		return new PenpotFileMetadata(
			$get(self::KEY_ID),
			$get(self::KEY_REVISION),
			$this->modeFromWire($get(self::KEY_MODE)),
		);
	}

	/**
	 * Read a folder's own Penpot markers as a typed {@see FolderMarkers}.
	 *
	 * Never returns null: a folder with no record, or a record missing both
	 * folder keys, is simply a bare folder ('' / '') — the common case, and the
	 * one {@see MembershipResolver} steps over on its way up the tree. Keeping
	 * this total (rather than nullable) is what lets the resolver's walk stay a
	 * plain loop with no null handling per rung.
	 */
	public function readFolder(int $folderId): FolderMarkers {
		$metadata = $this->readRecord($folderId);
		if ($metadata === null) {
			return new FolderMarkers('', '');
		}
		$get = fn (string $key): string => $metadata->hasKey($key) ? $metadata->getString($key) : '';
		return new FolderMarkers($get(self::KEY_PROJECT_ID), $get(self::KEY_TEAM_ID));
	}

	/**
	 * Drop the entire managed-metadata record for a node. Used when a COPY lands:
	 * a copy is ALWAYS a brand-new instance and must never inherit the original's
	 * `penpot_id` / mode / markers, so its metadata is wiped to a clean slate.
	 * Idempotent — safe on a node that has no record.
	 */
	public function clear(int $fileId): void {
		$this->manager->deleteMetadata($fileId);
	}

	/**
	 * Shared upsert for a fixed subset of keys — the only writer the two public
	 * `write*` methods share. Restricting each to its own key list means a caller
	 * cannot accidentally stamp a folder key onto a file (or vice versa) by
	 * passing the wrong array.
	 *
	 * @param array<string,string> $values
	 * @param list<string> $allowed
	 */
	private function writeKeys(int $fileId, array $values, array $allowed): void {
		// Narrow to the keys this list actually owns FIRST. If nothing survives,
		// return before touching the store — `getMetadata($fileId, true)` creates
		// a record on demand, so calling it for a no-op write would materialise a
		// blank record and turn "untracked == no record" into a lie.
		$updates = array_intersect_key($values, array_flip($allowed));
		if ($updates === []) {
			return;
		}
		$metadata = $this->manager->getMetadata($fileId, true);
		foreach ($updates as $key => $value) {
			$stored = $this->modeToWire($key, $value);
			$metadata->setString($key, $stored, in_array($key, self::INDEXED_KEYS, true));
		}
		$this->manager->saveMetadata($metadata);
	}

	/** Read a node's raw metadata record, or null when it has none. */
	private function readRecord(int $fileId): ?IFilesMetadata {
		try {
			return $this->manager->getMetadata($fileId, false);
		} catch (FilesMetadataNotFoundException) {
			return null;
		}
	}

	/** Canonical → stored: `link` mode is persisted as `reference`. */
	private function modeToWire(string $key, string $value): string {
		return ($key === self::KEY_MODE && $value === Mapping::MODE_LINK) ? self::WIRE_LINK : $value;
	}

	/** Stored → canonical: the stored `reference` mode reads back as `link`. */
	private function modeFromWire(string $value): string {
		return $value === self::WIRE_LINK ? Mapping::MODE_LINK : $value;
	}
}
