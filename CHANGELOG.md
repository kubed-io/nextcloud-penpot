# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One SHORT line per entry, written for a user —
  never a paragraph. Say what someone can now do, not how it was built. Only
  **BREAKING:** may stretch. Internal work — CI, refactors, types, tests —
  usually earns no line at all, and never more than a terse one. Deeper detail
  lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->


## [Unreleased]

### Added

- **Mapping a folder that already holds designs in link mode now asks first.** A linked folder holds pointers, so a real design cannot survive in one — the app used to map over them anyway and leave files that looked like designs and were not. It now tells you how many are there and that deleting them is permanent, and does nothing until you say so. Sync mappings are unaffected: they adopt what they find.

- **Removing a mapping now cleans up after itself.** The empty pointer files a `link` mapping left behind are removed — they held nothing and meant nothing once the mapping went. Designs that hold a real copy are kept exactly where they are and simply stop being mirrors, so nothing you could lose is ever deleted. Penpot is still never contacted.

### Fixed

- **Confirming the deletion of designs no longer flashes a failure at you.** Saying yes worked, but an error appeared for a moment first, claiming the mapping had not been saved — while it was being saved. Nextcloud's own confirmation dialog answers a second time after it closes, and the app was listening to the echo.

- **Deleting a project in Penpot no longer leaves a folder behind claiming to be it.** The designs went to your trash as they always did, but the folder stayed — and it was stuck: under a linked folder it could not be deleted afterwards, by any route. An emptied folder now goes to your trash too, and one still holding your own files keeps them and quietly stops being a Penpot project.

- **An ordinary folder inside a linked folder can be deleted again.** Any folder or file you put inside a folder mapped in `link` mode refused to be deleted — forever, and with a reason ("it would come back on the next sync") that was never going to happen, because nothing had ever synced it. Only the designs and project folders Penpot actually fills are protected now.

- **Restoring a design whose Penpot design is gone now brings the design back too.** It used to put the file back and tell you the design was lost — while holding the only copy of it. The file's own archive is imported instead, so the restore finishes. The design starts a fresh history, because Penpot cannot revive a design at the id it had.

- **Destroying a design in Penpot now empties its file out of your Nextcloud trash.** The file used to sit there offering a restore that could not work. It goes only when Penpot can be made to say the design is really gone — a design that was merely moved, or an unreachable Penpot, leaves it exactly where it is.

- **The refusals when a mapping cannot be created say what is actually wrong.** "Not visible to the service account" became "the team was not found using the given credentials", which covers both causes — a team that is not there, and one the service account was never invited to — instead of sending you looking for an invite to a team that never existed. The already-mapped and already-used messages now name which side the clash is on.

- **Removing a mapping asks in a native Nextcloud dialog** instead of the browser's own alert box, and says what will happen to that mapping's files rather than promising that nothing is deleted — which stopped being true when the teardown landed.

- **The scheduled-sync toggle is a real checkbox again, and it saves.** It had been a pair of radio buttons ("Off — mirror only when run manually" / "On — pull from Penpot automatically") to work around a Nextcloud bug that silently discarded a checkbox's value. The bug is stepped past rather than worked around now, and an instance that was already switched on stays switched on.

- **Dragging a design out of every mapped folder no longer leaves it adrift in Penpot.** The design used to stay sitting in its project, still shared with the team and indistinguishable from live work, while nothing mirrored it any more. It now goes to Penpot's trash, and the file keeps the design's identity — so dragging it back into a mapped folder brings the same design back, with its full history. If it is past Penpot's grace period, the file's own copy becomes a new design instead, so nothing is ever lost.

- **A design MOVED in Penpot no longer looks like a design somebody deleted.** Moving one into a team this app does not mirror used to leave its Nextcloud file in your trash, reading as a deletion that never happened. The file is now simply removed — the design is safe in Penpot. A design that really was deleted or purged still goes to your trash, because in that case your file may be the last copy.

- **A linked design is never quietly turned into a synced one.** When a link lost its design, the app used to download a copy and re-label the file — changing what the file meant without being asked.

- **A folder becomes a Penpot project when you create a design in it.** Previously a folder only became a project if you tagged it `penpot`, so a design made in a new folder landed loose in the team's Drafts and the folder meant nothing on the Penpot side. Dragging an already-mirrored design into a new folder promotes it too, as long as both are in the same mapped folder. An empty folder is still just a folder, and the tag still works.

- **Deleting a project folder now deletes the project in Penpot.** It used to be a purely local delete — the folder went to your trash and the project sat in Penpot untouched. Both trashes are soft, so the whole gesture is reversible on both sides, and trashing a folder that several projects sit under takes all of them.

- **A linked design can no longer be renamed or deleted from Nextcloud.** A link holds no copy of the design, so its name and its existence are Penpot's — renaming one used to appear to work and then be silently undone by the next sync. Both now say why instead.

- **Moving a project folder now renames the project in Penpot.** Dragging one into another folder used to leave Penpot calling it by its old name, and a project nested inside a folder was named after the folder alone instead of its path — so `Penpot/foo/Old` was announced as `Old`. Both sides now agree that a project's name is where it sits.

- When a move is refused — anything crossing the edge of a link folder — Nextcloud now shows you the reason instead of an empty error.

### Changed

- Every confirmation in the admin panel is now the same dialog, so a question about deleting designs looks and behaves exactly like one about removing a mapping.

- **The "Purge Nextcloud files" button is gone from Sync Actions.** It had never worked — it was rendered greyed out with a tooltip promising it was coming — and the feature behind it was cancelled. Emptying the Nextcloud trash is unaffected.

- **A project folder can now be dragged out of a mapped folder**, which used to be refused outright. Nothing is deleted in Penpot — the project and its designs stay exactly as they are, and the folder simply becomes an ordinary Nextcloud folder. Dragging one into a *different team's* folder works too, unless that folder is a Team Folder; Nextcloud handles those moves in a way the app cannot yet see.

- **Moving a folder now renames every project below it**, not just the one you dragged — a project's name is the path it sits at, so grouping three projects under a new folder renames all three.

- Supports Nextcloud **34**, and every major in the supported range is now actually executed by the integration suite — after two patch releases of Nextcloud turned out to disagree about behaviour these apps rely on.

- Internal: an untracked step assertion could not fail for the reason it claimed, and a metadata table silently compared unknown vocabulary as a literal (no behaviour change; test harness only).

- Internal: the specification is now organised by behaviour rather than by mechanism — the folder is the noun, the file is the verb — and the integration harness is being re-taught one behaviour at a time. The connection, app-lifecycle, mapping, design and project verbs run again; the rest stay `@todo` until their PR.

### Added

- **Restoring a design Penpot no longer has now tells you so.** The file comes back looking perfectly normal, and until now nothing said that the design behind it was gone for good and your copy is the only one left.

- **Nextcloud now tells you when Penpot did not get the message.** If a design file cannot be added to Penpot, or a move could not be passed on, you get a notification saying what Penpot said — instead of the failure only appearing in the server log.

- **A design's file now carries the design's own dates.** "Modified" shows when the design last changed in Penpot and "Created" when it was created there, instead of both showing when a sync happened to run — and a project folder carries its project's creation date. Sorting a mapped folder by date finally sorts by the designs.
- **Turn any folder into a Penpot project by tagging it `penpot`** — and it takes the designs already inside it with it. Everything else in a mapped folder stays an ordinary folder.
- **Every project folder now carries a visible `penpot` tag**, whether it came from Penpot or you opted it in, so you can spot and search for them in Files. Removing the tag never deletes the project.

- **Nextcloud never empties its own trash because Penpot did.** A design deleted — or even permanently deleted — in Penpot leaves your mirror in the Nextcloud trash, with whatever archive it had. Emptying that trash stays your decision.
- **Restoring a design from your Nextcloud trash brings it back in Penpot too**, with its id, revision, history and links intact — nothing is re-imported. Restoring one no longer leaves a file that the next sync trashes all over again.
- A restore that Penpot did not actually perform is reported as a failure rather than as success, so you never go looking for a design that never came back.

- **"Sync from Penpot" works.** It was a disabled button; now it starts a background sync, shows it running, and reports what it did — including runs the schedule did on its own.
- **The scheduled pull actually runs.** The interval in Sync Settings was previously read by nothing at all, so a design renamed in Penpot stayed renamed only in Penpot. It now reaches Nextcloud on its own.
- **Sync one team from its mapping card** with the button between Save and Delete, when you don't want to sweep everything.

- **Create a design from the Files app.** "+ New → Penpot design" makes a real design in the folder's project — or in that team's Drafts if you make it at the team root. It does not open anything; the file appears and you click it.
- Dragging a `.penpot` archive into a mapped folder does **not** create an empty design for it. That file already holds a design, and inventing a blank one beside it would let the next sync overwrite what you uploaded.
- **Deleting a mirrored design now reaches Penpot**, and the two trashes mirror each other: deleting puts the design in Penpot's trash (recoverable for about a week, with its id, revision and history), and emptying your Nextcloud trash deletes it there for good.
- A purge only ever destroys designs that are actually in Penpot's trash. If someone restored one in Penpot in the meantime, emptying your trash leaves it alone.
- **Copying a design file makes a real copy in Penpot** — in the same project when you copy in place, or into that team's Drafts when you copy to the team root. A `link` copies as completely as a `sync`, because Penpot duplicates the design server-side and no bytes need to travel.
- Mapped folders now behave like ordinary Nextcloud folders: the **+ New button**, subfolders, and paste all work. They never granted a way to write design content to Penpot, and still don't.

- Point the app at a Penpot instance and store an encrypted service-account token, from the admin UI or entirely headlessly over `occ`.
- **Test connection** tells an unset token, a rejected token, and an unreachable Penpot apart — and names the `enable-access-tokens` flag, whose absence looks exactly like a bad token.
- Map a Penpot team to a Nextcloud folder, shared with chosen groups, as a Team Folder or a plain folder. A team can only be mapped if the service account can actually see it.
- Optional per-user Penpot token, so a user's changes are attributed to them in Penpot's history rather than to the shared account.
- Choose whether to pull on a schedule, and how often.
- `occ penpot_sync:sync pull` mirrors a mapped team: projects become folders, designs become `.penpot` files stamped with their Penpot ids. Drafts stays a state at the team root, never a folder.
- **`sync` mode.** A mapping made with `--mode=sync` stores each design's real exported `.penpot` archive, so it opens, downloads and backs up like any other file; a `link` mapping keeps the lightweight pointer. The mode is the mapping's and is fixed once created — remove the mapping and map the team again to change it.
- A pull re-exports a `sync` file only when its Penpot revision moved or its archive is missing, so a team of links costs one listing and no downloads.
- An export that fails costs nothing: the previous content and revision are kept, the run reports how many failed rather than failing outright, and the next pull retries.
- A design deleted in Penpot no longer leaves a mirror that opens nothing — the pull moves it to the Nextcloud trash, where it stays recoverable.
- A pointer gets one last export on its way to the trash, so a deleted design leaves you a real, openable `.penpot` archive instead of a dead link.
- Pruning switches off entirely whenever a listing is incomplete, so a network blip is never mistaken for "Penpot deleted everything". Files you added yourself are never touched.
- `occ penpot_sync:status <path>` reports a mirrored node's Penpot metadata, which project and team it resolves to, and whether it holds a real archive, a pointer, or nothing.
- Renaming a mirrored design or project folder in Nextcloud renames it in Penpot.
- Dragging a design into another project folder re-files it in Penpot; dragging it to the team root files it into that team's Drafts. The design keeps its id, revision and history.
- Moves Penpot cannot express are refused before they happen, with the reason: a project dragged out of its team, or a `link` leaving its project — which offers `sync` mode instead, so nobody is left holding a pointer that looks like a design and isn't.
- Moving a design out of every mapped folder pushes nothing at all, since unmapping is a deliberate act rather than something to infer from a drag.
- Penpot ids are exposed over WebDAV and indexed for search: `penpot_id`, `penpot_revision`, `penpot_mode` on files, `penpot_project_id` and `penpot_team_id` on folders.
- **Click a mirrored design to open it in Penpot.** "Open in Penpot" is the default click and the only opener a `.penpot` file gets — there is no "edit as text", in any mode, because a design archive has nothing to hand-edit. The link keeps working after you rename or move the file, including out of its mapped folder.
- Both `sync` and `link` files open the same live design; the mode only decides whether the archive is stored locally. A file whose design was deleted hides the action instead of following a dead link.
- Mirrored designs get their own file type and icon rather than showing as generic archives. Removing the app puts the mimetype registration back exactly as it found it.
- A `link` file is now **empty** instead of holding a small JSON stub. Everything it used to say — the design id, the revision, the mode — already rides the file's metadata, and one copy cannot disagree with itself. Existing files are emptied by the next pull; nothing else changes.
- `occ penpot_sync:show-config` reports the URL, whether each token is set (never its value), the mappings, and the schedule.
- Design record in `saga/`, with every Penpot API claim verified against a live instance.

### Changed

- The admin cards no longer tag every immutable field with "(fixed)". The value renders as plain text rather than a control, which already says it cannot be edited.
- **A design you create is now stored the way its mapping says.** A new design was always born a pointer, on the reasoning that you could promote it afterwards — so under a `sync` mapping it was the one design in the folder whose archive was never kept. It now takes its mapping's mode, and the next sync fills the archive in.
- **`occ penpot_sync:set-mode` is gone, and with it any way to change one file's mode.** The mode belongs to the mapping and is fixed once created — as it already was in both sibling integrations — so a per-file promote/demote was a second answer to a question the mapping had already settled. To change a team's mode, remove the mapping and map the team again; the same designs come back, by the same ids, into the same folder.
- **A link file can no longer be written to over WebDAV** — a desktop client, a `curl` PUT, or an archive dragged on top of one is refused with a `403` instead of filling a pointer with bytes nothing reads and the next sync throws away. Both sibling apps have refused this for a while; this one never did.

### Fixed

- Spec: the personal-projects file is gone — personal projects follow every ordinary rule, and the only thing that differs is that a personal token maps your home root to your personal team.
- Spec: the team-import file is gone — importing a team was mapping a team and syncing it, stated differently, and the read-only carve-out it warned against had already shipped.
- Spec: the membership file is gone — the nearest-ancestor rule is a rule, not a behaviour, and six of its scenarios were already live word-for-word as moves and creates.
- Spec: the failure file is gone — a failure is not a behaviour, so each one now sits with the thing that can fail; four "prunes nothing" scenarios became one rule, and four export failures another.
- **Two syncs can no longer race over the same folders.** The card's own sync button and `occ penpot_sync:sync` now refuse while another run is going, as the section's button and the scheduled job already did; the command takes `--force` for the case where a previous run was killed and left the status stuck.
- A personal Penpot token **cannot be tested** — there is no health check for it, so a rejected token is only discovered when a change is silently attributed to the service account. Specified, not yet built.
- **`occ penpot_sync:sync` now records its run**, so "last run" no longer reports a scheduled sync while ignoring a CLI one minutes earlier.
- Spec: the settings-panel file is gone — a panel is where behaviour is configured, not a behaviour. What was real in it already lived in `connection/`.
- Spec: the feature files are grouped by what they act on — `designs/`, `projects/`, `team-mapping/` and `connection/` — one verb per file, so a mapping gets the same treatment a design already had.

- Moving or renaming a mirrored design is now proven not to re-download its archive.
- Spec: what happens when a design is edited in Penpot is now written down; a mirror's metadata is specified as the end state of the action that changed it.
- CI: the feature files' `# notes:` pointers are now checked to resolve, and their comments to stay within budget.
- The custom `.penpot` mimetype is now asserted in CI, on the install that registers it — a repair step that silently failed to merge the config used to look exactly like one that worked.
- Spec: `file-type.feature` is gone. A mimetype is not something anyone does — it is what enabling the app left behind, so it is asserted on install; the context-menu glyph moved next to the action that draws it; and the rest became `view-design.feature`, about looking at a mirror. Four scenarios went with it that were already stated elsewhere.
- The scheduled sync is now covered by the integration suite, alongside the two manual buttons — the same tree has to appear whichever one started it.
- Syncing now reports a clear failure when Penpot cannot be reached is **specified but not yet built** — today an unreachable Penpot or a rejected token surfaces as an unhandled error from `occ penpot_sync:sync`.
- **A folder you already made with a project's name is adopted by the first sync**, tagged and stamped, instead of a second folder appearing beside it.
- **Re-share a mapped folder from anywhere and this app reflects it.** The groups a mapped folder is shared with are now read from the folder itself rather than stored alongside the mapping, so a change made in Files or with `occ` shows up here and a sync never puts back a group you removed. Setting the groups to nothing now actually clears them, which it silently did not before.
- **BREAKING:** the `folder mode` setting is gone. It offered `nested` and `keyed`, but only `nested` was ever implemented and `keyed` was refused on save, so it was a choice with one usable value. `--folder-mode` is no longer accepted; existing mappings are unaffected.
- The new-mapping form no longer arrives with **Team Folder** pre-ticked, matching what `occ penpot_sync:add-mapping` does when you say nothing.
- **`occ penpot_sync:set-groups`** changes the groups a mapped folder is shared with — the one field a mapping lets you edit, previously reachable only from the admin panel.
- **A mapped folder now appears the moment you save the mapping**, instead of only when the first sync runs — which could be up to an hour later, and made a fresh mapping look broken. A sync still re-creates it if it goes missing.
- A mapping that asks for a Team Folder is now **refused up front** when the `groupfolders` app is not available, rather than being saved and failing on every sync afterwards.
- **BREAKING:** a new mapping now defaults to a **plain shared folder** instead of a Team Folder. Team Folders come from the optional `groupfolders` app, so the old default asked for a backend that is absent on a stock Nextcloud and could not be provisioned there. Pass `--team-folder` (or tick the box) to get one; existing mappings are unchanged.
- Documentation: the sync behaviour is now described as "sync now" by the admin or the schedule, rather than as the internal reconciler.
- Documentation: a mapped folder's name and its Penpot team's name are now shown as the two independent names they are — a folder need not be called what the team is called.
- **A design restored from the trash now stays restored in Penpot.** Penpot's delete runs a delayed job about 4 seconds after it reports success, and that job removed the design again even though it had just been brought back — so the app announced a lossless restore and the next sync trashed the mirror anyway. The restore is now confirmed past that window and re-issued if it was undone.
- The integration suite's published results now cover every leg of the test matrix; a failing leg could previously be hidden by a passing one and the run reported as green.
- CI: the check that every Behat step resolves no longer misreads a `Feature:`-level status tag or an optional `file(s)` plural — the second could have let a genuinely undefined step pass as defined.
- **Restoring a design from a Team Folder's trash now restores it in Penpot too.** On Team Folders the file came back in Nextcloud while the design stayed in Penpot's trash — and the next sync then removed the file again. Plain shared folders were never affected.
- Integration tests now run against **both storage backends** (a plain shared folder and a Team Folder), where before only the plain one was ever exercised — so a bug that only shows up on groupfolders can no longer ship unnoticed.
- **Deleting a project folder in your own personal space will delete that project in Penpot too**, exactly as it does for a team project. The specification previously claimed the opposite — that was the current gap written down as if it were the intent, and it is now recorded as the gap it is.
- **A design you filed into a subfolder is no longer duplicated by the next sync.** Moving a mirror into a folder of your own inside its project used to leave a second copy of the same design behind on the following sync, and nothing ever cleaned it up.

- The admin panel no longer claims the schedule does nothing. Sync Settings and the mapping cards carried "not built yet" notes written before the background job existed, so a working setting read as an inert one.
- `occ penpot_sync:show-config` reports the last run — its outcome, when it finished, and how much it did — instead of asserting the pull job is not built.

- Large Penpot responses are decoded correctly. The Transit cache was capped at 94 entries (the real limit is 1936) and skipped plain-string keys, so any big record decoded against a shifted cache — which could silently return the wrong field for the wrong key.

- Penpot's export response could not be read at all: the archive URL arrives as a Transit *tagged map*, which the decoder mistook for plain JSON and rejected with advice that did not apply.
- The "pull on a schedule" setting silently reverted after saving.
- A mirrored file's drift signal now carries `revn` **and** `modified-at`, so an edit made at the same revision is noticed.
- One broken mapping no longer aborts every other team in a bulk pull.
- Reading a node's Penpot metadata no longer creates an empty record as a side effect, keeping "no record" an honest signal that a node is untracked.
- Mirroring a large team is no longer quadratic: each folder's children are indexed once.
- A pull no longer echoes its own renames back to Penpot.
