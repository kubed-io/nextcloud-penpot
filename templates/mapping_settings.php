<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Team-mapping admin UI. One **card per mapping** (a repeating form, not a
 * table), laid out to match `nextcloud-grafana` so all three apps in the family
 * look and behave the same:
 *
 *   col 1: Penpot team (row 1) · Nextcloud folder (row 2)
 *   col 2: Mode (row 1) · Team Folder (row 2)
 *   col 3: Groups picker (spans every row)
 *   row 4: Save / Delete
 *
 * Two fields fewer than the Grafana card: no Format, because a `.penpot` archive
 * has exactly one shape, and no Folder mode, which was a designed-but-unbuilt
 * fork rendered as a permanently-"(fixed)" label (§C6.36). Everything else —
 * including Groups and Team Folder — sits where the siblings put it.
 *
 * Groups is the one editable control on a saved card, and the only one whose
 * value is not stored: it is read from the mapped folder as this renders and
 * written straight back to it on save (§C6.35).
 *
 * Server-renders existing cards; js/mapping-settings.js handles add/save/delete
 * and the per-field help.
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

// Per-field help, shown via the ⓘ tooltip on each label — same affordance the
// sibling apps use, so the cards read identically.
$desc = [
	'team' => $l->t('The Penpot team to mirror. Its projects become subfolders inside the Nextcloud folder. Bound by team id, so renaming the team in Penpot never breaks the mapping.'),
	'nc' => $l->t('Name of the Nextcloud folder the team is mirrored into. Leave blank to use the Penpot team\'s own name. Fixed once the mapping is created — changing it would have to move the whole mirrored tree. Project folders inside it are always named exactly as Penpot names them.'),
	'mode' => $l->t('Link: a read-only pointer that opens the design in Penpot. Sync: the exported .penpot archive is downloaded and kept as a real file. Fixed once the mapping is created — switching it in bulk would either delete every downloaded archive or export every file at once; promote or demote individual files instead.'),
	'tf' => $l->t('On = an ownerless Team Folder (groupfolders). Off = a folder in the admin account shared to the groups below. Fixed once the mapping is created — switching would have to migrate the folder and its shares.'),
	'groups' => $l->t('Which Nextcloud groups the mapped folder is shared with. Read from the folder itself, so sharing it elsewhere — the Files app, occ — shows up here too, and syncing never puts back a group you removed.'),
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
		<?php p($l->t('Each mapping mirrors a Penpot team into a Nextcloud folder. The team\'s projects become subfolders automatically — there is nothing to configure per project. Hover the ⓘ on a field for details.')); ?>
	</p>

	<?php if ($error !== null) { ?>
		<?php /* Penpot is unreachable. The stored mappings below still render and
				 stay removable — a connection problem must not lock an admin out
				 of their own configuration. */ ?>
		<div class="penpot-sync-notice penpot-sync-notice--error">
			<strong><?php p($l->t('Could not reach Penpot.')); ?></strong>
			<?php p($error); ?>
			<br>
			<?php p($l->t('Existing mappings are shown below and can still be edited or removed, but no new team can be mapped until this is fixed.')); ?>
		</div>
	<?php } elseif ($teams === []) { ?>
		<?php /* Authenticated, member of nothing — the §6.18 precondition, unmet.
				 This is the single most likely reason an admin gets stuck here, so
				 it names the exact fix rather than showing an empty dropdown. */ ?>
		<div class="penpot-sync-notice">
			<strong><?php p($l->t('The service account cannot see any Penpot teams.')); ?></strong>
			<?php p($l->t('Penpot has no instance-wide view — an account only sees teams it belongs to. In Penpot, invite the service account to the team you want to mirror, then reload this page.')); ?>
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
			<div class="penpot-sync-mappings__card" data-id="<?php p($m['id']); ?>">
				<div class="penpot-sync-mappings__grid">
					<div class="penpot-sync-field pp-team">
						<label><?php p($l->t('Penpot team'));
			print_unescaped($info($desc['team'])); ?></label>
						<?php /* The team is immutable — a mapping IS its team, so a
								 different team is a different mapping. Rendered as a
								 single-option select purely so the card's shape matches
								 the sibling apps'. */ ?>
						<select class="js-team" data-id="<?php p($teamId); ?>" disabled>
							<option selected><?php p($label); ?></option>
						</select>
					</div>
					<div class="penpot-sync-field pp-nc">
						<label><?php p($l->t('Nextcloud folder'));
			print_unescaped($info($desc['nc'])); ?></label>
						<?php /* Immutable once created — re-pointing it would have to move
								 the whole mirrored tree and re-stamp every file's metadata.
								 Shown as text for the same reason folder mode is: a disabled
								 input invites typing and implies it might save. */ ?>
						<span class="penpot-sync-fixed"><?php p((string)($m['nc_folder'] ?? '')); ?>
							<span class="penpot-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
					</div>
					<div class="penpot-sync-field pp-mode">
						<label><?php p($l->t('Mode'));
			print_unescaped($info($desc['mode'])); ?></label>
						<?php /* Immutable: sync→link would delete every downloaded archive
								 under the mapping, link→sync would export every file at once.
								 Per-FILE promotion is the supported path, because it can ask
								 first. */ ?>
						<?php /* The SAME localised labels the add-card offers — a saved card
								 showing raw "link"/"sync" while a new one says "Link"/"Sync"
								 is both inconsistent and untranslatable. */ ?>
						<span class="penpot-sync-fixed"><?php p($modeSel === 'sync' ? $l->t('Sync') : $l->t('Link')); ?>
							<span class="penpot-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
					</div>
					<div class="penpot-sync-field pp-tf">
						<label><?php p($l->t('Team Folder'));
			print_unescaped($info($desc['tf'])); ?></label>
						<?php /* Immutable: switching backend would have to migrate the
								 provisioned folder and all its shares. Both siblings lock
								 this too. */ ?>
						<span class="penpot-sync-fixed"><?php p($useTf ? $l->t('yes') : $l->t('no')); ?>
							<span class="penpot-sync-hint"><?php p($l->t('(fixed)')); ?></span>
						</span>
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
						<?php /* Per-mapping sync — LIVE, and deliberately SYNCHRONOUS: one
								 mapping, bounded, answered in the same request, because the
								 admin is watching this card. The section-wide "Sync from
								 Penpot" is the async one. Same position and treatment as in
								 the sibling apps; enabling it was wiring a handler, not
								 redesigning the card, exactly as planned. */ ?>
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

	<p class="settings-hint">
		<?php p($l->t('Mapping a team records it. Nothing is mirrored yet — the pull is not built (see the project roadmap).')); ?>
	</p>
</div>
</div>
