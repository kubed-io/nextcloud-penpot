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

  @in-nextcloud @occ
  Scenario: A sync leaves content it does not manage alone
    Given a mirrored design "Managed" in the project "Mixed Contents"
    And I create an unrelated file at "Penpot/Mixed Contents/notes.txt"
    When the team is mirrored again
    Then the file "Penpot/Mixed Contents/notes.txt" is still there and untouched
    And the file "Penpot/Mixed Contents/notes.txt" carries no Penpot id
    # Pruning keys on metadata, never on extension or on where a file sits.
    # notes: ../AGENTS.md#a-sync-leaves-content-it-does-not-manage-alone

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

  # @blocked — no fault injection: a sync has to be killed mid-write, and one
  # design has to fail while its neighbours succeed.
  # notes: ../AGENTS.md#one-failure-never-costs-the-rest-of-the-sync
  @blocked
  Scenario Outline: One failure never costs the rest of the sync
    Given a mapped team whose designs are mirrored in "sync" mode
    When <one thing fails> during a sync of every mapping
    Then the failure is reported for <that one> alone
    And everything else is mirrored as normal

    Examples: the two scales a single failure can happen at
      | one thing fails            | that one    |
      | exporting one design       | that design |
      | reaching one mapped team   | that team   |

  # @blocked — no fault injection: the sync has to be killed mid-write.
  # notes: ../AGENTS.md#a-sync-that-dies-halfway-leaves-every-file-whole
  @blocked
  Scenario: A sync that dies halfway leaves every file whole
    Given a mapped team whose designs are mirrored in "sync" mode
    When a sync of every mapping is killed partway through
    Then every mirrored file is either its old version or its new one
    And no file is left holding a half-written archive

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
