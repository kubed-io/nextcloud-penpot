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

Feature: Deleting designs, locally and in Penpot
  As a Nextcloud user
  I want local deletes to stay local and Penpot deletes to be recoverable
  So that removing a file is never a surprise and never silently costs me work

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

  # ══ DELETED IN NEXTCLOUD ═══════════════════════════════════════════════════
  #
  # Driven as real WebDAV DELETEs against a real Penpot. The two gestures below
  # are the two trashes, in order of reversibility.

  @in-nextcloud @gesture
  Scenario: Deleting a mirror moves the design into Penpot's trash
    Given a Penpot project named "Bin Me" exists in that team
    And a Penpot file named "Doomed" exists in the project "Bin Me"
    And the admin runs a pull
    When I delete "Penpot/Bin Me/Doomed.penpot"
    Then the design "Doomed" is in Penpot's trash
    And Penpot project "Bin Me" holds no design named "Doomed"
    # Soft on both sides. Nothing here is irreversible, which is what makes it
    # safe to do without asking.

  @in-nextcloud @gesture
  Scenario: Emptying the Nextcloud trash destroys the design in Penpot
    Given a Penpot project named "Purge Me" exists in that team
    And a Penpot file named "Gone For Good" exists in the project "Purge Me"
    And the admin runs a pull
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is not in Penpot's trash
    # The one irreversible thing this app can cause, reached only by the one
    # irreversible gesture Nextcloud offers. permanently-delete-team-files does
    # NOT check the trash itself (§C6.11) — the app reads the listing first, and
    # that guard is the only safety there is.

  @in-nextcloud @gesture
  Scenario: Deleting an untracked ".penpot" file leaves Penpot alone
    Given a Penpot project named "Untouched" exists in that team
    And a Penpot file named "Keep Me" exists in the project "Untouched"
    And the admin runs a pull
    And I upload a ".penpot" archive at "Penpot/Untouched/Not Ours.penpot"
    When I delete "Penpot/Untouched/Not Ours.penpot"
    Then Penpot project "Untouched" holds a design named "Keep Me"
    And the design "Keep Me" is not in Penpot's trash

  # ══ DELETED IN PENPOT ══════════════════════════════════════════════════════
  #
  # The mirror image, and it arrives via a sync run rather than an event: the
  # design stops being named by Penpot's listing, so the pull moves its mirror to
  # the Nextcloud trash. This is the PRUNE, and it is the most dangerous thing
  # this app does — every way of failing to ask (a 502, a project skipped for an
  # illegal name, a half-read listing) is indistinguishable from a deletion. The
  # safety half of it lives in reconcile.feature, where the run itself is spec'd.
  #
  # THE RULE WITH NO EXCEPTION: Nextcloud never purges a file because Penpot no
  # longer has it. The two trashes expire on schedules neither side controls —
  # Penpot's is ~7 days and not configurable, a Nextcloud instance may keep 30 —
  # so mirroring the purge would let every design that ages out of Penpot's trash
  # take the user's last copy with it, on a schedule nobody chose.

  @in-penpot
  Scenario: A design deleted in Penpot is snapshotted, then moved to the trash
    Given a Penpot project named "Doomed" exists in that team
    And a Penpot file named "Farewell" exists in the project "Doomed"
    When the admin runs a pull
    And the design "Farewell" is deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 1 final archive
    And there is no node at "Penpot/Doomed/Farewell.penpot"
    And the file "Penpot/Doomed/Farewell.penpot" is in the Nextcloud trash
    # THE CLAIM THE LIVE SUITE EXISTS FOR. The mirror was a `link` — a pointer
    # with no bytes — and the design it pointed at is gone. Penpot's grace window
    # turns an unrecoverable deletion into a recoverable one, so the pointer
    # becomes a real archive on its way to the trash.
    #
    # THE LAST LINE IS NOT DECORATION. "No node at that path" is equally true of
    # a hard delete — the one outcome this must never produce — so for three
    # courses "trash, never destroy" was a promise in a header and an assertion
    # in no scenario. It reached a user as *"the file left my folder and I cannot
    # find it in the trash"* before it reached a test (§C6.16).

  @in-penpot
  Scenario: A design that already had its archive needs no second export
    Given a Penpot project named "Kept" exists in that team
    And a Penpot file named "Backup" exists in the project "Kept"
    When the admin runs a pull
    And the admin promotes "Penpot/Kept/Backup.penpot" to "sync" mode
    And the design "Backup" is deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 0 final archives
    And the pull exported 0 archives
    # A `sync` file is already its own snapshot. Re-exporting it would download a
    # whole archive to replace an identical one — and would fail for exactly the
    # files most worth keeping, once the grace window closes.

  @in-penpot
  Scenario: A design purged in Penpot still only reaches the Nextcloud trash
    Given a Penpot project named "Erased" exists in that team
    And a Penpot file named "No Way Back" exists in the project "Erased"
    When the admin runs a pull
    And the design "No Way Back" is permanently deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And there is no node at "Penpot/Erased/No Way Back.penpot"
    And the file "Penpot/Erased/No Way Back.penpot" is in the Nextcloud trash
    # The design is gone from every Penpot listing AND from its trash, so nothing
    # about it can ever come back — and the mirror is still only trashed. This is
    # the case where the local file is genuinely the last copy of that design,
    # which is precisely why it must land somewhere recoverable.
    #
    # NOTHING IS ASSERTED ABOUT THE FINAL ARCHIVE HERE (§C6.16):
    # `permanently-delete-team-files` returns before the data is actually gone —
    # Penpot marks the rows and a worker removes them later — so `export-binfile`
    # can still succeed for seconds afterwards. Whether the snapshot lands is
    # Penpot's timing, not our behaviour.

  # ── the reconciler's field of view: VISIBLE FILES, and nothing else ───────
  #
  # THE RULE THAT MAKES THE ONE ABOVE SIMPLE. The reconciler walks the mapped
  # folder's directory listing, so a mirror already in the Nextcloud trash is not
  # merely spared — it is **not seen at all**. Once a file reaches the trash the
  # pull is finished with it, permanently, whatever Penpot does next.
  #
  # State this as a rule and a whole class of question stops existing. "Both
  # trashes hold it and then Penpot purges — now what?" has no answer to design,
  # because the reconciler was never looking. There is no cross-trash comparison,
  # and no schedule on which the app can take a user's last copy away.
  #
  # THE PRICE, NAMED: a design that comes back in Penpot while its old mirror
  # sits in the Nextcloud trash gets a NEW mirror, beside the trashed one — the
  # pull cannot re-adopt what it cannot see. reconcile.feature carries that as an
  # explicit open fork.

  @in-penpot
  Scenario: A mirror already in the Nextcloud trash is invisible to the pull
    Given a Penpot project named "Left Alone" exists in that team
    And a Penpot file named "Twice Dead" exists in the project "Left Alone"
    And the admin runs a pull
    When I delete "Penpot/Left Alone/Twice Dead.penpot"
    And the design "Twice Dead" is purged from Penpot's trash
    And the admin runs a pull
    Then the pull succeeds
    And the file "Penpot/Left Alone/Twice Dead.penpot" is in the Nextcloud trash
    And there is no node at "Penpot/Left Alone/Twice Dead.penpot"
    # THE SEQUENCE THE RULE EXISTS FOR, end to end: the user deletes the mirror
    # (which puts the design in Penpot's trash), then the design is destroyed in
    # Penpot for good. Both sides are now gone in their own way — and the pull
    # does nothing at all, because a trashed mirror was never in its field of
    # view: it is still in the trash afterwards, and no mirror reappeared for it.
    #
    # Asserted on this file rather than on the pull's prune COUNTER, which is a
    # claim about every mirror any scenario ever left in the shared folder.

  # ── the soft step: a delete reaches Penpot's trash ────────────────────────

  @todo
  Scenario: Deleting a mirrored file moves the design to Penpot's trash
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I move it to the trash
    Then the design is in Penpot's trash listing
    And it is no longer listed in the "My Stuff" project
    And the design keeps its id, revision and history
    And the trashed file keeps its "penpot_id" metadata intact
    # Both sides are now soft. Nothing here is irreversible, which is exactly
    # what makes it safe to do without asking.

  @todo
  Scenario: Both modes delete identically
    Given a mirrored ".penpot" file in "link" mode
    And a mirrored ".penpot" file in "sync" mode
    When I move each of them to the trash
    Then both designs are in Penpot's trash listing
    # The mode governs whether we hold the bytes (§6.22), never what the design
    # IS. A link is not "less deleted" than a sync.

  @todo
  Scenario: Deleting an untracked ".penpot" file touches nothing in Penpot
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I delete it
    Then Penpot is never contacted
    # No id, nothing to delete. This is also what keeps a mapped folder usable
    # as an ordinary folder.

  @todo
  Scenario: A design already gone from Penpot deletes locally without complaint
    Given a mirrored ".penpot" file whose design no longer exists in Penpot
    When I move it to the trash
    Then the local file is trashed
    And the failure is not reported to the user as an error
    # Being asked to delete something already deleted is not a problem, it is
    # the outcome the user wanted.

  # ── the hard step: emptying the trash purges Penpot ───────────────────────

  @todo
  Scenario: Purging a mirror from the Nextcloud trash destroys the design
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When I purge it from the Nextcloud trash
    Then the design is permanently deleted in Penpot
    And it is no longer in Penpot's trash listing
    # The one irreversible thing this app can cause, and it is reached only by
    # the one irreversible gesture Nextcloud offers.

  # THE GUARD (saga §C6.11). permanently-delete-team-files does NOT check that a
  # file is in the trash — a live design handed to it is destroyed. Proven live.
  @todo
  Scenario: A purge only ever passes ids that are in Penpot's trash listing
    Given a trashed ".penpot" file
    When I purge it from the Nextcloud trash
    Then the app reads Penpot's trash listing first
    And it passes only ids found in that listing to the permanent delete
    And an id absent from that listing is never passed

  @todo
  Scenario: Purging a mirror whose design was restored in Penpot destroys nothing
    Given a trashed ".penpot" file whose design someone restored in Penpot's own UI
    When I purge it from the Nextcloud trash
    Then the local file is purged
    And the design in Penpot is left completely alone
    # Without the guard this is the case that silently destroys live work: the
    # id is still on the trashed mirror, and the command would happily take it.

  @todo
  Scenario: A trash-bypassed delete is treated as the permanent one
    Given the instance has the trash disabled
    And a mirrored ".penpot" file
    When I delete it
    Then the design is permanently deleted in Penpot
    # There is no soft step to be had — the file never reaches a trash. Treating
    # it as the soft step would mean turning the trash off quietly stops deletes
    # reaching Penpot at all.

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

  @todo
  Scenario: Deleting a link file hides it instead of removing the design
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I move it to the trash
    Then Penpot is never contacted
    And the design is completely unaffected in Penpot
    And the trashed file keeps its "penpot_id" and "penpot_mode"
    # Being in the trash with that id IS the hidden state — see below.

  @todo
  Scenario: A pull does not recreate a link the user dismissed
    Given a link file that the user has deleted
    When the pull runs and the design still exists in Penpot
    Then no mirrored file is recreated for it
    # Recreating a pointer the user just dismissed would be an endless argument
    # between the user and the reconciler.

  # THE TRASH IS THE HIDDEN MARKER (saga §6.45) — no separate flag exists.
  # A trashed Nextcloud file keeps its fileid, its "penpot_id" and its
  # "penpot_mode" (saga §6.44, tested live), so the reconciler just looks.
  @todo
  Scenario: A hidden link is distinguishable from one that was never pulled
    Given a design in Penpot whose link file the user deleted
    And another design in Penpot that has never been pulled
    When the pull runs
    Then the dismissed design is recognised by its trashed file's "penpot_id" and left hidden
    And the never-pulled design gets a new mirrored file
    And no separate "hidden" flag is stored anywhere

  @todo
  Scenario: Restoring a hidden link from the Nextcloud trash unhides it
    Given a link file the user deleted, now in the Nextcloud trash
    When the user restores it from the trash
    Then the file is back in its project folder
    And Penpot is never contacted by the restore
    And the pull refreshes it normally again

  @todo
  Scenario: Emptying the trash un-hides a dismissed link
    Given a link file the user deleted, now in the Nextcloud trash
    When the user empties the Nextcloud trash
    And the pull runs
    Then the link reappears in its project folder
    # Coherent — the record of the dismissal was thrown away with it — but it
    # must be documented rather than discovered (saga open question #41).

  @todo
  Scenario: A link is never restored into Penpot, in any circumstance
    Given a link file in the Nextcloud trash
    When it is restored, purged, or left there
    Then Penpot is never contacted
    And no import, create, or move is ever performed for it
    # "A link just says it's there in Penpot and shows it in Nextcloud, but the
    # file contents are never touched for any reason" — trashing and restoring a
    # link are purely local visibility operations (saga §6.45).

  # ── layer 2: deleting in Penpot — recoverable for ~7 days ───────────────────

  @todo
  Scenario: Deleting in Penpot moves the design to Penpot's trash
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I choose "Delete in Penpot" and confirm
    Then "delete-file" is called
    And the app explains the design goes to Penpot's trash and can be restored for about a week
    And the design no longer appears in the "Northwind" team's project listings
    And the local mirror is moved to the Nextcloud trash with its metadata intact

  @todo
  Scenario: A design deleted in Penpot appears in Penpot's trash listing
    Given a design that was deleted in Penpot
    When the app lists that team's deleted files
    Then the design is listed, with the date it will be purged
    # get-team-deleted-files — team-scoped, which is why guessing file-scoped
    # command names found nothing (saga §6.49).

  @todo
  Scenario: Restoring from Penpot's trash returns the design completely intact
    Given a design in Penpot's trash, deleted within the grace window
    When I restore it
    Then "restore-deleted-team-files" is called
    And the design is back with the SAME id it always had
    And its revision number and edit history are intact
    And its deep link works again
    And no import or re-creation was performed
    # Verified live (saga §6.49): same id, same revn, get-file returns 200 again.

  @todo
  Scenario: A restore is confirmed by re-reading, never by the success event alone
    Given a design being restored from Penpot's trash
    When the restore stream reports success
    Then the app re-reads the design's state before reporting success to the user
    And a restore that did not actually take effect is reported as a failure
    # Confirmed live: the first restore call returned "end" while deleted_at was
    # still set; a second call cleared it. A silent no-op is worse than an error.

  @todo
  Scenario: Restoring a design also restores its project if that was deleted too
    Given a Penpot project that was deleted, containing a design
    When the design is restored from Penpot's trash
    Then its containing project is restored as well
    And the project folder reappears on the next pull
    # Penpot's restore clears deleted_at on the project as well as the file.

  @todo
  Scenario: The app always offers Penpot's trash before an archive import
    Given an unmapped ".penpot" file whose design was deleted in Penpot
    And the design is still inside Penpot's grace window
    When I ask to restore it
    Then the app restores it from Penpot's trash, not from the local archive
    And it explains that this restore loses nothing
    # Import is the last resort, not the default (saga §6.52) — see restore.feature.

  # ── the one irreversible act ────────────────────────────────────────────────

  @todo
  Scenario: Permanent deletion is a separate, explicit action
    Given a design in Penpot's trash
    When I choose to delete it permanently
    Then the app warns this cannot be undone
    When I confirm
    Then "permanently-delete-team-files" is called
    And the design's id and history become permanently unreachable

  @todo
  Scenario: An ordinary delete never reaches the permanent-delete call
    Given a mirrored ".penpot" file
    When I choose "Delete in Penpot" and confirm
    Then "permanently-delete-team-files" is never called
    # The only destructive call in the app is reachable only on its own action.

  @todo
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

  @todo
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

  @todo
  Scenario: A link file has nothing to fall back on once the window closes
    Given a mirrored ".penpot" file in "link" mode
    When its design is deleted in Penpot
    Then the app takes a final snapshot while the design is still recoverable
    And the archive is written into the file before it is trashed locally
    # The one genuinely unrecoverable case, closed by saga §6.46 — see
    # reconcile.feature.
