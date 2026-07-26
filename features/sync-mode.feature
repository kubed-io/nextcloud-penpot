# The per-file "do we store the bytes?" choice.
#
# THE AXIS IS BACK, MEANING SOMETHING NEW (saga §6.22). Chapter 1 §6.1 removed
# the sync/link axis because read-only means there's only one write direction.
# That reasoning was right about WRITES and wrong about WEIGHT. In both sibling
# apps the axis means "which direction do edits flow." Here it means only:
#
#     link  — a pointer. No archive stored. Never calls export-binfile.
#     sync  — a real backup. The .penpot archive is downloaded and stored.
#
# NEITHER MODE EVER PUSHES CONTENT. Saga §6.1's read-only lock is untouched by
# this feature — "sync" here does NOT mean "two-way", it means "we keep the
# bytes." A sync file is still a read-only mirror.
#
# WHY THIS MATTERS: a .penpot export is a full ZIP with embedded binaries, not a
# JSON diff (saga Course 2). Mirroring every file in a large team as a stored
# archive would be expensive and mostly pointless — most designs don't need a
# backup in Nextcloud, they need to be findable and clickable. Command's framing:
# "links would be lightweight and never backed up in nxt, then we simply make the
# sync for our important files."
#
# LINK IS THE DEFAULT, and that is a deliberate safety property as much as a
# performance one: the expensive, bandwidth-consuming path is opt-in, so a
# newly-mapped team with 500 files costs a listing, not 500 exports.
#
# THE DEMOTION IS THE DANGEROUS DIRECTION: sync → link DELETES a stored archive.
# That's local data the user may be relying on as a backup, so it is confirmed,
# and it is the only operation in this file that can lose bytes.
#
# @todo — no lib/Service/ exists yet.

@todo
Feature: Choosing whether a mirrored file stores its archive
  As a Nextcloud user
  I want to pick which designs are backed up and which are just links
  So that important work is preserved without paying to store everything

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a subfolder

  # ── defaults ─────────────────────────────────────────────────────────────────

  Scenario: Files inherit their mapping's default mode
    Given the "Ferronescotia" mapping has default mode "link"
    When the pull runs
    Then every newly mirrored file is in "link" mode
    When the admin changes the mapping's default mode to "sync"
    And a new Penpot file appears in the team and the pull runs
    Then the new file is in "sync" mode
    And files that already existed keep the mode they had
    # Changing a default never retroactively rewrites existing files — that would
    # silently trigger a bulk download, or silently delete a pile of archives.

  # ── what each mode actually is ───────────────────────────────────────────────

  Scenario: A link file is a pointer with no stored content
    Given a mirrored ".penpot" file in "link" mode
    Then the file stores no ".penpot" archive content
    And it carries its "penpot_id" and revision metadata
    And "Open in Penpot" opens the live design
    And no export was ever performed for it

  # A pointer can't survive the gestures a real archive can, so links are
  # confined (saga §6.43). Every refusal offers the same escape: promote first.
  Scenario: A link file is confined to its own project
    Given a mirrored ".penpot" file in "link" mode in a project folder
    Then it can be moved freely within that project, including into plain subfolders
    But it cannot be moved into another project folder
    And it cannot be moved to the team root
    And it cannot be moved out of every mapping
    And it cannot be tagged as ignored
    And each refusal offers to promote it to "sync" mode first
    # Detail lives in move.feature and ignore.feature; this is the summary of
    # what the mode actually costs you.

  Scenario: Promotion lifts every link restriction at once
    Given a mirrored ".penpot" file in "link" mode
    When I promote it to "sync" mode and the archive is fetched
    Then it can be moved, ignored, and kept outside a mapping like any sync file
    # The restrictions are a property of holding no bytes, not a policy about
    # links — so acquiring bytes removes all of them together.

  Scenario: A sync file holds the real archive
    Given a mirrored ".penpot" file in "sync" mode
    Then the file holds the real ".penpot" archive downloaded from Penpot
    And the archive is a valid ZIP that opens outside Nextcloud
    And "Open in Penpot" still opens the live design
    # A sync file is a backup AND a link — never one at the expense of the other.

  # ── promotion: safe, additive ────────────────────────────────────────────────

  Scenario: Promoting a link file to sync fetches the archive
    Given a mirrored ".penpot" file in "link" mode
    When I promote the file to "sync" mode
    And the pull runs
    Then the archive is exported and downloaded
    And the file holds real ".penpot" content
    And its "penpot_id" is unchanged
    And nothing was written to Penpot

  Scenario: Promotion survives future pulls
    Given a mirrored ".penpot" file promoted to "sync" mode
    When the pull runs several times
    Then the file stays in "sync" mode
    And it is re-exported whenever its Penpot revision moves
    # Mode is stored per-file in metadata, not re-derived from the mapping.

  # ── demotion: the one lossy operation here ───────────────────────────────────

  Scenario: Demoting a sync file to link warns before deleting the archive
    Given a mirrored ".penpot" file in "sync" mode
    When I demote the file to "link" mode
    Then the app warns that the stored archive will be deleted
    And it warns that this is a local backup, not recoverable from Penpot without a new export
    And nothing is deleted until I confirm

  Scenario: Confirming a demotion deletes the archive and keeps the pointer
    Given a mirrored ".penpot" file in "sync" mode
    When I demote the file to "link" mode and confirm
    Then the stored archive content is removed
    And the file keeps its "penpot_id" and revision metadata
    And "Open in Penpot" still works
    And Penpot is never contacted
    And the design in Penpot is completely unaffected

  Scenario: Demoting an ignored file is refused
    Given a mirrored ".penpot" file in "sync" mode tagged as ignored
    When I demote the file to "link" mode
    Then the action is refused
    And the refusal explains that an ignored file's archive is the only copy this app is keeping
    # Ignore exists precisely to preserve an archive (ignore.feature). Demoting an
    # ignored file would delete the thing the ignore tag was protecting.

  # ── cost ─────────────────────────────────────────────────────────────────────

  Scenario: A team of link files costs no exports at all
    Given the "Ferronescotia" team has 100 files, all in "link" mode
    When the pull runs
    Then "export-binfile" is called 0 times
    And no archive bytes are downloaded
    And every file's name and project placement is still reconciled
    # The listing carries name, projectId, revn and modifiedAt for every file in
    # one response (saga §5.5) — which is what makes link mode nearly free.
