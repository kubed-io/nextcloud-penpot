<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the Files-app frontend bundle (`penpot_sync-files`) and ships the Penpot
 * base URL through Initial State so the bundle can build a deep link with no
 * round-trip.
 *
 * Wired to {@see LoadAdditionalScriptsEvent}, which the Files app fires once per
 * page render right before its `<script>` tags are emitted — exactly the moment
 * Nextcloud's CSP nonce is in scope.
 *
 * ## THE BASE URL IS THE ONLY STATE THE FRONTEND NEEDS
 *
 * The deep link is `<base>/#/workspace?file-id=<penpot_id>` (saga §C6.1,
 * confirmed against a live instance's own route table). The `penpot_id` rides
 * the directory PROPFIND as file metadata, so the base URL is the one thing the
 * browser cannot derive from the listing — hence Initial State rather than a
 * controller. There is no endpoint to add and none to secure.
 *
 * It is read through {@see InstanceSettings::getUrl()} rather than re-normalised
 * here, so the trailing-slash rule has exactly one definition. An unconfigured
 * instance yields `''`, and the bundle hides the action rather than offering a
 * click that goes nowhere.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
final class LoadFilesScriptListener implements IEventListener {
	public function __construct(
		private InstanceSettings $instance,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		$this->initialState->provideInitialState(
			InstanceSettings::KEY_URL,
			$this->instance->getUrl(),
		);

		// The bundle lives under dist/ (built by `npm run build`, gitignored).
		// Util::addScript appends `js/<file>.js` to `apps/<appid>/`, so the
		// `../dist/` prefix walks back out of js/ and into dist/ — the same walk
		// vite.config.js documents from the other end.
		//
		// Deliberately NO `afterAppId` (the siblings learned this): the bundle must
		// run as early as possible so its registerDavProperty() calls land in the
		// shared scope BEFORE the Files app issues its first directory PROPFIND.
		// Register late and the first folder view races — that listing comes back
		// without metadata-penpot_id, and src/files.js has to pay for a one-shot
		// PROPFIND to recover.
		Util::addScript(Application::APP_ID, '../dist/' . Application::APP_ID . '-files');
	}
}
