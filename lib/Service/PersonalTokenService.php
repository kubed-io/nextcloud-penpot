<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Settings\PersonalSettings;
use OCP\IConfig;
use OCP\IUserSession;
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
final class PersonalTokenService {
	public function __construct(
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The token the write happening *right now* should attribute to: the acting
	 * user's personal token if they have one, else null (the service account).
	 *
	 * ONE ANSWER, ONE PLACE. Every write path asks this same question, and if each
	 * answered it for itself they would drift — one honouring a personal token, the
	 * next quietly not. Never throws: attribution is best-effort by design (see the
	 * class docblock), and there is no session at all on the cron path.
	 */
	public function tokenForActor(): ?string {
		$uid = $this->userSession->getUser()?->getUID();

		return $uid !== null ? $this->tokenFor($uid) : null;
	}

	/**
	 * Who is performing the current gesture, or '' when nobody is.
	 *
	 * SAME SESSION, DIFFERENT QUESTION — and it lives here rather than in each
	 * caller for the same reason {@see tokenForActor()} does: there is exactly one
	 * notion of "the acting user" in this app, and two classes answering it
	 * separately is how they drift.
	 *
	 * An empty string is an ordinary answer. The scheduled pull runs with no
	 * session at all, and {@see SyncNotifier} treats '' as "nobody to tell" rather
	 * than inventing a recipient.
	 */
	public function actingUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
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
