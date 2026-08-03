# Notes, decisions and history for this feature: AGENTS.md#uninstall

Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to Penpot
    And a folder mapped to the Penpot team "Northwind"

    # ── system cleanup (needs a live app remove — @blocked in CI) ────────────────────
  @blocked
  Scenario: Removing the app reverts the custom mimetype registration
    Given the app registered a custom mimetype for ".penpot" files on install
    When the app is removed
    Then the mimetype mapping for ".penpot" is gone from the Nextcloud config
    And the Penpot icon is removed from the core filetype icons
    And a ".penpot" file resolves to a generic archive mimetype again

    # ── data is orphaned, never deleted ───────────────────────────────────────────
  @todo
  Scenario: Disabling the app leaves the mirrored design files (and their identity) in place
    Given the mapped folder has mirrored ".penpot" files
    When the admin disables the app
    Then the ".penpot" files are still in the folder
    And each file still carries its "penpot_id" metadata

    # ── reinstall reconnects with no duplicates (the headline) ────────────────────
  @blocked
  Scenario: Re-enabling and pulling reconciles the existing files without duplicates
    Given the mapped folder has mirrored ".penpot" files
    And the admin disables and then re-enables the app
    When the admin clicks "Sync from Penpot" for the mapping
    Then each existing file is updated in place, matched by its "penpot_id"
    And no file gains a " (2)" collision-suffixed duplicate
