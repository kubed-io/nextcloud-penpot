<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * The prune and its final snapshot (saga Ch2 Course 5, §6.42/§6.46).
 *
 * ## THIS ONE NEEDS A LIVE PENPOT FOR A DIFFERENT REASON THAN THE OTHERS
 *
 * Everywhere else the live suite exists because the *wire* is unmockable. Here
 * it is the **grace window** — a claim about Penpot's own behaviour that no mock
 * can hold us to: `export-binfile` keeps working on a design that has already
 * been deleted, for as long as Penpot's trash holds it. The whole rescue path is
 * built on that sentence, so the sentence is asserted against Penpot rather than
 * quoted from a survey. A mocked `storeArchive` would return bytes for a design
 * that never existed and prove nothing at all.
 *
 * ## AND ONE THING THAT IS ASSERTED BY ABSENCE
 *
 * A pull that changes nothing must prune nothing. That is the negative half of
 * the most dangerous operation in the app: pruning is driven by "Penpot did not
 * name this", and every way of failing to ask — a 502, a skipped project, a
 * half-read listing — looks exactly like a deletion. A regression here does not
 * throw; it quietly moves a team's mirrors to the trash on the next scheduled
 * run. So "prunes nothing" is a step, not an assumption.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reuses
 * the occ transport, the `status` reader and the direct Penpot RPC seed channel
 * from {@see PullSteps}.
 */
trait PruneSteps {
	/**
	 * A PROJECT deleted in Penpot, with the sync folded in — the folder-shaped twin
	 * of the step above, and the trigger for `projects/delete.feature`'s last rule.
	 *
	 * ON THE WIRE IT IS A BARE `id`, and reading the app's own call site is how to
	 * get that wrong. {@see \OCA\PenpotSync\Service\PenpotClient::deleteProject()}
	 * passes `['project' => …]`, but `PenpotClient` carries a param-rename map that
	 * rewrites it to `id` before it is sent (saga Ch3 §C6.38, read off
	 * `schema:delete-project`). This step posts raw JSON, so it must spell what
	 * Penpot actually reads — the first cut copied the PHP spelling and CI answered
	 * `HTTP 400 … [:id] :malli.core/missing-key`, with `{:project "…"}` quoted back.
	 *
	 * Soft, like `delete-file`: the project goes to Penpot's own trash, which is
	 * what leaves its designs exportable for the prune's rescue on the way past.
	 *
	 * @When /^someone deletes the "([^"]*)" project in Penpot$/
	 */
	public function someoneDeletesTheProjectInPenpot(string $name): void {
		$this->penpotRpc('delete-project', ['id' => $this->projectIdNamed($name)]);
		$this->theAdminRunsAPull();
	}

	public function theDesignIsDeletedInPenpot(string $name): void {
		// `delete-file` is a SOFT delete — it moves the design into Penpot's own
		// trash, which is exactly the state the rescue depends on. Its id param is
		// the bare `id` (saga §6.54's spelling, not `file-id`).
		$this->penpotRpc('delete-file', ['id' => $this->fileIdNamed($name)]);
	}

	/**
	 * PAST THE GRACE WINDOW, WITHOUT WAITING A WEEK. `permanently-delete-team-files`
	 * destroys the design outright (§C6.11 — it does not require the file to be in
	 * the trash, and will happily destroy a live one), which puts the pull in
	 * exactly the state a seven-day-old deletion would: the design is not listed,
	 * not in Penpot's trash, and `export-binfile` can no longer rescue it.
	 *
	 * That state is otherwise untestable, and it is the one where the prune's
	 * behaviour matters most — it is the case where the local mirror is genuinely
	 * the last copy.
	 *
	 * NOT A STEP ANY MORE — no scenario in the suite says this sentence. It
	 * stays as the plain helper 1 other step calls.
	 */
	public function theDesignIsPermanentlyDeletedInPenpot(string $name): void {
		$this->permanentlyDeleteDesignById($this->fileIdNamed($name));
	}

	/**
	 * The same erasure, by id — for a scenario that says "its design" rather than
	 * naming one, and for the team the file's own mapping belongs to.
	 *
	 * THE TEAM IS RESOLVED FROM THE PATH where there is one. `firstVisibleTeamId()`
	 * answers whichever team the probe lists first, which is right for the prune
	 * scenarios (one team on stage) and wrong for the rewritten Backgrounds, which
	 * map three at once — destroying a file with the wrong team id is a no-op that
	 * looks like success.
	 */
	private function permanentlyDeleteDesignById(string $fileId): void {
		// Soft first: the ids handed to the destroy command may only ever come from
		// a real trash listing (§C6.11), and this suite holds itself to the same
		// rule it holds the app to.
		$this->penpotRpc('delete-file', ['id' => $fileId]);

		// THE ID HAS TO COME OFF THE TRASH LISTING, and the team has to be the one
		// that listing came from. `permanently-delete-team-files` is a SILENT no-op
		// when the team and the ids do not match — it looks exactly like success and
		// then fails three lines later on "still listed". Firing it at every mapped
		// team was not enough either: §C6.11 says the ids may only ever come from a
		// real trash listing, and this suite holds itself to the rule it holds the
		// app to. So the design is found in a team's trash first, and destroyed
		// against THAT team.
		$teams = array_values(array_unique($this->mappingTeamIds)) ?: [$this->firstVisibleTeamId()];
		foreach ($teams as $team) {
			foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $team]) as $file) {
				if (($file['id'] ?? null) !== $fileId) {
					continue;
				}
				// TWICE, AND THEN CONFIRMED BY RE-READING. §6.49 recorded this exact
				// shape on the restore twin: `restore-deleted-team-files` reported
				// `end` while `deleted_at` was still set, and a second call cleared
				// it. Success is not proof of success on these commands, so the
				// suite does what it holds the app to and re-reads.
				for ($attempt = 0; $attempt < 3; $attempt++) {
					$this->penpotRpc('permanently-delete-team-files', [
						'team-id' => $team,
						'ids' => [$fileId],
					]);
					if (!$this->inTeamTrash($team, $fileId)) {
						return;
					}
				}

				throw new \RuntimeException(
					"Penpot accepted permanently-delete-team-files for {$fileId} three times "
					. "and the design is still in team {$team}'s trash.",
				);
			}
		}

		throw new \RuntimeException(
			"the design {$fileId} is in no mapped team's trash after being deleted, so it cannot be destroyed",
		);
	}

	/**
	 * Destroy a design that is ALREADY in Penpot's trash — the state a mirror
	 * reaches by being deleted in Nextcloud, since that delete passes through to
	 * `delete-file`.
	 *
	 * Its id has to come off the TRASH listing, not the project listing: the
	 * design is not in a project any more, and `get-team-deleted-files` is the
	 * only sanctioned source of ids for the destroy command anyway (§C6.11 — it
	 * has no safety of its own, and this suite holds itself to the rule it holds
	 * the app to).
	 *
	 * NOT A STEP ANY MORE — no scenario in the suite says this sentence. It
	 * stays as the plain helper 1 other step calls.
	 */
	public function theDesignIsPurgedFromPenpotsTrash(string $name): void {
		$fileId = null;
		foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $this->teamId()]) as $file) {
			if (($file['name'] ?? null) === $name && is_string($file['id'] ?? null)) {
				$fileId = $file['id'];
				break;
			}
		}
		if ($fileId === null) {
			throw new \RuntimeException("no design named '{$name}' in Penpot's trash — was it actually deleted?");
		}

		$this->penpotRpc('permanently-delete-team-files', [
			'team-id' => $this->teamId(),
			'ids' => [$fileId],
		]);
	}

	/** Whether this team's trash still lists that design. */
	private function inTeamTrash(string $team, string $fileId): bool {
		foreach ($this->penpotRpcRead('get-team-deleted-files', ['team-id' => $team]) as $file) {
			if (($file['id'] ?? null) === $fileId) {
				return true;
			}
		}

		return false;
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * The Penpot id of a FILE by name, read back through the app's own probe so
	 * the seed channel and the read channel cross-check each other — the same
	 * trick {@see ModeSteps::projectIdNamed()} uses one level up.
	 *
	 * A file line is `    <name>  revn=<n>  <uuid>`; the `revn=` is what tells it
	 * apart from the project line above it, which ends in `[<team>]`.
	 */
	private function fileIdNamed(string $name): string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while looking for file '{$name}':\n{$res['output']}");
		}

		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^\s+' . preg_quote($name, '/') . '\s+revn=\S+\s+([0-9a-f-]{36})\s*$/', $line, $m) === 1) {
				return $m[1];
			}
		}

		throw new \RuntimeException("no Penpot file named '{$name}' in:\n{$res['output']}");
	}
}
