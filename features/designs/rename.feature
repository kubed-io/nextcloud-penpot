# Notes, decisions and history for this feature: ../AGENTS.md#designsrename

Feature: Renaming a design
  As a Nextcloud user
  I want a rename made on either side to reach the other
  So that one name means one thing in both places

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a name is one value living in two places ────────────────────────
    # notes: ../AGENTS.md#renaming-a-mirrored-file-renames-its-design-in-penpot

  @in-nextcloud @gesture @todo
  Scenario Outline: Rename a design in Nextcloud
    Given a design file named "Old Name.penpot" in "Penpot/Rename Live"
    When I rename the file to "<new name>.penpot"
    Then the file is named "<new name>.penpot"
    And the design is named "<new name>" in Penpot
    And the file holds:
      | penpot_id   | the original id    |
      | penpot_mode | the mapping's mode |
      | content     | an archive         |

    Examples: names that look like something the filename grammar means
      | new name                |
      | New Name                |
      | v1.2 board              |
      | Cluster (eu-west-1)     |
      | Latency — p99 · eu-west |

    # Every row is a name the app could misread, not a decorative charset test: a dot
    # is where an extension lives and brackets are how Nextcloud spells a collision.

    # The id is the whole anti-break claim: a rename must move the design, never
    # replace it with a new one wearing the new name.

  # notes: ../AGENTS.md#a-rename-is-picked-up-in-both-modes-without-an-export
  @in-penpot @gesture @todo
  Scenario Outline: Rename a design in Penpot
    Given a design file named "Old Name.penpot" in "<folder>/Renamed"
    When someone renames the design to "New Name" in Penpot
    Then the file is named "New Name.penpot"
    And "<folder>/Renamed" holds no file named "Old Name.penpot"
    And the file holds:
      | penpot_id   | the original id    |
      | penpot_mode | the mapping's mode |
    And there is exactly one file for that design

    Examples: a link holds no archive, but its NAME still mirrors
      | folder   |
      | Penpot   |
      | Shared   |
      | Pointers |

    # ── RULE: a link is read-only, so its name is Penpot's to set ─────────────
    # notes: ../AGENTS.md#renaming-a-link-never-renames-the-design

  @in-nextcloud @gesture @todo
  Scenario: Rename a link in Nextcloud
    Given a design file named "Old Name.penpot" in "Pointers/Confined"
    When I try to rename the file to "New Name.penpot"
    Then the rename is refused with a message
    And the file is named "Old Name.penpot"
    And the design is named "Old Name" in Penpot

    # A pointer's name comes from Penpot, so a local rename would survive exactly
    # until the next pull renamed it back — a silent undo, which is worse than a no.

    # ── RULE: two designs may share a name, two files may not ─────────────────
    # notes: ../AGENTS.md#the-suffix-is-nextclouds-alone

  @in-penpot @gesture @todo
  Scenario: Rename a design in Penpot to a name another one already has
    Given a design file named "Alpha.penpot" in "Penpot/Crowded"
    And a design file named "Beta.penpot" in "Penpot/Crowded"
    When someone renames the "Beta" design to "Alpha" in Penpot
    Then "Penpot/Crowded/Alpha.penpot" holds:
      | penpot_id | the original id |
    And "Penpot/Crowded/Alpha (1).penpot" holds:
      | penpot_id | the id of the renamed design |
    And both designs are named "Alpha" in Penpot

    # The file that held the name keeps it; the arriving one takes the suffix. The
    # suffix is Nextcloud's alone — Penpot is perfectly happy with two "Alpha"s.

    # ── RULE: a name Penpot cannot hold is refused before it is sent ──────────
    # notes: ../AGENTS.md#an-empty-file-name-is-refused-before-it-is-sent

  @in-nextcloud @gesture @todo
  Scenario Outline: Rename a design to a name Penpot cannot hold
    Given a design file named "Old Name.penpot" in "Penpot/Refusals"
    When I try to rename it to <name>
    Then the rename is refused with a message
    And the file is named "Old Name.penpot"
    And the design is named "Old Name" in Penpot

    Examples: two names, one refusal — neither ever reaches Penpot
      | name                                       |
      | a name that is empty once ".penpot" is off |
      | a name longer than Penpot allows           |

    # ── RULE: a rename outside every mapping is Nextcloud's business alone ────
    # notes: ../AGENTS.md#renaming-an-untracked-penpot-file-is-not-a-failure

  @in-nextcloud @gesture @todo
  Scenario Outline: Rename an untracked design file
    Given an untracked design file at "<path>"
    When I rename the file to "Renamed Anyway.penpot"
    Then the file is named "Renamed Anyway.penpot"
    And no design is renamed in Penpot
    And the file holds no Penpot metadata at all

    Examples: inside a mapping and outside every mapping alike
      | path                                      |
      | Penpot/Untracked Rename/Dragged In.penpot |
      | Scratch/Dragged In.penpot                 |

    # ── RULE: a rename we cannot propagate still stands locally ───────────────
    # notes: ../AGENTS.md#a-failed-propagation-never-reverts-the-users-local-rename

  @in-nextcloud @gesture @todo
  Scenario: Rename a design while Penpot is unreachable
    Given a design file named "Old Name.penpot" in "Penpot/Offline"
    And Penpot is unreachable
    When I rename the file to "New Name.penpot"
    Then the file is named "New Name.penpot"
    And the failure is reported to the user
    And the file holds:
      | penpot_id | the original id |

    # Nextcloud has already renamed it, and reverting would fight the user over a
    # gesture that succeeded locally.
