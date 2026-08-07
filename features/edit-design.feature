# Notes, decisions and history for this feature: AGENTS.md#edit-design

Feature: Editing a design
  As someone whose designs are mirrored into Nextcloud
  I want work I do in Penpot to reach the backup
  So that a "sync" file is the design as it is now, not as it was when I promoted it

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  # EDITING HAPPENS IN PENPOT, AND ONLY IN PENPOT — which is why every scenario
  # here is @in-penpot and there is no Nextcloud-side twin. A ".penpot" archive is
  # opaque nested design data; there is nothing coherent to hand-edit and no way
  # to re-import it if there were (open-with.feature: no text editor, ever). So
  # this file has one direction where its n8n and Grafana siblings have two.
  #
  # THE ARRIVAL IS NOT THE BEHAVIOUR. Nobody edits a design in order to make a
  # sync run. The `When` is the edit; that the news reaches Nextcloud at all is
  # this app's job, and belongs in the step rather than in the scenario.
  # notes: AGENTS.md#edit-design

  # @blocked — no way to edit a design's content. Penpot's `update-file` is the
  # only RPC that changes what is inside a design, and its `changes` payload is
  # unproven and reported fragile (saga §1, penpot/penpot#4180); the harness
  # creates, renames, moves and deletes designs, but cannot author in one.
  # notes: AGENTS.md#an-edit-in-penpot-reaches-the-stored-archive
  @in-penpot @blocked
  Scenario: An edit in Penpot reaches the stored archive
    Given a mirrored design "Cover" in the project "Brand"
    And "Penpot/Brand/Cover.penpot" is a "sync" design
    When the design "Cover" is edited in Penpot
    Then "Penpot/Brand/Cover.penpot" holds the design as it is now
    And "Penpot/Brand/Cover.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_revision | set             |
      | penpot_mode     | "sync"          |
      | content         | an archive      |
      | modified        | the design's    |

    # THE TABLE IS THE PROMISE, THE LINE ABOVE IT IS THE BEHAVIOUR. The archive is
    # the design as it is now — that is what an edit is for. Everything in the
    # table is what survived: the same design, in the same team, still a backup.
    # A mirror that came back as a new file standing where the old one was would
    # satisfy the first line and fail the table.
    #
    # `modified` is the design's clock rather than the sync's, so a mapped folder
    # sorted by date sorts by when designs were worked on (§C6.24). It is also the
    # half of the drift signal a rename moves without moving `revn`, which is why
    # the signal is the two joined (§5.5).

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

    # A LINK TRACKS THE EDIT WITHOUT PAYING FOR IT. The revision and the dates
    # come from a listing the sync already had; the bytes do not, because nobody
    # asked for them. That is the whole economic argument for link mode being the
    # default, stated as an end state rather than as a claim about call counts.

  # @blocked — same wall.
  @in-penpot @blocked
  Scenario: An edit to one design leaves its neighbours alone
    Given a mirrored design "Edited" in the project "Brand"
    And a mirrored design "Untouched" in the project "Brand"
    And "Penpot/Brand/Untouched.penpot" is a "sync" design
    And I note the mtime and etag of "Penpot/Brand/Untouched.penpot"
    When the design "Edited" is edited in Penpot
    Then "Penpot/Brand/Untouched.penpot" has the same mtime and etag
    And "Penpot/Brand/Untouched.penpot" holds:
      | penpot_id | the design's id |
      | content   | an archive      |

    # THE NEGATIVE THAT COSTS REAL MONEY. A sync that re-exported every design
    # whenever any one of them changed would satisfy both scenarios above and be
    # a bandwidth bill. The mtime and etag are what every desktop client polls, so
    # rewriting an unchanged mirror makes every device re-download the whole
    # mapped folder — which is why "nothing happened to it" has to be measured on
    # the two values a client can see, and is the one place this suite records a
    # before.
