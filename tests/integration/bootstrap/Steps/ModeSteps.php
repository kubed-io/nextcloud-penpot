<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * What a mirror's mode LEFT ON DISK — observed, never changed.
 *
 * ## THERE IS NO LONGER ANY WAY TO CHANGE A FILE'S MODE, AND THAT IS THE POINT
 *
 * This trait used to drive `occ penpot_sync:set-mode`, promoting and demoting
 * single files. That command is gone. The mode is an immutable field of the
 * MAPPING, so a design's mode follows entirely from the mapping it was mirrored
 * under, and the only way to change it is to remove the mapping and map the team
 * again. A per-file override was a fourth way to decide the same thing, existed
 * in neither sibling app, and quietly made "the mapping says link" untrue.
 *
 * So a scenario that needs a real archive on disk asks for a **sync mapping** —
 * `a Penpot team named "…" is mapped to the folder "…" in "sync" mode`
 * ({@see PullSteps}) — and the pull does the exporting.
 *
 * ## THE EXPORT IS STILL THE THING THE UNIT SUITE CANNOT FAKE
 *
 * An export is the app's only code path that moves real bytes out of Penpot, and
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
 * So the assertion that matters here stays deliberately crude and physical: the
 * mirrored file **starts with `PK\x03\x04`**. Not "the mock was called" — the
 * bytes are a ZIP.
 *
 * ## AND THE CHEAP PATH IS ASSERTED TOO, WHICH IS THE POINT OF THE MODE
 *
 * `link` mode's whole claim is that mirroring costs a listing and nothing else.
 * A scenario maps a team, pulls, and asserts **0 archives exported** — because a
 * regression that quietly exported every file would still pass every other test
 * in this suite, and would only be noticed as a bandwidth bill.
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

	/**
	 * The negative twin of the assertion above, and it got STRICTER at §C6.6.
	 *
	 * A `link` used to hold a small JSON body, so "not an archive" was the most
	 * this could claim. A link is now zero bytes, so `status` reports `empty` and
	 * this asserts the absence of content itself. `Content: pointer` still exists
	 * as a third state — a legacy body not yet truncated by a pull — and this
	 * step deliberately does NOT accept it: a demotion that left a body behind
	 * would be a real regression.
	 *
	 * @Then /^the file "([^"]*)" holds no content at all$/
	 */
	public function theFileHoldsNoContentAtAll(string $path): void {
		$this->mustContain($this->status($path), 'Content: empty', $path);
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
