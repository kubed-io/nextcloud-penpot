# Notes, decisions and history for this feature: ../AGENTS.md#mappingview

Feature: Looking at the team mappings
  As a Nextcloud admin
  I want to see what is mapped and what each mapping resolves to
  So that I can tell a stale name from a broken mapping

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  @todo
  Scenario: A team renamed in Penpot does not rename the mapped folder
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    When it is renamed to "Northwind Design" in Penpot
    And the pull runs
    Then the mapping's Nextcloud folder is still "Design Files"
    And the mapping records the team's new name
    And the mapping still resolves, because it is keyed on the team id, not the name
    # notes: ../AGENTS.md#a-team-renamed-in-penpot-does-not-rename-the-mapped-folder
