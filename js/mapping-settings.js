/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Team-mapping admin handlers (vanilla JS, no build step — same as the sibling
 * apps' settings scripts; the Vite bundle is reserved for the Files-app surface
 * that lands in a later course).
 *
 * A mapping is a team, so there is no card-per-mapping form here — just a row
 * per mapped team, an add control, and the Test connection button.
 */
(function () {
	'use strict';

	var BASE = '/apps/penpot_sync';

	function init() {
		var root = document.getElementById('penpot-mapping-settings');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		var addBtn = document.getElementById('penpot-add-submit');
		if (addBtn) {
			addBtn.addEventListener('click', addMapping);
		}

		var testBtn = document.getElementById('penpot-test-connection');
		if (testBtn) {
			testBtn.addEventListener('click', testConnection);
		}

		// Delegated, so rows added after load are handled without rebinding.
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.penpot-remove');
			if (btn) {
				removeMapping(btn.closest('tr'));
			}
		});

		root.addEventListener('change', function (e) {
			if (e.target.classList.contains('penpot-mode')) {
				updateMode(e.target.closest('tr'), e.target.value);
			}
		});
	}

	function addMapping() {
		var teamSel = document.getElementById('penpot-add-team');
		var modeSel = document.getElementById('penpot-add-mode');
		if (!teamSel || !teamSel.value) {
			return;
		}

		var btn = document.getElementById('penpot-add-submit');
		btn.disabled = true;

		api('POST', BASE + '/mappings', {
			teamId: teamSel.value,
			mode: modeSel ? modeSel.value : 'link',
		}).then(function () {
			// Re-render server-side rather than building the row here: the server
			// owns the team name (it comes from Penpot, not from this page) and
			// the immutable folder-mode column. Reloading keeps one renderer.
			window.location.reload();
		}).catch(function (err) {
			btn.disabled = false;
			flash('error', err.message);
		});
	}

	function updateMode(row, mode) {
		if (!row) { return; }
		api('PUT', BASE + '/mappings/' + encodeURIComponent(row.dataset.id), { mode: mode })
			.then(function () {
				flash('success', t('penpot_sync', 'Saved.'));
			})
			.catch(function (err) {
				flash('error', err.message);
			});
	}

	function removeMapping(row) {
		if (!row) { return; }

		// Deliberately explicit that nothing is deleted. An admin removing a
		// mapping most fears losing files or upstream designs; saying so here
		// prevents the hesitation and the support question both.
		var name = row.querySelector('.penpot-team-name');
		var msg = t(
			'penpot_sync',
			'Stop mirroring {team}? Nothing is deleted — not in Penpot, and not in Nextcloud.',
			{ team: name ? name.textContent : '' }
		);
		if (!window.confirm(msg)) {
			return;
		}

		api('DELETE', BASE + '/mappings/' + encodeURIComponent(row.dataset.id))
			.then(function () {
				window.location.reload();
			})
			.catch(function (err) {
				flash('error', err.message);
			});
	}

	function testConnection() {
		var btn = document.getElementById('penpot-test-connection');
		var out = document.getElementById('penpot-test-result');
		btn.disabled = true;
		out.textContent = t('penpot_sync', 'Testing…');
		out.className = '';

		api('POST', BASE + '/test-connection')
			.then(function (res) {
				// The endpoint answers 200 even for a failed connection — the
				// verdict is in the payload. `success` and a useful outcome are
				// not the same thing: a token that authenticates but sees no
				// teams succeeds here and still blocks every mapping, so it gets
				// its own warning styling rather than a green tick.
				out.textContent = res.message;
				out.className = res.success
					? (res.teams && res.teams.length ? 'success' : 'warning')
					: 'error';
			})
			.catch(function (err) {
				out.textContent = err.message;
				out.className = 'error';
			})
			.finally(function () {
				btn.disabled = false;
			});
	}

	function api(method, url, body) {
		var opts = {
			method: method,
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					return Promise.reject(new Error(data && data.message ? data.message : 'HTTP ' + res.status));
				}
				return data;
			});
		});
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('penpot-test-result');
		if (!el) { return; }
		el.textContent = text;
		el.className = kind || '';
		if (flashTimer) { clearTimeout(flashTimer); }
		flashTimer = setTimeout(function () {
			el.textContent = '';
			el.className = '';
		}, 5000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
