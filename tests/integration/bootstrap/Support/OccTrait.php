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
}
