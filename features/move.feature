# Moving things. The guiding principle, stated by Command and applied to every
# scenario below: DON'T LOSE DATA. A move never destroys bytes, never contacts
# Penpot destructively, and never leaves a file in a state the user can't get
# back out of.
#
# MOVES ARE NOW MUCH SIMPLER THAN EARLIER DRAFTS, because of saga §6.29: a file's
# project is THE NEAREST ANCESTOR FOLDER carrying a project id. So a move —
# up, down, sideways, into a plain folder, into a deeply nested one — resolves
# exactly one way: look up from the destination. There is no "too deep" case, no
# special orphan state, no rule about levels.
#
# THE AUTHORITY SPLIT (saga §6.29):
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
# DRAFTS IS A STATE, NOT A FOLDER (saga §6.35): a file under a team but under no
# project folder is in that team's Drafts. So dragging a file from the team root
# into a project folder FILES it, and dragging it back out UN-files it. The
# gesture Nextcloud users already know is exactly the Penpot operation.
#
# THE ONE HARD RULE (saga §6.30): a PROJECT FOLDER may not leave its team folder.
# Moving it out would mean either reparenting the project in Penpot (a destructive
# cross-team mutation, confirmed possible via move-project but far outside §6.1)
# or silently desyncing. Refused with an explanation, not silently undone. Moving
# a project folder ANYWHERE INSIDE its team folder is free and meaningful.
#
# BUILD STATE (Course 4, the move slice). The engine below is BUILT:
# `MoveGuardListener` refuses the two illegal moves before they happen (a project
# folder leaving its team folder, §6.30; a `link` file changing project, §6.43),
# and `MotionService` re-files a moved `sync` file with `move-files`. Both are
# unit-tested to the decision boundary, and deployed to a live pod where a full
# pull still runs clean — the drag itself is exercised from the Files app.
#
# STILL @todo HERE, for the reason rename.feature gives: the integration harness
# is occ-only. There is no running HTTP server, so no WebDAV MOVE to fire a real
# NodeRenamedEvent / BeforeNodeRenamedEvent, and no logged-in session for the
# personal-token branch. Adding a production `occ` move command purely to trip
# the events would be test scaffolding wearing a feature's clothes.
#
# NOT BUILT YET, and marked in place below: the notification surface for a failed
# move, the restore offer, and the "hidden, not deleted" link state.
#
# NO LONGER DORMANT: `sync` mode landed with export-binfile, so the `move-files`
# push above has real files to act on — a promoted design that changes project
# is re-filed in Penpot for real. `occ penpot_sync:set-mode` is the escape hatch
# every link refusal below offers.

@todo
Feature: Moving files and folders never destroys anything
  As a Nextcloud user
  I want to organise my files freely without risking the designs in Penpot
  So that Nextcloud can be as tidy as I like while Penpot stays flat

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── moving FILES within one project: free, local, and it sticks ──────────────

  Scenario: Moving a file into a plain subfolder of its project keeps its project
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And a plain subfolder "wip" inside the "My Stuff" folder
    When I move the file into "wip"
    Then Penpot is never contacted
    And the file still belongs to the "My Stuff" project, resolved from the nearest ancestor
    And the move sticks — a pull does not move it back
    And "wip" is never created in Penpot, which has no concept of subfolders

  Scenario: A pull never relocates a file that is already under the right project
    Given a mirrored ".penpot" file nested inside a plain subfolder of "My Stuff"
    When the pull runs
    Then the file stays exactly where the user put it
    And it is refreshed in place if its Penpot revision moved
    # Nextcloud owns layout (saga §6.29). The pull only cares that the file is
    # under SOME folder mapping to its real project.

  # ── moving FILES between projects: a real, propagated change ────────────────
  # THE DRAG IS THE PENPOT OPERATION (saga §6.35). An earlier draft had these
  # moves silently revert on the next pull — coherent, but useless: it made the
  # obvious gesture a no-op. `move-files` is one call, non-destructive, and
  # instantly reversible by dragging back, so the move propagates.

  Scenario: Moving a file into a different project folder moves it in Penpot
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And the Penpot project "Design System" is mirrored as a folder
    When I move the file into the "Design System" folder
    Then the design is moved to the "Design System" project in Penpot
    And the file keeps its "penpot_id" and all its content
    And a pull confirms the file is where it belongs, and does not move it back

  Scenario: Filing a draft is an ordinary drag
    Given a mirrored ".penpot" file at the Team Folder's root, in Penpot's Drafts
    When I move the file into the "My Stuff" folder
    Then the design is moved from Drafts into the "My Stuff" project in Penpot
    And the file keeps its "penpot_id"
    # Nearest project ancestor changed from none to "My Stuff" ⇒ move-files. The
    # gesture Nextcloud users already know IS the Penpot operation.

  Scenario: Dragging a file out of a project but still under the team un-files it
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I move the file to the Team Folder's root
    Then the design is moved into that team's Drafts project in Penpot
    And the file keeps its "penpot_id" and its content
    # Same rule in reverse — no project ancestor means Drafts (saga §6.35).

  Scenario: A failed move leaves the local move standing and reports it
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I move it into another project folder and the Penpot call fails
    Then the Nextcloud file stays where I put it
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles it
    # Saga §6.18 rule 3 — a remote failure never destroys local state.

  Scenario: A move between projects is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user moves a mirrored file into another project folder
    Then "move-files" is called using that user's own token
    And Penpot attributes the change to that user

  # ── moving FILES out of every mapping: the meaningful move ──────────────────

  Scenario: Moving a "sync" file out of every mapped folder leaves real, openable bytes
    Given a mirrored ".penpot" file in "sync" mode in the "My Stuff" folder
    When I move the file to a folder with no Penpot ancestor
    Then Penpot is never contacted
    And the design still exists, untouched, in Penpot
    And the file keeps its full ".penpot" archive content and its "penpot_id"
    And the file becomes "unmapped" — this app stops mirroring it
    And the archive remains a valid, openable ".penpot" file on its own
    # The "zip in nextcloud only" state (saga §6.23) — the same state the ignore
    # tag produces. Moving it back in offers a restore; see restore.feature.

  # ── link files are confined to their project (saga §6.43) ───────────────────
  # A "sync" file is a real archive, so moving it anywhere leaves the user holding
  # something valuable. A "link" file is a POINTER — move it out and they hold an
  # empty husk that looks like a design and isn't. So links are strictly confined,
  # and every refusal offers the same escape: promote to "sync" first. That isn't
  # a fob-off — it's exactly the action that makes the move safe.

  Scenario: A link file moves freely inside its own project
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    And a plain subfolder "wip" inside the "My Stuff" folder
    When I move the file into "wip"
    Then the move succeeds
    And Penpot is never contacted
    And the file still belongs to the "My Stuff" project

  Scenario: A link file cannot be moved into a different project
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    And the Penpot project "Design System" is mirrored as a folder
    When I try to move the file into the "Design System" folder
    Then the move is refused
    And the refusal offers to promote the file to "sync" mode first
    And Penpot is never contacted

  Scenario: A link file cannot be moved to the team root
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I try to move the file to the Team Folder's root
    Then the move is refused
    And the refusal offers to promote the file to "sync" mode first
    # The team root means Drafts (saga §6.35) — a real project change, which a
    # pointer is not allowed to make.

  Scenario: A link file cannot be moved out of every mapping
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I try to move the file to a folder with no Penpot ancestor
    Then the move is refused
    And the refusal explains a link file holds no archive — only a pointer
    And the app offers to promote it to "sync" mode so it can be kept
    And Penpot is never contacted
    # Allowing this would hand someone an empty husk that looks like a backup.

  Scenario: Promoting a link first makes the move work normally
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I promote the file to "sync" mode
    And a pull runs so the archive is fetched
    And I move the file to a folder with no Penpot ancestor
    Then the move succeeds
    And the file keeps its full ".penpot" archive content and its "penpot_id"

  Scenario: Moving an unmapped tracked file back under a project offers a restore
    Given an unmapped ".penpot" file in "sync" mode that still carries its "penpot_id"
    When I move the file back under a folder mapping to a Penpot project
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until the user confirms
    # Never automatic — a deleted Penpot file can't come back at its original id
    # (saga §6.20/§6.26). See restore.feature.

  Scenario: Moving a never-tracked ".penpot" file under a project creates nothing
    Given a ".penpot" file that was never tracked (no "penpot_id")
    When I move the file into the "My Stuff" folder
    Then the file is NOT automatically registered as a new Penpot design
    And Penpot is never contacted
    And the file sits as ordinary tolerated content, untouched by the pull
    # Creating a design is a deliberate action (create-design.feature), never a
    # side effect of dragging a file somewhere.

  # ── moving PROJECT FOLDERS: free inside the team, refused outside ───────────

  Scenario: A project folder can be moved anywhere inside its team folder
    Given a plain folder "Clients" inside the Team Folder
    When I move the "My Stuff" project folder into "Clients"
    Then the move succeeds
    And Penpot is never contacted
    And files inside "My Stuff" still belong to the "My Stuff" project
    And the folder still resolves to the "Northwind" team, found further up
    And a pull does not move the folder back
    # Free organisation is the whole point of §6.29 — Penpot is flat, we needn't be.

  Scenario: A project folder cannot be moved out of its team folder
    Given the "My Stuff" project folder inside the "Northwind" Team Folder
    When I try to move it outside that Team Folder
    Then the move is refused
    And the refusal explains a project cannot leave its team from Nextcloud
    And it explains that moving a project between teams must be done in Penpot
    And Penpot is never contacted
    And the folder and its files are left exactly as they were
    # Saga §6.30. Reparenting a project in Penpot (move-project) is real and
    # confirmed, but it's a destructive cross-team mutation that changes who can
    # see the work — far outside §6.1. Refuse loudly; never silently undo.

  Scenario: A project folder cannot be moved into a different team's folder
    Given a second Team Folder mapped to the Penpot team "Design Co"
    When I try to move the "My Stuff" project folder into it
    Then the move is refused with the same explanation
    And neither team's mapping is modified

  # ── the invariant that ties the whole file together ─────────────────────────

  Scenario: No move, of any file or folder, ever deletes anything in Penpot
    Given a mirrored ".penpot" file and its project folder
    When I move either of them anywhere at all
    Then no delete, strip, or destructive call is ever made against Penpot
    And the design and project in Penpot are completely unaffected
