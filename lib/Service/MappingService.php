<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\IAppConfig;

/**
 * Storage and CRUD for the team-mapping list.
 *
 * Backed by a single AppConfig key holding a JSON array — one round-trip to read
 * the whole list, and `occ config:app:get penpot_sync mappings` shows exactly
 * what is stored. Same shape both sibling apps use, for the same reason: it
 * makes declarative cluster config trivial.
 *
 * ## THE PRECONDITION THIS SERVICE ENFORCES (saga §6.18, locked)
 *
 * A team cannot be mapped unless the **service account can actually see it**.
 * That is not us being strict — it is Penpot's model. §6.12 confirmed there is
 * NO credential with an instance-wide view: `get-teams` is always
 * membership-scoped. So someone with authority over that Penpot team must
 * invite the service account first.
 *
 * Checking up front is the whole point. The alternative is a mapping that looks
 * fine in the admin list and silently pulls nothing, forever — the failure would
 * surface days later as "why is this folder empty?", with nothing in the UI
 * connecting it to a missing invite.
 *
 * ## WHY VALIDATION LIVES HERE AND NOT IN THE CONTROLLER
 *
 * There are two front doors — the settings panel and `occ` — and both must
 * enforce identical rules. Putting the rules in the service means the `occ`
 * twin cannot drift from the UI, which is the house style (CLI-first) and also
 * what the integration suite drives.
 */
final class MappingService {
	/** AppConfig key holding the JSON array of mappings. */
	public const KEY_MAPPINGS = 'mappings';

	/**
	 * Request-scoped memo of the parsed list.
	 *
	 * @var list<Mapping>|null
	 */
	private ?array $cache = null;

	public function __construct(
		private readonly IAppConfig $config,
		private readonly PenpotClient $client,
	) {
	}

	/** @return list<Mapping> */
	public function list(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}

		$decoded = json_decode(
			$this->config->getValueString(Application::APP_ID, self::KEY_MAPPINGS, '[]'),
			true,
		);

		if (!is_array($decoded)) {
			return $this->cache = [];
		}

		$result = [];

		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			try {
				/** @var array<string, mixed> $entry */
				$result[] = Mapping::fromArray($entry);
			} catch (\InvalidArgumentException) {
				// A malformed row is skipped rather than breaking the admin page
				// outright. Same tolerance both siblings apply to their lists.
				continue;
			}
		}

		return $this->cache = $result;
	}

	public function getById(string $id): ?Mapping {
		foreach ($this->list() as $mapping) {
			if ($mapping->id === $id) {
				return $mapping;
			}
		}

		return null;
	}

	public function getByTeamId(string $teamId): ?Mapping {
		foreach ($this->list() as $mapping) {
			if ($mapping->teamId === $teamId) {
				return $mapping;
			}
		}

		return null;
	}

	/**
	 * Add a mapping, after proving the service account can see the team.
	 *
	 * The visibility check is a live `get-teams` call, not a cached list: an
	 * invite may have landed (or been revoked) since the admin page was
	 * rendered, and this is the moment the answer matters.
	 *
	 * @throws \InvalidArgumentException if the team is already mapped, is not
	 *                                   visible to the service account, or the
	 *                                   requested folder mode is not implemented.
	 * @throws PenpotApiException if Penpot cannot be reached or the token is bad.
	 *                            Deliberately NOT swallowed: "could not reach
	 *                            Penpot" and "that team does not exist" are
	 *                            different problems needing different fixes.
	 */
	public function add(Mapping $mapping): Mapping {
		if ($this->getByTeamId($mapping->teamId) !== null) {
			throw new \InvalidArgumentException(
				'That Penpot team is already mapped. A team may only be mapped once.',
			);
		}

		if ($mapping->folderMode === Mapping::FOLDER_MODE_KEYED) {
			// The fork is locked (§6.53) and the field round-trips, but the mode
			// is not implemented. Refusing loudly here is much kinder than
			// accepting the value and behaving as `nested` — a silent lie the
			// admin would only discover from the folder layout.
			throw new \InvalidArgumentException(
				'Folder mode "keyed" is designed but not implemented yet (saga §6.53, open question #47). Use "nested".',
			);
		}

		// Live visibility check — the §6.18 precondition, and the reason this
		// service takes a PenpotClient at all.
		$team = $this->findVisibleTeam($mapping->teamId);

		if ($team === null) {
			throw new \InvalidArgumentException(sprintf(
				'The Penpot team %s is not visible to the service account. '
				. 'Invite the service account to that team in Penpot first, then map it.',
				$mapping->teamId,
			));
		}

		// Trust Penpot's name over whatever the caller passed. The TEAM name is a
		// display cache and the server is authoritative for it (§6.13 point 3).
		$name = $team['name'] ?? null;
		if (is_string($name) && $name !== '') {
			$mapping = $mapping->withTeamName($name);

			// Materialise the folder name now, if the caller left it blank. It
			// could not be defaulted in Mapping::fromArray() because the team
			// name is only known once Penpot has answered — so this is the first
			// moment the default exists. Matches nextcloud-grafana, where a blank
			// nc_folder becomes the Grafana folder's title at create.
			if ($mapping->ncFolder === '') {
				$mapping = $mapping->withNcFolder($name);
			}
		}

		if ($mapping->ncFolder === '') {
			// No name given and none to borrow — refuse rather than create a
			// mapping whose destination is unnamed.
			throw new \InvalidArgumentException(
				'This Penpot team has no name to borrow, so a Nextcloud folder name is required.',
			);
		}

		$this->assertFolderUnique($mapping->ncFolder, null);

		$all = $this->list();
		$all[] = $mapping;
		$this->persist($all);

		return $mapping;
	}

	/**
	 * Update a mapping's mutable fields.
	 *
	 * ## WHAT IS IMMUTABLE, AND WHY EACH ONE IS
	 *
	 * The same principle `nextcloud-grafana` settles on: a field is immutable
	 * when changing it would force a LIVE MIGRATION of already-mirrored content,
	 * which is easier to avoid by re-creating the mapping than to implement
	 * safely behind a dropdown. Delete and re-add makes the cost visible instead
	 * of hiding it.
	 *
	 *   - **the Penpot team** — a mapping IS its team; a different team is a
	 *     different mapping.
	 *   - **the Nextcloud folder** — re-pointing it would have to move the whole
	 *     mirrored tree and re-stamp every file's metadata. (Grafana locks its
	 *     `nc_folder` for exactly this reason.)
	 *   - **the Team Folder flag** — switching backend (ownerless Team Folder ⇄
	 *     admin-owned shared folder) would have to migrate the provisioned folder
	 *     and all its shares. Both siblings lock this.
	 *   - **`folder_mode`** (saga §6.53) — flipping it would restructure every
	 *     folder under the mapping *and* rewrite every project name in Penpot: a
	 *     bulk, two-sided, destructive migration.
	 *   - **`mode`** — link ⇄ sync. Grafana leaves its `mode` editable, and this
	 *     app deliberately does NOT, because the axis means something different
	 *     here (saga §6.22). There, mode decides which way edits flow. Here it
	 *     decides **whether we hold the bytes**: sync→link would delete every
	 *     downloaded `.penpot` archive under the mapping, and link→sync would
	 *     trigger a full export of every file at once. Per-FILE promotion and
	 *     demotion is the supported path (sync-mode.feature) precisely because it
	 *     is the one that can ask before destroying an archive.
	 *
	 * **Editable:** the recorded team name (the pull refreshes it) and the
	 * groups the folder is shared with — the same "everything else stays
	 * editable" line Grafana draws.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function update(string $id, Mapping $mapping): Mapping {
		$all = $this->list();
		$updated = null;

		foreach ($all as $i => $existing) {
			if ($existing->id !== $id) {
				continue;
			}

			if ($existing->teamId !== $mapping->teamId) {
				throw new \InvalidArgumentException(
					'A mapping\'s Penpot team cannot be changed after it is created — remove it and map the other team.',
				);
			}

			if ($existing->folderMode !== $mapping->folderMode) {
				throw new \InvalidArgumentException(
					'A mapping\'s folder mode cannot be changed after it is created — remove it and add a new one.',
				);
			}

			// Blank means "keep what is there" rather than "clear it", so a
			// caller that omits the field is not asking to change it.
			$ncFolder = $mapping->ncFolder !== '' ? $mapping->ncFolder : $existing->ncFolder;

			if ($ncFolder !== $existing->ncFolder) {
				throw new \InvalidArgumentException(
					'A mapping\'s Nextcloud folder cannot be changed after it is created — remove it and add a new one.',
				);
			}

			if ($existing->useTeamFolder !== $mapping->useTeamFolder) {
				throw new \InvalidArgumentException(
					'A mapping\'s Team Folder setting cannot be changed after it is created — remove it and add a new one.',
				);
			}

			if ($existing->mode !== $mapping->mode) {
				throw new \InvalidArgumentException(
					'A mapping\'s default mode cannot be changed after it is created — remove it and add a new one. '
					. 'To change an individual file, promote or demote that file instead.',
				);
			}

			$updated = new Mapping(
				$existing->id,
				$existing->teamId,
				$mapping->teamName !== '' ? $mapping->teamName : $existing->teamName,
				$existing->ncFolder,
				$mapping->ncGroups,
				$existing->useTeamFolder,
				$existing->mode,
				$existing->folderMode,
			);

			$all[$i] = $updated;
			break;
		}

		if ($updated === null) {
			throw new \InvalidArgumentException('No mapping with id ' . $id . '.');
		}

		$this->persist($all);

		return $updated;
	}

	/**
	 * Remove a mapping. Returns false when there was nothing to remove.
	 *
	 * NOTHING IS DELETED FROM PENPOT, EVER, and nothing local is deleted here
	 * either — removing a mapping only stops the pull. What happens to already-
	 * mirrored files is Course 5's decision (remove-mapping.feature), and it is
	 * not this method's to make quietly.
	 */
	public function remove(string $id): bool {
		$all = $this->list();
		$kept = [];
		$found = false;

		foreach ($all as $mapping) {
			if ($mapping->id === $id) {
				$found = true;
				continue;
			}

			$kept[] = $mapping;
		}

		if (!$found) {
			return false;
		}

		$this->persist($kept);

		return true;
	}

	/**
	 * The teams the service account can see, as `[id => record]`.
	 *
	 * Used by the mapping UI to offer a picker, and by {@see add()} to enforce
	 * the precondition. A team already mapped is still returned — the caller
	 * decides what to do about it.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	public function visibleTeams(): array {
		$teams = [];

		foreach ($this->client->getTeams() as $team) {
			$id = $team['id'] ?? null;

			if (is_string($id) && $id !== '') {
				$teams[$id] = $team;
			}
		}

		return $teams;
	}

	/**
	 * @return array<string, mixed>|null
	 *
	 * @throws PenpotApiException
	 */
	private function findVisibleTeam(string $teamId): ?array {
		return $this->visibleTeams()[$teamId] ?? null;
	}

	/**
	 * Two mappings must not target the same Nextcloud folder.
	 *
	 * Without this, two teams would mirror into one folder and their project
	 * subfolders would interleave — with the pull "fixing" the collision on every
	 * run by fighting over the same names. Compared case-insensitively because
	 * Nextcloud folder names are not reliably case-sensitive across storages, so
	 * `Design` and `design` may or may not be the same folder depending on the
	 * backend. Refusing both is the only answer that is right everywhere.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertFolderUnique(string $ncFolder, ?string $exceptId): void {
		foreach ($this->list() as $existing) {
			if ($existing->id === $exceptId) {
				continue;
			}

			if (strcasecmp($existing->ncFolder, $ncFolder) === 0) {
				throw new \InvalidArgumentException(sprintf(
					'The Nextcloud folder "%s" is already used by the mapping for %s. Pick another name.',
					$ncFolder,
					$existing->teamName !== '' ? $existing->teamName : $existing->teamId,
				));
			}
		}
	}

	/** @param list<Mapping> $mappings */
	private function persist(array $mappings): void {
		$this->config->setValueString(
			Application::APP_ID,
			self::KEY_MAPPINGS,
			json_encode(
				array_map(static fn (Mapping $m): array => $m->toArray(), $mappings),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
			),
		);

		$this->cache = $mappings;
	}
}
