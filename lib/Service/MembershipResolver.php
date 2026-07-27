<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCP\Files\NotFoundException;
use OCP\Files\Node;

/**
 * Resolves where a node "belongs" in Penpot by walking UP the folder tree,
 * reading folder metadata (saga §6.29 — *the single most load-bearing rule in
 * the app*). Almost every other feature defers to this.
 *
 * ## THE RULE (mapping-membership.feature, locked)
 *
 *   A `.penpot` file belongs to the Penpot PROJECT recorded on the NEAREST
 *   ANCESTOR folder carrying a project id. A project folder belongs to the TEAM
 *   recorded on the NEAREST ANCESTOR folder carrying a team id. No such ancestor
 *   ⇒ no mapping.
 *
 * "Nearest ancestor" is a walk at ANY depth, not a fixed one-level check. This
 * is what lets Nextcloud nest freely while Penpot stays flat: a project folder
 * works identically at any depth, and a user can group project folders under an
 * ordinary "Clients/" folder that has no Penpot counterpart at all — "walk up
 * until you find the key" is the same lookup as "check one level up", minus the
 * early exit. It replaces the withdrawn "exactly one level, hard cap" rule.
 *
 * ## WHY THE WALK INCLUDES THE NODE ITSELF
 *
 * The walk reads folder markers at every rung *including the starting node*, so
 * one method serves both call sites:
 *
 *   - a FILE never carries folder keys (files carry `penpot_id`, folders carry
 *     `penpot_project_id` / `penpot_team_id` — different keys), so reading its
 *     own markers returns bare and the result is driven entirely by its
 *     ancestors: "nearest ancestor", exactly as specced;
 *   - a project FOLDER resolved for its team correctly counts its own project id
 *     and finds the team above it — the "a project folder's team is the nearest
 *     ancestor carrying a team id" scenario.
 *
 * ## MEMBERSHIP IS DERIVED, NEVER STORED ON THE FILE
 *
 * There is no `penpot_mapping` key. The folders already know which project and
 * team they are; a copy stored on each file would have to be rewritten on every
 * move — exactly the drift an earlier stored-mapping design tangled itself in.
 * The one cost is this upward walk, and it is cheap: it stops the instant BOTH
 * ids are found, and terminates at the storage root.
 *
 * @see Membership for the four states this produces.
 */
final class MembershipResolver {
	/**
	 * A defensive ceiling on the upward walk. A Nextcloud tree is finite and the
	 * walk already terminates at the root, so this only guards against a
	 * pathological cycle (which a tree cannot produce) — it is a seatbelt, not a
	 * real limit. No legitimate folder nesting approaches it.
	 */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly PenpotMetadata $metadata,
	) {
	}

	/**
	 * Resolve a node's Penpot membership.
	 *
	 * Walks from the node up through its ancestors, keeping the FIRST (nearest)
	 * project id and the first team id it sees, and stops as soon as it has both
	 * — or when it runs past the storage root, whichever comes first. The result
	 * is a plain record of what the folders say; deriving the file's own
	 * *mirrored / unmapped / untracked* status additionally needs the file's
	 * `penpot_id` (see {@see PenpotFileMetadata}) and is a later course's job.
	 */
	public function resolve(Node $node): Membership {
		$projectId = null;
		$teamId = null;

		$current = $node;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			$id = $current->getId();
			// A node with no persisted id (id <= 0) carries no metadata record,
			// so there is nothing to read on this rung — but its ancestors may
			// still be marked, so keep climbing rather than bailing.
			if ($id > 0) {
				$markers = $this->metadata->readFolder($id);
				if ($projectId === null && $markers->hasProject()) {
					$projectId = $markers->projectId;
				}
				if ($teamId === null && $markers->hasTeam()) {
					$teamId = $markers->teamId;
				}
				if ($projectId !== null && $teamId !== null) {
					// Both found: nothing above can be nearer, so stop.
					break;
				}
			}

			try {
				$parent = $current->getParent();
			} catch (NotFoundException) {
				// Walked past the storage root — there is no higher folder.
				break;
			}

			// getParent() returns the same node (or a rootless placeholder) at
			// the very top of the tree; either way there is nowhere left to go.
			if ($parent->getId() === $id) {
				break;
			}

			$current = $parent;
		}

		return new Membership($projectId, $teamId);
	}
}
