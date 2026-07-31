# Purge — an admin-only button beside "Sync from Penpot" and "Test connection"
# (also `occ penpot_sync:purge`) that removes the mirrored ".penpot" files THIS
# APP created and nothing else. It deletes every mirrored file across all
# mappings, and:
#   - never contacts Penpot at all (there is nothing to guard against pushing —
#     unlike both siblings, there is no SyncGuard-style "don't mirror this
#     delete back out" concern, because there is no writeback direction to
#     guard, saga §6.1);
#   - leaves the mappings configured;
#   - leaves the custom mimetype registration alone (that is uninstall's job).
#
# THE IGNORE MARKER IS PRESERVED, SAME AS BOTH SIBLINGS. An earlier draft of this
# header claimed Penpot Sync had no ignore mechanism, reasoning from saga §6.3
# (Penpot's API has zero tag support). That conflated the two sides: the ignore
# marker is a NEXTCLOUD system tag, and Nextcloud has tags regardless of what
# Penpot offers. §6.23 established it, and purge must respect it — a purge that
# deleted ignored files would destroy the one thing the tag exists to protect.
#
# WHAT PURGE MUST REASON ABOUT: mirrored (delete), unmapped (keep), untracked
# (keep), ignored (keep). And within "mirrored", the MODE matters for what the
# user actually loses — a "sync" file holds a real archive, a "link" file holds
# nothing (saga §6.22), so the confirmation says which.
#
# Driven headlessly through `occ penpot_sync:purge`.
# Two intended flows: purge → "Sync from Penpot" (everything reappears, since
# Penpot's copy was never touched), and purge → uninstall (Nextcloud looks like
# the app was never there).
#
# @todo — no lib/Command/Purge exists yet.

Feature: Purge the app's mirrored files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the ".penpot" files this app mirrored
  So that I can reset the Nextcloud side without ever touching Penpot or losing standalone files

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  @todo
  Scenario: Purge deletes mirrored files but leaves Penpot and the mapping intact
    Given a mirrored ".penpot" file in the "My Stuff" subfolder
    When the admin purges the Nextcloud files
    Then no mirrored files remain in the "My Stuff" subfolder
    And the design file still exists, unchanged, in Penpot
    And the "Northwind" mapping is still configured

  @todo
  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped ".penpot" file that still carries its "penpot_id"
    And I remember the unmapped file
    And a mirrored ".penpot" file in the "My Stuff" subfolder
    When the admin purges the Nextcloud files
    Then no mirrored files remain in the "My Stuff" subfolder
    And the remembered unmapped file is left in place

  @todo
  Scenario: Purge keeps an untracked ".penpot" file — never the app's business
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When the admin purges the Nextcloud files
    Then that untracked file is left in place

  @todo
  Scenario: Purge keeps an ignored file — the ignore tag is a keep request
    Given a mirrored ".penpot" file tagged as ignored in the "My Stuff" subfolder
    When the admin purges the Nextcloud files
    Then the ignored file is left in place with its archive intact
    # Ignore exists precisely to say "this archive is mine now" (ignore.feature).
    # A purge that deleted ignored files would defeat the tag's only purpose.

  @todo
  Scenario: A purge warns what is actually being deleted
    Given 3 mirrored files in "sync" mode and 10 in "link" mode in the "My Stuff" subfolder
    When the admin starts a purge
    Then the confirmation names how many files hold real archives
    And it explains that link files hold no content to lose
    # Purging 10 pointers and purging 3 backups are very different events.

  @todo
  Scenario: Sync from Penpot brings a sync file back after a purge
    Given a mirrored ".penpot" file in "sync" mode in the "My Stuff" subfolder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Penpot" for the "Northwind" mapping
    Then the design file appears again as a file in the "My Stuff" subfolder
    And it is re-exported and re-downloaded from Penpot, not restored from any local backup

  @todo
  Scenario: Sync from Penpot brings a link file back as a pointer
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" subfolder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Penpot" for the "Northwind" mapping
    Then the file appears again in the "My Stuff" subfolder as a pointer
    And no export was performed to bring it back
    # A purge of link files costs nothing to undo — there were never any bytes.
