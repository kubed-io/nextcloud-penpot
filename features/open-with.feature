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
# AND WHEN THE OPENER HIDES, NEXTCLOUD TAKES OVER — DELIBERATELY. With no action
# registered for it, a click falls through to core's default for the mimetype: a
# download. That is the right ending, not a consolation prize. Nothing can open
# the design any more, so the bytes on disk are the whole remaining value of the
# file — and for a "sync" mirror those bytes are the real archive, which is
# exactly the case the local backup exists for. So "hide the action" is a
# decision about what a click SHOULD do, not merely what it must not do.
#
# BUILT AS OF C6.1 — and still @todo here, for a reason that changed. It used to
# be "no src/files.js exists yet"; src/files.js now exists and registers exactly
# one action, "Open in Penpot", as the default click. What is missing is a way to
# RUN these scenarios: every one of them is a click or a context menu, and the
# integration harness is occ-only with no browser driver (the same wall
# rename.feature and admin-section.feature describe). @todo here means "not
# executable from this file", not "unimplemented".
#
# WHAT IS ASSERTED INSTEAD, and where: tests/js/files-helpers.test.js covers the
# logic these scenarios would exercise — that both modes offer the opener
# identically, that `unmapped` hides it, and the exact deep-link shape. The parts
# no unit test can reach are the registration itself and the default-click
# promotion.
#
# THE DEEP LINK IS <base>/#/workspace?file-id=<penpot_id> (saga §C6.1), read off
# a live Penpot's own route table rather than guessed — §C3.4 refused to write it
# until it could be confirmed. It keys on the file id ALONE, which is why the
# "moved out of its mapped folder" case still links: no ancestor folder is
# consulted.
#
# STILL GENUINELY UNBUILT, not merely unrunnable, and named so C6.1 is not
# credited with it:
#   - the download-refusal for a `link` file — needs a WebDAV-layer guard (the
#     siblings' LinkWriteGuardPlugin shape); today a `link` downloads as a
#     zero-byte file without comment. That is at least honest — the old JSON body
#     handed you something that looked like a design export and was not — but
#     "here is an empty file" is still not the sentence the scenario asks for.
#   - the deleted-design case is built only HALFWAY. C6.1 hides "Open in Penpot"
#     for an `unmapped` file rather than following a dead id (§6.20), which is
#     the "instead of dead-linking" half. It does not yet REPORT why, and does
#     not offer the restore. Hiding is the safe subset; the sentence the scenario
#     asks for is a later slice.

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

  @todo
  Scenario: Open in Penpot is the default click
    Given a mirrored ".penpot" file with a live design in Penpot
    When I click the file in the Files app
    Then it opens in Penpot by default

  @todo
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
    # would be the same kind of quiet lie restore.feature exists to avoid.

  @todo
  Scenario: A file whose design was deleted in Penpot says so instead of dead-linking
    Given an unmapped ".penpot" file whose Penpot original has been deleted
    When I choose "Open in Penpot"
    Then the app reports that the design no longer exists in Penpot
    And it offers to restore it, warning the restore creates a new design
    # A deleted Penpot file can never come back at its original id (saga §6.20),
    # so its deep link is permanently dead — say so rather than opening a 404.
