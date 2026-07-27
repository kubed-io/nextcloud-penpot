<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Resolves a mapping's writable root folder — the mount point the pull mirrors
 * a Penpot team into — routing to the per-mapping storage backend, exactly as
 * both siblings' `StorageService` does (saga §14.1, saga Ch2 Course 3):
 *
 *   - **Team Folder (`use_team_folder = true`):** delegate to
 *     {@see TeamFolderService} — an ownerless groupfolders mount shared with the
 *     mapping's groups. The preferred path when the groupfolders app is present.
 *   - **Admin-owned (`use_team_folder = false`):** a plain folder in the sync
 *     actor's (admin's) home, shared to the mapping's groups via
 *     `OCP\Share\IManager` group shares. No groupfolders dependency. The owner is
 *     always the actor and is never switched (no migration).
 *
 * Both paths write files carrying the same Penpot metadata {@see PullService}
 * stamps, and neither ever creates a group — the content groups are
 * admin-managed. The pull calls {@see ensureRoot()} / {@see findRoot()} and never
 * learns which backend answered.
 *
 * ## PERMISSIONS ARE READ + RENAME, NOT FULL WRITE (penpot-specific)
 *
 * The mirror is read-only for *content* (§6.1) but a *rename* propagates to
 * Penpot (§6.2, Course 4), so the content groups get read + UPDATE and nothing
 * more — create and delete wait for §6.33 / Course 5. Unlike the siblings, the
 * grant does not vary with the mapping's `mode`, because penpot's `link`/`sync`
 * is a per-file archive choice, not a folder-wide read-vs-write stance.
 *
 * ## NO EXPLICIT FS SETUP ON THE PLAIN PATH
 *
 * The admin-owned path just asks {@see IRootFolder::getUserFolder()} for the
 * actor's home, which mounts it — no `OC_Util` dance (that is only needed for the
 * groupfolders mount, and lives in {@see TeamFolderService}). Mirrors
 * nextcloud-grafana's admin-owned branch, which is integration-green as-is.
 */
final class StorageService {
	/** Built-in group whose first member is the default sync actor. */
	public const ADMIN_GROUP = 'admin';

	/**
	 * Content-group rights on a managed Penpot folder: read + UPDATE, never
	 * create or delete. Kept identical to {@see TeamFolderService} so both
	 * backends grant the same surface.
	 *
	 * NB: Nextcloud has no "rename-only" permission bit — `PERMISSION_UPDATE` is
	 * the least that lets a group rename a node, and it also allows editing an
	 * existing file's contents. We accept that over-grant: a content edit is not
	 * a rename, so it is never pushed to Penpot ({@see PushService}) and is
	 * overwritten on the next pull, keeping content effectively one-way.
	 */
	private const CONTENT_PERMISSIONS = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE;

	public function __construct(
		private readonly TeamFolderService $teamFolders,
		private readonly IRootFolder $rootFolder,
		private readonly IShareManager $shareManager,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $config,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * True when the mapping's chosen backend can be provisioned right now.
	 *
	 * A Team-Folder mapping needs the groupfolders app enabled; an admin-owned
	 * mapping is always available. A Team-Folder mapping on an instance without
	 * groupfolders is reported unavailable, and the pull skips it with a warning
	 * rather than creating dead storage.
	 */
	public function isAvailable(Mapping $mapping): bool {
		return $mapping->useTeamFolder ? $this->teamFolders->isAvailable() : true;
	}

	/**
	 * Ensure the mapping's root folder exists and return it, writable by the
	 * sync actor. Idempotent: an existing folder is returned as-is, and its group
	 * shares / permissions are re-asserted.
	 *
	 * @throws \RuntimeException when the backend is unavailable, the actor is unresolvable, the mapping has no folder name, or the name collides with a non-folder node
	 */
	public function ensureRoot(Mapping $mapping): Folder {
		if ($mapping->useTeamFolder) {
			if (!$this->teamFolders->isAvailable()) {
				throw new \RuntimeException(
					'This mapping uses a Team Folder, but the Team Folders (groupfolders) app is not enabled.',
				);
			}
			$name = $this->folderName($mapping);
			$this->teamFolders->ensure($name, $mapping->ncGroups);
			return $this->teamFolders->getWritableFolder($name);
		}

		// Admin-owned backend.
		$name = $this->folderName($mapping);
		$uid = $this->resolveActorUid();
		$home = $this->rootFolder->getUserFolder($uid);
		if (!$home->nodeExists($name)) {
			$folder = $home->newFolder($name);
		} else {
			$node = $home->get($name);
			if (!$node instanceof Folder) {
				throw new \RuntimeException('Penpot mapping folder name is taken by a file: ' . $name);
			}
			$folder = $node;
		}
		$this->syncGroupShares($folder, $uid, $mapping);
		return $folder;
	}

	/**
	 * Return the mapping's existing root folder, or null when it does not exist.
	 * Never creates anything — used by read-only callers (status, a future
	 * purge).
	 */
	public function findRoot(Mapping $mapping): ?Folder {
		$name = trim($mapping->ncFolder);
		if ($name === '') {
			return null;
		}
		if ($mapping->useTeamFolder) {
			try {
				return $this->teamFolders->getWritableFolder($name);
			} catch (\Throwable) {
				return null;
			}
		}
		$home = $this->rootFolder->getUserFolder($this->resolveActorUid());
		if (!$home->nodeExists($name)) {
			return null;
		}
		$node = $home->get($name);
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * The uid the pull writes as. Must be a local user (LDAP does not resolve in
	 * a bare CLI/job context). Default: the first member of the built-in `admin`
	 * group; override with AppConfig `sync_actor` if ever needed. Same rule and
	 * key as the siblings, so an operator configures all three identically.
	 */
	public function resolveActorUid(): string {
		$configured = $this->config->getValueString(Application::APP_ID, 'sync_actor', '');
		if ($configured !== '') {
			return $configured;
		}
		$admin = $this->groupManager->get(self::ADMIN_GROUP);
		if ($admin !== null) {
			foreach ($admin->getUsers() as $user) {
				return $user->getUID();
			}
		}
		throw new \RuntimeException('No sync actor available: the built-in admin group has no members.');
	}

	/**
	 * Ensure the admin-owned folder is shared with each of the mapping's groups
	 * at the read + rename level. Idempotent: creates missing group shares, fixes
	 * permissions on existing ones. Does NOT remove shares to groups no longer
	 * listed (a removed group's share is left for the admin to clean up, so a
	 * manual share is never clobbered) — same conservative choice as the siblings.
	 */
	private function syncGroupShares(Folder $folder, string $ownerUid, Mapping $mapping): void {
		$existing = [];
		foreach ($this->shareManager->getSharesBy($ownerUid, IShare::TYPE_GROUP, $folder, false, -1, 0) as $share) {
			$existing[$share->getSharedWith()] = $share;
		}

		foreach ($mapping->ncGroups as $gid) {
			if ($gid === '') {
				continue;
			}
			if (isset($existing[$gid])) {
				$share = $existing[$gid];
				if ($share->getPermissions() !== self::CONTENT_PERMISSIONS) {
					$share->setPermissions(self::CONTENT_PERMISSIONS);
					try {
						$this->shareManager->updateShare($share);
					} catch (\Throwable $e) {
						$this->logger->warning('penpot_sync: failed to update group share', [
							'app' => Application::APP_ID,
							'group' => $gid,
							'exception' => $e,
						]);
						$this->clearPoisonedTransaction();
					}
				}
				continue;
			}
			try {
				$share = $this->shareManager->newShare();
				$share->setNode($folder);
				$share->setShareType(IShare::TYPE_GROUP);
				$share->setSharedWith($gid);
				$share->setSharedBy($ownerUid);
				$share->setPermissions(self::CONTENT_PERMISSIONS);
				$this->shareManager->createShare($share);
			} catch (\Throwable $e) {
				// Most likely the group does not exist (admin-managed / LDAP). Log
				// and carry on — a missing content group must not fail the pull.
				$this->logger->warning('penpot_sync: failed to share with group (does it exist?)', [
					'app' => Application::APP_ID,
					'group' => $gid,
					'exception' => $e,
				]);
				$this->clearPoisonedTransaction();
			}
		}
	}

	/**
	 * Drop a Postgres transaction left dangling by a failed share write.
	 *
	 * On this instance `IShareManager::createShare()` can throw *after* the
	 * share row commits — the notifications app's post-commit push crashes
	 * (`OCA\Notifications\Push::$appConfig` is null) inside its own
	 * notification transaction, leaving the shared DB connection in Postgres'
	 * aborted-transaction state (`SQLSTATE[25P02]`). We deliberately swallow the
	 * share error (a missing/awkward content group must not fail the pull), so
	 * we must also discard the poisoned transaction here — otherwise every later
	 * query on this connection, including the *next* mapping's file writes,
	 * fails until end of transaction. Best-effort and self-contained: no caller
	 * of {@see syncGroupShares} opens a transaction we would be clobbering.
	 */
	private function clearPoisonedTransaction(): void {
		try {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not clear a dangling transaction after a share failure', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	/** The single-segment folder name for a mapping, guarded non-empty. */
	private function folderName(Mapping $mapping): string {
		$name = trim($mapping->ncFolder);
		if ($name === '') {
			// Mapping::fromArray defaults nc_folder to the team name, so this is
			// only reachable for a malformed stored mapping — fail loud rather
			// than mirror a whole team into the actor's home root.
			throw new \RuntimeException('Penpot mapping has no folder name: ' . $mapping->id);
		}
		$this->logger->debug('penpot_sync storage root', [
			'app' => Application::APP_ID,
			'folder' => $name,
			'mapping' => $mapping->id,
			'backend' => $mapping->useTeamFolder ? 'team_folder' : 'admin_owned',
		]);
		return $name;
	}
}
