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
- **`sync` mode.** `occ penpot_sync:set-mode <path> sync` stores the design's real exported `.penpot` archive, so it opens, downloads and backs up like any other file. `… link` restores the lightweight pointer, after confirming — that deletes a local backup Penpot is not keeping for you.
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

### Fixed

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
