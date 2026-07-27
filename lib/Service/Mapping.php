<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use JsonSerializable;

/**
 * A team mapping — the whole configurable object in this app.
 *
 * ## A MAPPING IS A TEAM. THAT IS ALL (saga §6.24, refining §6.13)
 *
 * Penpot's hierarchy is a hard, structural three levels — team `contains`
 * project `contains` file (§6.5). But only the TOP level is a mapping:
 *
 *   - a Penpot **team** maps to a Nextcloud Team Folder (or a plain shared
 *     folder when groupfolders is absent — Course 3's concern, not this
 *     object's);
 *   - Penpot **projects** are NOT mapped. They are *mirrored* — the pull
 *     creates a folder per project. There is no project mapping to add,
 *     configure, or remove.
 *
 * An earlier draft had admins mapping projects individually. It could never
 * work: the next pull would immediately recreate any subfolder you removed.
 * One mapping object, one lifecycle.
 *
 * ## KEYED ON THE TEAM ID, NEVER THE NAME
 *
 * Penpot team names change, and a rename must not orphan a mapping — the pull
 * renames the Team Folder to follow (admin-mapping.feature). The name is stored
 * anyway, but only so the admin UI and `occ` can print something human before
 * the first pull has run. `teamName` is a cache, `teamId` is the identity.
 *
 * ## WHAT IS NOT ON THIS OBJECT, AND WHY
 *
 * **No folder name.** The Team Folder is named after the Penpot team, always
 * (§6.13 point 3) — there is deliberately no "call it something else" field.
 * Two Nextcloud instances mapping the same Penpot team stay recognisably in
 * sync by name, not just by hidden id.
 *
 * **No per-project anything.** See above.
 *
 * @see MappingService for storage, uniqueness, and the immutability rule.
 */
final class Mapping implements JsonSerializable {
	/** Files under this mapping are pointers by default (no archive downloaded). */
	public const MODE_LINK = 'link';

	/** Files under this mapping download their real `.penpot` archive. */
	public const MODE_SYNC = 'sync';

	/**
	 * Penpot projects are flat names; Nextcloud may nest them freely under the
	 * Team Folder (saga §6.29). A `/` in a project name is INVALID here, because
	 * it would mean nothing.
	 */
	public const FOLDER_MODE_NESTED = 'nested';

	/**
	 * A project's name IS its path relative to the Team Folder ("foo/bar" →
	 * `Team/foo/bar/`).
	 *
	 * DESIGNED, NOT BUILT (saga §6.53). The *fork* is locked and this constant
	 * exists so stored values round-trip, but the mode has no implementation and
	 * no feature file. Three real questions block it (open question #47): how
	 * inferred intermediate folders are told apart from user folders, what a
	 * move out of the team means when position *is* the name, and whether a
	 * `foo/bar` key collision is refused or disambiguated.
	 *
	 * {@see MappingService::add()} refuses it for exactly that reason. Do not
	 * "finish" it without resolving #47 first — that is a saga decision.
	 */
	public const FOLDER_MODE_KEYED = 'keyed';

	public function __construct(
		public readonly string $id,
		public readonly string $teamId,
		public readonly string $teamName,
		public readonly string $mode,
		public readonly string $folderMode,
	) {
	}

	/**
	 * Validate and normalise a raw array — from `occ`, from the settings
	 * controller, or from stored JSON — into a Mapping.
	 *
	 * Throws {@see \InvalidArgumentException} on any invariant violation, so a
	 * bad input becomes a clean 400 / non-zero exit instead of a persisted mess.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		$id = isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
			? $data['id']
			: self::newId();

		$teamId = trim((string)($data['team_id'] ?? ''));
		$teamName = trim((string)($data['team_name'] ?? ''));

		// Both default rather than being required: the overwhelmingly common
		// `occ` call names a team and nothing else, and every default here is
		// the conservative one (link downloads nothing, nested is the only
		// implemented folder model).
		$mode = trim((string)($data['mode'] ?? self::MODE_LINK));
		$folderMode = trim((string)($data['folder_mode'] ?? self::FOLDER_MODE_NESTED));

		if ($teamId === '') {
			throw new \InvalidArgumentException('team_id is required');
		}
		if (!self::isUuid($teamId)) {
			// Penpot ids are always UUIDs. Catching this here turns a typo into
			// a clear message instead of a puzzling 404 from Penpot later.
			throw new \InvalidArgumentException('team_id must be a UUID, got "' . $teamId . '"');
		}
		if (!in_array($mode, [self::MODE_LINK, self::MODE_SYNC], true)) {
			throw new \InvalidArgumentException('mode must be "link" or "sync"');
		}
		if (!in_array($folderMode, [self::FOLDER_MODE_NESTED, self::FOLDER_MODE_KEYED], true)) {
			throw new \InvalidArgumentException('folder_mode must be "nested" or "keyed"');
		}

		return new self($id, $teamId, $teamName, $mode, $folderMode);
	}

	/**
	 * @return array{id: string, team_id: string, team_name: string, mode: string, folder_mode: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'team_id' => $this->teamId,
			'team_name' => $this->teamName,
			'mode' => $this->mode,
			'folder_mode' => $this->folderMode,
		];
	}

	/**
	 * @return array{id: string, team_id: string, team_name: string, mode: string, folder_mode: string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** A copy with the team name refreshed — the pull's way of following a rename. */
	public function withTeamName(string $teamName): self {
		return new self($this->id, $this->teamId, trim($teamName), $this->mode, $this->folderMode);
	}

	/**
	 * Penpot ids are UUIDs; anything else is a typo, not a team.
	 *
	 * Deliberately shape-only, not version-checked — Penpot's own ids do not all
	 * carry a conventional version nibble (the live instance issues ids like
	 * `3fc1681a-2199-8124-8008-…`), so a strict RFC-4122 test would reject real
	 * teams. Confirmed against live data before being written this way.
	 */
	private static function isUuid(string $value): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
	}

	/** Stable local id for the mapping — unrelated to any Penpot id. */
	private static function newId(): string {
		return bin2hex(random_bytes(8));
	}
}
