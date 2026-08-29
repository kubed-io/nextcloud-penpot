<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Team-mapping admin UI. One **card per mapping** (a repeating form, not a
 * table). Each field label carries a ⓘ info button (CSS tooltip) explaining it.
 * Server-renders existing cards; js/mapping-settings.js handles add/save/delete.
 *
 * Card grid: team + mode on row 1, folder + team-folder on row 2 (left two
 * columns); groups picker spans both rows on the right; Save/Sync/Delete below.
 *
 * Every field but Groups is immutable once saved, and renders as plain text to
 * say so. Groups is also the only value not stored here: it is read from the
 * mapped folder as this renders and written back to it on save.
 *
 * @var array{mappings: list<array<string,mixed>>, teams: list<array<string,mixed>>, groups: list<string>, team_folders_available: bool, error: ?string} $_
 * @var \OCP\IL10N $l
 */

/** @var list<array<string,mixed>> $mappings */
$mappings = $_['mappings'] ?? [];
/** @var list<array<string,mixed>> $teams */
$teams = $_['teams'] ?? [];
/** @var list<string> $groups */
$groups = $_['groups'] ?? [];
/** @var bool $tfAvailable */
$tfAvailable = (bool)($_['team_folders_available'] ?? false);
/** @var ?string $error */
$error = $_['error'] ?? null;

// Per-field help, shown via the ⓘ tooltip on each label.
$desc = [
	'team' => $l->t('The Penpot team to mirror. Its projects become subfolders of the Nextcloud folder.'),
	'nc' => $l->t('The Nextcloud folder the team is mirrored into. Blank uses the Penpot team\'s own name. Fixed once saved.'),
	'mode' => $l->t('Link: a read-only pointer that opens the design in Penpot. Sync: the .penpot archive is downloaded and kept as a real file. Fixed once saved.'),
	'tf' => $l->t('On: an ownerless Team Folder (groupfolders). Off: an admin-owned folder shared to the groups. Fixed once saved.'),
	'groups' => $l->t('Nextcloud groups the mapped folder is shared with. Read from the folder itself, so re-sharing it elsewhere shows up here too.'),
];

// Inline an SVG glyph from img/icons/ — the single source of truth for the app's
// icons. Trusted, app-owned files, safe to embed verbatim; the licence-comment
// header is stripped so only the <svg> reaches the DOM.
$icons = [];
$icon = static function (string $name) use (&$icons): string {
	if (!array_key_exists($name, $icons)) {
		$path = __DIR__ . '/../img/icons/' . $name . '.svg';
		$svg = is_file($path) ? (string)file_get_contents($path) : '';
		$icons[$name] = trim((string)preg_replace('/^\s*<!--.*?-->\s*/s', '', $svg));
	}

	return $icons[$name];
};

// Renders a ⓘ info button with a hover/focus tooltip (styled in CSS).
$info = static function (string $tip) use ($icon): string {
	$t = \OCP\Util::sanitizeHTML($tip);

	// No role="note": this is focusable and behaves like a help trigger, so
	// announcing it as a note is misleading. The aria-label carries the text.
	return ' <span class="penpot-sync-info" tabindex="0" aria-label="' . $t . '" data-tip="' . $t . '">'
		. $icon('info')
		. '</span>';
};
?>
<div class="section">
<div id="penpot-sync-mappings" class="penpot-sync-mappings"
	data-teams="<?php p(json_encode($teams)); ?>"
	data-groups="<?php p(json_encode($groups)); ?>"
	data-tf-available="<?php p($tfAvailable ? '1' : '0'); ?>"
	data-icons="<?php p(json_encode([
		'info' => $icon('info'),
		'save' => $icon('save'),
		'sync' => $icon('sync'),
		'delete' => $icon('delete'),
	])); ?>">
	<h3 class="penpot-sync-mappings__heading"><?php p($l->t('Team mappings')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Each mapping mirrors a Penpot team into a Nextcloud folder; its projects become subfolders automatically. Hover ⓘ for details.')); ?>
	</p>

	<?php if ($error !== null) { ?>
		<?php /* Penpot is unreachable. The stored mappings below still render and
				 stay removable — a connection problem must not lock an admin out
				 of their own configuration. */ ?>
		<div class="penpot-sync-notice penpot-sync-notice--error">
			<strong><?php p($l->t('Could not reach Penpot.')); ?></strong>
			<?php p($error); ?>
			<br>
			<?php p($l->t('Existing mappings can still be edited or removed; no new team can be mapped until this is fixed.')); ?>
		</div>
	<?php } elseif ($teams === []) { ?>
		<?php /* Authenticated but a member of nothing — the likeliest reason an admin
				 gets stuck here, so it names the fix rather than showing an empty
				 dropdown. */ ?>
		<div class="penpot-sync-notice">
			<strong><?php p($l->t('The service account cannot see any Penpot teams.')); ?></strong>
			<?php p($l->t('An account only sees teams it belongs to. Invite the service account to the team you want to mirror, then reload this page.')); ?>
		</div>
	<?php } ?>

	<div class="penpot-sync-mappings__list">
		<?php foreach ($mappings as $m) { ?>
			<?php
			$teamId = (string)($m['team_id'] ?? '');
			$teamName = (string)($m['team_name'] ?? '');
			$modeSel = (($m['mode'] ?? '') === 'sync') ? 'sync' : 'link';
			$label = $teamName !== '' ? $teamName . ' (' . $teamId . ')' : $teamId;
			$selectedGroups = $m['nc_groups'] ?? [];
			$useTf = filter_var($m['use_team_folder'] ?? $tfAvailable, FILTER_VALIDATE_BOOLEAN);
			?>
			<?php /* The data- attributes carry the RAW values. The fields render
					 localised text, which is right for a reader and useless to the
					 delete confirmation that has to compare against "sync". */ ?>
			<div class="penpot-sync-mappings__card" data-id="<?php p($m['id']); ?>"
				data-team-name="<?php p($teamName !== '' ? $teamName : $teamId); ?>"
				data-nc-folder="<?php p((string)($m['nc_folder'] ?? '')); ?>"
				data-mode="<?php p($modeSel); ?>">
				<div class="penpot-sync-mappings__grid">
					<div class="penpot-sync-field pp-team">
						<label><?php p($l->t('Penpot team'));
			print_unescaped($info($desc['team'])); ?></label>
						<?php /* A mapping IS its team, so a different team is a different mapping.
								 A single-option select keeps the card the same shape as the others. */ ?>
						<select class="js-team" data-id="<?php p($teamId); ?>" disabled>
							<option selected><?php p($label); ?></option>
						</select>
					</div>
					<div class="penpot-sync-field pp-nc">
						<label><?php p($l->t('Nextcloud folder'));
			print_unescaped($info($desc['nc'])); ?></label>
						<?php /* Re-pointing this would have to move the whole mirrored tree and
								 re-stamp every file. Text, not a disabled input: an input invites
								 typing and implies it might save. */ ?>
						<span class="penpot-sync-fixed"><?php p((string)($m['nc_folder'] ?? '')); ?></span>
					</div>
					<div class="penpot-sync-field pp-mode">
						<label><?php p($l->t('Mode'));
			print_unescaped($info($desc['mode'])); ?></label>
						<?php /* sync→link would delete every downloaded archive under the mapping;
								 link→sync would export every file at once. Remap the team to change
								 it. Labels match the add-card's, so a saved card never shows a raw
								 untranslated "sync". */ ?>
						<span class="penpot-sync-fixed"><?php p($modeSel === 'sync' ? $l->t('Sync') : $l->t('Link')); ?></span>
					</div>
					<div class="penpot-sync-field pp-tf">
						<label><?php p($l->t('Team Folder'));
			print_unescaped($info($desc['tf'])); ?></label>
						<?php /* Switching backend would have to migrate the provisioned folder and
								 all of its shares. */ ?>
						<span class="penpot-sync-fixed"><?php p($useTf ? $l->t('yes') : $l->t('no')); ?></span>
					</div>
					<div class="penpot-sync-field pp-groups">
						<label><?php p($l->t('Groups'));
			print_unescaped($info($desc['groups'])); ?></label>
						<div class="js-groups penpot-sync-groups">
							<?php foreach ($groups as $g) { ?>
								<label class="penpot-sync-groups__item">
									<input type="checkbox" value="<?php p($g); ?>" <?php if (in_array($g, $selectedGroups, true)) {
										print_unescaped('checked');
									} ?> /> <?php p($g); ?>
								</label>
							<?php } ?>
						</div>
					</div>
					<div class="penpot-sync-mappings__actions">
						<button type="button" class="button js-save" title="<?php p($l->t('Save')); ?>" aria-label="<?php p($l->t('Save')); ?>">
							<?php print_unescaped($icon('save')); ?>
						</button>
						<?php /* Deliberately synchronous: one mapping, bounded, answered in the same
								 request, because the admin is watching this card. The section-wide
								 "Sync from Penpot" is the async one. */ ?>
						<button type="button" class="button js-sync" title="<?php p($l->t('Sync now')); ?>" aria-label="<?php p($l->t('Sync now')); ?>">
							<?php print_unescaped($icon('sync')); ?>
						</button>
						<button type="button" class="button js-delete" title="<?php p($l->t('Delete')); ?>" aria-label="<?php p($l->t('Delete')); ?>">
							<?php print_unescaped($icon('delete')); ?>
						</button>
						<span class="js-card-status"></span>
					</div>
				</div>
			</div>
		<?php } ?>
	</div>

	<div class="penpot-sync-mappings__footer">
		<button type="button" id="penpot-sync-mappings-add" class="button">
			+ <?php p($l->t('Add mapping')); ?>
		</button>
		<span id="penpot-sync-mappings-status" class="msg"></span>
	</div>
</div>
</div>
