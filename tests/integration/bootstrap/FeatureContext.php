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
use OCA\PenpotSync\Tests\Integration\Steps\ConnectionSteps;
use OCA\PenpotSync\Tests\Integration\Support\OccTrait;

/**
 * Behat context for the penpot_sync integration suite.
 *
 * Thin by design: it owns the shared per-scenario state and the constructor that
 * wires transports from the environment. Every step definition lives in a
 * per-concern trait composed in below (mirroring how nextcloud/server composes
 * its own Behat context).
 *
 * **Wired so far: the app lifecycle, the base-URL admin concern, and the live
 * Penpot connection.** As the sync engine lands, the remaining `*Steps` traits
 * arrive here and their features flip off `@todo`.
 *
 * Transport channels:
 *  - **occ** (the `$OCC` env var) drives admin setup exactly the way an operator
 *    or a helm config-injection job would. → {@see OccTrait}
 *  - **A real Penpot**, reached *through the app* via `penpot_sync:probe`
 *    → {@see ConnectionSteps}. The token is minted per run by
 *    the workflow's "Mint Penpot token" step (saga §6.47), so no repository
 *    secret is needed.
 *  - A direct **Penpot RPC** channel (Guzzle, `Authorization: Token`) is what the
 *    later assertion side will use — "did the app really create/export/move
 *    that?" Not needed yet: nothing writes to Penpot in this slice, so asserting
 *    through the app's own read path is sufficient and simpler.
 */
final class FeatureContext implements Context {
	use OccTrait;
	use AppLifecycleSteps;
	use AdminSteps;
	use ConnectionSteps;

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
