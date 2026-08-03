<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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
		private readonly StorageService $storage,
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
			// borrowFolderName(), not the raw name: Penpot permits "/" in a team
			// name and a Nextcloud folder name cannot carry one. Storing it raw
			// would persist an nc_folder that Mapping::fromArray() then REJECTS on
			// every later read — so the row would vanish from the list, and from
			// `list-mappings`, with nothing saying why.
			if ($mapping->ncFolder === '') {
				$mapping = $mapping->withNcFolder(Mapping::borrowFolderName($name));
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

		// THE FOLDER IS WHAT A MAPPING IS. Refuse before persisting if the chosen
		// backend cannot be built — a mapping whose destination can never exist is
		// a row that does nothing but produce failures on every later pull. The
		// common case is `--team-folder` on an instance without groupfolders.
		if (!$this->storage->isAvailable($mapping)) {
			throw new \InvalidArgumentException(
				'This mapping asks for a Team Folder, but the groupfolders app is not '
				. 'installed or not available. Install it, or leave the Team Folder '
				. 'option off to use a plain shared folder.',
			);
		}

		// PROVISION NOW, NOT ON THE FIRST SYNC. A mapping IS a folder — the admin
		// pressed save and expects to see it, not to wait for a schedule they did
		// not set. It used to appear only when PullService called ensureRoot on its
		// first pass, which made a fresh mapping look broken for up to an hour.
		//
		// ensureRoot() is IDEMPOTENT and PullService still calls it every run, so
		// the sync stays the safety net: a folder deleted by hand comes back on the
		// next pass rather than staying gone. Same function, two callers, one for
		// promptness and one for repair.
		//
		// BEFORE PERSISTING, AND THE ORDER IS THE POINT. If provisioning throws —
		// no sync actor, the name already taken by a FILE — a saved row would
		// outlive the failure and claim a folder that does not exist, which is
		// exactly the invariant this call was added to establish. Failing here
		// leaves nothing behind. The reverse order can leave a folder with no
		// mapping if the write fails, and a folder with no mapping is just a
		// folder: re-adding finds it and ensureRoot is idempotent.
		$this->storage->ensureRoot($mapping);

		$all = $this->list();
		$all[] = $mapping;
		$this->persist($all);

		return $mapping;
	}

	/**
	 * Change the groups a mapped folder is shared with. The only edit there is.
	 *
	 * ## IMMUTABILITY IS THE SIGNATURE, NOT A GUARD
	 *
	 * This used to be `update(string $id, Mapping $mapping)` — a whole mapping in,
	 * and five checks refusing every field that must not move. Those checks were
	 * unreachable: the one caller is {@see MappingController::update()}, which
	 * accepts `ncGroups` and rebuilds every other field FROM STORAGE, so it could
	 * not have tripped one if it tried. Defensive code guarding a door with no
	 * handle.
	 *
	 * Taking an array of groups says the same thing better: there is no way to
	 * express a change to anything else, so nothing has to refuse one. A field is
	 * immutable when changing it would force a LIVE MIGRATION of already-mirrored
	 * content, which is the same principle nextcloud-grafana settles on —
	 * re-creating the mapping makes that cost visible instead of hiding it behind
	 * a dropdown:
	 *
	 *   - **the Penpot team** — a mapping IS its team; a different team is a
	 *     different mapping.
	 *   - **the Nextcloud folder** — re-pointing it would move the whole mirrored
	 *     tree and re-stamp every file's metadata.
	 *   - **the Team Folder flag** — switching backend would migrate the
	 *     provisioned folder and all of its shares.
	 *   - **`folder_mode`** (§6.53) — flipping it would restructure every folder
	 *     under the mapping *and* rewrite every project name in Penpot.
	 *   - **`mode`** — link ⇄ sync decides whether we HOLD THE BYTES (§6.22), not
	 *     which way edits flow as it does in Grafana. sync→link would delete every
	 *     downloaded archive under the mapping; link→sync would export every file
	 *     at once. Per-FILE promotion is the supported path (sync-mode.feature)
	 *     precisely because it can ask before destroying an archive.
	 *
	 * Re-sharing a folder is the one change that moves no content, which is why it
	 * is the one that stayed.
	 *
	 * Takes whatever the caller has — a list, a comma-separated form field, or an
	 * array of unknown shape off a request. {@see Mapping::withNcGroups()} runs it
	 * through the same normaliser every other entry point uses, so there is one
	 * definition of what a group list is and coercing at the boundary would only
	 * add a second.
	 *
	 * @param array<array-key, mixed>|string $ncGroups
	 *
	 * @throws \InvalidArgumentException
	 */
	public function updateGroups(string $id, array|string $ncGroups): Mapping {
		$all = $this->list();
		$updated = null;

		foreach ($all as $i => $existing) {
			if ($existing->id !== $id) {
				continue;
			}

			$updated = $existing->withNcGroups($ncGroups);
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
