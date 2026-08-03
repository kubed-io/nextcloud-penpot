# Notes, decisions and history for this feature: AGENTS.md#sync-mode

Feature: Choosing whether a mirrored file stores its archive
  As a Nextcloud user
  I want to pick which designs are backed up and which are just links
  So that important work is preserved without paying to store everything

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a subfolder

    # ── defaults ─────────────────────────────────────────────────────────────────

  @todo @admin @occ
  Scenario: Files inherit their mapping's default mode
    Given the "Northwind" mapping has default mode "link"
    When the pull runs
    Then every newly mirrored file is in "link" mode
    When the admin changes the mapping's default mode to "sync"
    And a new Penpot file appears in the team and the pull runs
    Then the new file is in "sync" mode
    And files that already existed keep the mode they had
    # Changing a default never retroactively rewrites existing files — that would
    # silently trigger a bulk download, or silently delete a pile of archives.

    # ── what each mode actually is ───────────────────────────────────────────────

  @todo @admin @occ
  Scenario: A link file is a pointer with no stored content
    Given a mirrored ".penpot" file in "link" mode
    Then the file stores no ".penpot" archive content
    And it carries its "penpot_id" and revision metadata
    And "Open in Penpot" opens the live design
    And no export was ever performed for it

    # A pointer can't survive the gestures a real archive can, so links are
    # confined (saga §6.43). Every refusal offers the same escape: promote first.
  @todo @admin @occ
  Scenario: A link file is confined to its own project
    Given a mirrored ".penpot" file in "link" mode in a project folder
    Then it can be moved freely within that project, including into plain subfolders
    But it cannot be moved into another project folder
    And it cannot be moved to the team root
    And it cannot be moved out of every mapping
    And it cannot be tagged as ignored
    And each refusal offers to promote it to "sync" mode first
    # Detail lives in move-design.feature and ignore.feature; this is the summary of
    # what the mode actually costs you.

  @todo @admin @occ
  Scenario: Promotion lifts every link restriction at once
    Given a mirrored ".penpot" file in "link" mode
    When I promote it to "sync" mode and the archive is fetched
    Then it can be moved, ignored, and kept outside a mapping like any sync file
    # The restrictions are a property of holding no bytes, not a policy about
    # links — so acquiring bytes removes all of them together.

  @todo @admin @occ
  Scenario: A sync file holds the real archive
    Given a mirrored ".penpot" file in "sync" mode
    Then the file holds the real ".penpot" archive downloaded from Penpot
    And the archive is a valid ZIP that opens outside Nextcloud
    And "Open in Penpot" still opens the live design
    # A sync file is a backup AND a link — never one at the expense of the other.

    # ── promotion: safe, additive ────────────────────────────────────────────────

  @todo @admin @occ
  Scenario: Promoting a link file to sync fetches the archive
    Given a mirrored ".penpot" file in "link" mode
    When I promote the file to "sync" mode
    And the pull runs
    Then the archive is exported and downloaded
    And the file holds real ".penpot" content
    And its "penpot_id" is unchanged
    And nothing was written to Penpot

  @todo @admin @occ
  Scenario: Promotion survives future pulls
    Given a mirrored ".penpot" file promoted to "sync" mode
    When the pull runs several times
    Then the file stays in "sync" mode
    And it is re-exported whenever its Penpot revision moves
    # Mode is stored per-file in metadata, not re-derived from the mapping.

    # ── demotion: the one lossy operation here ───────────────────────────────────

  @todo @admin @occ
  Scenario: Demoting a sync file to link warns before deleting the archive
    Given a mirrored ".penpot" file in "sync" mode
    When I demote the file to "link" mode
    Then the app warns that the stored archive will be deleted
    And it warns that this is a local backup, not recoverable from Penpot without a new export
    And nothing is deleted until I confirm

  @todo @admin @occ
  Scenario: Confirming a demotion deletes the archive and keeps the pointer
    Given a mirrored ".penpot" file in "sync" mode
    When I demote the file to "link" mode and confirm
    Then the stored archive content is removed
    And the file keeps its "penpot_id" and revision metadata
    And "Open in Penpot" still works
    And Penpot is never contacted
    And the design in Penpot is completely unaffected

  @todo @admin @occ
  Scenario: Demoting an ignored file is refused
    Given a mirrored ".penpot" file in "sync" mode tagged as ignored
    When I demote the file to "link" mode
    Then the action is refused
    And the refusal explains that an ignored file's archive is the only copy this app is keeping
    # Ignore exists precisely to preserve an archive (ignore.feature). Demoting an
    # ignored file would delete the thing the ignore tag was protecting.

    # ── cost ─────────────────────────────────────────────────────────────────────

  @todo @admin @occ
  Scenario: A team of link files costs no exports at all
    Given the "Northwind" team has 100 files, all in "link" mode
    When the pull runs
    Then "export-binfile" is called 0 times
    And no archive bytes are downloaded
    And every file's name and project placement is still reconciled
    # The listing carries name, projectId, revn and modifiedAt for every file in
    # one response (saga §5.5) — which is what makes link mode nearly free.

    # ── what a "link" actually holds (saga §C6.6) ─────────────────────────────

  @todo @admin @occ
  Scenario: A link file holds nothing at all
    Given a mirrored ".penpot" file in "link" mode
    Then the file is zero bytes
    And "occ penpot_sync:status" reports its content as "empty"
    # notes: AGENTS.md#a-link-file-holds-nothing-at-all

  @todo @admin @occ
  Scenario: A link is never a small placeholder archive
    Given a mirrored ".penpot" file in "link" mode
    Then it is not a ZIP, empty or otherwise
    # notes: AGENTS.md#a-link-is-never-a-small-placeholder-archive

  @todo @admin @occ
  Scenario: A leftover body from an older version is truncated by the next pull
    Given a "link" file still holding a JSON pointer body from an earlier version
    When the pull runs
    Then the file is emptied
    And "occ penpot_sync:status" reported it as "pointer" until that happened
    # notes: AGENTS.md#a-leftover-body-from-an-older-version-is-truncated-by-the-next-pull

  @todo @admin @occ
  Scenario: An already-empty link is left strictly alone
    Given a mirrored ".penpot" file in "link" mode that is already empty
    When the pull runs
    Then the file's modification time and etag are unchanged
    # Rewriting it would be a no-op in content and a very real one in metadata:
    # every desktop client would re-download every link file after every pull.

  # ── personal projects are not a third mode ──────────────────────────────────

  @unbuilt
  Scenario: Personal projects support the same link and sync modes
    Given a personal project folder with mirrored files
    Then each file is in "link" or "sync" mode exactly as a team file would be
    And promoting or demoting a personal file behaves identically
    # Nothing about personal projects changes the storage model (sync-mode.feature).
