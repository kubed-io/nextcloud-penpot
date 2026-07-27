<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes a single user's personal Penpot token.
 *
 * The card ({@see PersonalSettings}) already stores the value through core; this
 * exists for the paths core does not serve — the `occ` twin, and Course 4's
 * write actions asking "whose token should attribute this?".
 *
 * ## THE ONE RULE THIS CLASS ENCODES (saga §6.18)
 *
 * A personal token **attributes writes**. It never reads on the app's behalf and
 * it never widens what the app can see. {@see tokenFor()} is therefore the write
 * path's helper only — the pull must never call it. Letting a personal token
 * widen the mirror would reintroduce exactly the dual-pull-path complexity §6.16
 * rejected, and the shared-Team-Folder data race with it.
 *
 * ## FALLBACK IS THE POINT, NOT AN ERROR PATH
 *
 * Penpot tokens expire (never / 30 / 60 / 90 / 180 days) with no auto-rotation,
 * so an expired personal token is a routine event, not an exceptional one. Every
 * caller is expected to fall back to the service account and carry on — the
 * user's rename must still happen, just attributed less precisely. That is why
 * this returns `null` rather than throwing: an exception here would invite
 * callers to fail the user's action over an attribution detail.
 */
class PersonalTokenService {
	public function __construct(
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * That user's personal token, decrypted — or null if they have not set one.
	 *
	 * Null is the ordinary case, not a failure: most users never set a token,
	 * and everything works without one.
	 */
	public function tokenFor(string $userId): ?string {
		$stored = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			PersonalSettings::KEY_PERSONAL_TOKEN,
			'',
		);

		if ($stored === '') {
			return null;
		}

		try {
			$token = $this->crypto->decrypt($stored);
		} catch (\Throwable $e) {
			// A token encrypted under a rotated instance secret. Log it and treat
			// the user as having none — the caller then attributes to the service
			// account, which is exactly the documented degraded behaviour.
			$this->logger->warning('Could not decrypt the personal Penpot token for {user}', [
				'user' => $userId,
				'exception' => $e,
				'app' => Application::APP_ID,
			]);

			return null;
		}

		return $token !== '' ? $token : null;
	}

	public function hasToken(string $userId): bool {
		return $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			PersonalSettings::KEY_PERSONAL_TOKEN,
			'',
		) !== '';
	}

	/**
	 * Store a user's personal token, encrypted — the `occ` twin's write path.
	 *
	 * Matches how core's `DeclarativeManager` persists the same field, so the CLI
	 * and the settings card remain interchangeable.
	 */
	public function setToken(string $userId, string $token): void {
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			PersonalSettings::KEY_PERSONAL_TOKEN,
			$this->crypto->encrypt($token),
		);
	}

	/**
	 * Remove a user's personal token.
	 *
	 * Breaks nothing: their mapped folders keep pulling as the service account,
	 * and future writes are simply attributed to it instead of to them.
	 */
	public function clearToken(string $userId): void {
		$this->config->deleteUserValue(
			$userId,
			Application::APP_ID,
			PersonalSettings::KEY_PERSONAL_TOKEN,
		);
	}
}
