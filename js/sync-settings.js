/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel handlers.
 *
 * Test connection and "Sync from Penpot" are wired. "Purge" is still rendered
 * `disabled` (see templates/sync_settings.php) until its engine lands — giving
 * it a handler that reports "not implemented" would be a worse lie than a
 * disabled button.
 *
 * "Sync from Penpot" is ASYNC: the click queues a job and returns, then this
 * polls for the outcome. A bulk pull walks every mapped team and exports an
 * archive per drifted file, which outlives a request — so there is nothing to
 * wait for synchronously, and a request that died half way would leave the admin
 * unable to tell slow from broken. The per-mapping "Sync now" on a mapping card
 * is the opposite shape, for the opposite reason (bounded work, admin waiting).
 */
(function () {
	'use strict';

	function url(path) {
		return OC.generateUrl('/apps/penpot_sync' + path);
	}

	function init() {
		var btn = document.getElementById('penpot-sync-test-btn');
		if (!btn || btn.dataset.bound === '1') {
			return;
		}
		btn.dataset.bound = '1';
		btn.addEventListener('click', testConnection);

		var run = document.querySelector('.penpot-sync-manual__row[data-direction="pull"] .js-run');
		if (run && run.dataset.bound !== '1') {
			run.dataset.bound = '1';
			run.disabled = false;
			run.removeAttribute('title');
			run.addEventListener('click', startPull);
		}
		// Show whatever the last run was — including one the SCHEDULE did, which
		// is otherwise invisible and was the whole complaint that started this.
		refreshStatus();
	}

	function manualStatus() {
		return document.getElementById('penpot-sync-manual-status');
	}

	/** Render one status record, whichever trigger produced it. */
	function renderStatus(st) {
		var out = manualStatus();
		if (!out || !st || !st.status) {
			return false;
		}
		var busy = st.status === 'queued' || st.status === 'running';
		if (st.status === 'error') {
			out.className = 'msg error';
			out.textContent = t('penpot_sync', 'Last sync failed: {message}', { message: st.message || '' });
		} else if (busy) {
			out.className = 'msg';
			out.textContent = st.status === 'queued'
				? t('penpot_sync', 'Sync queued…')
				: t('penpot_sync', 'Syncing…');
		} else if (st.finished_at) {
			out.className = 'msg success';
			out.textContent = t('penpot_sync', 'Last sync: {files} file(s), {exported} archive(s) exported.', {
				files: st.files == null ? '?' : st.files,
				exported: st.exported == null ? 0 : st.exported,
			});
		}
		return busy;
	}

	function refreshStatus() {
		return fetch(url('/sync/status'), { headers: { Accept: 'application/json' } })
			.then(function (res) { return res.ok ? res.json() : null; })
			.then(function (st) {
				var busy = renderStatus(st);
				// Keep polling only while something is actually running, so an idle
				// settings page makes no requests at all.
				if (busy) {
					window.setTimeout(refreshStatus, 3000);
				}
				return busy;
			})
			.catch(function () { return false; });
	}

	function startPull() {
		var run = document.querySelector('.penpot-sync-manual__row[data-direction="pull"] .js-run');
		var out = manualStatus();
		run.disabled = true;
		out.className = 'msg';
		out.textContent = t('penpot_sync', 'Starting…');

		fetch(url('/sync/pull'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		})
			.then(function (res) {
				// 409 = a sync is already running. Not an error: the admin asked
				// for a sync and a sync is happening.
				if (res.status === 409) {
					out.textContent = t('penpot_sync', 'A sync is already running.');
					return;
				}
				if (!res.ok) {
					throw new Error(t('penpot_sync', 'Could not start the sync ({status}).', { status: res.status }));
				}
			})
			.catch(function (e) {
				out.className = 'msg error';
				out.textContent = e.message;
			})
			.then(function () {
				run.disabled = false;
				refreshStatus();
			});
	}

	function testConnection() {
		var btn = document.getElementById('penpot-sync-test-btn');
		var out = document.getElementById('penpot-sync-test-status');

		btn.disabled = true;
		out.textContent = t('penpot_sync', 'Testing…');
		out.className = 'msg';

		fetch(url('/test-connection'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		})
			.then(function (res) {
				// A proxy error page, a login redirect or a CSRF failure answers
				// with HTML — parsing that throws, and the admin would see
				// "Unexpected token <" instead of anything actionable.
				return res.text().then(function (body) {
					try {
						return JSON.parse(body);
					} catch {
						throw new Error(t('penpot_sync', 'Connection test failed ({status}).', { status: res.status }));
					}
				});
			})
			.then(function (res) {
				// The endpoint answers 200 even for a failed connection — the
				// verdict is in the payload. `success` and a useful outcome are
				// not the same thing: a token that authenticates but sees no teams
				// succeeds here and still blocks every mapping, so it gets its own
				// warning styling rather than a green tick.
				out.textContent = res.message;
				out.className = res.success
					? (res.teams && res.teams.length ? 'msg success' : 'msg warning')
					: 'msg error';
			})
			.catch(function (err) {
				out.textContent = err.message || t('penpot_sync', 'Connection test failed.');
				out.className = 'msg error';
			})
			.finally(function () {
				btn.disabled = false;
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
