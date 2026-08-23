# Notes, decisions and history for this feature: ../AGENTS.md#designsview

Feature: Looking at a design file
  As someone with designs mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as designs rather than as anonymous archives

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#designsview

    # ── RULE: a mirror reads as a design, not as the archive it happens to be ─

  # @blocked — no browser. An icon in a folder listing is DOM, and the two DAV
  # scenarios below are how this harness reaches the same metadata.
  # notes: ../AGENTS.md#a-mapped-folder-shows-its-designs-as-designs
  @ui @blocked
  Scenario: A mapped folder shows its designs as designs
    Given a design file named "Brand Kit.penpot" in "Penpot/Brand"
    And a design file named "Landing Page.penpot" in "Penpot/Brand"
    When I open "Penpot/Brand" in the Files app
    Then the mapped folder shows the designs with the Penpot icon

    # ── RULE: a client can read what the app knows about the file ────────────

  # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-penpot-specific-details
  @dav
  Scenario Outline: Viewing the DAV properties on a file shows Penpot specific details
    Given a design file named "Brand Kit.penpot" in "<folder>"
    When a WebDAV client requests the file's properties
    Then the file holds:
      | penpot_id      | the design's id |
      | penpot_team_id | the team's id   |
      | penpot_mode    | <mode>          |
      | content        | <content>       |

    Examples: both modes a mapping can hold, and only one of them stores the design
      | folder         | mode      | content    |
      | Penpot/Brand   | sync      | an archive |
      | Pointers/Brand | reference | empty      |

    # The mode decides whether the bytes are here at all, so the body is asserted
    # beside the keys rather than left to a scenario of its own.

    # "link" travels the wire as "reference": the literal string "link" is
    # is_callable(), and core's PROPFIND calls it.

  # notes: ../AGENTS.md#finding-designs-by-their-mode
  @dav @todo
  Scenario: Finding designs by their mode
    Given a design file named "Brand Kit.penpot" in "Penpot/Brand"
    And a design file named "Brand Kit.penpot" in "Pointers/Brand"
    When a DAV REPORT searches for files where "nc:metadata-penpot_mode" is "sync"
    Then only the file in "Penpot/Brand" is returned
