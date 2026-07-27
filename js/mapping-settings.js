/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Team-mapping admin handlers (vanilla JS, no build step — same as the sibling
 * apps' settings scripts; the Vite bundle is reserved for the Files-app surface
 * that lands in a later course).
 *
 * One card per mapping, ported from nextcloud-grafana's mapping-settings.js so
 * the three apps behave identically: Add appends a blank card, Save posts it,
 * Delete removes it, and each card reports its own status inline.
 */
(function () {
	'use strict';

	// OC.generateUrl, never a hardcoded path (see
	// .github/instructions/frontend.instructions.md). A literal '/apps/...'
	// breaks on any instance served from a webroot (e.g. /nextcloud) or using
	// the index.php front controller — the request 404s with no clue why.
	function url(path) {
		return OC.generateUrl('/apps/penpot_sync' + path);
	}

	// Card glyphs are read from the root element's data-icons attribute, which
	// the server fills from img/icons/ — the same SVG folder any future bundle
	// would import. This unbundled script has no build step, so injection is how
	// it shares the one icon source.
	var ICONS = {};
	var DESC = {};

	function root() {
		return document.getElementById('penpot-sync-mappings');
	}

	function init() {
		var el = root();
		if (!el || el.dataset.bound === '1') {
			return;
		}
		el.dataset.bound = '1';

		try {
			ICONS = JSON.parse(el.dataset.icons || '{}');
		} catch {
			ICONS = {};
		}

		// Reuse the server-rendered tooltips verbatim, so a card built here
		// carries exactly the same help text as one rendered by PHP — one source
		// of wording, already translated.
		DESC = {};
		el.querySelectorAll('.penpot-sync-info').forEach(function (node) {
			var field = node.closest('.penpot-sync-field');
			if (field) {
				var key = (field.className.match(/pp-([a-z]+)/) || [])[1];
				if (key && !DESC[key]) {
					DESC[key] = node.dataset.tip || '';
				}
			}
		});

		var list = el.querySelector('.penpot-sync-mappings__list');

		list.addEventListener('click', function (e) {
			var btn = e.target.closest('button');
			if (!btn) { return; }
			var card = btn.closest('.penpot-sync-mappings__card');
			if (!card) { return; }

			if (btn.classList.contains('js-save')) {
				saveCard(card);
			} else if (btn.classList.contains('js-delete')) {
				deleteCard(card);
			}
		});

		var addBtn = document.getElementById('penpot-sync-mappings-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				list.appendChild(buildEmptyCard());
			});
		}
	}

	function availableTeams() {
		try { return JSON.parse(root().dataset.teams || '[]'); } catch { return []; }
	}

	function availableGroups() {
		try { return JSON.parse(root().dataset.groups || '[]'); } catch { return []; }
	}

	function teamFoldersAvailable() {
		return root().dataset.tfAvailable === '1';
	}

	function readCard(card) {
		var teamSel = card.querySelector('.js-team');
		var groups = [];
		card.querySelectorAll('.js-groups input[type="checkbox"]:checked').forEach(function (cb) {
			groups.push(cb.value);
		});
		var tfEl = card.querySelector('.js-use-team-folder');

		return {
			id: card.dataset.id || '',
			// A saved card's <select> is disabled (the team is immutable), and a
			// disabled control still reads its value fine — but the id is kept in
			// data-id so a saved card does not depend on the option's value at all.
			teamId: teamSel ? (teamSel.dataset.id || teamSel.value) : '',
			ncFolder: card.querySelector('.js-nc-folder').value.trim(),
			ncGroups: groups,
			useTeamFolder: tfEl ? tfEl.checked : true,
			mode: card.querySelector('.js-mode').value,
		};
	}

	function saveCard(card) {
		var data = readCard(card);

		if (!data.teamId) {
			cardStatus(card, 'error', t('penpot_sync', 'Pick a Penpot team first.'));
			return;
		}

		var isNew = !card.dataset.id;
		var req = isNew
			? api('POST', url('/mappings'), data)
			: api('PUT', url('/mappings/' + encodeURIComponent(card.dataset.id)), data);

		req.then(function (res) {
			if (isNew && res.id) {
				card.dataset.id = res.id;
			}
			// Reflect the materialised folder name back into the field, so a blank
			// entry shows the name the backend filled in from the Penpot team
			// rather than staying mysteriously empty.
			if (res.nc_folder) {
				var ncEl = card.querySelector('.js-nc-folder');
				if (ncEl && !ncEl.value.trim()) {
					ncEl.value = res.nc_folder;
				}
			}
			cardStatus(card, 'success', t('penpot_sync', 'Saved.'));
		}).catch(function (err) {
			cardStatus(card, 'error', err.message || t('penpot_sync', 'Save failed.'));
		});
	}

	function deleteCard(card) {
		// An unsaved card has nothing to delete server-side.
		if (!card.dataset.id) {
			card.remove();
			return;
		}

		// Deliberately explicit that nothing is deleted upstream. An admin
		// removing a mapping most fears losing files or designs; saying so here
		// prevents the hesitation and the support question both.
		var name = card.querySelector('.js-team');
		var msg = t(
			'penpot_sync',
			'Stop mirroring {team}? Nothing is deleted — not in Penpot, and not in Nextcloud.',
			{ team: name ? name.textContent.trim() : '' }
		);
		if (!window.confirm(msg)) {
			return;
		}

		api('DELETE', url('/mappings/' + encodeURIComponent(card.dataset.id)))
			.then(function () {
				card.remove();
				flash('success', t('penpot_sync', 'Mapping removed.'));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('penpot_sync', 'Delete failed.'));
			});
	}

	function info(tip) {
		if (!tip) { return ''; }
		var safe = escapeHtml(tip);
		return ' <span class="penpot-sync-info" tabindex="0" role="note" aria-label="' + safe
			+ '" data-tip="' + safe + '">' + (ICONS.info || '') + '</span>';
	}

	function buildEmptyCard() {
		var card = document.createElement('div');
		card.className = 'penpot-sync-mappings__card';

		// Only teams that are not already mapped can be added — the backend
		// refuses a duplicate anyway, but offering it would be a trap.
		var options = availableTeams()
			.filter(function (team) { return !team.mapped; })
			.map(function (team) {
				var label = team.name ? team.name + ' (' + team.id + ')' : team.id;
				return '<option value="' + escapeHtml(team.id) + '">' + escapeHtml(label) + '</option>';
			})
			.join('');

		var groupBoxes = availableGroups().map(function (g) {
			return '<label class="penpot-sync-groups__item"><input type="checkbox" value="'
				+ escapeHtml(g) + '" /> ' + escapeHtml(g) + '</label>';
		}).join('');

		card.innerHTML =
			'<div class="penpot-sync-mappings__grid">'
			+   '<div class="penpot-sync-field pp-team"><label>' + t('penpot_sync', 'Penpot team') + info(DESC.team) + '</label>'
			+     '<select class="js-team">' + options + '</select></div>'
			+   '<div class="penpot-sync-field pp-nc"><label>' + t('penpot_sync', 'Nextcloud folder') + info(DESC.nc) + '</label>'
			+     '<input type="text" class="js-nc-folder" placeholder="'
			+       escapeHtml(t('penpot_sync', 'defaults to the team name')) + '" /></div>'
			+   '<div class="penpot-sync-field pp-mode"><label>' + t('penpot_sync', 'Mode') + info(DESC.mode) + '</label>'
			+     '<select class="js-mode">'
			+       '<option value="link" selected>' + t('penpot_sync', 'Link') + '</option>'
			+       '<option value="sync">' + t('penpot_sync', 'Sync') + '</option>'
			+     '</select></div>'
			+   '<div class="penpot-sync-field pp-foldermode"><label>' + t('penpot_sync', 'Folder mode') + info(DESC.foldermode) + '</label>'
			+     '<span class="penpot-sync-fixed">nested <span class="penpot-sync-hint">'
			+       t('penpot_sync', '(fixed)') + '</span></span></div>'
			+   '<div class="penpot-sync-field pp-tf"><label class="penpot-sync-checkbox">'
			+     '<input type="checkbox" class="js-use-team-folder"' + (teamFoldersAvailable() ? ' checked' : '')
			+     ' /> ' + t('penpot_sync', 'Team Folder') + info(DESC.tf) + '</label></div>'
			+   '<div class="penpot-sync-field pp-groups"><label>' + t('penpot_sync', 'Groups') + info(DESC.groups) + '</label>'
			+     '<div class="js-groups penpot-sync-groups">' + groupBoxes + '</div></div>'
			+   '<div class="penpot-sync-mappings__actions">'
			+     '<button type="button" class="button js-save" title="' + escapeHtml(t('penpot_sync', 'Save'))
			+       '" aria-label="' + escapeHtml(t('penpot_sync', 'Save')) + '">' + (ICONS.save || '') + '</button>'
			+     '<button type="button" class="button js-delete" title="' + escapeHtml(t('penpot_sync', 'Delete'))
			+       '" aria-label="' + escapeHtml(t('penpot_sync', 'Delete')) + '">' + (ICONS.delete || '') + '</button>'
			+     '<span class="js-card-status"></span>'
			+   '</div>'
			+ '</div>';

		return card;
	}

	// `target`, not `url` — a parameter named `url` would shadow the url()
	// helper above for the whole body. Harmless today because nothing in here
	// calls it, but it is exactly the kind of shadowing that bites the next
	// person to add a retry or a redirect.
	function api(method, target, body) {
		var opts = {
			method: method,
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(target, opts).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					// The server's own message is already localised and specific;
					// the status fallback only fires when there is no body to show.
					return Promise.reject(new Error(
						data && data.message
							? data.message
							: t('penpot_sync', 'Request failed ({status})', { status: res.status })
					));
				}
				return data;
			});
		});
	}

	// Per-card status is STICKY (no auto-dismiss): a save error names the fix,
	// and a message that vanishes after five seconds is one the admin misses.
	function cardStatus(card, kind, text) {
		var el = card.querySelector('.js-card-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'js-card-status' + (kind ? ' msg ' + kind : '');
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('penpot-sync-mappings-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = kind ? 'msg ' + kind : 'msg';
		if (flashTimer) { clearTimeout(flashTimer); }
		flashTimer = setTimeout(function () {
			el.textContent = '';
			el.className = 'msg';
		}, 5000);
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
