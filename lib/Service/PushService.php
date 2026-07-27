<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The writeback (Nextcloud → Penpot) — the confirmed write paths, saga Ch2
 * Course 4. Where the siblings' `PushService` pushes *content*, this one never
 * does: §6.1 is the app's spine — Nextcloud mirrors, it does not edit shape data.
 *
 * The only writes Penpot permits us (§6.19) are **renames**, and this slice
 * carries the two of them:
 *
 *   - a `.penpot` **file** rename → `rename-file` on its `penpot_id` (§6.54);
 *   - a **project folder** rename → `rename-project` on its `penpot_project_id`
 *     (§6.36/§6.39 — its own RPC, not a variant of file rename).
 *
 * ## ATTRIBUTION (saga §6.18)
 *
 * A rename attributes to the acting user's personal token when they have set one
 * ({@see PersonalTokenService}), and to the service account otherwise. An expired
 * or absent personal token is the ordinary case, not an error — {@see actorToken()}
 * returns null and Penpot records the service account.
 *
 * ## ON FAILURE, THE LOCAL STATE STANDS (saga §6.18 rule 3)
 *
 * The NC rename has already happened by the time this runs (it reacts to the
 * completed {@see \OCP\Files\Events\Node\NodeRenamedEvent}); it cannot be undone
 * here. So a Penpot failure is thrown for the listener to log/surface, and the
 * mirror keeps the new local name until the next pull reconciles it — never a
 * lost edit, never a half-applied one.
 *
 * ## WHY THIS DOES NOT LOOP
 *
 * The pull raises {@see SyncGuard} around its own follow-renames, so the listener
 * that calls this never fires for a pull-driven move. A user rename pushed here
 * renames the Penpot object; the next pull sees the matching name and does
 * nothing. One hop each way, no echo.
 */
final class PushService {
	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly PersonalTokenService $personalTokens,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Propagate a completed NC rename of a managed Penpot node up to Penpot.
	 *
	 * @return bool true when a rename was pushed; false when $node is not a
	 *              managed Penpot object this app should propagate (an ordinary
	 *              file, an unmanaged `.penpot`, the team root, a non-project folder)
	 *
	 * @throws PenpotApiException when Penpot rejects or cannot be reached — the
	 *                            caller logs it; the local name stands until the next pull
	 */
	public function pushRename(Node $node): bool {
		if ($node instanceof File) {
			return $this->pushFileRename($node);
		}
		if ($node instanceof Folder) {
			return $this->pushFolderRename($node);
		}
		return false;
	}

	private function pushFileRename(File $node): bool {
		$name = $node->getName();
		if (!str_ends_with($name, PullService::EXTENSION)) {
			// Not a mirror file — nothing of ours renamed.
			return false;
		}
		$meta = $this->metadata->readFile($node->getId());
		if ($meta === null || !$meta->isManaged()) {
			// A `.penpot` file we do not track (hand-made, or a move-in). Creating
			// it in Penpot is the §6.33 carve-out, a later course — not a rename.
			return false;
		}

		// Penpot's own name never carries the `.penpot` affordance (§6.4), so send
		// the bare stem. An empty stem (a file literally named ".penpot") is not a
		// legal Penpot name; skip rather than throw a validation error upstream.
		$base = substr($name, 0, -strlen(PullService::EXTENSION));
		if (trim($base) === '') {
			$this->logger->debug('penpot_sync writeback: skipping rename to an empty Penpot name', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
			]);
			return false;
		}

		$this->client->renameFile($meta->penpotId, $base, $this->actorToken());
		$this->logger->info('penpot_sync writeback: renamed Penpot file', [
			'app' => Application::APP_ID,
			'penpotId' => $meta->penpotId,
			'name' => $base,
		]);
		return true;
	}

	private function pushFolderRename(Folder $node): bool {
		$markers = $this->metadata->readFolder($node->getId());
		if (!$markers->hasProject()) {
			// The team root carries only a team id, and a plain folder none — only
			// a project folder renames a Penpot object. Teams are not renamed from
			// here (the mapping owns the root's name).
			return false;
		}

		$this->client->renameProject($markers->projectId, $node->getName(), $this->actorToken());
		$this->logger->info('penpot_sync writeback: renamed Penpot project', [
			'app' => Application::APP_ID,
			'projectId' => $markers->projectId,
			'name' => $node->getName(),
		]);
		return true;
	}

	/**
	 * The token a write attributes to: the acting user's personal token if set,
	 * else null (the service account). Never throws — attribution is best-effort.
	 */
	private function actorToken(): ?string {
		$uid = $this->userSession->getUser()?->getUID();
		return $uid !== null ? $this->personalTokens->tokenFor($uid) : null;
	}
}
