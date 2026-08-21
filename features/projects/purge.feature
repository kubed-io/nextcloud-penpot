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

  @in-nextcloud @gesture @todo
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
      | Shared/Team | /Shared/Team/Alpha.penpot    |

    # Row two is two projects, not one: "Team/Sub" is named through "Team", so the
    # folder that spelled it takes it along. This is where the bin's promise ends.

    # A link team has no scenario here, deliberately: its project folders cannot be
    # trashed, so they can never be in the trash to purge — see projects/delete.

    # ── RULE: emptying Penpot's trash finishes the delete from that side ──────
    # notes: ../AGENTS.md#emptying-penpots-trash-reaches-back-into-the-nextcloud-trash

  @in-penpot @gesture @todo
  Scenario: Empty Penpot's trash while a project folder is trashed
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Doomed/Alpha.penpot |
      | /Penpot/Doomed/Beta.penpot  |
    And "Penpot/Doomed" is in the Nextcloud trash
    When someone empties Penpot's trash
    Then "Penpot/Doomed" is gone from the Nextcloud trash

    # Those designs were the only way the project could have come back, so with them
    # gone the trashed folder has nothing left to be restored to.

  # notes: ../AGENTS.md#a-penpot-purge-may-not-destroy-what-was-never-penpots
  @in-penpot @gesture @todo
  Scenario: Empty Penpot's trash where the trashed folder holds other files
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Doomed/Alpha.penpot |
      | /Penpot/Doomed/Budget.xlsx  |
    And "Penpot/Doomed" is in the Nextcloud trash
    When someone empties Penpot's trash
    Then "Penpot/Doomed" is still in the Nextcloud trash, holding "Budget.xlsx"

    # The same restraint the Penpot-side project delete shows: a spreadsheet has no
    # far side, so nothing that happened in Penpot may destroy it.

    # ── RULE: a purge that cannot reach Penpot destroys nothing there ─────────

  @in-nextcloud @gesture @todo
  Scenario: Purge a trashed project folder while Penpot is unreachable
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Doomed/Alpha.penpot |
    And "Penpot/Doomed" is in the Nextcloud trash
    And Penpot is unreachable
    When I purge "Penpot/Doomed" from the trash
    Then no design is deleted in Penpot
    And "Penpot/Doomed" is gone from the Nextcloud trash

    # Cannot prove what is still in Penpot's trash, so destroy none of it. Emptying
    # the Nextcloud trash is not ours to refuse — that half already happened.
