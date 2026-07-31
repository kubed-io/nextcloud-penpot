# Uninstall lifecycle — what happens to the SYSTEM and to the user's DATA when the
# app is removed, and that a reinstall reconnects cleanly.
#
#   - SYSTEM: removing the app runs the <uninstall> repair step (UnregisterMimetype),
#     which REVERTS the custom-mimetype registration the install wrote into the
#     Nextcloud core tree (config/mimetype*.json, core/img/filetypes/Penpot.svg,
#     core/js/mimetypelist.js) and re-stamps the .penpot filecache rows back to a
#     generic archive mimetype (Penpot's own server serves the export as
#     application/zip — saga §6.4 — so there's no Penpot-branded mimetype to fall
#     back to; this app owns the registration end to end, same as both siblings).
#   - DATA: the app ORPHANS the user's data — it never deletes the .penpot files,
#     never clears their Files-Metadata, never deletes Team Folders, never touches
#     Penpot. Every "sync"-mode file is a real archive (saga §6.22), so deleting
#     one would be genuine data loss; "link"-mode files hold no bytes, but their
#     penpot_id is what makes a later reconnect free, so those aren't deleted
#     either. To wipe the Nextcloud side deliberately, an admin uses Purge first
#     (see purge.feature).
#
# ONE THING SIMPLER THAN BOTH SIBLINGS: reconnection here is PULL-ONLY. n8n/Grafana's
# reinstall story has to worry about a stray push racing the first pull after
# re-enable; Penpot Sync never writes back (saga §6.1), so "reinstall reconciles
# in place, no duplicates" is strictly a read-side guarantee — there is no writeback
# half to reason about at all.
#
# Because the files keep their penpot_id (Files-Metadata, saga §6.1/§6.14), a
# reinstall + pull RECONCILES them in place (matched by id, never duplicated) — the
# reconnect is free, by design, same as both sibling apps.
#
# The <uninstall> system leg needs a full app remove on a live pod (CI can't drive
# it), so it stays @todo; the data-orphan + reinstall-reconnect legs are provable via
# disable/re-enable + a pull, which exercises the same metadata-keyed reconcile.
#
# @todo — the old note here read "no lib/ exists yet (zero code, v0.1.0)", which
# has been false for four courses. The sync engine and the mimetype registration
# are both BUILT: lib/Migration/UnregisterMimetype.php is wired to the
# <uninstall> repair step in appinfo/info.xml as of C6.1, and it is a deliberate
# mirror image of the install step — config keys removed, icon deleted, filecache
# rows re-stamped, mimetypelist.js regenerated, and nothing of the user's touched.
#
# What is missing is still only the DRIVER: a repair step registered under
# <uninstall> runs on a real app removal, which CI does not perform. So the
# system-cleanup leg stays unproven rather than unbuilt.
#
# ONE CHOICE WORTH READING BEFORE ASSERTING ON IT: the revert re-stamps `.penpot`
# rows to `application/zip`, not `application/json`. Both siblings revert to JSON
# because their mirrors always were JSON; ours are ZIP in `sync` mode and JSON in
# `link` mode, and no extension-keyed mimetype can be right for both. `zip` is
# what Penpot's own server calls the format (§6.4, confirmed live), so it is the
# honest answer for the archive the user is left holding.

Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to Penpot
    And a folder mapped to the Penpot team "Northwind"

    # ── system cleanup (needs a live app remove — @todo in CI) ────────────────────
  @todo
  Scenario: Removing the app reverts the custom mimetype registration
    Given the app registered a custom mimetype for ".penpot" files on install
    When the app is removed
    Then the mimetype mapping for ".penpot" is gone from the Nextcloud config
    And the Penpot icon is removed from the core filetype icons
    And a ".penpot" file resolves to a generic archive mimetype again

    # ── data is orphaned, never deleted ───────────────────────────────────────────
  @todo
  Scenario: Disabling the app leaves the mirrored design files (and their identity) in place
    Given the mapped folder has mirrored ".penpot" files
    When the admin disables the app
    Then the ".penpot" files are still in the folder
    And each file still carries its "penpot_id" metadata

    # ── reinstall reconnects with no duplicates (the headline) ────────────────────
  @todo
  Scenario: Re-enabling and pulling reconciles the existing files without duplicates
    Given the mapped folder has mirrored ".penpot" files
    And the admin disables and then re-enables the app
    When the admin clicks "Sync from Penpot" for the mapping
    Then each existing file is updated in place, matched by its "penpot_id"
    And no file gains a " (2)" collision-suffixed duplicate
