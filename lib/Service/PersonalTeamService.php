<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * A USER'S OWN HOME IS A MAPPING — the one nobody has to create (§6.45).
 *
 * ## THE RULE
 *
 * A user who has set a personal Penpot token has a Penpot team of their own: the
 * `default-team-id` on their profile, which is where Penpot puts everything they
 * make before they organise it. This app mounts that team at the ONE place that
 * needs no admin decision and belongs to nobody else — the root of their files.
 *
 * From there the ordinary rules apply unchanged, which is the whole point of
 * doing it this way rather than inventing a parallel set:
 *
 *   - the home ROOT is the mapping root, so it is that team's Drafts
 *     (§6.35 — Drafts is a STATE, never a folder);
 *   - a folder in the home is a project when a design is in it, named after its
 *     path below the root, exactly as in an admin-created mapping;
 *   - everything else — moving, renaming, deleting — is the same code.
 *
 * ## WHY THIS IS NOT A ROW IN `MappingService`
 *
 * An admin mapping is a decision someone made and stored: a team id, a folder, a
 * mode, a group list. A personal mapping is none of those. It has no folder to
 * choose (it is the home root), no mode to choose (`sync` — a link mapping is
 * filled from Penpot and a person's own home is theirs to write), no groups, and
 * no lifetime of its own: it exists exactly as long as the token does, and
 * clearing the token ends it without anything needing to be torn down.
 *
 * Storing it would mean keeping those two facts in step forever. Deriving it from
 * the token means they cannot drift.
 *
 * ## WHY THE TEAM ID IS FETCHED AND NOT STORED
 *
 * `get-teams` computes `is-default` as `(t.id = profile.default_team_id)` — read
 * from the backend's own `teams.clj`, not inferred — so asking with the user's
 * token returns their personal team flagged, and asking with anyone else's does
 * not. That makes the token the single source of truth: a token replaced with
 * another account's answers with the new account's team, with nothing to migrate.
 *
 * Cached for the life of the request, because the membership resolver asks on
 * every rung of every walk and the answer cannot change mid-request.
 *
 * ## NEVER THROWS
 *
 * Same contract as {@see PersonalTokenService}, and for a stronger reason: this
 * is consulted from {@see MembershipResolver}, which sits under nearly every
 * feature in the app. A Penpot that is slow or down must degrade to "this user
 * has no personal team" — their home stops being a mapping until it recovers —
 * and must never turn an unrelated file operation into an error.
 */
final class PersonalTeamService {
	/**
	 * Resolved team ids by user, for this request only. `false` is a resolved
	 * negative — distinct from "not asked yet", so a user with no token or an
	 * unreachable Penpot costs one attempt rather than one per rung.
	 *
	 * @var array<string, string|false>
	 */
	private array $cache = [];

	public function __construct(
		private readonly PersonalTokenService $tokens,
		private readonly PenpotClient $client,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The file id of the acting user's home folder, when that home is a mapping.
	 *
	 * Returns null when there is no session (the cron path), when the user has no
	 * personal token, or when their team cannot be resolved — in every one of
	 * those cases the home is an ordinary folder tree and nothing else changes.
	 *
	 * AN ID RATHER THAN A NODE, because the caller is comparing it against the
	 * rung it is standing on in an upward walk, and an id comparison is what that
	 * walk already does to detect the top of the tree.
	 */
	public function rootIdForActor(): ?int {
		if ($this->teamIdForActor() === null) {
			return null;
		}

		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return null;
		}

		try {
			$id = $this->rootFolder->getUserFolder($uid)->getId();
		} catch (\Throwable) {
			return null;
		}

		return $id > 0 ? $id : null;
	}

	/** The acting user's personal Penpot team, or null when they have none. */
	public function teamIdForActor(): ?string {
		$uid = $this->userSession->getUser()?->getUID();

		return $uid !== null ? $this->teamIdFor($uid) : null;
	}

	/** That user's personal Penpot team, resolved through their own token. */
	public function teamIdFor(string $userId): ?string {
		if (array_key_exists($userId, $this->cache)) {
			$hit = $this->cache[$userId];

			return $hit === false ? null : $hit;
		}

		$this->cache[$userId] = false;

		$token = $this->tokens->tokenFor($userId);
		if ($token === null) {
			return null;
		}

		try {
			$teams = $this->client->getTeams($token);
		} catch (PenpotApiException $e) {
			// Degrade, never fail: see the class docblock. The user's home is an
			// ordinary folder tree until Penpot answers again.
			$this->logger->warning('penpot_sync: could not resolve the personal Penpot team for {user}', [
				'user' => $userId,
				'exception' => $e,
				'app' => Application::APP_ID,
			]);

			return null;
		}

		foreach ($teams as $team) {
			$id = $team['id'] ?? null;
			if (!filter_var($team['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
				continue;
			}
			if (is_string($id) && $id !== '') {
				$this->cache[$userId] = $id;

				return $id;
			}
		}

		// A token that works but names no default team. Penpot always gives a
		// profile one, so this is worth a line in the log rather than a shrug.
		$this->logger->warning('penpot_sync: {user} has a personal token but Penpot reported no default team', [
			'user' => $userId,
			'app' => Application::APP_ID,
		]);

		return null;
	}

	/**
	 * Whether this node sits inside the acting user's own home.
	 *
	 * Only used where the answer is needed WITHOUT walking — the resolver already
	 * walks and compares ids as it goes, which is cheaper and does not need a
	 * second path read.
	 */
	public function isInActorsHome(Node $node): bool {
		$rootId = $this->rootIdForActor();
		if ($rootId === null) {
			return false;
		}

		$current = $node;
		for ($depth = 0; $depth < 100; $depth++) {
			if ($current->getId() === $rootId) {
				return true;
			}

			try {
				$parent = $current->getParent();
			} catch (\Throwable) {
				return false;
			}
			if ($parent->getId() === $current->getId()) {
				return false;
			}
			$current = $parent;
		}

		return false;
	}
}
