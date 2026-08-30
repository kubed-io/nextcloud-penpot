# Notes, decisions and history for this feature: ../AGENTS.md#projectscopy

Feature: Copying a Penpot project folder
  As a Nextcloud user
  I want to copy an entire Penpot project folder
  So that the contents are duplicated in penpot and nextcloud

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And the following items in the mappings:
      | path            | kind         |
      | /Penpot/Clients | plain folder |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a copied project is a new project, holding new designs ──────────
    # notes: ../AGENTS.md#a-copied-project-is-a-new-project

  @in-nextcloud @gesture
  Scenario Outline: Copy a project within its team
    Given the following items in the mappings:
      | path                            | kind    |
      | /<folder>/My Stuff              | project |
      | /<folder>/My Stuff/Alpha.penpot | design  |
      | /<folder>/My Stuff/Beta.penpot  | design  |
    When I copy "<folder>/My Stuff" into "<folder>"
    Then the mappings hold:
      | path                                | identity        |
      | /<folder>/My Stuff                  | the original id |
      | /<folder>/My Stuff/Alpha.penpot     | the original id |
      | /<folder>/My Stuff/Beta.penpot      | the original id |
      | /<folder>/My Stuff (2)              | a new id        |
      | /<folder>/My Stuff (2)/Alpha.penpot | a new id        |
      | /<folder>/My Stuff (2)/Beta.penpot  | a new id        |
    And the "My Stuff (2)" Penpot project holds one design per file, named:
      | Alpha |
      | Beta  |

    Examples: the storage a mapping uses makes no difference to what a copy is
      | folder |
      | Penpot |
      | Shared |

    # It lands beside the original, so core names it — `(2)`, where core's counter
    # starts, and every id below the copy is a new one.

    # ── RULE: a project duplicated in Penpot arrives as its own folder ────────
    # notes: ../AGENTS.md#a-project-duplicated-in-penpot-arrives-as-its-own-folder

  @in-penpot @gesture
  Scenario: Duplicate a project in Penpot
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
      | /Penpot/My Stuff/Beta.penpot  | design  |
    When someone duplicates that project in Penpot
    Then the mappings hold:
      | path                                 | identity        |
      | /Penpot/My Stuff                     | the original id |
      | /Penpot/My Stuff/Alpha.penpot        | the original id |
      | /Penpot/My Stuff (copy)              | a new id        |
      | /Penpot/My Stuff (copy)/Alpha.penpot | a new id        |
      | /Penpot/My Stuff (copy)/Beta.penpot  | a new id        |
    And the "My Stuff (copy)" Penpot project holds one design per file, named:
      | Alpha |
      | Beta  |

    # Penpot names this one, so the folder is "(copy)" — Penpot's own suffix, where
    # a copy made in Nextcloud is "(2)". The name says which side made it.

  # notes: ../AGENTS.md#a-project-copied-into-another-team-belongs-to-that-team
  @in-nextcloud @gesture
  Scenario: Copy a project into another team
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
    When I copy "Penpot/My Stuff" into "Shared"
    Then the mappings hold:
      | path                          | identity        |
      | /Penpot/My Stuff              | the original id |
      | /Penpot/My Stuff/Alpha.penpot | the original id |
      | /Shared/My Stuff              | a new id        |
      | /Shared/My Stuff/Alpha.penpot | a new id        |
    And the "My Stuff" Penpot project is in the "Second Team" team

    # ── RULE: a copy outside every mapping is an ordinary folder ──────────────

  @in-nextcloud @gesture
  Scenario: Copy a project out of every mapping
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
      | /Penpot/My Stuff/Beta.penpot  | design  |
    When I copy "Penpot/My Stuff" to "Scratch/My Stuff"
    Then the mappings hold:
      | path                           | identity        |
      | /Scratch/My Stuff              | absent          |
      | /Scratch/My Stuff/Alpha.penpot | the original id |
      | /Scratch/My Stuff/Beta.penpot  | the original id |
    And "Scratch/My Stuff/Alpha.penpot" holds:
      | penpot_mode | "unmapped" |

    # The folder is nobody's project, but each design still records where its bytes
    # came from — the same "unmapped" a design dragged out alone keeps.

    # ── RULE: a link is read-only, so a copy neither enters nor leaves one ────
    # notes: ../AGENTS.md#a-copy-never-changes-a-projects-mode

  @in-nextcloud @gesture
  Scenario Outline: Copying a link project, or into a link team, is refused
    Given the following items in the mappings:
      | path                            | kind    |
      | /<source>/My Stuff              | project |
      | /<source>/My Stuff/Alpha.penpot | design  |
    When I try to copy "<source>/My Stuff" to "<destination>/My Stuff copy"
    Then the copy is refused with a message
    And there is no folder at "<destination>/My Stuff copy"

    Examples: a link project is read-only in Nextcloud, and there is nowhere it may go
      | source   | destination |
      | Pointers | Penpot      |
      | Pointers | Scratch     |
      | Pointers | Pointers    |

    Examples: and a link team is filled from Penpot, whatever is arriving
      | source | destination |
      | Penpot | Pointers    |

    # The one copy that names its destination: a refusal never lands, so there is
    # no name for core to pick and nothing to aim at the source's own parent.

    # ── RULE: a copy Penpot will not take creates nothing ─────────────────────

  @in-nextcloud @gesture @todo
  Scenario: Copy a project while Penpot is unreachable
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
    And Penpot is unreachable
    When I copy "Penpot/My Stuff" to "Penpot/My Stuff copy"
    Then the failure is reported to the user
    And the mappings hold:
      | path                               | identity |
      | /Penpot/My Stuff copy              | absent   |
      | /Penpot/My Stuff copy/Alpha.penpot | absent   |
