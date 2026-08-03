# Notes, decisions and history for this feature: AGENTS.md#copy-design

Feature: Copying a mirrored design
  As a Nextcloud user
  I want a copied design file to become a real new design in Penpot
  So that duplicating work in Files duplicates it where the work actually lives
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Copying in place creates a second design in the same project
    Given a mirrored design "Original" in the project "Copy Here"
    When I copy "Penpot/Copy Here/Original.penpot" to "Penpot/Copy Here/Original copy.penpot"
    Then the file "Penpot/Copy Here/Original copy.penpot" carries a Penpot id
    And the files "Penpot/Copy Here/Original.penpot" and "Penpot/Copy Here/Original copy.penpot" carry different Penpot ids
    And Penpot project "Copy Here" holds a design named "Original copy"
    # Different ids is the load-bearing one: two files claiming a single design
    # is the ambiguity that made the old inert-copy rule necessary at all.

    # notes: AGENTS.md#copying-a-penpot-file-outside-every-mapping-never-contacts-penpot
  @in-nextcloud @gesture
  Scenario: Copying a ".penpot" file outside every mapping never contacts Penpot
    Given a mirrored project "Bystanders"
    And I upload a ".penpot" archive at "Loose Design.penpot"
    When I copy "Loose Design.penpot" to "Loose Design copy.penpot"
    Then the file "Loose Design copy.penpot" carries no Penpot id
    And Penpot project "Bystanders" holds no design named "Loose Design copy"

  @in-nextcloud @gesture
  Scenario: Copying up to the team root creates the design in Drafts
    Given a mirrored design "Promote Me" in the project "Copy Up"
    When I copy "Penpot/Copy Up/Promote Me.penpot" to "Penpot/Promote Me copy.penpot"
    Then the file "Penpot/Promote Me copy.penpot" carries a Penpot id
    And Penpot project "Copy Up" holds no design named "Promote Me copy"

  @in-nextcloud @gesture
  Scenario: A copy can be renamed immediately, because it was tracked
    Given a mirrored design "Before" in the project "Chain"
    And I copy "Penpot/Chain/Before.penpot" to "Penpot/Chain/Before copy.penpot"
    When I rename "Penpot/Chain/Before copy.penpot" to "After.penpot"
    Then Penpot project "Chain" holds a design named "After"
    And Penpot project "Chain" holds no design named "Before copy"
    And Penpot project "Chain" holds a design named "Before"
    # notes: AGENTS.md#a-copy-can-be-renamed-immediately-because-it-was-tracked

    # ── the two gestures, which are the same feature ──────────────────────────

  @todo
  Scenario: Copying inside the same folder duplicates the design in place
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file within that folder
    Then "duplicate-file" is called with the original's "penpot_id"
    And the new design is created in the "My Stuff" project
    And "move-files" is never called, because the project did not change
    And the copy carries the NEW design's "penpot_id", never the original's
    And the original keeps its own id and is completely unaffected

  @todo
  Scenario: Copying up to the team root duplicates it, then moves it to Drafts
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file to the team folder root
    Then "duplicate-file" is called with the original's "penpot_id"
    And "move-files" then moves the new design into that team's Drafts project
    And the copy carries the NEW design's "penpot_id"
    # Drafts is a state, not a folder (§6.35) — the team root resolves to the
    # team's default project, which is a real project id like any other.

  @todo
  Scenario: Copying into another project folder lands it in that project
    Given a second mirrored project folder "Client Work"
    And a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file into "Client Work"
    Then the new design is moved into the "Client Work" project
    And the copy carries the NEW design's "penpot_id"

  @todo
  Scenario: Copying into a plain subfolder is still the same project
    Given a plain subfolder "wip" inside the "My Stuff" folder
    When I copy a mirrored ".penpot" file into "wip"
    Then the new design is created in the "My Stuff" project
    And "move-files" is never called
    # Nearest-ancestor at any depth (§6.29): a plain subfolder carries no project
    # id, so the walk keeps going up and finds "My Stuff".

    # notes: AGENTS.md#a-copy-is-tracked-the-moment-it-exists-so-the-next-action-works

  @todo
  Scenario: A copy is tracked the moment it exists, so the next action works
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file within that folder
    Then the copy carries a "penpot_id" immediately
    And renaming the copy straight away renames its design in Penpot

  @todo
  Scenario: Copying to the team root creates the design in Drafts
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file up one level, to the mapped team folder itself
    Then a new design appears in that team's Drafts in Penpot
    And the copy in Nextcloud carries that new design's id
    # notes: AGENTS.md#copying-to-the-team-root-creates-the-design-in-drafts

  @todo
  Scenario: A copy that cannot be tracked says so rather than looking finished
    Given a mirrored ".penpot" file
    When I copy it and Penpot cannot be reached
    Then the failure is logged with the file and the design it came from
    And the copy carries no "penpot_id"
    # notes: AGENTS.md#a-copy-that-cannot-be-tracked-says-so-rather-than-looking-finished

    # ── the name ──────────────────────────────────────────────────────────────

  @todo
  Scenario: The new design is named after the copy, not the original
    Given a mirrored ".penpot" file named "Login screen.penpot"
    When I copy it and Nextcloud names the copy "Login screen (copy).penpot"
    Then the new design in Penpot is named "Login screen (copy)"
    And the ".penpot" extension is never part of the Penpot name

  @todo
  Scenario: An over-long name is truncated rather than refused
    Given a mirrored ".penpot" file whose copy would exceed 250 characters
    When I copy it
    Then the name sent to Penpot is truncated to 250 characters
    And the copy is created rather than skipped
    # Penpot's limit is a schema max (§C6.8). Losing the tail of a name is a
    # smaller harm than refusing to copy the file at all.

    # ── mode is not a special case ────────────────────────────────────────────

  @todo
  Scenario: A link file copies exactly like a sync file
    Given a mirrored ".penpot" file in "link" mode, holding no bytes
    When I copy the file within its folder
    Then "duplicate-file" is called and a real new design is created
    And no export is ever performed
    # notes: AGENTS.md#a-link-file-copies-exactly-like-a-sync-file

  @todo
  Scenario: A sync copy keeps its archive and is a valid file on its own
    Given a mirrored ".penpot" file in "sync" mode
    When I copy the file within its folder
    Then the copy holds the full ".penpot" archive content
    And the copy is a valid ZIP that opens outside Nextcloud
    # notes: AGENTS.md#a-sync-copy-keeps-its-archive-and-is-a-valid-file-on-its-own

    # ── where nothing is created ──────────────────────────────────────────────

  @unbuilt
  Scenario: Copying outside every mapping creates nothing in Penpot
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file to a folder with no Penpot ancestor
    Then "duplicate-file" is never called
    And the copy keeps the original's "penpot_id" as a historical record
    And the copy is "unmapped" — no pull will ever refresh it
    # notes: AGENTS.md#copying-outside-every-mapping-creates-nothing-in-penpot

  @todo
  Scenario: Copying an untracked ".penpot" file changes nothing
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I copy the file anywhere
    Then the copy also has no "penpot_id"
    And Penpot is never contacted

  @todo
  Scenario: The pull's own writes never look like a copy
    Given a pull is running
    When it writes a mirror file
    Then no duplicate is created in Penpot
    # The SyncGuard fences the pull out of the write-back listeners, exactly as
    # it does for rename and move.

    # ── failure ───────────────────────────────────────────────────────────────

  @todo
  Scenario: A failed duplicate leaves the Nextcloud copy standing
    Given a mirrored ".penpot" file
    When I copy it and "duplicate-file" fails
    Then the Nextcloud copy still exists with its content intact
    And the copy carries no "penpot_id" rather than the original's
    And the failure is reported
    # notes: AGENTS.md#a-failed-duplicate-leaves-the-nextcloud-copy-standing

  @todo
  Scenario: Exactly one file per design id under a project, always
    Given a mirrored ".penpot" file and a copy of it in the same project
    When the pull runs
    Then each file is refreshed against its own design
    And neither file is renamed, moved, or pruned because of the other
    # notes: AGENTS.md#exactly-one-file-per-design-id-under-a-project-always

    # ── folders are still refused ─────────────────────────────────────────────

  @in-nextcloud @gesture @unbuilt
  Scenario: Copying a design across two mappings makes a new design in the destination team
    Given the user has a personal project folder "Sketches" holding a design
    And a mapped team with a project folder "Client Work"
    When the user copies the design into "Client Work"
    Then a NEW design exists in "Client Work", with its own id
    And the original is untouched in the personal project
    # notes: AGENTS.md#copying-a-design-across-two-mappings-makes-a-new-design-in-the-destination-team

  # notes: AGENTS.md#a-design-duplicated-in-penpot-is-mirrored-like-any-other-new-design

  @in-penpot @todo
  Scenario: A design duplicated in Penpot is mirrored like any other new design
    Given a mirrored design "Original" in the project "Shared Work"
    And the design "Original" is duplicated in Penpot
    When the team is mirrored again
    Then the file "Penpot/Shared Work/Original (copy).penpot" carries a Penpot id
    And the files "Penpot/Shared Work/Original.penpot" and "Penpot/Shared Work/Original (copy).penpot" carry different Penpot ids

  @in-penpot @todo
  Scenario: A duplicate made in Penpot inherits the mapping's mode, not the original's
    Given a mirrored design "Original" in the project "Shared Work"
    And "Penpot/Shared Work/Original.penpot" is a "sync" design
    And the design "Original" is duplicated in Penpot
    When the team is mirrored again
    Then the file "Penpot/Shared Work/Original (copy).penpot" is in "link" mode
    # notes: AGENTS.md#a-duplicate-made-in-penpot-inherits-the-mappings-mode-not-the-originals
