<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use JsonSerializable;

/**
 * The outcome of a connection test — a value object so the admin button, the
 * `occ` twin, and the mapping page cannot describe the same state differently.
 *
 * `success` and `kind` are deliberately independent. {@see KIND_NO_TEAMS} is a
 * SUCCESS: the URL is right, the token works, Penpot answered. It just means
 * nothing can be mapped yet. Folding it into a failure would tell an admin to
 * go fix a connection that is not broken.
 */
final class ConnectionResult implements JsonSerializable {
	/** Connected, and the token can see at least one team. */
	public const KIND_OK = 'ok';

	/** Connected and authenticated, but a member of no teams. Success, not failure. */
	public const KIND_NO_TEAMS = 'no-teams';

	/**
	 * @param list<string> $teams Visible team names — the fact that decides what
	 *                            can be mapped (saga §6.12).
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $kind,
		public readonly string $message,
		public readonly array $teams,
	) {
	}

	/** True only when a mapping could actually be created right now. */
	public function canMap(): bool {
		return $this->success && $this->teams !== [];
	}

	/**
	 * @return array{success: bool, kind: string, message: string, teams: list<string>}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'success' => $this->success,
			'kind' => $this->kind,
			'message' => $this->message,
			'teams' => $this->teams,
		];
	}
}
