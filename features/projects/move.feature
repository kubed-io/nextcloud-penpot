# Notes, decisions and history for this feature: ../AGENTS.md#projectsmove

Feature: Moving a project
  As a Nextcloud user
  I want a project folder to mean the same thing in Penpot wherever I file it
  So that arranging my tree never orphans a project or duplicates one

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And the following items in the mappings:
      | path                            |
      | /Penpot/Clients                 |
      | /Shared/Archive                 |
      | /Pointers/Existing/Fixed.penpot |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: where a project folder sits is what the project is called ───────
    # notes: ../AGENTS.md#moving-a-project-folder-renames-the-project

  @in-nextcloud @gesture
  Scenario Outline: Move a project folder within its team
    Given the following items in the mappings:
      | path                             |
      | /<folder>/Traveller/Alpha.penpot |
    When I move "<folder>/Traveller" into "<destination>"
    Then Penpot holds a project named "<named>"
    And the mappings hold:
      | path                              | identity        |
      | /<destination>/Traveller          | the original id |
      | /<destination>/Traveller/Alpha.penpot | the original id |

    Examples: the storage a mapping uses makes no difference to what a move is
      | folder | destination     | named             |
      | Penpot | Penpot/Clients  | Clients/Traveller |
      | Shared | Shared/Archive  | Archive/Traveller |

    # The id is the whole anti-break claim: a move renames the project, it never
    # replaces it with a new one wearing the new path.

  # notes: ../AGENTS.md#a-move-high-in-the-tree-renames-every-project-below-it
  @in-nextcloud @gesture
  Scenario: Move a folder that other projects are named through
    Given the following items in the mappings:
      | path                            | kind    |
      | /Penpot/foo/bar                 | project |
      | /Penpot/foo/bar/Alpha.penpot    | design  |
      | /Penpot/foo/bar/baz             | project |
      | /Penpot/foo/bar/baz/Beta.penpot | design  |
    When I move "Penpot/foo" into "Penpot/Clients"
    Then Penpot holds a project named "Clients/foo/bar"
    And Penpot holds a project named "Clients/foo/bar/baz"
    And the mappings hold:
      | path                            | identity        |
      | /Penpot/Clients/foo/bar         | the original id |
      | /Penpot/Clients/foo/bar/baz     | the original id |

    # "foo" is no project itself, but every project below it is named THROUGH it —
    # so one drag is one rename per project, and each keeps the id it always had.

    # "baz" is spelled out as a project because a design alone would not make it one:
    # it sits under "foo/bar", and the nearest project ancestor already owns it.

    # ── RULE: leaving every mapping leaves the project standing ───────────────
    # notes: ../AGENTS.md#a-project-folder-that-leaves-every-mapping-stops-being-a-mirror

  @in-nextcloud @gesture
  Scenario: Move a project folder out of a team to unmap it
    Given the following items in the mappings:
      | path                            |
      | /Penpot/Let Go/Alpha.penpot     |
    When I move "Penpot/Let Go" into "Scratch"
    Then Penpot holds a project named "Let Go"
    And the mappings hold:
      | path                          | identity        |
      | /Scratch/Let Go               | absent          |
      | /Scratch/Let Go/Alpha.penpot  | the original id |

    # Nothing is deleted over there, and the two rows differ on purpose: the folder's
    # marker still resolves for anything dropped beside it, the design's id does not.

    # ── RULE: arriving in a team makes every design in it real ────────────────
    # notes: ../AGENTS.md#a-folder-is-a-project-when-a-design-is-in-it

  # @unbuilt — TWO walls, measured. A folder move fires one event for the folder
  # and none per child, and an uploaded archive is never imported (§6.33).
  @in-nextcloud @gesture @unbuilt
  Scenario: Move a folder of untracked designs into a team
    Given an untracked design file at "Scratch/Adopt Me/Alpha.penpot"
    When I move "Scratch/Adopt Me" into "Penpot"
    Then Penpot holds a project named "Adopt Me"
    And the mappings hold:
      | path                            | identity |
      | /Penpot/Adopt Me                | set      |
      | /Penpot/Adopt Me/Alpha.penpot   | set      |

    # ── RULE: a move is not a way around the link guard ───────────────────────
    # notes: ../AGENTS.md#a-move-never-changes-a-projects-mode

  @in-nextcloud @gesture
  Scenario Outline: Moving a link project, or into a link team, is refused
    Given the following items in the mappings:
      | path                            |
      | /<source>/Confined/Alpha.penpot |
    When I try to move "<source>/Confined" into "<destination>"
    Then the move is refused with a message
    And "<source>/Confined" stays where it was

    Examples: a link project is Penpot's to place, and there is nowhere it may go
      | source   | destination |
      | Pointers | Penpot      |
      | Pointers | Scratch     |
      | Pointers | Pointers    |

    Examples: and a link team is filled from Penpot, whatever is arriving
      | source | destination |
      | Penpot | Pointers    |

    # ── RULE: a project carries its team as well as its name ──────────────────
    # notes: ../AGENTS.md#a-project-carries-its-team-as-well-as-its-name

  # @unbuilt — the app never sees this gesture: "Shared" is a Team Folder, so the
  # move crosses a storage boundary and fires no NodeRenamedEvent. Measured in CI.
  @in-nextcloud @gesture @unbuilt
  Scenario: Move a project folder into another team
    Given the following items in the mappings:
      | path                          |
      | /Penpot/Crossing/Alpha.penpot |
    When I move "Penpot/Crossing" into "Shared"
    Then the "Crossing" Penpot project is in the "Second Team" team
    And the mappings hold:
      | path                          | identity        |
      | /Shared/Crossing              | the original id |
      | /Shared/Crossing/Alpha.penpot | the original id |

    # One move changing team and name together, keeping the id, the designs and
    # their history. A project is never re-created to cross a team boundary.

    # ── RULE: a project changed in Penpot moves its folder ────────────────────
    # notes: ../AGENTS.md#a-project-renamed-in-penpot-moves-its-folder

  @in-penpot @gesture @todo
  Scenario Outline: Move/Rename a project in Penpot
    Given the following items in the mappings:
      | path                  |
      | /<from>/Alpha.penpot  |
      | /<from>/Budget.xlsx   |
    When someone moves that project to "<name>" in the "<team>" Penpot team
    Then the mappings hold:
      | path                | identity        |
      | /<to>               | the original id |
      | /<to>/Alpha.penpot  | the original id |
    And "<to>" holds "Budget.xlsx"
    And there is no folder at "<from>"

    Examples: Penpot can re-path and change team in one call, where a drag cannot
      | from                | team        | name             | to                      |
      | Penpot/Upstream     | Design Team | Clients/Upstream | Penpot/Clients/Upstream |
      | Penpot/foo/Upstream | Design Team | Upstream         | Penpot/Upstream         |
      | Penpot/Upstream     | Design Team | Deep/Down/Low    | Penpot/Deep/Down/Low    |
      | Penpot/Upstream     | Second Team | Crossing         | Shared/Crossing         |

    # THE ID IS THE BEFORE AND AFTER. The new name says where the project belongs; the
    # id says which folder is already it. Ensure the destination, then move that folder.

    # ── RULE: what the old parent still holds decides whether it goes ─────────
    # notes: ../AGENTS.md#an-emptied-parent-is-reaped-only-when-it-holds-nothing-else

  @in-penpot @gesture @todo
  Scenario: Move a project in Penpot out of a folder holding nothing else
    Given the following items in the mappings:
      | path                          |
      | /Penpot/foo/Upstream/Alpha.penpot |
    When someone moves that project to "Clients/Upstream" in the "Design Team" Penpot team
    Then "Penpot/Clients/Upstream" holds:
      | penpot_project_id | the original id |
    And there is no folder at "Penpot/foo"

    # "foo" only ever existed because "foo/Upstream" needed somewhere to sit. With
    # nothing left in it and no id of its own, it has stopped meaning anything.

  @in-penpot @gesture @todo
  Scenario: Move a project in Penpot out of a folder holding other files
    Given the following items in the mappings:
      | path                              |
      | /Penpot/foo/Upstream/Alpha.penpot |
      | /Penpot/foo/Notes.txt             |
    When someone moves that project to "Clients/Upstream" in the "Design Team" Penpot team
    Then "Penpot/Clients/Upstream" holds:
      | penpot_project_id | the original id |
    And "Penpot/foo" still exists in Nextcloud, holding "Notes.txt"

    # Deleting a user's notes because a Penpot project moved out from under them is
    # not this app's call — the same line Grafana draws for a folder losing its last.

    # ── RULE: a move Penpot will not take leaves the local one standing ───────

  @in-nextcloud @gesture @todo
  Scenario: Move a project folder while Penpot is unreachable
    Given the following items in the mappings:
      | path                             |
      | /Penpot/Traveller/Alpha.penpot   |
    And Penpot is unreachable
    When I move "Penpot/Traveller" into "Penpot/Clients"
    Then the failure is reported to the user
    And "Penpot/Clients/Traveller" holds:
      | penpot_project_id | the original id |

    # Nextcloud has already moved it, and reverting would fight the user over a
    # gesture that succeeded locally. The next pull settles which name wins.
