<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\BackgroundJob;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\BulkPushService;
use OCA\PenpotSync\Service\PushStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * "Sync to Penpot", run out of band — the twin of {@see ManualPullJob}.
 *
 * ## WHY THE PUSH IS QUEUED TOO
 *
 * For the pull the reason is export volume; here it is import volume, which is
 * worse per file. `import-binfile` uploads a whole archive and Penpot unpacks it
 * server-side, so a folder of designs that has never been pushed — precisely the
 * case this button exists for, a team that mapped a folder full of `.penpot`
 * files — is the slowest run this app can be asked to do.
 *
 * The CLI is deliberately synchronous instead ({@see \OCA\PenpotSync\Command\Sync}):
 * an operator is watching it and an exit code is the answer. A button has neither.
 */
final class ManualPushJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private BulkPushService $push,
		private PushStatus $status,
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
			$this->status->markFinished($this->push->push($mappingId));
		} catch (\Throwable $e) {
			// The status record is the ONLY place this failure can surface — a
			// queued job has nobody to return to — so it must always be written.
			$this->logger->error('penpot_sync: manual push failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			$this->status->markFailed($e->getMessage());
		}
	}
}
