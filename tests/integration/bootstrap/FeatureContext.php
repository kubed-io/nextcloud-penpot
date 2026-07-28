<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration;

use Behat\Behat\Context\Context;
use OCA\PenpotSync\Tests\Integration\Steps\AdminSteps;
use OCA\PenpotSync\Tests\Integration\Steps\AppLifecycleSteps;
use OCA\PenpotSync\Tests\Integration\Steps\ConnectionSteps;
use OCA\PenpotSync\Tests\Integration\Steps\MappingSteps;
use OCA\PenpotSync\Tests\Integration\Steps\ModeSteps;
use OCA\PenpotSync\Tests\Integration\Steps\PullSteps;
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
 *  - A direct **Penpot RPC** channel (Guzzle, `Authorization: Token`) seeds the
 *    fixtures the pull needs — "create this project in Penpot, then prove the app
 *    mirrored it." → {@see PullSteps}. The pull itself is asserted through the
 *    app's own read path (`penpot_sync:status`), so the two channels cross-check
 *    each other.
 *  - The same two channels carry the **archive** story → {@see ModeSteps}: seed a
 *    file in Penpot, promote it with `set-mode`, and assert through `status`
 *    that what landed on disk is a real ZIP. That is the only place the SSE
 *    export and its second asset fetch (§5.1–§5.4) meet a real wire.
 */
final class FeatureContext implements Context {
	use OccTrait;
	use AppLifecycleSteps;
	use AdminSteps;
	use ConnectionSteps;
	use MappingSteps;
	use PullSteps;
	use ModeSteps;

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
