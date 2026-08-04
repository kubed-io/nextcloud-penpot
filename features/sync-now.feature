# Notes, decisions and history for this feature: AGENTS.md#sync-now

Feature: Syncing a mapped Penpot team into Nextcloud
  As an admin who has just mapped a team
  I want the designs already in Penpot to appear in Nextcloud
  So that the mirror starts out true, however the sync was started

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  # ── one behaviour, four ways to start it ───────────────────────────────────
  #
  #   actor    | scope
  #   ---------+---------------------
  #   admin    | one mapping          the card's "Sync now"
  #   admin    | every mapping        the section's "Sync from Penpot"
  #   schedule | every mapping        time as the actor
  #   user     | their personal team  the personal "Sync now"
  #
  # Same pre-state, same post-state. The actor and the scope are the only things
  # that differ, so they are COLUMNS rather than four scenarios. Whether a run is
  # synchronous or queued is a mechanism, and is asserted nowhere.
  #
  # THIS FILE IS THE FIRST SYNC, AND ONLY THAT. A later run only has work to do
  # because something changed in Penpot — and every one of those is a scenario
  # about the change, not about the sync: a design deleted upstream belongs to
  # delete-design.feature, a project renamed upstream to rename-project.feature.
  # There is no "second sync" behaviour left to describe once those are theirs.
  # notes: AGENTS.md#sync-now-scope

  Scenario Outline: A sync brings the team's projects and designs into Nextcloud
    Given a Penpot team named "Design Team" is mapped to the folder "<folder>"
    And the Penpot team already contains:
      | project | design     |
      | Cogs    | Gizmo      |
      | Cogs    | Doohickey  |
      | Levers  | Sprocket   |
      | Drafts  | Loose Idea |
    When <actor> syncs <scope>
    Then the sync succeeds
    And the folder "<folder>" carries the team's Penpot id
    And the mapped folder holds:
      | path                            | tagged |
      | <folder>/Cogs                   | penpot |
      | <folder>/Cogs/Gizmo.penpot      | -      |
      | <folder>/Cogs/Doohickey.penpot  | -      |
      | <folder>/Levers                 | penpot |
      | <folder>/Levers/Sprocket.penpot | -      |
      | <folder>/Loose Idea.penpot      | -      |
    And there is no node at "<folder>/Drafts"
    # PROJECTS COME IN BY NAME AND WEAR THE TAG; designs come in beneath them.
    # Drafts is the team's default project and gets no folder of its own — it IS
    # the mapped folder (§6.35), so a loose design sits at the root.

    Examples: the triggers this harness can fire
      | actor     | scope         | folder       |
      | the admin | one mapping   | One Mapping  |
      | the admin | every mapping | All Mappings |

  @todo
  Scenario: The schedule does the same, with time as the actor
    Given a Penpot team named "Design Team" is mapped to the folder "On Schedule"
    And the Penpot team already contains:
      | project | design    |
      | Timed   | Clockwork |
    When the schedule syncs every mapping
    Then the sync succeeds
    And the mapped folder holds:
      | path                               | tagged |
      | On Schedule/Timed                  | penpot |
      | On Schedule/Timed/Clockwork.penpot | -      |
    # The row that belongs in the outline above and cannot sit there yet: the job
    # is built (ScheduledPullJob), but this harness has no way to make time pass.
    # notes: AGENTS.md#sync-now-scope

  @unbuilt
  Scenario: A user syncs their own personal team
    Given the user has a personal Penpot token configured
    When the user syncs their personal team
    Then the sync succeeds
    And the designs their token can see are in their personal folder
    # notes: AGENTS.md#a-user-syncs-their-own-personal-team-folder

  # ── what a first sync does with a tree that is already there ───────────────

  Scenario: A folder already named like a Penpot project is adopted, not duplicated
    Given a Penpot team named "Design Team" is mapped to the folder "Adopted"
    And a folder "Adopted/Handmade" already exists
    And the Penpot team already contains:
      | project  | design |
      | Handmade | Sketch |
    When the admin syncs every mapping
    Then the sync succeeds
    And there is no node at "Adopted/Handmade (2)"
    And the folder "Adopted/Handmade" carries a Penpot project id
    And the mapped folder holds:
      | path                           | tagged |
      | Adopted/Handmade               | penpot |
      | Adopted/Handmade/Sketch.penpot | -      |
    # THE NAME IS ALL THERE IS TO MATCH ON the first time — a hand-made folder
    # carries no project id yet. Adopting it is what stops a first sync over an
    # existing tree from leaving a second folder beside the one someone made.
    # From then on it is the id that identifies the project, which is why a
    # rename upstream moves this folder rather than making another.

  # ── what a mirror carries when it arrives ──────────────────────────────────

    # notes: AGENTS.md#a-mirrored-design-carries-the-designs-own-dates-not-the-pulls

  @in-penpot @occ
  Scenario: A mirror carries its own dates, not the sync's
    Given a Penpot team named "Design Team" is mapped to the folder "Design Dates"
    And a mirrored design "Dated" in the project "Clocks"
    Then "Design Dates/Clocks/Dated.penpot" is dated when the design changed in Penpot
    And "Design Dates/Clocks/Dated.penpot" was created when the design was created in Penpot
    And the folder "Design Dates/Clocks" was created when its Penpot project was

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

    # UNBUILT, AND THE GAP IS REAL. `occ penpot_sync:sync` catches only
    # OutOfBoundsException — an unknown mapping id. A PenpotApiException escapes
    # uncaught, so today an unreachable Penpot produces a stack trace instead of a
    # sentence naming the fix. Both front doors need the same answer, which is why
    # this is one scenario rather than a CLI one and a UI one.
    # notes: AGENTS.md#a-sync-that-cannot-finish-says-so-and-says-why

  # ── still to specify ───────────────────────────────────────────────────────

  @todo
  Scenario: Project folder names always match their Penpot projects
    Given a Penpot team named "Design Team" is mapped to the folder "Named Sync"
    And the team has been mirrored into Nextcloud
    Then every project folder is named exactly as Penpot names that project
    And the app never lets a project folder's name diverge from its project's
    # notes: AGENTS.md#project-folder-names-always-match-their-penpot-projects

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
    # Penpot permits two projects with one name; Nextcloud does not permit two
    # folders with one name in one parent. freeName() already picks a free one —
    # what is unspecified is how the admin is TOLD, which is why this is @todo.
    # notes: AGENTS.md#two-penpot-projects-in-one-team-sharing-a-name-is-handled-not-crashed
