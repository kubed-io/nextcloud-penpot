# Notes, decisions and history for this feature: ../AGENTS.md#designsdelete

Feature: Deleting a mirrored design
  As a Nextcloud user
  I want deleting a design file to be soft on both sides
  So that nothing is lost until I deliberately empty the trash
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Deleting a mirror moves the design into Penpot's trash
    Given a mirrored design "Doomed" in the project "Bin Me"
    When I delete "Penpot/Bin Me/Doomed.penpot"
    Then the design "Doomed" is in Penpot's trash
    And Penpot project "Bin Me" holds no design named "Doomed"
    # Soft on both sides. Nothing here is irreversible, which is what makes it
    # safe to do without asking.

  # notes: ../AGENTS.md#emptying-the-nextcloud-trash-destroys-the-design-in-penpot

  @in-nextcloud @gesture @plain-folder
  Scenario: Emptying the Nextcloud trash destroys the design in Penpot
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is not in Penpot's trash

  @in-nextcloud @gesture @team-folder
  Scenario: Emptying a Team Folder's trash cannot reach Penpot, and says nothing
    Given a mirrored design "Gone For Good" in the project "Purge Me"
    And I delete "Penpot/Purge Me/Gone For Good.penpot"
    When I purge "Penpot/Purge Me/Gone For Good.penpot" from the Nextcloud trash
    Then the design "Gone For Good" is still in Penpot's trash
    # notes: ../AGENTS.md#emptying-a-team-folders-trash-cannot-reach-penpot-and-says-nothing

  @in-nextcloud @gesture
  Scenario: Deleting an untracked ".penpot" file leaves Penpot alone
    Given a mirrored design "Keep Me" in the project "Untouched"
    And I upload a ".penpot" archive at "Penpot/Untouched/Not Ours.penpot"
    When I delete "Penpot/Untouched/Not Ours.penpot"
    Then Penpot project "Untouched" holds a design named "Keep Me"
    And the design "Keep Me" is not in Penpot's trash

    # notes: ../AGENTS.md#deleting-an-untracked-penpot-file-leaves-penpot-alone

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
    # notes: ../AGENTS.md#a-design-deleted-in-penpot-is-snapshotted-then-moved-to-the-trash

  @in-penpot
  Scenario: A design that already had its archive needs no second export
    Given a Penpot team named "Design Team" is mapped to the folder "Penpot" in "sync" mode
    And a mirrored design "Backup" in the project "Kept"
    And the design "Backup" is deleted in Penpot
    When the team is mirrored again
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 0 final archives
    And the pull exported 0 archives
    # notes: ../AGENTS.md#a-design-that-already-had-its-archive-needs-no-second-export

  @in-penpot
  Scenario: A design purged in Penpot still only reaches the Nextcloud trash
    Given a mirrored design "No Way Back" in the project "Erased"
    And the design "No Way Back" is permanently deleted in Penpot
    When the team is mirrored again
    Then the pull succeeds
    And the pull pruned 1 mirror
    And there is no node at "Penpot/Erased/No Way Back.penpot"
    And the file "Penpot/Erased/No Way Back.penpot" is in the Nextcloud trash
    # notes: ../AGENTS.md#a-design-purged-in-penpot-still-only-reaches-the-nextcloud-trash

    # notes: ../AGENTS.md#a-mirror-already-in-the-nextcloud-trash-is-invisible-to-the-pull

  @in-penpot
  Scenario: A mirror already in the Nextcloud trash is invisible to the pull
    Given a mirrored design "Twice Dead" in the project "Left Alone"
    When I delete "Penpot/Left Alone/Twice Dead.penpot"
    And the design "Twice Dead" is purged from Penpot's trash
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And the file "Penpot/Left Alone/Twice Dead.penpot" is in the Nextcloud trash
    And there is no node at "Penpot/Left Alone/Twice Dead.penpot"

  # THE MOST IMPORTANT RULE IN THE APP: not knowing is not evidence of deletion.
  # notes: ../AGENTS.md#an-incomplete-listing-prunes-nothing
  @in-penpot @todo
  Scenario Outline: An incomplete listing prunes nothing
    Given a mirrored design "Still Here" in the project "Intact"
    And <the listing is incomplete>
    When the team is mirrored again
    Then the sync fails
    And the pull pruned 0 mirrors
    And "Penpot/Intact/Still Here.penpot" holds:
      | penpot_id | the design's id |

    Examples: every way the app can end up knowing less than it did
      | the listing is incomplete                     |
      | the service-account token has been rejected   |
      | the team's project listing fails              |
      | one project's file listing fails              |

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

    # notes: ../AGENTS.md#purging-a-mirror-from-the-nextcloud-trash-destroys-the-design

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
    # notes: ../AGENTS.md#a-trash-bypassed-delete-is-treated-as-the-permanent-one

    # notes: ../AGENTS.md#deleting-a-link-file-hides-it-instead-of-removing-the-design

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

    # notes: ../AGENTS.md#a-hidden-link-is-distinguishable-from-one-that-was-never-pulled
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
    # notes: ../AGENTS.md#a-link-is-never-restored-into-penpot-in-any-circumstance

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
    # notes: ../AGENTS.md#there-is-no-app-managed-trash-bin-setting

    # ── after the grace window ──────────────────────────────────────────────────

  @blocked
  Scenario: Once the grace window passes, only a best-effort import remains
    Given a design deleted in Penpot longer ago than the grace window
    And its ".penpot" archive still in the Nextcloud trash
    When I restore it
    Then the archive is imported back into Penpot
    And the design's name, pages, assets and revision number come back
    But it has a NEW id, and its edit history does not come back
    # notes: ../AGENTS.md#once-the-grace-window-passes-only-a-best-effort-import-remains

  @todo
  Scenario: A link file has nothing to fall back on once the window closes
    Given a mirrored ".penpot" file in "link" mode
    When its design is deleted in Penpot
    Then the app takes a final snapshot while the design is still recoverable
    And the archive is written into the file before it is trashed locally
    # The one genuinely unrecoverable case, closed by saga §6.46 — see
    # reconcile.feature.
