/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel handlers.
 *
 * Three buttons: "Sync to Penpot", "Sync from Penpot", and Test connection.
 *
 * BOTH SYNC DIRECTIONS ARE ASYNC: the click queues a job and returns, then this
 * polls for the outcome. A bulk pull walks every mapped team and exports an
 * archive per drifted file; a bulk push uploads an archive per file that has
 * never been a design. Either outlives a request, and a request that died half
 * way would leave the admin unable to tell slow from broken. The per-mapping
 * "Sync now" on a mapping card is the opposite shape, for the opposite reason
 * (bounded work, admin waiting).
 *
 * THE DIRECTION IS DATA, NOT A SECOND CODE PATH. Each row carries a
 * `data-direction`, and every function below takes one — the alternative was two
 * near-identical copies of the poller, which is how the two directions would
 * drift apart on the next change.
 */
(function () {
	'use strict';

	function url(path) {
		return OC.generateUrl('/apps/penpot_sync' + path);
	}

	/**
	 * ONE delegated listener on the panel root, bound once.
	 *
	 * The first version bound each button individually, inside an `init()` that
	 * early-returned if the TEST button was missing or already bound — so the
	 * Sync button silently never got a handler and clicking it did nothing at
	 * all. Delegation removes the whole class of bug: there is one listener, it
	 * cannot half-attach, and a button added later still works.
	 */
	function init() {
		var root = document.getElementById('penpot-sync-manual');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.js-run');
			if (btn) {
				startSync(btn);
			}
		});

		var test = document.getElementById('penpot-sync-test-btn');
		if (test) {
			test.addEventListener('click', testConnection);
		}

		// Show whatever the last run was — including one the SCHEDULE did, which
		// is otherwise completely invisible and was the complaint that started
		// this slice.
		refreshStatus();
	}

	function manualStatus() {
		return document.getElementById('penpot-sync-manual-status');
	}

	/** The row for a direction, and the per-row "last run" line inside it. */
	function rowFor(direction) {
		return document.querySelector('#penpot-sync-manual [data-direction="' + direction + '"]');
	}

	/** Which direction a button belongs to, read off its row. */
	function directionOf(btn) {
		var row = btn && btn.closest('[data-direction]');
		return (row && row.dataset.direction) === 'push' ? 'push' : 'pull';
	}

	/**
	 * Paint the shared status line. `busy` adds Nextcloud's own inline spinner.
	 *
	 * THE BUTTON KEEPS ITS LABEL. Swapping it to "Queued…" (which is what the
	 * sibling does) makes the button change width mid-click and briefly stops it
	 * saying what it is. The spinner lives on the status line instead, so the
	 * layout never moves and the control keeps its identity while it works.
	 */
	function flash(kind, text, busy) {
		var out = manualStatus();
		if (!out) { return; }
		out.className = 'msg' + (kind ? ' ' + kind : '') + (busy ? ' icon-loading-small penpot-sync-busy' : '');
		out.textContent = text;
	}

	/** Paint the per-row "last: …" line, which persists between runs. */
	function paintLast(direction, text) {
		var row = rowFor(direction);
		var last = row && row.querySelector('.js-last');
		if (last) { last.textContent = text; }
	}

	/**
	 * One line describing a finished run, whichever trigger produced it.
	 *
	 * THE TWO DIRECTIONS COUNT DIFFERENT THINGS and so cannot share a sentence: a
	 * pull reports files written and archives exported, a push reports designs
	 * made out of files that had none. Saying "12 files" for a push that created
	 * three designs would be counting the candidates it considered.
	 */
	function describe(direction, rec) {
		if (!rec || !rec.finished_at) {
			return t('penpot_sync', 'Never run.');
		}
		if (rec.status === 'error') {
			return t('penpot_sync', 'Last sync failed: {message}', { message: rec.message || '?' });
		}
		if (direction === 'push') {
			return t('penpot_sync', 'Last sync: {pushed} design(s) created from {processed} file(s).', {
				pushed: rec.pushed == null ? 0 : rec.pushed,
				processed: rec.processed == null ? 0 : rec.processed,
			});
		}
		return t('penpot_sync', 'Last sync: {files} file(s), {exported} archive(s) exported.', {
			files: rec.files == null ? '?' : rec.files,
			exported: rec.exported == null ? 0 : rec.exported,
		});
	}

	function statusUrl(direction) {
		return direction === 'push' ? '/sync/push-status' : '/sync/status';
	}

	function getStatus(direction) {
		return fetch(url(statusUrl(direction)), {
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		}).then(function (r) { return r.ok ? r.json() : null; });
	}

	/** Paint the current state of BOTH directions on load, without polling an idle page. */
	function refreshStatus() {
		['push', 'pull'].forEach(function (direction) {
			getStatus(direction).then(function (rec) {
				if (!rec) { return; }
				if (rec.status === 'queued' || rec.status === 'running') {
					// A run is already in flight — probably the schedule. Follow it.
					var row = rowFor(direction);
					pollUntilDone(row && row.querySelector('.js-run'), direction);
					return;
				}
				paintLast(direction, describe(direction, rec));
			}).catch(function () { /* an idle panel is not worth an error */ });
		});
	}

	/**
	 * The bulk sync is ASYNC: this POST enqueues a job and returns. The visible
	 * feedback starts BEFORE the round trip, because a click that looks like it
	 * did nothing is the thing people click twice.
	 */
	function startSync(btn) {
		var direction = directionOf(btn);

		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		flash('', t('penpot_sync', 'Queued…'), true);

		fetch(url(direction === 'push' ? '/sync/push' : '/sync/pull'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
		})
			.then(function (res) {
				if (res.status === 409) {
					// Not an error: a sync was asked for and a sync is happening.
					flash('', t('penpot_sync', 'A sync is already running.'), true);
					pollUntilDone(btn, direction);
					return;
				}
				if (!res.ok) {
					throw new Error(t('penpot_sync', 'Could not start the sync ({status}).', { status: res.status }));
				}
				pollUntilDone(btn, direction);
			})
			.catch(function (err) {
				flash('error', err.message || t('penpot_sync', 'Could not start the sync.'));
				release(btn);
			});
	}

	function release(btn) {
		if (btn) {
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
		}
	}

	/**
	 * Poll until the run finishes, backing off as it goes.
	 *
	 * Two deliberate departures from the sibling's version:
	 *
	 *  - **setTimeout, not setInterval.** An interval keeps firing while a slow
	 *    request is still in flight, so a sluggish server gets a pile-up of
	 *    overlapping polls exactly when it can least afford them. Each tick here
	 *    schedules the next one only after the previous answered.
	 *  - **It starts fast and slows down.** 400ms, 800ms, then a steady 2s. A
	 *    queued job is usually picked up almost immediately, so a flat 2s poll
	 *    means up to two seconds of dead air before "Running…" appears — which is
	 *    the exact window where a button feels broken.
	 */
	function pollUntilDone(btn, direction) {
		var waited = 0;
		var delay = 400;

		function tick() {
			getStatus(direction)
				.then(function (rec) {
					if (!rec) { return schedule(); }

					if (rec.status === 'running') {
						flash('', t('penpot_sync', 'Syncing…'), true);
					}
					if (rec.status === 'ok' || rec.status === 'error') {
						var line = describe(direction, rec);
						flash(rec.status === 'error' ? 'error' : 'success', line);
						paintLast(direction, line);
						release(btn);
						return;
					}
					schedule();
				})
				.catch(schedule);
		}

		function schedule() {
			// ~5 minutes. A queued job only moves when a cron worker picks it up,
			// so an instance with cron misconfigured would otherwise spin here
			// forever. Say it may still be running rather than call it failed.
			if (waited > 300000) {
				flash('', t('penpot_sync', 'Still running in the background — check back shortly.'));
				release(btn);
				return;
			}
			waited += delay;
			window.setTimeout(tick, delay);
			delay = Math.min(delay * 2, 2000);
		}

		tick();
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
