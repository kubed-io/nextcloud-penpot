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

		// Trust Penpot's name over whatever the caller passed. The name is a
		// display cache and the server is authoritative for it (§6.13 point 3).
		$name = $team['name'] ?? null;
		if (is_string($name) && $name !== '') {
			$mapping = $mapping->withTeamName($name);
		}

		$all = $this->list();
		$all[] = $mapping;
		$this->persist($all);

		return $mapping;
	}

	/**
	 * Update a mapping's mutable fields.
	 *
	 * `folder_mode` is IMMUTABLE (saga §6.53). Flipping it live would restructure
	 * every folder under the mapping *and* rewrite every project name in Penpot —
	 * a bulk, two-sided, destructive migration hiding behind a dropdown. The
	 * admin must remove and re-add instead, which makes the cost visible. Same
	 * immutability precedent both sibling apps set for their structural fields.
	 *
	 * The team id is immutable for a simpler reason: a mapping IS its team. A
	 * different team is a different mapping.
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

			if ($existing->folderMode !== $mapping->folderMode) {
				throw new \InvalidArgumentException(
					'A mapping\'s folder mode cannot be changed after it is created. '
					. 'Remove the mapping and add it again with the mode you want.',
				);
			}

			if ($existing->teamId !== $mapping->teamId) {
				throw new \InvalidArgumentException(
					'A mapping\'s Penpot team cannot be changed. Remove it and map the other team.',
				);
			}

			$updated = new Mapping(
				$existing->id,
				$existing->teamId,
				$mapping->teamName !== '' ? $mapping->teamName : $existing->teamName,
				$mapping->mode,
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
