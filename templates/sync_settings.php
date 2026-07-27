<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — the single home for every action button in the section,
 * rendered last (below Team mappings), laid out to match the sibling apps'
 * sync_settings.php so the three look the same:
 *
 *   • Manual bulk sync — "Sync from Penpot"
 *   • Purge — remove the design files this app created (Nextcloud side only)
 *   • Connection test — "Test connection"
 *
 * NB (honest UI, saga Ch2): **Test connection works today.** "Sync from Penpot"
 * is disabled until Course 3's pull lands, and "Purge" until Course 5's delete
 * machine. Present-but-disabled rather than absent, so the finished shape of the
 * section is visible from the first release and enabling one later is deleting
 * an attribute.
 *
 * THERE IS NO "Sync to Penpot", and there never will be: this app is read-only
 * for file content (§6.1). That is the spine of the design, not a phase-ordering
 * gap — a disabled push button would promise a feature that is never coming.
 * This is the one place the family's layout deliberately differs, and the
 * difference is load-bearing.
 *
 * @var \OCP\IL10N $l
 */

// Tooltip on every not-yet-live button — one string, so the promise is consistent.
$soon = $l->t('Available once design sync lands (a later release). Test connection works now.');
?>
<div class="section">
<div id="penpot-sync-manual" class="penpot-sync-manual">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Run a one-shot bulk sync at any time. Sync from Penpot mirrors every mapped team\'s designs into Nextcloud. Nothing is ever written back to Penpot — this app mirrors designs, it does not edit them. Purge and bulk sync arrive with a later release; Test connection works now.')); ?>
	</p>

	<div class="penpot-sync-manual__row" data-direction="pull">
		<button type="button" class="button primary js-run" disabled title="<?php p($soon); ?>"><?php p($l->t('Sync from Penpot')); ?></button>
	</div>

	<div class="penpot-sync-manual__footer">
		<span id="penpot-sync-manual-status" class="msg"></span>
	</div>

	<p class="settings-hint penpot-sync-actions__sep">
		<?php p($l->t('Reset the Nextcloud side. Purge removes the design files this app created (sync & link). Penpot is never touched, and unmapped files are kept — get the rest back any time with “Sync from Penpot”.')); ?>
	</p>

	<div class="penpot-sync-manual__row" data-action="purge">
		<button type="button" class="button js-purge" disabled title="<?php p($soon); ?>"><?php p($l->t('Purge Nextcloud files')); ?></button>
		<span id="penpot-sync-purge-status" class="msg"></span>
	</div>

	<p class="settings-hint penpot-sync-actions__sep">
		<?php p($l->t('Check that Nextcloud can reach Penpot — this just tests the connection, nothing is synced. It reports which teams the service account can actually see, since that is what decides which teams you can map.')); ?>
	</p>

	<div id="penpot-sync-test" class="penpot-sync-test-wrap">
		<button type="button" id="penpot-sync-test-btn" class="button"><?php p($l->t('Test connection')); ?></button>
		<span id="penpot-sync-test-status" class="msg"></span>
	</div>
</div>
</div>
