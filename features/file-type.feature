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

@todo
Feature: A mirrored Penpot file is a first-class file type
  As a Nextcloud user
  I want .penpot files to be a real, purpose-built file type
  So that they have the right mimetype + icon and expose their sync state

  Background:
    Given the app is connected to Penpot

  Scenario: Mirrored files get the custom mimetype and Penpot icon
    Given a mirrored ".penpot" file
    Then its mimetype is a custom Penpot mimetype, not generic "application/zip"
    And the Files app shows the Penpot icon instead of a generic archive icon

  Scenario: WebDAV PROPFIND exposes the Penpot metadata in the XML
    Given a mirrored ".penpot" file
    When a WebDAV client requests the file's properties (PROPFIND)
    Then the raw XML includes:
      | property                    |
      | nc:metadata-penpot_id       |
      | nc:metadata-penpot_revision |
      | nc:metadata-penpot_mode     |

  Scenario: A file's mapping is derived from its folders, not stored on the file
    Given a mirrored ".penpot" file inside a project folder
    Then the file carries no mapping key of its own
    And its project is read from the nearest ancestor folder carrying a project id
    And its team is read from the nearest ancestor folder carrying a team id
    # Confirmed available and working on Team Folders specifically (saga §6.21).
    # Nearest-ancestor at any depth (saga §6.29) — see mapping-membership.feature.

  Scenario: A project folder is identifiable by both metadata and a visible tag
    Given a folder mirroring the Penpot project "My Stuff"
    Then the folder carries "penpot_project_id" as folder metadata
    And the folder carries the app's project tag, visible in the Files app
    And a Team Folder carries "penpot_team_id" the same way

  Scenario: A file moved out of its mapped folder is unmapped, not untracked
    Given a mirrored ".penpot" file that has been moved out of its mapped folder
    Then its "nc:metadata-penpot_id" property is still present
    And the file resolves to no mapping, because no enclosing folder carries Penpot metadata
    And this combination is what marks the file "unmapped" rather than "untracked"

  Scenario: The mode is visible and reflects whether content is stored
    Given a mirrored ".penpot" file in "link" mode
    Then its "nc:metadata-penpot_mode" property is "link"
    And the file holds no archive content
    Given a mirrored ".penpot" file in "sync" mode
    Then its "nc:metadata-penpot_mode" property is "sync"
    And the file holds the real ".penpot" archive

  Scenario: The metadata is read-only over DAV
    Given a mirrored ".penpot" file
    When a client tries to change "nc:metadata-penpot_id" via PROPPATCH
    Then the change is rejected — the sync engine owns this property
