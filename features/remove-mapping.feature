# Removing a team mapping — the admin deletes a mapping from the list (or
# `occ penpot_sync:remove-mapping`). This is NOT the "Purge Nextcloud files"
# button (that keeps the mapping and never touches Penpot — see purge.feature).
# Removing a MAPPING tears down the connection: what happens to the files that
# were mirrored through it?
#
# A MAPPING IS A TEAM, AND THAT'S THE ONLY THING THERE IS TO REMOVE (saga §6.24).
# An earlier draft had a "remove the My Stuff project mapping" scenario. That
# operation doesn't exist and never coherently could: project subfolders are
# MIRRORED by the pull, not mapped by a human, so "removing" one would just mean
# the next pull recreates it. One mapping object, one lifecycle.
#
# GRAFANA HAS THIS FILE, N8N DOESN'T — Grafana's exists because its recycle-bin
# setting gives removing a mapping a two-path story. This app has no such
# setting: Penpot provides its own trash (saga §6.49/§6.52), and it only ever
# engages on an explicit "Delete in Penpot" action. Removing a mapping never
# deletes anything in Penpot at all, so teardown collapses to ONE rule — but the
# file is still needed, because the app DOES provision real folders that a
# removed mapping leaves behind.
#
# THE CONTRACT: every mirrored file connected to the removed mapping goes to the
# Nextcloud trash and becomes unmapped — purely local, since there is no remote
# state to reconcile. Files that were never part of the mapping are left strictly
# alone. Penpot is never contacted, at any point.
#
# MODE MATTERS FOR WHAT THE USER ACTUALLY LOSES (saga §6.22): a trashed "sync"
# file still holds its real archive, so it's recoverable content. A trashed
# "link" file was only ever a pointer — there's nothing in it to recover. The
# teardown warns about this, because "removing a mapping deleted my backups" and
# "removing a mapping deleted some pointers" are very different events.
#
# @todo — no lib/Service/MappingTeardownService exists yet.

Feature: Removing a mapping tears down the connection without ever touching Penpot
  As a Nextcloud admin
  I want removing a mapping to clean up only what it connected, via the trash
  So that I never lose data and Penpot is never contacted by a purely local action

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  @unbuilt
  Scenario: Removing the team mapping trashes its mirrored files and leaves standalone files alone
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And an untracked standalone ".penpot" file also sitting in the "My Stuff" folder
    When the admin removes the "Northwind" mapping
    Then the mirrored file is moved to the Nextcloud trash
    And the mirrored file becomes "unmapped"
    And the standalone file is left in place, untouched
    And the "Northwind" mapping is no longer configured
    And Penpot is never contacted by this action
    And the design still exists, unchanged, in Penpot

  @decision
  Scenario: There is no project mapping to remove
    Given the "Northwind" mapping exists with several mirrored project folders
    Then no individual project folder can be unmapped
    And the only teardown available is removing the team mapping itself
    # Project folders exist because the pull created them (saga §6.24).

  @todo
  Scenario: Removing a mapping warns about what is actually being trashed
    Given 3 mirrored files in "sync" mode and 10 in "link" mode under the mapping
    When the admin removes the "Northwind" mapping
    Then the confirmation names how many files hold real archives
    And it explains that link files hold no content to lose
    # Don't-lose-data starts with telling the user what's at stake.

  @todo
  Scenario: Trashed mirrored files keep their identity so they can reconnect
    Given a mirrored ".penpot" file in the "My Stuff" folder
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
    # than duplicated — the same id-matching guarantee uninstall.feature relies on.

  @todo
  Scenario: An ignored file under a removed mapping is still trashed, not destroyed
    Given a mirrored ".penpot" file tagged as ignored in the "My Stuff" folder
    When the admin removes the "Northwind" mapping
    Then the file is moved to the trash with its archive intact
    And it is recoverable from the trash
    # Ignore protects a file from the PULL (ignore.feature). It doesn't exempt it
    # from an explicit admin teardown — but trash, never destroy, still holds.

  @todo
  Scenario: Removing a mapping never contacts Penpot, even for cleanup
    Given the "Northwind" mapping with mirrored files
    When the admin removes the mapping
    Then no request of any kind is made to Penpot
    And "delete-file" is never called
    And no webhook, project, or design is deleted on the Penpot side
    # Penpot deletion only ever happens on an explicit "Delete in Penpot" action
    # (delete-project.feature). Tearing down a mapping is a purely Nextcloud-side act.
