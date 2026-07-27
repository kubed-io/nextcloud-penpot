<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Settings;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCP\AppFramework\Http\TemplateResponse;
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

		return new TemplateResponse(
			Application::APP_ID,
			'mapping_settings',
			[
				'mappings' => array_map(
					static fn (Mapping $m): array => $m->toArray(),
					$this->service->list(),
				),
				'teams' => $teams,
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
	 * Below Service account (10) and above Scheduled pull (30): configure the
	 * connection, then choose what to mirror, then say how often.
	 */
	#[\Override]
	public function getPriority(): int {
		return 20;
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
