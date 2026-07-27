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
 * creates, and never touches content. There is exactly one call it can make —
 * `move-files` — and it is non-destructive and reversible by dragging back.
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
 * PROJECT FOLDERS DO NOT MOVE IN PENPOT. Nextcloud is authoritative for folder
 * layout (§6.29), so a project folder may be dragged anywhere **inside** its team
 * folder for free — Penpot has no concept of the position. Dragging it *out* of
 * its team is the one hard rule (§6.30) and is refused before it happens by
 * {@see \OCA\PenpotSync\Listener\MoveGuardListener}, not undone after.
 *
 * ## ONLY `sync` FILES GET HERE (saga §6.43, locked)
 *
 * A `link` file is a pointer with no content, and §6.43 confines it to its own
 * project — every project-changing move of a link is refused by the guard, so it
 * never reaches this service. Which means: today, when `sync` mode has not landed
 * yet and every mirrored file is a link, this service is built, tested and
 * deliberately dormant. It is written now rather than later because the
 * classification above is the part that has to be right, and it is far easier to
 * get right against the resolver than retrofitted alongside an archive download.
 * No mode check is duplicated here — one gate, in one place, is the point.
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
	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly PersonalTokenService $personalTokens,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile Penpot to a completed move of $target, which used to live under
	 * $source's parent.
	 *
	 * @return bool true when a `move-files` was pushed; false when the move was
	 *              none of Penpot's business (a plain file, an unmanaged
	 *              `.penpot`, a folder, or a move that changed no project)
	 *
	 * @throws PenpotApiException when Penpot rejects or cannot be reached — the
	 *                            caller logs it; the file stays put and the next pull reconciles
	 */
	public function onMove(Node $source, Node $target): bool {
		if (!$target instanceof File) {
			// Folder layout is Nextcloud's to decide (§6.29); a project folder has
			// no position in Penpot to update. Moving one out of its team is the
			// one refusal, and MoveGuardListener has already made it.
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

		$to = $this->destinationProject($target);
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
		return true;
	}

	/**
	 * The Penpot project the node now belongs to, or null when it belongs to none.
	 *
	 * A resolved project id is used as-is. A team with no project above the node
	 * is Penpot's Drafts (§6.35), which IS a real project — the team's default
	 * one — so it is looked up rather than treated as "no project".
	 */
	private function destinationProject(Node $node): ?string {
		$membership = $this->resolver->resolve($node);
		if ($membership->projectId !== null) {
			return $membership->projectId;
		}
		if ($membership->teamId === null) {
			return null;
		}

		return $this->draftsProject($membership->teamId);
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

		return $this->destinationProject($parent);
	}

	/**
	 * A team's Drafts project — the one flagged `is-default` (§6.35, same lookup
	 * the pull uses to decide a project's files belong at the team root).
	 *
	 * Returns null when the token cannot see it, which the caller treats as "no
	 * destination": better an un-pushed move than a file re-filed into a guess.
	 *
	 * @throws PenpotApiException
	 */
	private function draftsProject(string $teamId): ?string {
		foreach ($this->client->getAllProjects() as $project) {
			$sameTeam = ($project['team-id'] ?? null) === $teamId;
			$isDefault = filter_var($project['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$id = $project['id'] ?? null;
			if ($sameTeam && $isDefault && is_string($id) && $id !== '') {
				return $id;
			}
		}

		$this->logger->warning('penpot_sync writeback: no default (Drafts) project visible for team', [
			'app' => Application::APP_ID,
			'teamId' => $teamId,
		]);

		return null;
	}
}
