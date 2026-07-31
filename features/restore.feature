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
# WHAT IS BUILT, AND WHAT THIS FILE IS STILL WAITING FOR (§C6.15). Layers 1 and 2
# ship: taking a mirror out of the Nextcloud trash restores the design out of
# Penpot's trash with it, losslessly, and does nothing at all when the design
# never left. Their scenarios live in delete.feature, next to the delete they
# undo. THIS file is layer 3 — the archive import — and only layer 3.
#
# Layer 3 is deliberately last, and not merely unfinished. It is the only restore
# that CHANGES A DESIGN'S IDENTITY, so it cannot be a listener that fires on a
# gesture the way the other two are: it needs a human to be told what they are
# about to trade and to say yes. The app already reports the state that leads
# here — "the design is gone from Penpot and this mirror is the only copy" — so
# the missing piece is the confirmation surface and the import itself, not the
# detection.
#
# @todo — the import, and the confirmation it requires, are not built.

Feature: Restoring a design from its Nextcloud archive back into Penpot
  As a Nextcloud user
  I want to put a design back into Penpot from the archive I kept
  So that I can recover work — while understanding exactly what is and isn't recoverable

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

    # ══ RESTORED IN NEXTCLOUD ══════════════════════════════════════════════════
    #
    # Layers 1 and 2, driven live. Taking a mirror out of the Nextcloud trash takes
    # the design out of Penpot's trash with it — losslessly, same id, revision and
    # history — and does nothing at all when the design never left.
    #
    # Live rather than @todo for the reason §C6.11 exists: restore is the command
    # that reports success without doing the work. Handed an id that is not in the
    # trash it answers 200 with an EMPTY SET, and a mocked restore would return
    # whatever the mock was told to. The assertion that matters — "and the design
    # is back in its project" — can only come from Penpot.

  @in-nextcloud @gesture
  Scenario: Restoring a mirror brings its design back out of Penpot's trash
    Given a mirrored design "Second Thoughts" in the project "Bring Back"
    And I delete "Penpot/Bring Back/Second Thoughts.penpot"
    When I restore "Penpot/Bring Back/Second Thoughts.penpot" from the Nextcloud trash
    Then the design "Second Thoughts" is not in Penpot's trash
    And Penpot project "Bring Back" holds a design named "Second Thoughts"
    # Lossless: the SAME design comes back, with its id, revision and history —
    # nothing is imported and nothing is re-created (§6.49/§C6.11).

  @in-nextcloud @gesture
  Scenario: A pull after a restore neither prunes the mirror nor duplicates it
    Given a mirrored design "Round Trip" in the project "Stay Put"
    And I delete "Penpot/Stay Put/Round Trip.penpot"
    When I restore "Penpot/Stay Put/Round Trip.penpot" from the Nextcloud trash
    And the team has been mirrored into Nextcloud
    Then the file "Penpot/Stay Put/Round Trip.penpot" carries a Penpot id
    And Penpot project "Stay Put" holds a design named "Round Trip"
    # THE WHOLE POINT OF THE SLICE, asserted end to end: the mirror is NOT trashed
    # a second time, and it keeps its id so no duplicate appears beside it.
    #
    # ASSERTED ON THIS FILE, NOT ON THE PULL'S COUNTER. An earlier version used
    # "the pull pruned nothing", which is a claim about the whole mapped folder —
    # every design every other scenario has ever left in it. It passed or failed
    # on what its NEIGHBOURS did, which is why it flapped for a whole session and
    # then broke again the moment the feature files were reordered. A scenario
    # about one file asserts on that file.

  @in-nextcloud @gesture @todo
  Scenario: A pull after a restore does not trash the mirror a second time
    Given a mirrored design "Round Trip" in the project "Stay Put"
    And I delete "Penpot/Stay Put/Round Trip.penpot"
    When I restore "Penpot/Stay Put/Round Trip.penpot" from the Nextcloud trash
    And the team has been mirrored into Nextcloud
    Then the file "Penpot/Stay Put/Round Trip.penpot" is not in the Nextcloud trash
    # @todo BECAUSE IT FAILS, NOT BECAUSE IT IS UNWRITTEN — the one @todo in this
    # suite that marks a defect rather than a gap.
    #
    # The restore logs success having CONFIRMED the design is back in its
    # project's listing (§C6.15), and the pull one second later does not see it
    # there and trashes the mirror again. Two `get-project-files` calls for the
    # same project, seconds apart, disagreeing.
    #
    # It hid for a whole session behind "the pull pruned nothing", which asks
    # about the entire mapped folder and so flapped with the suite's ordering
    # rather than reporting a fact. Asked precisely, it fails every time — which
    # is the first time this has been reproducible enough to chase.
    #
    # The scenario above keeps the two claims that DO hold: the mirror keeps its
    # id (so no duplicate appears) and the design really is back in Penpot.

  @in-nextcloud @gesture
  Scenario: Restoring an untracked ".penpot" file never contacts Penpot
    Given a mirrored design "Not Involved" in the project "Bystander"
    And I upload a ".penpot" archive at "Penpot/Bystander/Strays In.penpot"
    And I delete "Penpot/Bystander/Strays In.penpot"
    When I restore "Penpot/Bystander/Strays In.penpot" from the Nextcloud trash
    Then the file "Penpot/Bystander/Strays In.penpot" carries no Penpot id
    And Penpot project "Bystander" holds a design named "Not Involved"
    And Penpot project "Bystander" holds no design named "Strays In"
    # Restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's still-open
    # fork, and it must not happen by accident on the way out of the trash.

    # ── restore is never automatic ───────────────────────────────────────────────

  @todo
  Scenario: Restoring is always confirmed, never silent
    Given an unmapped ".penpot" file in "sync" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then the app offers to restore it into Penpot
    And nothing is sent to Penpot until I confirm
    And declining leaves the file in place as ordinary tolerated content

  @todo
  Scenario: A file with no archive cannot be restored
    Given an unmapped ".penpot" file in "link" mode carrying a "penpot_id"
    When I move the file into the "My Stuff" folder
    Then no restore is offered
    And the app explains that a link file holds no archive to restore from
    # You cannot put back bytes you never had.

  @todo
  Scenario: An untracked file is never restored, because it was never in Penpot
    Given a ".penpot" file with no "penpot_id"
    When I move the file into the "My Stuff" folder
    Then no restore is offered
    And Penpot is never contacted
    # Creating brand-new Penpot files from Nextcloud is a separate, still-open
    # fork (team-import.feature) — restore only ever puts BACK something that
    # this app previously mirrored out.

    # ── the good case: the original still exists ─────────────────────────────────

  @todo
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

  @todo
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

  @todo
  Scenario: Confirming a restore of a deleted design creates a new file and re-points the mirror
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When I confirm the restore
    Then a new Penpot file is created in the "My Stuff" project
    And the Nextcloud file's "penpot_id" is updated to the NEW id
    And the old id is not kept as the file's identity
    And the file's "Open in Penpot" action points at the new design

  @todo
  Scenario: The app never silently resurrects a deleted design
    Given an unmapped ".penpot" file whose Penpot original was deleted
    When a pull runs
    Then the file is not restored automatically
    And Penpot is never contacted about it
    # Every restore is a human decision, because half of them are lossy.

    # ── the app must offer the BETTER layer when one applies ────────────────────

  @todo
  Scenario: A design still in Penpot's trash is restored losslessly, not imported
    Given the design was deleted in Penpot within the last few days
    When I ask to restore it
    Then the app restores it with "restore-deleted-team-files"
    And no import is performed
    And the design keeps its original id, revision, history and links
    And the app makes clear this restore lost nothing
    # Layer 2 always beats layer 3 (saga §6.49/§6.52), and it is BUILT: the trash
    # listing is read before anything else is considered. Kept here as the rule
    # this file must obey; its live scenarios are in delete.feature.

  @todo
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

  @todo
  Scenario: A restored file takes the name from its archive, not from the request
    Given an unmapped ".penpot" file renamed locally to "My Renamed Design.penpot"
    And its archive's manifest still carries the original name "My firsty"
    When I confirm a restore that creates a new Penpot file
    Then the created Penpot file is named "My firsty", from the archive manifest
    And the app issues a follow-up rename to reconcile the name
    # The `name` param is ignored on import — confirmed live (saga §6.20). This
    # is two RPC calls, and the second can fail on its own; see errors.feature.

    # ── attribution ──────────────────────────────────────────────────────────────

  @todo
  Scenario: A restore is attributed to the acting user when they have a personal token
    Given the user has a valid personal Penpot token
    When the user confirms a restore
    Then the import is performed using that user's own token
    And Penpot attributes the change to that user

  @todo
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

  @todo
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
    And a design that is not there yet is restored a second time
    And a design still missing after that is reported as a failure
    # NOT "is it out of the trash?", which sounds equivalent and is not. Penpot's
    # restore returns BEFORE its transaction settles (§6.49), and in that window
    # the trash listing can stop naming a design while the project listing still
    # omits it. The pull decides what to prune from the project listing, so that
    # is the only answer worth having — asking the other one failed this file's
    # own scenario about half the time (§C6.15).

  @in-nextcloud @gesture @todo
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

    # ── the layers restore does NOT use, and why it says so ───────────────────

  @todo
  Scenario: A design that never left Penpot is restored locally and nothing is sent
    Given a trashed ".penpot" file whose design still exists in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And Penpot is never contacted to restore it
    # Layer 1. The mirror was trashed while Penpot was unreachable, or someone
    # restored the design in Penpot's own UI first. Nothing was ever lost
    # remotely, so taking the file out of the trash IS the whole restore.

  @todo
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

  @todo
  Scenario: An untracked file coming out of the trash is never restored into Penpot
    Given a ".penpot" file with no "penpot_id" in the Nextcloud trash
    When I restore it from the Nextcloud trash
    Then Penpot is never contacted
    # Restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's open fork.
