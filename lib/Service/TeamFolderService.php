<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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
 *  - **The content groups get the ordinary folder surface.** Read, update, create
 *    and delete — what Nextcloud grants any folder. An earlier cut withheld
 *    create and delete to express "read-only"; see
 *    {@see StorageService::CONTENT_PERMISSIONS} for why that expressed nothing
 *    except a broken folder (§C6.8). §6.1 still holds absolutely, enforced by
 *    there being no content push. Penpot's `link`/`sync` mode is a per-file
 *    archive choice, not a folder-permission stance, so — unlike the siblings —
 *    the permission does not vary by mode.
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
	 * Content-group rights on a managed Penpot folder: the ordinary folder
	 * surface. Kept identical to {@see StorageService::CONTENT_PERMISSIONS}, whose
	 * docblock carries the reasoning, so both backends grant the same thing.
	 *
	 * Deliberately NOT `PERMISSION_ALL`: the missing bit is SHARE, and its absence
	 * is what {@see contentGroups()} reads to tell a chosen group from the actor's
	 * plumbing assignment. Widening this to ALL would erase that distinction.
	 */
	private const CONTENT_PERMISSIONS = Constants::PERMISSION_READ
		| Constants::PERMISSION_UPDATE
		| Constants::PERMISSION_CREATE
		| Constants::PERMISSION_DELETE;

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
	 * Ensure a Team Folder named $mountPoint exists and is writable by the actor
	 * (via the `admin` group). Returns the groupfolders folder id.
	 *
	 * Idempotent. When $contentGroups is null the folder's existing assignments are
	 * left exactly as they are, INCLUDING the actor's — which is only added when it
	 * is absent, never re-stamped, because on a folder shared with the `admin`
	 * group re-stamping would change a chosen group's bits into plumbing. When a
	 * list IS given it is applied literally: assignments not in it are removed. See
	 * {@see StorageService}'s docblock for which caller does which, and why.
	 *
	 * @param list<string>|null $contentGroups user-facing groups (admin-managed), or null to leave sharing alone
	 */
	public function ensure(string $mountPoint, ?array $contentGroups): int {
		$fm = $this->container->get(self::FOLDER_MANAGER);

		$folderId = $this->findByMountPoint($mountPoint);
		if ($folderId === null) {
			$folderId = $fm->createFolder($mountPoint);
		}

		// LEAVE SHARING ALONE MEANS LEAVE THE ADMIN ASSIGNMENT ALONE TOO.
		//
		// This branch used to fall through and stamp `admin` with PERMISSION_ALL
		// unconditionally. On a folder deliberately shared WITH the admin group —
		// where `admin` is a content group at CONTENT_PERMISSIONS — the next pull
		// would overwrite those bits, and contentGroups() would then read the
		// result as plumbing and stop reporting it. A sync would have silently
		// dropped a group the admin chose, which is precisely the thing §C6.35
		// exists to prevent, in the one code path that runs most often.
		//
		// So when no groups were asked for, the actor's access is only ADDED if it
		// is missing entirely — never re-stamped. A folder that already grants the
		// actor anything is a folder this method has nothing to do.
		if ($contentGroups === null) {
			if (!$this->groupIsApplied($folderId, self::ADMIN_GROUP)) {
				$this->assignGroup($fm, $folderId, self::ADMIN_GROUP, Constants::PERMISSION_ALL);
			}

			return $folderId;
		}

		foreach ($contentGroups as $gid) {
			if ($gid === '') {
				continue;
			}
			$this->assignGroup($fm, $folderId, $gid, self::CONTENT_PERMISSIONS);
		}

		// THE ACTOR'S ACCESS, AND HOW IT IS TOLD APART FROM A REAL SHARE.
		//
		// groupfolders has no per-user applicable, so the only way this app can
		// write into the mount is to assign a group the actor belongs to — `admin`,
		// always present, never created by us. That assignment is PLUMBING: nobody
		// asked for it, and reporting it back from contentGroups() would tell an
		// admin their folder is shared with a group they never chose.
		//
		// The PERMISSION BITMASK is what distinguishes the two, which is why this
		// runs after the loop above and does not overwrite it. Plumbing gets
		// PERMISSION_ALL; a deliberate `admin` content group keeps
		// CONTENT_PERMISSIONS from the loop, which is already enough to write with,
		// so nothing is lost by not upgrading it. That leaves "share this with the
		// admin group" expressible on a Team Folder — it would not be if we always
		// stamped ALL over it.
		if (!in_array(self::ADMIN_GROUP, $contentGroups, true)) {
			$this->assignGroup($fm, $folderId, self::ADMIN_GROUP, Constants::PERMISSION_ALL);
		}

		// Prune assignments the admin did not ask for (keeping the actor's).
		$keep = array_merge([self::ADMIN_GROUP], $contentGroups);
		foreach (array_keys($this->appliedGroups($folderId)) as $gid) {
			if (!in_array($gid, $keep, true)) {
				$fm->removeApplicableGroup($folderId, $gid);
			}
		}

		return $folderId;
	}

	/**
	 * The groups a managed Team Folder is shared with, as an admin would mean it —
	 * the applied assignments, minus the actor's plumbing one.
	 *
	 * Empty for a mount point that has no Team Folder: this is a read, and a
	 * missing folder has no groups.
	 *
	 * @return list<string>
	 */
	public function contentGroups(string $mountPoint): array {
		$folderId = $this->findByMountPoint($mountPoint);
		if ($folderId === null) {
			return [];
		}

		$out = [];
		foreach ($this->appliedGroups($folderId) as $gid => $permissions) {
			// See ensure(): `admin` at PERMISSION_ALL is how this app grants itself
			// write access, not something anyone chose. At any other permission it
			// got there as a content group, so it is reported like one.
			if ($gid === self::ADMIN_GROUP && $permissions === Constants::PERMISSION_ALL) {
				continue;
			}
			$out[] = $gid;
		}

		return $out;
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
	 * Group ids currently applied to the folder, mapped to their permission
	 * bitmask (excludes Circles, which store an empty group_id).
	 *
	 * The permission comes back because it is load-bearing, not for display:
	 * {@see contentGroups()} uses it to tell the actor's plumbing assignment from
	 * a group somebody actually chose.
	 *
	 * @return array<string, int>
	 */
	private function appliedGroups(int $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('group_id', 'permissions')
			->from('group_folders_groups')
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->neq('group_id', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$out = [];
		foreach ($res->fetchAll() as $row) {
			$out[(string)$row['group_id']] = (int)$row['permissions'];
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
