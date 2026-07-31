<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
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
