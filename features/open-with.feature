# "Open with" — the opener(s) offered for a mirrored ".penpot" file.
#
# RADICALLY SIMPLER THAN BOTH SIBLINGS, and for a specific, locked reason (saga
# §6.1): there is no "Edit as text" action AT ALL. Both n8n and Grafana offer a
# raw-JSON text editor as a second opener because their files hold editable
# content that can meaningfully round-trip through a hand-edit + save + push. A
# `.penpot` file is an opaque ZIP of nested design-shape JSON (saga Course 2) —
# there is no sane way to hand-edit it and re-import it coherently, so this app
# never offers a text-editor opener, not even for unmapped/untracked files (both
# siblings default UNMAPPED files to the text editor as their opener; Penpot
# Sync has no equivalent because there's nothing editable to fall back to).
#
# ONE OPENER, AND THE MODE AXIS DOESN'T CHANGE IT. This app does have a
# sync-vs-link mode (saga §6.22 — an earlier draft of this header said it didn't),
# but the mode governs whether the ARCHIVE is stored locally, never whether the
# design can be opened. Both siblings' default-click table has a row per mode
# because their modes change what a click means; here every mirrored file that
# carries a "penpot_id" gets exactly one action regardless of mode: "Open in
# Penpot", a deep link to the live design.
#
# WHERE MODE DOES SHOW UP: downloading. A "sync" file hands you a real .penpot
# archive; a "link" file has no bytes to hand over, so the app says so rather
# than serving an empty placeholder that looks like a design export.
#
# A file with no "penpot_id" (never tracked) has no Penpot-specific opener at
# all; it falls through to whatever Nextcloud does with a generic archive. A file
# whose design was DELETED in Penpot is a third case — it has an id, but that id
# is permanently dead (saga §6.20), so the opener reports that instead of
# following a link it knows is broken.
#
# @todo — no src/files.js exists yet.

@todo
Feature: Opening a mirrored Penpot file (Open in Penpot only)
  As a Nextcloud user
  I want the one opener that makes sense for a read-only mirror
  So that I'm always sent to the live design, never to a dead-end editor

  Background:
    Given the app is connected to Penpot

  Scenario: Open in Penpot opens the live design
    Given a mirrored ".penpot" file with a live design in Penpot
    When I choose "Open in Penpot" from its context menu
    Then Penpot opens at that design (not a download, not a text editor)

  Scenario: Open in Penpot is the default click
    Given a mirrored ".penpot" file with a live design in Penpot
    When I click the file in the Files app
    Then it opens in Penpot by default

  Scenario: There is no "Edit as text" action, ever
    Given a mirrored ".penpot" file
    Then its context menu has no "Open with text editor" or "Edit as text" action
    # Unlike both sibling apps, this holds for every state a file can be in —
    # there is no mode where a text-editor fallback becomes the default click.

  Scenario: A file with no live design has no Penpot-specific opener
    Given a ".penpot" file with no "penpot_id" (never tracked)
    Then "Open in Penpot" is hidden from its context menu
    And the file falls back to Nextcloud's default handling for its mimetype

  # Both modes deep-link identically — the mode governs whether bytes are stored
  # locally (saga §6.22), never whether the design can be opened.
  Scenario: Open in Penpot works the same in link and sync mode
    Given a mirrored ".penpot" file in "link" mode
    Then "Open in Penpot" is available and opens the live design
    Given a mirrored ".penpot" file in "sync" mode
    Then "Open in Penpot" is available and opens the same live design

  Scenario: Only a sync file can be downloaded as a real archive
    Given a mirrored ".penpot" file in "sync" mode
    Then downloading it yields the real ".penpot" archive
    Given a mirrored ".penpot" file in "link" mode
    Then the app does not offer the file as a downloadable archive
    And it explains the file is a pointer, offering to switch it to "sync" mode
    # Handing a user an empty or placeholder file that looks like a design export
    # would be the same kind of quiet lie restore.feature exists to avoid.

  Scenario: A file whose design was deleted in Penpot says so instead of dead-linking
    Given an unmapped ".penpot" file whose Penpot original has been deleted
    When I choose "Open in Penpot"
    Then the app reports that the design no longer exists in Penpot
    And it offers to restore it, warning the restore creates a new design
    # A deleted Penpot file can never come back at its original id (saga §6.20),
    # so its deep link is permanently dead — say so rather than opening a 404.
