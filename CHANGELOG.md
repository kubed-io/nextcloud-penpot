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

- Penpot's export response could not be read at all: the archive URL arrives as a Transit *tagged map*, which the decoder mistook for plain JSON and rejected with advice that did not apply.
- The "pull on a schedule" setting silently reverted after saving.
- A mirrored file's drift signal now carries `revn` **and** `modified-at`, so an edit made at the same revision is noticed.
- One broken mapping no longer aborts every other team in a bulk pull.
- Reading a node's Penpot metadata no longer creates an empty record as a side effect, keeping "no record" an honest signal that a node is untracked.
- Mirroring a large team is no longer quadratic: each folder's children are indexed once.
- A pull no longer echoes its own renames back to Penpot.
