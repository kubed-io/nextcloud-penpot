# Notes, decisions and history for this feature: ../AGENTS.md#designspurge

Feature: Emptying the trash
  As a Nextcloud user
  I want emptying the trash to finish the delete on both sides
  So that the one irreversible act is reached only by the one irreversible gesture

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |

    # ── RULE: the purge finishes what the trashing started ────────────────────
    # notes: ../AGENTS.md#purging-a-mirror-from-the-nextcloud-trash-destroys-the-design

  @in-nextcloud @gesture @plain-folder
  Scenario: Empty the trash
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And "Penpot/Purge Me/Gone For Good.penpot" is in the trash
    And the design "Gone For Good" is in Penpot's trash
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is not in Penpot's trash
    And the file "Penpot/Purge Me/Gone For Good.penpot" is gone from the Nextcloud trash

    # The one irreversible thing this app can cause, reached only by the one
    # irreversible gesture Nextcloud offers.

  # notes: ../AGENTS.md#emptying-a-team-folders-trash-cannot-reach-penpot-and-says-nothing
  @in-nextcloud @gesture @team-folder
  Scenario: Empty the trash of a Team Folder
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And "Penpot/Purge Me/Gone For Good.penpot" is in the trash
    And the design "Gone For Good" is in Penpot's trash
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is still in Penpot's trash
    And the file "Penpot/Purge Me/Gone For Good.penpot" is gone from the Nextcloud trash

    # A Team Folder's trash raises no event this app can hear, so the design is left
    # in Penpot's trash to age out on its own rather than be destroyed unseen.

    # ── RULE: the purge destroys only what it can still see in Penpot's trash ─
    # notes: ../AGENTS.md#a-purge-only-destroys-what-is-still-in-penpots-trash

  @in-nextcloud @gesture @todo
  Scenario: Empty the trash after someone rescued the design in Penpot
    Given a mirrored design "Rescued" in the project "Purge Me"
    And "Penpot/Purge Me/Rescued.penpot" is in the trash
    And the design "Rescued" has been restored in Penpot
    When I purge "Penpot/Purge Me/Rescued.penpot" from the Nextcloud trash
    Then Penpot project "Purge Me" holds a design named "Rescued"
    And the file "Penpot/Purge Me/Rescued.penpot" is gone from the Nextcloud trash

    # Penpot's permanent delete does NOT check that a design is in the trash — hand
    # it a live one and it is destroyed. The trashed mirror still carries the id.

  @in-nextcloud @gesture @todo
  Scenario: Empty the trash after the design is already gone from Penpot
    Given a mirrored design "Twice Dead" in the project "Purge Me"
    And "Penpot/Purge Me/Twice Dead.penpot" is in the trash
    And the design "Twice Dead" has been permanently deleted in Penpot
    When I purge "Penpot/Purge Me/Twice Dead.penpot" from the Nextcloud trash
    Then the file "Penpot/Purge Me/Twice Dead.penpot" is gone from the Nextcloud trash

    # ── RULE: a file the app never mirrored is Nextcloud's alone ──────────────

  @in-nextcloud @gesture
  Scenario: Empty the trash of a file the app never mirrored
    Given a mirrored design "Keep Me" in the project "Purge Me"
    And an untracked ".penpot" archive at "Loose Design.penpot"
    And "Loose Design.penpot" is in the trash
    When I purge "Loose Design.penpot" from the Nextcloud trash
    Then Penpot project "Purge Me" holds a design named "Keep Me"
    And the design "Keep Me" is not in Penpot's trash
    And the file "Loose Design.penpot" is gone from the Nextcloud trash
