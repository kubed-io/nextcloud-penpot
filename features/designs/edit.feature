# Notes, decisions and history for this feature: ../AGENTS.md#designsedit

Feature: Editing a design
  As someone whose designs are mirrored into Nextcloud
  I want work I do in Penpot to reach the backup
  So that a "sync" file is the design as it is now, not as it was when it was first mirrored

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  # Penpot-side only: a ".penpot" archive cannot be authored from Nextcloud.
  # @blocked throughout — the harness cannot edit a design's content.
  # notes: ../AGENTS.md#designsedit
  @in-penpot @blocked
  Scenario: An edit in Penpot reaches the stored archive
    Given a Penpot team named "Design Team" is mapped to the folder "Penpot" in "sync" mode
    And a mirrored design "Cover" in the project "Brand"
    When the design "Cover" is edited in Penpot
    Then "Penpot/Brand/Cover.penpot" holds the design as it is now
    And "Penpot/Brand/Cover.penpot" holds:
      | penpot_id       | the design's id    |
      | penpot_team_id  | the mapping's team |
      | penpot_revision | set                |
      | penpot_mode     | "sync"             |
      | content         | an archive         |
      | modified        | the design's       |

    # notes: ../AGENTS.md#an-edit-in-penpot-reaches-the-stored-archive

  # @blocked — same wall.
  @in-penpot @blocked
  Scenario: An edit in Penpot costs a link nothing but its dates
    Given a mirrored design "Sketch" in the project "Brand"
    When the design "Sketch" is edited in Penpot
    Then the design was never exported
    And "Penpot/Brand/Sketch.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_revision | set             |
      | penpot_mode     | "link"          |
      | content         | empty           |
      | modified        | the design's    |

    # notes: ../AGENTS.md#an-edit-in-penpot-costs-a-link-nothing-but-its-dates
