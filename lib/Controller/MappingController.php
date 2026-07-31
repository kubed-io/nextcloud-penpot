<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Controller;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ConnectionTester;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\PullStatus;
use OCA\PenpotSync\Settings\AdminTest;
use OCA\PenpotSync\Settings\MappingSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST endpoints behind the team-mapping panel and the Test connection button.
 *
 * ## THIN BY DESIGN
 *
 * Every rule lives in {@see MappingService} / {@see ConnectionTester}, because
 * the `occ` commands enforce the same rules and there must be exactly one
 * implementation of each. This class only translates HTTP to a service call and
 * an exception to a status code.
 *
 * ## THE TWO FAILURE MODES ARE KEPT APART, ON PURPOSE
 *
 * `InvalidArgumentException` → **422**: the request was understood and refused
 * (already mapped, team not visible, folder mode not implemented). The admin
 * must change what they asked for.
 *
 * `PenpotApiException` → **502**: we could not get an answer from Penpot at all.
 * The admin must fix the connection, and nothing about their input was wrong.
 *
 * Collapsing both into 400 would send people to the wrong fix — the same
 * distinction the CLI draws, kept identical here.
 *
 * Every endpoint is gated by `#[AuthorizedAdminSetting]`, which is what
 * {@see MappingSettings} implementing `IDelegatedSettings` enables.
 */
final class MappingController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MappingService $service,
		private readonly ConnectionTester $tester,
		private readonly PullService $pull,
		private readonly PullStatus $status,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The configured mappings.
	 *
	 * No `#[NoCSRFRequired]`: it was here out of habit from read-only endpoints,
	 * but nothing needs it. js/mapping-settings.js sends `requesttoken` on every
	 * request, so the check passes — and dropping the attribute keeps the CSRF
	 * protection Nextcloud applies by default to an authenticated admin surface.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse([
			'mappings' => array_map(
				static fn (Mapping $m): array => $m->toArray(),
				$this->service->list(),
			),
		]);
	}

	/**
	 * Map a Penpot team.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function create(
		string $teamId,
		string $ncFolder = '',
		array $ncGroups = [],
		bool $useTeamFolder = true,
		string $mode = Mapping::MODE_LINK,
		string $folderMode = Mapping::FOLDER_MODE_NESTED,
	): JSONResponse {
		try {
			$mapping = $this->service->add(Mapping::fromArray([
				'team_id' => $teamId,
				'nc_folder' => $ncFolder,
				'nc_groups' => $ncGroups,
				'use_team_folder' => $useTeamFolder,
				'mode' => $mode,
				'folder_mode' => $folderMode,
			]));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (PenpotApiException $e) {
			return new JSONResponse(
				['message' => 'Could not check the team against Penpot: ' . $e->getMessage()],
				Http::STATUS_BAD_GATEWAY,
			);
		}

		return new JSONResponse($mapping->toArray());
	}

	/**
	 * Update a mapping's mutable fields — in practice, the groups its folder is
	 * shared with.
	 *
	 * Everything else is immutable once created: the team, the Nextcloud folder,
	 * the Team Folder flag, `mode`, and `folder_mode`. {@see MappingService::update()}
	 * gives the reason for each. Changing one means removing the mapping and
	 * adding it again, which makes the migration cost visible instead of hiding
	 * it behind a dropdown.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id, array $ncGroups = []): JSONResponse {
		$existing = $this->service->getById($id);

		if ($existing === null) {
			return new JSONResponse(['message' => 'No such mapping.'], Http::STATUS_NOT_FOUND);
		}

		try {
			// Only the groups are taken from the request. Every other field is
			// carried over from the stored mapping, so this endpoint CANNOT trip
			// the service's immutability checks with an omitted parameter's
			// default — a caller that sends nothing changes nothing, which is the
			// right behaviour for a PUT that only owns one field.
			$updated = $this->service->update($id, Mapping::fromArray([
				'id' => $existing->id,
				'team_id' => $existing->teamId,
				'team_name' => $existing->teamName,
				'nc_folder' => $existing->ncFolder,
				'nc_groups' => $ncGroups,
				'use_team_folder' => $existing->useTeamFolder,
				'mode' => $existing->mode,
				'folder_mode' => $existing->folderMode,
			]));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($updated->toArray());
	}

	/**
	 * Remove a mapping. Deletes nothing, in Penpot or in Nextcloud.
	 */
	/**
	 * "Sync now" on one mapping's card — SYNCHRONOUS, and deliberately so.
	 *
	 * One team is bounded work: a `link` mapping exports nothing at all (§5.5),
	 * and a `sync` one only re-exports files whose revision actually moved. The
	 * admin is looking at that card waiting for an answer about that team, so
	 * queuing it would replace a short wait with a spinner and a poll.
	 *
	 * The section-wide button is the opposite shape for the opposite reason —
	 * see {@see SyncController::pull()}.
	 *
	 * It records into the SAME {@see PullStatus} as every other trigger, so the
	 * panel's "last run" line does not depend on which button was pressed.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function sync(string $id): JSONResponse {
		$this->status->markStarted();
		try {
			$result = $this->pull->pull($id);
		} catch (\OutOfBoundsException $e) {
			$this->status->markFailed($e->getMessage());

			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (PenpotApiException $e) {
			$this->status->markFailed($e->getMessage());

			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->status->markFailed($e->getMessage());

			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->status->markFinished($result);

		return new JSONResponse(['status' => 'ok'] + $result);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function destroy(string $id): JSONResponse {
		if (!$this->service->remove($id)) {
			return new JSONResponse(['message' => 'No such mapping.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['status' => 'removed']);
	}

	/**
	 * Test connection — the button.
	 *
	 * Always HTTP 200, even when the connection failed: the *request* succeeded,
	 * and the outcome is in the payload. A 502 here would make the frontend
	 * unable to tell "the test ran and reports a bad token" from "the test
	 * endpoint itself is broken", and only the first has a message worth showing.
	 */
	// Gated on AdminTest, not MappingSettings: the connection test is not part of
	// the mapping panel — it lives in Sync Actions, and the CLI and the mapping
	// page both use it. AdminTest exists precisely to be this target (Nextcloud
	// gates admin endpoints by naming an IDelegatedSettings class), which is the
	// same arrangement nextcloud-grafana uses.
	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function testConnection(): JSONResponse {
		return new JSONResponse($this->tester->test()->jsonSerialize());
	}
}
