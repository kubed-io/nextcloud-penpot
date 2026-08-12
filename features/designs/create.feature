# Notes, decisions and history for this feature: ../AGENTS.md#designscreate

Feature: Creating a design
  As a Nextcloud user
  I want to start a new design from the Files app
  So that creating work is as easy as it is for workflows and dashboards

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |
    And a mapping with the following values:
      | team   | Reference Team |
      | folder | Pointers       |
      | mode   | link           |

  # notes: ../AGENTS.md#create-design-background

    # ── RULE: a new design belongs to the nearest project, or to Drafts ───────
    # notes: ../AGENTS.md#a-design-created-under-the-team-but-not-under-a-project-is-a-draft

  @in-nextcloud @gesture
  Scenario Outline: Create a design
    Given a mirrored project "Make Here"
    And a folder at "Penpot/Inbox" that is not a project
    When I create a new design file at "<path>"
    Then "<path>" holds:
      | penpot_id   | set    |
      | penpot_mode | "sync" |
    And Penpot project "<project>" holds a design named "<design>"

    Examples: the nearest project ancestor decides, and the team root means Drafts
      | path                               | design        | project   |
      | Penpot/Make Here/Fresh Idea.penpot | Fresh Idea    | Make Here |
      | Penpot/Loose Idea.penpot           | Loose Idea    | Drafts    |
      | Penpot/Inbox/Filed By Hand.penpot  | Filed By Hand | Drafts    |

    # Drafts is a STATE, not a folder: the file stays where it was made, and only
    # Penpot's side of it differs.

  # notes: ../AGENTS.md#a-design-created-in-the-users-own-home-lands-in-their-personal-drafts
  @in-nextcloud @gesture @unbuilt
  Scenario Outline: Create a design in the user's own home
    Given the user has a personal Penpot token
    And a folder at "Sketchbook" in the user's home that is not a project
    When I create a new design file at "<path>"
    Then "<path>" holds:
      | penpot_id | set |
    And the user's personal "Drafts" project holds a design named "<design>"

    Examples: the home root and a plain folder in it are both outside every project
      | path                     | design     |
      | Fresh Idea.penpot        | Fresh Idea |
      | Sketchbook/Sketch.penpot | Sketch     |

    # The same nearest-ancestor rule: no project id on the way up, a team id at the
    # root. The personal team is the one team with no folder of its own.

    # ── RULE: a design has to have somewhere to go ────────────────────────────

  # notes: ../AGENTS.md#a-design-has-to-have-somewhere-to-go
  @in-nextcloud @gesture @unbuilt
  Scenario: Create a design where no Penpot team can be determined
    Given a folder at "No Mapping Here" that is not mapped
    When I try to create a new design file at "No Mapping Here/Wishful.penpot"
    Then the creation is refused with a message
    And "No Mapping Here" holds no file named "Wishful.penpot"

    # Penpot's create-file needs a project id and there is no rootless design, so
    # there is nothing this gesture could mean here.

    # ── RULE: authorship is durable, so it follows whoever made the design ────
    # notes: ../AGENTS.md#a-created-design-is-attributed-to-the-acting-user-when-possible

  @in-nextcloud @gesture @blocked
  Scenario: Create a design as a user with a personal token
    Given the user has a personal Penpot token
    And a mirrored project "Attribution"
    When I create a new design file at "Penpot/Attribution/Mine.penpot"
    Then Penpot project "Attribution" holds a design named "Mine"
    And Penpot records that user as the design's author

  @in-nextcloud @gesture @blocked
  Scenario: Create a design as a user with no personal token
    Given the user has no personal Penpot token
    And a mirrored project "Attribution"
    When I create a new design file at "Penpot/Attribution/Ours.penpot"
    Then Penpot project "Attribution" holds a design named "Ours"
    And Penpot records the service account as the design's author
    And the user is told the design will be authored by the service account

    # Authorship is a durable property of a design rather than a line of history,
    # so it matters more here than for any other write.

    # ── RULE: a link mapping authors nothing ─────────────────────────────────

  @in-nextcloud @gesture @unbuilt
  Scenario: Create a design in a link-mapped folder
    Given a mirrored project "Read Only"
    When I try to create a new design file at "Pointers/Read Only/Fresh Idea.penpot"
    Then the creation is refused with a message
    And Penpot project "Read Only" holds no design named "Fresh Idea"

    # A link is the inert mode: it projects Penpot into Nextcloud and never writes
    # back, so a file authored into one could never become the design it looks like.

    # ── RULE: a design made in Penpot arrives as a file ───────────────────────
    # notes: ../AGENTS.md#a-newly-created-design-is-born-in-its-mappings-mode

  @in-penpot @gesture @todo
  Scenario Outline: Create a design in Penpot
    Given a mirrored project "Made There"
    When someone creates the design "Fresh Idea" in the project "Made There" in Penpot
    Then "<folder>/Made There/Fresh Idea.penpot" holds:
      | penpot_id   | the design's id |
      | penpot_mode | "<mode>"        |
      | content     | <content>       |

    Examples: the mapping decides what the file holds, and the design decides nothing
      | folder   | mode | content    |
      | Penpot   | sync | an archive |
      | Pointers | link | empty      |

    # This is where mode earns a scenario: it is the one thing about an arriving
    # design that Nextcloud decides rather than Penpot.
