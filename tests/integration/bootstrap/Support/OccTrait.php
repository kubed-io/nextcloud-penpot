<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Support;

/**
 * occ transport: run the admin CLI the way an operator (or our own occ commands)
 * would. Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}.
 *
 * Reads/writes the shared `$occ` / `$lastExit` / `$lastOutput` state declared on
 * the context. A stdin variant will be needed once a command reads a secret from
 * stdin (the siblings' `set-token`); this slice has no such command.
 */
trait OccTrait {
	/**
	 * Run an occ command. $args is appended to the occ prefix verbatim.
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occ(string $args): array {
		$cmd = $this->occ . ' ' . $args . ' 2>&1';
		$output = [];
		$exit = 0;
		exec($cmd, $output, $exit);
		$this->lastExit = $exit;
		$this->lastOutput = implode("\n", $output);
		return ['exit' => $exit, 'output' => $this->lastOutput];
	}

	/**
	 * The storage-backend flags for `add-mapping`, chosen by the matrix leg.
	 *
	 * ## WHY THIS IS AN ENV VAR AND NOT A STEP PARAMETER
	 *
	 * Every behaviour in this suite is valid on BOTH backends, so writing each
	 * scenario twice would produce two identical blocks and prove nothing
	 * (features/README.md). The backend is a dimension the suite is RUN across —
	 * so the Gherkin says nothing about it, and the harness reads which leg it is
	 * in.
	 *
	 * A Team Folder shared with NO GROUP is invisible to everyone, and the app
	 * skips such a mapping with a warning rather than creating dead storage — so
	 * the team backend must name a group the acting user is in. `admin` is the
	 * one the CI admin always has.
	 *
	 * Defaults to the plain backend, so a local `behat` run with nothing set
	 * behaves exactly as it did before the matrix existed.
	 */
	private function backendFlags(): string {
		return $this->isTeamFolderBackend() ? '--team-folder --groups=admin' : '';
	}

	/** True when this leg is exercising the groupfolders-backed backend. */
	private function isTeamFolderBackend(): bool {
		return getenv('PENPOT_TEST_BACKEND') === 'team';
	}
}
