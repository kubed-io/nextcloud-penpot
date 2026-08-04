<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * The team-mapping panel — the one part of the admin surface that is not a
 * declarative card, because declarative settings have no array-of-objects field
 * type. So this is a server-rendered {@see IDelegatedSettings} panel: PHP builds
 * the initial table, and a small vanilla-JS file does add/remove through
 * {@see \OCA\PenpotSync\Controller\MappingController}. Same split, for the same
 * reason, as both sibling apps.
 *
 * Implementing `IDelegatedSettings` is what lets the controller gate its
 * endpoints with `#[AuthorizedAdminSetting(settings: MappingSettings::class)]`.
 *
 * ## THE PANEL RENDERS THE CONNECTION STATE, NOT JUST THE LIST
 *
 * Mapping is impossible without a working service-account token, and the reason
 * is never obvious from an empty dropdown. So {@see getForm()} fetches the
 * visible teams up front and passes down *why* the list is empty when it is —
 * unreachable, unauthorised, or authenticated-but-invited-to-nothing. Those need
 * three different fixes (saga §6.18), and an admin staring at "no teams" cannot
 * tell them apart.
 *
 * A Penpot failure here must NEVER blank the panel: the mappings themselves are
 * stored locally and stay editable — in particular removable — while Penpot is
 * down. So the exception is caught, turned into a message, and rendered
 * alongside the existing list rather than replacing it.
 */
final class MappingSettings implements IDelegatedSettings {
	public function __construct(
		private MappingService $service,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'mapping-settings');
		Util::addStyle(Application::APP_ID, 'mapping-settings');

		$teams = [];
		$error = null;

		try {
			foreach ($this->service->visibleTeams() as $id => $team) {
				$teams[] = [
					// Deliberately '' rather than a placeholder string: this value
					// is rendered into the admin UI, and a fallback invented here
					// could never be localised. The template applies its own
					// translated fallback instead.
					'id' => $id,
					'name' => is_string($team['name'] ?? null) ? $team['name'] : '',
					'mapped' => $this->service->getByTeamId($id) !== null,
				];
			}
		} catch (PenpotApiException $e) {
			// Rendered as a notice above the (still fully functional) list.
			$error = $e->getMessage();
		}

		usort($teams, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

		// Every group id, for the per-mapping group picker. search('') returns
		// them all — fine at homelab scale; paginate if it ever gets large. Same
		// approach, and same caveat, as both sibling apps.
		$groups = array_map(
			static fn ($g): string => $g->getGID(),
			$this->groupManager->search(''),
		);
		sort($groups);

		return new TemplateResponse(
			Application::APP_ID,
			'mapping_settings',
			[
				// describe(), not toArray(): each card's Groups picker is checked
				// against what the FOLDER is shared with, read as this page renders
				// (§C6.35). So a share added in the Files UI shows up here.
				'mappings' => array_map(
					fn (Mapping $m): array => $this->service->describe($m),
					$this->service->list(),
				),
				'teams' => $teams,
				'groups' => $groups,
				// Drives the Team Folder checkbox's availability, exactly as in the
				// siblings: without groupfolders the mapping falls back to a plain
				// shared folder, and the checkbox should say so rather than offer a
				// backend that is not installed.
				'team_folders_available' => $this->appManager->isEnabledForUser('groupfolders'),
				'error' => $error,
			],
			'blank',
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 30 — below Instance (5) and Sync Settings (20), above Sync Actions
	 * (45). The same slot Folder mappings occupies in both sibling apps.
	 */
	#[\Override]
	public function getPriority(): int {
		return 30;
	}

	#[\Override]
	public function getName(): ?string {
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Mappings are edited through the dedicated controller, which carries its
		// own #[AuthorizedAdminSetting] — not through the generic appconfig write
		// endpoint. Exposing the raw key here would let the generic endpoint
		// bypass every invariant MappingService enforces.
		return [];
	}
}
