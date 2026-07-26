# Putting a design back INTO Penpot from its Nextcloud archive.
#
# ONE OF THE APP'S FEW WRITE PATHS (saga §6.19), and a deliberate,
# user-confirmed carve-out from §6.1's read-only stance on file CONTENT. It puts
# back something that was taken out; it is not a sync channel.
#
# WHEN THIS PATH APPLIES — AND IT IS THE LAST RESORT (saga §6.52). Importing an
# archive is what you do when NOTHING better is available. Three cheaper,
# lossless options come first, and confusing them would be the worst failure in
# this file:
#
#   1. NEXTCLOUD TRASH — the local mirror was trashed but the design still exists
#      in Penpot. Restore = pull it out of the trash. Nothing is sent to Penpot;
#      the reconciler re-adopts it rather than duplicating (saga §6.37).
#   2. PENPOT'S OWN TRASH (~7 days) — the design was deleted in Penpot.
#      `restore-deleted-team-files` brings it back with its **id, revision and
#      history** intact, verified live (saga §6.49). This is delete.feature's
#      territory and is ALWAYS preferred over importing.
#   3. THIS FILE — the grace window has closed, or the design was permanently
#      deleted, and all we hold is a .penpot archive. Import is the only option
#      left, and the only one that changes a design's identity.
#
# WHY IMPORT CAN'T PRESERVE IDENTITY (saga §6.20): a purged Penpot file cannot be
# resurrected at its original id. Tested directly — delete-file (204), then
# import-binfile with that file-id returns `object-not-found`. `file-id` is an
# "import into an EXISTING file" parameter, not a "create with this id" one.
# There is no way to make Penpot accept an id we choose.
#
# THE MECHANISM IS REAL AND EXERCISED (saga §6.20). Three facts learned by
# actually calling `import-binfile`, all of which shape this feature:
#   1. It is SSE, like export — progress events, then end|error. The `end` event
#      carries an ARRAY of resulting file id(s), Transit-tagged.
#   2. Its params are KEBAB-CASE on the wire (`project-id`, `file-id`) — unlike
#      export-binfile, which takes camelCase. Per-command fact, not a convention.
#   3. Its `name` parameter is IGNORED. The imported file takes the name baked
#      into the archive's manifest.json. So a restore that needs a specific name
#      is import-binfile THEN rename-file — two calls, second can fail alone.
#
# BEST-EFFORT IS BETTER THAN IT SOUNDS, and the docs should say so plainly rather
# than framing it as a consolation prize. Measured on a real export→import round
# trip (saga §6.41):
#
#     comes back:  name, pages, shapes, assets, and even the revision number
#     does not:    the file id (old deep links stay dead), the edit history
#                  (0 file_change rows against the original's 5)
#
# Nobody loses design work. They lose undo-history and a URL. That is a
# respectable outcome for a backup — it is simply not as good as layers 1 and 2,
# so the app must check those first and say which one it used.
#
# SO RESTORE MEANS GENUINELY DIFFERENT THINGS depending on what survived, and
# conflating them would be a lie to the user. That is why restore ALWAYS checks
# the cheaper layers first, ALWAYS asks, and ALWAYS says which one it is doing.
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
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── restore is never automatic ───────────────────────────────────────────────

  Scenario: Restoring is always confirmed, never silent
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until I confirm
    And declining leaves the file in place as ordinary tolerated content

  Scenario: A file with no archive cannot be restored
    Given an unmapped ".penpot" file in "link" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then no restore is offered
    And the app explains that a link file holds no archive to restore from
    # You cannot put back bytes you never had.

  Scenario: An untracked file is never restored, because it was never in Penpot
    Given a ".penpot" file with no "penpot_id"
    When I move the file into the "My Stuff" folder
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
    And nothing is sent to Penpot until I confirm
    # Stated as "here is what you get and what you don't", not as a warning about
    # failure — because the design itself really does come back (saga §6.41).
    # NOTE: this scenario only applies once Penpot's own ~7-day trash window has
    # closed. Inside it, the app restores losslessly instead — see below.
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

  Scenario: A design still in Penpot's trash is restored losslessly, not imported
    Given the design was deleted in Penpot within the last few days
    When I ask to restore it
    Then the app restores it with "restore-deleted-team-files"
    And no import is performed
    And the design keeps its original id, revision, history and links
    And the app makes clear this restore lost nothing
    # Layer 2 always beats layer 3 (saga §6.49/§6.52). The app must CHECK
    # get-team-deleted-files before offering an import — see delete.feature.

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
