# The scheduled/manual pull — PULL-ONLY, unlike both siblings. n8n and Grafana
# each expose "Sync from X" AND "Sync to X" because their mappings hold live,
# editable content. This app has no content push (saga §6.1): "Sync from Penpot"
# is the entire feature, not half of one.
#
# WHO PULLS (saga §6.18, locked): the SERVICE-ACCOUNT token, always. One job, one
# credential, one pass per team. Never per-user — §6.16 found the real reason:
# two Nextcloud users who are both members of one Penpot team resolve to the SAME
# Team Folder, so a per-user pull means two uncoordinated writers on one file.
# That's a data race, not an inefficiency. Personal tokens exist only to attribute
# WRITES (rename, restore); they never drive the pull.
#
# TWO MODES, AND THEY MEAN SOMETHING NEW (saga §6.22): neither mode ever pushes
# content — §6.1 is intact. The axis is purely "do we store the bytes?":
#   link  — the DEFAULT. A pointer carrying penpot_id + metadata. Deep-links to
#           the live design. NEVER calls export-binfile. Costs no bytes.
#   sync  — opt-in, for files worth backing up. The real .penpot archive is
#           downloaded and stored. Costs a full export whenever revn moves.
#
# WHY THIS SCALES TO "A LOT OR A LITTLE" (Command's ask): get-project-files
# returns revn + modifiedAt for EVERY file in one response (saga §5.5), so the
# drift check needs no per-file call. A pull over an unchanged instance costs
# 1 + P calls per team and zero exports. Renames and moves reconcile from that
# same listing, for free, regardless of mode — which is exactly Command's "we
# just need to know when the name changes or it's moved."
#
# PENPOT IS AUTHORITATIVE for a mirrored file's name and project placement (saga
# §6.22) — see move.feature. A pull restores both.
#
# EXPORT IS SSE (saga §5.1/§6.20): progress events, then end|error; the end event
# carries a SEPARATE /assets/by-id/<uuid> URL requiring a SECOND authenticated
# fetch (401 without the token, confirmed §6.20). HTTP 200 does NOT mean success
# — an error arrives as an SSE event inside a 200 response. Failure handling is
# specified in errors.feature.
#
# WEBHOOKS ARE NOT PART OF THIS DESIGN YET. Creation works, but DELIVERY has
# never been observed (saga §6.17, open question #19) — two confirmed rename-file
# mutations produced zero POSTs. Cron is the sole trigger until that's explained.
#
# @todo — no lib/Service/SyncService or lib/BackgroundJob/ exists yet.
#
# BUILD STATE (Course 5, the prune slice). THE PRUNE AND ITS FINAL SNAPSHOT ARE
# BUILT: `PullService` collects every `penpot_id` Penpot names while it walks a
# team, then moves any mirror under the mapped folder that was NOT named to the
# Nextcloud trash — and a doomed `link` gets one last `export-binfile` on the way
# out, so the user is left with a real archive rather than a pointer to nothing
# (saga §6.42/§6.46, §C5.1). The prune is switched off entirely by any incomplete
# listing, including a project skipped for an illegal name.
#
# The half of this file CI can prove today has moved to **prune.feature**, where
# it runs live against a design this suite really deletes in Penpot.
#
# WHAT STAYS @todo HERE: adopting a mirror out of the Nextcloud TRASH (§6.37) —
# both the "don't duplicate a trashed mirror" and the "a design restored in
# Penpot's own UI comes back" scenarios need `files_trashbin`, and are their own
# slice. So do the ignore marker, the scheduled job, and the admin buttons.

@todo
Feature: Scheduled or manual pull from Penpot
  As a Nextcloud admin
  I want mapped folders to reflect Penpot on a schedule or on demand
  So that the mirror stays current without ever needing a push counterpart

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── what a pull produces ─────────────────────────────────────────────────────

  # ── the manual controls in admin settings ────────────────────────────────────
  # Both siblings expose a per-mapping "Sync now" button on each mapping card,
  # plus a section-wide bulk sync in the Sync Actions panel. This app has the
  # same two controls in the same two places — but only ONE direction, because
  # there is no content push (saga §6.1). See admin-section.feature for where
  # they sit; this is what they do.

  @todo
  Scenario: Sync now on a mapping card pulls just that team
    Given the Penpot team "Northwind" is mapped
    When the admin uses that mapping's "Sync now" button
    Then only "Northwind" is pulled
    And other mapped teams are left alone
    # The per-mapping equivalent of "Sync from Penpot", exactly as in both
    # siblings — the same button, in the same place on the card.

  @todo
  Scenario: Sync now reports honestly while the pull is unbuilt
    Given the Penpot team "Northwind" is mapped
    When the admin uses that mapping's "Sync now" button
    Then the card reports that per-team sync is not available yet
    And nothing is mirrored
    # The button ships BEFORE its engine, present and clickable, because
    # present-but-honest keeps the finished shape of the card visible and makes
    # enabling it later a one-line change. Silently doing nothing would be worse
    # than either a disabled button or an absent one.

  @todo
  Scenario: Sync now on an unsaved mapping asks for a save first
    Given the admin has added a mapping card but not saved it
    When the admin uses that card's "Sync now" button
    Then the card asks the admin to save the mapping first
    # There is nothing to sync until the mapping is persisted.

  Scenario: Pulling mirrors Penpot's projects as folders and its files inside them
    Given the "Northwind" team has a Penpot project "My Stuff" containing a file "My firsty"
    When the pull runs
    Then a folder named "My Stuff" exists in the Team Folder
    And that folder carries the Penpot project id as folder metadata
    And that folder carries the app's project tag, visible in the Files app
    And a file named "My firsty.penpot" appears inside it
    And the file's "penpot_id" matches the Penpot file's id
    # Projects are MIRRORED, not mapped (saga §6.24) — nobody configures them.
    # The pull CREATES them one level under the Team Folder; the user may then
    # move them anywhere within it (saga §6.29/§6.30).

  Scenario: A pull never relocates folders or files the user has moved
    Given the "My Stuff" project folder has been moved into a plain folder "Clients"
    And a mirrored ".penpot" file inside a plain subfolder of "My Stuff"
    When the pull runs
    Then the "My Stuff" folder stays inside "Clients"
    And the mirrored file stays where the user put it
    And both are refreshed in place if Penpot has changed
    # Nextcloud owns folder LAYOUT; Penpot owns project MEMBERSHIP (saga §6.29).
    # The pull only ensures each file sits under SOME folder mapping to its real
    # project — not that it sits at a particular path.

  Scenario: A newly created Penpot project appears as a folder on the next pull
    Given the "Northwind" team gains a new Penpot project "Brand"
    When the pull runs
    Then a folder named "Brand" appears one level inside the Team Folder
    And it carries the new project's id as metadata and the app's project tag

  Scenario: A project renamed in Penpot renames its folder, wherever the user put it
    Given the "My Stuff" project folder has been moved into a plain folder "Clients"
    When the project is renamed to "Acme" in Penpot
    And the pull runs
    Then the folder is renamed to "Acme"
    And it stays inside "Clients" — only the name changes, not the location
    # Names are pinned to Penpot; positions are the user's (saga §6.29/§6.36).

  # The Nextcloud→Penpot direction of project renaming, its name guard, and the
  # sanitisation case for Penpot names Nextcloud can't represent all live in
  # project-folder.feature — it's a distinct flow from file rename (saga §6.39):
  # different node type, different RPC, 204 with no body, and no extension to
  # handle.

  Scenario: A second pull with nothing changed creates no duplicates
    Given a mirrored ".penpot" file for the Penpot file "My firsty"
    When the pull runs again with nothing changed in Penpot
    Then the "My Stuff" folder still holds exactly 1 mirrored file
    And no file gains a " (2)" collision-suffixed duplicate

  # The reconciler must look in the Nextcloud trash before creating a mirror,
  # or restoring one file yields two (saga §6.37).
  Scenario: A design whose mirror sits in the Nextcloud trash is not duplicated
    Given a mirrored ".penpot" file that has been moved to the Nextcloud trash
    When the pull runs and finds the design still exists in Penpot
    Then no second mirror is created for that design
    And the trashed file is left in the trash, still carrying its "penpot_id"

  Scenario: Restoring a mirror from the Nextcloud trash re-adopts it
    Given a mirrored ".penpot" file in the Nextcloud trash
    When the user restores it from the trash
    And the pull runs
    Then the restored file is adopted as the mirror for its design
    And exactly one mirrored file exists for that design
    And it is refreshed if Penpot has changed since it was trashed

  Scenario: A design already in Penpot's Drafts surfaces at the Team Folder root
    Given the "Northwind" team has a design in its "Drafts" project
    When the pull runs
    Then the design appears as a file at the Team Folder's root
    And no folder named "Drafts" is created
    # Drafts is a state, not a folder (saga §6.35) — mirroring it as a folder
    # would make the design appear to be in two places.

  # ── the mode axis: what actually costs anything ──────────────────────────────

  Scenario: A new mapping defaults its files to "link" mode
    When the admin maps the Penpot team "Northwind" without choosing a mode
    And the pull runs
    Then every mirrored file is in "link" mode
    And no ".penpot" archive content is stored for any of them
    And "export-binfile" was never called during the pull

  Scenario: A "link" file is refreshed from the listing alone, never exported
    Given a mirrored ".penpot" file in "link" mode
    When the Penpot file's "revn" increases
    And the pull runs
    Then "export-binfile" is never called for that file
    And the file still holds no archive content
    But its recorded revision metadata is updated from the listing

  Scenario: A "sync" file is exported only when its Penpot revision has moved
    Given a mirrored ".penpot" file in "sync" mode whose last-pulled "revn" is recorded
    And the Penpot file's "revn" and "modifiedAt" have not changed since
    When the pull runs
    Then "export-binfile" is not called for that file
    And the stored archive is left exactly as it was
    When the Penpot file's "revn" increases
    And the pull runs
    Then the file is re-exported and its archive content replaced

  Scenario: A pull over an unchanged instance costs no exports at all
    Given the "Northwind" team has 3 mirrored projects holding 50 files in "link" mode
    And nothing has changed in Penpot since the last pull
    When the pull runs
    Then "export-binfile" is called 0 times
    And no archive bytes are downloaded
    # 1 get-projects + 3 get-project-files = 4 calls for 50 files. This is the
    # property that makes the design scale (saga §6.22).

  # ── name and placement reconcile for free, in both modes ─────────────────────

  Scenario: A rename in Penpot renames the mirrored file, whatever its mode
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When the file is renamed to "New Name" in Penpot
    And the pull runs
    Then the mirrored file is renamed to "New Name.penpot"
    And its "penpot_id" metadata is unchanged
    And no export was needed to detect or apply the rename

  Scenario: A file moved between projects in Penpot moves folder in Nextcloud
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And the Penpot project "Design System" is mirrored as a folder
    When the file is moved to the "Design System" project in Penpot
    And the pull runs
    Then the mirrored file is in the "Design System" folder
    And it keeps its "penpot_id" and its content
    And no duplicate is left behind in "My Stuff"
    # Project MEMBERSHIP is Penpot's to decide, so this one does relocate the
    # file — into whichever folder maps to "Design System", wherever the user
    # has put that folder (saga §6.29).

  Scenario: A file whose project folder was moved follows it, not a fixed path
    Given a mirrored ".penpot" file in the "My Stuff" folder
    And the user has moved the "My Stuff" folder inside a plain folder "Clients"
    When the file is renamed in Penpot and the pull runs
    Then the file is refreshed inside "Clients/My Stuff"
    And no folder is recreated at the old location

  # ── pruning: the most dangerous thing this app does ──────────────────────────

  Scenario: A pull prunes a mirrored file whose Penpot file no longer exists
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When the underlying Penpot file is deleted in Penpot
    And the pull runs
    Then the mirrored file is moved to the Nextcloud trash, not hard-deleted
    And a file outside every mapping is never pruned by this pull
    # Trash, never destroy — don't-lose-data. A pruned file is recoverable for as
    # long as the user's trash retention allows.

  # THE FINAL SNAPSHOT (saga §6.46) — the app's one genuinely lossy moment,
  # fixed. A pruned "link" file would otherwise be a pointer to a design that no
  # longer exists, with nothing to rebuild from. But "export-binfile" still
  # exports a soft-deleted file for ~7 days (saga §6.42, confirmed live), and a
  # trashed Nextcloud file's content is writable (saga §6.44, confirmed live).
  Scenario: A link file gets a final snapshot before being pruned
    Given a mirrored ".penpot" file in "link" mode in the "My Stuff" folder
    When its design is deleted in Penpot
    And the pull runs within Penpot's grace window
    Then the app exports the design one last time
    And the archive is written into the file, which becomes a "sync" file
    And only then is it moved to the Nextcloud trash
    And the user is left with a real, openable ".penpot" archive

  Scenario: A snapshot that cannot be taken is reported, not faked
    Given a mirrored ".penpot" file in "link" mode
    When its design was deleted in Penpot longer ago than the grace window
    And the pull runs
    Then the export fails
    And the pointer is moved to the Nextcloud trash as before
    And the app reports that no archive could be recovered for it
    # Best-effort by design — we never pretend a snapshot succeeded.

  Scenario: A sync file needs no snapshot, it already has one
    Given a mirrored ".penpot" file in "sync" mode
    When its design is deleted in Penpot and the pull runs
    Then no extra export is performed
    And the file is moved to the Nextcloud trash with its existing archive intact

  # DETECTING A PENPOT-SIDE RESTORE (saga §6.46). We cannot DRIVE Penpot's trash
  # — no API command restores a file — but we can notice when a human does it in
  # Penpot's own UI: the design reappears under its ORIGINAL id.
  Scenario: A design restored in Penpot's own UI is re-adopted from the trash
    Given a mirrored ".penpot" file that the pull trashed when its design vanished
    When a human restores that design in Penpot's own UI
    And the pull runs
    Then the trashed mirror is restored in place, matched by its "penpot_id"
    And no new file is created for the design
    And the file keeps its original id, metadata and mode
    # The cleanest restore path in the app — and it belongs to Penpot, not us.
    # It costs no new mechanism: the trash-aware reconciler already does this.

  Scenario: A pull never prunes on a failed or incomplete listing
    Given a mirrored ".penpot" file in the "My Stuff" subfolder
    When the pull runs but "get-project-files" fails for that project
    Then nothing is pruned
    And the existing mirrored files are left exactly as they are
    And the failure is reported
    # A failed listing is indistinguishable from "everything was deleted". This
    # is THE most dangerous operation in the app (saga §6.25) — an auth blip or a
    # network error must never be read as evidence that a user's files are gone.

  Scenario: An ignored file is skipped by the pull, not pruned
    Given a mirrored ".penpot" file tagged with the app's ignore marker
    When the pull runs
    Then the file is not refreshed, renamed, moved, or pruned
    And its archive content is left intact
    # See ignore.feature — ignore means "stop mirroring", never "delete".

  # ── there is no push, and there is no user-attributed pull ───────────────────

  Scenario: There is no push counterpart to this feature
    Given the "Northwind" mapping exists
    Then no "Sync to Penpot" action exists anywhere in the admin panel or CLI

  Scenario: The pull always runs as the service account, never as a user
    Given two Nextcloud users both have personal Penpot tokens configured
    And both are members of the mapped "Northwind" team
    When the scheduled pull runs
    Then the pull uses the service-account token only
    And neither user's personal token is used for any read
    And the mapped folder is written by exactly one job, not once per user
