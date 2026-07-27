<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * Typed view of a mirrored `.penpot` file's Files-Metadata — the `penpot_*` keys
 * {@see PenpotMetadata} stores, read back as a value object instead of an
 * `array<string,mixed>` poked at with `?? null` + `is_string()`. The master's
 * {@see \OCA\N8nSync\Service\WorkflowMetadata} view / the apprentice's
 * {@see \OCA\GrafanaSync\Service\ManagedFile}, re-cut for our ingredient.
 *
 * Every field is normalised to a plain string: a key that was never stamped
 * reads back as `''` (not null), so callers compare against `''` or use the
 * `is*()` helpers and never juggle null. A file with no metadata record at all
 * is represented by {@see PenpotMetadata::readFile()} returning `null`, not by a
 * view with empty fields.
 *
 * ## THE KEY SET IS DELIBERATELY SMALLER THAN THE SIBLINGS' (saga §6.22, file-type.feature)
 *
 *   penpot_id       — the Penpot file id (the master's `n8n_id` / apprentice's
 *                     `grafana_uid`). The stable thread; survives renames and
 *                     moves because it is keyed on Penpot's own id, not a name.
 *   penpot_revision — Penpot's `revn` + `modifiedAt` pair (saga §5.5), the drift
 *                     signal a pull diffs against to skip unchanged files.
 *   penpot_mode     — "sync" or "link" or "unmapped".
 *
 * **There is no `syncedHash` and no `mapping` key.** Both siblings carry a
 * body-hash to guard a *writeback loop*; this app never pushes content (§6.1),
 * so `penpot_revision` is a read-side "is my copy stale" check, not a loop
 * guard. And membership is DERIVED by walking up the folder tree
 * ({@see MembershipResolver}), never stored on the file — a stored copy would
 * have to be rewritten on every move, which is exactly the drift a stored
 * mapping key caused in an earlier design.
 *
 * The mode is in the **canonical** vocabulary (`sync` / `link` / `unmapped`) —
 * the stored `reference` wire value is already translated back to `link` by
 * {@see PenpotMetadata::readFile()} before it reaches here.
 */
final class PenpotFileMetadata {
	public function __construct(
		public readonly string $penpotId,
		public readonly string $revision,
		public readonly string $mode,
	) {
	}

	/** True when the file carries a Penpot file id — i.e. it is one of ours. */
	public function isManaged(): bool {
		return $this->penpotId !== '';
	}

	/** Sync mode: the real `.penpot` archive is held locally. */
	public function isSync(): bool {
		return $this->mode === Mapping::MODE_SYNC;
	}

	/** Link mode: a pointer only, no archive content stored. */
	public function isLink(): bool {
		return $this->mode === Mapping::MODE_LINK;
	}

	/**
	 * Unmapped: carries a `penpot_id` but currently resolves to no Penpot
	 * ancestor at all (moved out of every mapped folder). Distinct from
	 * *untracked*, which is a file with no `penpot_id` — that state is the
	 * absence of a record, i.e. {@see PenpotMetadata::readFile()} returning null.
	 */
	public function isUnmapped(): bool {
		return $this->mode === PenpotMetadata::MODE_UNMAPPED;
	}
}
