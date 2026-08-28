<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Routes for the admin team-mapping panel.
 *
 * Only the settings panel calls these, and every one is gated by
 * `#[AuthorizedAdminSetting]` on the controller. There is no public or
 * user-facing API surface in this app yet — the Files-app integration
 * (Course 6) brings the first one.
 */
return [
	'routes' => [
		['name' => 'mapping#index', 'url' => '/mappings', 'verb' => 'GET'],
		['name' => 'mapping#create', 'url' => '/mappings', 'verb' => 'POST'],
		['name' => 'mapping#update', 'url' => '/mappings/{id}', 'verb' => 'PUT'],
		['name' => 'mapping#destroy', 'url' => '/mappings/{id}', 'verb' => 'DELETE'],
		['name' => 'mapping#testConnection', 'url' => '/test-connection', 'verb' => 'POST'],
		// Per-mapping "Sync now" — SYNCHRONOUS and scoped to one team, because the
		// admin is watching that card and one team is bounded work.
		['name' => 'mapping#sync', 'url' => '/mappings/{id}/sync', 'verb' => 'POST'],
		// The section-wide button — ASYNC, because a full pull can export an
		// archive per drifted file and outlive the request. `status` is what the
		// panel polls, and it reports runs from every trigger including the
		// schedule.
		['name' => 'sync#pull', 'url' => '/sync/pull', 'verb' => 'POST'],
		['name' => 'sync#status', 'url' => '/sync/status', 'verb' => 'GET'],
		['name' => 'sync#push', 'url' => '/sync/push', 'verb' => 'POST'],
		['name' => 'sync#pushStatus', 'url' => '/sync/push-status', 'verb' => 'GET'],
	],
];
