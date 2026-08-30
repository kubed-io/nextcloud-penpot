# Notes, decisions and history for this feature: ../AGENTS.md#projectspurge

Feature: Emptying the trash of a project
  As a Nextcloud user
  I want purging a trashed project folder to finish the delete for everything it held
  So that one gesture leaves nothing behind, and takes nothing else with it

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And the following items in the mappings:
      | path                          |
      | /Penpot/Existing/Alpha.penpot |

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a purge reaches everything the trash gesture put there ──────────
    # notes: ../AGENTS.md#a-purge-reaches-every-project-the-folder-held

  @in-nextcloud @gesture
  Scenario Outline: Purge a trashed project folder
    Given the following items in the mappings:
      | path       |
      | <contents> |
    And "<trashed>" is in the Nextcloud trash
    When I purge "<trashed>" from the trash
    Then no design it held is left in Penpot's trash
    And "<trashed>" is gone from the Nextcloud trash

    Examples: recursive is recursive — one gesture, however many projects it reached
      | trashed     | contents                     |
      | Penpot/Team | /Penpot/Team/Alpha.penpot    |
      | Penpot/Team | /Penpot/Team/Sub/Deep.penpot |

    # ── RULE: emptying Penpot's trash finishes the delete from that side ──────
    # notes: ../AGENTS.md#emptying-penpots-trash-reaches-back-into-the-nextcloud-trash

  @in-penpot @gesture
  Scenario: Empty Penpot's trash while a project folder is trashed
    Given the following items in the mappings:
      | path                         |
      | /Penpot/Emptied/Alpha.penpot |
      | /Penpot/Emptied/Beta.penpot  |
    And "Penpot/Emptied" is in the Nextcloud trash
    When someone empties Penpot's trash
    Then "Penpot/Emptied" is gone from the Nextcloud trash

  # notes: ../AGENTS.md#a-penpot-purge-may-not-destroy-what-was-never-penpots
  @in-penpot @gesture
  Scenario: Empty Penpot's trash where the trashed folder holds other files
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Spared/Alpha.penpot |
      | /Penpot/Spared/Budget.xlsx  |
    And "Penpot/Spared" is in the Nextcloud trash
    When someone empties Penpot's trash
    Then "Penpot/Spared" is still in the Nextcloud trash, holding "Budget.xlsx"

    # ── RULE: a purge that cannot reach Penpot destroys nothing there ─────────
    # notes: ../AGENTS.md#a-purge-penpot-cannot-be-told-about-still-empties-the-bin

  @in-nextcloud @gesture @todo
  Scenario: Purge a trashed project folder while Penpot is unreachable
    Given the following items in the mappings:
      | path                         |
      | /Penpot/Offline/Alpha.penpot |
    And "Penpot/Offline" is in the Nextcloud trash
    And Penpot is unreachable
    When I purge "Penpot/Offline" from the trash
    Then no design is deleted in Penpot
    And "Penpot/Offline" is gone from the Nextcloud trash
