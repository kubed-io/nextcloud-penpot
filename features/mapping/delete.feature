# Notes, decisions and history for this feature: ../AGENTS.md#team-mappingdelete

Feature: Removing a mapping tears down the connection without ever touching Penpot
  As a Nextcloud admin
  I want removing a mapping to clean up only what it connected, via the trash
  So that I never lose data and Penpot is never contacted by a purely local action

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # THE BACKGROUND WAS FICTION until the scenarios below joined this file: its
    # three steps had never been written. notes: ../AGENTS.md#team-mappingdelete

    # ── RULE: teardown keeps whatever the files were worth keeping for ────────
    # notes: ../AGENTS.md#removing-a-mapping-keeps-what-the-mode-made-worth-keeping

  @occ
  Scenario: Removing a mapping in link mode takes its designs with it
    Given the following mappings were made:
      | team      | folder       | mode |
      | Northwind | Design Files | link |
    And the following items in the mappings:
      | path                                |
      | /Design Files/Cogs/Gizmo.penpot     |
      | /Design Files/Cogs/Doohickey.penpot |
    When the admin removes the "Northwind" mapping
    Then the "Northwind" mapping is no longer configured
    And no ".penpot" designs exist under "/Design Files" in Nextcloud

    # A link holds nothing, so once the mapping that gave it meaning is gone there
    # is nothing left for it to be — whatever else the folder happens to hold.

  @occ
  Scenario: Removing a mapping in sync mode leaves its designs behind, unmapped
    Given the following mappings were made:
      | team      | folder       | mode |
      | Northwind | Design Files | sync |
    And the following items in the mappings:
      | path                                |
      | /Design Files/Cogs/Gizmo.penpot     |
      | /Design Files/Cogs/Doohickey.penpot |
    When the admin removes the "Northwind" mapping
    Then the "Northwind" mapping is no longer configured
    And "Design Files/Cogs/Gizmo.penpot" holds:
      | penpot_id      | the original id |
      | penpot_team_id | absent          |
      | penpot_mode    | "unmapped"      |
      | content        | an archive      |

    # The one difference from the link teardown, and it is the archive: a sync
    # mirror holds the design itself, so it stays and stops being a mirror.
  

