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
 *     (§6.36/§6.39 — its own RPC, not a variant of file rename), and one more for
 *     every project NESTED below it, because a project's name is its path
 *     (§C6.38 — see {@see pushFolderRename()}).
 *
 * ## ATTRIBUTION (saga §6.18)
 *
 * A rename attributes to the acting user's personal token when they have set one
 * ({@see PersonalTokenService::tokenForActor()}), and to the service account
 * otherwise. An expired or absent personal token is the ordinary case, not an
 * error — the helper returns null and Penpot records the service account.
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
	/**
	 * A ceiling on the descent, mirroring {@see MembershipResolver}'s upward
	 * seatbelt. A Nextcloud tree is finite; this only guards a pathological shape.
	 */
	private const MAX_DEPTH = Walk::MAX_DEPTH;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly PersonalTokenService $personalTokens,
		private readonly MembershipResolver $resolver,
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

		$this->client->renameFile($meta->penpotId, $base, $this->personalTokens->tokenForActor());
		$this->logger->info('penpot_sync writeback: renamed Penpot file', [
			'app' => Application::APP_ID,
			'penpotId' => $meta->penpotId,
			'name' => $base,
		]);
		return true;
	}

	/**
	 * A folder moved or was renamed — so every project named THROUGH it renamed too.
	 *
	 * ## WHY THIS IS A SUBTREE AND NOT ONE FOLDER (§C6.38)
	 *
	 * A project's Penpot name is its path below the mapping, and Penpot has no
	 * parent field — there is no atomic re-parent to send. So dragging
	 * `Penpot/foo` into `Penpot/Clients` renames `foo/bar` to `Clients/foo/bar`,
	 * and `foo/bar/baz` to `Clients/foo/bar/baz`, and everything else spelled
	 * through it: one `rename-project` each.
	 *
	 * That is the cost of the path model, and it is stated here because this is
	 * where someone meets it. It is survivable precisely because every project
	 * keeps its ID: a run that fails halfway leaves projects correctly identified
	 * and wrongly named, which the next pull reconciles. It would not be
	 * survivable if a rename re-created anything.
	 *
	 * NOTE `foo` ITSELF NEED NOT BE A PROJECT. It very often is not — a plain
	 * folder someone groups their projects under has no Penpot counterpart at all
	 * (§6.29), and renaming it still renames everything below. Which is why the
	 * walk starts unconditionally and the "is this one a project" test is per
	 * folder, rather than an early return on the node the user actually touched.
	 */
	private function pushFolderRename(Folder $node): bool {
		if ($this->metadata->readFolder($node->getId())->hasTeam()) {
			// THE MAPPING ROOT, and renaming it renames nothing. A project's name
			// is its path BELOW the root, so moving or renaming the root itself
			// leaves every one of those paths exactly as it was — walking the tree
			// would send a `rename-project` per project, each to the name it
			// already has. The mapping owns the root's name in any case
			// (`mapping/view.feature`), not a Files gesture.
			return false;
		}

		if ($this->resolver->resolve($node)->teamId === null) {
			// Outside every mapping. Nothing below has a path below a mapping to be
			// named by, so there is nothing to rename and no subtree worth walking
			// — which is also what keeps an ordinary rename anywhere else in the
			// instance from costing a directory listing.
			//
			// A project folder that has just been dragged OUT of a mapping lands
			// here too, and that is correct: it stops being a mirror rather than
			// being renamed. MotionService strips its markers on the same event.
			return false;
		}

		return $this->renameProjectsIn($node, 0);
	}

	/** @return bool true when at least one project below (or at) $folder was renamed */
	private function renameProjectsIn(Folder $folder, int $depth): bool {
		if ($depth >= self::MAX_DEPTH) {
			return false;
		}

		$pushed = false;

		$markers = $this->metadata->readFolder($folder->getId());
		if ($markers->hasProject()) {
			// A MOVE IS A RENAME, WHICH IS WHY THIS IS A PATH. Dragging
			// `Penpot/Traveller` into `Penpot/Clients` does not change the folder's
			// own name at all — only where it sits — and Penpot has to hear about
			// that as `Clients/Traveller`. The bare name could not express it, so a
			// move renamed the project to what it was already called.
			$name = $this->resolver->pathBelowMapping($folder);
			if ($name !== null && trim($name) !== '') {
				$this->client->renameProject($markers->projectId, trim($name), $this->personalTokens->tokenForActor());
				$this->logger->info('penpot_sync writeback: renamed Penpot project', [
					'app' => Application::APP_ID,
					'projectId' => $markers->projectId,
					'name' => $name,
				]);
				$pushed = true;
			}
		}

		foreach ($this->subfolders($folder) as $child) {
			// `||` the other way round would short-circuit the recursion the moment
			// one child pushed, and silently skip its siblings.
			$pushed = $this->renameProjectsIn($child, $depth + 1) || $pushed;
		}

		return $pushed;
	}

	/**
	 * The folders directly inside $folder.
	 *
	 * A listing that throws is logged and treated as empty: the rename the user
	 * asked for has already happened locally, and failing the whole push over one
	 * unreadable subfolder would lose the renames that DID work above it.
	 *
	 * @return list<Folder>
	 */
	private function subfolders(Folder $folder): array {
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync writeback: could not list a folder while renaming the projects below it', [
				'app' => Application::APP_ID,
				'path' => $folder->getPath(),
				'exception' => $e,
			]);

			return [];
		}

		$folders = [];
		foreach ($children as $child) {
			if ($child instanceof Folder) {
				$folders[] = $child;
			}
		}

		return $folders;
	}
}
