# Notes, decisions and history for this feature: ../AGENTS.md#projectscreate

Feature: Creating a project
  As a Nextcloud user
  I want the folders holding my designs to exist in Penpot too
  So that the two sides look the same without my having to manage either

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And the following items in the mappings:
      | path                            |
      | /Penpot/Existing/Alpha.penpot   |
      | /Pointers/Existing/Fixed.penpot |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder is a project in Penpot when a design is in it ──────────
    # notes: ../AGENTS.md#a-folder-is-a-project-when-a-design-is-in-it

  @in-nextcloud @gesture
  Scenario Outline: Create a design in a folder Penpot has never seen
    Given the folder "<folder>" holding no designs
    When I create a new design in "<folder>"
    Then Penpot holds a project named "<project>"
    And the design is in the "<project>" Penpot project
    And "<folder>" holds:
      | penpot_project_id | set |

    Examples: however deep it lands, in either kind of storage
      | folder                  | project          |
      | Penpot/Team             | Team             |
      | Penpot/Team/Deep        | Team/Deep        |
      | Shared/Team             | Team             |
      | Shared/Team/Deep/Deeper | Team/Deep/Deeper |

    Examples: and a folder inside a project is a project of its own
      | folder                | project          |
      | Penpot/Existing/Below | Existing/Below   |

  # notes: ../AGENTS.md#the-project-name-is-the-path-below-the-mapping

  @in-nextcloud @gesture
  Scenario Outline: Move a design into a folder Penpot has never seen
    Given a design file named "Travelling.penpot" in "<source>"
    And the folder "<folder>" holding no designs
    When I move the file into "<folder>"
    Then Penpot holds a project named "<project>"
    And the design is in the "<project>" Penpot project

    Examples: wherever it came from, it ends up in the same place
      | source          | folder                  | project          |
      | Scratch         | Penpot/Team             | Team             |
      | Penpot/Existing | Penpot/Team/Deep        | Team/Deep        |
      | Shared/Existing | Penpot/Team             | Team             |
      | Penpot/Existing | Shared/Team/Deep/Deeper | Team/Deep/Deeper |

    # Four arrivals and one rule: tracked or untracked, within one storage or across
    # the boundary between two, the folder the design lands in becomes the project.

    # ── RULE: a folder with no design in it is Nextcloud's alone ──────────────
    # notes: ../AGENTS.md#a-folder-holding-no-designs-is-just-a-folder

  @in-nextcloud @gesture
  Scenario: Create a folder in a mapping
    When I create the folder "Penpot/Notes"
    Then Penpot holds no project named "Notes"
    And "Penpot/Notes" holds:
      | penpot_project_id | absent |

    # ── RULE: a project made in Penpot arrives as the folders its name spells ─
    # notes: ../AGENTS.md#a-project-name-with-slashes-is-a-path
    # notes: ../AGENTS.md#the-folders-a-project-name-spells-are-not-projects

  @in-penpot @gesture
  Scenario Outline: Create a project in Penpot
    When someone creates the "<name>" project in the "<team>" Penpot team
    Then "<folder>" exists in Nextcloud, holding:
      | penpot_project_id | the project's id |
    And the folders its name spelled on the way down hold:
      | penpot_project_id | absent |

    Examples: one name, however many folders it spells, in any team
      | team           | name          | folder               |
      | Design Team    | Fresh         | Penpot/Fresh         |
      | Design Team    | foo/bar       | Penpot/foo/bar       |
      | Second Team    | Deep/Down/Low | Shared/Deep/Down/Low |
      | Reference Team | Pinned        | Pointers/Pinned      |

    # Every name here is one no scenario above leaves standing in that team: a second
    # project of a name already taken is mirrored beside the first, not into it.

    # "foo" is made because "foo/bar" needs somewhere to sit. It holds no design, so
    # it is a folder like any other — and a project named "foo" would later claim it.

    # ── RULE: a name that spells no path is reported, not guessed at ──────────
    # notes: ../AGENTS.md#a-project-name-that-spells-no-path-is-skipped

  @in-penpot @gesture @todo
  Scenario Outline: Create a project in Penpot with a name Nextcloud cannot spell
    When someone creates the "<name>" project in the "Design Team" Penpot team
    Then no folder is created for it in Nextcloud
    And the user is notified that the project could not be placed

    Examples: every name that survives Penpot's 1-to-250 rule and spells nothing
      | name       |
      | /          |
      | foo/../bar |
      | foo/?/bar  |

    # Penpot takes any string of 1 to 250 characters, so these are all reachable from
    # its own UI. One project is the whole cost — the rest of the team still arrives.
