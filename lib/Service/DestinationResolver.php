<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\Node;
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
	 * The project id a write should target, or null when the node genuinely
	 * belongs to no mapping.
	 *
	 * A null return is always "do not write to Penpot" — never "guess". Callers
	 * treat it as a reason to leave Penpot alone, because the alternative is
	 * inventing a destination for someone's file.
	 */
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
	 * The three outcomes, and they are the nearest-ancestor rule (§6.29) with one
	 * new branch in the middle:
	 *
	 *   - a project ancestor → that project, unchanged. A plain subfolder of a
	 *     project is Nextcloud's layout, which Penpot cannot see.
	 *   - a team but no project → the containing folder BECOMES a project, named
	 *     by its path below the mapping.
	 *   - a team, and the design landed at the mapping ROOT → Drafts (§6.35).
	 *     `pathBelowMapping()` returns null there, which is what
	 *     {@see ProjectFolderService::adoptForContent()} reads to say "not me".
	 */
	public function projectForContentIn(Node $node, Membership $membership): ?string {
		if ($membership->projectId !== null) {
			return $membership->projectId;
		}
		if ($membership->teamId === null) {
			return null;
		}

		try {
			$parent = $node->getParent();
		} catch (\Throwable) {
			return $this->projectFor($membership);
		}

		// A null adoption is not a failure — it is "this folder is not a project",
		// which the mapping root always is and a Penpot refusal temporarily is.
		// Either way the design still belongs in the team, so Drafts is the answer.
		return $this->projects->adoptForContent($parent) ?? $this->draftsProject($membership->teamId);
	}

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
