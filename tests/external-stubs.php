<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Psalm stubs for classes that exist in a real Nextcloud at runtime but ship in
 * neither `nextcloud/ocp` nor any Composer package — Sabre/DAV server classes,
 * other bundled apps' event classes, and so on.
 *
 * **Empty on purpose right now.** The first slice touches only OCP interfaces
 * that `nextcloud/ocp` already provides, so there is nothing to stub. It exists
 * because psalm.xml references it (a missing stub file is a hard Psalm error),
 * and so the next person has an obvious home for the first real stub rather than
 * wiring up the plumbing under time pressure.
 *
 * Add entries here one at a time, each with a comment naming the class that
 * needs it — see the sibling apps' versions for the established shape.
 */
