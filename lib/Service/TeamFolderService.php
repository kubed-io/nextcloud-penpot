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
use Psr\Container\ContainerInterface;

/**
 * All Team Folder (groupfolders) interaction lives here, so the rest of the app
 * never touches groupfolders internals directly. Ported from the sibling
 * integrations' `TeamFolderService` (nextcloud-n8n / nextcloud-grafana, saga
 * §14.1) — the "optional dependency" precedent this app inherits wholesale
 * (saga Ch2 Course 3, "Team Folder provisioning + fallback").
 *
 * ## THE THREE RULES THIS ENCODES, UNCHANGED FROM THE SIBLINGS
 *
 *  - **No owner, and we never create groups.** Team Folders are ownerless; the
 *    content groups are whatever the admin already manages (often LDAP-mapped),
 *    so creating groups is out of scope. To *write* server-side we must act as a
 *    member of an assigned group, so we lean on the built-in `admin` group
 *    (always present, contains the sync actor, never created by us) and assign
 *    it to each managed folder with full rights. groupfolders 21.x has no
 *    per-user applicable, so a group is required; `admin` is the safe one.
 *  - **groupfolders has no stable PHP API**, but `FolderManager` is its public
 *    service and the cleanest surface; resolved lazily so a disabled app does
 *    not break DI. Name→id lookup hits the `group_folders` table directly, which
 *    is stable.
 *  - **The content groups get read + rename, not full write.** This is the one
 *    penpot-specific deviation from the siblings, and it is deliberate: the
 *    Nextcloud mirror is read-only for *content* (§6.1) but a *rename* flows back
 *    to Penpot (§6.2, Course 4), so members may read and rename and nothing more.
 *    Create and delete are withheld until the courses that define them (§6.33
 *    create, Course 5 delete). Penpot's `link`/`sync` mode is a per-file archive
 *    choice, not a folder-permission stance, so — unlike the siblings — the
 *    permission does not vary by mode.
 *
 * Side effect (documented, acceptable, same as the siblings): because the folder
 * is shared to the `admin` group, admins see managed Team Folders in their own
 * Drive. Fine for homelab / single-admin; revisit if per-user applicable lands
 * upstream.
 */
final class TeamFolderService {
	/** Built-in group used to grant the write actor access. We never create it. */
	public const ADMIN_GROUP = 'admin';

	/** FQCN resolved lazily so a disabled groupfolders app does not break DI. */
	private const FOLDER_MANAGER = 'OCA\\GroupFolders\\Folder\\FolderManager';

	/**
	 * Content-group rights on a managed Penpot folder: read + UPDATE, never
	 * create or delete. See the class docblock's third rule.
	 *
	 * NB: Nextcloud has no "rename-only" permission bit — `PERMISSION_UPDATE` is
	 * the least that lets a group rename a node, and it also allows editing an
	 * existing file's contents. We accept that over-grant: a content edit is not
	 * a rename, so it is never pushed to Penpot ({@see \OCA\PenpotSync\Service\PushService})
	 * and is overwritten on the next pull, keeping content effectively one-way.
	 */
	private const CONTENT_PERMISSIONS = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE;

	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IDBConnection $db,
		private readonly IGroupManager $groupManager,
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $config,
	) {
	}

	public function isAvailable(): bool {
		return $this->container->has(self::FOLDER_MANAGER);
	}

	/**
	 * Ensure a Team Folder named $mountPoint exists, is shared with the content
	 * groups at the right permission level, and is writable by the actor (via the
	 * `admin` group). Returns the groupfolders folder id.
	 *
	 * Idempotent: re-running re-asserts assignments + permissions and prunes
	 * content groups no longer desired.
	 *
	 * @param list<string> $contentGroups user-facing groups (admin-managed; ≥1 expected)
	 */
	public function ensure(string $mountPoint, array $contentGroups): int {
		$fm = $this->container->get(self::FOLDER_MANAGER);

		$folderId = $this->findByMountPoint($mountPoint);
		if ($folderId === null) {
			$folderId = $fm->createFolder($mountPoint);
		}

		foreach ($contentGroups as $gid) {
			if ($gid === self::ADMIN_GROUP || $gid === '') {
				continue;
			}
			$this->assignGroup($fm, $folderId, $gid, self::CONTENT_PERMISSIONS);
		}

		// Actor access last, with full rights so it overrides if `admin` was also
		// listed as a content group.
		$this->assignGroup($fm, $folderId, self::ADMIN_GROUP, Constants::PERMISSION_ALL);

		// Prune content groups dropped from the mapping (keep admin + desired).
		$keep = array_merge([self::ADMIN_GROUP], $contentGroups);
		foreach ($this->appliedGroups($folderId) as $gid) {
			if (!in_array($gid, $keep, true)) {
				$fm->removeApplicableGroup($folderId, $gid);
			}
		}

		return $folderId;
	}

	/**
	 * The writable {@see Folder} node for a managed Team Folder, via the actor's
	 * Files view (the only context the mount exists in). Re-inits the actor FS so
	 * a folder/assignment created earlier in this same request is picked up.
	 */
	public function getWritableFolder(string $mountPoint): Folder {
		$actor = $this->resolveActorUid();
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($actor);
		$userFolder = $this->rootFolder->getUserFolder($actor);
		if (!$userFolder->nodeExists($mountPoint)) {
			throw new \RuntimeException(
				"Team Folder '$mountPoint' is not mounted for actor '$actor'. "
				. 'Check the actor is in the "' . self::ADMIN_GROUP . '" group.',
			);
		}
		$node = $userFolder->get($mountPoint);
		if (!$node instanceof Folder) {
			throw new \RuntimeException("'$mountPoint' exists but is not a folder for actor '$actor'.");
		}
		return $node;
	}

	/**
	 * uid we act as when writing. Must be a local user (LDAP does not resolve in
	 * bare CLI/job context). Default: first member of the built-in `admin` group;
	 * override with AppConfig `sync_actor` if ever needed. Same key and rule as
	 * {@see StorageService::resolveActorUid()} and both siblings, so an operator
	 * configures every app identically.
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

	/** Assign $groupId (idempotent) and set its permission bitmask. */
	private function assignGroup(object $fm, int $folderId, string $groupId, int $permissions): void {
		if (!$this->groupIsApplied($folderId, $groupId)) {
			$fm->addApplicableGroup($folderId, $groupId);
		}
		$fm->setGroupPermissions($folderId, $groupId, $permissions);
	}

	/**
	 * Group ids currently applied to the folder (excludes Circles, which store an
	 * empty group_id). Used to prune content groups dropped from a mapping.
	 *
	 * @return list<string>
	 */
	private function appliedGroups(int $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('group_id')
			->from('group_folders_groups')
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->neq('group_id', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$out = [];
		foreach ($res->fetchAll() as $row) {
			$out[] = (string)$row['group_id'];
		}
		$res->closeCursor();
		return $out;
	}

	private function groupIsApplied(int $folderId, string $groupId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_id')
			->from('group_folders_groups')
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$found = $res->fetchOne() !== false;
		$res->closeCursor();
		return $found;
	}

	private function findByMountPoint(string $mountPoint): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_id')
			->from('group_folders')
			->where($qb->expr()->eq('mount_point', $qb->createNamedParameter($mountPoint)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$id = $res->fetchOne();
		$res->closeCursor();
		return $id === false ? null : (int)$id;
	}
}
