# Notes, decisions and history for this feature: ../AGENTS.md#projectsrename

Feature: Renaming a project
  As a Nextcloud user
  I want a project renamed on either side to be recognised as the same project
  So that a rename never costs a project its id, its designs, or their history

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team        | folder | mode | storage      | groups |
      | Design Team | Penpot | sync | admin folder |        |
      | Second Team | Shared | sync | team folder  | admin  |
    And the following items in the mappings:
      | path                          |
      | /Penpot/Existing/Alpha.penpot |

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the id is what makes a rename a rename ──────────────────────────
    # notes: ../AGENTS.md#renaming-a-project-folder-renames-the-project-in-penpot

  @in-nextcloud @gesture
  Scenario Outline: Rename a project folder in Nextcloud
    Given the following items in the mappings:
      | path                     |
      | /<from>/Inside.penpot    |
    When I rename "<from>" to "<to>"
    Then Penpot holds a project named "<named>"
    And the mappings hold:
      | path                 | identity        |
      | /<to>                | the original id |
      | /<to>/Inside.penpot  | the original id |

    Examples: however deep it sits, in either kind of storage
      | from             | to                | named       |
      | Penpot/Old       | Penpot/New        | New         |
      | Penpot/foo/Old   | Penpot/foo/New    | foo/New     |
      | Shared/Old       | Shared/New        | New         |

    # The path below the mapping is the name, so only the last segment moved — and
    # the designs inside keep the ids they had, because nothing about them changed.

    # ── RULE: a project renamed in Penpot is renamed in place ─────────────────
    # notes: ../AGENTS.md#a-project-renamed-in-penpot-keeps-its-folder-where-it-is

  # @todo — needs per-scenario isolation in Penpot: the team still holds the
  # "New" project the scenario above made, so the pull adopts the wrong folder.
  @in-penpot @gesture @todo
  Scenario Outline: Rename a project in Penpot
    Given the following items in the mappings:
      | path                  |
      | /<from>/Inside.penpot |
      | /<from>/Budget.xlsx   |
    When someone renames that project to "<named>" in Penpot
    Then the mappings hold:
      | path                | identity        |
      | /<to>               | the original id |
      | /<to>/Inside.penpot | the original id |
    And "<to>" holds "Budget.xlsx"
    And there is no folder at "<from>"

    Examples: however deep it sits, in either kind of storage
      | from           | named       | to                 |
      | Penpot/Old     | New         | Penpot/New         |
      | Penpot/foo/Old | foo/New     | Penpot/foo/New     |
      | Shared/Old     | New         | Shared/New         |
      | Penpot/Bubbles | Bubbles/foo | Penpot/Bubbles/foo |

    # THE FOLDER IS RENAMED, NOT REPLACED. Everything in it comes along, the user's
    # own files included — nothing here is deleted and re-made under the new name.

    # ── RULE: a rename Penpot will not take leaves the local one standing ─────
    # notes: ../AGENTS.md#a-failed-project-rename-leaves-the-local-rename-standing

  @in-nextcloud @gesture @todo
  Scenario: Rename a project folder while Penpot is unreachable
    Given the following items in the mappings:
      | path                    |
      | /Penpot/Old/Inside.penpot |
    And Penpot is unreachable
    When I rename "Penpot/Old" to "Penpot/New"
    Then "Penpot/New" exists in Nextcloud
    And the failure is reported to the user
    And "Penpot/New" holds:
      | penpot_project_id | the original id |

    # Nextcloud has already renamed it, and reverting would fight the user over a
    # gesture that succeeded locally. The next pull settles which name wins.
