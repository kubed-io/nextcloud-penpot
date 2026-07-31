<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\BackgroundJob;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\PullStatus;
use OCA\PenpotSync\Service\ScheduleConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The unattended pull, on the interval from Sync Settings
 * (`admin-section.feature`, `reconcile.feature`).
 *
 * ## THIS IS THE SLICE'S WHOLE POINT
 *
 * The interval has been configurable since Course 2 and was read by **nothing**.
 * `occ penpot_sync:show-config` said so out loud — *"every 1h (not running — the
 * pull job is not built yet)"* — but from the admin surface it looked like a
 * working setting. A design renamed in Penpot stayed renamed only in Penpot
 * until somebody ran `occ` by hand, which is exactly how this was reported.
 *
 * The pull itself has followed renames since Course 3. Nothing about it changes
 * here; it finally just gets asked.
 *
 * ## A TimedJob, NOT A CRON EXPRESSION
 *
 * Nextcloud schedules by interval, and the interval is re-read every time the
 * job is INSTANTIATED — so changing it in settings takes effect on the next tick
 * rather than needing a re-registration.
 *
 * The 60s floor is not a preference: a shorter interval would have the job
 * re-entering faster than a real pull over a large team can finish.
 *
 * ## DISABLED MEANS "DO NOTHING", NOT "DO NOT TICK"
 *
 * When the schedule is off, `run()` returns immediately rather than the job
 * being unregistered. Turning it back on then takes effect by itself, with no
 * re-registration and no app reload — the interval simply gates how often the
 * setting is re-read.
 */
final class ScheduledPullJob extends TimedJob {
	/** Never re-enter faster than this, whatever the setting says. */
	private const MIN_INTERVAL = 60;

	public function __construct(
		ITimeFactory $time,
		private ScheduleConfig $schedule,
		private PullService $pull,
		private PullStatus $status,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(max(self::MIN_INTERVAL, $this->schedule->getIntervalSeconds()));
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->schedule->isEnabled()) {
			return;
		}

		// A manual run already in flight owns the tree; two concurrent pulls would
		// race on the same files. Skipping beats queuing — the next tick is
		// another chance, and the work is idempotent anyway.
		if ($this->status->isBusy()) {
			return;
		}

		$this->status->markStarted();
		try {
			$this->status->markFinished($this->pull->pull(null));
		} catch (\Throwable $e) {
			$this->logger->error('penpot_sync: scheduled pull failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			$this->status->markFailed($e->getMessage());
		}
	}
}
