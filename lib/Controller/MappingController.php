<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Controller;

use OCA\PenpotSync\Exception\ExistingDesignsException;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ConnectionTester;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\MappingTeardownService;
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
 * (already mapped, team not visible, folder name already taken). The admin must
 * change what they asked for.
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
		private readonly MappingTeardownService $teardown,
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
				fn (Mapping $m): array => $this->service->describe($m),
				$this->service->list(),
			),
		]);
	}

	/**
	 * Map a Penpot team.
	 *
	 * `$ncGroups` is passed alongside the mapping rather than into it: groups are
	 * applied to the provisioned folder and read back from it, never stored
	 * (§C6.35).
	 *
	 * `purgeDesigns` DEFAULTS TO FALSE, AND THAT IS THE SAFETY. A link mapping over
	 * a folder that already holds `.penpot` files is refused with a 422 naming the
	 * count; the panel turns that into a confirmation and re-submits with the flag
	 * set. So the destructive path cannot be reached by a client that does not know
	 * about it — an older panel, a script, a curl — which is the property that
	 * matters for the one operation here that does not go to the trash.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function create(
		string $teamId,
		string $ncFolder = '',
		array $ncGroups = [],
		bool $useTeamFolder = false,
		string $mode = Mapping::MODE_LINK,
		bool $purgeDesigns = false,
	): JSONResponse {
		try {
			$mapping = $this->service->add(Mapping::fromArray([
				'team_id' => $teamId,
				'nc_folder' => $ncFolder,
				'use_team_folder' => $useTeamFolder,
				'mode' => $mode,
			]), $ncGroups, $purgeDesigns);
		} catch (ExistingDesignsException $e) {
			// THE COUNT TRAVELS AS A NUMBER. The panel turns this refusal into a
			// confirmation and re-submits with `purgeDesigns`, so it needs the figure
			// to put in the warning — and parsing it back out of a localised sentence
			// would break the first time the sentence is translated. Caught before
			// the `InvalidArgumentException` arm below, which it extends.
			return new JSONResponse(
				['message' => $e->getMessage(), 'designs' => $e->designs],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (PenpotApiException $e) {
			return new JSONResponse(
				['message' => 'Could not check the team against Penpot: ' . $e->getMessage()],
				Http::STATUS_BAD_GATEWAY,
			);
		}

		return new JSONResponse($this->service->describe($mapping));
	}

	/**
	 * Re-share a mapping's folder with the given groups — the only edit there is.
	 *
	 * Everything else is immutable once created: the team, the Nextcloud folder,
	 * the Team Folder flag and `mode`. That is not enforced here — it is enforced
	 * by {@see MappingService::updateGroups()} taking GROUPS, so no caller can
	 * express a change to anything else. Its docblock gives the reason each field
	 * is locked. Changing one means removing the mapping and adding it again,
	 * which makes the migration cost visible instead of hiding it behind a
	 * dropdown.
	 *
	 * It writes to the FOLDER and stores nothing (§C6.35), so the response carries
	 * the groups the folder reports afterwards — which is not always what was
	 * submitted, since a group that does not exist cannot be shared with.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id, array $ncGroups = []): JSONResponse {
		$existing = $this->service->getById($id);

		if ($existing === null) {
			return new JSONResponse(['message' => 'No such mapping.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->service->updateGroups($id, $ncGroups);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\RuntimeException $e) {
			// The mapping is fine and the request was fine — the folder could not be
			// reached to re-share it. A 422 would send the admin back to the form to
			// change an input that was never the problem.
			return new JSONResponse(
				['message' => 'Could not re-share the mapped folder: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new JSONResponse($this->service->describe($existing));
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
		// THE SAME GUARD THE SECTION'S BUTTON HAS. A card sync and an
		// instance-wide one race over the same folder tree exactly as two
		// instance-wide ones would — the scope does not make it safe.
		if ($this->status->isBusy()) {
			return new JSONResponse(
				['status' => 'already-running'] + $this->status->get(),
				Http::STATUS_CONFLICT,
			);
		}

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
		// THE MAPPING IS READ BEFORE IT IS REMOVED and torn down AFTER, for the
		// reason {@see \OCA\PenpotSync\Command\RemoveMapping} spells out: the
		// teardown needs only the loaded object, and `remove()` is the half that can
		// throw. Both routes tear down, or the admin panel and the CLI would leave
		// the instance in two different states.
		$mapping = $this->service->getById($id);

		if ($mapping === null || !$this->service->remove($id)) {
			return new JSONResponse(['message' => 'No such mapping.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['status' => 'removed'] + $this->teardown->tearDown($mapping));
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
