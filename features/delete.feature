# Deletion, in two independent layers that are easy to confuse:
#
#   NEXTCLOUD-SIDE delete  → the local mirror. Purely local, ALWAYS. Penpot is
#                            never contacted. This is what deleting a .penpot
#                            file in the Files app means (saga §6.1).
#   PENPOT-SIDE delete     → a deliberate "Delete in Penpot" action that moves
#                            the design into Penpot's own trash.
#
# PENPOT HAS A REAL TRASH, AND WE USE IT (saga §6.49/§6.52). An earlier design
# (§6.34) built an opt-in "trash project" inside the service account's team,
# because §6.26 had concluded Penpot's own trash was unreachable by API. That was
# WRONG — the trash commands exist, they're just team-scoped:
#
#   get-team-deleted-files        {team-id}         → the trash listing
#   restore-deleted-team-files    {team-id, ids[]}  → restore (SSE)
#   permanently-delete-team-files {team-id, ids[]}  → hard delete (SSE)
#
# Verified live: a deleted file restores with its id, revision and history
# intact. So `delete-file` is NOT the destructive act §6.34 assumed — it puts the
# design in a trash that keeps it for ~7 days. The whole trash-bin setting, its
# trash-project config, and the origin bookkeeping are GONE. Deletion is now one
# behaviour with one honest description.
#
# THE ONE IRREVERSIBLE CALL is `permanently-delete-team-files`, and it is only
# ever reached through an explicit "delete permanently" action.
#
# @todo — no lib/ exists yet; and the read-only guard (make sure NOTHING
# accidentally calls Penpot on an ordinary Nextcloud delete) needs its own test.

@todo
Feature: Deleting designs, locally and in Penpot
  As a Nextcloud user
  I want local deletes to stay local and Penpot deletes to be recoverable
  So that removing a file is never a surprise and never silently costs me work

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── layer 1: ordinary Nextcloud deletes are always purely local ─────────────

  Scenario: Trashing a mirrored file never contacts Penpot
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I move it to the trash
    Then Penpot is never contacted
    And the design is completely unaffected in Penpot
    And the trashed file keeps its "penpot_id" metadata intact

  Scenario: Restoring a trashed file puts the local mirror back, unchanged
    Given a trashed ".penpot" file that still carries its "penpot_id"
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And it keeps the same "penpot_id" it had before being trashed
    And no new design, and no re-created design, is registered in Penpot

  Scenario: A pull does not duplicate a file I restored from the Nextcloud trash
    Given a mirrored ".penpot" file that I moved to the trash
    When I restore it from the Nextcloud trash
    And a pull runs
    Then exactly one mirrored file exists for that design
    And no second copy is created alongside it
    # The reconciler checks the trash for a matching "penpot_id" before creating
    # a mirror (saga §6.37) — otherwise restoring one file yields two.

  Scenario: Emptying the trash for a mirrored file never contacts Penpot
    Given a trashed ".penpot" file
    When I purge it from the Nextcloud trash
    Then Penpot is never contacted
    And the design, if it still exists in Penpot, is untouched

  Scenario: Deleting an untracked ".penpot" file touches nothing in Penpot
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I delete it
    Then Penpot is never contacted

  # ── deleting a link file HIDES it (saga §6.43) ──────────────────────────────
  # There is nothing to delete: the design lives in Penpot and the local file is
  # a pointer with no content. So a local delete of a link is a VISIBILITY
  # operation, not a destructive one.

  Scenario: Deleting a link file hides it instead of removing the design
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I move it to the trash
    Then Penpot is never contacted
    And the design is completely unaffected in Penpot
    And the trashed file keeps its "penpot_id" and "penpot_mode"
    # Being in the trash with that id IS the hidden state — see below.

  Scenario: A pull does not recreate a link the user dismissed
    Given a link file that the user has deleted
    When the pull runs and the design still exists in Penpot
    Then no mirrored file is recreated for it
    # Recreating a pointer the user just dismissed would be an endless argument
    # between the user and the reconciler.

  # THE TRASH IS THE HIDDEN MARKER (saga §6.45) — no separate flag exists.
  # A trashed Nextcloud file keeps its fileid, its "penpot_id" and its
  # "penpot_mode" (saga §6.44, tested live), so the reconciler just looks.
  Scenario: A hidden link is distinguishable from one that was never pulled
    Given a design in Penpot whose link file the user deleted
    And another design in Penpot that has never been pulled
    When the pull runs
    Then the dismissed design is recognised by its trashed file's "penpot_id" and left hidden
    And the never-pulled design gets a new mirrored file
    And no separate "hidden" flag is stored anywhere

  Scenario: Restoring a hidden link from the Nextcloud trash unhides it
    Given a link file the user deleted, now in the Nextcloud trash
    When the user restores it from the trash
    Then the file is back in its project folder
    And Penpot is never contacted by the restore
    And the pull refreshes it normally again

  Scenario: Emptying the trash un-hides a dismissed link
    Given a link file the user deleted, now in the Nextcloud trash
    When the user empties the Nextcloud trash
    And the pull runs
    Then the link reappears in its project folder
    # Coherent — the record of the dismissal was thrown away with it — but it
    # must be documented rather than discovered (saga open question #41).

  Scenario: A link is never restored into Penpot, in any circumstance
    Given a link file in the Nextcloud trash
    When it is restored, purged, or left there
    Then Penpot is never contacted
    And no import, create, or move is ever performed for it
    # "A link just says it's there in Penpot and shows it in Nextcloud, but the
    # file contents are never touched for any reason" — trashing and restoring a
    # link are purely local visibility operations (saga §6.45).

  # ── layer 2: deleting in Penpot — recoverable for ~7 days ───────────────────

  Scenario: Deleting in Penpot moves the design to Penpot's trash
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I choose "Delete in Penpot" and confirm
    Then "delete-file" is called
    And the app explains the design goes to Penpot's trash and can be restored for about a week
    And the design no longer appears in the "Ferronescotia" team's project listings
    And the local mirror is moved to the Nextcloud trash with its metadata intact

  Scenario: A design deleted in Penpot appears in Penpot's trash listing
    Given a design that was deleted in Penpot
    When the app lists that team's deleted files
    Then the design is listed, with the date it will be purged
    # get-team-deleted-files — team-scoped, which is why guessing file-scoped
    # command names found nothing (saga §6.49).

  Scenario: Restoring from Penpot's trash returns the design completely intact
    Given a design in Penpot's trash, deleted within the grace window
    When I restore it
    Then "restore-deleted-team-files" is called
    And the design is back with the SAME id it always had
    And its revision number and edit history are intact
    And its deep link works again
    And no import or re-creation was performed
    # Verified live (saga §6.49): same id, same revn, get-file returns 200 again.

  Scenario: A restore is confirmed by re-reading, never by the success event alone
    Given a design being restored from Penpot's trash
    When the restore stream reports success
    Then the app re-reads the design's state before reporting success to the user
    And a restore that did not actually take effect is reported as a failure
    # Confirmed live: the first restore call returned "end" while deleted_at was
    # still set; a second call cleared it. A silent no-op is worse than an error.

  Scenario: Restoring a design also restores its project if that was deleted too
    Given a Penpot project that was deleted, containing a design
    When the design is restored from Penpot's trash
    Then its containing project is restored as well
    And the project folder reappears on the next pull
    # Penpot's restore clears deleted_at on the project as well as the file.

  Scenario: The app always offers Penpot's trash before an archive import
    Given an unmapped ".penpot" file whose design was deleted in Penpot
    And the design is still inside Penpot's grace window
    When I ask to restore it
    Then the app restores it from Penpot's trash, not from the local archive
    And it explains that this restore loses nothing
    # Import is the last resort, not the default (saga §6.52) — see restore.feature.

  # ── the one irreversible act ────────────────────────────────────────────────

  Scenario: Permanent deletion is a separate, explicit action
    Given a design in Penpot's trash
    When I choose to delete it permanently
    Then the app warns this cannot be undone
    When I confirm
    Then "permanently-delete-team-files" is called
    And the design's id and history become permanently unreachable

  Scenario: An ordinary delete never reaches the permanent-delete call
    Given a mirrored ".penpot" file
    When I choose "Delete in Penpot" and confirm
    Then "permanently-delete-team-files" is never called
    # The only destructive call in the app is reachable only on its own action.

  Scenario: There is no app-managed trash-bin setting
    Given a freshly installed app
    Then no trash project setting exists
    And no design is ever moved into a service-account-owned trash project
    # WITHDRAWN DESIGN (saga §6.34 → §6.52). An earlier draft built exactly this,
    # on the false premise that Penpot's own trash was unreachable. It isn't —
    # and Penpot's trash preserves more, with no configuration and no bespoke
    # machinery. Moving a user's design into a robot's private team would also
    # have made it vanish for their whole team.

  # ── after the grace window ──────────────────────────────────────────────────

  Scenario: Once the grace window passes, only a best-effort import remains
    Given a design deleted in Penpot longer ago than the grace window
    And its ".penpot" archive still in the Nextcloud trash
    When I restore it
    Then the archive is imported back into Penpot
    And the design's name, pages, assets and revision number come back
    But it has a NEW id, and its edit history does not come back
    # Measured, not assumed (saga §6.41): a real export→import round trip
    # preserved name, revn 5, pages and assets — and produced 0 file_change rows
    # against the original's 5.

  Scenario: A link file has nothing to fall back on once the window closes
    Given a mirrored ".penpot" file in "link" mode
    When its design is deleted in Penpot
    Then the app takes a final snapshot while the design is still recoverable
    And the archive is written into the file before it is trashed locally
    # The one genuinely unrecoverable case, closed by saga §6.46 — see
    # reconcile.feature.
