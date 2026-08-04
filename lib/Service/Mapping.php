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
 * **No groups** (§C6.35). Which groups the mapped folder is shared with is a
 * property OF THE FOLDER, and Nextcloud already stores it — as groupfolders
 * assignments or as group shares. Copying it here would create a second answer
 * to the same question, and the two would disagree the moment an admin changed
 * the sharing on the folder directly, which they are entitled to do. Groups are
 * therefore read from the folder on demand ({@see StorageService::groupsOf()})
 * and written straight through ({@see MappingService::updateGroups()}).
 *
 * **No folder mode** (§C6.36). `nested`/`keyed` was a designed-but-unbuilt fork
 * that this object carried, the CLI took, the admin card rendered as "(fixed)",
 * and `add()` refused half the values of. One unimplemented value on a field
 * with one implemented value is not a choice, so the field is gone. The design
 * question it stood for is still open in the saga (§6.53, open question #47),
 * which is where an unbuilt design belongs.
 *
 * @see MappingService for storage, uniqueness, and the immutability rule.
 */
final class Mapping implements JsonSerializable {
	/** Files under this mapping are pointers by default (no archive downloaded). */
	public const MODE_LINK = 'link';

	/** Files under this mapping download their real `.penpot` archive. */
	public const MODE_SYNC = 'sync';

	public function __construct(
		public readonly string $id,
		public readonly string $teamId,
		public readonly string $teamName,
		public readonly string $ncFolder,
		public readonly bool $useTeamFolder,
		public readonly string $mode,
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

		// Whether the folder is an ownerless Team Folder (groupfolders) or a plain
		// shared folder. Which GROUPS it is shared with is not here — that lives on
		// the folder itself (§C6.35, see the class docblock).
		//
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

		// Defaults rather than being required: the overwhelmingly common `occ` call
		// names a team and nothing else, and `link` is the conservative choice —
		// it downloads nothing.
		$mode = trim((string)($data['mode'] ?? self::MODE_LINK));

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

		return new self($id, $teamId, $teamName, $ncFolder, $useTeamFolder, $mode);
	}

	/**
	 * The STORED shape — what goes into appconfig, and nothing else.
	 *
	 * Deliberately not the shape the admin page or `list-mappings --json` renders:
	 * those add the folder's current groups, which are read live rather than
	 * stored ({@see MappingService::describe()}).
	 *
	 * @return array{id: string, team_id: string, team_name: string, nc_folder: string, use_team_folder: bool, mode: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'team_id' => $this->teamId,
			'team_name' => $this->teamName,
			'nc_folder' => $this->ncFolder,
			'use_team_folder' => $this->useTeamFolder,
			'mode' => $this->mode,
		];
	}

	/**
	 * @return array{id: string, team_id: string, team_name: string, nc_folder: string, use_team_folder: bool, mode: string}
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
		return new self($this->id, $this->teamId, trim($teamName), $this->ncFolder, $this->useTeamFolder, $this->mode);
	}

	/** A copy with the Nextcloud folder name replaced. */
	public function withNcFolder(string $ncFolder): self {
		return new self($this->id, $this->teamId, $this->teamName, self::normaliseFolder($ncFolder), $this->useTeamFolder, $this->mode);
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
