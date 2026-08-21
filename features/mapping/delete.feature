# Notes, decisions and history for this feature: ../AGENTS.md#team-mappingdelete

Feature: Removing a mapping tears down the connection without ever touching Penpot
  As a Nextcloud admin
  I want removing a mapping to clean up only what it connected, via the trash
  So that I never lose data and Penpot is never contacted by a purely local action

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # THE BACKGROUND WAS FICTION until the live scenario below joined this file:
    # its three steps had never been written. notes: ../AGENTS.md#team-mappingdelete

  @todo
  Scenario: Removing the team mapping trashes its mirrored files and leaves standalone files alone
    Given a Penpot team named "Northwind" is mapped to the folder "Penpot"
    And a mirrored ".penpot" file in the "My Stuff" folder
    And an untracked standalone ".penpot" file also sitting in the "My Stuff" folder
    When the admin removes the "Northwind" mapping
    Then the mirrored file is moved to the Nextcloud trash
    And the mirrored file becomes "unmapped"
    And the standalone file is left in place, untouched
    And the "Northwind" mapping is no longer configured
    And Penpot is never contacted by this action
    And the design still exists, unchanged, in Penpot

  @todo
  Scenario: There is no project mapping to remove
    Given the "Northwind" mapping exists with several mirrored project folders
    Then no individual project folder can be unmapped
    And the only teardown available is removing the team mapping itself
    # Project folders exist because the pull created them (saga §6.24).

  @todo
  Scenario: Removing a mapping warns about what is actually being trashed
    Given a Penpot team named "Northwind" is mapped to the folder "Penpot"
    And 3 mirrored files in "sync" mode and 10 in "link" mode under the mapping
    When the admin removes the "Northwind" mapping
    Then the confirmation names how many files hold real archives
    And it explains that link files hold no content to lose
    # Don't-lose-data starts with telling the user what's at stake.

  @todo
  Scenario: Trashed mirrored files keep their identity so they can reconnect
    Given a Penpot team named "Northwind" is mapped to the folder "Penpot"
    And a mirrored ".penpot" file in the "My Stuff" folder
    When the admin removes the "Northwind" mapping
    Then the trashed file keeps its "penpot_id" metadata
    And it keeps its archive content if it was in "sync" mode

  @todo
  Scenario: Re-mapping the same team and restoring from trash reconnects the file
    Given the admin removed the "Northwind" mapping and the file is trashed
    When the admin maps the Penpot team "Northwind" again
    And the admin restores the trashed file into the mirrored "My Stuff" subfolder
    Then the file keeps the same "penpot_id" it had before
    And the next pull confirms it is current, or refreshes it if Penpot has since changed
    And no duplicate mirror is created alongside it
    # Reconnecting is matched on penpot_id, so a restored file is adopted rather
    # than duplicated — the same id-matching guarantee sync-now.feature asserts.


