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
 * ## PERMISSIONS ARE THE ORDINARY FOLDER SURFACE (corrected, §C6.8)
 *
 * Content groups get READ|UPDATE|CREATE|DELETE — what any Nextcloud folder
 * grants, and what both siblings grant. An earlier cut withheld CREATE and
 * DELETE to express "read-only"; see {@see CONTENT_PERMISSIONS} for why that
 * expressed nothing except a broken folder. Unlike the siblings, the grant does
 * not vary with the mapping's `mode`, because penpot's `link`/`sync` is a
 * per-file archive choice, not a folder-wide read-vs-write stance.
 *
 * ## NO EXPLICIT FS SETUP ON THE PLAIN PATH
 *
 * The admin-owned path just asks {@see IRootFolder::getUserFolder()} for the
 * actor's home, which mounts it — no `OC_Util` dance (that is only needed for the
 * groupfolders mount, and lives in {@see TeamFolderService}). Mirrors
 * nextcloud-grafana's admin-owned branch, which is integration-green as-is.
 *
 * ## THE FOLDER OWNS ITS GROUPS (§C6.35)
 *
 * This service is the ONLY place that knows which groups a mapped folder is
 * shared with. {@see Mapping} does not carry them and nothing persists them: the
 * groupfolders assignment table and the share table are already the record, and
 * a copy in appconfig would be a second answer that goes stale the moment an
 * admin re-shares the folder in the Files UI — which they are entitled to do.
 *
 * That makes the group argument to {@see ensureRoot()} meaningfully **optional**,
 * and the distinction is the whole design:
 *
 *   - `ensureRoot($mapping)` — *the folder must exist.* Says nothing about
 *     sharing, so it changes nothing about sharing. This is what every pull
 *     calls, which is why a hand-edited share survives a sync instead of being
 *     reverted by the next pass.
 *   - `ensureRoot($mapping, $groups)` — *the folder must exist and be shared with
 *     exactly these.* Only an explicit admin action (create, or `set-groups` /
 *     the panel's PUT) passes this, and it prunes as well as adds, because
 *     "shared with exactly these" is what the admin asked for.
 *
 * {@see groupsOf()} reads the answer back out of whichever backend holds it.
 */
final class StorageService {
	/** Built-in group whose first member is the default sync actor. */
	public const ADMIN_GROUP = 'admin';

	/**
	 * Content-group rights on a managed Penpot folder: the same surface Nextcloud
	 * gives any ordinary folder, and for the same reason.
	 *
	 * ## THIS USED TO BE READ + UPDATE, AND THAT BROKE THE APP'S OWN DESIGN
	 *
	 * The grant was READ|UPDATE — "read-only for content (§6.1), plus rename" —
	 * with create and delete deferred to §6.33 / Course 5. The deferral read as
	 * conservative. It was not: withholding CREATE removed the **+ New button
	 * entirely** from every mapped folder, so the folders behaved unlike every
	 * other folder in Nextcloud, and it made three built features unreachable:
	 *
	 *   - **§6.29 free nesting**, the most load-bearing rule in the app. The whole
	 *     nearest-ancestor resolver exists so a user can group mirrors into plain
	 *     subfolders of their own. You cannot create a subfolder without CREATE.
	 *   - **Course 4's move writeback.** A cross-folder move needs DELETE on the
	 *     source, so `MotionService` and `move-files` could not be reached by a
	 *     drag at all.
	 *   - **"a mapped folder stays usable as an ordinary folder"** — asserted in
	 *     the prune's own docblock as the reason unstamped files are left alone,
	 *     while nothing could be put there in the first place.
	 *
	 * §6.1 is about CONTENT never flowing back to Penpot, and it still holds
	 * absolutely: it is enforced by the listeners and by there being no content
	 * push, not by making the folder awkward. A permission bit was the wrong
	 * place to express it — it did not stop a single write to Penpot, it only
	 * stopped the user from using their own files.
	 *
	 * Kept identical to {@see TeamFolderService} so both backends grant the same
	 * surface, and identical to both sibling apps.
	 */
	private const CONTENT_PERMISSIONS = Constants::PERMISSION_READ
		| Constants::PERMISSION_UPDATE
		| Constants::PERMISSION_CREATE
		| Constants::PERMISSION_DELETE;

	public function __construct(
		private readonly TeamFolderService $teamFolders,
		private readonly IRootFolder $rootFolder,
		private readonly IShareManager $shareManager,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $config,
		private readonly IDBConnection $db,
		private readonly PenpotMetadata $metadata,
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
	 * sync actor. Idempotent: an existing folder is returned as-is.
	 *
	 * Pass `$groups` ONLY when an admin explicitly said what the folder should be
	 * shared with — it is then applied exactly, pruning groups not listed. Leave
	 * it null (every pull does) and the folder's existing sharing is left alone.
	 * See the class docblock for why that asymmetry is the point.
	 *
	 * @param array<array-key, mixed>|string|null $groups
	 *
	 * @throws \RuntimeException when the backend is unavailable, the actor is unresolvable, the mapping has no folder name, or the name collides with a non-folder node
	 */
	public function ensureRoot(Mapping $mapping, array|string|null $groups = null): Folder {
		$wanted = $groups === null ? null : self::normaliseGroups($groups);

		if ($mapping->useTeamFolder) {
			if (!$this->teamFolders->isAvailable()) {
				throw new \RuntimeException(
					'This mapping uses a Team Folder, but the Team Folders (groupfolders) app is not enabled.',
				);
			}
			$name = $this->folderName($mapping);
			$this->teamFolders->ensure($name, $wanted);

			return $this->marked($this->teamFolders->getWritableFolder($name), $mapping);
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
		if ($wanted !== null) {
			$this->syncGroupShares($folder, $uid, $wanted);
		}

		return $this->marked($folder, $mapping);
	}

	/**
	 * Stamp the root with the team it mirrors, and answer it.
	 *
	 * ## THE MARKER IS PART OF PROVISIONING, NOT PART OF SYNCING
	 *
	 * `penpot_team_id` on the root is what makes the folder MEAN something: it is
	 * the top of the nearest-ancestor walk (§6.29), so until it is written every
	 * node below resolves to no team at all. That is a property of the mapping
	 * existing, not of a sync having happened — and it used to be written only by
	 * {@see PullService::pullOne()}, which made it one.
	 *
	 * The consequences were real and both silent. A push over a mapping nobody had
	 * pulled declined every file and reported "processed, nothing done"; and a
	 * `.penpot` created in a freshly mapped folder was refused outright by
	 * {@see MoveRules::refusalForCreating()}, because an unmarked folder is
	 * indistinguishable from a folder outside every mapping. Both are the first
	 * thing someone does with a new mapping.
	 *
	 * This is the same reasoning that already moved PROVISIONING here from the
	 * first pull ({@see MappingService::add()}): a mapping is a folder that mirrors
	 * a team, and it should be one the moment it is saved rather than the moment a
	 * schedule next fires.
	 *
	 * IDEMPOTENT, like the rest of `ensureRoot()` — the pull still calls this every
	 * run, where it now writes the value that is already there.
	 */
	private function marked(Folder $folder, Mapping $mapping): Folder {
		try {
			$this->metadata->writeFolder(
				$folder->getId(),
				[PenpotMetadata::KEY_TEAM_ID => $mapping->teamId],
			);
		} catch (\Throwable $e) {
			// NEVER FATAL TO PROVISIONING. The folder is the thing the admin asked
			// for and it exists; a marker that would not write is a degraded mapping
			// the next pull repairs, not a reason to fail the save and leave them
			// with nothing.
			$this->logger->warning('penpot_sync: could not mark a mapping root with its team', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);
		}

		return $folder;
	}

	/**
	 * The groups the mapping's folder is currently shared with — read from the
	 * folder, which is the only record there is (§C6.35).
	 *
	 * Answers `[]` for a mapping whose folder does not exist rather than throwing:
	 * every caller is rendering a list, and "no folder" is not more informative to
	 * an admin than "no groups" when the row next to it already shows the folder.
	 *
	 * @return list<string>
	 */
	public function groupsOf(Mapping $mapping): array {
		try {
			if ($mapping->useTeamFolder) {
				return $this->teamFolders->isAvailable()
					? $this->teamFolders->contentGroups($this->folderName($mapping))
					: [];
			}

			$folder = $this->findRoot($mapping);

			return $folder === null ? [] : $this->sharedGroups($folder, $this->resolveActorUid());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not read the mapped folder\'s groups', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Group ids: non-empty trimmed strings, de-duplicated, re-indexed. Tolerates
	 * a comma-separated string from a form field, or the untyped array a request
	 * hands a controller.
	 *
	 * Lives here, not on {@see Mapping}, because this is where groups live now —
	 * and it stays a single definition so `occ`, the panel and the pull cannot
	 * disagree about what a group list is. Identical to both siblings'
	 * normaliser.
	 *
	 * @return list<string>
	 */
	public static function normaliseGroups(mixed $value): array {
		if (is_string($value)) {
			$value = $value === '' ? [] : explode(',', $value);
		}

		if (!is_array($value)) {
			return [];
		}

		$out = [];

		foreach ($value as $g) {
			$g = trim((string)$g);

			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}

		return $out;
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
	 * Share the admin-owned folder with EXACTLY $wanted: create what is missing,
	 * fix permissions on what is there, and delete the shares for groups that are
	 * not.
	 *
	 * ## IT PRUNES NOW, AND THAT IS THE POINT (§C6.35)
	 *
	 * It used to leave a dropped group's share in place — "so a manual share is
	 * never clobbered". That was defensible only while this ran on every pull with
	 * a stored list: pruning would have meant a sync silently revoking access an
	 * admin granted by hand. But it also meant `set-groups` could add and never
	 * remove, so the one editable thing about a mapping was write-only.
	 *
	 * Now that the pull passes no groups at all, this method runs only when
	 * someone explicitly said what the sharing should be — and "shared with these"
	 * has to mean "and not the others", or the mapping's groups could never be
	 * narrowed. A hand-made share is still safe: nothing removes it unless an
	 * admin submits a set that omits it.
	 *
	 * ## A LIST, NOT A MAP KEYED ON THE GROUP ID
	 *
	 * The existing shares used to be indexed by group id, which PHP quietly turns
	 * into an INT for a numeric group name — and `in_array($gid, $wanted, true)`
	 * then compares `123` against `'123'` and says no. A group called "2024" would
	 * have been pruned on every save and re-created immediately after. Keeping the
	 * shares as a list and asking each one for its own id removes the coercion
	 * entirely, and takes a redundant cast with it.
	 *
	 * @param list<string> $wanted
	 */
	private function syncGroupShares(Folder $folder, string $ownerUid, array $wanted): void {
		$existing = [];
		foreach ($this->shareManager->getSharesBy($ownerUid, IShare::TYPE_GROUP, $folder, false, -1, 0) as $share) {
			$existing[] = $share;
		}

		foreach ($existing as $share) {
			$gid = $share->getSharedWith();
			if (in_array($gid, $wanted, true)) {
				continue;
			}
			try {
				$this->shareManager->deleteShare($share);
			} catch (\Throwable $e) {
				$this->logger->warning('penpot_sync: failed to unshare from group', [
					'app' => Application::APP_ID,
					'group' => $gid,
					'exception' => $e,
				]);
				$this->clearPoisonedTransaction();
			}
		}

		foreach ($wanted as $gid) {
			if ($gid === '') {
				continue;
			}

			$share = null;
			foreach ($existing as $candidate) {
				if ($candidate->getSharedWith() === $gid) {
					$share = $candidate;
					break;
				}
			}

			if ($share !== null) {
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
				// Re-run on an existing share too: a mapping made before this
				// method accepted anything left every member pending, and the
				// folder invisible to all of them until someone edited the groups.
				$this->acceptForMembers($folder, $gid);
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
				$this->acceptForMembers($folder, $gid);
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
	 * Make a group share VISIBLE to the group, which creating it does not do.
	 *
	 * ## WHY THIS EXISTS AT ALL
	 *
	 * `DefaultShareProvider::create()` writes `accepted = STATUS_PENDING` for
	 * every share it makes, unconditionally — there is no auto-accept flag to set
	 * and no argument to `createShare()` that changes it. `Files_Sharing`'s mount
	 * provider then builds the super-share for that pending group share and
	 * declines to mount it. So the folder a mapping provisions is correct, shared,
	 * and carries the right permissions — and is invisible to every member of the
	 * group, with nothing to click, because a group share raises no acceptance
	 * prompt the way a user share does.
	 *
	 * MEASURED ON A LIVE INSTANCE, not reasoned about: the mount provider returned
	 * the super-share at status 0 and produced no mount; accepting the four pending
	 * shares and asking it again produced the mount, with nothing else changed.
	 *
	 * ## A REJECTION IS LEFT ALONE
	 *
	 * Someone who removed the folder from their own Files view holds a share at
	 * STATUS_REJECTED, and re-accepting that on their behalf would put it back
	 * every time an admin so much as re-saved the mapping's groups. Only PENDING is
	 * accepted — the state in which nobody has expressed an opinion yet.
	 */
	private function acceptForMembers(Folder $folder, string $gid): void {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return;
		}

		foreach ($group->getUsers() as $user) {
			$uid = $user->getUID();
			foreach ($this->shareManager->getSharedWith($uid, IShare::TYPE_GROUP, $folder, -1) as $share) {
				if ($share->getStatus() !== IShare::STATUS_PENDING) {
					continue;
				}
				try {
					$this->shareManager->acceptShare($share, $uid);
				} catch (\Throwable $e) {
					// NEVER FATAL TO PROVISIONING. The share itself exists and is
					// correct; the member can still accept it by hand. Losing the
					// whole mapped folder over one unacceptable share would be the
					// worse trade by a distance.
					$this->logger->warning('penpot_sync: failed to accept a group share for a member', [
						'app' => Application::APP_ID,
						'group' => $gid,
						'user' => $uid,
						'exception' => $e,
					]);
					$this->clearPoisonedTransaction();
				}
			}
		}
	}

	/**
	 * The groups an admin-owned folder is currently shared with.
	 *
	 * @return list<string>
	 */
	private function sharedGroups(Folder $folder, string $ownerUid): array {
		$out = [];
		foreach ($this->shareManager->getSharesBy($ownerUid, IShare::TYPE_GROUP, $folder, false, -1, 0) as $share) {
			$gid = $share->getSharedWith();
			if ($gid !== '' && !in_array($gid, $out, true)) {
				$out[] = $gid;
			}
		}

		return $out;
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
