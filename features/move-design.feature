# Notes, decisions and history for this feature: AGENTS.md#move-design

Feature: Moving a mirrored design
  As a Nextcloud user
  I want moving a design file to re-file the design in Penpot
  So that the folder I drag it into is the project it belongs to
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

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
    And "Penpot/Move To/Travelling.penpot" holds:
      | penpot_id      | the design's id |
      | penpot_team_id | the team's id   |
      | penpot_mode    | "sync"          |
      | content        | an archive      |

    # THE FILE IS THE SAME FILE SOMEWHERE ELSE, which is what the table says and a
    # comment could not: it still names that design, in that team, holding real
    # bytes. A move re-files the design in Penpot — it is not an edit and not a
    # new mirror.
    #
    # THE PROJECT IS DELIBERATELY NOT A ROW. A file's project is the nearest
    # ancestor folder carrying a project id, never a copy on the file (§C6.7) — and
    # a move is exactly the gesture that would make such a copy lie, which is why
    # there is none to update. `penpot_project_id | absent` would say it outright;
    # it is view-design.feature's to say, once.
    # notes: AGENTS.md#dragging-a-sync-design-into-another-project-re-files-it-in-penpot

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

    # notes: AGENTS.md#filing-a-draft-dragging-from-the-team-root-into-a-project

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
    # notes: AGENTS.md#un-filing-dragging-from-a-project-out-to-the-team-root

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
    # produces. Moving it back in offers a restore; see restore-design.feature.

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving an unmapped tracked file back under a project offers a restore
    Given an unmapped ".penpot" file in "sync" mode that still carries its "penpot_id"
    When I move the file back under a folder mapping to a Penpot project
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until the user confirms
    # Never automatic — a deleted Penpot file cannot come back at its original id
    # (§6.20/§6.26). See restore-design.feature.

  @in-nextcloud @gesture
  Scenario: Moving a never-tracked ".penpot" file under a project creates nothing
    Given a mirrored project "Adopt Nothing"
    And I upload a ".penpot" archive at "Uploaded.penpot"
    When I move "Uploaded.penpot" to "Penpot/Adopt Nothing/Uploaded.penpot"
    Then the file "Penpot/Adopt Nothing/Uploaded.penpot" carries no Penpot id
    And Penpot project "Adopt Nothing" holds no design named "Uploaded"
    # Creating a design is a deliberate action (create-design.feature), never a
    # side effect of dragging a file somewhere.

    # notes: AGENTS.md#moving-a-design-from-a-personal-project-into-a-mapped-team-project

  @in-nextcloud @gesture @unbuilt
  Scenario: Moving a design from a personal project into a mapped team project
    Given the user has a personal project folder "Sketches" holding a design
    And a mapped team with a project folder "Client Work"
    When the user moves the design into "Client Work"
    Then the design changes team and project in Penpot in one "move-files" call
    And it keeps its id, its revision and its history
    And the file's "penpot_team_id" is re-stamped to the new team

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

    # notes: AGENTS.md#moving-a-design-out-of-both-mappings-unmaps-it-from-either-side

  # notes: AGENTS.md#a-link-cannot-be-moved-out-of-the-project-it-points-into
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
    # notes: AGENTS.md#a-design-moved-to-another-project-in-penpot-relocates-its-mirror

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
