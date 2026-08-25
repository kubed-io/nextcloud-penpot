# Notes, decisions and history for this feature: ../AGENTS.md#designscreate

Feature: Creating a design
  As a Nextcloud user
  I want a design I make on either side to exist on both
  So that I can author designs without opening the Penpot UI

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And the following items in the mappings:
      | path              | kind         |
      | /Penpot/Make Here | project      |
      | /Shared/Quarterly | project      |
      | /Pointers/Nested  | project      |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a new design belongs to the nearest project, or to Drafts ───────
    # notes: ../AGENTS.md#a-design-created-under-the-team-but-not-under-a-project-is-a-draft
    # the archive is empty on create because a scheduled sync will eventually fill it in

  @in-nextcloud @gesture
  Scenario Outline: Create a design in a mapped folder
    When I create a new design in "<folder>"
    Then a matching design is created in Penpot
    And the design is named after the file, in the "<project>" Penpot project
    And "<folder>/New design.penpot" holds:
      | penpot_id       | the design's id    |
      | penpot_team_id  | the mapping's team |
      | penpot_mode     | "sync"             |
      | penpot_revision | set                |
      | content         | an archive         |

    Examples: the nearest project ancestor decides, at either storage kind
      | folder                | project   |
      | Penpot/Make Here      | Make Here |
      | Penpot/Make Here/wip  | Make Here |
      | Shared/Quarterly      | Quarterly |

    Examples: and at the mapping root, which IS the team's Drafts
      | folder | project |
      | Penpot | Drafts  |

    # Drafts is a STATE, not a folder: the file stays where it was made, and only
    # Penpot's side of it differs — and the ROOT is the only folder it applies to.

    # ── RULE: a design made in Penpot arrives as a file ───────────────────────
    # notes: ../AGENTS.md#a-newly-created-design-is-born-in-its-mappings-mode

  @in-penpot @gesture
  Scenario Outline: Create a design in Penpot
    When someone creates a design in the "<project>" Penpot project
    Then a matching file is created in "<folder>"
    And the file holds:
      | penpot_id       | the design's id    |
      | penpot_team_id  | the mapping's team |
      | penpot_mode     | "<mode>"           |
      | penpot_revision | <revision>         |
      | content         | <content>          |

    Examples: one gesture, and the mapping decides what the file is
      | project   | folder           | mode      | revision | content    |
      | Make Here | Penpot/Make Here | sync      | set      | an archive |
      | Quarterly | Shared/Quarterly | sync      | set      | an archive |
      | Nested    | Pointers/Nested  | reference | set      | empty      |

    # notes: ../AGENTS.md#a-link-carries-a-revision-too-because-it-is-the-pulls-stamp
    # Mode is the one thing about an arriving design that Nextcloud decides.

  # notes: ../AGENTS.md#a-design-created-in-the-users-own-home-lands-in-their-personal-drafts
  # notes: ../AGENTS.md#the-personal-mapping-is-held-until-the-siblings-have-one
  # @todo — held deliberately; the personal mapping is ahead of both siblings.
  @in-nextcloud @gesture @todo
  Scenario Outline: Create a design in the user's own home
    Given the user has a personal Penpot token
    And a folder at "Sketchbook" in the user's home that is not a project
    When I create a new design in "<folder>"
    Then the user's personal "<project>" project holds a design named "New design"
    And "<folder>/New design.penpot" holds:
      | penpot_id | set |

    Examples: the home root is this team's Drafts, and a folder in it is a project
      | folder     | project    |
      |            | Drafts     |
      | Sketchbook | Sketchbook |

    # The ordinary rules with a different mapping: the home root is where this team
    # is mounted, so it is Drafts, and a folder in it promotes like any other.

    # ── RULE: a design has to have somewhere to go ───────────────────────────
    # notes: ../AGENTS.md#a-design-has-to-have-somewhere-to-go

  @in-nextcloud @gesture
  Scenario: Create a design outside every mapping
    When I try to create a new design in "Scratch"
    Then the creation is refused with a message
    And "Scratch" holds no file named "New design.penpot"

    # DIVERGES FROM BOTH SIBLINGS, which allow the plain file: an empty ".penpot" is
    # not authorable the way an empty JSON is, and there is no rootless design.

    # ── RULE: a link mapping authors nothing ─────────────────────────────────
    # notes: ../AGENTS.md#a-link-mapping-authors-nothing

  @in-nextcloud @gesture
  Scenario Outline: Creating a design in a link-mapped folder is refused
    When I try to create a new design in "<folder>"
    Then the creation is refused with a message
    And no design is created in Penpot

    Examples: a link folder is Penpot's to write, at every depth
      | folder          |
      | Pointers        |
      | Pointers/Nested |

    # A link is the inert mode: it projects Penpot into Nextcloud and never writes
    # back, so a file authored into one could never become the design it looks like.
