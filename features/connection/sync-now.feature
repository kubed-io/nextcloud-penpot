# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync to bring every mapped team up to date
  So that the mirror stays true without anyone tending it

  Background:
    Given the app is connected to Penpot
    And Penpot holds these resources:
      | team           | project     | design     |
      | Design Team    | Cogs        | Gizmo      |
      | Design Team    | Cogs        | Doohickey  |
      | Design Team    | Region/Deep | Traffic    |
      | Design Team    | Drafts      | Loose Idea |
      | Second Team    | Coast       | Tides      |
      | Reference Team | Pinned      | Fixed      |
    And Nextcloud holds these resources:
      | path                          |
      | /Penpot/notes.txt          |
      | /Penpot/Cogs               |
      | /Penpot/Cogs/plan.txt      |
      | /Penpot/Cogs/Hand Made.penpot |
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |

  # notes: ../AGENTS.md#a-background-is-a-picture-not-a-story
  # The two sides do not agree yet, and the Background never says why they don't.

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @occ @ui @todo
  Scenario Outline: A sync from Penpot mounts every mapped folder, however it was started
    When <actor> syncs every mapping from Penpot
    Then Nextcloud holds exactly these resources:
      | path                               |
      | /Penpot/notes.txt                  |
      | /Penpot/Cogs                       |
      | /Penpot/Cogs/plan.txt              |
      | /Penpot/Cogs/Hand Made.penpot      |
      | /Penpot/Cogs/Gizmo.penpot          |
      | /Penpot/Cogs/Doohickey.penpot      |
      | /Penpot/Region                     |
      | /Penpot/Region/Deep                |
      | /Penpot/Region/Deep/Traffic.penpot |
      | /Penpot/Loose Idea.penpot          |
      | /Shared/Coast                      |
      | /Shared/Coast/Tides.penpot         |
      | /Pointers/Pinned                   |
      | /Pointers/Pinned/Fixed.penpot      |
    And "Penpot" holds:
      | penpot_team_id | the mapping's team |

    Examples: both ways an instance-wide sync starts
      | actor        |
      | the admin    |
      | the schedule |

  # notes: ../AGENTS.md#the-tree-is-the-assertion

    # ── RULE: the other direction — Nextcloud is declared the source of truth ──
    # notes: ../AGENTS.md#the-first-sync-to-penpot-makes-designs-of-the-files-already-there

  @admin @occ @ui @todo
  Scenario: The first sync to Penpot makes designs of the files already there
    When the admin syncs every mapping to Penpot
    Then Penpot holds exactly these resources:
      | team           | project     | design     |
      | Design Team    | Cogs        | Gizmo      |
      | Design Team    | Cogs        | Doohickey  |
      | Design Team    | Cogs        | Hand Made  |
      | Design Team    | Region/Deep | Traffic    |
      | Design Team    | Drafts      | Loose Idea |
      | Second Team    | Coast       | Tides      |
      | Reference Team | Pinned      | Fixed      |
    And "Penpot/Cogs/Hand Made.penpot" holds:
      | penpot_id      | set                |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |

    # A ".penpot" sitting in a mapped folder is not a design yet, and the button that
    # declares Nextcloud the source of truth is where it becomes one.

