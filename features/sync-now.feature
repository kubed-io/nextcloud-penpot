# Notes, decisions and history for this feature: AGENTS.md#sync-now

Feature: Syncing Penpot into Nextcloud, now or on a schedule
  As an admin who has just mapped a team
  I want the designs already in Penpot to appear in Nextcloud
  So that the mirror starts out true, and stays true without me watching it

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  # ── the admin syncs one mapping, and waits ─────────────────────────────────

  @admin @occ
  Scenario: A pull mirrors a mapped team's root folder and stamps its team id
    Given a Penpot team named "Design Team" is mapped to the folder "Team Root"
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Team Root" carries the team's Penpot id

  @admin @occ
  Scenario: A pull mirrors a project as a folder carrying its project id and its date
    Given a Penpot team named "Design Team" is mapped to the folder "Project Folders"
    And a Penpot project named "Widgets" exists in that team
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Project Folders/Widgets" carries a Penpot project id
    # notes: AGENTS.md#a-pull-mirrors-a-project-as-a-folder-carrying-its-project-id-and-its-date
    And the folder "Project Folders/Widgets" was created when its Penpot project was

  @admin @occ
  Scenario: A second pull reconciles in place and does not duplicate the folder
    Given a Penpot team named "Design Team" is mapped to the folder "Twice Pulled"
    And a Penpot project named "Widgets" exists in that team
    When the admin runs a pull
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And there is no node at "Twice Pulled/Widgets (2)"
    # IDEMPOTENCE IS THE WHOLE POINT of a reconciler: the second run must find
    # what the first one made, by id, and leave it alone.

    # notes: AGENTS.md#a-pull-that-changed-nothing-prunes-nothing

  @admin @occ
  Scenario: A pull that changed nothing prunes nothing
    Given a Penpot team named "Design Team" is mapped to the folder "Quiet Pull"
    And a Penpot project named "Untouched" exists in that team
    And a Penpot file named "Poster" exists in the project "Untouched"
    When the admin runs a pull
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And the pull pruned nothing

    # ── what a pull produces ─────────────────────────────────────────────────────

    # notes: AGENTS.md#a-mirrored-design-carries-the-designs-own-dates-not-the-pulls

  @in-penpot @occ
  Scenario: A mirrored design carries the design's own dates, not the pull's
    Given a Penpot team named "Design Team" is mapped to the folder "Design Dates"
    And a mirrored design "Dated" in the project "Clocks"
    Then "Design Dates/Clocks/Dated.penpot" is dated when the design changed in Penpot
    And "Design Dates/Clocks/Dated.penpot" was created when the design was created in Penpot

  @admin @occ
  Scenario: An unchanged pull moves no file's mtime or etag
    Given a Penpot team named "Design Team" is mapped to the folder "Steady State"
    And a mirrored design "Steady" in the project "Idempotent"
    And I note the mtime and etag of "Steady State/Idempotent/Steady.penpot"
    When the team is mirrored again
    Then "Steady State/Idempotent/Steady.penpot" has the same mtime and etag
    # notes: AGENTS.md#an-unchanged-pull-moves-no-files-mtime-or-etag

    # ── name and placement reconcile for free, in both modes ─────────────────────

  # ── what a sync does about designs Penpot no longer has ────────────────────

  @in-penpot @occ
  Scenario: A pull prunes a mirrored file whose Penpot file no longer exists
    Given a Penpot team named "Design Team" is mapped to the folder "Prune Target"
    And a mirrored design "Doomed" in the project "Prune Me"
    When the design "Doomed" is deleted in Penpot
    And the admin runs a pull
    Then the pull pruned 1 mirror
    And the file "Prune Target/Prune Me/Doomed.penpot" is in the Nextcloud trash
    # notes: AGENTS.md#a-pull-prunes-a-mirrored-file-whose-penpot-file-no-longer-exists

    # notes: AGENTS.md#a-link-file-gets-a-final-snapshot-before-being-pruned

  @in-penpot @occ
  Scenario: A link file gets a final snapshot before being pruned
    Given a Penpot team named "Design Team" is mapped to the folder "Snapshot Target"
    And a mirrored design "Rescued" in the project "Snapshot Me"
    When the design "Rescued" is deleted in Penpot
    And the admin runs a pull
    Then the pull pruned 1 mirror
    And the pull saved 1 final archive

  @in-penpot @occ
  Scenario: A sync file needs no snapshot, it already has one
    Given a Penpot team named "Design Team" is mapped to the folder "Kept Target"
    And a mirrored design "Already Kept" in the project "Has Archive"
    And "Kept Target/Has Archive/Already Kept.penpot" is a "sync" design
    When the design "Already Kept" is deleted in Penpot
    And the admin runs a pull
    Then the pull pruned 1 mirror
    And the pull saved 0 final archives
    # A `sync` file already holds its archive, so a second export would be work
    # with a knowable answer. The counter is the assertion: 1 pruned, 0 rescued.

  # ── the other two actors, not yet built ────────────────────────────────────

  @unbuilt
  Scenario: Syncing everything now runs in the background and says so
    Given two Penpot teams are mapped
    When the admin syncs everything now
    Then the sync is queued as a background job
    And the admin is told it is running rather than left waiting
    And every mapping's designs are in Nextcloud when it finishes
    # The one real difference from the per-mapping button: this one cannot be
    # synchronous, because "everything" has no bound.

  @unbuilt
  Scenario: The schedule's first run mirrors a team nobody has touched
    Given a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And nobody has synced it
    When the schedule comes round
    Then the team's designs are in Nextcloud
    # TIME IS THE ACTOR. Same outcome as the admin's button, and that is the
    # point — the schedule is not a different feature, it is a different trigger.

  @unbuilt
  Scenario: A user syncs their own personal team folder
    Given the user has a personal Penpot token configured
    When the user syncs their personal folder now
    Then the designs their token can see are in their personal folder
    # notes: AGENTS.md#a-user-syncs-their-own-personal-team-folder

  @decision
  Scenario: Users do not author their own team mappings
    Given a user who is not an admin
    Then they cannot map a Penpot team to a folder
    # notes: AGENTS.md#users-do-not-author-their-own-team-mappings
