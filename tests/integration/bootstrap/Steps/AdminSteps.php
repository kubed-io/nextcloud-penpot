<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * Admin connection steps — in this slice, the base URL and nothing else.
 *
 * Steps read in plain English and stay medium-agnostic; occ is an implementation
 * detail of the step definitions, not of the feature. That matters here because
 * the same scenarios should keep passing unchanged once a UI exists — the
 * feature says "the admin sets the Penpot base URL", not "the admin runs occ".
 *
 * NO PHPUnit ASSERTIONS IN HERE (saga R1.6). PHPUnit builds an assertion's
 * failure *message* through `TextUI\Configuration\Registry`, which is null
 * outside a PHPUnit-bootstrapped run — so under Behat a **failing** assertion
 * dies with `Registry::get(): Return value must be of type Configuration, null
 * returned`, replacing the diagnostic exactly when it matters. Passing
 * assertions are unaffected, which is why this file looked fine for a whole
 * slice: its assertions had simply never failed. Every check now throws a plain
 * exception carrying the command's own output.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}.
 */
trait AdminSteps {
}
