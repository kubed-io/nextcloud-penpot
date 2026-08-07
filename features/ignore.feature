# Notes, decisions and history for this feature: AGENTS.md#ignore

Feature: Ignoring a mirrored file stops mirroring without losing it
  As a Nextcloud user
  I want to take a file out of this app's management while keeping the file
  So that I can hold on to a design's backup without it being refreshed or pruned

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

    # ── the core behaviour ───────────────────────────────────────────────────────

  @unbuilt
  Scenario: Ignoring a sync file keeps the archive and stops the mirroring
    Given a mirrored ".penpot" file in "sync" mode in the "My Stuff" subfolder
    When I apply the app's ignore tag to the file
    Then the file keeps its full ".penpot" archive content
    And the file keeps its "penpot_id"
    And Penpot is never contacted
    And the design still exists, untouched, in Penpot

  @unbuilt
  Scenario: An ignored file is skipped by every pull
    Given a mirrored ".penpot" file tagged as ignored
    When the Penpot file's "revn" increases
    And the pull runs
    Then the file is not re-exported
    And the file is not renamed, even if it was renamed in Penpot
    And the file is not moved, even if it moved project in Penpot
    And the file is not pruned, even if it was deleted in Penpot
    # Ignore means the app's hands are off, in every direction.

  @unbuilt
  Scenario: An ignored file whose Penpot original is deleted is still kept
    Given a mirrored ".penpot" file tagged as ignored
    When the underlying Penpot file is deleted in Penpot
    And the pull runs
    Then the file remains in place with its archive intact
    And it is not moved to the trash
    # notes: AGENTS.md#an-ignored-file-whose-penpot-original-is-deleted-is-still-kept

    # ── the mode restriction, and why it exists ──────────────────────────────────

  @unbuilt
  Scenario: Ignoring a link file is refused, with an offer to make it real first
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" subfolder
    When I apply the app's ignore tag to the file
    Then the action is refused
    And the refusal explains that a link file holds no archive to keep
    And the app offers to promote the file to "sync" mode first
    And the ignore tag is not left applied
    # Refusing beats silently accepting: an "ignored link" is a pointer to a
    # design nobody is tracking — it looks like a backup and is not one.

  @unbuilt
  Scenario: Demoting an ignored file is refused
    Given a mirrored ".penpot" file in "sync" mode tagged as ignored
    When I demote the file to "link" mode
    Then the action is refused
    And the refusal explains that an ignored file's archive is the only copy this app is keeping
    # notes: AGENTS.md#demoting-an-ignored-file-is-refused

  @unbuilt
  Scenario: Promoting a link file to sync, then ignoring it, works
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" subfolder
    When I promote the file to "sync" mode
    And the pull runs
    Then the file holds a real ".penpot" archive
    When I apply the app's ignore tag to the file
    Then the ignore tag is accepted
    And the file keeps its archive and stops being mirrored

    # ── reversing it ─────────────────────────────────────────────────────────────

  @unbuilt
  Scenario: Removing the ignore tag resumes mirroring
    Given a mirrored ".penpot" file tagged as ignored
    When I remove the ignore tag
    And the pull runs
    Then the file is mirrored normally again
    And it is refreshed if its Penpot revision moved while it was ignored
    And its "penpot_id" was never lost

  @unbuilt
  Scenario: Un-ignoring a file whose Penpot original was deleted offers a restore
    Given a mirrored ".penpot" file tagged as ignored
    And the underlying Penpot file was deleted in Penpot while it was ignored
    When I remove the ignore tag
    Then the app does not prune the file
    And the app offers to restore it into Penpot as a new file
    And nothing is sent to Penpot until the user confirms
    # Restore is never automatic and never silent, because a deleted Penpot file
    # cannot come back at its original id (saga §6.20) — see restore-design.feature.

    # ── the invariant ────────────────────────────────────────────────────────────

  @unbuilt
  Scenario: Ignoring never deletes anything in Penpot
    Given a mirrored ".penpot" file in "sync" mode
    When I apply the app's ignore tag
    Then no delete or destructive call is ever made against Penpot
    And the design in Penpot is completely unaffected
