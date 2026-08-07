# Notes, decisions and history for this feature: AGENTS.md#lifecycle

Feature: App install lifecycle
  As a Nextcloud admin
  I want the penpot_sync app to enable, disable and uninstall cleanly
  So that installing or removing it never leaves the instance broken

  Scenario: Enabling the app
    When the admin enables the app
    Then the app should be enabled
    And the app is installed correctly
    And ".penpot" files are registered as their own file type
    # notes: AGENTS.md#enabling-the-app

  Scenario: Disabling the app
    Given the app is enabled
    When the admin disables the app
    Then the app is not enabled

  # @blocked — no app removal. The harness can enable and disable, which is what
  # `occ` offers; removing an app and reinstalling it is a store operation this
  # suite has no way to perform.
  # notes: AGENTS.md#removing-the-app
  @blocked
  Scenario: Removing the app
    Given the app is enabled
    When the admin removes the app
    Then ".penpot" files are no longer registered as their own file type
    And the mirrored design files are left where they are, with their metadata
