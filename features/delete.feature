# Deletion, in two independent layers that are easy to confuse:
#
#   NEXTCLOUD-SIDE delete  → the local mirror. Purely local, ALWAYS. Penpot is
#                            never contacted. This is the default meaning of
#                            deleting a .penpot file in the Files app (saga §6.1).
#   PENPOT-SIDE delete     → a deliberate "Delete in Penpot" action that removes
#                            the design itself. Opt-in behaviour, see below.
#
# THE TRASH BIN (saga §6.34 — this REVERSES the earlier §6.27 rejection).
# Penpot's own `delete-file` is irreversible FOR US: the id dies, deep links die,
# and history becomes unreachable (§6.20/§6.26 — Penpot keeps the row for ~7 days
# but no API command reaches it). So "restore" after a real delete degrades to
# creating a look-alike with a new id.
#
# The trash bin avoids that entirely: instead of calling delete-file, the app
# MOVES the design into a trash project inside the SERVICE ACCOUNT's personal
# team — a space no ordinary user is a member of, so the design genuinely
# disappears from the team's view. Restoring moves it back.
#
# PROVEN LOSSLESS, not assumed (saga §6.34). A real round trip:
#   duplicate-file → rename-file → move to personal team → move back
#   result: SAME id, SAME name, SAME revn, SAME history rows.
#
# A TRASHED DESIGN NEED NOT FUNCTION WHILE TRASHED (Dr K). Nobody opens a design
# in the bin; they restore it first. So questions like "does a shared library
# still resolve while parked" don't need answering — only the restore has to work.
#
# WITH THE BIN DISABLED (the default), a Penpot-side delete is a real delete-file
# — but it is NOT a data-loss event, because Nextcloud keeps the ".penpot"
# archive in its own trash and can import it back. That's a BEST-EFFORT restore,
# and it's genuinely good (saga §6.41, measured on a real round trip):
#
#     comes back:  name, pages, shapes, assets, even the revision number
#     does not:    the file id (old deep links stay dead), the edit history
#
# So the two tiers are honest about themselves rather than "safe vs dangerous":
#   bin OFF → best-effort restore. You get your design back, not its history.
#   bin ON  → perfect restore. The same file, id and history intact.
#
# The bin earns its keep as the deeper flow for people who care about history,
# not as the difference between recoverable and gone. ONE CAVEAT: best-effort
# restore needs the archive to exist locally, so it only applies to "sync" files
# (saga §6.22) — a "link" file holds no bytes to restore from.
#
# @todo — no lib/ exists yet; and the read-only guard (make sure NOTHING
# accidentally calls Penpot on an ordinary Nextcloud delete) needs its own test.

@todo
Feature: Deleting designs, locally and in Penpot
  As a Nextcloud user
  I want local deletes to be purely local and Penpot deletes to be recoverable
  So that removing a file is never a surprise and never permanently costs me work

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
    # Unhiding is the restore gesture users already know — no new UI.

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

  # ── layer 2: deleting in Penpot, with the bin ON ────────────────────────────

  Scenario: Deleting in Penpot with the bin on moves the design, losing nothing
    Given the admin has enabled the trash bin with a trash project
    And a mirrored ".penpot" file in the "My Stuff" folder
    When I choose "Delete in Penpot" and confirm
    Then the design is moved into the configured trash project
    And "delete-file" is never called
    And the design keeps its id, its name, its revision and its history
    And it no longer appears in the "Ferronescotia" team for any team member

  Scenario: The origin is recorded so a restore knows where to go
    Given the trash bin is enabled
    When a design is moved to the trash
    Then the app records the design's original project id in the file's metadata
    # Penpot does not remember where a file came from — this bookkeeping is ours,
    # and without it restore has nowhere to put the design back (saga §6.34).

  Scenario: Restoring from the Penpot trash returns the design intact
    Given a design in the configured trash project with its origin recorded
    When I restore it
    Then the design is moved back to its original Penpot project
    And it has the same id it always had
    And its deep link works again
    And its history is intact
    And no import or re-creation was performed

  Scenario: A trashed design is not required to work while it is trashed
    Given a design sitting in the configured trash project
    Then the app makes no guarantee about opening or rendering it in place
    And only its restore is guaranteed to produce a working design
    # Dr K: what matters is that it works after restore, not while parked.

  @todo
  Scenario: Trashing a shared library warns that consumers may not resolve it
    Given a mirrored design whose Penpot file is a shared library
    When I choose "Delete in Penpot" with the bin enabled
    Then the app warns that files consuming this library may not resolve it while it is trashed
    And it confirms the library resolves again once restored
    # Library relations are keyed on file ids and survive the move, but Penpot
    # scopes library VISIBILITY by team. Needs a real test with an actually
    # shared library before shipping (saga open question #32).

  Scenario: Purging from the Penpot trash is the only irreversible step
    Given a design in the configured trash project
    When the admin purges it from the Penpot trash
    Then "delete-file" is finally called for that design
    And the app warns this cannot be undone
    And the design's id and history become permanently unreachable

  # ── layer 2: deleting in Penpot, with the bin OFF (the default) ─────────────

  Scenario: With the bin disabled, deleting in Penpot is real but restorable
    Given the trash bin is not enabled
    And a mirrored ".penpot" file in "sync" mode in the "My Stuff" folder
    When I choose "Delete in Penpot"
    Then the app explains the design will really be deleted in Penpot
    And it explains the archive is kept in the Nextcloud trash so it can be restored
    And it explains a restore rebuilds the design but not its id or edit history
    When I confirm
    Then "delete-file" is called
    And the local mirror is moved to the Nextcloud trash with its archive intact

  Scenario: Best-effort restore rebuilds the design from the kept archive
    Given a design deleted in Penpot with the bin off
    And its ".penpot" archive still in the Nextcloud trash
    When I restore it
    Then the archive is imported back into Penpot
    And the design's name, pages, assets and revision number come back
    But it has a NEW id, and its edit history does not come back
    # Measured, not assumed (saga §6.41): a real export→import round trip
    # preserved name, revn 5, pages and assets — and produced 0 file_change rows
    # against the original's 5. The DESIGN survives; the history and link do not.

  Scenario: A link file has nothing to restore from, and the app says so before deleting
    Given the trash bin is not enabled
    And a mirrored ".penpot" file in "link" mode
    When I choose "Delete in Penpot"
    Then the app warns that no archive is stored for a link file
    And it offers to fetch the archive first so the design can be restored later
    # Best-effort restore depends on the bytes existing locally. Without them
    # "delete" really is unrecoverable — worth saying at the moment of deletion
    # rather than at the moment someone tries to restore (saga open question #37).

  Scenario: The trash bin is off by default
    Given a freshly installed app
    Then no trash project is configured
    And the setting explains that with it off, restoring rebuilds a design without its history
    And it explains that with it on, restoring returns the original design untouched

  # ── Penpot's own grace period: real, better than ours, and not ours to drive ─

  Scenario: A design deleted in Penpot's own UI may still be recoverable there
    Given a design that was deleted directly in Penpot
    Then Penpot retains its data for roughly 7 days before a purge worker removes it
    But no API command exposes or restores it
    And the app never manipulates Penpot's database to recover it
    # Confirmed from Penpot's own database (saga §6.26): "deleted_at" is the
    # scheduled PURGE time, ~7 days out — not the deletion time. Every plausible
    # restore command (restore-file, get-deleted-files, undelete-file, …) 404s.

  Scenario: A pull points the user at Penpot when a design vanishes there
    Given a mirrored ".penpot" file whose design was deleted directly in Penpot
    When the pull detects the design is gone
    Then the local mirror is moved to the Nextcloud trash, never hard-deleted
    And the app notes Penpot may still be able to recover it for about a week
    And it points the user at Penpot, which preserves the id and history
    # Recovering it in Penpot is strictly better than anything we can offer.
