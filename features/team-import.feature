# Notes, decisions and history for this feature: AGENTS.md#team-import

Feature: Importing an existing Penpot team as a Team Folder, and the open question of Nextcloud-originated projects
  As a Nextcloud user with a configured Penpot token
  I want to see which of my Penpot teams are already mapped and import the ones that aren't
  So that connecting a new team doesn't require the admin to hand-configure every mapping
    # notes: AGENTS.md#team-import-background

  Background:
    Given the app is connected to Penpot
    And the user has a personal Penpot token configured

  @unbuilt
  Scenario: A Penpot team already mapped to a Team Folder is detected, not re-imported
    Given the Penpot team "Northwind" is already mapped to a Team Folder
    And the user's Nextcloud group has access to that Team Folder
    When the user views their Penpot teams in personal settings
    Then "Northwind" is shown as already imported
    And no new folder or mapping is created

    # ── importing a NOT-yet-mapped team — the permission gate is the open point ──
  @blocked
  Scenario: Importing an unmapped team as a Team Folder requires Team Folder rights
    Given the Penpot team "New Team" is visible to the user's token but not yet mapped
    And the acting user does not hold Team Folder admin or delegated rights
    When the user tries to import "New Team" as a Team Folder
    Then the import is refused or routed to an admin approval step
    And the UI explains that Team Folder creation is admin-configured by default
    # notes: AGENTS.md#importing-an-unmapped-team-as-a-team-folder-requires-team-folder-rights

    # notes: AGENTS.md#a-team-the-service-account-cannot-see-is-shown-as-not-importable
  @blocked
  Scenario: A team the service account cannot see is shown as not importable
    Given the Penpot team "Solo Team" is visible to the user's personal token
    But the service account has not been invited to "Solo Team"
    When the user views their Penpot teams in personal settings
    Then "Solo Team" is listed but marked as not importable
    And the UI explains the service account must be invited as "viewer" first
    And it names which of the two prerequisites is missing

  @unbuilt
  Scenario: The import surface explains that tagging a folder creates a project
    Given a Team Folder mapped to the Penpot team "Northwind"
    And a plain, untagged subfolder created directly inside it
    Then that subfolder is ordinary tolerated content — nothing happens to it
    # The confirmed, locked tolerated-content rule (§6.13), unchanged by the tag.
    And the import surface names the "penpot" tag as the way to make one a project
    # notes: AGENTS.md#the-import-surface-explains-that-tagging-a-folder-creates-a-project
