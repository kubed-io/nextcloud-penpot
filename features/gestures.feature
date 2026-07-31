# The three file-manager gestures — COPY, MOVE, RENAME — driven for real.
#
# THE DESIGNS LIVE IN copy.feature, move.feature AND rename.feature. Those three
# are the full, still-@todo specifications: every refusal, every mode, every
# edge. THIS file is the subset CI can prove today, and it is the live half of
# all three at once because they share one transport and one setup.
#
# ## WHY THESE THREE HAD NO LIVE TEST UNTIL NOW, AND WHAT IT COST
#
# All three are driven by events Nextcloud emits from its Files API, and nothing
# in `occ` performs a file-manager gesture. So the suite could configure the app
# and pull with it, but could never *use* it: the write-backs shipped and were
# exercised only by hand.
#
# Three bugs came out of that gap in a single sitting, and every one of them
# fails on the first run of the scenarios below:
#
#   §C6.8   a `move-files` param bug was believed for an hour, because nothing
#           red contradicted it — the move write-back had never once run against
#           a real Penpot;
#   §C6.9   a copy silently failed to record its id, which presents as a broken
#           RENAME one gesture later, and reached a human before it reached a
#           test;
#   §C6.10  a copy to the team root did nothing at all in Penpot, while its unit
#           test passed — the mock had been handed a membership shape the
#           resolver never actually produces.
#
# The transport is WebDAV, which is the verb a browser sends, ported from
# nextcloud-n8n where it has long carried CopySteps/MoveSteps/RenameSteps. PHP's
# built-in server is enough for DAV; the workflow starts one alongside `occ`.
#
# ## ASSERTED THROUGH BOTH CHANNELS, ON PURPOSE
#
# Each result is read back through the app (`penpot_sync:status` — what the app
# BELIEVES) and through Penpot's own listing (`penpot_sync:probe --files` — what
# actually EXISTS). Every bug above would have passed a test that only asked the
# app: the file was there, it just meant nothing.

@live
Feature: Copy, move and rename, driven as real gestures
  As a Nextcloud user
  I want the ordinary file gestures to reach Penpot
  So that organising my files organises my designs

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped

  # ── copy ──────────────────────────────────────────────────────────────────

  Scenario: Copying in place creates a second design in the same project
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Copy Here" exists in that team
    And a Penpot file named "Original" exists in the project "Copy Here"
    When the admin runs a pull
    And I copy "Penpot/Copy Here/Original.penpot" to "Penpot/Copy Here/Original copy.penpot"
    Then the file "Penpot/Copy Here/Original copy.penpot" carries a Penpot id
    And the files "Penpot/Copy Here/Original.penpot" and "Penpot/Copy Here/Original copy.penpot" carry different Penpot ids
    And Penpot project "Copy Here" holds a design named "Original copy"
    # Different ids is the load-bearing one: two files claiming a single design
    # is the ambiguity that made the old inert-copy rule necessary at all.

  # THE ONE THAT FAILED BY HAND. The team root has no project FOLDER above it, so
  # membership resolves to "no project" — which reads exactly like "outside every
  # mapping" and is nothing of the kind (§6.35). The copy appeared in Nextcloud
  # and nothing whatsoever happened in Penpot, with nothing logged.
  Scenario: Copying up to the team root creates the design in Drafts
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Copy Up" exists in that team
    And a Penpot file named "Promote Me" exists in the project "Copy Up"
    When the admin runs a pull
    And I copy "Penpot/Copy Up/Promote Me.penpot" to "Penpot/Promote Me copy.penpot"
    Then the file "Penpot/Promote Me copy.penpot" carries a Penpot id
    And Penpot project "Copy Up" holds no design named "Promote Me copy"

  # ── the chain that made a copy bug look like a rename bug ─────────────────

  Scenario: A copy can be renamed immediately, because it was tracked
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Chain" exists in that team
    And a Penpot file named "Before" exists in the project "Chain"
    When the admin runs a pull
    And I copy "Penpot/Chain/Before.penpot" to "Penpot/Chain/Before copy.penpot"
    And I rename "Penpot/Chain/Before copy.penpot" to "After.penpot"
    Then Penpot project "Chain" holds a design named "After"
    And Penpot project "Chain" holds no design named "Before copy"
    And Penpot project "Chain" holds a design named "Before"
    # The last line is the point: renaming the COPY must not touch the original.

  # ── rename ────────────────────────────────────────────────────────────────

  Scenario: Renaming a mirrored file renames its design in Penpot
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Rename Live" exists in that team
    And a Penpot file named "Old Name" exists in the project "Rename Live"
    When the admin runs a pull
    And I rename "Penpot/Rename Live/Old Name.penpot" to "New Name.penpot"
    Then Penpot project "Rename Live" holds a design named "New Name"
    And Penpot project "Rename Live" holds no design named "Old Name"
    # Penpot's name never carries the ".penpot" extension (§6.4) — the assertion
    # is on "New Name", not "New Name.penpot", and that is the whole rule.

  # ── move ──────────────────────────────────────────────────────────────────

  Scenario: Dragging a sync design into another project re-files it in Penpot
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Move From" exists in that team
    And a Penpot project named "Move To" exists in that team
    And a Penpot file named "Travelling" exists in the project "Move From"
    When the admin runs a pull
    And the admin promotes "Penpot/Move From/Travelling.penpot" to "sync" mode
    And I move "Penpot/Move From/Travelling.penpot" to "Penpot/Move To/Travelling.penpot"
    Then Penpot project "Move To" holds a design named "Travelling"
    And Penpot project "Move From" holds no design named "Travelling"
    # Promoted to sync first because a `link` is confined to its project (§6.43)
    # and MoveGuardListener refuses this drag before it happens — that refusal is
    # its own scenario in move.feature, and needs a different assertion.

  # ── create ────────────────────────────────────────────────────────────────
  #
  # "+ New → Penpot design" writes an empty file and stops; the server notices it
  # and creates the design. Asserted in Penpot, because a file appearing in
  # Nextcloud is exactly what a broken create looks like.

  Scenario: A new design file in a project folder becomes a design in that project
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Make Here" exists in that team
    When the admin runs a pull
    And I create a new design file at "Penpot/Make Here/Fresh Idea.penpot"
    Then the file "Penpot/Make Here/Fresh Idea.penpot" carries a Penpot id
    And Penpot project "Make Here" holds a design named "Fresh Idea"
    # The Penpot name never carries the extension (§6.4).

  Scenario: A new design file at the team root is created in Drafts
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Anchor" exists in that team
    When the admin runs a pull
    And I create a new design file at "Penpot/Loose Idea.penpot"
    Then the file "Penpot/Loose Idea.penpot" carries a Penpot id
    And Penpot project "Anchor" holds no design named "Loose Idea"
    # Drafts is a state, not a folder (§6.35) — the file stays where it was made.

  # THE GUARD NEITHER SIBLING NEEDS. An uploaded .penpot already holds a whole
  # design; creating an empty one for it would set the file and Penpot against
  # each other, and the next sync pull would overwrite the real archive with the
  # empty export.
  Scenario: Uploading a ".penpot" archive does not create an empty design
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "No Invent" exists in that team
    When the admin runs a pull
    And I upload a ".penpot" archive at "Penpot/No Invent/Dragged In.penpot"
    Then the file "Penpot/No Invent/Dragged In.penpot" carries no Penpot id
    And Penpot project "No Invent" holds no design named "Dragged In"

  # ── delete: the two trashes ───────────────────────────────────────────────

  Scenario: Deleting a mirror moves the design into Penpot's trash
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Bin Me" exists in that team
    And a Penpot file named "Doomed" exists in the project "Bin Me"
    When the admin runs a pull
    And I delete "Penpot/Bin Me/Doomed.penpot"
    Then the design "Doomed" is in Penpot's trash
    And Penpot project "Bin Me" holds no design named "Doomed"
    # Soft on both sides. Nothing here is irreversible, which is what makes it
    # safe to do without asking.

  Scenario: Emptying the Nextcloud trash destroys the design in Penpot
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Purge Me" exists in that team
    And a Penpot file named "Gone For Good" exists in the project "Purge Me"
    When the admin runs a pull
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    And I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is not in Penpot's trash
    # The one irreversible thing this app can cause, reached only by the one
    # irreversible gesture Nextcloud offers. permanently-delete-team-files does
    # NOT check the trash itself (§C6.11) — the app reads the listing first, and
    # that guard is the only safety there is.

  Scenario: Deleting an untracked ".penpot" file leaves Penpot alone
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Untouched" exists in that team
    And a Penpot file named "Keep Me" exists in the project "Untouched"
    When the admin runs a pull
    And I upload a ".penpot" archive at "Penpot/Untouched/Not Ours.penpot"
    And I delete "Penpot/Untouched/Not Ours.penpot"
    Then Penpot project "Untouched" holds a design named "Keep Me"
    And the design "Keep Me" is not in Penpot's trash

