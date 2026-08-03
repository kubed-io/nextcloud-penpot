# RESTORING A DESIGN — out of the Nextcloud trash, out of Penpot's trash, or out
# of an archive when both are gone. Restoring a PROJECT is restore-project.feature.
#
# ## THE ORDER IS THE FEATURE (saga §6.49/§C6.11)
#
# The app always offers Penpot's own trash BEFORE an archive import, and the
# difference is not cosmetic: a trash restore returns the SAME design — same id,
# same revision, same history — while an import creates a new one that merely
# looks like it. Only once Penpot's grace window has closed is an import the best
# that remains, and the app says so rather than quietly producing a lookalike.
#
# ## A RESTORE IS CONFIRMED, NEVER ASSUMED
#
# Penpot's success event is not proof: the restore is re-read from the same
# listing the pull uses before it is reported as done. A restore that did not
# actually happen must never be announced as one.
#
# ## A LINK HAS NOTHING TO RESTORE INTO PENPOT
#
# Restoring a dismissed `link` un-hides a pointer. It never pushes anything back
# into Penpot, in any circumstance.

Feature: Restoring a mirrored design
  As a Nextcloud user
  I want a restore to bring back the same design rather than a copy of it
  So that undo means undo, with its id and history intact
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Restoring a design brings back the file and its design together
    Given a mirrored design "Round Trip" in the project "Stay Put"
    And "Penpot/Stay Put/Round Trip.penpot" is in the trash
    When I restore "Penpot/Stay Put/Round Trip.penpot" from the Nextcloud trash
    Then the file "Penpot/Stay Put/Round Trip.penpot" carries a Penpot id
    And Penpot project "Stay Put" holds a design named "Round Trip"
    And the design "Round Trip" is not in Penpot's trash
    # ONE BEHAVIOUR, AND IT USED TO BE THREE.
    #
    # There were three scenarios here: this gesture, "a pull after a restore
    # neither prunes the mirror nor duplicates it", and "a pull after a restore
    # does not trash the mirror a second time". The last two were not behaviours.
    # They asked whether the SCHEDULED SYNC treats a restored item like any other
    # mapped item — which is the reconciler, and the reconciler is how this app
    # works, not something a user does. Writing it as a scenario also put the
    # gesture in the Given block ("And I delete …") and the machine in the When
    # block ("And the team has been mirrored into Nextcloud"), so the file read as
    # though a user deletes things during setup and runs syncs by hand.
    #
    # What is left is what the user actually does and what they actually get: the
    # file comes back, the design comes back with it, and the id is the same one —
    # so nothing downstream can mistake it for a new design. Once that holds, the
    # sync has an id and a listing like any other mirror and needs no scenario of
    # its own. Not duplicating and not re-trashing are guarantees the CODE owes,
    # and RestoreServiceTest is where they are pinned.
    #
    # The pre-state is state, not a gesture: "is in the trash" says what is true
    # before the behaviour, and how it got that way is the step definition's
    # business.

  @in-nextcloud @gesture
  Scenario: Restoring a file that was never in Penpot leaves Penpot alone
    Given an uploaded ".penpot" archive at "Loose Design.penpot"
    And "Loose Design.penpot" is in the trash
    When I restore "Loose Design.penpot" from the Nextcloud trash
    Then the file "Loose Design.penpot" is not in the Nextcloud trash
    And the file "Loose Design.penpot" carries no Penpot id
    # A restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's open fork, and
    # it must not happen by accident on the way out of the trash.
    #
    # THE OLD VERSION ASSUMED SOMETHING THE APP DOES NOT ALLOW. It staged a
    # "mirrored design in the project Bystander", uploaded a second, untracked
    # `.penpot` NEXT TO IT inside that same mapped project folder, and then
    # asserted the bystander was still there — an uninvolved file being uninvolved,
    # which is not a claim worth a scenario unless the two could collide, and they
    # cannot.
    #
    # Worse, it implied a `.penpot` file can sit inside a mapped project folder
    # WITHOUT being in Penpot. Nothing in this app produces that state: anything
    # under a mapping is mirrored. An opt-out marker — a `penpot:ignore` tag, say —
    # would create it, and that idea has never been designed, only implied by this
    # scenario. It is written down in the saga as an open question rather than
    # smuggled in as a fact the spec depends on.
    #
    # So the file lives OUTSIDE every mapping, which is the only way a `.penpot`
    # file is genuinely untracked today, and the assertions are about it alone.

    # ── restore is never automatic ───────────────────────────────────────────────

  @unbuilt
  Scenario: Restoring is always confirmed, never silent
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until I confirm
    And declining leaves the file in place as ordinary tolerated content

  @unbuilt
  Scenario: A file with no archive cannot be restored
    Given an unmapped ".penpot" file in "link" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then no restore is offered
    And the app explains that a link file holds no archive to restore from
    # You cannot put back bytes you never had.

  @unbuilt
  Scenario: An untracked file is never restored, because it was never in Penpot
    Given a ".penpot" file with no "penpot_id"
    When I move the file into the "My Stuff" folder
    Then no restore is offered
    And Penpot is never contacted
    # Creating brand-new Penpot files from Nextcloud is a separate, still-open
    # fork (team-import.feature) — restore only ever puts BACK something that
    # this app previously mirrored out.

    # ── the good case: the original still exists ─────────────────────────────────

  @unbuilt
  Scenario: Restoring over a design that still exists keeps its identity
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    And the Penpot file with that id still exists
    When I choose to restore it
    Then the app tells me the existing design's contents will be replaced in place
    And it tells me the design keeps its id, its links, and its history
    When I confirm
    Then the archive is imported into the existing Penpot file
    And the restored Penpot file has the same id it had before
    And no duplicate design is created in Penpot
    # Confirmed live (saga §6.20): in-place import returned the same file id, and
    # the project's file count was unchanged before and after.

    # ── the lossy case: the original is gone ─────────────────────────────────────

  @unbuilt
  Scenario: Restoring a deleted design states clearly what does and does not return
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    And the Penpot file with that id has been deleted in Penpot
    When I choose to restore it
    Then the app says the design's content will be rebuilt from the archive
    And it says the name, pages and assets come back
    But it says the design gets a NEW id, so old links stay broken
    And it says the edit history does not come back
    And nothing is sent to Penpot until I confirm
    # Stated as "here is what you get and what you don't", not as a warning about
    # failure — because the design itself really does come back (saga §6.41).
    # NOTE: this scenario only applies once Penpot's own ~7-day trash window has
    # closed. Inside it, the app restores losslessly instead — see below.
    # That last line matters: if the delete was recent, recovering it IN PENPOT
    # keeps the id, the links and the history — strictly better than what we can
    # offer. Pointing the user at the better option, even though it isn't ours,
    # is the honest thing to do (saga §6.26).

  @unbuilt
  Scenario: Confirming a restore of a deleted design creates a new file and re-points the mirror
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When I confirm the restore
    Then a new Penpot file is created in the "My Stuff" project
    And the Nextcloud file's "penpot_id" is updated to the NEW id
    And the old id is not kept as the file's identity
    And the file's "Open in Penpot" action points at the new design

  @unbuilt
  Scenario: The app never silently resurrects a deleted design
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When a pull runs
    Then the file is not restored automatically
    And Penpot is never contacted about it
    # Every restore is a human decision, because half of them are lossy.

    # ── the app must offer the BETTER layer when one applies ────────────────────

  @unbuilt
  Scenario: A design still in Penpot's trash is restored losslessly, not imported
    Given the design was deleted in Penpot within the last few days
    When I ask to restore it
    Then the app restores it with "restore-deleted-team-files"
    And no import is performed
    And the design keeps its original id, revision, history and links
    And the app makes clear this restore lost nothing
    # Layer 2 always beats layer 3 (saga §6.49/§6.52), and it is BUILT: the trash
    # listing is read before anything else is considered. Kept here as the rule
    # this file must obey; its live scenarios are in delete-design.feature.

  @unbuilt
  Scenario: A mirror in the Nextcloud trash is restored locally, not re-imported
    Given the design still exists in Penpot
    And its mirror was moved to the Nextcloud trash
    When I ask to restore it
    Then the app restores the file from the Nextcloud trash
    And Penpot is never contacted
    And no duplicate mirror is created
    # Layer 1 — nothing was ever lost remotely, so nothing needs sending. BUILT;
    # same note as above.

    # ── naming, and the two-call reality ─────────────────────────────────────────

  @blocked
  Scenario: A restored file takes the name from its archive, not from the request
    Given an unmapped ".penpot" file renamed locally to "My Renamed Design.penpot"
    And its archive's manifest still carries the original name "My firsty"
    When I confirm a restore that creates a new Penpot file
    Then the created Penpot file is named "My firsty", from the archive manifest
    And the app issues a follow-up rename to reconcile the name
    # The `name` param is ignored on import — confirmed live (saga §6.20). This
    # is two RPC calls, and the second can fail on its own; see errors.feature.

    # ── attribution ──────────────────────────────────────────────────────────────

  @blocked
  Scenario: A restore is attributed to the acting user when they have a personal token
    Given the user has a valid personal Penpot token
    When the user confirms a restore
    Then the import is performed using that user's own token
    And Penpot attributes the change to that user

  @blocked
  Scenario: A restore falls back to the service account, and says so
    Given the user has no personal Penpot token configured
    When the user confirms a restore
    Then the import is performed using the service-account token
    And the app tells the user the action was performed as the service account
    # Saga §6.18: attribution is the personal token's only job, and its absence
    # never blocks the action.

    # ── restore: the delete, undone ───────────────────────────────────────────

  @todo
  Scenario: Restoring a trashed file puts the local mirror back, unchanged
    Given a trashed ".penpot" file that still carries its "penpot_id"
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And it keeps the same "penpot_id" it had before being trashed
    # The fileid survives the trash, so the metadata does too (§6.44/§6.45).
    # That id-stability is why restore needs no extra state of its own.

  @unbuilt
  Scenario: Restoring the mirror also restores the design in Penpot
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When I restore it from the Nextcloud trash
    Then the design is restored in Penpot
    And it comes back with its id, revision, history and links intact
    And nothing is imported and nothing is re-created

  @todo
  Scenario: A restore that Penpot did not actually perform is never reported as success
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When the restore stream answers with an empty set of ids
    Then the app does not report the design as restored
    And the local file stays where the user restored it
    # §C6.11: handed an id it does not restore, Penpot answers 200 with an `end`
    # event carrying an EMPTY SET. The ids in that set — not the status, not the
    # existence of the event — are the answer.

  @todo
  Scenario: A restore is confirmed against the listing the pull reads
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When the restore reports the design's id as restored
    Then the app checks the design is back in its project's listing
    And it checks again, once an in-flight delete has had time to land
    And a design that is not there yet is restored a second time
    And a design still missing after that is reported as a failure
    # NOT "is it out of the trash?", which sounds equivalent and is not. Penpot's
    # restore returns BEFORE its transaction settles (§6.49), and in that window
    # the trash listing can stop naming a design while the project listing still
    # omits it. The pull decides what to prune from the project listing, so that
    # is the only answer worth having — asking the other one failed this file's
    # own scenario about half the time (§C6.15).

  @in-nextcloud @gesture @blocked
  Scenario: A pull after a restore leaves exactly one mirror, in any mode
    Given a mirrored ".penpot" file that I moved to the trash
    When I restore it from the Nextcloud trash
    And a pull runs
    Then exactly one mirrored file exists for that design
    And the mirror is not trashed again
    # THE GAP THIS SLICE CLOSED. Before it, the design stayed in Penpot's trash,
    # so the pull saw a design Penpot no longer named and pruned the mirror a
    # second time (with a final snapshot, C5.1). Nothing was lost; the file
    # appeared to delete itself twice, which is its own kind of bad. Restoring
    # the design upstream is what makes the pull leave the file alone.

    # ── restoring a whole PROJECT, and the asymmetry that makes it tricky ──────
    #
    # PENPOT HAS NO `restore-project` (checked in its source: projects.clj offers
    # create / rename / delete / pin and nothing else). A project comes back only
    # as a SIDE EFFECT of restoring one of its files — `restore-deleted-team-files`
    # collects the `project-id` of every file it restores and clears `deleted_at`
    # on those projects too.
    #
    # That makes restore asymmetric with delete, and the asymmetry is measured,
    # not inferred (§C6.19). Deleting a project with two designs trashes both.
    # Restoring ONE of them:
    #
    #     the project        → back, listed again by get-all-projects
    #     the file restored  → back in the project
    #     the OTHER file     → still in the trash
    #
    # So "restore the folder" must mean "restore every design that was in it",
    # in one call, or the user gets a project back with a hole in it. The one
    # call is also the only way to reach the project at all.
    #
    # AND A PROJECT DELETED WHILE EMPTY CANNOT BE RESTORED THROUGH THE API AT
    # ALL — there is no file to carry it back. It simply expires.

  @todo
  Scenario: A design that never left Penpot is restored locally and nothing is sent
    Given a trashed ".penpot" file whose design still exists in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And Penpot is never contacted to restore it
    # Layer 1. The mirror was trashed while Penpot was unreachable, or someone
    # restored the design in Penpot's own UI first. Nothing was ever lost
    # remotely, so taking the file out of the trash IS the whole restore.

  @unbuilt
  Scenario: A design that is gone for good is not silently recreated
    Given a trashed ".penpot" file whose design was permanently deleted in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And no design is created in Penpot
    And the app reports that the design is gone and the mirror is now the only copy
    # Layer 3, and it is NOT BUILT: importing the archive would mint a NEW id
    # (§6.20 — a purged id cannot be resurrected, tested directly), so it is a
    # user decision with real consequences, specified in restore-design.feature. The one
    # thing that must not happen is quietly doing nothing.

  @unbuilt
  Scenario: An untracked file coming out of the trash is never restored into Penpot
    Given a ".penpot" file with no "penpot_id" in the Nextcloud trash
    When I restore it from the Nextcloud trash
    Then Penpot is never contacted
    # Restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's open fork.
