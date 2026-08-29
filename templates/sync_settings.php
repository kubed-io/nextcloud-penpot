<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — all action buttons in one place, rendered last in the
 * section (below Team mappings):
 *
 *   • Manual bulk sync: ⭱ Sync to Penpot / ⭳ Sync from Penpot
 *   • Connection test: Test connection
 *
 * Push sits above pull: the destructive-sounding direction is read before it is
 * clicked.
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="section">
<div id="penpot-sync-manual" class="penpot-sync-manual">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Run a one-shot bulk sync at any time, whatever Sync Settings says above.')); ?>
	</p>

	<div class="penpot-sync-manual__row" data-direction="push">
		<button type="button" class="button js-run"><?php p($l->t('Sync to Penpot')); ?></button>
		<span class="penpot-sync-manual__last js-last"></span>
		<span class="penpot-sync-manual__hint"><?php p($l->t('(sync mappings only)')); ?></span>
	</div>

	<div class="penpot-sync-manual__row" data-direction="pull">
		<button type="button" class="button primary js-run"><?php p($l->t('Sync from Penpot')); ?></button>
		<span class="penpot-sync-manual__last js-last"></span>
	</div>

	<div class="penpot-sync-manual__footer">
		<span id="penpot-sync-manual-status" class="msg"></span>
	</div>

	<p class="settings-hint penpot-sync-actions__sep">
		<?php p($l->t('Check Nextcloud can reach Penpot and list the teams it can see. Nothing is synced.')); ?>
	</p>

	<div id="penpot-sync-test" class="penpot-sync-test-wrap">
		<button type="button" id="penpot-sync-test-btn" class="button"><?php p($l->t('Test connection')); ?></button>
		<span id="penpot-sync-test-status" class="msg"></span>
	</div>
</div>
</div>
