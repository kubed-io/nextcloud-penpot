<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Controller;

use OCA\PenpotSync\BackgroundJob\ManualPullJob;
use OCA\PenpotSync\Service\PullStatus;
use OCA\PenpotSync\Settings\SyncSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * The section-wide "Sync from Penpot" button, and the state the panel polls
 * (`admin-section.feature`).
 *
 * ## ASYNC, UNLIKE THE PER-MAPPING BUTTON
 *
 * This one queues {@see ManualPullJob} and returns immediately. A bulk pull
 * walks every mapped team and exports an archive per drifted `sync` file, which
 * outlives a PHP request — and a request that dies half way leaves no record of
 * how far it got.
 *
 * {@see MappingController::sync()} is the deliberate opposite: one mapping, run
 * inline, answered in the same request. The two buttons look similar and are
 * shaped differently on purpose, because one is bounded and the other is not.
 *
 * ## THERE IS NO PUSH ENDPOINT AND NEVER WILL BE
 *
 * Both siblings have one. §6.1 makes this app read-only for design content — a
 * permanent boundary, not a phase-ordering gap — so the absence here is the
 * feature, and a disabled push button would promise something that is not
 * coming.
 */
final class SyncController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IJobList $jobList,
		private readonly PullStatus $status,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Queue a full pull.
	 *
	 * Answers `202 Accepted` — the work has been accepted, not done. Returning
	 * 200 would tell the panel a sync had finished when it has not started.
	 */
	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function pull(): JSONResponse {
		if ($this->status->isBusy()) {
			// Not an error: the admin asked for a sync and a sync is happening.
			// Queuing a second would have two pulls racing over one folder tree.
			return new JSONResponse(
				['status' => 'already-running'] + $this->status->get(),
				\OCP\AppFramework\Http::STATUS_CONFLICT,
			);
		}

		$this->status->markQueued();
		$this->jobList->add(ManualPullJob::class, ['mappingId' => null]);

		return new JSONResponse(
			['status' => PullStatus::QUEUED] + $this->status->get(),
			\OCP\AppFramework\Http::STATUS_ACCEPTED,
		);
	}

	/**
	 * The last (or current) run, for the panel to poll.
	 *
	 * ONE RECORD FOR EVERY TRIGGER — `occ`, either button, or the schedule. See
	 * {@see PullStatus}: the alternative is "when did this last sync?" having a
	 * different answer per button, and the schedule having none at all.
	 */
	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function status(): JSONResponse {
		return new JSONResponse($this->status->get());
	}
}
