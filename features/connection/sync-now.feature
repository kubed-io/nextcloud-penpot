# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync, in either direction, across every mapping at once
  So that the mirror stays true without anyone tending it — and so I can declare
  Nextcloud the source of truth on the day something has gone wrong in Penpot

  Background:
    Given the app is connected to Penpot
    And Penpot holds these resources:
      | team              | project     | design       |
      | Everything Team   | Widgets     | Sprocket A   |
      | Everything Team   | Widgets     | Sprocket B   |
      | Everything Team   | Deep/Nested | Buried       |
      | Everything Team   | Drafts      | Stray Sketch |
      | Everything Shared | Tideline    | Ebb          |
      | Everything Linked | Anchored    | Riveted      |
    And Nextcloud holds these resources:
      | path                                |
      | /All Sync/readme.txt                |
      | /All Sync/Widgets                   |
      | /All Sync/Widgets/outline.txt       |
      | /All Sync/Widgets/Local Only.penpot |
    And the following mappings were made:
      | team              | folder   | mode | storage      | groups |
      | Everything Team   | All Sync | sync | admin folder |        |
      | Everything Shared | All Team | sync | team folder  | admin  |
      | Everything Linked | All Link | link | admin folder |        |

  # notes: ../AGENTS.md#a-background-is-a-picture-not-a-story
  # The two sides do not agree yet, and the Background never says why they don't.

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @occ @ui
  Scenario Outline: A sync from Penpot mounts every mapped folder, however it was started
    When <actor> syncs every mapping from Penpot
    Then Nextcloud holds exactly these resources:
      | path                                |
      | /All Sync/readme.txt                |
      | /All Sync/Widgets                   |
      | /All Sync/Widgets/outline.txt       |
      | /All Sync/Widgets/Local Only.penpot |
      | /All Sync/Widgets/Sprocket A.penpot |
      | /All Sync/Widgets/Sprocket B.penpot |
      | /All Sync/Deep                       |
      | /All Sync/Deep/Nested               |
      | /All Sync/Deep/Nested/Buried.penpot |
      | /All Sync/Stray Sketch.penpot       |
      | /All Team/Tideline                  |
      | /All Team/Tideline/Ebb.penpot       |
      | /All Link/Anchored                  |
      | /All Link/Anchored/Riveted.penpot   |
    And "All Sync" holds:
      | penpot_team_id | the mapping's team |

    Examples: both ways an instance-wide sync starts
      | actor        |
      | the admin    |
      | the schedule |

    # notes: ../AGENTS.md#the-tree-is-the-assertion

    # ── RULE: the other direction — Nextcloud is declared the source of truth ──

  # notes: ../AGENTS.md#the-first-sync-to-penpot-makes-designs-of-the-files-already-there
  @admin @occ @ui
  Scenario: A sync to Penpot pushes archived designs into penpot if they are not there yet 
    When the admin syncs every mapping to Penpot
    Then Penpot holds exactly these resources:
      | team              | project     | design       |
      | Everything Team   | Widgets     | Sprocket A   |
      | Everything Team   | Widgets     | Sprocket B   |
      | Everything Team   | Widgets     | Local Only   |
      | Everything Team   | Deep/Nested | Buried       |
      | Everything Team   | Drafts      | Stray Sketch |
      | Everything Shared | Tideline    | Ebb          |
      | Everything Linked | Anchored    | Riveted      |
    And "All Sync/Widgets/Local Only.penpot" holds:
      | penpot_id      | set                |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |

    # A ".penpot" sitting in a mapped folder is not a design yet, and the button that
    # declares Nextcloud the source of truth is where it becomes one.
