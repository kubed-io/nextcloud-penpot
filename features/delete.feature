# Deleting a mirrored design — TWO TRASHES THAT MIRROR EACH OTHER.
#
# ## THIS FILE REVERSED ITS OWN RULE. READ WHY BEFORE REVERSING IT BACK.
#
# It used to open: "NEXTCLOUD-SIDE delete → the local mirror. Purely local,
# ALWAYS. Penpot is never contacted." That was written under §6.34, which
# believed Penpot's trash was unreachable by API and therefore that `delete-file`
# was destructive. §6.52 disproved both halves, and §C6.11 re-confirmed them live:
# `delete-file` is a SOFT delete into a real trash that keeps the design for ~7
# days with its id, revision and history intact.
#
# Once that is true, "purely local" stops being the safe choice and starts being
# the surprising one — a user who deletes a design in Nextcloud and finds it
# still in Penpot has not been protected, they have been ignored.
#
# ## THE MAPPING: EACH GESTURE GETS THE OPERATION WITH THE SAME REVERSIBILITY
#
# This is the rule the whole feature comes from, and it is the same one
# nextcloud-n8n uses — with one difference that makes it fit BETTER here: n8n and
# Grafana have no trash of their own, so their soft step has to invent one
# (archive, untag). Penpot has a real trash, so the two sides line up exactly:
#
#   | Nextcloud gesture      | Penpot RPC                      | Reversible? |
#   |------------------------|---------------------------------|-------------|
#   | Delete (→ NC trash)    | delete-file (→ Penpot trash)    | yes, ~7d    |
#   | Empty trash / purge    | permanently-delete-team-files   | NO          |
#   | Restore from NC trash  | restore-deleted-team-files      | n/a — it IS the undo |
#
# ## ONE EVENT, TWO STEPS, TOLD APART BY PATH
#
# Nextcloud fires `BeforeNodeDeletedEvent` for BOTH steps. They are distinguished
# by where the node lives when it fires (the n8n mechanism, ported):
#
#   <uid>/files/…                  → the first delete, on its way to the trash
#   <uid>/files_trashbin/files/…   → the purge, the irreversible one
#
# ...EXCEPT THE SECOND ONE IS NOT AN EVENT AT ALL (saga §C6.13). Nextcloud fires
# `BeforeNodeDeletedEvent` for the first delete and NOTHING TYPED for the purge —
# the trashbin emits a legacy `\OCP\Trashbin` `preDelete` hook instead. The path
# discrimination above is how the two are told apart in principle; in practice
# they arrive through two different doors. Nothing a user sees changes, but a
# reader looking for the purge in the delete listener will not find it there.
#
# A trash-BYPASSED delete (admin disabled the trash, or `X-NC-Skip-Trashbin`)
# never produces the soft step. It is a known gap rather than a handled case:
# there is no trash for the purge hook to fire from, so the design is left in
# Penpot's trash and expires on Penpot's own schedule.
#
# ## THE PURGE GUARD, AND WHY IT IS NOT OPTIONAL (saga §C6.11)
#
# `permanently-delete-team-files` DOES NOT CHECK THAT THE FILE IS IN THE TRASH.
# Proven live: a design that had been restored — live, listed in its project —
# was destroyed by passing its id to that command. It is not "empty the trash",
# it is "destroy these designs", and it has no safety of its own.
#
# So the ids handed to it may come from exactly one place: a fresh
# `get-team-deleted-files` listing. Never from the mirror's metadata, never from
# a user's selection, never from anything the app worked out for itself. If the
# id is not in that listing, the purge does nothing — the design was already
# purged, or someone restored it in Penpot's own UI, and either way destroying it
# is not what the user asked for.
#
# ## RESTORE, AND WHY IT WAS ITS OWN SLICE
#
# It is now built (§C6.15), and the gap this header used to describe — restore
# the mirror, and the next pull trashes it again because the design is still in
# Penpot's trash — is closed. Two things made it worth a slice of its own rather
# than a line in this one, and both are about the same command lying:
#
#   - `restore-deleted-team-files` answers 200 with an EMPTY SET for an id it did
#     not restore (§C6.11). No error. So the `end` event's PAYLOAD — the ids
#     actually restored — is the only honest answer, and the app compares it
#     against what it asked for.
#   - §6.49 once saw that event arrive while `deleted_at` was still set. It did
#     not reproduce on 2.17.0; the confirming re-read stays anyway, because one
#     non-reproduction does not disprove a race and the read costs one listing.
#
# A restore that reported success without restoring is worse than an error: the
# user stops looking for the file.
#
# WHAT "RESTORE" MEANS DEPENDS ON WHAT SURVIVED (saga §6.52), and the app picks
# the cheapest, most lossless layer that applies — never the other way round:
#
#   1. the design still exists in Penpot   → Penpot is not contacted at all
#   2. it is in Penpot's trash (~7 days)   → restore-deleted-team-files, LOSSLESS
#   3. it is gone for good                 → only the local archive is left, and
#                                            importing it mints a NEW id (§6.20).
#                                            NOT BUILT — see restore.feature.
#
# @todo — the scenarios here are the full specification; the live half runs in
# gestures.feature, which drives the real DAV gestures against a real Penpot.

@todo
Feature: Deleting designs, locally and in Penpot
  As a Nextcloud user
  I want local deletes to stay local and Penpot deletes to be recoverable
  So that removing a file is never a surprise and never silently costs me work

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── the soft step: a delete reaches Penpot's trash ────────────────────────

  Scenario: Deleting a mirrored file moves the design to Penpot's trash
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I move it to the trash
    Then the design is in Penpot's trash listing
    And it is no longer listed in the "My Stuff" project
    And the design keeps its id, revision and history
    And the trashed file keeps its "penpot_id" metadata intact
    # Both sides are now soft. Nothing here is irreversible, which is exactly
    # what makes it safe to do without asking.

  Scenario: Both modes delete identically
    Given a mirrored ".penpot" file in "link" mode
    And a mirrored ".penpot" file in "sync" mode
    When I move each of them to the trash
    Then both designs are in Penpot's trash listing
    # The mode governs whether we hold the bytes (§6.22), never what the design
    # IS. A link is not "less deleted" than a sync.

  Scenario: Deleting an untracked ".penpot" file touches nothing in Penpot
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I delete it
    Then Penpot is never contacted
    # No id, nothing to delete. This is also what keeps a mapped folder usable
    # as an ordinary folder.

  Scenario: A design already gone from Penpot deletes locally without complaint
    Given a mirrored ".penpot" file whose design no longer exists in Penpot
    When I move it to the trash
    Then the local file is trashed
    And the failure is not reported to the user as an error
    # Being asked to delete something already deleted is not a problem, it is
    # the outcome the user wanted.

  # ── the hard step: emptying the trash purges Penpot ───────────────────────

  Scenario: Purging a mirror from the Nextcloud trash destroys the design
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When I purge it from the Nextcloud trash
    Then the design is permanently deleted in Penpot
    And it is no longer in Penpot's trash listing
    # The one irreversible thing this app can cause, and it is reached only by
    # the one irreversible gesture Nextcloud offers.

  # THE GUARD (saga §C6.11). permanently-delete-team-files does NOT check that a
  # file is in the trash — a live design handed to it is destroyed. Proven live.
  Scenario: A purge only ever passes ids that are in Penpot's trash listing
    Given a trashed ".penpot" file
    When I purge it from the Nextcloud trash
    Then the app reads Penpot's trash listing first
    And it passes only ids found in that listing to the permanent delete
    And an id absent from that listing is never passed

  Scenario: Purging a mirror whose design was restored in Penpot destroys nothing
    Given a trashed ".penpot" file whose design someone restored in Penpot's own UI
    When I purge it from the Nextcloud trash
    Then the local file is purged
    And the design in Penpot is left completely alone
    # Without the guard this is the case that silently destroys live work: the
    # id is still on the trashed mirror, and the command would happily take it.

  Scenario: A trash-bypassed delete is treated as the permanent one
    Given the instance has the trash disabled
    And a mirrored ".penpot" file
    When I delete it
    Then the design is permanently deleted in Penpot
    # There is no soft step to be had — the file never reaches a trash. Treating
    # it as the soft step would mean turning the trash off quietly stops deletes
    # reaching Penpot at all.

  # ── restore: the delete, undone ───────────────────────────────────────────

  Scenario: Restoring a trashed file puts the local mirror back, unchanged
    Given a trashed ".penpot" file that still carries its "penpot_id"
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And it keeps the same "penpot_id" it had before being trashed
    # The fileid survives the trash, so the metadata does too (§6.44/§6.45).
    # That id-stability is why restore needs no extra state of its own.

  Scenario: Restoring the mirror also restores the design in Penpot
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When I restore it from the Nextcloud trash
    Then the design is restored in Penpot
    And it comes back with its id, revision, history and links intact
    And nothing is imported and nothing is re-created

  Scenario: A restore that Penpot did not actually perform is never reported as success
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When the restore stream answers with an empty set of ids
    Then the app does not report the design as restored
    And the local file stays where the user restored it
    # §C6.11: handed an id it does not restore, Penpot answers 200 with an `end`
    # event carrying an EMPTY SET. The ids in that set — not the status, not the
    # existence of the event — are the answer.

  Scenario: A restore is confirmed against the listing the pull reads
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When the restore reports the design's id as restored
    Then the app checks the design is back in its project's listing
    And a design that is not there yet is restored a second time
    And a design still missing after that is reported as a failure
    # NOT "is it out of the trash?", which sounds equivalent and is not. Penpot's
    # restore returns BEFORE its transaction settles (§6.49), and in that window
    # the trash listing can stop naming a design while the project listing still
    # omits it. The pull decides what to prune from the project listing, so that
    # is the only answer worth having — asking the other one failed this file's
    # own scenario about half the time (§C6.15).

  Scenario: A pull after a restore neither prunes the mirror nor duplicates it
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

  # ── the layers restore does NOT use, and why it says so ───────────────────

  Scenario: A design that never left Penpot is restored locally and nothing is sent
    Given a trashed ".penpot" file whose design still exists in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And Penpot is never contacted to restore it
    # Layer 1. The mirror was trashed while Penpot was unreachable, or someone
    # restored the design in Penpot's own UI first. Nothing was ever lost
    # remotely, so taking the file out of the trash IS the whole restore.

  Scenario: A design that is gone for good is not silently recreated
    Given a trashed ".penpot" file whose design was permanently deleted in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And no design is created in Penpot
    And the app reports that the design is gone and the mirror is now the only copy
    # Layer 3, and it is NOT BUILT: importing the archive would mint a NEW id
    # (§6.20 — a purged id cannot be resurrected, tested directly), so it is a
    # user decision with real consequences, specified in restore.feature. The one
    # thing that must not happen is quietly doing nothing.

  Scenario: An untracked file coming out of the trash is never restored into Penpot
    Given a ".penpot" file with no "penpot_id" in the Nextcloud trash
    When I restore it from the Nextcloud trash
    Then Penpot is never contacted
    # Restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's open fork.

  # ── deleting a link file HIDES it (saga §6.43) ──────────────────────────────
  # There is nothing to delete: the design lives in Penpot and the local file is
  # a pointer with no content. So a local delete of a link is a VISIBILITY
  # operation, not a destructive one.
  #
  # NOT BUILT YET, AND THE REASON IS THE SCENARIO AFTER NEXT. Today a `link`
  # deletes exactly like a `sync` — the design goes to Penpot's trash and the
  # restore brings it back. Making the delete local requires the pull to read the
  # Nextcloud trash first ("A pull does not recreate a link the user dismissed"),
  # because otherwise a dismissed link reappears on the very next run and the user
  # is in an argument with the reconciler. The two are one slice, and it is not
  # this one. Stated here rather than left as a scenario that quietly does not
  # match the code.

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
    And the design no longer appears in the "Northwind" team's project listings
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
