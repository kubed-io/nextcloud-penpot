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
 * ## A CROSS-STORAGE MOVE REACHES HERE — AND ARRIVES WITH NO IDENTITY
 *
 * This section used to say the opposite, inherited from both siblings: that a
 * move into or out of a **Team Folder** is a copy+delete underneath, fires
 * `NodeDeletedEvent` + a create rather than `NodeRenamedEvent`, and so never
 * reaches this service at all — benign, because an unseen move is one we did not
 * push and the next pull reconciles it.
 *
 * Every clause of that is wrong for a FILE, and the consequence is the opposite
 * of benign. The event arrives. The file id is even PRESERVED. What Nextcloud
 * destroys is the METADATA: removing the source cache entries raises
 * `CacheEntriesRemovedEvent` and core's own `MetadataDelete` listener drops the
 * `files_metadata` rows — measured live, with a same-storage rename as the
 * control. So the design lands here looking like a stranger, and the §6.33 branch
 * imports it as a BRAND NEW DESIGN: the user asked for a move and got a duplicate
 * with a new id and no history.
 *
 * {@see recoverAcrossStorages()} is the answer, and {@see MoveMemory} is where the
 * identity waits. A cross-team move is exactly this gesture — the two teams a
 * mapping can reach never share a storage — which is why `designs/move.feature`'s
 * cross-team scenario stood `@blocked` until the mechanism was named rather than
 * re-read.
 *
 * A cross-storage move of a FOLDER is still unhandled, and still for the original
 * reason: neither half of core routes it (saga Ch3, "the fourth scenario").
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
		private readonly MoveMemory $memory,
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

		$meta = $this->metadata->readFile($target->getId()) ?? $this->recoverAcrossStorages($target);
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
		// TWO FILES MAY NOT CLAIM ONE DESIGN — the "keep both versions" answer.
		//
		// The Files app resolves a name collision by moving the arrival in under a
		// free name, so both files end up in the mapping carrying the same
		// `penpot_id`. Left alone that is the "two files, one design, forever" state
		// the pull's own indexes are written to avoid: whichever the prune reaches
		// first wins, the other mirrors a design it does not own, and no later pass
		// ever separates them.
		//
		// The ARRIVAL is the one that gives way, always. The file already sitting in
		// the mapping is what every other node has been resolving against, so
		// re-identifying that one would move the problem rather than fix it.
		//
		// Checked before the departure branch below, because a duplicate arriving
		// from unmapped space is this case and not that one: the answer is the same
		// import either way, but the reason to log is different.
		if ($this->idIsSpokenFor($target, $meta->penpotId)) {
			$this->logger->info('penpot_sync writeback: a design of that id is already in this mapping; importing the arrival as its own', [
				'app' => Application::APP_ID,
				'penpotId' => $meta->penpotId,
				'path' => $target->getPath(),
			]);
			if ($this->imports->adopt($target, $to, $membership->teamId) === null) {
				// THE IMPORT FAILED, AND THE ID MUST STILL GO. `adopt()` answers null
				// when Penpot refused the archive or there was none to send — and the
				// file is then still carrying the id of a design ANOTHER file in this
				// mapping is mirroring, which is precisely the state this branch exists
				// to prevent. Leaving it is worse than a file with no design: two
				// mirrors of one design is what nothing downstream can separate.
				//
				// So it becomes an ordinary untracked `.penpot` — the same shape any
				// file the app never adopted has, and one a later gesture can still
				// import. Raised by Copilot on #52.
				$this->metadata->clear($target->getId());
				$this->logger->warning('penpot_sync writeback: could not import a duplicate arrival; cleared its stale id', [
					'app' => Application::APP_ID,
					'penpotId' => $meta->penpotId,
					'path' => $target->getPath(),
				]);
			}

			return true;
		}

		// ARRIVING FROM OUTSIDE EVERY MAPPING IS AN IMPORT, WHATEVER IT CARRIES.
		//
		// This used to reattach: read the id off the file, untrash the design if it
		// was parked, and file that design into the project. It made the ID
		// authoritative for identity while Nextcloud stayed authoritative for
		// CONTENT, and those two collide the moment they disagree — which they can,
		// silently, inside one sync interval. Park a design, unarchive it in Penpot,
		// edit it, trash it again, then drag the file back: the reattach hands back
		// bytes the user never saw and cannot have asked for, because nothing local
		// ever knew the design had moved on.
		//
		// The bytes in Nextcloud are the thing the person is holding, so they are
		// what must exist afterwards. An import guarantees it. It also mints an id —
		// Penpot has no way to put new bytes inside an existing design — which is
		// why the id a file arrives carrying now decides nothing at all.
		//
		// GATED ON WHERE IT CAME FROM, not on the stamp. `isUnmapped()` is the stamp
		// park() writes, and it is right almost always — but a file that arrived in
		// unmapped space some other way (copied there, uploaded with a stale id) may
		// carry any mode at all, and it is the same arrival with the same question.
		//
		// THE SOURCE'S MEMBERSHIP STATE, and it took two goes to get right — both
		// raised by Copilot on #52.
		//
		// `$from === null` was wrong because {@see DestinationResolver::projectFor()}
		// answers null for two unrelated reasons: the source was outside every
		// mapping, and the source was inside one whose Drafts project the token could
		// not see. Reading the second as an arrival IMPORTS a file that never left —
		// minting a design and abandoning its history — because a lookup failed on
		// our side.
		//
		// `sourceTeam() === null` was wrong for a narrower reason: a PERSONAL project
		// (§6.31) is a project id with NO team, so a file re-filed inside one has no
		// team above it and had never left Penpot space at all.
		//
		// {@see Membership::belongsToPenpot()} is the question actually being asked —
		// does the source resolve to any Penpot home, team or personal — and it is
		// answered from folder markers alone, with no remote lookup that can fail.
		if (!$this->sourceMembership($source)->belongsToPenpot() || $meta->isUnmapped()) {
			// The old design, if there ever was one, stays wherever it is. A parked
			// one ages out of Penpot's trash on its own; a live one was never ours to
			// touch. Either way this file is a new design from here on.
			$this->imports->adopt($target, $to, $membership->teamId);

			return true;
		}

		// THE STAMP BELOW RUNS EITHER WAY, which is why this is not an early return.
		// An arrival whose design Penpot had already filed still needs its team and
		// its mode written — it is coming back into a mapping, and a file left
		// stamped `unmapped` inside one is invisible to every later gesture.
		if ($this->fileInto($to, $meta->penpotId)) {
			$this->logger->info('penpot_sync writeback: moved Penpot file to another project', [
				'app' => Application::APP_ID,
				'penpotId' => $meta->penpotId,
				'fromProject' => $from,
				'toProject' => $to,
			]);
		}

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
	 * Recover the identity a design lost by crossing a storage boundary.
	 *
	 * ## THE GESTURE THIS MAKES POSSIBLE (`designs/move.feature`, "Move a design into another team")
	 *
	 * Dragging a mirror from a home folder into a Team Folder is an ordinary
	 * cross-team move, and Penpot does it in ONE call — `move-files` carries the
	 * destination team with it (saga §6.27/§6.34). Nextcloud is the half that
	 * could not express it: crossing a storage boundary destroys the file's
	 * `files_metadata`, so the design arrived here carrying no `penpot_id` and the
	 * §6.33 branch above imported it as a brand-new design. The user asked for a
	 * move and got a duplicate with no history — the one outcome the scenario says
	 * must never happen, and why it stood `@blocked`.
	 *
	 * {@see MoveMemory} holds what the file was carrying a moment earlier, read on
	 * `BeforeNodeRenamedEvent` while the record still existed. Re-stamping it here
	 * puts the file back in the state the rest of this method already handles: the
	 * project comparison, the `move-files`, and the team re-stamp at the end all
	 * work unchanged, because from this line on nothing can tell the difference
	 * between a design that crossed a storage and one that did not.
	 *
	 * ## THE FILE ID IS THE SAME ONE, WHICH IS THE SURPRISE
	 *
	 * A cross-storage move looks like a copy-and-delete, so the natural assumption
	 * is a new file id — and it is wrong. Measured live: the id is preserved and
	 * the METADATA is what goes, because removing the source cache entries raises
	 * `CacheEntriesRemovedEvent` and core's own listener drops the rows. So the id
	 * the memory was filed under on the before-event is the id the target has now,
	 * and the target is the only node this needs.
	 *
	 * ## AND THE SOURCE IS NOT A NODE YOU CAN ASK
	 *
	 * The first cut looked the note up under BOTH ids — the source's and the
	 * target's — reasoning that they agree today and a recovery quietly depending
	 * on that would be a quiet thing to get wrong later. `$source` on a COMPLETED
	 * rename is a `NonExistingFile`, and `getId()` on one throws
	 * `NotFoundException`. Which made the belt-and-braces the failure: every
	 * arrival with no metadata — every §6.33 import, the commonest path through
	 * this method — threw before it could reach the import at all, and three
	 * scenarios that had nothing to do with storages went red.
	 *
	 * The unit suite could not have caught it. `$source` there is a mock with a
	 * `getId()` that answers; only a real completed rename has a source that
	 * refuses. {@see \OCA\PenpotSync\Listener\NodeRenamedListener::parentPath()}
	 * already said as much — it reads the source's PATH rather than calling
	 * `getParent()`, because "the source node no longer exists at that path".
	 *
	 * ## AN EMPTY VALUE IS NOT WRITTEN
	 *
	 * The record was deleted outright, so this is a create rather than an update,
	 * and stamping `penpot_revision => ''` would leave a key that reads as "known
	 * to be empty" where "never stamped" is the truth. Only what the file actually
	 * carried is put back.
	 */
	private function recoverAcrossStorages(File $target): ?PenpotFileMetadata {
		$remembered = $this->memory->recall($target->getId());
		if ($remembered === null) {
			return null;
		}

		$this->memory->forget($target->getId());

		$stamp = [PenpotMetadata::KEY_ID => $remembered->penpotId];
		if ($remembered->revision !== '') {
			$stamp[PenpotMetadata::KEY_REVISION] = $remembered->revision;
		}
		if ($remembered->mode !== '') {
			$stamp[PenpotMetadata::KEY_MODE] = $remembered->mode;
		}
		if ($remembered->teamId !== '') {
			$stamp[PenpotMetadata::KEY_TEAM_ID] = $remembered->teamId;
		}

		$this->metadata->writeFile($target->getId(), $stamp);
		$this->logger->info('penpot_sync writeback: a design crossed a storage boundary; restored the identity Nextcloud dropped', [
			'app' => Application::APP_ID,
			'penpotId' => $remembered->penpotId,
			'fileId' => $target->getId(),
			'path' => $target->getPath(),
		]);

		return $remembered;
	}

	/**
	 * `move-files`, treating "it is already there" as the success it is.
	 *
	 * ## PENPOT REFUSES A MOVE INTO THE PROJECT A DESIGN IS ALREADY IN
	 *
	 * `cant-move-to-same-project`, HTTP 400. Ordinarily unreachable, because the
	 * caller compares `$from` against `$to` first and returns early when they match.
	 * Two paths get past that comparison anyway, and both are legitimate:
	 *
	 *   - **an arrival from unmapped space.** `projectForContentIn()` runs BEFORE
	 *     this, and for a folder that is not yet a project it promotes one — which
	 *     files the designs already sitting in that folder, ours included. So by the
	 *     time we ask, Penpot has already put the design where we were about to.
	 *   - **a drag to the team root** whose file was in Drafts to begin with, where
	 *     `$from` reads null and `$to` resolves to that same Drafts project.
	 *
	 * In both the end state the caller wanted is already true, so reporting a
	 * failure would be a lie — and an expensive one, since the listener turns it
	 * into a notification telling the user their move did not reach Penpot.
	 *
	 * ONLY THAT ONE CODE. Every other 400 is a real refusal and still throws.
	 *
	 * @return bool true when Penpot actually moved it, false when it was already
	 *              there — the caller uses that only to decide what to log
	 *
	 * @throws PenpotApiException
	 */
	private function fileInto(string $project, string $penpotId): bool {
		try {
			$this->client->moveFiles($project, [$penpotId], $this->personalTokens->tokenForActor());

			return true;
		} catch (PenpotApiException $e) {
			if ($e->getPenpotCode() !== 'cant-move-to-same-project') {
				throw $e;
			}

			$this->logger->info('penpot_sync writeback: the design was already in the destination project', [
				'app' => Application::APP_ID,
				'penpotId' => $penpotId,
				'project' => $project,
			]);

			return false;
		}
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
	 * ## THE ID STAYS ON THE FILE, BUT IT NO LONGER BUYS A RETURN
	 *
	 * It used to: an unmapped file was a claim on something parked, and moving it
	 * back untrashed the design and reattached to it. That made the ID authoritative
	 * for identity while Nextcloud stayed authoritative for CONTENT, and the two
	 * collide silently inside one sync interval — unarchive in Penpot, edit, re-trash,
	 * drag the file back, and the reattach hands over bytes nobody asked for.
	 *
	 * So a return is now an import ({@see onMove()}), and the parked design simply
	 * ages out of Penpot's trash. The id stays only so a later arrival can be told
	 * apart from a stranger — {@see idIsSpokenFor()} is the one question still asked
	 * of it.
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

	/** The mode a design in this mapping is born in — the mapping's own. */
	private function modeFor(Membership $membership): string {
		$teamId = $membership->teamId ?? '';
		if ($teamId === '') {
			return Mapping::MODE_LINK;
		}

		return $this->mappings->getByTeamId($teamId)?->mode ?? Mapping::MODE_LINK;
	}

	/**
	 * Does a DIFFERENT file in the same mapping already carry this design's id?
	 *
	 * The "keep both versions" answer to the Files app's conflict dialog produces
	 * exactly this: the arrival lands under a free name, so two files sit in one
	 * mapping claiming one design. Only the arrival is asked to give way, so the
	 * question is always about the OTHER files.
	 *
	 * ## SCOPED TO THE MAPPING ROOT, NOT THE PROJECT FOLDER
	 *
	 * A design can be filed into a plain subfolder of a project (§6.29) and a
	 * duplicate can land in a different project of the same team, so the collision
	 * this looks for is not confined to one directory. The mapping root is the
	 * boundary that matters: within it, one id means one file.
	 *
	 * ## AN UNREADABLE TREE ANSWERS "NO"
	 *
	 * A false positive here imports a design that did not need importing — a
	 * duplicate design in Penpot, recoverable and visible. A false negative leaves
	 * two files on one id, which no later pass separates. Neither is good, but the
	 * walk failing is not evidence of a collision, and inventing one would make an
	 * unreadable folder mint designs.
	 */
	private function idIsSpokenFor(File $node, string $penpotId): bool {
		if ($penpotId === '') {
			return false;
		}

		$root = $this->mappingRootOf($node);
		if ($root === null) {
			return false;
		}

		return $this->holdsTheId($root, $penpotId, $node->getId(), 0);
	}

	/**
	 * The mapped folder $node sits under — the nearest ancestor carrying a team id.
	 *
	 * Walks up rather than asking {@see MembershipResolver}, because the resolver
	 * answers WHICH team and this needs the FOLDER: the search below has to start
	 * somewhere, and the mapping root is the only defensible boundary.
	 */
	private function mappingRootOf(File $node): ?Folder {
		try {
			$current = $node->getParent();
		} catch (NotFoundException) {
			return null;
		}

		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			$id = $current->getId();
			if ($id > 0 && $this->metadata->readFolder($id)->hasTeam()) {
				return $current;
			}

			try {
				$parent = $current->getParent();
			} catch (NotFoundException) {
				return null;
			}
			if ($parent->getId() === $id) {
				return null;
			}
			$current = $parent;
		}

		return null;
	}

	/** Any file below $folder other than $exceptId carrying $penpotId. */
	private function holdsTheId(Folder $folder, string $penpotId, int $exceptId, int $depth): bool {
		if ($depth >= self::MAX_DEPTH) {
			return false;
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync writeback: could not read a folder while looking for a duplicate id', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			return false;
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				if ($this->holdsTheId($child, $penpotId, $exceptId, $depth + 1)) {
					return true;
				}
				continue;
			}
			if (!$child instanceof File || $child->getId() === $exceptId) {
				continue;
			}
			if (($this->metadata->readFile($child->getId())?->penpotId ?? '') === $penpotId) {
				return true;
			}
		}

		return false;
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
		return $this->sourceMembership($source)->teamId;
	}

	/**
	 * Where the node resolved BEFORE the move, from folder markers alone.
	 *
	 * NO REMOTE LOOKUP, which is the point: {@see sourceProject()} runs this through
	 * {@see DestinationResolver::projectFor()} and can come back null because Penpot
	 * could not be asked, while this can only come back {@see Membership::none()}
	 * because the folders really say nothing. A question about what the tree says
	 * must not be answerable by a network failure.
	 *
	 * An unreachable parent is `none()`, which reads as "outside every mapping" —
	 * the same answer a deleted parent gives, and the conservative one for a source
	 * that no longer exists.
	 */
	private function sourceMembership(Node $source): Membership {
		try {
			$parent = $source->getParent();
		} catch (NotFoundException) {
			return Membership::none();
		}

		return $this->resolver->resolve($parent);
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
