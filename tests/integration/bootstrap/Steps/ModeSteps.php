<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * `occ penpot_sync:set-mode`, end-to-end (saga §6.22, §C4.8).
 *
 * ## THIS IS THE ONE THING THE UNIT SUITE CANNOT FAKE
 *
 * Promotion is the app's only code path that moves real bytes out of Penpot, and
 * it is four unmockable steps in a row: a POST whose response is an **SSE
 * stream**, a Transit payload in *tagged-map form* buried in the `end` event, a
 * **second authenticated GET** to a completely different URL for the ZIP itself,
 * and a magic-byte check on what comes back. Every one of those was discovered
 * by watching a real Penpot rather than by reading its source (§5.1–§5.4), and
 * every one would happily pass a mocked test while failing against the wire —
 * a proxy that buffers the stream, a Penpot that changes the event name, an
 * asset URL the app cannot reach from inside the cluster (§5.3, an nginx
 * resolver bug that made exactly this fetch 502 while the export "succeeded").
 *
 * So the assertion that matters here is deliberately crude and physical: after
 * a promotion the mirrored file **starts with `PK\x03\x04`**. Not "the mock was
 * called" — the bytes are a ZIP.
 *
 * ## AND THE CHEAP PATH IS ASSERTED TOO, WHICH IS THE POINT OF THE MODE
 *
 * `link` mode's whole claim is that mirroring costs a listing and nothing else.
 * A scenario below maps a team, pulls, and asserts **0 archives exported** —
 * because a regression that quietly exported every file would still pass every
 * other test in this suite, and would only be noticed as a bandwidth bill.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reuses
 * the occ transport, the `status` reader and the direct Penpot RPC seed channel
 * from {@see PullSteps}.
 */
trait ModeSteps {
	/** @Given /^a Penpot file named "([^"]*)" exists in the project "([^"]*)"$/ */
	public function aPenpotFileExistsInTheProject(string $name, string $project): void {
		$this->penpotRpc('create-file', [
			'project-id' => $this->projectIdNamed($project),
			'name' => $name,
		]);
	}

	/** @When /^the admin promotes "([^"]*)" to "sync" mode$/ */
	public function theAdminPromotesTo(string $path): void {
		$this->occ('penpot_sync:set-mode ' . escapeshellarg($path) . ' sync');
	}

	/** @When /^the admin demotes "([^"]*)" to "link" mode$/ */
	public function theAdminDemotesTo(string $path): void {
		// --force because Behat has no tty to answer the confirmation. The prompt
		// itself is covered in the unit suite, where the answer can be scripted.
		$this->occ('penpot_sync:set-mode ' . escapeshellarg($path) . ' link --force');
	}

	/** @When /^the admin sets the mode of "([^"]*)" to "([^"]*)"$/ */
	public function theAdminSetsTheModeOf(string $path, string $mode): void {
		$this->occ('penpot_sync:set-mode ' . escapeshellarg($path) . ' ' . escapeshellarg($mode) . ' --force');
	}

	/** @Then /^the mode change succeeds$/ */
	public function theModeChangeSucceeds(): void {
		if ($this->lastExit !== 0) {
			throw new \RuntimeException("set-mode failed (exit {$this->lastExit}):\n{$this->lastOutput}");
		}
	}

	/** @Then /^the mode change is refused$/ */
	public function theModeChangeIsRefused(): void {
		if ($this->lastExit === 0) {
			throw new \RuntimeException("expected set-mode to be refused, but it succeeded:\n{$this->lastOutput}");
		}
	}

	/** @Then /^the refusal mentions "([^"]*)"$/ */
	public function theRefusalMentions(string $phrase): void {
		if (!str_contains($this->lastOutput, $phrase)) {
			throw new \RuntimeException("expected the refusal to mention '{$phrase}', got:\n{$this->lastOutput}");
		}
	}

	/** @Then /^the file "([^"]*)" is in "([^"]*)" mode$/ */
	public function theFileIsInMode(string $path, string $mode): void {
		$this->mustContain($this->status($path), 'penpot_mode: ' . $mode, $path);
	}

	/**
	 * The load-bearing assertion of this trait: real ZIP bytes on disk.
	 *
	 * @Then /^the file "([^"]*)" holds a real ".penpot" archive$/
	 */
	public function theFileHoldsARealArchive(string $path): void {
		$this->mustContain($this->status($path), 'Content: archive', $path);
	}

	/** @Then /^the file "([^"]*)" holds only a pointer$/ */
	public function theFileHoldsOnlyAPointer(string $path): void {
		$this->mustContain($this->status($path), 'Content: pointer', $path);
	}

	/** @Then /^the file "([^"]*)" still carries its Penpot id$/ */
	public function theFileStillCarriesItsPenpotId(string $path): void {
		if (preg_match('/penpot_id: \S/', $this->status($path)) !== 1) {
			throw new \RuntimeException("expected '{$path}' to still carry a penpot_id after the mode change.");
		}
	}

	/** @Then /^the pull exported (\d+) archives?$/ */
	public function thePullExportedArchives(int $count): void {
		if (!str_contains($this->lastOutput, sprintf('%d archive(s) exported', $count))) {
			throw new \RuntimeException("expected the pull to report {$count} archive(s) exported, got:\n{$this->lastOutput}");
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * The Penpot id of a project by name, read back through the app's own listing
	 * so the seed channel and the read channel cross-check each other.
	 */
	private function projectIdNamed(string $name): string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while looking for project '{$name}':\n{$res['output']}");
		}

		// A project line is `  <name>  <uuid>  [<team>]`; a FILE line under it is
		// indented further and carries `revn=` instead of a trailing team. The
		// trailing `[team]` is what tells them apart, so a file that happens to
		// share a project's name cannot be mistaken for one.
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^\s+' . preg_quote($name, '/') . '\s+([0-9a-f-]{36})\s+\[/', $line, $m) === 1) {
				return $m[1];
			}
		}

		throw new \RuntimeException("no project named '{$name}' in:\n{$res['output']}");
	}
}
