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

	/**
	 * How long a restored design must stay out of the trash before it is believed.
	 *
	 * Penpot's `delete-file` schedules a delayed removal that lands about 3.8s
	 * later and runs whether or not the design was restored in between — measured
	 * against a live instance and written up in {@see RestoreService}, which pays
	 * the same window for the same reason. Six seconds covers it with margin.
	 *
	 * Only a design that really was in the trash pays this, which in practice means
	 * a file coming back into a mapping shortly after leaving one.
	 */
	private const SETTLE_MICROSECONDS = 6_000_000;

	/** How often to look while waiting out that window. */
	private const SETTLE_POLL_MICROSECONDS = 250_000;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly DestinationResolver $destinations,
		private readonly PersonalTokenService $personalTokens,
		private readonly ProjectTags $tags,
		private readonly SyncGuard $guard,
		private readonly ImportService $imports,
		private readonly ArchiveService $archives,
		private readonly MappingService $mappings,
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
			// A `.penpot` WE DO NOT TRACK, dragged somewhere. If it landed inside a
			// mapping and holds an archive, that is the §6.33 import — the same act
			// as an upload arriving there, because a mapping that ignores a design
			// sitting inside it is not a mapping. This used to return here, and the
			// spec has said otherwise in two files since.
			$landing = $this->resolver->resolve($target);
			$into = $this->destinations->projectForContentIn($target, $landing);

			return $into !== null
				&& $this->imports->adopt($target, $into, $landing->teamId) !== null;
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

		// RESOLVED FROM THE DESTINATION FOLDER, NOT FROM THE FILE — and that
		// distinction is load-bearing now in a way it never was before.
		//
		// {@see MembershipResolver::resolve()} starts its walk AT the node it is
		// given, and a design file carries its own cached `penpot_team_id` (§C6.7,
		// so the browser can build a workspace link without walking the tree). Hand
		// it the file and the file answers about itself: a mirror dragged out to an
		// unmapped folder still reports the team it used to belong to, because the
		// stamp travelled with it.
		//
		// That was harmless while a null project merely meant "do nothing". It is
		// not harmless now: it made a design that had left every mapping resolve to
		// its old team, and therefore to that team's Drafts — so instead of being
		// parked it was quietly re-filed into Drafts, which is a real project inside
		// a mapping it is no longer in. Measured in CI, not reasoned about.
		//
		// A file's membership IS its folder's membership. Asking the parent is both
		// the correct question and the one that cannot be answered by a stale stamp.
		$membership = $this->resolver->resolve($this->destinationFolder($target));

		// THE DESTINATION SIDE ADOPTS; the source side must never (see
		// `sourceProject()` below). A design dragged into a folder Penpot has never
		// seen makes that folder a project.
		$to = $this->destinations->projectForContentIn($target, $membership);
		if ($to === null) {
			// TWO VERY DIFFERENT STATES REACH THIS LINE, and only one of them is a
			// departure:
			//
			//   - NO TEAM — the file left every mapped folder. That is the gesture
			//     park() exists for.
			//   - A TEAM BUT NO PROJECT — the file is still inside a mapping and we
			//     merely could not resolve its Drafts project (an unreadable
			//     listing, a team whose default project we cannot see).
			//
			// Parking the second would soft-delete a design because a LOOKUP failed,
			// on a file that never left the mapping — destructive, and caused by our
			// own blind spot rather than by anything the user did. Better an
			// un-pushed move than a design in the trash on a guess; the next pull
			// reconciles it. The unit suite caught this conflation, which is why the
			// distinction is spelt out here rather than implied by a null check.
			if ($membership->teamId !== null && $membership->teamId !== '') {
				$this->logger->info('penpot_sync writeback: move landed in a team whose project could not be resolved; leaving Penpot untouched', [
					'app' => Application::APP_ID,
					'fileId' => $target->getId(),
					'path' => $target->getPath(),
					'teamId' => $membership->teamId,
				]);

				return false;
			}

			// LEFT EVERY MAPPING. Park the design in Penpot's own trash and let the
			// file keep its id — see park() for why that is not a delete.
			return $this->park($target, $meta);
		}

		$from = $this->sourceProject($source);
		if ($from === $to) {
			// A rename, a plain subfolder, or two folders mapping to one project.
			// The overwhelmingly common case, and it costs zero requests.
			return false;
		}

		// ARRIVING FROM OUTSIDE EVERY MAPPING, where the design may have been parked
		// long enough to be unreachable. Settled before the move, because `move-files`
		// on an id Penpot cannot match is an error, and on a TRASHED id is worse — it
		// succeeds, and files a deleted design into a project nobody can see it in.
		// GATED ON WHERE IT CAME FROM, not on the stamp. `isUnmapped()` is the stamp
		// park() writes, and it is right almost always — but a file that arrived in
		// unmapped space some other way (copied there, uploaded with a stale id) may
		// carry any mode at all, and it is the same arrival with the same question.
		if (($from === null || $meta->isUnmapped()) && !$this->revive($target, $meta, $membership, $to)) {
			// Nothing to reattach to: the id named nothing, so the archive became a
			// new design and the file has already been re-stamped. Done.
			return true;
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
		$stamp = [];
		if ($membership->teamId !== null && $membership->teamId !== $meta->teamId) {
			$stamp[PenpotMetadata::KEY_TEAM_ID] = $membership->teamId;
		}
		if ($meta->isUnmapped()) {
			// IT IS MAPPED AGAIN. The mode is the mapping's, exactly as a design
			// created in that folder would be born — a file left stamped `unmapped`
			// inside a mapping would be skipped by every later gesture that asks
			// `isManaged()` first, and would read as unmapped to the Files sidebar.
			$stamp[PenpotMetadata::KEY_MODE] = $this->modeFor($membership);
		}
		if ($stamp !== []) {
			$this->metadata->writeFile($target->getId(), $stamp);
		}

		return true;
	}

	/**
	 * The folder a moved file now sits in, for the membership walk.
	 *
	 * Falls back to the file itself when the parent cannot be read, which is the
	 * pre-existing behaviour and the safe direction: a file that answers about
	 * itself resolves to the team it is stamped with, so the move is PUSHED rather
	 * than the design parked. Getting a push wrong costs a `move-files` Penpot
	 * treats as a no-op; getting a park wrong soft-deletes somebody's design.
	 */
	private function destinationFolder(File $target): Node {
		try {
			return $target->getParent();
		} catch (NotFoundException) {
			return $target;
		}
	}

	/**
	 * The file left every mapping: park its design in Penpot's trash, and keep the id.
	 *
	 * ## WHY PARKING AND NOT "LEAVE PENPOT ALONE"
	 *
	 * This method replaces a `return false` that logged *"move landed outside any
	 * Penpot project; leaving Penpot untouched"*, on the reasoning that unmapping was
	 * a decision to make explicitly rather than infer from a drag. What that actually
	 * produced was a design sitting in a project whose folder maps nowhere — still
	 * listed, still shared with the team, indistinguishable from live work, and
	 * mirrored by nothing. The absence of a decision IS a decision, and it was the
	 * worst of the three available.
	 *
	 * Both siblings park instead: n8n ARCHIVES the workflow, Grafana moves the
	 * dashboard into its `nextcloud-trash` folder. Penpot needs neither invention
	 * because it HAS a trash, which `designs/delete.feature` already leans on — so
	 * leaving a mapping is the same soft delete the trash gesture makes, and it keeps
	 * the design's **id, revision and history** against the day it comes back.
	 *
	 * ## THE ID STAYS ON THE FILE, AND THAT IS THE WHOLE TRICK
	 *
	 * An unmapped file is not a file that forgot what it was — it is a file holding a
	 * claim on something parked. {@see revive()} is what redeems that claim. Clearing
	 * the id here would make every return an import, minting a new design and
	 * throwing away the history for no reason.
	 *
	 * The TEAM goes, because the file is under no team now and a stale
	 * `penpot_team_id` is a workspace deep link that opens the wrong place (§C6.7).
	 *
	 * @throws PenpotApiException the caller logs it; §6.18 rule 3 — the file stays
	 *                            where the user dropped it either way
	 */
	private function park(File $node, PenpotFileMetadata $meta): bool {
		// THE BYTES FIRST, WHILE THE DESIGN IS STILL REACHABLE. A `sync` mirror
		// already holds its archive and this is a no-op; anything that does not gets
		// one last export, because after the trashing Penpot's own grace window is
		// the only thing keeping it exportable at all.
		if (!$this->archives->holdsArchive($node)) {
			try {
				$this->archives->storeArchive($node, $meta->penpotId);
			} catch (\Throwable $e) {
				// Not fatal: the design is going to Penpot's trash, not out of
				// existence, so a failed snapshot costs a backup rather than the work.
				$this->logger->warning('penpot_sync writeback: could not snapshot a design on its way out of every mapping', [
					'app' => Application::APP_ID,
					'penpotId' => $meta->penpotId,
					'file' => $node->getName(),
					'exception' => $e,
				]);
			}
		}

		$this->client->deleteFile($meta->penpotId, $this->personalTokens->tokenForActor());

		// AFTER the call (§6.18 rule 3): a stamp written first would describe a
		// parking that never happened.
		$this->metadata->writeFile($node->getId(), [
			PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
			PenpotMetadata::KEY_TEAM_ID => '',
		]);

		$this->logger->info('penpot_sync writeback: a design left every mapping; parked it in Penpot\'s trash', [
			'app' => Application::APP_ID,
			'penpotId' => $meta->penpotId,
			'path' => $node->getPath(),
		]);

		return true;
	}

	/**
	 * An unmapped file is arriving in a mapping. Make sure its id names a design.
	 *
	 * ## THREE FAR-SIDE STATES, TWO OUTCOMES
	 *
	 * The file carries an id, and the id is a claim that may or may not still be
	 * good. `designs/restore.feature` names the same three layers for the trash
	 * gesture, and they resolve the same way here:
	 *
	 *   - **live** — somebody restored it in Penpot, or it never went. Nothing to do;
	 *     the caller's `move-files` files it into the destination.
	 *   - **trashed** — {@see park()} put it there, or a person did. Untrash it and
	 *     the id, revision and history all come back with it.
	 *   - **gone** — past Penpot's grace window, or purged, or an id copied onto a
	 *     file that never had a design of its own. Nothing can be revived, so the
	 *     archive is imported as a NEW design and the stale id is replaced.
	 *
	 * @return bool true when the id still names a design and the caller should file
	 *              it; false when the file has been re-stamped with a new one and
	 *              there is nothing left to move
	 */
	private function revive(File $node, PenpotFileMetadata $meta, Membership $membership, string $project): bool {
		$teamId = $membership->teamId ?? '';

		// THE TRASH LISTING IS ASKED FIRST, and the order is not a preference.
		//
		// This began with `fileExists()` and fell through to the untrash — and both
		// rows of the scenario failed identically, with the design still in the trash
		// afterwards. `get-file-summary` answers NOT-FOUND for a soft-deleted design,
		// so a parked design read as "the id names nothing" and was IMPORTED: the
		// user got a new design with a new id, and the one holding all their history
		// stayed in the trash where nobody would look for it.
		//
		// `get-team-deleted-files` is the authority on what is parked, and it is the
		// one this app already trusts everywhere else. Existence is only consulted
		// once the trash has said no.
		if ($teamId !== '' && $this->isParked($teamId, $meta->penpotId)) {
			$this->untrash($teamId, $meta->penpotId);

			return true;
		}

		if ($this->client->fileExists($meta->penpotId) === false) {
			// THE ID NAMES NOTHING, and the trash agrees. Not an error and not a data
			// loss — the bytes have been in Nextcloud the whole time, so they become a
			// design again (§6.33). A failed import leaves the file exactly as it
			// arrived, which is the honest outcome ImportService gives every archive it
			// cannot place.
			$this->imports->adopt($node, $project, $teamId !== '' ? $teamId : null);

			return false;
		}

		// Live all along. Nothing to revive; the caller files it.
		return true;
	}

	/** Is this design sitting in that team's Penpot trash right now? */
	private function isParked(string $teamId, string $penpotId): bool {
		try {
			foreach ($this->client->deletedFiles($teamId) as $file) {
				if (($file['id'] ?? null) === $penpotId) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			// UNREADABLE ANSWERS YES, and that is the cheap direction rather than the
			// timid one. Saying no drops the id into the existence probe, which is the
			// only branch that can mint a SECOND design and leave the original holding
			// the history somewhere nobody will look. Saying yes costs a restore call
			// that is a no-op for a design which was never trashed — Penpot answers
			// with an empty set and {@see untrash()} returns having done nothing.
			$this->logger->warning('penpot_sync writeback: could not read Penpot\'s trash for a returning design; treating it as parked', [
				'app' => Application::APP_ID,
				'penpotId' => $penpotId,
				'exception' => $e,
			]);

			return true;
		}

		return false;
	}

	/**
	 * Bring a parked design back out of Penpot's trash, and make it STAY out.
	 *
	 * ## `delete-file` FIRES A DELAYED JOB, AND IT DOES NOT CARE THAT YOU RESTORED
	 *
	 * The first cut of this restored once and returned, reasoning that the
	 * `move-files` immediately after would fail visibly if the design had gone. It
	 * does not fail — it succeeds, and then the design disappears anyway. CI caught
	 * it on the `trashed` row: the design came back, the move landed, and the design
	 * was in the trash again by the time the scenario looked.
	 *
	 * {@see RestoreService} had already measured exactly this and written it down:
	 * `delete-file` answers immediately, lists the design in the trash within
	 * ~0.1–0.3s, and then **about 3.8 seconds later** runs a delayed job that removes
	 * the file AGAIN, even if it was restored in the meantime. A park followed by a
	 * prompt return sits squarely inside that window.
	 *
	 * So the restore is confirmed rather than assumed, and re-issued once if the
	 * delayed job takes it back — the same remedy §6.49 arrived at, for the same
	 * reason. Only a design that was actually trashed pays the wait.
	 *
	 * BEST EFFORT THROUGHOUT. A design that is already live is the common case and
	 * needs nothing; one that cannot be restored leaves the caller's `move-files` to
	 * fail on its own terms, which is a better error than one invented here.
	 */
	private function untrash(string $teamId, string $penpotId): void {
		try {
			if (!$this->restoreOnce($teamId, $penpotId)) {
				// Never in the trash to begin with — the ordinary "still live" case.
				return;
			}

			if ($this->staysOutOfTheTrash($teamId, $penpotId)) {
				$this->logger->info('penpot_sync writeback: brought a parked design back out of Penpot\'s trash', [
					'app' => Application::APP_ID,
					'penpotId' => $penpotId,
					'team_id' => $teamId,
				]);

				return;
			}

			// The delayed delete took it back. Re-issuing AFTER that job has fired is
			// what makes the restore stick (§6.49) — the second call is not a retry of
			// a failure, it is the first call that lands on the far side of the undo.
			$this->restoreOnce($teamId, $penpotId);
			$this->logger->info('penpot_sync writeback: the parked design needed a second restore (saga §6.49)', [
				'app' => Application::APP_ID,
				'penpotId' => $penpotId,
				'team_id' => $teamId,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync writeback: could not untrash a returning design', [
				'app' => Application::APP_ID,
				'penpotId' => $penpotId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * One restore call. True when Penpot says it actually restored this id.
	 *
	 * `restore-deleted-team-files` answers 200 with an EMPTY set for an id it did
	 * not restore (§C6.11), so the returned ids are the only honest signal — a
	 * status code here would report success for a design still in the trash.
	 */
	private function restoreOnce(string $teamId, string $penpotId): bool {
		$restored = $this->client->restoreDeletedFiles($teamId, [$penpotId], $this->personalTokens->tokenForActor());

		return in_array($penpotId, $restored, true);
	}

	/**
	 * Watch the trash for the delayed delete, rather than sleeping through it.
	 *
	 * Same total worst case as one long wait, but a restore that gets undone is
	 * seen the moment it happens instead of at the end of the window — and once the
	 * delayed job has removed the file it does not put it back, so an early answer
	 * is a final one.
	 */
	private function staysOutOfTheTrash(string $teamId, string $penpotId): bool {
		$deadline = microtime(true) + (self::SETTLE_MICROSECONDS / 1_000_000.0);
		do {
			usleep(self::SETTLE_POLL_MICROSECONDS);

			foreach ($this->client->deletedFiles($teamId) as $file) {
				if (($file['id'] ?? null) === $penpotId) {
					return false;
				}
			}
		} while (microtime(true) < $deadline);

		return true;
	}

	/** The mode a design in this mapping is born in — the mapping's own. */
	private function modeFor(Membership $membership): string {
		$teamId = $membership->teamId ?? '';
		if ($teamId === '') {
			return Mapping::MODE_LINK;
		}

		return $this->mappings->getByTeamId($teamId)?->mode ?? Mapping::MODE_LINK;
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
			// from the same event.
			//
			// KNOWN GAP, AND THE TWO HALVES OF THE EVENT DISAGREE ABOUT IT.
			// `PushService::pushFolderRename()` walks the subtree; this does not. So
			// dragging a plain folder that HOLDS projects across two mapped teams
			// renames those projects to their new path and leaves them in the old
			// team. It is an incomplete improvement rather than a regression —
			// before §C6.38 that gesture did nothing at all, neither half — but the
			// asymmetry is real and it is written down here rather than in a review
			// thread.
			//
			// Not closed in the PR that found it, deliberately: the only cross-team
			// pair the suite can express crosses a STORAGE boundary (a Team Folder),
			// which fires no NodeRenamedEvent at all — measured, see
			// `projects/move.feature`. So the fix cannot be proven by the
			// integration suite today, and it belongs with the capability that
			// unblocks it: noticing a folder that has ARRIVED inside a mapping.
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
