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

  @in-nextcloud @gesture @todo
  Scenario Outline: Copy a project within its team
    Given the following items in the mappings:
      | path                            | kind    |
      | /<folder>/My Stuff              | project |
      | /<folder>/My Stuff/Alpha.penpot | design  |
      | /<folder>/My Stuff/Beta.penpot  | design  |
    When I copy "<folder>/My Stuff" to "<folder>/My Stuff copy"
    Then the mappings hold:
      | path                                 | identity        |
      | /<folder>/My Stuff                   | the original id |
      | /<folder>/My Stuff/Alpha.penpot      | the original id |
      | /<folder>/My Stuff copy              | a new id        |
      | /<folder>/My Stuff copy/Alpha.penpot | a new id        |
      | /<folder>/My Stuff copy/Beta.penpot  | a new id        |
    And the "My Stuff copy" Penpot project holds one design per file, named:
      | Alpha |
      | Beta  |

    Examples: the storage a mapping uses makes no difference to what a copy is
      | folder |
      | Penpot |
      | Shared |

    # Penpot has no duplicate-project call, so this is a create followed by one
    # duplicate per design — which is why every id below the copy is a new one.

  # notes: ../AGENTS.md#a-project-copied-into-another-team-belongs-to-that-team
  @in-nextcloud @gesture @todo
  Scenario: Copy a project into another team
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
    When I copy "Penpot/My Stuff" to "Shared/My Stuff"
    Then the mappings hold:
      | path                          | identity        |
      | /Penpot/My Stuff              | the original id |
      | /Shared/My Stuff              | a new id        |
      | /Shared/My Stuff/Alpha.penpot | a new id        |
    And the "My Stuff" Penpot project is in the "Second Team" team

    # ── RULE: a project has no project above it ──────────────────────────────
    # notes: ../AGENTS.md#penpot-projects-do-not-nest

  @in-nextcloud @gesture @todo
  Scenario Outline: Copy a project under something that cannot hold one
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/Landing               | project |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
    When I copy "Penpot/My Stuff" to "<destination>/My Stuff"
    Then the mappings hold:
      | path                                 | identity |
      | /<destination>/My Stuff              | absent   |
      | /<destination>/My Stuff/Alpha.penpot | a new id |
    And the design "Alpha" is in the "<lands in>" Penpot project

    Examples: Penpot has no sub-project, so the nearest project above decides
      | destination    | lands in |
      | Penpot/Landing | Landing  |
      | Penpot/Clients | Drafts   |

    # The folder arrives as a plain folder because there is nowhere in Penpot to put
    # a project inside a project. Its designs still land somewhere, by the usual rule.

    # ── RULE: a copy outside every mapping is an ordinary folder ──────────────

  @in-nextcloud @gesture @todo
  Scenario: Copy a project out of every mapping
    Given the following items in the mappings:
      | path                          | kind    |
      | /Penpot/My Stuff              | project |
      | /Penpot/My Stuff/Alpha.penpot | design  |
      | /Penpot/My Stuff/Beta.penpot  | design  |
    When I copy "Penpot/My Stuff" to "Scratch/My Stuff"
    Then the mappings hold:
      | path                           | identity |
      | /Scratch/My Stuff              | absent   |
      | /Scratch/My Stuff/Alpha.penpot | absent   |
      | /Scratch/My Stuff/Beta.penpot  | absent   |

    # ── RULE: a link is read-only, so a copy neither enters nor leaves one ────
    # notes: ../AGENTS.md#a-copy-never-changes-a-projects-mode

  @in-nextcloud @gesture @todo
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
