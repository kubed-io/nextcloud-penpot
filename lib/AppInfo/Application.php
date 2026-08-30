<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\PenpotSync\BackgroundJob\ScheduledPullJob;
use OCA\PenpotSync\Listener\CopyListener;
use OCA\PenpotSync\Listener\CreateListener;
use OCA\PenpotSync\Listener\DeleteListener;
use OCA\PenpotSync\Listener\LoadFilesScriptListener;
use OCA\PenpotSync\Listener\MoveGuardListener;
use OCA\PenpotSync\Listener\MoveMemoryListener;
use OCA\PenpotSync\Listener\NodeRenamedListener;
use OCA\PenpotSync\Listener\RegisterDavPluginsListener;
use OCA\PenpotSync\Listener\RestoreFromTrashListener;
use OCA\PenpotSync\Listener\TrashPurgeHook;
use OCA\PenpotSync\Notification\Notifier;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Settings\AutoSyncSettings;
use OCA\PenpotSync\Settings\InstanceSettings;
// HIDDEN FOR THE FIRST RELEASE — see the registration below.
// use OCA\PenpotSync\Settings\PersonalSettings;
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
 * The ordering that produced it is the one the siblings earned the hard way and
 * this app inherits: *finish the room before lighting the stove.* Configuration
 * that arrives after the feature means every feature ships twice — once wired to
 * nothing, once wired for real — and the second pass is where the settings bugs
 * live. {@see boot()} follows the same rule one level down: the Penpot metadata
 * keys are registered ahead of anything that writes them, because DAV must
 * advertise them and the resolver's reverse lookups must be indexed.
 *
 * ## THE LISTENERS, AND WHAT EACH GESTURE IS ALLOWED TO REACH
 *
 * Nextcloud gestures are the app's whole write surface (§6.19) — content is
 * strictly one-way (§6.1), so nothing here ever pushes shape data:
 *
 *   rename / move  → `rename-file`, `rename-project`, `move-files`,
 *                    `move-project`                                  (Course 4)
 *   copy           → `duplicate-file` (+ a `move-files` when it lands elsewhere)
 *   write (+ New)  → `create-file`                                  (§6.33)
 *   delete         → `delete-file`            → Penpot's trash, ~7 days
 *   purge          → `permanently-delete-team-files`  — via boot()'s legacy hook
 *   restore        → `restore-deleted-team-files`     — the inverse of the delete
 *
 * Two refusals sit in front of them ({@see MoveGuardListener}, on the event that
 * fires BEFORE the move): nothing crosses the edge of a `link` mapping in either
 * direction (§C6.38), and a `link` file may not leave the project it points into
 * (§6.43). The
 * `SyncGuard` fences out the app's own motion — the pull renames, writes and
 * trashes mirrors constantly — so none of these loop.
 *
 * The Files-app surface (Course 6) is a single {@see LoadFilesScriptListener}
 * that loads the frontend bundle and hands it the instance base URL, the one
 * thing the browser cannot read off the file listing.
 *
 * Still to land: restoring a design from its local archive when Penpot's own
 * trash no longer holds it (`designs/restore.feature`, the lossy layer), and personal
 * projects. Don't scaffold those here ahead of the code that uses them.
 *
 * The admin purge is NOT on that list any more — it was retired rather than
 * deferred (saga/Chapter_3_Building_To_Plan.md#retired--the-admin-purge), and its disabled button
 * went with it. Notifications came off the list the other way: they are the
 * channel two `designs/move.feature` scenarios were waiting on.
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

		// ── PERSONAL TOKEN: HIDDEN FOR THE FIRST RELEASE (saga §D4.13) ───────
		//
		// Per-user, attribution-only (saga §6.18). Registered the same way, but
		// core stores it per-uid because the form declares a PERSONAL section
		// type — see PersonalSettings.
		//
		// The feature is real but unfinished and untested: every scenario in
		// `features/connection/personal.feature` is still @todo. Shipping the
		// card would put a control on the settings page that nothing proves
		// works, so the ENTRY POINTS are hidden and the plumbing is left intact.
		//
		// Nothing downstream breaks. PersonalTokenService::tokenFor() returns
		// null when no token is stored, which it documents as "the ordinary
		// case, not a failure" — every write then attributes to the service
		// account, the same path a user who simply never set one already takes.
		//
		// TO RESTORE: uncomment this line and the `use` at the top of the file,
		// plus <personal-section> and the SetPersonalToken <command> in
		// appinfo/info.xml. Those four lines are the whole switch.
		// $context->registerDeclarativeSettings(PersonalSettings::class);

		// THE CHANNEL A FAILURE TRAVELS ON. A failure that only logs reaches an
		// admin reading nextcloud.log and nobody else — while the person who can
		// act on it is the one who dragged the file.
		$context->registerNotifierService(Notifier::class);

		// The write paths (saga Ch2 Course 4). NodeRenamedEvent fires for both a
		// rename and a move, and the listener routes each: a rename of a managed
		// `.penpot` file or project folder goes up as `rename-file`/`rename-project`,
		// and a move that changes a file's project goes up as `move-files`. The
		// pull's own follow-renames are fenced out by the SyncGuard, so neither loops.
		$context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);

		// The refusals (§C6.38, §6.43), and they must happen BEFORE the move: a
		// `link` mapping holds pointers rather than designs, so anything dragged
		// out of one arrives as empty files and anything dragged in is content
		// Penpot never asked for. Aborting the event is the only hook that reaches
		// every route; the readable half is LinkWriteGuardPlugin on method:MOVE.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveGuardListener::class);

		// AND THE MEMORY, on the same event, because this is the last moment a
		// moving design's metadata still exists. Nextcloud deletes a file's
		// `files_metadata` when it crosses a storage boundary — the file id
		// survives, the record does not — so a design dragged between two mappings
		// on different storages would otherwise reach MotionService looking like a
		// stranger and be imported as a new design. See MoveMemory for the
		// measurement; `designs/move.feature`'s cross-team scenario is what it
		// unblocks.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveMemoryListener::class);

		// A LINK IS READ-ONLY ON DISK, and here that needs saying out loud: a link
		// mirror is a ZERO-BYTE file, so nothing about it stops a desktop client, a
		// `curl` PUT, or an archive dragged on top of it from filling it with bytes
		// the app will never read and the next pull will silently empty again.
		// RegisterDavPluginsListener attaches LinkWriteGuardPlugin, which refuses the
		// write with a 403 before it lands. Sabre is the only reliable choke — core's
		// BeforeNodeWrittenEvent is emitted from File::put() *only* on the
		// non-part-file branch, so an ordinary PUT slips past it. Our own writes go
		// through the Node API and never reach Sabre. Both siblings do exactly this.
		$context->registerEventListener(SabrePluginAddEvent::class, RegisterDavPluginsListener::class);

		// A COPY IS ITS OWN EVENT. NodeCopiedEvent fires for neither a write nor a
		// rename, so without this a copied design is simply never noticed. It
		// becomes a real new design in Penpot (designs/copy.feature, reversed deliberately
		// in §C6.8) — one `duplicate-file` when it lands in the same project, plus
		// a `move-files` when it lands anywhere else.
		$context->registerEventListener(NodeCopiedEvent::class, CopyListener::class);

		// A NEW .penpot FILE BECOMES A DESIGN (§6.33, designs/create.feature). The
		// "+ New" menu writes a file and nothing more — that is the whole
		// Nextcloud-sanctioned pattern — so the server notices it here. The
		// SyncGuard is load-bearing: the pull writes .penpot files constantly.
		$context->registerEventListener(NodeWrittenEvent::class, CreateListener::class);

		// DELETING REACHES PENPOT'S TRASH (designs/delete.feature). This covers the SOFT
		// step only — the purge fires no typed event at all and is wired as a
		// legacy hook in boot(), see TrashPurgeHook.
		$context->registerEventListener(BeforeNodeDeletedEvent::class, DeleteListener::class);

		// ...AND RESTORING REACHES BACK INTO IT. The exact inverse gesture, and
		// unlike the purge this one IS a typed event — files_trashbin dispatches
		// NodeRestoredEvent once the file is back. Without it a restored mirror sat
		// in its folder while the design stayed in Penpot's trash, and the next
		// pull pruned the file a second time (the gap designs/delete.feature used to name).
		$context->registerEventListener(NodeRestoredEvent::class, RestoreFromTrashListener::class);

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

		// THE SCHEDULED PULL. IJobList::add is idempotent, so calling it on every boot just ensures
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

			// RESTORING FROM A TEAM FOLDER'S TRASH ARRIVES BY A DIFFERENT DOOR.
			// groupfolders does not use files_trashbin — it registers its own
			// ITrashBackend, and restoreItem() emits the legacy
			// `\OCA\Files_Trashbin\Trashbin` / `post_restore` hook rather than the
			// typed NodeRestoredEvent registered above. Without this, restoring a
			// mirror on the backend shared teams actually use reached Penpot not at
			// all: the file came back while the design stayed in Penpot's trash, and
			// the next pull pruned it again.
			//
			// Found by running the existing scenarios against both backends (saga
			// §C6.26). Guarded by the same flag, for the same reason: connectHook
			// appends without de-duplication.
			$restoreHook = $context->getAppContainer()->get(RestoreFromTrashListener::class);
			/** @psalm-suppress DeprecatedMethod */
			\OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', $restoreHook, 'postRestore');
		}
	}
}
