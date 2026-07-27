<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Intentionally empty.
 *
 * {@see \OCA\PenpotSync\Settings\AdminTest} exists only as the
 * `#[AuthorizedAdminSetting]` target for the connection-test endpoint — Nextcloud
 * gates admin REST endpoints by naming an IDelegatedSettings class, and that
 * interface requires a getForm(). The panel is never listed in info.xml, so this
 * template is never rendered.
 *
 * The Test connection button itself lives in sync_settings.php, with every other
 * button. Same arrangement as nextcloud-grafana.
 */
