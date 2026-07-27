# Where a file "belongs" — resolved by walking UP the folder tree, reading folder
# metadata. This is the single most load-bearing rule in the app; almost every
# other feature file defers to it.
#
# THE RULE (saga §6.29, locked):
#
#   A .penpot file belongs to the Penpot project recorded on THE NEAREST ANCESTOR
#   FOLDER carrying a project id. A project folder belongs to the team recorded on
#   THE NEAREST ANCESTOR FOLDER carrying a team id. No such ancestor ⇒ no mapping.
#
# THIS REPLACES THE OLD "EXACTLY ONE LEVEL, HARD CAP" RULE. Earlier drafts capped
# project folders at exactly one level below the team folder and treated anything
# deeper as an error-ish "tolerated content" state. That cap was a legibility
# guess made before we understood how cleanly folder metadata resolves — and it
# imposed Penpot's flatness on Nextcloud, which doesn't share it. Withdrawn.
#
# WHY FREE NESTING IS THE RIGHT CALL: Penpot is flat and rigid (team → project →
# file, no sub-projects). Nextcloud is a file manager people organise however they
# like. Identity lives in METADATA, not in path — so a project folder works
# exactly the same at any depth, and a user can group five project folders under
# a "Clients/" folder that has no Penpot counterpart at all. That's real value
# Penpot itself can't offer, and it costs us nothing: "walk up until you find the
# key" is the same lookup as "check one level up," minus the early exit.
#
# THIS FILE DESCRIBES `nested` MODE — the default (saga §6.53). A mapping can
# instead be created in `keyed` mode, where a project's NAME is its path and free
# nesting does not apply. The two are mutually exclusive and the choice is
# immutable per mapping; keyed mode is designed but not yet specced or built.
#
# THE MECHANISM IS CONFIRMED LIVE (saga §6.21): Files-Metadata attaches to
# folders exactly as to files — same Node type, same fileid space. Tested
# write/persist/read-back against a REAL production Team Folder (groupfolder 5),
# with an ordinary folder as control. Identical results.
#
# TWO MARKERS, TWO JOBS (saga §6.32):
#   - folder METADATA (penpot_project_id / penpot_team_id) is the authoritative
#     machine record — what every lookup below reads.
#   - a system TAG is the human-visible pill in the Files app, so a user can SEE
#     and SEARCH which folders are real Penpot projects. Under free nesting this
#     matters more than it would have under the cap: position no longer tells you.
#
# MEMBERSHIP IS DERIVED, NEVER STORED ON THE FILE. No "penpot_mapping" key — the
# folders already know, and a stored copy would have to be rewritten on every
# move, which is exactly the drift an earlier move.feature tangled itself in.
#
# @todo — no lib/Service/MappingService exists yet.

@todo
Feature: Membership is the nearest ancestor folder carrying a Penpot id
  As a Nextcloud admin
  I want membership derived by walking up the folder tree
  So that Nextcloud can nest freely while Penpot stays flat

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── the core lookup ──────────────────────────────────────────────────────────

  Scenario: A file's project is the nearest ancestor folder carrying a project id
    When a mirrored ".penpot" file lives directly in the "My Stuff" folder
    Then the file belongs to the "My Stuff" project, read from that folder's metadata
    And that folder belongs to the "Northwind" team, read from the Team Folder's metadata
    And the file itself stores no copy of its mapping

  Scenario: A file nested deeper inside a project folder still belongs to that project
    Given a plain subfolder "wip" created inside the "My Stuff" folder
    When a mirrored ".penpot" file lives inside "wip"
    Then the file still belongs to the "My Stuff" project
    And "wip" is ordinary Nextcloud organisation with no Penpot counterpart
    # The old cap called this "too deep" and orphaned the file. It's just a
    # subfolder — Penpot has no opinion about it, so neither do we.

  Scenario: The nearest project id wins when project folders are nested
    Given the Penpot project "Design System" is mirrored as a folder
    And the "Design System" folder has been moved inside the "My Stuff" folder
    When a mirrored ".penpot" file lives directly in the "Design System" folder
    Then the file belongs to "Design System", not to "My Stuff"
    And nothing about this nesting is reflected in Penpot, where both are flat
    # Nearest ancestor, not outermost — this is what makes nesting unambiguous.

  Scenario: Project folders can be grouped under ordinary Nextcloud folders
    Given a plain folder "Clients" created inside the Team Folder
    When the "My Stuff" project folder is moved inside "Clients"
    Then files in "My Stuff" still belong to the "My Stuff" project
    And "My Stuff" still belongs to the "Northwind" team, found further up
    And "Clients" is never sent to Penpot, which has no concept of it

  Scenario: A file with no project-id ancestor belongs to no mapping
    Given a folder that carries no Penpot metadata, outside every Team Folder
    When a ".penpot" file lives in that folder
    Then the file belongs to no mapping
    And it is "untracked" if it has no "penpot_id", or "unmapped" if it carries one

  # ── the Drafts state: a team ancestor but no project ancestor ───────────────
  # Drafts is NEVER a folder (saga §6.35). It's the name Penpot gives to "belongs
  # to a team, sits in no project" — which is exactly what the nearest-ancestor
  # rule produces when it finds a team id but no project id on the way up.

  Scenario: A file at a Team Folder's root is in that team's Drafts
    When a mirrored ".penpot" file lives directly at the Team Folder's root
    Then it belongs to the "Northwind" team
    And it belongs to no project
    And in Penpot it lives in that team's "Drafts" project

  Scenario: A file in any plain folder under a team is also in Drafts
    Given a plain folder "Inbox" inside the Team Folder, with no Penpot metadata
    And a deeper plain folder "Inbox/2026" also with no Penpot metadata
    When a mirrored ".penpot" file lives in "Inbox/2026"
    Then it belongs to the "Northwind" team
    And it belongs to no project
    And in Penpot it lives in that team's "Drafts" project
    # This is where Nextcloud is MORE expressive than Penpot: any arrangement of
    # ordinary folders under a team all maps to the one Drafts bucket. Penpot has
    # a single Drafts because a flat system has nowhere else to put an unfiled
    # design; we can express the same state as a whole folder tree, for free.

  Scenario: No folder is ever created to represent Drafts
    Given the "Northwind" team has a "Drafts" project in Penpot
    When the pull runs
    Then no folder named "Drafts" is created for it
    And no folder carries the Drafts project's id as metadata
    # Mirroring Drafts as a folder would make a design appear to be in two
    # places at once — at the team root AND inside a Drafts folder.

  # ── the visible marker ───────────────────────────────────────────────────────

  Scenario: A project folder carries a visible tag as well as its metadata
    Given the Penpot project "My Stuff" is mirrored as a folder
    Then the folder carries the Penpot project id as metadata
    And the folder carries the app's project tag, visible in the Files app
    And a user can search or filter for that tag to find every project folder

  # The tag only earns its keep if a tagged folder means exactly one thing —
  # hence the naming invariant below (saga §6.36).
  Scenario: A tagged folder's name always equals its Penpot project's name
    Given the Penpot project "My Stuff" is mirrored as a folder
    Then the folder is named "My Stuff", exactly as Penpot names the project
    And the app does not allow the two names to diverge
    # Under free nesting a project folder is otherwise indistinguishable from an
    # ordinary folder someone named the same thing. Tag + matching name means a
    # tagged folder called "Acme" IS the Penpot project "Acme" — no ambiguity.

  Scenario: A plain folder inside a Team Folder is tolerated, not adopted
    When a plain, untagged folder with no Penpot metadata is created inside the Team Folder
    Then the pull does not touch that folder
    And it is not treated as a Penpot project
    And nothing about it is ever sent to Penpot
    # This is the whole point of the tag: ordinary folders can live among project
    # folders without becoming projects.

  Scenario: Applying the project tag by hand does not create a Penpot project
    Given a plain folder inside the Team Folder
    When a user applies the app's project tag to it by hand
    Then no Penpot project is created
    And the folder carries no project id, so no file inside it resolves to a project
    # The tag is app-owned output, not user input. Whether a tagged folder could
    # ever BECOME a project is the still-open creation fork (team-import.feature).

  # ── team resolution, and the one exception ───────────────────────────────────

  Scenario: A project folder's team is the nearest ancestor carrying a team id
    Given the "My Stuff" project folder is nested two levels deep inside the Team Folder
    Then it still resolves to the "Northwind" team
    And the depth between them is irrelevant to the lookup

  Scenario: A personal project folder has no team ancestor, and that is valid
    Given the user has a personal Penpot token configured
    And a personal project folder mounted at the root of the user's home
    Then the folder carries a Penpot project id but has no team-id ancestor
    And it resolves as a personal project, not as a broken mapping
    And files inside it belong to that project normally
    # The ONE exception to "walk up for a team" (saga §6.31) — a personal team
    # gets no folder of its own, so its projects sit at the home root. Without
    # this rule the natural implementation would treat every personal project as
    # an error. See personal-projects.feature.

  # ── tolerated content ────────────────────────────────────────────────────────

  Scenario: Non-Penpot content inside a project folder is left alone
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And an unrelated file "notes.txt" in the same folder
    When the pull runs
    Then "notes.txt" is untouched and never pruned
    And only files the app recognizes by their metadata are managed
    # Pruning keys on metadata, never on file extension or folder contents.

  # ── the ambiguity free nesting introduces ────────────────────────────────────

  @todo
  Scenario: Two folders carrying the same project id is a reported conflict
    Given two different folders both carry the Penpot project id for "My Stuff"
    Then any file inside either folder still resolves unambiguously to "My Stuff"
    But the app reports the duplicate so an admin can resolve it
    And the pull writes new files into only one of them, deterministically
    # Free nesting makes this reachable (copy a project folder and you have two).
    # The lookup stays well-defined; the WRITE target needs a tie-break. Which
    # rule to use is not yet decided — saga open question #30.
