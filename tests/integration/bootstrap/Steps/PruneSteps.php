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
	/** @When /^the design "([^"]*)" is deleted in Penpot$/ */
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
	 * @When /^the design "([^"]*)" is permanently deleted in Penpot$/
	 */
	public function theDesignIsPermanentlyDeletedInPenpot(string $name): void {
		$fileId = $this->fileIdNamed($name);
		// Soft first: the ids handed to the destroy command may only ever come from
		// a real trash listing (§C6.11), and this suite holds itself to the same
		// rule it holds the app to.
		$this->penpotRpc('delete-file', ['id' => $fileId]);
		$this->penpotRpc('permanently-delete-team-files', [
			'team-id' => $this->firstVisibleTeamId(),
			'ids' => [$fileId],
		]);
	}

	/** @Then /^the pull pruned (\d+) mirrors?$/ */
	public function thePullPrunedMirrors(int $count): void {
		$this->mustReport(sprintf('%d design(s) no longer exist in Penpot', $count));
	}

	/** @Then /^the pull saved (\d+) final archives?$/ */
	public function thePullSavedFinalArchives(int $count): void {
		$this->mustReport(sprintf('%d saved as a final archive first', $count));
	}

	/**
	 * ASSERTED BY ABSENCE, on purpose: the pull prints the prune line only when it
	 * pruned something, so "nothing was pruned" is "the line never appeared".
	 *
	 * @Then /^the pull pruned nothing$/
	 */
	public function thePullPrunedNothing(): void {
		if (str_contains($this->lastOutput, 'no longer exist in Penpot')) {
			throw new \RuntimeException("expected the pull to prune nothing, but it reported a prune:\n{$this->lastOutput}");
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	private function mustReport(string $phrase): void {
		if (!str_contains($this->lastOutput, $phrase)) {
			throw new \RuntimeException("expected the pull to report '{$phrase}', got:\n{$this->lastOutput}");
		}
	}

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
