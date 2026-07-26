<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration;

use Behat\Behat\Context\Context;
use OCA\PenpotSync\Tests\Integration\Steps\AdminSteps;
use OCA\PenpotSync\Tests\Integration\Steps\AppLifecycleSteps;
use OCA\PenpotSync\Tests\Integration\Support\OccTrait;

/**
 * Behat context for the penpot_sync integration suite.
 *
 * Thin by design: it owns the shared per-scenario state and the constructor that
 * wires transports from the environment. Every step definition lives in a
 * per-concern trait composed in below (mirroring how nextcloud/server composes
 * its own Behat context).
 *
 * **Wired so far: only the app lifecycle and the base-URL admin concern** — this
 * is the first slice, and it is deliberately the smallest thing that proves the
 * pipeline end to end (a real Nextcloud, our app installed, our occ commands
 * driving it, assertions on the result). As the sync engine lands, the remaining
 * `*Steps` traits arrive here and their features flip off `@todo`.
 *
 * Transport channels:
 *  - **occ** (the `$OCC` env var) drives admin setup exactly the way an operator
 *    or a helm config-injection job would. → {@see OccTrait}
 *  - A **Penpot RPC** channel (Guzzle, `Authorization: Token`) is what the later
 *    assertion side will use — "did the app really create/export/move that?" It
 *    isn't here yet because nothing in this slice talks to Penpot. The token it
 *    will use is minted by `bin/mint-penpot-token.sh` (saga §6.47).
 */
final class FeatureContext implements Context {
	use OccTrait;
	use AppLifecycleSteps;
	use AdminSteps;

	private const APP_ID = 'penpot_sync';

	/** The occ invocation prefix, e.g. "php occ". */
	private string $occ;

	/** Result of the most recent occ command. */
	private int $lastExit = 0;
	private string $lastOutput = '';

	public function __construct() {
		// The runner tells us how to invoke occ (path differs between the CI
		// checkout and a local docker stack), defaulting to the common case.
		$this->occ = getenv('OCC') ?: 'php occ';
	}
}
