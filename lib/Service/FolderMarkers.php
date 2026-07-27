<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * The Penpot markers carried by a single FOLDER (saga §6.21, §6.32).
 *
 * Confirmed live: Files-Metadata attaches to folders exactly as it does to
 * files — same Node type, same fileid space — tested write/persist/read-back
 * against a REAL production Team Folder (§6.21). So a project folder and a Team
 * Folder each carry one authoritative machine marker:
 *
 *   penpot_project_id — on a project folder.
 *   penpot_team_id    — on a Team Folder.
 *
 * A folder may carry either, both (unusual but not illegal), or neither (an
 * ordinary Nextcloud folder — the overwhelmingly common case). Both fields
 * normalise to `''` when absent, so {@see MembershipResolver} tests them with a
 * plain `!== ''` and never juggles null.
 *
 * This is ONLY the folder's own markers — not resolved membership. Resolution
 * (walking up to the nearest ancestor carrying each id) is
 * {@see MembershipResolver}'s job; this is one rung of that ladder.
 */
final class FolderMarkers {
	public function __construct(
		public readonly string $projectId,
		public readonly string $teamId,
	) {
	}

	/** True when this folder is a mirrored Penpot project folder. */
	public function hasProject(): bool {
		return $this->projectId !== '';
	}

	/** True when this folder is a Team Folder mapped to a Penpot team. */
	public function hasTeam(): bool {
		return $this->teamId !== '';
	}

	/** True when the folder carries neither marker — an ordinary NC folder. */
	public function isBare(): bool {
		return $this->projectId === '' && $this->teamId === '';
	}
}
