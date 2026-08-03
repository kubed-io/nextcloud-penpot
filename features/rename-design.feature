# Notes, decisions and history for this feature: AGENTS.md#rename-design

Feature: Renaming a mirrored Penpot design
  As a Nextcloud user
  I want renaming a design file to rename the design in Penpot, and vice versa
  So that one name means one thing on both sides
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Renaming a mirrored file renames its design in Penpot
    Given a mirrored design "Old Name" in the project "Rename Live"
    When I rename "Penpot/Rename Live/Old Name.penpot" to "New Name.penpot"
    Then Penpot project "Rename Live" holds a design named "New Name"
    And Penpot project "Rename Live" holds no design named "Old Name"
    # Penpot's name never carries the ".penpot" extension (§6.4) — the assertion
    # is on "New Name", not "New Name.penpot", and that is the whole rule.

  @todo
  Scenario: Renaming a file in Penpot renames the mirrored file on the next pull
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When the file is renamed to "New Name" in Penpot
    And a pull runs
    Then the mirrored file is renamed to "New Name.penpot"
    And its "penpot_id" metadata is unchanged

  @todo
  Scenario: A rename is picked up in both modes, without an export
    Given a mirrored ".penpot" file in "link" mode
    When the file is renamed in Penpot and a pull runs
    Then the mirrored file is renamed
    And "export-binfile" was never called to detect or apply the rename

    # notes: AGENTS.md#a-rename-is-picked-up-in-both-modes-without-an-export

  @todo
  Scenario: Renaming a mirrored file in Nextcloud renames the Penpot file
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When I rename the file to "New Name.penpot" in the Files app
    Then "rename-file" is called with the file's Penpot id and "New Name"
    And the Penpot file is named "New Name"
    And the ".penpot" extension is stripped before sending and re-added locally
    And the file's "penpot_id" is unchanged
    # notes: AGENTS.md#renaming-a-mirrored-file-in-nextcloud-renames-the-penpot-file

  @todo
  Scenario: The rename call sends the file id under the plain "id" parameter
    When a mirrored file is renamed and the rename propagates
    Then "rename-file" is called with the id under the key "id"
    And not under "file-id"
    # notes: AGENTS.md#the-rename-call-sends-the-file-id-under-the-plain-id-parameter

  @blocked
  Scenario: A propagated rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using that user's own token
    And Penpot attributes the rename to that user, not to the service account
    # notes: AGENTS.md#a-propagated-rename-is-attributed-to-the-acting-user

  @blocked
  Scenario: A propagated rename with no personal token uses the service account
    Given the user has no personal Penpot token configured
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using the service-account token
    And the user is told the change was attributed to the service account

  @todo
  Scenario: A failed propagation never reverts the user's local rename
    When the user renames a mirrored file and the Penpot call fails
    Then the Nextcloud file keeps its new name
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # Saga §6.18 rule 3 — a remote failure must never destroy local state.

    # notes: AGENTS.md#an-empty-file-name-is-refused-before-it-is-sent

  @todo
  Scenario: An empty file name is refused before it is sent
    When I try to rename a mirrored file to a name that is empty once the extension is stripped
    Then the rename is refused with an explanation
    And Penpot is never contacted

  @todo
  Scenario: A file name longer than Penpot allows is refused before it is sent
    When I try to rename a mirrored file to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  @todo
  Scenario: In nested mode, a Penpot file whose name contains a slash is skipped with a clear reason
    Given the mapping's folder mode is "nested"
    And a Penpot file named "Has/Slash"
    When the pull runs
    Then no file is created for it
    And the admin is told the file cannot be mirrored because "/" is not allowed in a file name
    And the message names the file so it can be renamed in Penpot
    And every other file in the same project is mirrored normally
    # notes: AGENTS.md#in-nested-mode-a-penpot-file-whose-name-contains-a-slash-is-skipped-with-a-clear-reason

    # notes: AGENTS.md#renaming-never-breaks-the-penpot-link

  @in-nextcloud @gesture
  Scenario: Renaming never breaks the Penpot link
    Given a mirrored design "Before" in the project "Keeps Its Id"
    When I rename "Penpot/Keeps Its Id/Before.penpot" to "After.penpot"
    Then the file "Penpot/Keeps Its Id/After.penpot" still carries its Penpot id
    And Penpot project "Keeps Its Id" holds a design named "After"

    # ── renaming something that was just created by another gesture ───────────

  @in-nextcloud @gesture
  Scenario: Renaming a design that was just copied propagates to Penpot
    Given a mirrored design "Original" in the project "Copy Then Rename"
    And I copy "Penpot/Copy Then Rename/Original.penpot" to "Penpot/Copy Then Rename/Duplicate.penpot"
    When I rename "Penpot/Copy Then Rename/Duplicate.penpot" to "Renamed Copy.penpot"
    Then Penpot project "Copy Then Rename" holds a design named "Renamed Copy"
    And Penpot project "Copy Then Rename" holds a design named "Original"
    And the files "Penpot/Copy Then Rename/Original.penpot" and "Penpot/Copy Then Rename/Renamed Copy.penpot" carry different Penpot ids
    # notes: AGENTS.md#renaming-a-design-that-was-just-copied-propagates-to-penpot

  @in-nextcloud @gesture
  Scenario: Renaming an untracked ".penpot" file is not a failure
    Given a mirrored project "Untracked Rename"
    And I upload a ".penpot" archive at "Penpot/Untracked Rename/Dragged In.penpot"
    When I rename "Penpot/Untracked Rename/Dragged In.penpot" to "Renamed Anyway.penpot"
    Then the file "Penpot/Untracked Rename/Renamed Anyway.penpot" carries no Penpot id
    And Penpot project "Untracked Rename" holds no design named "Renamed Anyway"
    # notes: AGENTS.md#renaming-an-untracked-penpot-file-is-not-a-failure
