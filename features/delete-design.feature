# DELETING A DESIGN — both bins, both directions, and the one irreversible path.
# Deleting a PROJECT (the folder) is delete-project.feature: one call, not one
# per design, and a different set of guards.
#
# ## TWO BINS, AND THEY ARE NOT SYMMETRIC (saga §C6.11)
#
# Nextcloud's trash and Penpot's trash are separate systems with separate
# retentions. An ordinary delete is SOFT on both sides — recoverable, and
# therefore safe to do without asking. Only emptying the Nextcloud trash reaches
# `permanently-delete-team-files`, the single irreversible thing this app can
# cause, and it is reached only by the single irreversible gesture Nextcloud
# offers.
#
# THE GUARD ON THAT CALL IS THE ONLY SAFETY THERE IS: Penpot does not check its
# own trash before permanently deleting, so the app reads the trash listing first
# and passes only ids that are in it. A design someone restored in Penpot between
# the two is therefore left alone.
#
# ## A LINK HAS NOTHING TO DELETE
#
# A `link` is zero bytes pointing at a design that lives elsewhere, so deleting
# one is a DISMISSAL, not a deletion — it hides the pointer and leaves the design
# untouched. That branch is here in full, because "delete" reads the same in the
# Files app whichever mode the file is in.

Feature: Deleting a mirrored design
  As a Nextcloud user
  I want deleting a design file to be soft on both sides
  So that nothing is lost until I deliberately empty the trash
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Deleting a mirror moves the design into Penpot's trash
    Given a mirrored design "Doomed" in the project "Bin Me"
    When I delete "Penpot/Bin Me/Doomed.penpot"
    Then the design "Doomed" is in Penpot's trash
    And Penpot project "Bin Me" holds no design named "Doomed"
    # Soft on both sides. Nothing here is irreversible, which is what makes it
    # safe to do without asking.

  # ── ONE BEHAVIOUR THAT REALLY DOES DIFFER BY BACKEND ────────────────────────
  # Everything else in this suite is backend-agnostic, which is why the backend is
  # a dimension the run varies rather than something the specs mention. This is
  # the exception, and it earns two scenarios because the OUTCOMES differ — the
  # same rule that gave §C6.16 its own scenario.
  #
  # Found by the backend matrix on its first run (saga §C6.27), not by review.

  @in-nextcloud @gesture @plain-folder
  Scenario: Emptying the Nextcloud trash destroys the design in Penpot
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is not in Penpot's trash
    # The one irreversible thing this app can cause, reached only by the one
    # irreversible gesture Nextcloud offers. permanently-delete-team-files does
    # NOT check the trash itself (§C6.11) — the app reads the listing first, and
    # that guard is the only safety there is.

  @in-nextcloud @gesture @team-folder
  Scenario: Emptying a Team Folder's trash cannot reach Penpot, and says nothing
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is still in Penpot's trash
    # NOT A DECISION — A GAP WE CANNOT CLOSE FROM HERE, recorded so it is tracked
    # rather than rediscovered. groupfolders does not use files_trashbin: it
    # registers its own ITrashBackend, and its removeItem() calls
    # `$node->getStorage()->unlink()` and emits NOTHING — no typed event, no
    # legacy hook. There is no entry point for any app to observe it, so the
    # purge simply never reaches us.
    #
    # (Its restoreItem() DOES emit the legacy `post_restore` hook, which is why
    # the restore half of this pair was fixable and this half is not.)
    #
    # IT SELF-CORRECTS, WHICH IS WHY THIS IS AN EDGE CASE RATHER THAN DATA LOSS.
    # The design is already in Penpot's own trash from the ordinary delete, and
    # that trash expires on its own — `deleted_at` is set to now + 7 days
    # (§C6.11). So the divergence is a WINDOW, not a permanent state: the design
    # outlives the Nextcloud file by up to a week and is then gone anyway. What is
    # lost is the immediacy, not the outcome.
    #
    # SOLVING IT SPECIALLY, when we do: the candidates are an upstream hook in
    # groupfolders, or a pull-side reconcile that notices a mirror is gone from
    # both the folder AND the trash. The second is delicate — "absent" must not be
    # confused with "never existed", the same trap §C6.11 hit with a deleted
    # project's folder.

  @in-nextcloud @gesture
  Scenario: Deleting an untracked ".penpot" file leaves Penpot alone
    Given a mirrored design "Keep Me" in the project "Untouched"
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
    Given a mirrored design "Farewell" in the project "Doomed"
    And the design "Farewell" is deleted in Penpot
    When the team is mirrored again
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
    Given a mirrored design "Backup" in the project "Kept"
    And "Penpot/Kept/Backup.penpot" is a "sync" design
    And the design "Backup" is deleted in Penpot
    When the team is mirrored again
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 0 final archives
    And the pull exported 0 archives
    # A `sync` file is already its own snapshot. Re-exporting it would download a
    # whole archive to replace an identical one — and would fail for exactly the
    # files most worth keeping, once the grace window closes.

  @in-penpot
  Scenario: A design purged in Penpot still only reaches the Nextcloud trash
    Given a mirrored design "No Way Back" in the project "Erased"
    And the design "No Way Back" is permanently deleted in Penpot
    When the team is mirrored again
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
    Given a mirrored design "Twice Dead" in the project "Left Alone"
    When I delete "Penpot/Left Alone/Twice Dead.penpot"
    And the design "Twice Dead" is purged from Penpot's trash
    And the team has been mirrored into Nextcloud
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

  @blocked
  Scenario: A design already gone from Penpot deletes locally without complaint
    Given a mirrored ".penpot" file whose design no longer exists in Penpot
    When I move it to the trash
    Then the local file is trashed
    And the failure is not reported to the user as an error
    # Being asked to delete something already deleted is not a problem, it is
    # the outcome the user wanted.

    # ══ DELETING A PROJECT FOLDER ══════════════════════════════════════════════
    #
    # WHAT HAPPENS TODAY, AND IT IS NOT WHAT A USER EXPECTS (§C6.19). Deleting a
    # project folder reaches Penpot **not at all** — verified live. Two reasons
    # stack, and neither is deliberate:
    #
    #   1. `DeleteListener` returns unless the node is a `File`.
    #   2. Nextcloud fires `BeforeNodeDeletedEvent` for the FOLDER ONLY. There is
    #      no per-child event, so even removing (1) would not reach the designs
    #      inside — a recursive walk is something this app would have to do
    #      itself, before the node is gone.
    #
    # The folder then reappears on the next pull (the project never went
    # anywhere), which reads as the app undoing the user's deletion.
    #
    # PENPOT SUPPORTS EXACTLY WHAT IS WANTED — checked in its source and proven
    # live against a project holding two designs:
    #
    #   delete-project {id}      → HTTP 204. SOFT: project.deleted_at = now + 7d
    #                              (per-team `deletion-delay`, default 7 days).
    #                              A worker then cascades the SAME future
    #                              timestamp onto every file in the project and
    #                              its changes, data, media and thumbnails.
    #   → the project vanishes from `get-all-projects` IMMEDIATELY;
    #   → its designs appear in `get-team-deleted-files` IMMEDIATELY, before the
    #     worker runs, because that query matches on `p.deleted_at > now` OR
    #     `f.deleted_at > now` — the project's own mark is enough.
    #
    # So there is no "must be empty first" rule to mirror, and no reason to
    # refuse. A project deletes with its contents, reversibly, on a grace window
    # that lines up with the Nextcloud trash almost exactly.
    #
    # ONE PROJECT CANNOT BE DELETED: the team's default (Drafts) answers
    # `:non-deletable-project`. It has no folder of its own in `nested` mode — it
    # IS the team root (§6.35) — so this app cannot reach it by this gesture
    # anyway; the guard is stated so a future folder mode cannot back into it.

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

  @unbuilt
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

  @unbuilt
  Scenario: Deleting a link file hides it instead of removing the design
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When I move it to the trash
    Then Penpot is never contacted
    And the design is completely unaffected in Penpot
    And the trashed file keeps its "penpot_id" and "penpot_mode"
    # Being in the trash with that id IS the hidden state — see below.

  @unbuilt
  Scenario: A pull does not recreate a link the user dismissed
    Given a link file that the user has deleted
    When the pull runs and the design still exists in Penpot
    Then no mirrored file is recreated for it
    # Recreating a pointer the user just dismissed would be an endless argument
    # between the user and the reconciler.

    # THE TRASH IS THE HIDDEN MARKER (saga §6.45) — no separate flag exists.
    # A trashed Nextcloud file keeps its fileid, its "penpot_id" and its
    # "penpot_mode" (saga §6.44, tested live), so the reconciler just looks.
  @unbuilt
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

  @unbuilt
  Scenario: Emptying the trash un-hides a dismissed link
    Given a link file the user deleted, now in the Nextcloud trash
    When the user empties the Nextcloud trash
    And the pull runs
    Then the link reappears in its project folder
    # Coherent — the record of the dismissal was thrown away with it — but it
    # must be documented rather than discovered (saga open question #41).

  @blocked
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

  @blocked
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

  @blocked
  Scenario: The app always offers Penpot's trash before an archive import
    Given an unmapped ".penpot" file whose design was deleted in Penpot
    And the design is still inside Penpot's grace window
    When I ask to restore it
    Then the app restores it from Penpot's trash, not from the local archive
    And it explains that this restore loses nothing
    # Import is the last resort, not the default (saga §6.52) — see restore-design.feature.

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

  @decision
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

  @blocked
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
