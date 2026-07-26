# Putting a design back INTO Penpot from its Nextcloud archive.
#
# THIS IS THE APP'S SECOND AND LAST WRITE PATH (saga §6.19). The complete set of
# things Nextcloud can cause in Penpot is: rename a file, and this. Everything
# else — move, copy, delete, trash — is purely local. Saga §6.1's read-only stance
# on file CONTENT stands; restore is a deliberate, user-confirmed carve-out for
# putting back something that was taken out, not a sync channel.
#
# THE MECHANISM IS REAL AND NOW EXERCISED (saga §6.20 — Chapter 1 open question
# #6, closed). `import-binfile` works, live, both variants. Three facts learned
# by actually calling it, all of which shape this feature:
#   1. It is SSE, like export — progress events, then end|error. The `end` event
#      carries an ARRAY of resulting file id(s), Transit-tagged.
#   2. Its params are KEBAB-CASE on the wire (`project-id`, `file-id`) — unlike
#      export-binfile, which takes camelCase. Per-command fact, not a convention.
#   3. Its `name` parameter is IGNORED. The imported file takes the name baked
#      into the archive's manifest.json. So a restore that needs a specific name
#      is import-binfile THEN rename-file — two calls, second can fail alone.
#
# THE FINDING THAT SHAPES EVERYTHING BELOW (saga §6.20): a DELETED Penpot file
# CANNOT be resurrected at its original id. Tested directly — delete-file (204),
# then import-binfile with that file-id returns `object-not-found`. `file-id` is
# an "import into an EXISTING file" parameter, not a "create with this id" one.
# There is no way to make Penpot accept an id we choose.
#
# AND WE NOW KNOW WHY, WHICH DOESN'T HELP (saga §6.26): the deleted row is
# actually still ALIVE in Penpot's database for ~7 days — "deleted_at" is set to
# the future purge time, not the deletion time. So the design isn't gone; it's
# unreachable. Every plausible restore command (restore-file, get-deleted-files,
# undelete-file, untrash-file, …) 404s, and mutating a soft-deleted file via
# move-files succeeds while leaving it invisible to every listing. The practical
# consequence is identical to "it's gone": our restore is create-new-with-new-id.
# What it DOES change is the advice we give — the user may be able to recover it
# in Penpot's own UI within the week, and we should say so (delete.feature).
#
# THREE RESTORE LAYERS, AND CONFUSING THEM WOULD BE THE WORST FAILURE HERE:
#
#   1. NEXTCLOUD TRASH  — the local mirror was trashed. Restore = pull it out of
#      the trash. Nothing is sent to Penpot; the reconciler re-adopts it rather
#      than creating a duplicate (saga §6.37, reconcile.feature).
#   2. PENPOT TRASH PROJECT (opt-in, saga §6.34) — the design was moved to the
#      service account's trash project. Restore = move it back. LOSSLESS: same
#      id, name, revision and history. This is delete.feature's territory.
#   3. THIS FILE — the design is genuinely gone from Penpot (or the trash bin was
#      disabled), and all we hold is a .penpot archive. Restore = import. This is
#      the BEST-EFFORT path, and the only one that changes a design's identity.
#
# BEST-EFFORT IS BETTER THAN IT SOUNDS, and the docs should say so plainly rather
# than framing it as a consolation prize. Measured on a real export→import round
# trip (saga §6.41):
#
#     comes back:  name, pages, shapes, assets, and even the revision number
#     does not:    the file id (old deep links stay dead), the edit history
#                  (0 file_change rows against the original's 5)
#
# Nobody loses design work. They lose undo-history and a URL. That is a perfectly
# respectable outcome for a backup — which is why the trash bin (layer 2) is
# framed as "the deeper flow for people who care about history," not as the
# difference between recoverable and gone.
#
# Layer 3 is still the last resort: if layer 1 or 2 applies, the app says so and
# uses it, because they preserve what this one cannot.


#
# SO RESTORE MEANS TWO GENUINELY DIFFERENT THINGS, and conflating them would be
# a lie to the user:
#   - Original still exists → in-place import. Same id, same links, same history.
#     A true restore.
#   - Original was deleted   → create-new. NEW id. Every deep link to the old id
#     is permanently dead, and the Penpot-side history is gone. The design comes
#     back; the identity does not.
# A user who thinks they undeleted something, when they actually created a
# look-alike, has been misled. That is why restore ALWAYS asks first and always
# says which of the two it is about to do.
#
# @todo — no lib/Service/ exists yet.

@todo
Feature: Restoring a design from its Nextcloud archive back into Penpot
  As a Nextcloud user
  I want to put a design back into Penpot from the archive I kept
  So that I can recover work — while understanding exactly what is and isn't recoverable

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a subfolder

  # ── restore is never automatic ───────────────────────────────────────────────

  Scenario: Restoring is always confirmed, never silent
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" subfolder
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until I confirm
    And declining leaves the file in place as ordinary tolerated content

  Scenario: A file with no archive cannot be restored
    Given an unmapped ".penpot" file in "link" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" subfolder
    Then no restore is offered
    And the app explains that a link file holds no archive to restore from
    # You cannot put back bytes you never had.

  Scenario: An untracked file is never restored, because it was never in Penpot
    Given a ".penpot" file with no "penpot_id"
    When I move the file into the "My Stuff" subfolder
    Then no restore is offered
    And Penpot is never contacted
    # Creating brand-new Penpot files from Nextcloud is a separate, still-open
    # fork (team-import.feature) — restore only ever puts BACK something that
    # this app previously mirrored out.

  # ── the good case: the original still exists ─────────────────────────────────

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

  Scenario: Restoring a deleted design states clearly what does and does not return
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    And the Penpot file with that id has been deleted in Penpot
    When I choose to restore it
    Then the app says the design's content will be rebuilt from the archive
    And it says the name, pages and assets come back
    But it says the design gets a NEW id, so old links stay broken
    And it says the edit history does not come back
    And it mentions Penpot may still be able to recover the original for about a week
    And nothing is sent to Penpot until I confirm
    # Stated as "here is what you get and what you don't", not as a warning about
    # failure — because the design itself really does come back (saga §6.41).
    # That last line matters: if the delete was recent, recovering it IN PENPOT
    # keeps the id, the links and the history — strictly better than what we can
    # offer. Pointing the user at the better option, even though it isn't ours,
    # is the honest thing to do (saga §6.26).

  Scenario: Confirming a restore of a deleted design creates a new file and re-points the mirror
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When I confirm the restore
    Then a new Penpot file is created in the "My Stuff" project
    And the Nextcloud file's "penpot_id" is updated to the NEW id
    And the old id is not kept as the file's identity
    And the file's "Open in Penpot" action points at the new design

  Scenario: The app never silently resurrects a deleted design
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When a pull runs
    Then the file is not restored automatically
    And Penpot is never contacted about it
    # Every restore is a human decision, because half of them are lossy.

  # ── the app must offer the BETTER layer when one applies ────────────────────

  Scenario: A design sitting in the Penpot trash is restored losslessly, not imported
    Given the trash bin is enabled
    And the design was moved to the configured trash project
    When I ask to restore it
    Then the app restores it by moving it back to its original project
    And no import is performed
    And the design keeps its original id, history and links
    And the app makes clear this restore lost nothing
    # Layer 2 beats layer 3 whenever it applies (delete.feature, saga §6.34).

  Scenario: A mirror in the Nextcloud trash is restored locally, not re-imported
    Given the design still exists in Penpot
    And its mirror was moved to the Nextcloud trash
    When I ask to restore it
    Then the app restores the file from the Nextcloud trash
    And Penpot is never contacted
    And no duplicate mirror is created
    # Layer 1 — nothing was ever lost remotely, so nothing needs sending.

  # ── naming, and the two-call reality ─────────────────────────────────────────

  Scenario: A restored file takes the name from its archive, not from the request
    Given an unmapped ".penpot" file renamed locally to "My Renamed Design.penpot"
    And its archive's manifest still carries the original name "My firsty"
    When I confirm a restore that creates a new Penpot file
    Then the created Penpot file is named "My firsty", from the archive manifest
    And the app issues a follow-up rename to reconcile the name
    # The `name` param is ignored on import — confirmed live (saga §6.20). This
    # is two RPC calls, and the second can fail on its own; see errors.feature.

  # ── attribution ──────────────────────────────────────────────────────────────

  Scenario: A restore is attributed to the acting user when they have a personal token
    Given the user has a valid personal Penpot token
    When the user confirms a restore
    Then the import is performed using that user's own token
    And Penpot attributes the change to that user

  Scenario: A restore falls back to the service account, and says so
    Given the user has no personal Penpot token configured
    When the user confirms a restore
    Then the import is performed using the service-account token
    And the app tells the user the action was performed as the service account
    # Saga §6.18: attribution is the personal token's only job, and its absence
    # never blocks the action.
