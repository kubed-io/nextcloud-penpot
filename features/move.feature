# MOVING A DESIGN — every way it can change project, team, or Drafts state, from
# either side.
#
# This file used to be the Nextcloud half only: the Penpot half lived in
# reconcile.feature and the two scenarios CI could prove lived in a third file
# called gestures.feature. Three places, one behaviour. Now a move is a move,
# whoever performed it, and the sections below are ordered by where it happened.
#
# ## THE GUIDING PRINCIPLE: DON'T LOSE DATA
#
# A move never destroys bytes, never contacts Penpot destructively, and never
# leaves a file in a state the user cannot get back out of.
#
# ## WHY MOVES ARE SIMPLE (saga §6.29)
#
# A file's project is THE NEAREST ANCESTOR FOLDER carrying a project id. So a
# move — up, down, sideways, into a plain folder, into a deeply nested one —
# resolves exactly one way: look up from the destination. There is no "too deep"
# case, no orphan state, no rule about levels.
#
#     NEXTCLOUD is authoritative for FOLDER LAYOUT.
#     PENPOT is authoritative for PROJECT MEMBERSHIP — but a move CHANGES it.
#
# So a pull never drags files to fixed paths. Two kinds of move, both of which
# stick:
#   - within the same project (into a plain subfolder, or between two folders
#     mapping to the same project) — purely local, Penpot never contacted;
#   - into a folder mapping to a DIFFERENT project, or out to the team root —
#     a real membership change, propagated via `move-files` (saga §6.35).
#
# An earlier draft had cross-project moves silently REVERT on the next pull. That
# was coherent but useless — it made the obvious gesture a no-op that had to be
# apologised for in the UI. `move-files` is one call, non-destructive, and
# instantly reversible by dragging back, so the drag propagates instead.
#
# ## DRAFTS IS A STATE, NOT A FOLDER (saga §6.35)
#
# A file under a team but under no project folder is in that team's Drafts. So
# dragging a file from the team root into a project folder FILES it, and dragging
# it back out UN-files it. The gesture Nextcloud users already know is exactly the
# Penpot operation.
#
# That boundary is also where three separate bugs lived (§C6.8/§C6.9/§C6.10), all
# the same mistake: reading "no project ancestor" as "outside every mapping",
# when it means Drafts — a real project. Every scenario that crosses it in either
# direction is live below, deliberately, because project-to-project moves never
# touch it and pass regardless.
#
# ## THE ONE HARD RULE (saga §6.30)
#
# A PROJECT FOLDER may not leave its team folder. Moving it out would mean either
# reparenting the project in Penpot (a destructive cross-team mutation, confirmed
# possible via `move-project` but far outside §6.1) or silently desyncing.
# Refused with an explanation, never silently undone.
#
# ## BUILD STATE
#
# BUILT: `MoveGuardListener` refuses the two illegal moves before they happen,
# and `MotionService` re-files a moved `sync` file with `move-files`. The three
# live scenarios below drive real WebDAV MOVEs against a real Penpot.
#
# NOT BUILT, marked in place: the notification surface for a failed move, the
# restore offer on move-in, and the personal-token attribution branch (which
# needs a logged-in session the harness does not have).

Feature: Moving a design
  As a Nextcloud user
  I want to organise my files freely without risking the designs in Penpot
  So that Nextcloud can be as tidy as I like while Penpot stays flat

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

    # ══ MOVED IN NEXTCLOUD ═════════════════════════════════════════════════════

    # ══ MOVED IN NEXTCLOUD ═════════════════════════════════════════════════════

    # ── RULE: a move within one project is local — Penpot is never told ──────
    # Nextcloud owns folder layout (§6.29). Sub-foldering a design changes nothing
    # Penpot can even represent, so nothing is sent and nothing is undone.

  @in-nextcloud @gesture
  Scenario: Moving a file into a plain subfolder of its project keeps its project
    Given a mirrored design "Wanderer" in the project "Stays Put"
    And I create a folder at "Penpot/Stays Put/wip"
    When I move "Penpot/Stays Put/Wanderer.penpot" to "Penpot/Stays Put/wip/Wanderer.penpot"
    Then Penpot project "Stays Put" holds a design named "Wanderer"
    And the file "Penpot/Stays Put/wip/Wanderer.penpot" still carries its Penpot id
    # No project change, so no `move-files`. "wip" is never created in Penpot,
    # which has no concept of subfolders.

  @in-nextcloud @gesture
  Scenario: A pull never relocates a file the user filed into a subfolder
    Given a mirrored design "Nested" in the project "Left Where I Put It"
    And I create a folder at "Penpot/Left Where I Put It/wip"
    When I move "Penpot/Left Where I Put It/Nested.penpot" to "Penpot/Left Where I Put It/wip/Nested.penpot"
    And the team has been mirrored into Nextcloud
    Then the file "Penpot/Left Where I Put It/wip/Nested.penpot" still carries its Penpot id
    And there is no node at "Penpot/Left Where I Put It/Nested.penpot"
    # Nextcloud owns layout (§6.29). The pull only cares that the file is under
    # SOME folder mapping to its real project.

    # ── between projects: a real, propagated change ───────────────────────────

  @in-nextcloud @gesture
  Scenario: Dragging a sync design into another project re-files it in Penpot
    Given a Penpot project named "Move From" exists in that team
    And a Penpot project named "Move To" exists in that team
    And a Penpot file named "Travelling" exists in the project "Move From"
    And the team has been mirrored into Nextcloud
    And "Penpot/Move From/Travelling.penpot" is a "sync" design
    When I move "Penpot/Move From/Travelling.penpot" to "Penpot/Move To/Travelling.penpot"
    Then Penpot project "Move To" holds a design named "Travelling"
    And Penpot project "Move From" holds no design named "Travelling"
    # Promoted to sync first because a `link` is confined to its project (§6.43)
    # and the guard refuses this drag before it happens — that refusal is its own
    # scenario below, and needs a different assertion.

  @in-nextcloud @gesture @blocked
  Scenario: A move between projects is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user moves a mirrored file into another project folder
    Then "move-files" is called using that user's own token
    And Penpot attributes the change to that user
    # Needs a logged-in session the occ+DAV harness does not have.

  @in-nextcloud @gesture @todo
  Scenario: A failed move leaves the local move standing and reports it
    Given a mirrored ".penpot" file in a project folder
    When I move it into another project folder and the Penpot call fails
    Then the Nextcloud file stays where I put it
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles it
    # Saga §6.18 rule 3 — a remote failure never destroys local state.

    # ── across the Drafts boundary, both directions (§6.35) ───────────────────
    # Both walked by hand on a live instance before being written. They are the
    # same rule in mirror image: the team root has no project ancestor, so it IS
    # that team's Drafts — a real project, not an absence of one.

  @in-nextcloud @gesture
  Scenario: Filing a draft — dragging from the team root into a project
    Given a mirrored project "File Me"
    And I create a new design file at "Penpot/Loose Draft.penpot"
    And "Penpot/Loose Draft.penpot" is a "sync" design
    When I move "Penpot/Loose Draft.penpot" to "Penpot/File Me/Loose Draft.penpot"
    Then Penpot project "File Me" holds a design named "Loose Draft"
    # Created at the root, so it starts life in Drafts; the drag files it.

  @in-nextcloud @gesture
  Scenario: Un-filing — dragging from a project out to the team root
    Given a mirrored design "Going Loose" in the project "Unfile Me"
    And "Penpot/Unfile Me/Going Loose.penpot" is a "sync" design
    When I move "Penpot/Unfile Me/Going Loose.penpot" to "Penpot/Going Loose.penpot"
    Then Penpot project "Unfile Me" holds no design named "Going Loose"
    # The design is in Drafts now. The file simply sits at the team root, because
    # Drafts is a state and never a folder — Nextcloud stays more expressive than
    # Penpot here, and that is the point of the rule.

    # ── out of every mapping: the meaningful move ─────────────────────────────

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving a "sync" file out of every mapped folder leaves real, openable bytes
    Given a mirrored ".penpot" file in "sync" mode in a project folder
    When I move the file to a folder with no Penpot ancestor
    Then Penpot is never contacted
    And the design still exists, untouched, in Penpot
    And the file keeps its full ".penpot" archive content and its "penpot_id"
    And the file becomes "unmapped" — this app stops mirroring it
    And the archive remains a valid, openable ".penpot" file on its own
    # The "zip in nextcloud only" state (§6.23) — the same state the ignore tag
    # produces. Moving it back in offers a restore; see restore.feature.

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving an unmapped tracked file back under a project offers a restore
    Given an unmapped ".penpot" file in "sync" mode that still carries its "penpot_id"
    When I move the file back under a folder mapping to a Penpot project
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until the user confirms
    # Never automatic — a deleted Penpot file cannot come back at its original id
    # (§6.20/§6.26). See restore.feature.

  @in-nextcloud @gesture
  Scenario: Moving a never-tracked ".penpot" file under a project creates nothing
    Given a mirrored project "Adopt Nothing"
    And I upload a ".penpot" archive at "Uploaded.penpot"
    When I move "Uploaded.penpot" to "Penpot/Adopt Nothing/Uploaded.penpot"
    Then the file "Penpot/Adopt Nothing/Uploaded.penpot" carries no Penpot id
    And Penpot project "Adopt Nothing" holds no design named "Uploaded"
    # Creating a design is a deliberate action (create-design.feature), never a
    # side effect of dragging a file somewhere.

    # ── across two mappings: personal ⇄ a shared team ──────────────────────────
    # A user's home root and a mapped Team Folder are two mappings to two
    # DIFFERENT Penpot teams (personal-projects.feature — setting a personal token
    # maps the personal team to the home root, implicitly). So a drag between them
    # is a real cross-team move, and Penpot supports it directly: `move-files`
    # carries the destination's team with it, proven live in §6.27/§6.34.
    #
    # ALLOWED IN BOTH DIRECTIONS, deliberately and for now. It is the simple
    # behaviour — the user moved a design, so the design moved. An admin option to
    # FORBID moving designs out of a team folder is a reasonable thing to want and
    # is deliberately NOT specified: see saga §C6.21 for why it is a bigger
    # decision than it looks.
    #
    # @unbuilt rather than @todo: the personal side of the mapping does not exist
    # in `lib/` at all. The cross-TEAM machinery underneath these does — it is the
    # same `move-files` the scenarios above use.

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving a design from a personal project into a mapped team project
    Given the user has a personal project folder "Sketches" holding a design
    And a mapped team with a project folder "Client Work"
    When the user moves the design into "Client Work"
    Then the design changes team and project in Penpot in one "move-files" call
    And it keeps its id, its revision and its history
    And the file's "penpot_team_id" is re-stamped to the new team
    # One call does both: the destination project's team follows automatically.
    # The re-stamp matters because the workspace deep link is built from it
    # (§C6.7) — a stale one opens the wrong team's workspace.

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving a design from a mapped team into a personal project
    Given a mirrored design in a mapped team's project folder
    And the user has a personal project folder "Sketches"
    When the user moves the design into "Sketches"
    Then the design moves into that personal project in Penpot
    And it keeps its id, its revision and its history
    # The mirror image, and it has to work: allowing one direction and refusing
    # the other would make the rule impossible to state.

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving a design out of both mappings unmaps it, from either side
    Given the user has a personal project folder "Sketches" holding a "sync" design
    When the user moves the design to a folder with no Penpot ancestor
    Then Penpot is never contacted
    And the design still exists, untouched, in Penpot
    And the file keeps its archive and its Penpot id, and stops being mirrored
    # The existing unmapped state (§6.23, above), reached from the personal side.
    # Nothing about the personal mapping changes what leaving a mapping means.

    # ── RULE: a link may not leave the project it points into (§6.43) ────────
    #
    # A "sync" file is a real archive, so moving it anywhere leaves the user
    # holding something valuable. A "link" is a POINTER — move it out and they
    # hold an empty husk that looks like a design and isn't. So links are
    # confined, and every refusal offers the same escape: promote to "sync" first.
    # That is not a fob-off; it is exactly the action that makes the move safe.
    #
    # ONE RULE, THREE DESTINATIONS — which is why these are Examples rather than
    # three scenarios. The destination is an INPUT; the outcome is identical for
    # every row. Contrast the Drafts pair further down, which look equally
    # symmetrical and are two different rules read from opposite ends.
    #
    # (Written as a comment, not a Gherkin `Rule:` block — Behat's parser rejects
    # that keyword outright. See features/README.md.)

  # DRIVEN LIVE. The guard is the only thing in this app that says no, and until
  # now nothing proved it ever does — a guard that silently stopped refusing
  # would hand someone an empty husk that looks like a design, and every test
  # would still have been green.
  @in-nextcloud @gesture
  Scenario Outline: A link cannot be moved out of the project it points into
    Given a mirrored design "Pointer" in the project "Confined"
    And a mirrored project "Elsewhere"
    When I try to move "Penpot/Confined/Pointer.penpot" to "<destination>"
    Then the move is refused
    And the file "Penpot/Confined/Pointer.penpot" carries a Penpot id
    And Penpot project "Confined" holds a design named "Pointer"

    Examples: destinations that would change what the pointer points at
      | destination                          |
      | Penpot/Elsewhere/Pointer.penpot      |
      | Penpot/Pointer.penpot                |
      | Pointer.penpot                       |
    # Row 1 is another project, row 2 is the team root (which MEANS Drafts, a
    # real project change, §6.35), and row 3 leaves every mapping. One rule,
    # three destinations, one outcome — which is what makes it a table.
    #
    # The last two assertions are the point of the refusal: the file is still
    # where it was, still tracked, and Penpot never heard about any of it.

  @in-nextcloud @gesture @todo
  Scenario: A link refusal offers to promote the file to "sync" mode first
    Given a mirrored design "Pointer" in the project "Confined"
    When I try to move it into a different project folder
    Then the refusal offers to promote the file to "sync" mode first
    # Split from the outline above because it asserts on the MESSAGE, which the
    # DAV status alone cannot carry — it needs the exception body surfaced.

  @in-nextcloud @gesture
  Scenario: A link moves freely inside its own project
    Given a mirrored design "Pointer" in the project "Confined"
    And "Penpot/Confined/Pointer.penpot" is a "link" design
    And I create a folder at "Penpot/Confined/wip"
    When I move "Penpot/Confined/Pointer.penpot" to "Penpot/Confined/wip/Pointer.penpot"
    Then the file "Penpot/Confined/wip/Pointer.penpot" still carries its Penpot id
    And Penpot project "Confined" holds a design named "Pointer"
    # The negative case that gives the rule its edge: confinement is to the
    # PROJECT, not to a folder.

  @in-nextcloud @gesture @todo
  Scenario: Promoting a link first makes the move work normally
    Given a "link" design at "Penpot/Confined/Pointer.penpot"
    And the admin has promoted it to "sync" mode
    When I move it to a folder outside every mapping
    Then the move succeeds
    And the file keeps its full ".penpot" archive content and its "penpot_id"
    # The escape hatch every refusal above offers, exercised.

    # ── moving PROJECT FOLDERS: free inside the team, refused outside ─────────

  @in-nextcloud @gesture @todo
  Scenario: A project folder can be moved anywhere inside its team folder
    Given a plain folder "Clients" inside the team folder
    When I move a project folder into "Clients"
    Then the move succeeds
    And Penpot is never contacted
    And files inside it still belong to the same project
    And the folder still resolves to the same team, found further up
    And a pull does not move the folder back
    # Free organisation is the whole point of §6.29 — Penpot is flat, we needn't be.

  @in-nextcloud @gesture
  Scenario: A project folder cannot be moved out of its team folder
    Given a mirrored project "Stays Inside"
    When I try to move "Penpot/Stays Inside" to "Stays Inside"
    Then the move is refused
    And the folder "Penpot/Stays Inside" carries a Penpot project id
    # The folder is still there, still stamped — the refusal happened BEFORE the
    # move, which is the whole point of guarding on the `Before` event.

  @in-nextcloud @gesture @todo
  Scenario: The project-folder refusal explains why, and what to do instead
    Given a mirrored project "Stays Inside"
    When I try to move it outside its team folder
    Then the refusal explains a project cannot leave its team from Nextcloud
    And it explains that moving a project between teams must be done in Penpot
    # Split from the scenario above, which proves the refusal HAPPENS; this one
    # is about what it SAYS, and needs the exception body surfaced through DAV.
    # Saga §6.30. Reparenting a project in Penpot (`move-project`) is real and
    # confirmed, but it is a destructive cross-team mutation that changes who can
    # see the work — far outside §6.1. Refuse loudly; never silently undo.

  @in-nextcloud @gesture @unbuilt
  Scenario: A project folder cannot be moved into a different team's folder
    Given a second team folder mapped to another Penpot team
    When I try to move a project folder into it
    Then the move is refused with the same explanation
    And neither team's mapping is modified

    # ══ MOVED IN PENPOT ════════════════════════════════════════════════════════
    #
    # The same behaviour from the other end, and it arrives via a sync run rather
    # than an event. Penpot is authoritative for project membership, so a design
    # re-filed upstream relocates its mirror — it is not a conflict to resolve, it
    # is the source of truth changing.

  @in-penpot @todo
  Scenario: A design moved to another project in Penpot relocates its mirror
    Given a Penpot project named "Upstream From" exists in that team
    And a Penpot project named "Upstream To" exists in that team
    And a Penpot file named "Relocated" exists in the project "Upstream From"
    And the team has been mirrored into Nextcloud
    And the design "Relocated" is moved to the project "Upstream To" in Penpot
    When the team is mirrored again
    Then there is no node at "Penpot/Upstream From/Relocated.penpot"
    And the file "Penpot/Upstream To/Relocated.penpot" carries a Penpot id
    And the file "Penpot/Upstream To/Relocated.penpot" is not in the Nextcloud trash
    # THE PRUNE MUST NOT FIRE. The design is still named by Penpot, just from a
    # different project — a reconciler that keyed on "not in this folder" instead
    # of "not in this team's listing" would trash the mirror and re-create it,
    # losing a `sync` file's archive on the way past.

  @in-penpot @todo
  Scenario: A design moved into Drafts in Penpot surfaces at the team root
    Given a mirrored design "Sent To Drafts" in the project "Unfiled Upstream"
    And the design "Sent To Drafts" is moved to Drafts in Penpot
    When the team is mirrored again
    Then there is no node at "Penpot/Unfiled Upstream/Sent To Drafts.penpot"
    And the file "Penpot/Sent To Drafts.penpot" carries a Penpot id
    # Drafts is a state, so the mirror lands at the team root — the mirror image
    # of "Un-filing" above, and the same rule read from the other side.

  @in-penpot @unbuilt
  Scenario: A design moved to another team in Penpot leaves this mapping
    Given a mirrored ".penpot" file whose design is moved to an unmapped team
    When the pull runs
    Then the design is no longer named by this team's listing
    And its mirror is moved to the Nextcloud trash like any vanished design
    # Not a special case: from this mapping's point of view the design is simply
    # gone. If the other team is also mapped, its own pull mirrors it there.

    # ── the invariant that ties the whole file together ───────────────────────

  @todo
  Scenario: No move, of any file or folder, ever deletes anything in Penpot
    Given a mirrored ".penpot" file and its project folder
    When I move either of them anywhere at all
    Then no delete, strip, or destructive call is ever made against Penpot
    And the design and project in Penpot are completely unaffected
