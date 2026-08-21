# Notes, decisions and history for this feature: ../AGENTS.md#team-mappingsync-now

Feature: Syncing one mapping from its card
  As an admin who has just mapped a team
  I want to sync that one team without touching the others
  So that a new mapping fills immediately and a busy instance is not re-walked

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  Scenario: Syncing one mapping brings its projects and designs into Nextcloud
    Given a Penpot team named "Design Team" is mapped to the folder "One Mapping"
    And the Penpot team already contains:
      | project | design     |
      | Cogs    | Gizmo      |
      | Cogs    | Doohickey  |
      | Levers  | Sprocket   |
      | Drafts  | Loose Idea |
    When the admin syncs one mapping
    Then the folder "One Mapping" carries the team's Penpot id
    And the mapped folder holds:
      | path                            | tagged |
      | One Mapping/Cogs                   | penpot |
      | One Mapping/Cogs/Gizmo.penpot      | -      |
      | One Mapping/Cogs/Doohickey.penpot  | -      |
      | One Mapping/Levers                 | penpot |
      | One Mapping/Levers/Sprocket.penpot | -      |
      | One Mapping/Loose Idea.penpot      | -      |
    And there is no node at "One Mapping/Drafts"
    And the folder "One Mapping/Cogs" carries its Penpot dates
    And "One Mapping/Cogs/Gizmo.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_revision | set             |
      | penpot_mode     | "link"          |
      | content         | empty           |
      | modified        | the design's    |
      | created         | the design's    |
    # notes: ../AGENTS.md#syncing-one-mapping-brings-its-projects-and-designs-into-nextcloud
