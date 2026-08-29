<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Turns a resolved {@see Membership} into the Penpot PROJECT a write should
 * target — which is not the same question as "what does the folder tree say".
 *
 * ## THE WHOLE REASON THIS EXISTS: DRAFTS IS A STATE, NOT A FOLDER (§6.35)
 *
 * {@see MembershipResolver} answers structurally: it walks up looking for a
 * folder carrying a project id, and a file sitting at a TEAM ROOT has none. So
 * it reports `projectId = null, teamId = <team>` — which reads exactly like
 * "outside every mapping" and is nothing of the kind. Those files are in the
 * team's **Drafts**, which is a real Penpot project with a real id; Penpot just
 * never gives it a folder in our mirror.
 *
 * Distinguishing those two nulls is the entire job:
 *
 *     projectId set              → that project
 *     projectId null, team set   → that team's default (Drafts) project
 *     projectId null, team null  → genuinely outside every mapping
 *
 * ## WHY IT IS SHARED, AND WHAT IT COST TO LEARN
 *
 * {@see MotionService} got this right; {@see CopyService} was written later and
 * did not — it treated a null project as "outside every mapping" and silently
 * skipped Penpot entirely. Copying a design up to the team root produced a
 * Nextcloud file and no design, with nothing logged, because from the code's
 * point of view nothing had gone wrong.
 *
 * Worse, its unit test PASSED, because the fixture handed the service a resolved
 * project id for the team-root case — a shape the resolver never actually
 * produces. The test encoded the same misunderstanding as the code, so it
 * certified the bug instead of catching it (saga §C6.10).
 *
 * Hence one implementation, in one place, for every write path.
 */
final class DestinationResolver {
	public function __construct(
		private readonly PenpotClient $client,
		private readonly ProjectFolderService $projects,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Where a DESIGN THAT HAS JUST ARRIVED belongs — promoting its folder to a
	 * project if that is what its arrival means (`projects/create.feature`).
	 *
	 * ## WHY THIS IS A SECOND METHOD AND NOT A FLAG ON THE FIRST
	 *
	 * {@see projectFor()} answers a question; this one can CHANGE THE WORLD. It
	 * creates a Penpot project as a side effect, so every call site has to have
	 * decided it means to — and exactly one kind does: a design arriving somewhere
	 * (created, moved in, copied in). The place that must never adopt is the other
	 * half of a move, {@see MotionService::sourceProject()}, which asks where a
	 * file CAME from; adopting there would create a project for the folder someone
	 * just dragged a design OUT of.
	 *
	 * Two methods make that a compile-time distinction instead of a boolean nobody
	 * reads.
	 *
	 * ## THE FOLDER A DESIGN LANDS IN IS THE PROJECT — ALWAYS THE FOLDER ITSELF
	 *
	 * This used to short-circuit on the nearest project ANCESTOR, so a design
	 * dragged into `Bubbles/pustice` where `Bubbles` was already a project stayed
	 * in `Bubbles` and `pustice` became nothing. The rule read "a plain subfolder
	 * of a project is Nextcloud's layout, which Penpot cannot see", and it made
	 * two identical-looking folders behave differently on markers a user cannot
	 * see: whichever folder happened to get a design first won, permanently, and
	 * nothing in the Files app said so.
	 *
	 * It also made `projects/create.feature`'s own headline — *a folder is a
	 * project when a design is in it* — false of every folder below a project.
	 *
	 * So the ancestor is now a FALLBACK rather than an answer, and the order is:
	 *
	 *   - the folder the design landed in becomes a project, named by its path
	 *     below the mapping ({@see ProjectFolderService::adoptForContent()});
	 *   - failing that, the nearest project ancestor. Two things reach this and
	 *     both are legitimate: a LINK mapping, where promotion is refused because
	 *     the tree is Penpot's to write and not ours, and a Penpot refusal, where
	 *     filing the design with its neighbours beats filing it in Drafts;
	 *   - failing that, the team's Drafts (§6.35) — which is what the mapping ROOT
	 *     resolves to, since `pathBelowMapping()` answers null there and
	 *     `adoptForContent()` reads that as "not me".
	 */
	public function projectForContentIn(Node $node, Membership $membership): ?string {
		if ($membership->teamId === null) {
			return null;
		}

		try {
			$parent = $node->getParent();
		} catch (NotFoundException) {
			// PAST THE STORAGE ROOT, the one expected failure — and the only one that
			// means "there is no folder to promote" rather than "the filesystem did
			// not answer". Anything else propagates: a storage or metadata failure
			// swallowed here would pick a destination without knowing where the file
			// actually is, and the callers all have error containment of their own
			// (§6.18 rule 3) that is a better place for it than a silent Drafts.
			return $this->projectFor($membership);
		}

		if (!$parent instanceof Folder) {
			// `Node::getParent()` is typed `Node`, not `Folder` — only a FOLDER can
			// become a project, so anything else is not a promotion case and the
			// design belongs in the team's Drafts. Reached in practice only past the
			// storage root, where the walk has already run out of tree.
			return $this->draftsProject($membership->teamId);
		}

		// A null adoption is not a failure — it is "this folder is not a project",
		// which the mapping root always is, a link mapping's folders always are,
		// and a Penpot refusal temporarily is.
		//
		// THE ANCESTOR COMES BEFORE DRAFTS, and the link case is why. Under a link
		// the folders are filled FROM Penpot and promotion is refused by design; a
		// mirror filed into a subfolder there still belongs to the project it is
		// under, and sending it to Drafts instead would move somebody's design out
		// of the project Penpot has it in because they made a folder.
		return $this->projects->adoptForContent($parent)
			?? $membership->projectId
			?? $this->draftsProject($membership->teamId);
	}

	/**
	 * The project id a write should target, or null when the node genuinely
	 * belongs to no mapping.
	 *
	 * A null return is always "do not write to Penpot" — never "guess". Callers
	 * treat it as a reason to leave Penpot alone, because the alternative is
	 * inventing a destination for someone's file.
	 */
	public function projectFor(Membership $membership): ?string {
		if ($membership->projectId !== null) {
			return $membership->projectId;
		}
		if ($membership->teamId === null) {
			return null;
		}

		return $this->draftsProject($membership->teamId);
	}

	/**
	 * A team's Drafts project — the one flagged `is-default` (§6.35, the same
	 * lookup the pull uses to decide a project's files belong at the team root).
	 *
	 * Returns null when the token cannot see it, which callers treat as "no
	 * destination": better an un-written change than a file filed into a guess.
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

		$this->logger->warning('penpot_sync: no default (Drafts) project visible for team', [
			'app' => Application::APP_ID,
			'teamId' => $teamId,
		]);

		return null;
	}
}
