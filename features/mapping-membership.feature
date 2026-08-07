# Notes, decisions and history for this feature: AGENTS.md#mapping-membership

Feature: Membership is the nearest ancestor folder carrying a Penpot id
  As a Nextcloud admin
  I want membership derived by walking up the folder tree
  So that Nextcloud can nest freely while Penpot stays flat

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

    # ── the core lookup ──────────────────────────────────────────────────────────

  @in-nextcloud @occ
  Scenario: A file's project is the nearest ancestor folder carrying a project id
    Given a mirrored design "Direct" in the project "Nearest"
    Then "Penpot/Nearest/Direct.penpot" resolves to the project "Nearest"
    And resolving "Penpot/Nearest/Direct.penpot" reports the team
    And the file "Penpot/Nearest/Direct.penpot" stores no copy of its project

  @in-nextcloud @gesture
  Scenario: A file nested deeper inside a project folder still belongs to that project
    Given a mirrored design "Deep" in the project "Nested Deep"
    And I create a folder at "Penpot/Nested Deep/wip"
    When I move "Penpot/Nested Deep/Deep.penpot" to "Penpot/Nested Deep/wip/Deep.penpot"
    Then "Penpot/Nested Deep/wip/Deep.penpot" resolves to the project "Nested Deep"
    And Penpot project "Nested Deep" holds a design named "Deep"
    # notes: AGENTS.md#a-file-nested-deeper-inside-a-project-folder-still-belongs-to-that-project

  @in-nextcloud @gesture
  Scenario: The nearest project id wins when project folders are nested
    Given a mirrored design "Outer Design" in the project "Outer"
    And a mirrored project "Inner"
    And I create a new design file at "Penpot/Inner/Inner Design.penpot"
    When I move "Penpot/Inner" to "Penpot/Outer/Inner"
    Then "Penpot/Outer/Inner/Inner Design.penpot" resolves to the project "Inner"
    And "Penpot/Outer/Outer Design.penpot" resolves to the project "Outer"
    # notes: AGENTS.md#the-nearest-project-id-wins-when-project-folders-are-nested

  @in-nextcloud @gesture
  Scenario: Project folders can be grouped under ordinary Nextcloud folders
    Given a mirrored design "Grouped" in the project "Grouped Project"
    And I create a folder at "Penpot/Clients"
    When I move "Penpot/Grouped Project" to "Penpot/Clients/Grouped Project"
    Then "Penpot/Clients/Grouped Project/Grouped.penpot" resolves to the project "Grouped Project"
    And resolving "Penpot/Clients/Grouped Project/Grouped.penpot" reports the team
    And Penpot holds no project named "Clients"
    # The team is found FURTHER UP, past a folder Penpot has no concept of.

  @in-nextcloud @gesture
  Scenario: A file with no project-id ancestor belongs to no mapping
    Given I create a folder at "Outside Everything"
    When I create a new design file at "Outside Everything/Loose.penpot"
    Then "Outside Everything/Loose.penpot" resolves to no Penpot mapping at all
    And the file "Outside Everything/Loose.penpot" carries no Penpot id
    # Untracked: no id, no mapping. A file that HAS an id and no mapping is the
    # separate "unmapped" state — see move-design.feature.

    # notes: AGENTS.md#a-file-at-the-mapped-folders-root-is-in-that-teams-drafts

  @in-nextcloud @gesture
  Scenario: A file at the mapped folder's root is in that team's Drafts
    When I create a new design file at "Penpot/At The Root.penpot"
    Then "Penpot/At The Root.penpot" is in the team's Drafts
    And the file "Penpot/At The Root.penpot" carries a Penpot id

  @in-nextcloud @gesture
  Scenario: A file in any plain folder under a team is also in Drafts
    Given I create a folder at "Penpot/Inbox"
    And I create a folder at "Penpot/Inbox/2026"
    When I create a new design file at "Penpot/Inbox/2026/Filed By Hand.penpot"
    Then "Penpot/Inbox/2026/Filed By Hand.penpot" is in the team's Drafts
    # notes: AGENTS.md#a-file-in-any-plain-folder-under-a-team-is-also-in-drafts

  @in-penpot @occ
  Scenario: No folder is ever created to represent Drafts
    When the team is mirrored again
    Then no folder named "Drafts" exists under the mapped folder
    # Mirroring Drafts as a folder would make a design appear to be in two places
    # at once — at the team root AND inside a Drafts folder.

  @in-nextcloud @occ
  Scenario: A folder opted in by tag resolves exactly like a mirrored one
    Given I create a folder at "Penpot/Opted In"
    And the folder "Penpot/Opted In" has been tagged "penpot"
    When I create a new design file at "Penpot/Opted In/After The Tag.penpot"
    Then "Penpot/Opted In/After The Tag.penpot" resolves to the project "Opted In"
    # notes: AGENTS.md#a-folder-opted-in-by-tag-resolves-exactly-like-a-mirrored-one

    # ── team resolution, and the one exception ───────────────────────────────────

  @todo
  Scenario: A project folder's team is the nearest ancestor carrying a team id
    Given the "My Stuff" project folder is nested two levels deep inside the Team Folder
    Then it still resolves to the "Northwind" team
    And the depth between them is irrelevant to the lookup

  @blocked
  Scenario: A personal project folder has no team ancestor, and that is valid
    Given the user has a personal Penpot token configured
    And a personal project folder mounted at the root of the user's home
    Then the folder carries a Penpot project id but has no team-id ancestor
    And it resolves as a personal project, not as a broken mapping
    And files inside it belong to that project normally
    # notes: AGENTS.md#a-personal-project-folder-has-no-team-ancestor-and-that-is-valid

    # ── tolerated content ────────────────────────────────────────────────────────

  @in-nextcloud @occ
  Scenario: Non-Penpot content inside a project folder is left alone
    Given a mirrored design "Managed" in the project "Mixed Contents"
    And I create an unrelated file at "Penpot/Mixed Contents/notes.txt"
    When the team is mirrored again
    Then the file "Penpot/Mixed Contents/notes.txt" is still there and untouched
    And the file "Penpot/Mixed Contents/notes.txt" carries no Penpot id
    # Pruning keys on metadata, never on file extension or folder contents.

    # ── the ambiguity free nesting introduces ────────────────────────────────────

  @unbuilt
  Scenario: Two folders carrying the same project id is a reported conflict
    Given two different folders both carry the Penpot project id for "My Stuff"
    Then any file inside either folder still resolves unambiguously to "My Stuff"
    But the app reports the duplicate so an admin can resolve it
    And the pull writes new files into only one of them, deterministically
    # notes: AGENTS.md#two-folders-carrying-the-same-project-id-is-a-reported-conflict
