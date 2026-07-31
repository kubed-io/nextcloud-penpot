<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The record of the last pull — **one record, whichever trigger caused it**
 * (`admin-section.feature`).
 *
 * ## WHY IT IS ONE RECORD AND NOT THREE
 *
 * A pull can be started four ways: `occ`, a mapping card's "Sync now", the
 * section's "Sync from Penpot", or the schedule. If each kept its own state,
 * "when did this last sync?" would have four answers depending on which button
 * someone happened to press, and the schedule — the one nobody watches — would
 * be the one with no visible answer at all. That was the actual complaint that
 * started this slice: an interval was configured, nothing consumed it, and there
 * was no way to tell.
 *
 * ## ONE DIRECTION, UNLIKE BOTH SIBLINGS
 *
 * Theirs key this by `pull`/`push` because they push content. This app never
 * will (§6.1), so there is no direction to key on — a deliberate simplification
 * rather than an omission, and the reason this class is named for the pull
 * rather than for syncing in general.
 *
 * ## THE PREVIOUS RESULT SURVIVES A NEW RUN
 *
 * `queued` and `running` only flip `status`; the counters and `finished_at` from
 * the last completed run stay put. So the panel can say "running… (last: 3
 * minutes ago, 12 files)" instead of going blank the moment someone clicks —
 * which would read as "the previous run was lost".
 */
final class PullStatus {
	private const KEY = 'pull_status';

	/** Queued but not yet picked up by a cron worker. */
	public const QUEUED = 'queued';
	/** A worker (or a synchronous caller) is in the middle of it. */
	public const RUNNING = 'running';
	public const OK = 'ok';
	public const ERROR = 'error';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
	) {
	}

	/** @return array<string, mixed> the whole record, `[]` if nothing ever ran */
	public function get(): array {
		$decoded = json_decode(
			$this->config->getValueString(Application::APP_ID, self::KEY, '{}'),
			true,
		);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * True while a run is queued or in flight.
	 *
	 * The guard behind "a second click does not start another": two concurrent
	 * pulls over one folder tree would race on the same files, and the second one
	 * would spend a full sweep re-doing work the first is already doing.
	 */
	public function isBusy(): bool {
		$status = $this->get()['status'] ?? '';

		return $status === self::QUEUED || $status === self::RUNNING;
	}

	public function markQueued(): void {
		$this->save(['status' => self::QUEUED, 'queued_at' => $this->nowIso()]);
	}

	public function markStarted(): void {
		$this->save(['status' => self::RUNNING, 'started_at' => $this->nowIso()]);
	}

	/**
	 * Record a finished run. `$patch` carries the pull's own counters, so the
	 * panel reports what actually happened rather than just "ok".
	 *
	 * @param array<string, mixed> $patch
	 */
	public function markFinished(array $patch): void {
		$this->save(array_merge($patch, [
			'status' => $patch['status'] ?? self::OK,
			'finished_at' => $this->nowIso(),
		]));
	}

	public function markFailed(string $message): void {
		$this->save([
			'status' => self::ERROR,
			'message' => $message,
			'finished_at' => $this->nowIso(),
		]);
	}

	/**
	 * Merge on top of the existing record — never replace it.
	 *
	 * See the class docblock: a new run must not blank out the previous result,
	 * or the panel goes empty the instant someone clicks.
	 *
	 * @param array<string, mixed> $patch
	 */
	private function save(array $patch): void {
		$this->config->setValueString(
			Application::APP_ID,
			self::KEY,
			(string)json_encode(array_merge($this->get(), $patch)),
		);
	}

	private function nowIso(): string {
		return $this->time->getDateTime()->format(\DATE_ATOM);
	}
}
