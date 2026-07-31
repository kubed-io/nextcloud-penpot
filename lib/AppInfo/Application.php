<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\PenpotSync\BackgroundJob\ScheduledPullJob;
use OCA\PenpotSync\Listener\CopyListener;
use OCA\PenpotSync\Listener\CreateListener;
use OCA\PenpotSync\Listener\DeleteListener;
use OCA\PenpotSync\Listener\LoadFilesScriptListener;
use OCA\PenpotSync\Listener\MoveGuardListener;
use OCA\PenpotSync\Listener\NodeRenamedListener;
use OCA\PenpotSync\Listener\TrashPurgeHook;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

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
 * The Files-app surface opens now (saga Ch2 Course 6): a
 * {@see LoadFilesScriptListener} loads the frontend bundle and hands it the
 * instance base URL, which is the one thing it cannot read off the file listing.
 * That is what turns a mirrored `.penpot` row into a click through to the live
 * design. It is the app's FIRST browser-side code — everything before it was
 * `occ`, settings forms and server-side listeners.
 *
 * The background job, the mode pills, "+ New → design" and the remaining Course
 * 6 surface still land later. Don't scaffold those here ahead of the code that
 * uses them.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'penpot_sync';

	/** Guards against connectHook stacking the purge handler on a repeat boot(). */
	private static bool $purgeHookRegistered = false;

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

		// A COPY IS ITS OWN EVENT. NodeCopiedEvent fires for neither a write nor a
		// rename, so without this a copied design is simply never noticed. It
		// becomes a real new design in Penpot (copy.feature, reversed deliberately
		// in §C6.8) — one `duplicate-file` when it lands in the same project, plus
		// a `move-files` when it lands anywhere else.
		$context->registerEventListener(NodeCopiedEvent::class, CopyListener::class);

		// A NEW .penpot FILE BECOMES A DESIGN (§6.33, create-design.feature). The
		// "+ New" menu writes a file and nothing more — that is the whole
		// Nextcloud-sanctioned pattern — so the server notices it here. The
		// SyncGuard is load-bearing: the pull writes .penpot files constantly.
		$context->registerEventListener(NodeWrittenEvent::class, CreateListener::class);

		// DELETING REACHES PENPOT'S TRASH (delete.feature). This covers the SOFT
		// step only — the purge fires no typed event at all and is wired as a
		// legacy hook in boot(), see TrashPurgeHook.
		$context->registerEventListener(BeforeNodeDeletedEvent::class, DeleteListener::class);


		// The Files-app surface (saga Ch2 Course 6). Loads `dist/penpot_sync-files`
		// and hands it the instance base URL, which is all the browser needs to
		// build `<base>/#/workspace?file-id=<penpot_id>` — the id itself already
		// rides the directory PROPFIND as file metadata.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);
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

		// THE SCHEDULED PULL, at last. The interval has been configurable since
		// Course 2 and was read by NOTHING — `occ penpot_sync:show-config` said so
		// out loud ("not running — the pull job is not built yet"), but from the
		// admin surface it looked like a working setting, so a design renamed in
		// Penpot stayed renamed only in Penpot until someone ran occ by hand.
		//
		// IJobList::add is idempotent, so calling it on every boot just ensures
		// the TimedJob exists. The job self-gates on the schedule being enabled
		// and re-reads its interval every time it is instantiated, so changing
		// either setting takes effect on the next tick with no re-registration.
		$context->getAppContainer()->get(IJobList::class)->add(ScheduledPullJob::class);

		// EMPTYING THE TRASH IS NOT AN EVENT. Nextcloud fires no typed event when
		// a file is purged from the trash — the trashbin emits the legacy
		// `\OCP\Trashbin` `preDelete` hook just before it unlinks the node, and
		// that is the only entry point there is, so the deprecation is
		// unavoidable. Connecting the handler INSTANCE because the legacy hook
		// calls object+method.
		//
		// connectHook APPENDS with no de-duplication, so a second boot() in the
		// same process (tests, repeated loadApp) would stack the handler and purge
		// twice per file. Guarded.
		if (!self::$purgeHookRegistered) {
			self::$purgeHookRegistered = true;
			$purgeHook = $context->getAppContainer()->get(TrashPurgeHook::class);
			/** @psalm-suppress DeprecatedMethod */
			\OCP\Util::connectHook('\OCP\Trashbin', 'preDelete', $purgeHook, 'preDelete');
		}
	}
}
