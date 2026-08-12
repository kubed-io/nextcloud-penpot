# Notes, decisions and history for this feature: ../AGENTS.md#designsdelete

Feature: Trashing a design
  As a Nextcloud user
  I want deleting a design file to be soft on both sides
  So that nothing is lost until I deliberately empty the trash

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |
    And a mapping with the following values:
      | team   | Reference Team |
      | folder | Pointers       |
      | mode   | link           |

    # ── RULE: a delete is soft on both sides ──────────────────────────────────
    # notes: ../AGENTS.md#deleting-a-mirror-moves-the-design-into-penpots-trash

  @in-nextcloud @gesture
  Scenario: Trash a design
    Given a mirrored design "Doomed" in the project "Bin Me"
    When I delete "Penpot/Bin Me/Doomed.penpot"
    Then the design "Doomed" is in Penpot's trash
    And Penpot project "Bin Me" holds no design named "Doomed"
    And the file "Penpot/Bin Me/Doomed.penpot" is in the Nextcloud trash

    # Both sides are soft, and the design keeps its id, revision and history — which
    # is what makes this safe to do without asking.

  @in-nextcloud @gesture @blocked
  Scenario: Trash a design that is already gone from Penpot
    Given a mirrored design "Twice Dead" in the project "Left Alone"
    And the design "Twice Dead" is permanently deleted in Penpot
    When I delete "Penpot/Left Alone/Twice Dead.penpot"
    Then the design "Twice Dead" is not in Penpot's trash
    And the file "Penpot/Left Alone/Twice Dead.penpot" is in the Nextcloud trash

    # Being asked to delete something already deleted is not a problem — it is the
    # outcome the user wanted, so it is not reported as an error.

    # ── RULE: a link is read-only, so it is not deleted from this side ────────
    # notes: ../AGENTS.md#a-link-is-never-deleted-from-nextcloud

  @in-nextcloud @gesture @unbuilt
  Scenario: Trash a link
    Given a mirrored design "Pointer" in the project "Confined"
    When I try to delete "Pointers/Confined/Pointer.penpot"
    Then the delete is refused
    And Penpot project "Confined" holds a design named "Pointer"
    And the design "Pointer" is not in Penpot's trash

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it trashes
    # the file and calls it "hidden". A link is Penpot's copy to remove.

    # ── RULE: a file the app never mirrored is Nextcloud's alone ──────────────
    # notes: ../AGENTS.md#deleting-an-untracked-penpot-file-leaves-penpot-alone

  @in-nextcloud @gesture
  Scenario: Trash a file the app never mirrored
    Given a mirrored design "Keep Me" in the project "Untouched"
    And an untracked ".penpot" archive at "Penpot/Untouched/Not Ours.penpot"
    When I delete "Penpot/Untouched/Not Ours.penpot"
    Then Penpot project "Untouched" holds a design named "Keep Me"
    And the design "Keep Me" is not in Penpot's trash
    And the file "Penpot/Untouched/Not Ours.penpot" is in the Nextcloud trash

    # ── RULE: a design deleted in Penpot takes its mirror to the trash ────────
    # notes: ../AGENTS.md#a-design-deleted-in-penpot-is-snapshotted-then-moved-to-the-trash

  @in-penpot @gesture
  Scenario: Delete a design in Penpot
    Given a mirrored design "Farewell" in the project "Doomed"
    When someone deletes the design "Farewell" in Penpot
    Then the design "Farewell" is in Penpot's trash
    And there is no node at "Penpot/Doomed/Farewell.penpot"
    And the file "Penpot/Doomed/Farewell.penpot" is in the Nextcloud trash
    And the trashed file "Penpot/Doomed/Farewell.penpot" holds the design's final archive

  @in-penpot @gesture
  Scenario: Permanently delete a design in Penpot
    Given a mirrored design "No Way Back" in the project "Erased"
    When someone permanently deletes the design "No Way Back" in Penpot
    Then the design "No Way Back" is not in Penpot's trash
    And there is no node at "Penpot/Erased/No Way Back.penpot"
    And the file "Penpot/Erased/No Way Back.penpot" is in the Nextcloud trash
    And the trashed file "Penpot/Erased/No Way Back.penpot" holds the design's final archive

    # However the design went in Penpot, its mirror only ever reaches OUR trash, and
    # the snapshot is taken while it is still readable — see the notes above.

    # ── RULE: not knowing is not evidence of deletion ─────────────────────────
    # notes: ../AGENTS.md#an-incomplete-listing-prunes-nothing

  @in-penpot @gesture @todo
  Scenario Outline: Sync when the app can see less than it did
    Given a mirrored design "Still Here" in the project "Intact"
    And <the listing is incomplete>
    When the admin syncs every mapping
    Then the sync fails
    And "Penpot/Intact/Still Here.penpot" holds:
      | penpot_id | the design's id |

    Examples: every way the app can end up knowing less than it did
      | the listing is incomplete                   |
      | the service-account token has been rejected |
      | the team's project listing fails            |
      | one project's file listing fails            |

    # The most important rule in the app: a design missing from a listing the app
    # could not read is not a design that was deleted.
