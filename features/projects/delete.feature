# Notes, decisions and history for this feature: ../AGENTS.md#projectsdelete

Feature: Deleting a project
  As a Nextcloud user
  I want deleting a project folder to be as safe and as legible as deleting one design
  So that one gesture cannot quietly destroy many designs

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

    # ── RULE: trashing a project folder trashes the project ───────────────────
    # notes: ../AGENTS.md#deleting-a-project-folder-deletes-the-project-in-penpot

  @in-nextcloud @gesture
  Scenario Outline: Trash a project folder
    Given the following items in the mappings:
      | path                          |
      | /<folder>/Doomed/Alpha.penpot |
      | /<folder>/Doomed/Beta.penpot  |
    When I move "<folder>/Doomed" to the trash
    Then Penpot holds no project named "Doomed"
    And those designs are in Penpot's trash
    And "<folder>/Doomed" is recoverable from the Nextcloud trash

    Examples: the storage a mapping uses makes no difference to what a trash is
      | folder |
      | Penpot |
      | Shared |

    # Penpot's own trash takes the designs with the project, so nothing is destroyed
    # by this gesture on either side — which is what makes it safe to do at all.

  # notes: ../AGENTS.md#trashing-a-folder-takes-every-project-its-name-spelled
  @in-nextcloud @gesture
  Scenario: Trash a folder that other projects are named through
    Given the following items in the mappings:
      | path                            | kind    |
      | /Penpot/foo/bar                 | project |
      | /Penpot/foo/bar/Alpha.penpot    | design  |
      | /Penpot/foo/bar/baz             | project |
      | /Penpot/foo/bar/baz/Beta.penpot | design  |
    When I move "Penpot/foo" to the trash
    Then Penpot holds no project named "foo/bar"
    And Penpot holds no project named "foo/bar/baz"
    And "Penpot/foo" is recoverable from the Nextcloud trash

    # "foo" is not a project itself, but every project below it is named THROUGH it,
    # so the one gesture ends all of them — which is why the trash entry matters.

    # Both are spelled out as projects: without that, "holds no project named
    # foo/bar/baz" would pass by the project never having existed at all.

    # ── RULE: a link team is Penpot's to change ───────────────────────────────
    # notes: ../AGENTS.md#trashing-a-project-folder-in-a-link-team-is-refused

  @in-nextcloud @gesture
  Scenario: Trashing a project folder in a link team is refused
    Given a folder at "Pointers/Existing"
    And Penpot holds a project named "Existing"
    When I try to move "Pointers/Existing" to the trash
    Then the trash is refused with a message
    And Penpot holds a project named "Existing"

    # The refusal turns on the project EXISTING, which is why the premise is stated
    # rather than left to the Background — the rule below is the same folder without one.

    # ── RULE: a folder Penpot never named is an ordinary folder ──────────────
    # notes: ../AGENTS.md#a-folder-penpot-never-named-is-an-ordinary-folder

  @in-nextcloud @gesture
  Scenario Outline: Trash a folder that is not mapped to a penpot project
    Given a folder at "<folder>/Odds and ends"
    And Penpot holds no project named "Odds and ends"
    When I move "<folder>/Odds and ends" to the trash
    Then "<folder>/Odds and ends" is recoverable from the Nextcloud trash

    Examples: a folder Penpot never named is an ordinary folder, whatever the mapping
      | folder   |
      | Penpot   |
      | Shared   |
      | Pointers |

    # The refusal above is about the MIRROR, not about the mapping — which is the
    # boundary the "Pointers" row draws, and the only row that was ever broken.

    # ── RULE: a project deleted in Penpot takes only what is Penpot's ─────────
    # notes: ../AGENTS.md#a-project-deleted-in-penpot-leaves-no-folder-claiming-its-id

  @in-penpot @gesture
  Scenario: Delete a project in Penpot whose folder holds only designs
    Given the following items in the mappings:
      | path                         |
      | /Penpot/Doomed/Alpha.penpot  |
      | /Penpot/Doomed/Beta.penpot   |
    When someone deletes the "Doomed" project in Penpot
    Then "Penpot/Doomed" is gone from Nextcloud
    And the designs are recoverable from the Nextcloud trash

  @in-penpot @gesture
  Scenario: Delete a project in Penpot whose folder holds other files too
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Doomed/Alpha.penpot |
      | /Penpot/Doomed/Budget.xlsx  |
    When someone deletes the "Doomed" project in Penpot
    Then "Penpot/Doomed" still exists in Nextcloud, holding "Budget.xlsx"
    And it holds no design files
    And the designs are recoverable from the Nextcloud trash
    And the mappings hold:
      | path           | identity |
      | /Penpot/Doomed | absent   |

    # It stops being a project and goes back to being an ordinary folder. Deleting a
    # user's spreadsheets because a Penpot project went away is not the app's call.
