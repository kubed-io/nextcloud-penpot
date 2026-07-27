<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Standalone unit-test bootstrap.
 *
 * The unit suite runs with nothing but PHP and the classes under test — no
 * Nextcloud server tree, no docker stack. Composer's autoloader maps
 * `OCA\PenpotSync\` → `lib/` and `OCA\PenpotSync\Tests\Unit\` → `tests/unit/`,
 * and pulls in `nextcloud/ocp` so NC interfaces resolve for the collaborators we
 * mock.
 *
 * `dg/bypass-finals` strips the `final` keyword as classes are autoloaded, so
 * PHPUnit's mock builder can double our `final` classes (it otherwise refuses).
 *
 * The integration suite does NOT use this bootstrap — it runs Behat against a
 * real Nextcloud + Penpot stack (see tests/integration/).
 */
require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp ships interfaces but no autoload block, so a few base symbols
// don't resolve standalone. These declaration-only shims cover exactly what this
// slice's classes reference — nothing more (see the file's own header).
require_once __DIR__ . '/ocp-stubs.php';

\DG\BypassFinals::enable();
