# The custom mimetype makes a mirrored design file a first-class FILE TYPE: its
# own mimetype, its own icon, DAV-exposed (and read-only) metadata. (What happens
# when you OPEN one is the separate "open with" concern; see open-with.feature.)
#
# STILL NEEDED, EVEN THOUGH THE EXTENSION IS REAL (saga §6.4): Penpot's own
# server serves an export as generic `Content-Type: application/zip` (confirmed
# live, re-verified §6.20). So this app registers its own mimetype via the same
# `occ maintenance:mimetype:update-db`/`update-js` mechanism both siblings use.
# The one real win over both: `.penpot` is a SINGLE-TOKEN extension, not a
# compound (`.n8n.json` / `.grafana.json`) — none of the "don't simplify the
# compound extension" fragility n8n's AGENTS.md warns about.
#
# METADATA KEYS (revised — saga §6.21/§6.22 changed this set):
#   penpot_id       — the Penpot file id (the master's n8n_id / grafana_uid).
#                     Stable thread; survives renames and moves because it's keyed
#                     on Penpot's own id, not a name.
#   penpot_revision — Penpot's "revn" + modifiedAt pair (saga §5.5), the drift
#                     signal a pull diffs against so it can skip unchanged files.
#                     NOT a push-loop guard (there is no content push) — a
#                     read-side "is my copy stale" check only, unlike the
#                     siblings' syncedHash keys which guard a writeback loop.
#   penpot_mode     — "sync" or "link" (saga §6.22). NEW since an earlier draft,
#                     which asserted no mode key existed. The axis came back
#                     meaning something different from both siblings: not "which
#                     way do edits flow" (they never flow out) but "do we store
#                     the bytes at all."
#   penpot_team_id  — the Penpot TEAM the design belongs to (saga §C6.7). Added
#                     when the Files-app deep link needed it: Penpot's workspace
#                     route refuses to open without a team, and a browser holding
#                     one directory PROPFIND cannot walk up a freely-nested tree
#                     to find the Team Folder's marker. See below for why this is
#                     not a relapse into the removed "penpot_mapping" key.
#
# WHY penpot_team_id IS NOT THE RETURN OF "penpot_mapping". The removed key
# cached the file's POSITION — project AND team, as resolved from the folder tree
# — and position is exactly what a move changes, so every move had to rewrite it
# or it lied. A team id is not position: it is a property of the DESIGN in
# Penpot, in the same category as penpot_id and penpot_revision. The PROJECT id
# is still deliberately NOT stored on a file, because that one is position and
# does change locally.
#
# The folder walk stays the authority and VERIFIES the stamp: a move between two
# mapped team folders really does change the owning team, so the move path
# re-stamps from the resolver, and "occ penpot_sync:status" reports a
# stamp-vs-folders disagreement rather than letting a stale link open the wrong
# team's workspace.
#
# DELIBERATELY REMOVED: "penpot_mapping". An earlier draft stored the file's
# mapping on the file. That's redundant now that folder-level metadata is
# confirmed working (saga §6.21, tested live on a real Team Folder) — the folder
# already knows which project and team it is, so membership is DERIVED by walking
# up two levels. Storing a copy on every file means rewriting it on every move,
# which is exactly the drift the old move.feature tangled itself in.
#
# SO A FILE'S STATE IS DERIVED FROM penpot_id + WHERE IT LIVES:
#   mirrored  — has penpot_id, has a project-id ancestor folder (saga §6.29)
#   unmapped  — has penpot_id, no project-id ancestor
#   untracked — has no penpot_id
#   ignored   — carries the ignore tag (a visible tag, not metadata — ignore.feature)
#
# "Has a project-id ancestor" is a NEAREST-ANCESTOR walk at any depth, not a
# fixed-level check — see mapping-membership.feature.
#
# FOLDERS CARRY METADATA TOO (saga §6.21, §6.32):
#   penpot_project_id — on a project folder. The authoritative machine record.
#   penpot_team_id    — on a Team Folder.
# Plus a visible system TAG on project folders, so a user can see and search for
# them among ordinary folders — which matters under free nesting, where position
# alone no longer tells you what a folder is.
#
# BUILD STATE, corrected at C6.1 (the old note read "no lib/Service/ exists yet",
# which has been false since Course 3):
#
#   BUILT — the metadata keys, all five, written by the pull and advertised over
#   DAV at `{nc:}metadata-<key>` with the indexed ones queryable (Course 3). The
#   mapping-is-derived-from-folders rule, via MembershipResolver. The read-only
#   guarantee: every key is registered EDIT_FORBIDDEN, so PROPPATCH is refused by
#   core, not by us.
#
#   BUILT AT C6.1 — the custom mimetype and icon. `application/vnd.penpot`, with
#   no structured suffix: `+json` would be a lie for a `sync` mirror (a real ZIP)
#   and `+zip` for a `link` one (a JSON pointer), and `+zip` is the worse lie
#   because it invites a client to unpack a pointer. Registered by
#   lib/Migration/RegisterMimetype.php on every install/upgrade, reverted on
#   uninstall (uninstall.feature).
#
#   NOT BUILT — the project folder's visible system TAG (§6.32). The folder
#   metadata is written; the human-visible pill is still Course 6 work. The
#   fourth scenario below asserts both halves and only the metadata half holds.
#
# @todo — the scenarios are all DAV/mimetype assertions and the integration
# harness is occ-only. The mimetype registration in particular is UNASSERTED IN
# CI right now: a repair step that silently failed to merge the config would look
# exactly like one that worked. Named here so it is not assumed to be covered.

Feature: A mirrored Penpot file is a first-class file type
  As a Nextcloud user
  I want .penpot files to be a real, purpose-built file type
  So that they have the right mimetype + icon and expose their sync state

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

  @in-penpot @occ
  Scenario: Mirrored files get the custom Penpot mimetype, not a generic one
    Given a mirrored design "Typed" in the project "Types"
    Then the DAV content type of "Penpot/Types/Typed.penpot" is "application/vnd.penpot"
    # ASSERTED OVER DAV, because that is where the Files app reads it. A `.penpot`
    # archive would otherwise be sniffed as application/zip — a zip icon, no
    # "Open in Penpot" action, and no hint as to why. The mapping file being
    # right on disk proves nothing about what a client is told.
    #
    # The ICON half stays unasserted here: it is a rendering fact, and the two
    # icon files' separate treatments (§C6.1) are not reachable from HTTP.

    # TWO FILES, ONE MARK (saga §C6.1/§C6.7). The row icon and the context-menu
    # glyph are the same drawing with opposite colour treatments, and collapsing
    # them fails in both directions — this is not a style preference.
  @blocked
  Scenario: The row icon and the menu glyph are separate files
    Given a mirrored ".penpot" file
    Then the Files-row icon comes from the app's colour mark, with a fixed fill
    And the "Open in Penpot" menu glyph is themed to the menu's own colour
    And the menu glyph is drawn as filled shapes, never as strokes
    # Nextcloud renders mimetype icons out of core/img/filetypes/ WITHOUT
    # recolouring them, so that file must carry its own fill or it is invisible.
    # Menu glyphs are the opposite: NC applies its own fill, which overrides
    # fill="none" and floods a stroked outline into a solid tile. A filled shape
    # cannot fail that way — recolouring it just recolours it.

  @in-penpot @occ
  Scenario: WebDAV PROPFIND exposes the Penpot metadata in the XML
    Given a mirrored design "Advertised" in the project "Props"
    Then the DAV property "nc:metadata-penpot_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_mode" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_team_id" of "Penpot/Props/Advertised.penpot" is set
    # The keys are registered in Application::boot() precisely so they ride the
    # directory PROPFIND, and nothing had ever checked that they do. The app's own
    # `status` command cannot answer this — it reads the metadata store directly.
    #
    # `penpot_revision` is deliberately not asserted: a `link` file that has never
    # drifted carries an empty one, so requiring it here would make this scenario
    # about export state rather than about DAV advertising the key set.

  @in-penpot @occ
  Scenario: A file carries the team its design belongs to, but never a project
    Given a mirrored design "Team Stamped" in the project "Stamps"
    Then the DAV property "nc:metadata-penpot_team_id" of "Penpot/Stamps/Team Stamped.penpot" is set
    And the file "Penpot/Stamps/Team Stamped.penpot" stores no copy of its project
    # THE ONE THING CACHED ON THE FILE (§C6.7), and the one thing that is not.
    # The team is stamped because the browser builds the workspace deep link from
    # it and cannot afford to walk a freely-nested tree on every render. The
    # project is NOT, because it is derived from the folders and a copy would go
    # stale on the first move — see mapping-membership.feature.

  @in-penpot @occ
  Scenario: A mirrored file's mode is visible over DAV
    Given a mirrored design "Moded" in the project "Modes"
    Then the DAV property "nc:metadata-penpot_mode" of "Penpot/Modes/Moded.penpot" is "reference"
    And the file "Penpot/Modes/Moded.penpot" holds no content at all
    # `link` is stored as `reference` ON THE WIRE — the key predates the rename
    # and changing it would break every client already reading it, so the app
    # translates at its own boundary and DAV keeps the old name. Written down
    # here because a client author reading only the README would look for "link".

  @in-penpot @occ
  Scenario: A project folder is identifiable by both metadata and a visible tag
    Given a mirrored project "Both Markers"
    Then the folder "Penpot/Both Markers" carries a Penpot project id
    And the folder "Penpot/Both Markers" carries the "penpot" tag
    # Folder metadata works exactly as file metadata does — same Node type, same
    # fileid space (§6.21). The tag is the human half of the same fact (§C6.18).

  @unbuilt
  Scenario: A file moved out of its mapped folder is unmapped, not untracked
    Given a mirrored ".penpot" file that has been moved out of its mapped folder
    Then its "nc:metadata-penpot_id" property is still present
    And the file resolves to no mapping, because no enclosing folder carries Penpot metadata
    And this combination is what marks the file "unmapped" rather than "untracked"

  @todo
  Scenario: The mode is visible and reflects whether content is stored
    Given a mirrored ".penpot" file in "link" mode
    Then its "nc:metadata-penpot_mode" property is "link"
    And the file holds no archive content
    Given a mirrored ".penpot" file in "sync" mode
    Then its "nc:metadata-penpot_mode" property is "sync"
    And the file holds the real ".penpot" archive

  @unbuilt
  Scenario: The metadata is read-only over DAV
    Given a mirrored ".penpot" file
    When a client tries to change "nc:metadata-penpot_id" via PROPPATCH
    Then the change is rejected — the sync engine owns this property

    # ══ NEXTCLOUD'S TIMESTAMPS ARE PENPOT'S NOW ═══════════════════════════════
    #
    # A mirror carries two sets of dates and they used to mean different things:
    #
    #   Nextcloud's `mtime` / `creation_time`   when the app last wrote the node
    #   Penpot's `created-at` / `modified-at`   when the DESIGN was last changed
    #
    # The first is now stamped FROM the second, so sorting a mapped folder by date
    # sorts by the designs rather than by sync activity (saga §C6.24).
    #
    # THERE ARE NO SCENARIOS FOR IT HERE, DELIBERATELY. A modification time is not
    # a behaviour anyone performs — it is the shared RESULT of editing, moving,
    # copying and renaming, each of which is already owned by its own feature file.
    # A scenario asserting "the mtime moved" would be specifying Nextcloud, in the
    # wrong file, with an invented actor. So the assertions ride the behaviours that
    # cause them: a design changed in Penpot (`reconcile.feature`), and a mirror
    # coming into existence (`reconcile.feature`).
    #
    # This file keeps only what is genuinely about the FILE TYPE: which DAV
    # properties exist and who may write them.
    #
    # THE CONSTRAINT THAT MADE IT SUBTLE (§C6.19) still holds and is now enforced
    # in `reconcile.feature`: a pull that changes nothing must move neither mtime
    # nor etag. `touch()` leaves a file's own etag alone but propagates a fresh one
    # to its PARENT FOLDER — which is what sync clients poll — so an unconditional
    # stamp would churn the folder on every tick. Every write is conditional.
    #
    # A PROJECT FOLDER TAKES ITS CREATION TIME ONLY. Core propagates a folder's
    # mtime from its children, so stamping that would be a fight lost on every pull
    # that writes any design — and a propagated mtime is better information anyway
    # ("something in this project changed"), since Penpot's project `modified-at`
    # only moves on a rename.

