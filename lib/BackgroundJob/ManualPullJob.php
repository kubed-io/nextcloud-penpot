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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * "Sync from Penpot", run out of band (`connection/sync-now.feature`).
 *
 * ## WHY THE BULK PULL CANNOT BE SYNCHRONOUS
 *
 * It walks every mapped team, and for each `sync` file whose revision moved it
 * exports a full `.penpot` archive over SSE plus a second authenticated fetch
 * (§5.1/§5.4). One archive on the instance this was built against is ~110 KB and
 * takes real seconds; a team of them outlives a PHP request. Worse, a request
 * that dies half way leaves nothing behind saying how far it got, so an admin
 * cannot tell a slow sync from a broken one.
 *
 * Queuing it means the work survives the admin navigating away — which is what
 * people actually do with a button that takes a while — and the run stays
 * visible in {@see PullStatus} throughout.
 *
 * The per-mapping button is deliberately the opposite shape (synchronous,
 * bounded): see {@see \OCA\PenpotSync\Controller\MappingController::sync()}.
 */
final class ManualPullJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PullService $pull,
		private PullStatus $status,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$mappingId = is_array($argument) ? ($argument['mappingId'] ?? null) : null;
		$mappingId = is_string($mappingId) && $mappingId !== '' ? $mappingId : null;

		$this->status->markStarted();
		try {
			$this->status->markFinished($this->pull->pull($mappingId));
		} catch (\Throwable $e) {
			// The status record is the ONLY place this failure can surface — a
			// queued job has nobody to return to — so it must always be written.
			$this->logger->error('penpot_sync: manual pull failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			$this->status->markFailed($e->getMessage());
		}
	}
}
