<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — the single home for every action button in the section,
 * rendered last (below Team mappings), laid out to match the sibling apps'
 * sync_settings.php so the three look the same:
 *
 *   • Manual bulk sync — ⭱ "Sync to Penpot" / ⭳ "Sync from Penpot"
 *   • Connection test — "Test connection"
 *
 * EVERY BUTTON IN HERE WORKS. That was not true while "Purge Nextcloud files" sat
 * between them, disabled, promising a delete machine that the spec then decided
 * never to build (features/AGENTS.md#retired--the-admin-purge — n8n and grafana
 * dropped theirs for the same reason). A disabled button is only honest while
 * someone still intends to enable it; past that it is a promise nobody is keeping,
 * and it had been making the panel lie for two courses.
 *
 * THE PUSH BUTTON IS NEW, AND THIS FILE USED TO ARGUE IT NEVER COULD BE. The old
 * comment said "there is no Sync to Penpot, and there never will be: this app is
 * read-only for file content (§6.1)". That over-read §6.1, which forbids pushing
 * shape data into a design Penpot ALREADY HAS — not making a design out of an
 * archive it has never seen. The push does only the latter, so the family's layout
 * no longer differs here at all.
 *
 * THE ORDER IS PUSH THEN PULL, copied from n8n rather than invented: the
 * destructive-sounding direction sits first, where it is read before it is
 * clicked, and both siblings put it there.
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
		<?php p($l->t('Check that Nextcloud can reach Penpot — this just tests the connection, nothing is synced. It reports which teams the service account can actually see, since that is what decides which teams you can map.')); ?>
	</p>

	<div id="penpot-sync-test" class="penpot-sync-test-wrap">
		<button type="button" id="penpot-sync-test-btn" class="button"><?php p($l->t('Test connection')); ?></button>
		<span id="penpot-sync-test-status" class="msg"></span>
	</div>
</div>
</div>
