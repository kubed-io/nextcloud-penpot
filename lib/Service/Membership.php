<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * Where a node "belongs" in Penpot — the result of {@see MembershipResolver}
 * walking UP the folder tree (saga §6.29, the single most load-bearing rule in
 * the app). Neither sibling has an equivalent: both talk to a flat REST API and
 * derive nothing from folder position. Here, identity lives in ancestor folder
 * METADATA, not in path.
 *
 * The resolver reports the two ids it found on the way up — the nearest project
 * id and the nearest team id — and this object names the STATE that combination
 * describes. It is deliberately a plain record of "what the folders say", not a
 * judgement about the file itself: whether a file is *mirrored* / *unmapped* /
 * *untracked* also depends on the file's own `penpot_id` (see
 * {@see PenpotFileMetadata}), which is a different read and a later course's
 * concern.
 *
 * ## THE FOUR STATES (saga §6.29; observed through the gestures in `designs/`)
 *
 *   IN_PROJECT — a project id AND a team id above the node. The ordinary case:
 *                the file is in that project, in that team.
 *   DRAFTS     — a team id but NO project id (saga §6.35). "In a team, in no
 *                project" — which is precisely Penpot's Drafts. Note Drafts is a
 *                STATE, never a folder: a Team Folder's root and every plain
 *                folder under it all resolve here. Nextcloud is MORE expressive
 *                than Penpot at zero cost — a whole folder tree maps to the one
 *                Drafts bucket.
 *   PERSONAL   — a project id but NO team id (saga §6.31). The ONE valid case of
 *                a project with no team above it: a personal project mounts at
 *                the user's home root, since a personal team gets no folder of
 *                its own. Without this state the natural code would mistake every
 *                personal project for a broken mapping.
 *   NONE       — neither id found. The node is under no Penpot-mapped folder at
 *                all. A `.penpot` file here is *unmapped* (if it carries a
 *                `penpot_id`) or *untracked* (if it does not).
 *
 * `projectId` / `teamId` are `null` when absent (not `''`) — a resolver result
 * is about presence, and null reads more honestly at the call sites than an
 * empty string that could be mistaken for "found, but blank".
 */
final class Membership {
	public const STATE_IN_PROJECT = 'in_project';
	public const STATE_DRAFTS = 'drafts';
	public const STATE_PERSONAL = 'personal';
	public const STATE_NONE = 'none';

	public function __construct(
		public readonly ?string $projectId,
		public readonly ?string $teamId,
	) {
	}

	/** Convenience for the overwhelmingly common "found nothing" result. */
	public static function none(): self {
		return new self(null, null);
	}

	public function state(): string {
		if ($this->teamId !== null) {
			return $this->projectId !== null ? self::STATE_IN_PROJECT : self::STATE_DRAFTS;
		}
		return $this->projectId !== null ? self::STATE_PERSONAL : self::STATE_NONE;
	}

	/** In a concrete project inside a mapped team. */
	public function inProject(): bool {
		return $this->state() === self::STATE_IN_PROJECT;
	}

	/** In a team but no project — Penpot's Drafts (saga §6.35). */
	public function inDrafts(): bool {
		return $this->state() === self::STATE_DRAFTS;
	}

	/** A personal project mounted at the user's home root (saga §6.31). */
	public function isPersonal(): bool {
		return $this->state() === self::STATE_PERSONAL;
	}

	/**
	 * The node resolves to SOME Penpot home — a project (team or personal) or a
	 * team's Drafts. False only for {@see STATE_NONE}, where a `.penpot` file is
	 * unmapped/untracked rather than mirrored.
	 */
	public function belongsToPenpot(): bool {
		return $this->state() !== self::STATE_NONE;
	}
}
