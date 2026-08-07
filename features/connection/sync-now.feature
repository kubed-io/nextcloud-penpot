# Notes, decisions and history for this feature: ../AGENTS.md#sync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync to bring every mapped team up to date
  So that the mirror stays true without anyone tending it

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  Scenario Outline: A sync brings the team's projects and designs into Nextcloud
    Given a Penpot team named "Design Team" is mapped to the folder "<folder>"
    And the Penpot team already contains:
      | project | design     |
      | Cogs    | Gizmo      |
      | Cogs    | Doohickey  |
      | Levers  | Sprocket   |
      | Drafts  | Loose Idea |
    When <actor> syncs <scope>
    Then the folder "<folder>" carries the team's Penpot id
    And the mapped folder holds:
      | path                            | tagged |
      | <folder>/Cogs                   | penpot |
      | <folder>/Cogs/Gizmo.penpot      | -      |
      | <folder>/Cogs/Doohickey.penpot  | -      |
      | <folder>/Levers                 | penpot |
      | <folder>/Levers/Sprocket.penpot | -      |
      | <folder>/Loose Idea.penpot      | -      |
    And there is no node at "<folder>/Drafts"
    And the folder "<folder>/Cogs" carries its Penpot dates
    And "<folder>/Cogs/Gizmo.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_revision | set             |
      | penpot_mode     | "link"          |
      | content         | empty           |
      | modified        | the design's    |
      | created         | the design's    |
    And the run is recorded with when it ran and what it did
    # notes: ../AGENTS.md#a-sync-brings-the-teams-projects-and-designs-into-nextcloud

    Examples: both ways an instance-wide sync starts
      | actor        | scope         | folder       |
      | the admin    | every mapping | All Mappings |
      | the schedule | every mapping | On Schedule  |

  # @blocked — no browser, and no way to hold a run open while a second is issued.
  # notes: ../AGENTS.md#a-second-sync-started-while-one-is-running-does-not-queue-another
  @blocked
  Scenario: A second sync started while one is running does not queue another
    Given a sync of every mapping is already running
    When the admin syncs every mapping again
    Then no second run is queued
    And the running one is left to finish

  @unbuilt
  Scenario: A user syncs their own personal team
    Given the user has a personal Penpot token configured
    When the user syncs their personal team
    Then the designs their token can see are in their personal folder
    # notes: ../AGENTS.md#a-user-syncs-their-own-personal-team-folder

  # ── what a first sync does with a tree that is already there ───────────────

  Scenario: A folder already named like a Penpot project is adopted, not duplicated
    Given a Penpot team named "Design Team" is mapped to the folder "Adopted"
    And a folder "Adopted/Handmade" already exists
    And the Penpot team already contains:
      | project  | design |
      | Handmade | Sketch |
    When the admin syncs every mapping
    Then there is no node at "Adopted/Handmade (2)"
    And the folder "Adopted/Handmade" carries a Penpot project id
    And the mapped folder holds:
      | path                           | tagged |
      | Adopted/Handmade               | penpot |
      | Adopted/Handmade/Sketch.penpot | -      |
    # notes: ../AGENTS.md#a-folder-already-named-like-a-penpot-project-is-adopted-not-duplicated

  # ── when a sync cannot finish ──────────────────────────────────────────────

  @unbuilt
  Scenario Outline: A sync that cannot finish says so, and says why
    Given a Penpot team named "Design Team" is mapped to the folder "Broken Sync"
    And <what is wrong>
    When the admin syncs every mapping
    Then the sync fails
    And the failure explains "<reason>"

    Examples: the ways a sync cannot reach Penpot
      | what is wrong                          | reason         |
      | no service-account token is configured | token          |
      | the Penpot base URL points nowhere     | could not read |

    # notes: ../AGENTS.md#a-sync-that-cannot-finish-says-so-and-says-why

  # ── still to specify ───────────────────────────────────────────────────────

  @todo
  Scenario: Two Penpot projects in one team sharing a name is handled, not crashed
    Given a Penpot team named "Design Team" is mapped to the folder "Twin Sync"
    And the Penpot team already contains:
      | project | design |
      | Brand   | Logo   |
      | Brand   | Mark   |
    When the admin syncs every mapping
    Then both are mirrored without a folder-name collision
    And the app reports the ambiguity so an admin can rename one in Penpot
    # notes: ../AGENTS.md#two-penpot-projects-in-one-team-sharing-a-name-is-handled-not-crashed
