<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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
 * Penpot team names change, and a rename must not orphan a mapping. The name is
 * stored anyway — so the admin UI and `occ` can print something human, and so a
 * rename upstream is visible — but `teamName` is a CACHE and `teamId` is the
 * identity.
 *
 * A team rename in Penpot therefore updates `teamName` and **nothing else**. It
 * does not rename the admin's Nextcloud folder; see the next section for why.
 * (An earlier draft of this docblock said the pull renames the folder to follow.
 * That predates `ncFolder` and is wrong — the two statements contradicted each
 * other, which is exactly the kind of thing a later contributor implements from
 * the wrong half.)
 *
 * ## THE FOLDER NAME IS THE ADMIN'S, THE PROJECT NAMES ARE PENPOT'S
 *
 * This is the one place the two levels behave differently, and the split is
 * deliberate:
 *
 *   - **The team folder** may be called whatever the admin likes. Left blank it
 *     defaults to the Penpot team's name — the same rule `nextcloud-grafana`
 *     uses for `nc_folder` (blank → the Grafana folder's title), so all three
 *     apps behave alike: *the mapping names the destination, and the source name
 *     is merely the default.*
 *   - **Project folders inside it** always match their Penpot project's name
 *     exactly (§6.36), in both directions — a Penpot rename propagates down on
 *     the pull, and renaming a project folder in Nextcloud calls
 *     `rename-project`. There is no per-project naming choice at all.
 *
 * Why they differ: a team folder is a *mount point the admin chose to create*,
 * so naming it is theirs. A project folder is a *mirror of a Penpot object* —
 * letting it drift would break the identity the pull relies on to match folders
 * to projects, and would make a tagged folder named "Acme" no longer mean the
 * project "Acme".
 *
 * The name is materialised AT CREATE rather than resolved on every read (again
 * matching Grafana): the stored mapping always carries a concrete folder name,
 * so the admin list shows what will actually be created, and a later Penpot team
 * rename does not silently move an existing folder.
 *
 * ## WHAT IS NOT ON THIS OBJECT
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
		public readonly string $ncFolder,
		/** @var list<string> */
		public readonly array $ncGroups,
		public readonly bool $useTeamFolder,
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

		// The Nextcloud folder is OPTIONAL: when omitted, materialise it to the
		// Penpot TEAM'S NAME at create and store it. Same rule (and same reason)
		// as nextcloud-grafana's nc_folder ← Grafana folder title: it keeps both
		// fields populated in the saved mapping and the admin list, so it is
		// visible at a glance that they match because the name was left blank —
		// rather than being resolved invisibly on every read.
		$ncFolder = self::normaliseFolder((string)($data['nc_folder'] ?? ''));
		if ($ncFolder === '' && $teamName !== '') {
			// borrowFolderName(), not normaliseFolder(): Penpot permits "/" in a
			// team name and a Nextcloud folder name cannot carry one, so the
			// borrowed default has to be made legal here. Passing it through
			// unchanged would build a mapping the validation below then rejects —
			// on every read, so the row would silently vanish from the list.
			$ncFolder = self::borrowFolderName($teamName);
		}

		// Which Nextcloud groups the mapped folder is shared with, and whether it
		// is an ownerless Team Folder (groupfolders) or a plain shared folder.
		$ncGroups = self::normaliseGroups($data['nc_groups'] ?? []);

		// DEFAULT FALSE, BECAUSE A DEFAULT HAS TO WORK EVERYWHERE.
		//
		// groupfolders is an OPTIONAL app. Defaulting to it meant the default
		// mapping — the one an admin gets by naming a team and nothing else —
		// asked for a backend that is simply absent on a stock Nextcloud, and
		// StorageService then refuses to provision it. A default that fails on an
		// unconfigured instance is not a default.
		//
		// The plain shared folder is core, always present, and carries the same
		// folder metadata a Team Folder would (§6.21); the Team Folder is the
		// upgrade an admin opts into once groupfolders is installed. This
		// deliberately diverges from the siblings' "prefer groupfolders" wording,
		// which says what to use when it IS available — not what to assume when
		// nobody said anything.
		$useTeamFolder = array_key_exists('use_team_folder', $data)
			&& filter_var($data['use_team_folder'], FILTER_VALIDATE_BOOLEAN);

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
		if (str_contains($ncFolder, '/')) {
			// The team folder is one mount point. Everything under it is created
			// by the pull from Penpot's project names, so an inner "/" would
			// invent an intermediate folder that no Penpot object corresponds to
			// — and that nothing would ever clean up.
			throw new \InvalidArgumentException(
				'nc_folder must be a single folder name, not a path — got "' . $ncFolder . '"',
			);
		}
		if (!in_array($mode, [self::MODE_LINK, self::MODE_SYNC], true)) {
			throw new \InvalidArgumentException('mode must be "link" or "sync"');
		}
		if (!in_array($folderMode, [self::FOLDER_MODE_NESTED, self::FOLDER_MODE_KEYED], true)) {
			throw new \InvalidArgumentException('folder_mode must be "nested" or "keyed"');
		}

		return new self($id, $teamId, $teamName, $ncFolder, $ncGroups, $useTeamFolder, $mode, $folderMode);
	}

	/**
	 * @return array{id: string, team_id: string, team_name: string, nc_folder: string, nc_groups: list<string>, use_team_folder: bool, mode: string, folder_mode: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'team_id' => $this->teamId,
			'team_name' => $this->teamName,
			'nc_folder' => $this->ncFolder,
			'nc_groups' => $this->ncGroups,
			'use_team_folder' => $this->useTeamFolder,
			'mode' => $this->mode,
			'folder_mode' => $this->folderMode,
		];
	}

	/**
	 * @return array{id: string, team_id: string, team_name: string, nc_folder: string, nc_groups: list<string>, use_team_folder: bool, mode: string, folder_mode: string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * A copy with the Penpot team name refreshed — how the pull follows a rename
	 * upstream.
	 *
	 * NOTE this deliberately does NOT touch `ncFolder`. A Penpot team rename
	 * updates the recorded team name (so the admin page shows the truth), but it
	 * must not silently rename the admin's Nextcloud folder — the folder name is
	 * the admin's choice, and the team name was only ever its default. Renaming
	 * the mapped folder on the pull is a separate, explicit decision that belongs
	 * to Course 3, not a side effect of this setter.
	 */
	public function withTeamName(string $teamName): self {
		return new self($this->id, $this->teamId, trim($teamName), $this->ncFolder, $this->ncGroups, $this->useTeamFolder, $this->mode, $this->folderMode);
	}

	/**
	 * A copy with the shared groups replaced — the only field a mapping may change
	 * after it is created ({@see MappingService::updateGroups()}).
	 *
	 * @param list<string>|string $ncGroups
	 */
	public function withNcGroups(array|string $ncGroups): self {
		return new self($this->id, $this->teamId, $this->teamName, $this->ncFolder, self::normaliseGroups($ncGroups), $this->useTeamFolder, $this->mode, $this->folderMode);
	}

	/** A copy with the Nextcloud folder name replaced. */
	public function withNcFolder(string $ncFolder): self {
		return new self($this->id, $this->teamId, $this->teamName, self::normaliseFolder($ncFolder), $this->ncGroups, $this->useTeamFolder, $this->mode, $this->folderMode);
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

	/**
	 * Normalise a Nextcloud folder name: trimmed, no surrounding or duplicated
	 * slashes.
	 *
	 * `nextcloud-grafana`'s equivalent permits an inner `/` because a Grafana
	 * mapping targets an arbitrary path. Ours is a single mount point — the team
	 * folder — and everything below it is created by the pull from Penpot's own
	 * project names, so an inner `/` here would silently invent an intermediate
	 * folder that nothing owns. It is rejected in {@see fromArray()} instead.
	 */
	private static function normaliseFolder(string $value): string {
		$v = trim($value);
		$v = preg_replace('#/+#', '/', $v) ?? $v;

		return trim($v, '/');
	}

	/**
	 * Group ids: non-empty trimmed strings, de-duplicated, re-indexed. Tolerates
	 * a comma-separated string from a form field.
	 *
	 * Identical to both siblings' normaliser, so the three mapping models reduce
	 * cleanly into a shared base later.
	 *
	 * @return list<string>
	 */
	private static function normaliseGroups(mixed $value): array {
		if (is_string($value)) {
			$value = $value === '' ? [] : explode(',', $value);
		}

		if (!is_array($value)) {
			return [];
		}

		$out = [];

		foreach ($value as $g) {
			$g = trim((string)$g);

			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}

		return $out;
	}

	/**
	 * Turn a Penpot team name into a legal Nextcloud folder name.
	 *
	 * Only used when defaulting — an EXPLICIT folder name containing "/" is still
	 * rejected, because that is a mistake worth telling the admin about. A
	 * borrowed one is not their mistake, so it is made to work instead.
	 */
	public static function borrowFolderName(string $teamName): string {
		return self::normaliseFolder(str_replace('/', '-', $teamName));
	}

	/** Stable local id for the mapping — unrelated to any Penpot id. */
	private static function newId(): string {
		return bin2hex(random_bytes(8));
	}
}
