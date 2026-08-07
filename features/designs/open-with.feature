# Notes, decisions and history for this feature: ../AGENTS.md#open-with

Feature: Opening a mirrored Penpot file (Open in Penpot only)
  As a Nextcloud user
  I want the one opener that makes sense for a read-only mirror
  So that I'm always sent to the live design, never to a dead-end editor

  Background:
    Given the app is connected to Penpot

  @todo
  Scenario: Open in Penpot opens the live design
    Given a mirrored ".penpot" file with a live design in Penpot
    When I choose "Open in Penpot" from its context menu
    Then Penpot opens at that design (not a download, not a text editor)

  @blocked
  Scenario: Open in Penpot is the default click
    Given a mirrored ".penpot" file with a live design in Penpot
    When I click the file in the Files app
    Then it opens in Penpot by default

  # @blocked — no browser, as everything in this file is: a context menu and the
  # glyph in it are pixels, and the harness is occ + DAV.
  # notes: ../AGENTS.md#the-open-in-penpot-glyph-is-drawn-for-a-menu
  @blocked
  Scenario: The "Open in Penpot" glyph is drawn for a menu
    Given a mirrored ".penpot" file
    When I open its context menu
    Then the "Open in Penpot" glyph is themed to the menu's own colour
    And the glyph is drawn as filled shapes, never as strokes

  @decision
  Scenario: There is no "Edit as text" action, ever
    Given a mirrored ".penpot" file
    Then its context menu has no "Open with text editor" or "Edit as text" action
    # Unlike both sibling apps, this holds for every state a file can be in —
    # there is no mode where a text-editor fallback becomes the default click.

  @todo
  Scenario: The deep link carries both the team and the file
    Given a mirrored ".penpot" file
    When I choose "Open in Penpot"
    Then the link names both the Penpot team and the design
    # Penpot's workspace route refuses to open on a file id alone — its own
    # legacy route exists to look the team up before navigating (saga §C6.7).

  @todo
  Scenario: A file pulled before the team was recorded cannot build a link
    Given a mirrored ".penpot" file carrying no "penpot_team_id"
    When I choose "Open in Penpot"
    Then nothing opens, and no error is shown
    And the next pull stamps the team, after which the link works
    # Silence beats opening a workspace with a missing team, which is an error
    # page.

  @todo
  Scenario: A file with no live design has no Penpot-specific opener
    Given a ".penpot" file with no "penpot_id" (never tracked)
    Then "Open in Penpot" is hidden from its context menu
    And the file falls back to Nextcloud's default handling for its mimetype

    # Both modes deep-link identically — the mode governs whether bytes are stored
    # locally (saga §6.22), never whether the design can be opened.
  @todo
  Scenario: Open in Penpot works the same in link and sync mode
    Given a mirrored ".penpot" file in "link" mode
    Then "Open in Penpot" is available and opens the live design
    Given a mirrored ".penpot" file in "sync" mode
    Then "Open in Penpot" is available and opens the same live design

  @todo
  Scenario: Only a sync file can be downloaded as a real archive
    Given a mirrored ".penpot" file in "sync" mode
    Then downloading it yields the real ".penpot" archive
    Given a mirrored ".penpot" file in "link" mode
    Then the app does not offer the file as a downloadable archive
    And it explains the file is a pointer, offering to switch it to "sync" mode
    # Handing a user an empty or placeholder file that looks like a design export
    # would be the same kind of quiet lie restore-design.feature exists to avoid.

  @unbuilt
  Scenario: A file whose design was deleted in Penpot says so instead of dead-linking
    Given an unmapped ".penpot" file whose Penpot original has been deleted
    When I choose "Open in Penpot"
    Then the app reports that the design no longer exists in Penpot
    And it offers to restore it, warning the restore creates a new design
    # A deleted Penpot file can never come back at its original id (saga §6.20),
    # so its deep link is permanently dead — say so rather than opening a 404.
