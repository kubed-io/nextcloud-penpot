<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
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
	],
];
