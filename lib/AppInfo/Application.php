<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\AppInfo;

use OCA\PenpotSync\Listener\MoveGuardListener;
use OCA\PenpotSync\Listener\NodeRenamedListener;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

/**
 * App bootstrap.
 *
 * ## WHAT IS REGISTERED HERE, AND WHAT IS DELIBERATELY STILL ABSENT
 *
 * The admin surface is now **complete** (saga Ch2, Course 2): the instance URL,
 * the service-account credential, the scheduled-pull settings, the team-mapping
 * list, and a per-user personal token card. Every one of them persists,
 * round-trips, and has an `occ` twin.
 *
 * There is still **no sync engine**, no file actions, and no listeners. That is
 * the ordering the siblings earned the hard way and this app inherits: *finish
 * the room before lighting the stove.* Configuration that arrives after the
 * feature means every feature ships twice — once wired to nothing, once wired
 * for real — and the second pass is where the settings bugs live.
 *
 * The visible consequence is that some controls here configure something that
 * does not exist yet (the pull schedule, most obviously). Each one says so in
 * its own description rather than implying a sync that is not running.
 *
 * The one exception, landing now (saga Ch2 Course 3): the Penpot metadata keys
 * are registered in {@see boot()}, ahead of the pull that writes them. That is
 * the same "register the seam before the engine" move both siblings make — the
 * keys must exist for DAV to advertise them and for the resolver's reverse
 * lookups to be indexed, and registration is idempotent and cheap.
 *
 * The first write paths also land now (saga Ch2 Course 4): a
 * {@see NodeRenamedListener} on {@see NodeRenamedEvent} propagates a Nextcloud
 * rename of a managed `.penpot` file or project folder up to Penpot, and re-files
 * a moved design into the project it landed in. Those are the only writes Penpot
 * permits us at this stage (§6.19) — content is still strictly one-way (§6.1).
 * A {@see MoveGuardListener} on {@see BeforeNodeRenamedEvent} refuses the two
 * moves that cannot be honoured: a project folder leaving its team folder
 * (§6.30), and a `link` file leaving the project it points into (§6.43).
 * The `SyncGuard` keeps the pull's own follow-renames from looping through either.
 *
 * The background job, the rest of the Files-app surface and the remaining write
 * paths still land in Courses 4–6. Don't scaffold those here ahead of the code
 * that uses them.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'penpot_sync';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Admin cards, in the order they appear in the section. Their sidebar
		// entries come from AdminSection / PersonalSection, wired in
		// appinfo/info.xml's <settings> block.
		//
		// The team-mapping list and Sync Actions are NOT here: declarative
		// settings can host neither an array-of-objects nor a button, so both are
		// server-rendered IDelegatedSettings panels declared in info.xml — the
		// same split both siblings use, for the same reasons.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(AutoSyncSettings::class);

		// Per-user, attribution-only (saga §6.18). Registered the same way, but
		// core stores it per-uid because the form declares a PERSONAL section
		// type — see PersonalSettings.
		$context->registerDeclarativeSettings(PersonalSettings::class);

		// The write paths (saga Ch2 Course 4). NodeRenamedEvent fires for both a
		// rename and a move, and the listener routes each: a rename of a managed
		// `.penpot` file or project folder goes up as `rename-file`/`rename-project`,
		// and a move that changes a file's project goes up as `move-files`. The
		// pull's own follow-renames are fenced out by the SyncGuard, so neither loops.
		$context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);

		// The one refusal (§6.30), and it must happen BEFORE the move: a project
		// folder may not leave its team folder, because neither honouring it
		// (a cross-team reparent in Penpot) nor ignoring it (a silent desync) is
		// acceptable. Aborting the event shows the user why, at the moment they try.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveGuardListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Register the Penpot metadata keys, once, ahead of the pull that writes
		// them (saga Ch2 Course 3). This surfaces `penpot_id` / `penpot_revision`
		// / `penpot_mode` on files and `penpot_project_id` / `penpot_team_id` on
		// folders over DAV, and makes the indexed keys queryable — the seam the
		// resolver and reconciler build on. Idempotent; safe on every boot, and
		// it registers only the key *schema* — nothing writes a value until the
		// pull lands, so a save still triggers no Penpot behaviour yet.
		//
		// getAppContainer() resolves THIS app's services — the same boot-time
		// accessor both siblings use to register their metadata keys.
		$context->getAppContainer()->get(PenpotMetadata::class)->register();

		// The trash purge hook and the background job still belong to later
		// courses — not scaffolded here ahead of the code that uses them.
	}
}
