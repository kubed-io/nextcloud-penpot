/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel handlers.
 *
 * Only **Test connection** is wired today. "Sync from Penpot" and "Purge" are
 * rendered `disabled` (see templates/sync_settings.php) until their engines land
 * in Courses 3 and 5, so they need no handler yet — and giving them a handler
 * that reports "not implemented" would be a worse lie than a disabled button.
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
