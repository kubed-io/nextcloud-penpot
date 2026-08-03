# SYNC NOW — bringing what is already in Penpot into Nextcloud.
#
# ## THE RECONCILER IS NOT A FEATURE (saga §C6.28)
#
# This file used to be `reconcile.feature`, and it had thirty-four scenarios. The
# reconciler is what carries every "from Penpot" change into Nextcloud — it is the
# mechanism BEHIND the behaviours, not one of them, and a file named after it
# collects scenarios for no better reason than that they travel through the same
# code. Three of the thirty-four were behaviours. Ten were rules with no actor and
# no gesture. Thirteen restated a verb another file already owns — a rename is a
# rename, and that it arrived via the reconciler is HOW, not WHAT.
#
# Half the file could not be built, and that was the tell rather than a coincidence:
# an unbuildable scenario is usually a scenario about the wrong thing.
#
# ## TWO ACTORS, AND THAT IS THE WHOLE FILE
#
#     admin   syncs one mapping now, and waits for it
#     admin   syncs everything now, which is a background job
#     time    the schedule comes round and does it with nobody asking
#
# Everything below is the OUTCOME of one of those. Mirroring a root, a project, a
# file, its dates; leaving an unchanged instance alone; pruning what Penpot no
# longer has — none of them is a separate behaviour, they are what a sync DOES.
#
# ## THE FIRST SYNC IS ITS OWN SITUATION
#
# Whatever put these designs in Penpot happened before this app existed, so it is
# out of scope by definition. That makes "existing designs arrive for the first
# time" a real and independent thing to describe — and it needs one or two designs
# to describe it, not a catalogue of every state a design can be in.
#
# ## A USER'S SYNC NOW IS THE SAME BEHAVIOUR
#
# Scoped by what their token can see in Penpot and what they can see in Nextcloud,
# but the end state is identical, and a scenario that differs only in scope is the
# same scenario. The one genuine difference is that the personal team mapping is
# AUTOMATIC, so a user's button is scoped to exactly one folder and needs no
# mapping card at all.

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
    Given a Penpot team is mapped to the folder "Team Root"
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Team Root" carries the team's Penpot id

  @admin @occ
  Scenario: A pull mirrors a project as a folder carrying its project id and its date
    Given a Penpot team is mapped to the folder "Project Folders"
    And a Penpot project named "Widgets" exists in that team
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Project Folders/Widgets" carries a Penpot project id
    # END STATE, not a feature of its own — the mirror comes into existence here, so
    # this is the one place its creation date is answerable. The folder takes its
    # CREATION time only: Nextcloud propagates a folder's mtime from its children, so
    # stamping that would be a fight lost on every pull that writes any design (and a
    # propagated mtime says something more useful anyway — that the project's contents
    # changed — since Penpot's project `modified-at` only moves on a rename).
    And the folder "Project Folders/Widgets" was created when its Penpot project was

  # ── and syncing again leaves a settled mirror alone ────────────────────────
  #
  # THE ANTI-CHURN GUARANTEE (§5.11). These read as properties of the machine, and
  # they are kept as scenarios only because they are the regression that started
  # this whole line of work: every file looking freshly modified on every tick.
  # The precise pinning lives in the unit tests; this is the end-to-end proof.

  @admin @occ
  Scenario: A second pull reconciles in place and does not duplicate the folder
    Given a Penpot team is mapped to the folder "Twice Pulled"
    And a Penpot project named "Widgets" exists in that team
    When the admin runs a pull
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And there is no node at "Twice Pulled/Widgets (2)"
    # IDEMPOTENCE IS THE WHOLE POINT of a reconciler: the second run must find
    # what the first one made, by id, and leave it alone.

    # ══ WHAT A RUN MUST REFUSE TO DO ═══════════════════════════════════════════
    #
    # Pruning is driven by "Penpot did not name this file", and every way of
    # failing to ask — a 502, a project skipped for an illegal name, a half-read
    # listing — is indistinguishable from a deletion. A regression here does not
    # throw; it quietly moves a team's mirrors to the trash on the next scheduled
    # run. So "prunes nothing" is asserted as a step rather than assumed.

  @admin @occ
  Scenario: A pull that changed nothing prunes nothing
    Given a Penpot team is mapped to the folder "Quiet Pull"
    And a Penpot project named "Untouched" exists in that team
    And a Penpot file named "Poster" exists in the project "Untouched"
    When the admin runs a pull
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And the pull pruned nothing
    # The safety property, asserted first because it is the one a regression
    # breaks silently. Pruning on a listing that simply did not mention a file is
    # the single most destructive thing this app could do.
    #
    # ITS OWN FOLDER, deliberately. This is the one assertion in the suite that
    # IS about the whole mapped folder, so it must not share one: every other
    # scenario's leftovers land in "Penpot", and a design any of them deleted in
    # Penpot is a mirror this pull would correctly prune. A fresh folder is
    # mirrored from the current listing and therefore has nothing to prune —
    # which is exactly the state this scenario means to describe.

    # ── what a pull produces ─────────────────────────────────────────────────────

    # ── the manual controls in admin settings ────────────────────────────────────
    # Both siblings expose a per-mapping "Sync now" button on each mapping card,
    # plus a section-wide bulk sync in the Sync Actions panel. This app has the
    # same two controls in the same two places — but only ONE direction, because
    # there is no content push (saga §6.1). See admin-section.feature for where
    # they sit; this is what they do.

  @in-penpot @occ
  Scenario: A mirrored design carries the design's own dates, not the pull's
    Given a Penpot team is mapped to the folder "Design Dates"
    And a mirrored design "Dated" in the project "Clocks"
    Then "Design Dates/Clocks/Dated.penpot" is dated when the design changed in Penpot
    And "Design Dates/Clocks/Dated.penpot" was created when the design was created in Penpot
    # The behaviour is "a design exists in Penpot and is mirrored"; these two are its
    # end state. A design gets BOTH clocks — unlike its project folder, a file's mtime
    # is not propagated from anything, so there is nothing to fight.

  @admin @occ
  Scenario: An unchanged pull moves no file's mtime or etag
    Given a Penpot team is mapped to the folder "Steady State"
    And a mirrored design "Steady" in the project "Idempotent"
    And I note the mtime and etag of "Steady State/Idempotent/Steady.penpot"
    When the team is mirrored again
    Then "Steady State/Idempotent/Steady.penpot" has the same mtime and etag
    # Now also the guard on the timestamp feature itself (§C6.24): stamping a clock
    # unconditionally would move both of these on every tick. `touch()` leaves a
    # file's own etag alone but propagates a fresh one to the parent folder, which is
    # what clients poll — so the stamp is conditional, and this scenario is what
    # proves it stayed that way.
    # NOT A MICRO-OPTIMISATION — it is what stops every desktop and mobile client
    # re-downloading the whole mapped folder after every scheduled pull. mtime and
    # etag ARE the sync protocol, so rewriting a byte-identical file is a
    # broadcast to every client that something changed.
    #
    # This app has always avoided it; BOTH siblings had to be fixed. `nextcloud-n8n`
    # and `nextcloud-grafana` each called `putContent()` on every mirror on every run
    # unconditionally, and a pull with nothing changed upstream moved both clocks on
    # every file — measured live, then fixed in each (§C6.19, and their own sagas).
    # Ours avoids it in two places, and BOTH are load-bearing rather than incidental:
    #   - `storeLink()` returns early on an already-empty file (§C6.6);
    #   - `driftedOrMissing()` gates the archive write on the revision signal.
    # A change to either that makes the write unconditional would pass every
    # other scenario in this suite, which is why this one exists.

    # ── name and placement reconcile for free, in both modes ─────────────────────

  # ── what a sync does about designs Penpot no longer has ────────────────────

  @in-penpot @occ
  Scenario: A pull prunes a mirrored file whose Penpot file no longer exists
    Given a Penpot team is mapped to the folder "Prune Target"
    And a mirrored design "Doomed" in the project "Prune Me"
    When the design "Doomed" is deleted in Penpot
    And the admin runs a pull
    Then the pull pruned 1 mirror
    And the file "Prune Target/Prune Me/Doomed.penpot" is in the Nextcloud trash
    # TRASH, NEVER DESTROY — the don't-lose-data rule. A pruned file is
    # recoverable for as long as the user's trash retention allows, which is what
    # makes the most dangerous thing this app does survivable.

    # THE FINAL SNAPSHOT (saga §6.46) — the app's one genuinely lossy moment,
    # fixed. A pruned "link" file would otherwise be a pointer to a design that no
    # longer exists, with nothing to rebuild from. But "export-binfile" still
    # exports a soft-deleted file for ~7 days (saga §6.42, confirmed live), and a
    # trashed Nextcloud file's content is writable (saga §6.44, confirmed live).

  @in-penpot @occ
  Scenario: A link file gets a final snapshot before being pruned
    Given a Penpot team is mapped to the folder "Snapshot Target"
    And a mirrored design "Rescued" in the project "Snapshot Me"
    When the design "Rescued" is deleted in Penpot
    And the admin runs a pull
    Then the pull pruned 1 mirror
    And the pull saved 1 final archive
    # The mirror is a `link` — it held nothing at all until this moment. The pull
    # exports the design one last time INSIDE Penpot's grace window and writes the
    # archive into the file before trashing it, so the user is left with a real,
    # openable `.penpot` rather than a pointer to nothing.

  @in-penpot @occ
  Scenario: A sync file needs no snapshot, it already has one
    Given a Penpot team is mapped to the folder "Kept Target"
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
    Given a Penpot team is mapped to the folder "Penpot"
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
    # SAME BEHAVIOUR, DIFFERENT SCOPE (§C6.28). No mapping card exists for this —
    # the personal team mapping is automatic, so there is exactly one folder to
    # sync and nothing to choose.

  @decision
  Scenario: Users do not author their own team mappings
    Given a user who is not an admin
    Then they cannot map a Penpot team to a folder
    # DELIBERATELY NOT BUILT, not merely unwritten. Letting users author mappings
    # breeds edge cases faster than anything else on the table: two users mapping
    # one team, a user mapping a team the service account cannot see, folders
    # orphaned when the admin removes the mapping underneath them. The personal
    # team mapping stays automatic and singular.
