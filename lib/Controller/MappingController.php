<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Controller;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ConnectionTester;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Settings\MappingSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The configured mappings.
	 */
	#[NoCSRFRequired]
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
		string $mode = Mapping::MODE_LINK,
		string $folderMode = Mapping::FOLDER_MODE_NESTED,
	): JSONResponse {
		try {
			$mapping = $this->service->add(Mapping::fromArray([
				'team_id' => $teamId,
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
	 * Update a mapping's mutable fields. `folder_mode` and the team are not among
	 * them — {@see MappingService::update()} explains why.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id, string $mode): JSONResponse {
		$existing = $this->service->getById($id);

		if ($existing === null) {
			return new JSONResponse(['message' => 'No such mapping.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$updated = $this->service->update($id, new Mapping(
				$existing->id,
				$existing->teamId,
				$existing->teamName,
				$mode,
				$existing->folderMode,
			));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($updated->toArray());
	}

	/**
	 * Remove a mapping. Deletes nothing, in Penpot or in Nextcloud.
	 */
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
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function testConnection(): JSONResponse {
		return new JSONResponse($this->tester->test()->jsonSerialize());
	}
}
