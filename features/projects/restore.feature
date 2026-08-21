# Notes, decisions and history for this feature: ../AGENTS.md#projectsrestore

Feature: Restoring a project from the trash
  As a Nextcloud user
  I want restoring a project folder to bring the project back
  So that undoing a folder delete undoes it in Penpot too

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

    # ── RULE: the project comes back; what Penpot kept decides which id ───────
    # notes: ../AGENTS.md#restoring-a-project-folder-brings-the-project-back

  @in-nextcloud @gesture @todo
  Scenario Outline: Restore a project folder from the Nextcloud trash
    Given a project folder "<folder>/Doomed" <held>
    And "<folder>/Doomed" is in the Nextcloud trash
    When I restore "<folder>/Doomed" from the Nextcloud trash
    Then Penpot holds a project named "Doomed"
    And "<folder>/Doomed" holds:
      | penpot_project_id | <identity> |

    Examples: what Penpot can still give back decides which id comes home
      | folder | held                                    | identity        |
      | Penpot | holding designs still in Penpot's trash | the original id |
      | Shared | holding designs still in Penpot's trash | the original id |
      | Penpot | holding designs Penpot has purged       | a new id        |
      | Penpot | holding no designs at all               | a new id        |

    # A project comes back only through a design of its own — Penpot has no
    # restore-project call. With nothing to come back through, it is made again.

    # ── RULE: a design coming back in Penpot brings its project with it ───────
    # notes: ../AGENTS.md#restoring-one-design-brings-its-project-with-it

  @in-penpot @gesture @todo
  Scenario: Restore one design of a deleted project in Penpot
    Given the following items in the mappings:
      | path                        |
      | /Penpot/Doomed/Alpha.penpot |
      | /Penpot/Doomed/Beta.penpot  |
    And "Penpot/Doomed" is in the Nextcloud trash
    When someone restores only "Alpha" in Penpot
    Then Penpot holds a project named "Doomed"
    And "Penpot/Doomed" is not in the Nextcloud trash
    And the design "Beta" is in Penpot's trash

    # Penpot clears a project's deletion when any file inside it comes back, so one
    # design revives the project — and restoring a hundred would say nothing more.
