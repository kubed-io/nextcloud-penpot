<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Team-mapping admin UI.
 *
 * A mapping is a TEAM and nothing else (saga §6.24), so this is much simpler
 * than the sibling apps' equivalent: there is no folder-name field (the Team
 * Folder is named after the Penpot team, always — §6.13), no group picker at
 * this stage, and no per-project rows (projects are mirrored by the pull, never
 * mapped). One row per mapped team.
 *
 * Server-renders the existing rows and the "add" form; js/mapping-settings.js
 * handles add/remove and the Test connection button.
 *
 * @var array{mappings: list<array<string,mixed>>, teams: list<array<string,mixed>>, error: ?string} $_
 * @var \OCP\IL10N $l
 */

/** @var list<array<string,mixed>> $mappings */
$mappings = $_['mappings'] ?? [];
/** @var list<array<string,mixed>> $teams */
$teams = $_['teams'] ?? [];
/** @var ?string $error */
$error = $_['error'] ?? null;

$unmapped = array_values(array_filter(
	$teams,
	static fn (array $t): bool => !($t['mapped'] ?? false),
));
?>

<div id="penpot-mapping-settings" class="section">
	<h2><?php p($l->t('Team mappings')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Each mapped Penpot team is mirrored into its own Nextcloud folder, named after the team. Penpot projects inside it become subfolders automatically — there is nothing to configure per project.')); ?>
	</p>

	<?php if ($error !== null) { ?>
		<?php /* Penpot is unreachable. The stored mappings below still render and
				 stay removable — a connection problem must not lock an admin out
				 of their own configuration. */ ?>
		<div class="penpot-notice penpot-notice--error">
			<strong><?php p($l->t('Could not reach Penpot.')); ?></strong>
			<?php p($error); ?>
			<br>
			<?php p($l->t('Existing mappings are shown below and can still be removed, but no new team can be mapped until this is fixed.')); ?>
		</div>
	<?php } elseif ($teams === []) { ?>
		<?php /* Authenticated, member of nothing — the §6.18 precondition, unmet.
				 This is the single most likely reason an admin gets stuck here, so
				 it names the exact fix rather than showing an empty dropdown. */ ?>
		<div class="penpot-notice">
			<strong><?php p($l->t('The service account cannot see any Penpot teams.')); ?></strong>
			<?php p($l->t('Penpot has no instance-wide view — an account only sees teams it belongs to. In Penpot, invite the service account to the team you want to mirror, then reload this page.')); ?>
		</div>
	<?php } ?>

	<table class="penpot-mappings" <?php if ($mappings === []) { ?>hidden<?php } ?>>
		<thead>
			<tr>
				<th><?php p($l->t('Penpot team')); ?></th>
				<th><?php p($l->t('Default file mode')); ?></th>
				<th><?php p($l->t('Folder mode')); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($mappings as $mapping) { ?>
				<tr data-id="<?php p($mapping['id']); ?>">
					<td>
						<span class="penpot-team-name"><?php p($mapping['team_name'] !== '' ? $mapping['team_name'] : $l->t('(unknown team)')); ?></span>
						<span class="penpot-team-id"><?php p($mapping['team_id']); ?></span>
					</td>
					<td>
						<select class="penpot-mode">
							<option value="link" <?php if ($mapping['mode'] === 'link') { ?>selected<?php } ?>><?php p($l->t('Link — pointers only')); ?></option>
							<option value="sync" <?php if ($mapping['mode'] === 'sync') { ?>selected<?php } ?>><?php p($l->t('Sync — download the design archive')); ?></option>
						</select>
					</td>
					<td>
						<?php /* Immutable after creation (§6.53) — rendered as text, not a
								 control, because changing it would restructure every folder
								 AND rename every project in Penpot. */ ?>
						<span class="penpot-folder-mode"><?php p($mapping['folder_mode']); ?></span>
						<span class="penpot-hint"><?php p($l->t('fixed')); ?></span>
					</td>
					<td>
						<button type="button" class="penpot-remove"><?php p($l->t('Remove')); ?></button>
					</td>
				</tr>
			<?php } ?>
		</tbody>
	</table>

	<p class="penpot-empty" <?php if ($mappings !== []) { ?>hidden<?php } ?>>
		<?php p($l->t('No Penpot teams are mapped yet.')); ?>
	</p>

	<?php if ($unmapped !== []) { ?>
		<div class="penpot-add">
			<label for="penpot-add-team"><?php p($l->t('Map a team')); ?></label>
			<select id="penpot-add-team">
				<?php foreach ($unmapped as $team) { ?>
					<?php /* The backend passes '' rather than inventing a placeholder,
							 precisely so the fallback shown here can be localised —
							 same translated string the table rows use. */ ?>
					<option value="<?php p($team['id']); ?>"><?php p($team['name'] !== '' ? $team['name'] : $l->t('(unknown team)')); ?></option>
				<?php } ?>
			</select>

			<select id="penpot-add-mode">
				<option value="link"><?php p($l->t('Link — pointers only')); ?></option>
				<option value="sync"><?php p($l->t('Sync — download the design archive')); ?></option>
			</select>

			<button type="button" id="penpot-add-submit" class="primary"><?php p($l->t('Map team')); ?></button>
		</div>
		<p class="settings-hint">
			<?php /* Said here because the schedule card is further down the page and
					 an admin who just mapped a team will otherwise wait for a sync
					 that cannot happen yet. */ ?>
			<?php p($l->t('Mapping a team records it. Nothing is mirrored yet — the pull is not built (see the project roadmap).')); ?>
		</p>
	<?php } elseif ($teams !== [] && $error === null) { ?>
		<p class="settings-hint"><?php p($l->t('Every team the service account can see is already mapped.')); ?></p>
	<?php } ?>

	<div class="penpot-test">
		<button type="button" id="penpot-test-connection"><?php p($l->t('Test connection')); ?></button>
		<span id="penpot-test-result" role="status" aria-live="polite"></span>
	</div>
</div>
