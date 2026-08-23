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
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The move half of Course 4 — the drag, propagated (saga Ch2, `move.feature`).
 * Driven by {@see \OCA\PenpotSync\Listener\NodeRenamedListener} on the *completed*
 * `NodeRenamedEvent`; the *before* gate that can refuse a move is
 * {@see \OCA\PenpotSync\Listener\MoveGuardListener}.
 *
 * Both siblings have a `MotionService` and this is cut from theirs, but ours does
 * far less, because §6.1 removes the hard part: a move here never deletes, never
 * creates, and never touches content. There are exactly two calls it can make —
 * `move-files` for a design and `move-project` for a project folder — and both
 * are non-destructive and reversible by dragging back.
 *
 * ## A MOVE IS CLASSIFIED BY WHERE THE FILE LANDS (saga §6.29)
 *
 * A file's project is **the nearest ancestor folder carrying a project id**, so a
 * move — up, down, sideways, into a plain folder, into a deeply nested one —
 * resolves exactly one way: {@see MembershipResolver} on the destination. There
 * is no "too deep" case, no orphan state, no rule about levels. Comparing that
 * against the same resolution of the *source's* parent gives the whole decision:
 *
 *   - **same project** (a rename, a plain subfolder, two folders mapping to one
 *     project) → **Penpot is never contacted**. This is the common case and it
 *     costs zero requests.
 *   - **a different project** → one `move-files`. §6.27/§6.34 proved this works
 *     across teams too, with `team-id` following automatically.
 *   - **into a team root or a plain folder under it** (a team, no project) →
 *     that is Penpot's **Drafts** (§6.35 — Drafts is a *state*, not a folder), so
 *     the file moves into that team's default project. Dragging it back into a
 *     project folder files it again.
 *   - **out of every mapped folder** → **nothing is pushed**. Penpot keeps the
 *     file exactly where it is. Unmapping, deleting and re-homing are Course 5's
 *     subject, and guessing at them from a move is precisely the destructive
 *     inference this app refuses to make.
 *
 * ## A PROJECT FOLDER MOVES TOO (§C6.38)
 *
 * This class used to say *project folders do not move in Penpot*, and refuse to
 * look at one. Two things changed under §C6.38 and each takes a branch of
 * {@see onFolderMove()}:
 *
 *   - a project's NAME is its path below the mapping, so a drag renames it. That
 *     half is {@see PushService}'s, pushed from the same listener event.
 *   - a project's TEAM is the mapping it sits under, and `move-project` carries a
 *     project across one in a single call. So a drag between two mapped folders
 *     is one request, and nothing is re-created to cross the boundary.
 *
 * And a project folder dragged OUT of every mapping is not a desync to be
 * refused — it is an unmapping, the same thing a single design leaving already
 * does. **Penpot is not contacted at all**: the project stands, its designs
 * stand, and the folder stops being the thing that mirrors it. What that costs is
 * the marker, which is why {@see unmap()} strips it rather than leaving a
 * `penpot_project_id` sitting in unmapped space where the resolver would still
 * read it (§6.29 walks UP, and does not care how it got there).
 *
 * ## ONLY `sync` FILES GET HERE (saga §6.43, locked)
 *
 * A `link` file is a pointer with no content, and §6.43 confines it to its own
 * project — every project-changing move of a link is refused by
 * {@see \OCA\PenpotSync\Listener\MoveGuardListener} before it happens. What can
 * still reach this service is a link moved *within* its project, which needs no
 * push; {@see onMove()} returns on it explicitly rather than relying on the
 * comparison to come out equal.
 *
 * This service was written before `sync` mode existed, and was dormant until it
 * did: the classification below is the part that has to be right, and it was far
 * easier to get right against the resolver alone than retrofitted alongside an
 * archive download. A mapping made in `sync` mode is what wakes it up.
 *
 * ## SCOPE — SAME-STORAGE MOVES ONLY (inherited from both siblings)
 *
 * Nextcloud fires `NodeRenamedEvent` for a move *within one storage*. A move that
 * crosses a storage boundary — notably into or out of a **Team Folder**, a
 * groupfolders mount — is a copy+delete underneath and fires
 * `NodeDeletedEvent` + a create on the far side, never `NodeRenamedEvent`, so it
 * never reaches this service. That path belongs to Course 5's delete/create
 * lifecycle. The consequence is benign here: an unseen move is a move we did not
 * push, and the next pull reconciles it.
 *
 * ## ON FAILURE, THE LOCAL STATE STANDS (saga §6.18 rule 3)
 *
 * The Nextcloud move has already committed by the time this runs. A Penpot
 * failure is thrown for the listener to log; the file stays where the user put it
 * and the next pull reconciles Penpot. Nothing is ever reverted under the user.
 * The push attributes to the acting user exactly as a rename does
 * ({@see PersonalTokenService::tokenForActor()}).
 */
final class MotionService {
	/**
	 * A ceiling on the descent when unmapping a tree, mirroring the seatbelts in
	 * {@see MembershipResolver} and {@see ProjectFolderService}. A Nextcloud tree
	 * is finite and the walk terminates naturally.
	 */
	private const MAX_DEPTH = 100;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly DestinationResolver $destinations,
		private readonly PersonalTokenService $personalTokens,
		private readonly ProjectTags $tags,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile Penpot to a completed move of $target, which used to live under
	 * $source's parent.
	 *
	 * @return bool true when the move changed something — a `move-files`, a
	 *              `move-project`, or an unmapping; false when it was none of
	 *              Penpot's business (a plain file or folder, an unmanaged
	 *              `.penpot`, or a move that changed neither project nor team)
	 *
	 * @throws PenpotApiException when Penpot rejects or cannot be reached — the
	 *                            caller logs it; the file stays put and the next pull reconciles
	 */
	public function onMove(Node $source, Node $target): bool {
		if ($target instanceof Folder) {
			return $this->onFolderMove($source, $target);
		}
		if (!$target instanceof File) {
			return false;
		}
		if (!str_ends_with($target->getName(), PullService::EXTENSION)) {
			return false;
		}

		$meta = $this->metadata->readFile($target->getId());
		if ($meta === null || !$meta->isManaged()) {
			// A `.penpot` we do not track. Creating it in Penpot on the way in is
			// the §6.33 carve-out, a later course — never a side effect of a drag.
			return false;
		}
		if ($meta->isLink()) {
			// A `link` file is confined to its project (§6.43), so MoveGuardListener
			// has already refused every move that could change one. What is left is
			// a move WITHIN the project — which by definition needs no `move-files`.
			//
			// Returning here rather than falling through to the project comparison
			// is not just an optimisation, though it is one (two resolver walks and
			// possibly a `get-all-projects` for a Drafts lookup, per drag). It makes
			// the guard's rule true in this class too: if the guard is ever relaxed,
			// this is the line that has to be deliberately removed, instead of the
			// push quietly starting to fire on files that hold no bytes.
			return false;
		}

		// ONE resolver walk, used twice: the project decides whether to push, and
		// the team decides whether the file's cached `penpot_team_id` still tells
		// the truth (§C6.7).
		$membership = $this->resolver->resolve($target);

		$to = $this->destinations->projectFor($membership);
		if ($to === null) {
			// Landed outside every mapped folder, or in a team whose Drafts we
			// could not resolve. Penpot keeps the file where it is: unmapping is
			// Course 5's decision to make explicitly, not one to infer from a drag.
			$this->logger->info('penpot_sync writeback: move landed outside any Penpot project; leaving Penpot untouched', [
				'app' => Application::APP_ID,
				'fileId' => $target->getId(),
				'path' => $target->getPath(),
			]);
			return false;
		}

		$from = $this->sourceProject($source);
		if ($from === $to) {
			// A rename, a plain subfolder, or two folders mapping to one project.
			// The overwhelmingly common case, and it costs zero requests.
			return false;
		}

		$this->client->moveFiles($to, [$meta->penpotId], $this->personalTokens->tokenForActor());
		$this->logger->info('penpot_sync writeback: moved Penpot file to another project', [
			'app' => Application::APP_ID,
			'penpotId' => $meta->penpotId,
			'fromProject' => $from,
			'toProject' => $to,
		]);

		// RE-STAMP THE TEAM, but only now — after Penpot accepted the move.
		//
		// With two teams mapped to two folders, dragging a mirror from one tree to
		// the other really does change which Penpot team owns the design, and the
		// `penpot_team_id` cached on the file would otherwise keep naming the old
		// one until the next pull. That stamp is what the workspace deep link is
		// built from (§C6.7), so a stale one is a link that opens the wrong team's
		// workspace — the exact failure this key was added to fix.
		//
		// The resolver is the authority for which team a node now sits under; the
		// stamp is only a copy the browser can reach without walking the tree. So
		// the resolver writes it, here and in the pull, and nowhere else.
		//
		// AFTER the call, never before: a `moveFiles` that throws leaves the design
		// in its old team, and a stamp written first would describe a move that did
		// not happen (§6.18 rule 3).
		if ($membership->teamId !== null && $membership->teamId !== $meta->teamId) {
			$this->metadata->writeFile($target->getId(), [PenpotMetadata::KEY_TEAM_ID => $membership->teamId]);
		}

		return true;
	}

	/**
	 * A project folder that moved: it crossed a team, or it left every mapping.
	 *
	 * THE COMPARISON IS TEAM TO TEAM, not "is there a team now". A project folder
	 * whose old parent resolved to no team either was never mirrored or had
	 * already left, and in both cases a move within unmapped space changes
	 * nothing — reading the destination alone would unmap it a second time, and
	 * would unmap a personal project (§6.31: a project id with no team above it is
	 * a valid state, not a broken one) the first time its owner tidied their home.
	 */
	private function onFolderMove(Node $source, Folder $target): bool {
		$markers = $this->metadata->readFolder($target->getId());
		if (!$markers->hasProject()) {
			// A plain folder. Every PROJECT below it was named through it and has
			// just been renamed — but that is a rename, and PushService pushes it
			// from the same event. Nothing here.
			return false;
		}
		$projectId = $markers->projectId;

		$from = $this->sourceTeam($source);
		$to = $this->resolver->resolve($target)->teamId;
		if ($from === $to) {
			// Dragged within its own team folder: the position means nothing to
			// Penpot and the name has already been pushed. Zero requests.
			return false;
		}

		if ($to === null) {
			$this->unmap($target, 0);
			$this->logger->info('penpot_sync writeback: a project folder left every mapping; Penpot untouched', [
				'app' => Application::APP_ID,
				'projectId' => $projectId,
				'path' => $target->getPath(),
			]);

			return true;
		}

		$this->client->moveProject($projectId, $to, $this->personalTokens->tokenForActor());
		$this->logger->info('penpot_sync writeback: moved Penpot project to another team', [
			'app' => Application::APP_ID,
			'projectId' => $projectId,
			'fromTeam' => $from,
			'toTeam' => $to,
		]);

		return true;
	}

	/**
	 * Stop a folder tree from being a mirror: strip the markers, take the badge off.
	 *
	 * RECURSIVE, because the resolver is. Leaving a `penpot_project_id` on a
	 * folder that now sits outside every mapping does not make it inert — §6.29
	 * walks UP from whatever lands in it and would find that id, reporting a
	 * project with no team above it. So a nested project has to be unmapped too,
	 * or dropping a design into the unmapped tree would file it into a Penpot
	 * project the folder no longer represents.
	 *
	 * THE DESCENT DOES NOT STOP AT UNMARKED FOLDERS, and the first cut of this
	 * did. A project three levels down under two ordinary folders — `Let Go/notes/
	 * archive/Deep` — is reached by walking UP exactly like any other, so it has to
	 * be reached walking DOWN too. Descending only through marked folders left
	 * precisely the ids this method exists to remove, in precisely the tree shape
	 * §6.29 is designed to allow. {@see ProjectFolderService::managedDesignsBelow()}
	 * descends the same way for the same reason — the one difference being that it
	 * STOPS at a marked folder (those designs belong to that project) while this
	 * one carries on (that project is leaving too).
	 *
	 * The `penpot` tag goes with the marker. It is the opt-in badge
	 * ({@see ProjectFolderService}), and a folder wearing it while carrying no
	 * project id is the one place the badge would mean nothing. Inside the guard,
	 * so the `TagUnassignedEvent` is unmistakably the app's own motion.
	 *
	 * Nothing here contacts Penpot, and nothing here is undone by a drag back:
	 * moving the folder in again finds no marker and adopts nothing, which is
	 * `projects/create.feature`'s subject rather than this one's.
	 */
	private function unmap(Folder $folder, int $depth): void {
		if ($depth >= self::MAX_DEPTH) {
			return;
		}

		if ($this->metadata->readFolder($folder->getId())->hasProject()) {
			$this->metadata->clear($folder->getId());
			$this->guard->run(fn () => $this->tags->remove($folder->getId()));
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync writeback: could not list an unmapped folder; a nested project may keep its marker', [
				'app' => Application::APP_ID,
				'path' => $folder->getPath(),
				'exception' => $e,
			]);

			return;
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				$this->unmap($child, $depth + 1);
			}
		}
	}

	/**
	 * The team the node used to be under, resolved through its *old parent* for
	 * the same reason {@see sourceProject()} is.
	 */
	private function sourceTeam(Node $source): ?string {
		try {
			$parent = $source->getParent();
		} catch (NotFoundException) {
			return null;
		}

		return $this->resolver->resolve($parent)->teamId;
	}

	/**
	 * Where the node came from, resolved through the *old parent folder* — never
	 * through the node itself, which no longer exists at that path.
	 *
	 * A null here is not an error: it only ever means "we could not tell", and the
	 * caller treats a null source as *different* from any resolved destination, so
	 * the move is pushed. Pushing a `move-files` that Penpot would treat as a
	 * no-op is harmless (§6.34: non-destructive, id and history intact); failing
	 * to push a real re-file is not.
	 */
	private function sourceProject(Node $source): ?string {
		try {
			$parent = $source->getParent();
		} catch (NotFoundException) {
			return null;
		}

		return $this->destinations->projectFor($this->resolver->resolve($parent));
	}
}
