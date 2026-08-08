# Notes, decisions and history for this feature: ../AGENTS.md#designspurge

Feature: Purge the app's mirrored files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the ".penpot" files this app mirrored
  So that I can reset the Nextcloud side without ever touching Penpot or losing standalone files

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  @unbuilt
  Scenario: Purge deletes mirrored files but leaves Penpot and the mapping intact
    Given a mirrored ".penpot" file in the "My Stuff" subfolder
    When the admin purges the Nextcloud files
    Then no mirrored files remain in the "My Stuff" subfolder
    And the design file still exists, unchanged, in Penpot
    And the "Northwind" mapping is still configured

  @unbuilt
  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped ".penpot" file that still carries its "penpot_id"
    And I remember the unmapped file
    And a mirrored ".penpot" file in the "My Stuff" subfolder
    When the admin purges the Nextcloud files
    Then no mirrored files remain in the "My Stuff" subfolder
    And the remembered unmapped file is left in place

  @unbuilt
  Scenario: Purge keeps an untracked ".penpot" file — never the app's business
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When the admin purges the Nextcloud files
    Then that untracked file is left in place

  @unbuilt
  Scenario: A purge warns what is actually being deleted
    Given 3 mirrored files in "sync" mode and 10 in "link" mode in the "My Stuff" subfolder
    When the admin starts a purge
    Then the confirmation names how many files hold real archives
    And it explains that link files hold no content to lose
    # Purging 10 pointers and purging 3 backups are very different events.

  @blocked
  Scenario: Sync from Penpot brings a sync file back after a purge
    Given a mirrored ".penpot" file in "sync" mode in the "My Stuff" subfolder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Penpot" for the "Northwind" mapping
    Then the design file appears again as a file in the "My Stuff" subfolder
    And it is re-exported and re-downloaded from Penpot, not restored from any local backup

  @blocked
  Scenario: Sync from Penpot brings a link file back as a pointer
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" subfolder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Penpot" for the "Northwind" mapping
    Then the file appears again in the "My Stuff" subfolder as a pointer
    And no export was performed to bring it back
    # A purge of link files costs nothing to undo — there were never any bytes.
