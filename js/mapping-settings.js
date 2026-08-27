/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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
			} else if (btn.classList.contains('js-sync')) {
				syncCard(card);
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

	// Reads whatever the card still offers. A SAVED card renders its immutable
	// fields as text, so those selectors simply miss and the corresponding keys
	// are omitted — which is exactly what the update endpoint wants, since it
	// only accepts groups. A NEW card has every control, and create takes them
	// all.
	function readCard(card) {
		var teamSel = card.querySelector('.js-team');
		var ncEl = card.querySelector('.js-nc-folder');
		var modeEl = card.querySelector('.js-mode');
		var tfEl = card.querySelector('.js-use-team-folder');

		var groups = [];
		card.querySelectorAll('.js-groups input[type="checkbox"]:checked').forEach(function (cb) {
			groups.push(cb.value);
		});

		var data = { ncGroups: groups };

		if (card.dataset.id) {
			return data;
		}

		data.teamId = teamSel ? (teamSel.dataset.id || teamSel.value) : '';
		data.ncFolder = ncEl ? ncEl.value.trim() : '';
		data.mode = modeEl ? modeEl.value : 'link';
		data.useTeamFolder = tfEl ? tfEl.checked : false;

		return data;
	}

	/** Check exactly the boxes in `groups`, clearing the rest. */
	function showGroups(card, groups) {
		card.querySelectorAll('.js-groups input[type="checkbox"]').forEach(function (cb) {
			cb.checked = groups.indexOf(cb.value) !== -1;
		});
	}

	function saveCard(card) {
		var isNew = !card.dataset.id;
		var data = readCard(card);

		if (isNew && !data.teamId) {
			cardStatus(card, 'error', t('penpot_sync', 'Pick a Penpot team first.'));
			return;
		}

		submit(card, isNew, data);
	}

	/**
	 * Send one card, and handle the ONE refusal that is a question.
	 *
	 * A link mapping over a folder that already holds designs comes back 422 with
	 * a count. Everything else is a dead end and lands in the card's status line;
	 * this one becomes a confirmation, because the admin can answer it — and
	 * answering it destroys files that do NOT go to the trash.
	 *
	 * `purge` is passed on the retry only, never on the first attempt, so the panel
	 * cannot destroy anything the admin has not just been shown a number for.
	 */
	function submit(card, isNew, data, purge) {
		var body = data;
		if (purge) {
			// A COPY, so a retry cannot leave the flag on the card's own state and
			// quietly arm the next save.
			body = Object.assign({}, data, { purgeDesigns: true });
		}

		var req = isNew
			? api('POST', url('/mappings'), body)
			: api('PUT', url('/mappings/' + encodeURIComponent(card.dataset.id)), body);

		req.then(function (res) {
			if (isNew && res.id) {
				card.dataset.id = res.id;
				// Everything except the groups is immutable once created, so redraw
				// the card from the server's own renderer rather than trying to
				// convert the controls into text here. It also surfaces the folder
				// name the backend materialised from the team name, and the groups
				// the folder actually ended up shared with.
				window.location.reload();
				return;
			}

			// Re-tick from the RESPONSE, not from what was submitted. The server
			// applies the groups to the folder and reads them back, and the two can
			// differ — a group that does not exist cannot be shared with. Showing
			// the box still checked would claim a share that was never made.
			showGroups(card, res.nc_groups || []);
			cardStatus(card, 'success', t('penpot_sync', 'Saved.'));
		}).catch(function (err) {
			if (typeof err.designs === 'number' && !purge) {
				confirmPurge(card, isNew, data, err.designs);
				return;
			}
			cardStatus(card, 'error', err.message || t('penpot_sync', 'Save failed.'));
		});
	}

	/**
	 * Ask before destroying designs, and say how many and that they will not come
	 * back.
	 *
	 * THE COUNT AND THE WORD "PERMANENTLY" ARE THE POINT. This is the only gesture
	 * in the app that destroys something outright — a link mirror is a pointer, so
	 * a design already in the folder cannot survive there, and it may not go to the
	 * trash either: restoring one into a link mapping cannot work, so offering the
	 * restore would be a worse lie than refusing it.
	 *
	 * Cancelling needs no cleanup, and that is a property of the rule rather than
	 * an omission. The admin goes and moves the files, and when they come back the
	 * folder holds no designs — so the mapping is created with no warning at all.
	 */
	function confirmPurge(card, isNew, data, count) {
		var msg = n(
			'penpot_sync',
			'"{folder}" already holds {count} design. Mapping it in link mode will '
				+ 'permanently delete it — it will not go to the trash and cannot be recovered.',
			'"{folder}" already holds {count} designs. Mapping it in link mode will '
				+ 'permanently delete them — they will not go to the trash and cannot be recovered.',
			count,
			{ folder: data.ncFolder || '', count: count }
		);

		OC.dialogs.confirmDestructive(
			msg,
			t('penpot_sync', 'Delete these designs?'),
			{
				type: OC.dialogs.YES_NO_BUTTONS,
				confirm: n('penpot_sync', 'Delete {count} design', 'Delete {count} designs', count, { count: count }),
				confirmClasses: 'error',
				cancel: t('penpot_sync', 'Cancel')
			},
			function (ok) {
				if (!ok) {
					cardStatus(card, 'error', t('penpot_sync', 'Not saved — the folder still holds designs.'));
					return;
				}
				submit(card, isNew, data, true);
			}
		);
	}

	/**
	 * "Sync now" on one card — SYNCHRONOUS, unlike the section-wide button.
	 *
	 * One team is bounded work (a `link` mapping exports nothing at all), and the
	 * admin is looking at this card waiting for an answer about this team. Queuing
	 * it would trade a short wait for a spinner and a poll; the bulk button is
	 * async precisely because it is NOT bounded.
	 *
	 * The card keeps its own status line so two mappings can be synced in turn
	 * without their results overwriting each other.
	 */
	function syncCard(card) {
		if (!card.dataset.id) {
			cardStatus(card, 'error', t('penpot_sync', 'Save the mapping first.'));
			return;
		}

		var btn = card.querySelector('.js-sync');
		if (btn) {
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
		}
		cardStatus(card, '', t('penpot_sync', 'Syncing…'));

		fetch(OC.generateUrl('/apps/penpot_sync/mappings/' + encodeURIComponent(card.dataset.id) + '/sync'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		})
			.then(function (res) {
				return res.text().then(function (body) {
					var data;
					try {
						data = JSON.parse(body);
					} catch {
						// A proxy page, a login redirect or a CSRF failure answers with
						// HTML; parsing it would surface "Unexpected token <" instead of
						// anything the admin can act on.
						throw new Error(t('penpot_sync', 'Sync failed ({status}).', { status: res.status }));
					}
					if (!res.ok) {
						throw new Error(data.message || t('penpot_sync', 'Sync failed ({status}).', { status: res.status }));
					}
					return data;
				});
			})
			.then(function (data) {
				cardStatus(card, 'success', t('penpot_sync', 'Synced: {files} file(s), {exported} archive(s).', {
					files: data.files == null ? '?' : data.files,
					exported: data.exported == null ? 0 : data.exported,
				}));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('penpot_sync', 'Sync failed.'));
			})
			.finally(function () {
				if (btn) {
					btn.disabled = false;
					btn.removeAttribute('aria-busy');
				}
			});
	}

	function deleteCard(card) {
		// An unsaved card has nothing to delete server-side.
		if (!card.dataset.id) {
			card.remove();
			return;
		}

		// WHAT THE ADMIN LOSES, IN THE WORDS OF THE MODE THEY PICKED. The old
		// message said "Nothing is deleted — not in Penpot, and not in Nextcloud",
		// which stopped being true when the teardown landed: a `link` mapping's
		// pointers DO go. Saying so per mode is the difference between a warning
		// and a surprise, and the Penpot half — the one an admin actually fears —
		// is still the reassurance it always was.
		var msg = card.dataset.mode === 'sync'
			? t(
				'penpot_sync',
				'Remove mapping for team {team} to {folder}? Its designs stay in Nextcloud '
					+ 'and become unmapped, and Penpot is left alone.',
				{ team: card.dataset.teamName || '', folder: card.dataset.ncFolder || '' }
			)
			: t(
				'penpot_sync',
				'Remove mapping for team {team} to {folder}? Its links will be removed from '
					+ 'Nextcloud, and Penpot is left alone.',
				{ team: card.dataset.teamName || '', folder: card.dataset.ncFolder || '' }
			);

		// THE NATIVE DIALOG, NOT `window.confirm`. `OC.dialogs.confirmDestructive`
		// is not the old browser box and not the old jQuery one either: in
		// Nextcloud 34 it is built on the same `DialogBuilder` the Vue components
		// use, so it inherits the instance's theming — read out of the shipped
		// `core-main.js`, not assumed. This shape (YES_NO_BUTTONS, an explicit
		// destructive verb, `confirmClasses: 'error'`) is copied from the Files
		// app's own delete confirmation, which is the strongest available argument
		// for "native".
		//
		// No bundling needed, which is why it suits this file: `js/` is served
		// verbatim with no build step, so `@nextcloud/dialogs` is not importable
		// here — and reaching for it would mean moving the whole admin panel into
		// the Vite bundle for a confirmation box.
		OC.dialogs.confirmDestructive(
			msg,
			t('penpot_sync', 'Remove mapping'),
			{
				type: OC.dialogs.YES_NO_BUTTONS,
				confirm: t('penpot_sync', 'Remove mapping'),
				confirmClasses: 'error',
				cancel: t('penpot_sync', 'Cancel')
			},
			function (ok) {
				if (!ok) {
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
		);
	}

	function info(tip) {
		if (!tip) { return ''; }
		var safe = escapeHtml(tip);
		return ' <span class="penpot-sync-info" tabindex="0" aria-label="' + safe
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
			+   '<div class="penpot-sync-field pp-tf"><label class="penpot-sync-checkbox">'
			// UNCHECKED, even where groupfolders IS installed. A default has to be
			// the same through both front doors, and `occ add-mapping` with no flag
			// makes a plain shared folder (§C6.31) — a card that arrives pre-ticked
			// would make the panel and the CLI disagree about what "I said nothing"
			// means. Disabled when groupfolders is absent, so the option reads as
			// unavailable rather than merely off.
			+     '<input type="checkbox" class="js-use-team-folder"' + (teamFoldersAvailable() ? '' : ' disabled')
			+     ' /> ' + t('penpot_sync', 'Team Folder') + info(DESC.tf) + '</label></div>'
			+   '<div class="penpot-sync-field pp-groups"><label>' + t('penpot_sync', 'Groups') + info(DESC.groups) + '</label>'
			+     '<div class="js-groups penpot-sync-groups">' + groupBoxes + '</div></div>'
			+   '<div class="penpot-sync-mappings__actions">'
			+     '<button type="button" class="button js-save" title="' + escapeHtml(t('penpot_sync', 'Save'))
			+       '" aria-label="' + escapeHtml(t('penpot_sync', 'Save')) + '">' + (ICONS.save || '') + '</button>'
			+     '<button type="button" class="button js-sync" title="' + escapeHtml(t('penpot_sync', 'Sync now'))
			+       '" aria-label="' + escapeHtml(t('penpot_sync', 'Sync now')) + '">' + (ICONS.sync || '') + '</button>'
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
			// NOT res.json() directly: a reverse-proxy error page, a login
			// redirect or a CSRF failure answers with HTML, and parsing that
			// throws a SyntaxError which would replace the real diagnostic with
			// "Unexpected token <". Read the text and fall back to the status.
			return res.text().then(function (body) {
				var data;
				try { data = body ? JSON.parse(body) : null; } catch { data = null; }

				if (!res.ok) {
					// The server's own message is already localised and specific;
					// the status fallback only fires when there is no JSON body.
					var err = new Error(
						data && data.message
							? data.message
							: t('penpot_sync', 'Request failed ({status})', { status: res.status })
					);
					// AND ANY STRUCTURED DETAIL THAT CAME WITH IT. One refusal is a
					// question rather than a dead end — a link mapping over a folder
					// that already holds designs — and the caller needs the COUNT to
					// ask it. Reading it off the sentence would break on translation.
					if (data && typeof data.designs === 'number') {
						err.designs = data.designs;
					}
					return Promise.reject(err);
				}

				if (data === null) {
					return Promise.reject(new Error(
						t('penpot_sync', 'Request failed ({status})', { status: res.status })
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
