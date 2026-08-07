# Notes, decisions and history for this feature: ../AGENTS.md#restore-design

Feature: Restoring a mirrored design
  As a Nextcloud user
  I want a restore to bring back the same design rather than a copy of it
  So that undo means undo, with its id and history intact
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Restoring a design brings back the file and its design together
    Given a mirrored design "Round Trip" in the project "Stay Put"
    And "Penpot/Stay Put/Round Trip.penpot" is in the trash
    When I restore "Penpot/Stay Put/Round Trip.penpot" from the Nextcloud trash
    Then the file "Penpot/Stay Put/Round Trip.penpot" carries a Penpot id
    And Penpot project "Stay Put" holds a design named "Round Trip"
    And the design "Round Trip" is not in Penpot's trash
    # notes: ../AGENTS.md#restoring-a-design-brings-back-the-file-and-its-design-together

  @in-nextcloud @gesture
  Scenario: Restoring a file that was never in Penpot leaves Penpot alone
    Given an uploaded ".penpot" archive at "Loose Design.penpot"
    And "Loose Design.penpot" is in the trash
    When I restore "Loose Design.penpot" from the Nextcloud trash
    Then the file "Loose Design.penpot" is not in the Nextcloud trash
    And the file "Loose Design.penpot" carries no Penpot id
    # notes: ../AGENTS.md#restoring-a-file-that-was-never-in-penpot-leaves-penpot-alone

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
    # notes: ../AGENTS.md#an-untracked-file-is-never-restored-because-it-was-never-in-penpot

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
    # notes: ../AGENTS.md#restoring-a-deleted-design-states-clearly-what-does-and-does-not-return

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
    # notes: ../AGENTS.md#a-design-still-in-penpots-trash-is-restored-losslessly-not-imported

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
    # notes: ../AGENTS.md#a-restore-that-penpot-did-not-actually-perform-is-never-reported-as-success

  @todo
  Scenario: A restore is confirmed against the listing the pull reads
    Given a trashed ".penpot" file whose design is in Penpot's trash
    When the restore reports the design's id as restored
    Then the app checks the design is back in its project's listing
    And it checks again, once an in-flight delete has had time to land
    And a design that is not there yet is restored a second time
    And a design still missing after that is reported as a failure
    # notes: ../AGENTS.md#a-restore-is-confirmed-against-the-listing-the-pull-reads

  @in-nextcloud @gesture @blocked
  Scenario: A pull after a restore leaves exactly one mirror, in any mode
    Given a mirrored ".penpot" file that I moved to the trash
    When I restore it from the Nextcloud trash
    And a pull runs
    Then exactly one mirrored file exists for that design
    And the mirror is not trashed again
    # notes: ../AGENTS.md#a-pull-after-a-restore-leaves-exactly-one-mirror-in-any-mode

    # notes: ../AGENTS.md#a-design-that-never-left-penpot-is-restored-locally-and-nothing-is-sent

  @todo
  Scenario: A design that never left Penpot is restored locally and nothing is sent
    Given a trashed ".penpot" file whose design still exists in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And Penpot is never contacted to restore it

  @unbuilt
  Scenario: A design that is gone for good is not silently recreated
    Given a trashed ".penpot" file whose design was permanently deleted in Penpot
    When I restore it from the Nextcloud trash
    Then the file is back where it was
    And no design is created in Penpot
    And the app reports that the design is gone and the mirror is now the only copy
    # notes: ../AGENTS.md#a-design-that-is-gone-for-good-is-not-silently-recreated

  @unbuilt
  Scenario: An untracked file coming out of the trash is never restored into Penpot
    Given a ".penpot" file with no "penpot_id" in the Nextcloud trash
    When I restore it from the Nextcloud trash
    Then Penpot is never contacted
    # Restore only ever puts BACK something this app mirrored out. Inventing a
    # design for a file that never had one is team-import.feature's open fork.
