<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves a mapping's writable root folder — the mount point the pull mirrors
 * a Penpot team into.
 *
 * ## THIS INCREMENT SHIPS THE FALLBACK BACKEND ONLY (saga Ch2 Course 3)
 *
 * The course lists two backends for the team folder:
 *
 *   - **Team Folder (`use_team_folder = true`):** an ownerless groupfolders
 *     mount shared with the mapping's groups — the preferred path, and the one
 *     both siblings' `TeamFolderService` builds.
 *   - **Admin-owned (`use_team_folder = false`):** a plain folder in the sync
 *     actor's home. No groupfolders dependency.
 *
 * Only the **admin-owned** path is built here. It is the one the integration
 * suite can prove end-to-end (the CI Nextcloud has no groupfolders app), so it
 * is the honest first slice: the pull becomes real and testable against a live
 * Penpot, and {@see isAvailable()} skips a Team-Folder mapping with a warning
 * rather than half-doing it. The groupfolders backend lands next, ported from
 * the siblings, behind the same two methods so the pull needs no change.
 *
 * ## NO EXPLICIT FS SETUP ON THE PLAIN PATH
 *
 * Unlike the siblings' Team-Folder path (which re-inits the actor FS so a
 * groupfolders mount created earlier in the same request is visible), the
 * admin-owned path just asks {@see IRootFolder::getUserFolder()} for the actor's
 * home, which mounts it. This mirrors `nextcloud-grafana`'s admin-owned branch,
 * which is integration-green without any `OC_Util` dance.
 *
 * ## GROUP SHARING IS DEFERRED
 *
 * The siblings also share the admin-owned folder to the mapping's groups. That
 * is not built here (the CI actor is admin-only, so it is untestable now); the
 * folder is created owned by, and visible to, the sync actor. Group shares land
 * with the groupfolders backend.
 */
final class StorageService {
	/** Built-in group whose first member is the default sync actor. */
	public const ADMIN_GROUP = 'admin';

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * True when the mapping's chosen backend can be provisioned right now.
	 *
	 * Only the admin-owned backend is built in this increment, so a Team-Folder
	 * mapping is reported unavailable — the pull skips it with a warning rather
	 * than creating dead storage.
	 */
	public function isAvailable(Mapping $mapping): bool {
		return !$mapping->useTeamFolder;
	}

	/**
	 * Ensure the mapping's root folder exists and return it, writable by the
	 * sync actor. Idempotent: an existing folder is returned as-is.
	 *
	 * @throws \RuntimeException when the actor is unresolvable, the mapping has no folder name, or the name collides with a non-folder node
	 */
	public function ensureRoot(Mapping $mapping): Folder {
		$name = $this->folderName($mapping);
		$home = $this->rootFolder->getUserFolder($this->resolveActorUid());
		if (!$home->nodeExists($name)) {
			return $home->newFolder($name);
		}
		$node = $home->get($name);
		if (!$node instanceof Folder) {
			throw new \RuntimeException('Penpot mapping folder name is taken by a file: ' . $name);
		}
		return $node;
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
		]);
		return $name;
	}
}
