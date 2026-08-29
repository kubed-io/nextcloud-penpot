<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\ExistingDesignsException;
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
		private readonly ExistingDesigns $existing,
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
	 * `$groups` is not part of the mapping — it is what to share the provisioned
	 * folder with, handed straight to storage (§C6.35). It is a parameter rather
	 * than a field for the same reason it is not persisted: the folder is the
	 * record, and this is simply the admin saying what it should start as.
	 *
	 * @param array<array-key, mixed>|string $groups
	 *
	 * @throws \InvalidArgumentException if the team is already mapped or is not
	 *                                   visible to the service account.
	 * @throws PenpotApiException if Penpot cannot be reached or the token is bad.
	 *                            Deliberately NOT swallowed: "could not reach
	 *                            Penpot" and "that team does not exist" are
	 *                            different problems needing different fixes.
	 */
	public function add(Mapping $mapping, array|string $groups = [], bool $purgeDesigns = false): Mapping {
		if ($this->getByTeamId($mapping->teamId) !== null) {
			throw new \InvalidArgumentException(
				'The team is already mapped to another folder. A team may only be mapped once.',
			);
		}

		// Live visibility check — the §6.18 precondition, and the reason this
		// service takes a PenpotClient at all.
		$team = $this->findVisibleTeam($mapping->teamId);

		if ($team === null) {
			// ONE MESSAGE FOR TWO CAUSES, BECAUSE THERE IS NO THIRD ANSWER TO GIVE.
			// `get-teams` is membership-scoped (§6.12), so a team that does not exist
			// and a team the service account was never invited to come back
			// identically: the lookup returns nothing. This used to say "is not
			// visible to the service account", which named only the second and sent
			// an admin looking for an invite to a team that was never there.
			throw new \InvalidArgumentException(sprintf(
				'The team was not found using the given credentials (%s). Either it does not '
				. 'exist, or the service account has not been invited to it — check in Penpot, '
				. 'then map it.',
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

		// A LINK MAPPING MAY NOT BE MADE OVER DESIGNS THAT ALREADY EXIST, and the
		// count is read here — before anything is provisioned — so a refusal costs
		// nothing and the number the admin is shown is the number that would go.
		//
		// AFTER `assertFolderUnique()`, WHICH IS WHY THIS ONLY EVER SEES UNMAPPED
		// FILES. A folder already in use is refused one line up, and a mapping may
		// not be made under or over another, so a tree that belongs to some other
		// mapping never reaches this check. "No `.penpot` anywhere in the tree"
		// holds implicitly for every mapped tree without being asked.
		$designs = $mapping->mode === Mapping::MODE_LINK ? $this->existing->under($mapping) : [];

		if ($designs !== [] && !$purgeDesigns) {
			// THE FOLDER NAME AS THE APP RESOLVED IT, not as the admin typed it —
			// they may have typed nothing at all and taken the team's name as the
			// default, which is settled a few lines above this. The panel puts this
			// in the confirmation, and `"" already holds 3 designs` is a poor sentence
			// to read before destroying something.
			throw new ExistingDesignsException(sprintf(
				'"%s" already holds %d design%s. A link mapping holds pointers rather than '
				. 'designs, so they would be permanently deleted — not moved to the trash, and '
				. 'not recoverable. Move them elsewhere first, or confirm the deletion.',
				$mapping->ncFolder,
				count($designs),
				count($designs) === 1 ? '' : 's',
			), count($designs), $mapping->ncFolder);
		}

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
		//
		// The one call that passes GROUPS along with the mapping, together with
		// updateGroups() below. Every other caller — every pull — passes none, so
		// a sync never touches sharing (§C6.35).
		$this->storage->ensureRoot($mapping, $groups);

		$all = $this->list();
		$all[] = $mapping;
		$this->persist($all);

		// THE PURGE IS LAST, AND AFTER THE WRITE ON PURPOSE. It is the one
		// irreversible thing this app does, so it must not happen for a mapping that
		// then fails to save — the admin would be left having destroyed designs to
		// make room for nothing. `persist()` can throw (it encodes and writes app
		// config); everything after this point cannot.
		//
		// The reverse order fails worse in the only other direction available: a
		// saved link mapping over surviving archives is the contradiction this whole
		// rule exists to prevent, and it would be created deliberately.
		if ($designs !== []) {
			$this->existing->purge($designs);
		}

		return $mapping;
	}

	/**
	 * Change the groups a mapped folder is shared with. The only edit there is.
	 *
	 * ## IMMUTABILITY IS THE SIGNATURE, NOT A GUARD
	 *
	 * Taking `update(string $id, Mapping $mapping)` — a whole mapping in, and five
	 * checks refusing every field that must not move — makes those checks
	 * unreachable. Both callers ({@see MappingController::update()} and
	 * {@see \OCA\PenpotSync\Command\SetGroups}) can only supply groups, and the
	 * controller rebuilds every other field FROM STORAGE, so neither could trip one
	 * if it tried. Defensive code guarding a door with no handle.
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
	 *   - **`mode`** — link ⇄ sync decides whether we HOLD THE BYTES (§6.22), not
	 *     which way edits flow. sync→link would delete every downloaded archive
	 *     under the mapping; link→sync would export every file at once. Mode is a
	 *     property of the mapping, so remapping the team is how it changes.
	 *
	 * Re-sharing a folder is the one change that moves no content, which is why it
	 * is the one that stayed.
	 *
	 * ## AND IT WRITES TO THE FOLDER, NOT TO THE MAPPING (§C6.35)
	 *
	 * Nothing here is persisted, because the mapping does not hold groups: this
	 * re-shares the provisioned folder and returns what the folder then reports.
	 * It used to save a list to appconfig and leave the actual re-share to the
	 * next pull, so "the groups changed" and "the folder is shared with them" were
	 * two events an hour apart, and an admin who fixed the sharing by hand in the
	 * meantime had it reverted.
	 *
	 * Reading the result back rather than echoing the input is the honest answer
	 * and occasionally a different one — a group that does not exist cannot be
	 * shared with, and the caller should see that it is not in the list.
	 *
	 * Takes whatever the caller has: a list, a comma-separated form field, or an
	 * array of unknown shape off a request. {@see StorageService::normaliseGroups()}
	 * is the one definition of what a group list is, so coercing at the boundary
	 * would only add a second.
	 *
	 * @param array<array-key, mixed>|string $ncGroups
	 *
	 * @return list<string> the groups the folder is shared with afterwards
	 *
	 * @throws \InvalidArgumentException when there is no such mapping
	 * @throws \RuntimeException when the folder cannot be reached to re-share it
	 */
	public function updateGroups(string $id, array|string $ncGroups): array {
		$mapping = $this->getById($id);

		if ($mapping === null) {
			throw new \InvalidArgumentException('No mapping with id ' . $id . '.');
		}

		$this->storage->ensureRoot($mapping, $ncGroups);

		return $this->storage->groupsOf($mapping);
	}

	/**
	 * The groups a mapping's folder is shared with. Read from the folder, every
	 * time, because the folder is the only record (§C6.35).
	 *
	 * @return list<string>
	 */
	public function groupsOf(Mapping $mapping): array {
		return $this->storage->groupsOf($mapping);
	}

	/**
	 * A mapping as the admin page, `list-mappings --json` and the REST endpoints
	 * render it: everything stored, plus the folder's current groups.
	 *
	 * Separate from {@see Mapping::toArray()} on purpose — that is the STORED
	 * shape and must stay free of anything read live, or the next person to call
	 * it in `persist()` would write a cached copy of the groups back into
	 * appconfig and undo the whole point.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(Mapping $mapping): array {
		return $mapping->toArray() + ['nc_groups' => $this->groupsOf($mapping)];
	}

	/**
	 * Remove a mapping. Returns false when there was nothing to remove.
	 *
	 * NOTHING IS DELETED FROM PENPOT, EVER, and nothing local is deleted here
	 * either — removing a mapping only stops the pull. What happens to already-
	 * mirrored files is Course 5's decision (mapping/delete.feature), and it is
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
				// THE PLACEHOLDER IS THE TEAM, and it was the folder for one commit:
				// the message used to open with `The Nextcloud folder "%s"` and so
				// carried two arguments. Rewording it to say which SIDE the clash is
				// on left one placeholder and two arguments, and sprintf silently
				// filled it with the first — announcing the folder's own name as the
				// team that holds it.
				throw new \InvalidArgumentException(sprintf(
					'The folder is already mapped to another team (%s). Pick another name.',
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
