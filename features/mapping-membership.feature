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
# BUILT AND NOW LIVE. `MembershipResolver` has existed since Course 3 and every
# other feature defers to it, but nothing in CI had ever asked it a question —
# the resolver was the most load-bearing rule in the app and the least tested.
# The scenarios below drive it through `occ penpot_sync:status`, which prints the
# resolved membership alongside the raw markers, so a failure says which of the
# two disagreed.
#
# THE BACKGROUND WAS FICTION. It provisioned a Team Folder and mirrored a project
# called "My Stuff", and none of those steps had ever existed — harmless while
# the file was entirely @todo, an instant `--strict` failure the moment one
# scenario went live. Same trap as project-folder.feature (§C6.18). It is now the
# standard Background: a PLAIN mapped folder, because Team Folder provisioning is
# not covered by this suite (features/README.md).

Feature: Membership is the nearest ancestor folder carrying a Penpot id
  As a Nextcloud admin
  I want membership derived by walking up the folder tree
  So that Nextcloud can nest freely while Penpot stays flat

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"
    And the team has been mirrored into Nextcloud

    # ── the core lookup ──────────────────────────────────────────────────────────

  @in-nextcloud @occ
  Scenario: A file's project is the nearest ancestor folder carrying a project id
    Given a mirrored design "Direct" in the project "Nearest"
    Then "Penpot/Nearest/Direct.penpot" resolves to the project "Nearest"
    And resolving "Penpot/Nearest/Direct.penpot" reports the team
    And the file "Penpot/Nearest/Direct.penpot" stores no copy of its project

  @in-nextcloud @gesture
  Scenario: A file nested deeper inside a project folder still belongs to that project
    Given a mirrored design "Deep" in the project "Nested Deep"
    And I create a folder at "Penpot/Nested Deep/wip"
    When I move "Penpot/Nested Deep/Deep.penpot" to "Penpot/Nested Deep/wip/Deep.penpot"
    Then "Penpot/Nested Deep/wip/Deep.penpot" resolves to the project "Nested Deep"
    And Penpot project "Nested Deep" holds a design named "Deep"
    # The old cap called this "too deep" and orphaned the file. It is just a
    # subfolder — Penpot has no opinion about it, so neither do we, and the
    # design must not have moved project as a side effect of the drag.

  @in-nextcloud @gesture
  Scenario: The nearest project id wins when project folders are nested
    Given a mirrored design "Outer Design" in the project "Outer"
    And a mirrored project "Inner"
    And I create a new design file at "Penpot/Inner/Inner Design.penpot"
    When I move "Penpot/Inner" to "Penpot/Outer/Inner"
    Then "Penpot/Outer/Inner/Inner Design.penpot" resolves to the project "Inner"
    And "Penpot/Outer/Outer Design.penpot" resolves to the project "Outer"
    # NEAREST ancestor, not outermost — this is what makes free nesting
    # unambiguous, and it is only reachable now that a project folder can be
    # moved inside another one. Nothing about the nesting reaches Penpot, where
    # both projects stay flat.

  @in-nextcloud @gesture
  Scenario: Project folders can be grouped under ordinary Nextcloud folders
    Given a mirrored design "Grouped" in the project "Grouped Project"
    And I create a folder at "Penpot/Clients"
    When I move "Penpot/Grouped Project" to "Penpot/Clients/Grouped Project"
    Then "Penpot/Clients/Grouped Project/Grouped.penpot" resolves to the project "Grouped Project"
    And resolving "Penpot/Clients/Grouped Project/Grouped.penpot" reports the team
    And Penpot holds no project named "Clients"
    # The team is found FURTHER UP, past a folder Penpot has no concept of.

  @in-nextcloud @gesture
  Scenario: A file with no project-id ancestor belongs to no mapping
    Given I create a folder at "Outside Everything"
    When I create a new design file at "Outside Everything/Loose.penpot"
    Then "Outside Everything/Loose.penpot" resolves to no Penpot mapping at all
    And the file "Outside Everything/Loose.penpot" carries no Penpot id
    # Untracked: no id, no mapping. A file that HAS an id and no mapping is the
    # separate "unmapped" state — see move.feature.

    # ── the Drafts state: a team ancestor but no project ancestor ───────────────
    # Drafts is NEVER a folder (saga §6.35). It is the name Penpot gives to
    # "belongs to a team, sits in no project" — exactly what the nearest-ancestor
    # rule produces when it finds a team id but no project id on the way up.
    #
    # This boundary is where §C6.8, §C6.9 and §C6.10 all lived, every one of them
    # the same mistake: reading "no project ancestor" as "outside every mapping"
    # when it means Drafts — a real project with a real id.

  @in-nextcloud @gesture
  Scenario: A file at the mapped folder's root is in that team's Drafts
    When I create a new design file at "Penpot/At The Root.penpot"
    Then "Penpot/At The Root.penpot" is in the team's Drafts
    And the file "Penpot/At The Root.penpot" carries a Penpot id

  @in-nextcloud @gesture
  Scenario: A file in any plain folder under a team is also in Drafts
    Given I create a folder at "Penpot/Inbox"
    And I create a folder at "Penpot/Inbox/2026"
    When I create a new design file at "Penpot/Inbox/2026/Filed By Hand.penpot"
    Then "Penpot/Inbox/2026/Filed By Hand.penpot" is in the team's Drafts
    # This is where Nextcloud is MORE expressive than Penpot: any arrangement of
    # ordinary folders under a team maps to the one Drafts bucket. Penpot has a
    # single Drafts because a flat system has nowhere else to put an unfiled
    # design; we can express the same state as a whole folder tree, for free.

  @in-penpot @occ
  Scenario: No folder is ever created to represent Drafts
    When the team is mirrored again
    Then no folder named "Drafts" exists under the mapped folder
    # Mirroring Drafts as a folder would make a design appear to be in two places
    # at once — at the team root AND inside a Drafts folder.

    # ── the visible marker ───────────────────────────────────────────────────────

  @in-penpot @occ
  Scenario: A project folder carries a visible tag as well as its metadata
    Given a mirrored project "Marked"
    Then the folder "Penpot/Marked" carries a Penpot project id
    And the folder "Penpot/Marked" carries the "penpot" tag
    # Two markers, two jobs (§6.32): the metadata is what every lookup reads, the
    # tag is what a user can see and search for. Under free nesting that matters
    # more than it would have under the old depth cap — position no longer tells
    # you which folders are projects.

  @in-penpot @occ
  Scenario: A tagged folder's name always equals its Penpot project's name
    Given a mirrored project "Exactly This Name"
    Then the folder "Penpot/Exactly This Name" carries a Penpot project id
    And Penpot holds a project named "Exactly This Name"
    # Under free nesting a project folder is otherwise indistinguishable from an
    # ordinary folder someone named the same thing. Tag + matching name means a
    # tagged folder called "Acme" IS the Penpot project "Acme" — no ambiguity.
    # The rename half of the invariant is rename.feature's, where it is live.

  @in-nextcloud @gesture
  Scenario: A plain folder inside a mapped folder is tolerated, not adopted
    Given I create a folder at "Penpot/Just Sitting Here"
    When the team is mirrored again
    Then the folder "Penpot/Just Sitting Here" carries no Penpot project id
    And Penpot holds no project named "Just Sitting Here"
    # This is the whole point of the tag: ordinary folders can live among project
    # folders without becoming projects. The opt-in that DOES make one a project
    # is project-folder.feature's, and it is live there.

  @in-nextcloud @occ
  Scenario: A folder opted in by tag resolves exactly like a mirrored one
    Given I create a folder at "Penpot/Opted In"
    And the folder "Penpot/Opted In" has been tagged "penpot"
    When I create a new design file at "Penpot/Opted In/After The Tag.penpot"
    Then "Penpot/Opted In/After The Tag.penpot" resolves to the project "Opted In"
    # The opt-in itself — what the tag DOES — is project-folder.feature's, and it
    # is live there. This is only the half this file owns: once stamped, nothing
    # downstream can tell which direction the folder came from.

    # ── team resolution, and the one exception ───────────────────────────────────

  @todo
  Scenario: A project folder's team is the nearest ancestor carrying a team id
    Given the "My Stuff" project folder is nested two levels deep inside the Team Folder
    Then it still resolves to the "Northwind" team
    And the depth between them is irrelevant to the lookup

  @todo
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

  @in-nextcloud @occ
  Scenario: Non-Penpot content inside a project folder is left alone
    Given a mirrored design "Managed" in the project "Mixed Contents"
    And I create an unrelated file at "Penpot/Mixed Contents/notes.txt"
    When the team is mirrored again
    Then the file "Penpot/Mixed Contents/notes.txt" is still there and untouched
    And the file "Penpot/Mixed Contents/notes.txt" carries no Penpot id
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
