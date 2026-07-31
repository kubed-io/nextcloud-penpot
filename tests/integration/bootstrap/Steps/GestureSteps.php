<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * The file-manager gestures: COPY, MOVE and RENAME (`copy.feature`,
 * `move.feature`, `rename.feature`).
 *
 * ## THE THREE PATHS THAT SHIPPED WITHOUT A LIVE TEST
 *
 * These are the app's write-backs, and every one of them is driven by an event
 * Nextcloud emits from its Files API. `occ` cannot perform a single one, so the
 * suite could configure the app and pull with it but never *use* it — the three
 * features sat @todo while the code they describe ran only ever by hand.
 *
 * Three separate bugs came out of that gap in one sitting:
 *
 *   §C6.8   a `move-files` param bug was believed for an hour, because nothing
 *           red contradicted it;
 *   §C6.9   a copy silently failed to record its id — which presents as a broken
 *           RENAME, one gesture later, and reached a human before a test;
 *   §C6.10  a copy to the team root did nothing at all in Penpot, while its unit
 *           test passed against a mock handed a shape the resolver never emits.
 *
 * Every one of those fails on the first run of a real gesture against a real
 * Penpot. That is what these steps are for.
 *
 * ## ASSERTED THROUGH THE APP, SEEDED THROUGH THE WIRE
 *
 * Same cross-check the rest of the suite uses: the gesture goes in over WebDAV
 * (what a browser does), and the result is read back BOTH through the app
 * (`penpot_sync:status`, so we see what the app believes) and through Penpot's
 * own RPC (so we see what actually exists). A test that only asked the app would
 * have passed for every bug listed above.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; uses the
 * DAV transport from {@see \OCA\PenpotSync\Tests\Integration\Support\WebDavTrait}
 * and the Penpot RPC channel from {@see PullSteps}.
 */
trait GestureSteps {
	/** Where the most recent gesture put the file, for the assertions to read. */
	private string $gestureTarget = '';

	// ── the gestures ────────────────────────────────────────────────────────

	/** @When /^I copy "([^"]*)" to "([^"]*)"$/ */
	public function iCopyTo(string $from, string $to): void {
		$this->davCopy($from, $to);
		$this->gestureTarget = $to;
		// The listeners run in the same request as the DAV call, so by the time
		// COPY has answered 201 the design either exists in Penpot or does not.
		// Nothing to wait for, which is what makes these assertions stable.
	}

	/** @When /^I move "([^"]*)" to "([^"]*)"$/ */
	public function iMoveTo(string $from, string $to): void {
		$this->davMove($from, $to);
		$this->gestureTarget = $to;
	}

	/**
	 * A rename is a MOVE to a sibling path — the same DAV verb and the same
	 * Nextcloud event. Telling the two apart is the listener's job, not the
	 * transport's, so this step deliberately goes through the same call.
	 *
	 * @When /^I rename "([^"]*)" to "([^"]*)"$/
	 */
	public function iRenameTo(string $path, string $newName): void {
		$parent = dirname($path);
		$target = ($parent === '.' || $parent === '') ? $newName : $parent . '/' . $newName;
		$this->davMove($path, $target);
		$this->gestureTarget = $target;
	}

	// ── what the APP believes ───────────────────────────────────────────────

	/** @Then /^the file "([^"]*)" carries a Penpot id$/ */
	public function theFileCarriesAPenpotId(string $path): void {
		if (preg_match('/penpot_id: \S/', $this->status($path)) !== 1) {
			throw new \RuntimeException(
				"expected '{$path}' to carry a penpot_id, got:\n" . $this->status($path),
			);
		}
	}

	/**
	 * The negative that the copy bug needed: a file that exists and is untracked
	 * looks completely normal in the Files app, so only the stamp shows it.
	 *
	 * @Then /^the file "([^"]*)" carries no Penpot id$/
	 */
	public function theFileCarriesNoPenpotId(string $path): void {
		if (preg_match('/penpot_id: \S/', $this->status($path)) === 1) {
			throw new \RuntimeException(
				"expected '{$path}' to carry NO penpot_id, got:\n" . $this->status($path),
			);
		}
	}

	/** @Then /^the files "([^"]*)" and "([^"]*)" carry different Penpot ids$/ */
	public function theFilesCarryDifferentPenpotIds(string $a, string $b): void {
		$idA = $this->penpotIdOfFile($a);
		$idB = $this->penpotIdOfFile($b);
		if ($idA === $idB) {
			throw new \RuntimeException(
				"expected '{$a}' and '{$b}' to be different designs, but both carry {$idA}. "
				. 'Two files claiming one design is the ambiguity copy.feature exists to prevent.',
			);
		}
	}

	// ── what PENPOT actually holds ──────────────────────────────────────────

	/** @Then /^Penpot project "([^"]*)" holds (\d+) designs?$/ */
	public function penpotProjectHoldsDesigns(string $projectName, int $count): void {
		$found = count($this->penpotFileNamesIn($projectName));
		if ($found !== $count) {
			throw new \RuntimeException(
				sprintf("expected %d design(s) in Penpot project '%s', found %d: %s", $count, $projectName, $found, implode(', ', $this->penpotFileNamesIn($projectName))),
			);
		}
	}

	/** @Then /^Penpot project "([^"]*)" holds a design named "([^"]*)"$/ */
	public function penpotProjectHoldsADesignNamed(string $projectName, string $designName): void {
		$names = $this->penpotFileNamesIn($projectName);
		if (!in_array($designName, $names, true)) {
			throw new \RuntimeException(
				sprintf("expected a design named '%s' in Penpot project '%s'; found: %s", $designName, $projectName, implode(', ', $names) ?: '(none)'),
			);
		}
	}

	/** @Then /^Penpot project "([^"]*)" holds no design named "([^"]*)"$/ */
	public function penpotProjectHoldsNoDesignNamed(string $projectName, string $designName): void {
		if (in_array($designName, $this->penpotFileNamesIn($projectName), true)) {
			throw new \RuntimeException(
				sprintf("expected NO design named '%s' in Penpot project '%s', but it is there", $designName, $projectName),
			);
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/** The `penpot_id` the app has stamped on a file, or '' when untracked. */
	private function penpotIdOfFile(string $path): string {
		if (preg_match('/penpot_id: (\S+)/', $this->status($path), $m) === 1) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Design names in a Penpot project, read through the app's own probe so the
	 * seed channel and the read channel keep cross-checking each other — the same
	 * trick PruneSteps uses.
	 *
	 * A project line ends in `[<team>]`; a file line under it carries `revn=`.
	 *
	 * @return list<string>
	 */
	private function penpotFileNamesIn(string $projectName): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while listing '{$projectName}':\n{$res['output']}");
		}

		$names = [];
		$inProject = false;
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}[0-9a-f-]{36}\s+\[/', $line, $m) === 1) {
				$inProject = trim($m[1]) === $projectName;
				continue;
			}
			if ($inProject && preg_match('/^\s+(.*?)\s+revn=/', $line, $m) === 1) {
				$names[] = trim($m[1]);
			}
		}

		return $names;
	}
}
