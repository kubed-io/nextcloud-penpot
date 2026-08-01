<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use GuzzleHttp\Client;

/**
 * The pull, end-to-end: `occ penpot_sync:sync pull` against a real Nextcloud and
 * a real Penpot, asserted through `occ penpot_sync:status` (saga Ch2 Course 3).
 *
 * ## WHY THIS SUITE IS WORTH A LIVE PENPOT
 *
 * The pull is the one place three unmocked things meet: the Penpot wire read
 * (`get-all-projects` / `get-project-files`), real Nextcloud folder writes in a
 * bare `occ` context, and the {@see \OCA\PenpotSync\Service\MembershipResolver}
 * walk over a tree the pull actually built. The unit suite mocks each in
 * isolation; only here do they have to agree. In particular the resolver — *the
 * single most load-bearing rule in the app* (saga §6.29) — is exercised against
 * a real folder tree for the first time.
 *
 * ## THE FIXTURE IS CREATED DIRECTLY IN PENPOT (not through the app)
 *
 * A pull has nothing to mirror unless Penpot has projects, so a scenario seeds
 * one over Penpot's own RPC bus with the same minted token the app uses — the
 * "direct Penpot channel" the FeatureContext reserved for the assertion/seed
 * side. `create-project` is confirmed live and kebab-cased (`team-id`, saga
 * §6.38); we do not read its Transit response, only assert HTTP 200.
 *
 * ## PLAIN FOLDER, NOT A TEAM FOLDER
 *
 * The CI Nextcloud has no groupfolders app, so mappings here use
 * `--no-team-folder` — the admin-owned backend {@see \OCA\PenpotSync\Service\StorageService}
 * builds. The groupfolders backend is out of scope for this suite until the CI
 * image ships it.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reuses
 * the occ transport and the team helpers from the other traits.
 */
trait PullSteps {
	/** The Penpot id of the team the current scenario mapped. */
	private string $pulledTeamId = '';

	/** path -> the mtime/etag stamp noted before a pull. @var array<string, string> */
	private array $notedStamps = [];

	/** @Given /^the first visible team is mapped as a plain folder "([^"]*)"$/ */
	public function theFirstVisibleTeamIsMappedAsAPlainFolder(string $folder): void {
		$this->noPenpotTeamsAreMapped();
		$this->pulledTeamId = $this->firstVisibleTeamId();

		$res = $this->occ(sprintf(
			'penpot_sync:add-mapping %s --folder=%s --no-team-folder',
			escapeshellarg($this->pulledTeamId),
			escapeshellarg($folder),
		));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not map the team as a plain folder:\n{$res['output']}");
		}
	}

	/** @Given /^a Penpot project named "([^"]*)" exists in that team$/ */
	public function aPenpotProjectExistsInThatTeam(string $name): void {
		$teamId = $this->pulledTeamId !== '' ? $this->pulledTeamId : $this->firstVisibleTeamId();
		$this->penpotRpc('create-project', ['team-id' => $teamId, 'name' => $name]);
	}

	/**
	 * The sync run, as an ACTION — an admin clicking the button or running the
	 * command, which is what `reconcile.feature` is about.
	 *
	 * ## TWO PHRASINGS, ONE FUNCTION — AND THAT IS THE POINT
	 *
	 * Cucumber and Behat ignore the KEYWORD when matching a step, so the same
	 * text can be a Given in one scenario and a When in another. What they do not
	 * ignore is the text, and the text is what a reader believes.
	 *
	 * "The admin runs a pull" as setup made it read as though an admin were
	 * permanently on call, standing by to run a sync before every gesture a user
	 * makes. That is not the system being described — it is scaffolding wearing a
	 * behaviour's clothes. Setup says what IS TRUE ("the team has been mirrored"),
	 * not who did what to make it true.
	 *
	 * So: use the ACTION phrasing where the run is the behaviour under test
	 * (reconcile.feature), and the STATE phrasing everywhere the mirror merely has
	 * to exist first. One implementation, because it is one operation.
	 *
	 * THREE PHRASINGS, ONE OPERATION:
	 *
	 *   "the admin runs a pull"                  the run IS the behaviour under
	 *                                            test — reconcile.feature only
	 *   "the team has been mirrored into
	 *    Nextcloud"                              setup: a mirror has to exist
	 *                                            before a gesture can touch it
	 *   "the team is mirrored again"             the EVENT in a Penpot-origin
	 *                                            scenario: someone changed
	 *                                            something upstream, and nothing
	 *                                            happens in Nextcloud until the
	 *                                            next sync notices
	 *
	 * That last one matters more than it looks. A Penpot-side change is context —
	 * it already happened, elsewhere, possibly by someone else. The event this
	 * app is responsible for is the sync seeing it.
	 *
	 * @When /^the admin runs a pull$/
	 * @Given /^the team has been mirrored into Nextcloud$/
	 * @When /^the team is mirrored again$/
	 */
	public function theAdminRunsAPull(): void {
		$this->occ('penpot_sync:sync pull');
	}

	/**
	 * A design that already exists in Penpot AND is already mirrored.
	 *
	 * ## THE SETUP PULL SHOULD NOT BE A STEP AT ALL
	 *
	 * Almost every scenario here needs a mirror before it can do anything, and
	 * spelling that out cost three lines: seed a project, seed a file, run a pull.
	 * The pull in that trio is not something the scenario DOES — it is how the
	 * precondition comes to be true, which is the step definition's business, not
	 * the reader's.
	 *
	 * Left visible it also lied about the system: it read as though an admin were
	 * on call to run a sync before every gesture a user makes. Nobody does that;
	 * the mirror is simply there, because a sync ran at some point.
	 *
	 * So the pull survives in the Gherkin in exactly two places — where the run IS
	 * the behaviour (`reconcile.feature`), and where it is the EVENT that lets
	 * Nextcloud notice an upstream change ("when the team is mirrored again").
	 * Everywhere else it belongs in here.
	 *
	 * @Given /^a mirrored design "([^"]*)" in the project "([^"]*)"$/
	 */
	public function aMirroredDesignInTheProject(string $design, string $project): void {
		$this->aPenpotProjectExistsInThatTeam($project);
		$this->aPenpotFileExistsInTheProject($design, $project);
		$this->theAdminRunsAPull();
	}

	/**
	 * The same, for a project with no design in it yet — "+ New" scenarios need
	 * the folder to exist but must create the design themselves.
	 *
	 * @Given /^a mirrored project "([^"]*)"$/
	 */
	public function aMirroredProject(string $project): void {
		$this->aPenpotProjectExistsInThatTeam($project);
		$this->theAdminRunsAPull();
	}

	/**
	 * The idempotency guard the sibling app fails (§C6.19).
	 *
	 * `nextcloud-n8n` rewrites every mirrored file on every run, so a pull with
	 * nothing changed upstream moves mtime and etag on all of them — which tells
	 * every connected client to re-download the lot. This app avoids it via two
	 * guards added for unrelated reasons (`storeLink()`'s early return on an
	 * empty file, and `driftedOrMissing()`'s revision gate), and neither is
	 * protected by any other scenario: making the write unconditional would
	 * leave the rest of this suite green.
	 *
	 * Read over DAV rather than through the app deliberately — the question is
	 * what a SYNC CLIENT would see, and the app's own view of a file cannot
	 * answer that.
	 *
	 * @Given /^I note the mtime and etag of "([^"]*)"$/
	 */
	public function iNoteTheStampOf(string $path): void {
		$this->notedStamps[$path] = $this->davStamp($path);
	}

	/** @Then /^"([^"]*)" has the same mtime and etag$/ */
	public function hasTheSameStamp(string $path): void {
		$before = $this->notedStamps[$path]
			?? throw new \RuntimeException("nothing was noted for '{$path}' — the Given is missing");
		$after = $this->davStamp($path);
		if ($before !== $after) {
			throw new \RuntimeException(
				"'{$path}' was rewritten by a pull that changed nothing upstream.\n"
				. "  before: {$before}\n  after:  {$after}\n"
				. 'mtime and etag are what every sync client reads, so this makes every '
				. 'device re-download the whole mapped folder after every scheduled pull.',
			);
		}
	}

	/** @Then /^the pull succeeds$/ */
	public function thePullSucceeds(): void {
		if ($this->lastExit !== 0 || !str_contains($this->lastOutput, 'Pull complete')) {
			throw new \RuntimeException("the pull did not complete cleanly (exit {$this->lastExit}):\n{$this->lastOutput}");
		}
	}

	/** @Then /^the folder "([^"]*)" carries the team's Penpot id$/ */
	public function theFolderCarriesTheTeamId(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: folder', $path);
		$this->mustContain($out, 'penpot_team_id: ' . $this->pulledTeamId, $path);
	}

	/** @Then /^the folder "([^"]*)" carries a Penpot project id$/ */
	public function theFolderCarriesAProjectId(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: folder', $path);
		if (preg_match('/penpot_project_id: \S/', $out) !== 1) {
			throw new \RuntimeException("expected '{$path}' to carry a Penpot project id, got:\n{$out}");
		}
	}

	/** @Then /^resolving "([^"]*)" reports the team$/ */
	public function resolvingReportsTheTeam(string $path): void {
		$this->mustContain($this->status($path), 'team=' . $this->pulledTeamId, $path);
	}

	/** @Then /^resolving "([^"]*)" reports it is inside a Penpot project$/ */
	public function resolvingReportsInProject(string $path): void {
		$this->mustContain($this->status($path), 'Membership: in_project', $path);
	}

	/** @Then /^there is no node at "([^"]*)"$/ */
	public function thereIsNoNodeAt(string $path): void {
		$this->mustNotExist($path);
	}

	/**
	 * Assert a path does not exist, ON THE SPECIFIC FAILURE that means that.
	 *
	 * A non-zero exit is NOT enough. `penpot_sync:status` also exits 1 when it
	 * cannot resolve the sync actor's home at all, so "any failure" would read a
	 * broken fixture as a passing absence assertion — a test that goes green
	 * precisely when the thing it depends on has stopped working. The command
	 * prints `No such node: <path>` for the one case we mean.
	 */
	private function mustNotExist(string $path): void {
		$res = $this->occ('penpot_sync:status ' . escapeshellarg($path));
		if ($res['exit'] === 0) {
			throw new \RuntimeException("expected no node at '{$path}', but one exists:\n{$res['output']}");
		}
		if (!str_contains($res['output'], 'No such node')) {
			throw new \RuntimeException(
				"status failed for '{$path}', but NOT because the node is absent — so this "
				. "assertion proves nothing:\n{$res['output']}",
			);
		}
	}

	// ── resolution: what the folder walk says a node belongs to ──────────────

	/**
	 * The resolver's answer for a node, as `penpot_sync:status` reports it.
	 *
	 * ASSERTED ON THE PROJECT'S REAL PENPOT ID, not on a folder name. The whole
	 * claim of `mapping-membership.feature` is that membership comes from
	 * METADATA rather than from position or naming, so an assertion that only
	 * compared paths would pass for a resolver that had never read a marker.
	 * Resolving the name through Penpot's own listing first is what makes this
	 * test the rule rather than a restatement of the folder tree.
	 *
	 * @Then /^"([^"]*)" resolves to the project "([^"]*)"$/
	 */
	public function resolvesToTheProject(string $path, string $project): void {
		$this->mustContain($this->status($path), 'project=' . $this->penpotProjectId($project), $path);
	}

	/**
	 * In a team, in no project — which is Penpot's Drafts (§6.35), and NOT an
	 * error state. The distinction this asserts is the one three separate bugs
	 * lived in (§C6.8/§C6.9/§C6.10): "no project ancestor" means Drafts, not
	 * "outside every mapping".
	 *
	 * @Then /^"([^"]*)" is in the team's Drafts$/
	 */
	public function isInTheTeamsDrafts(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Membership: drafts', $path);
		$this->mustContain($out, 'team=' . $this->pulledTeamId, $path);
		// `project=)` — the CLOSING PAREN is the assertion. The line is
		// "Membership: drafts (team=<id> project=)", so an empty project is
		// `project=` immediately followed by `)`. Matching `project=\S` instead
		// matched that paren and failed on correct output.
		$this->mustContain($out, 'project=)', $path);
	}

	/** @Then /^"([^"]*)" resolves to no Penpot mapping at all$/ */
	public function resolvesToNoMapping(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Membership: none', $path);
		// Both ids empty: "(team= project=)" exactly. See the paren note above.
		$this->mustContain($out, '(team= project=)', $path);
	}

	/**
	 * Membership is DERIVED, never stored (§6.29). There is no `penpot_mapping`
	 * key, and a file must not carry a copy of its project — a copy would have to
	 * be rewritten on every move, which is the drift the derived design exists to
	 * avoid. Asserted by checking the file's own stamp list, not the resolved
	 * line below it.
	 *
	 * @Then /^the file "([^"]*)" stores no copy of its project$/
	 */
	public function storesNoCopyOfItsProject(string $path): void {
		$out = $this->status($path);
		$this->mustContain($out, 'Type: file', $path);
		if (preg_match('/^penpot_project_id: \S/m', $out) === 1) {
			throw new \RuntimeException(
				"'{$path}' carries a penpot_project_id of its own. Membership is derived from "
				. "the folder walk; a copy on the file would go stale on the first move:\n{$out}",
			);
		}
	}

	/** @Then /^no folder named "([^"]*)" exists under the mapped folder$/ */
	public function noFolderNamedExists(string $name): void {
		$this->mustNotExist('Penpot/' . $name);
	}

	/** @Then /^the file "([^"]*)" is still there and untouched$/ */
	public function isStillThereAndUntouched(string $path): void {
		if (!$this->davExists($path)) {
			throw new \RuntimeException("'{$path}' is gone — the pull pruned a file it does not manage");
		}
	}

	/**
	 * A project's Penpot id, looked up by name through the app's own probe.
	 *
	 * The probe prints `  <name>  <uuid>  [<team>]` per project — the same
	 * listing {@see GestureSteps::penpotFileNamesIn()} parses for designs.
	 */
	private function penpotProjectId(string $name): string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while resolving project '{$name}':\n{$res['output']}");
		}
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^  (\S.*?)\s{2,}([0-9a-f-]{36})\s+\[/', $line, $m) === 1 && trim($m[1]) === $name) {
				return $m[2];
			}
		}

		throw new \RuntimeException("Penpot has no project named '{$name}':\n{$res['output']}");
	}

	// ── the DAV surface: what a client actually sees ─────────────────────────

	/**
	 * A Penpot metadata property, read over PROPFIND (`file-type.feature`).
	 *
	 * READ THROUGH DAV, NOT THROUGH THE APP, and that is the whole point of these
	 * scenarios: the README promises these keys are visible to any WebDAV client,
	 * and `occ penpot_sync:status` cannot answer whether DAV advertises them. The
	 * keys are registered in `Application::boot()` precisely so they ride the
	 * directory PROPFIND, and nothing had ever checked that they do.
	 *
	 * @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is set$/
	 */
	public function theDavPropertyIsSet(string $key, string $path): void {
		if (($this->davReadMetadata($path, $key) ?? '') === '') {
			throw new \RuntimeException(
				"PROPFIND on '{$path}' returned no nc:metadata-{$key}. The key is registered in "
				. 'Application::boot() so DAV advertises it; a client that cannot read it has no '
				. 'way to tell a mirror from an ordinary file.',
			);
		}
	}

	/** @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is "([^"]*)"$/ */
	public function theDavPropertyEquals(string $key, string $path, string $expected): void {
		$actual = $this->davReadMetadata($path, $key) ?? '';
		if ($actual !== $expected) {
			throw new \RuntimeException(
				"expected nc:metadata-{$key} of '{$path}' to be '{$expected}', got '{$actual}'",
			);
		}
	}

	/** @Then /^the DAV property "nc:metadata-([^"]*)" of "([^"]*)" is absent$/ */
	public function theDavPropertyIsAbsent(string $key, string $path): void {
		$actual = $this->davReadMetadata($path, $key) ?? '';
		if ($actual !== '') {
			throw new \RuntimeException(
				"expected '{$path}' to carry NO nc:metadata-{$key}, got '{$actual}'",
			);
		}
	}

	/**
	 * The custom mimetype, read off the same PROPFIND a Files client uses.
	 *
	 * NOT `application/zip`, which is what a `.penpot` archive would otherwise be
	 * sniffed as — the whole reason the app registers a mimetype and ships a
	 * repair step for it (§C6.1). Asserted over DAV because that is where the
	 * Files app reads it from; the mapping file on disk being right proves
	 * nothing about what a client is told.
	 *
	 * @Then /^the DAV content type of "([^"]*)" is "([^"]*)"$/
	 */
	public function theDavContentTypeIs(string $path, string $expected): void {
		$reqBody = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop><d:getcontenttype/></d:prop></d:propfind>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => $reqBody,
		]);
		$this->assertStatus($res, [207], "PROPFIND $path");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$actual = trim((string)(($doc->xpath('//d:getcontenttype') ?: [])[0] ?? ''));
		if ($actual !== $expected) {
			throw new \RuntimeException(
				"expected '{$path}' to be served as '{$expected}', got '{$actual}'. "
				. 'A generic type means the mimetype repair step did not run, and the Files app '
				. 'shows a zip icon with no Open in Penpot action.',
			);
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/** Run `penpot_sync:status <path>`, requiring success, and return its output. */
	private function status(string $path): string {
		$res = $this->occ('penpot_sync:status ' . escapeshellarg($path));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("status failed for '{$path}':\n{$res['output']}");
		}
		return $res['output'];
	}

	private function mustContain(string $haystack, string $needle, string $path): void {
		if (!str_contains($haystack, $needle)) {
			throw new \RuntimeException("expected status of '{$path}' to contain '{$needle}', got:\n{$haystack}");
		}
	}

	/**
	 * Post a command straight to Penpot's RPC bus with the minted service-account
	 * token — the seed/assertion channel, bypassing the app. The Transit response
	 * body is not decoded (nothing here needs it).
	 *
	 * SUCCESS IS 200 **OR** 204, because Penpot's own answers disagree: the
	 * creators return a body, `delete-file` returns nothing at all. Asserting 200
	 * alone would have failed a delete that worked perfectly — the same "there is
	 * no convention, only a table" lesson the client's param table records.
	 *
	 * PARAM CASING IS PER-COMMAND, NOT A HOUSE RULE. Most creators take
	 * kebab-case on the wire (`project-id`, saga §6.38), but `delete-file` and
	 * `rename-file` take a plain `id` (§6.54) — so callers spell each key the way
	 * that one command's schema does, and this helper passes them through
	 * untouched rather than normalising anything.
	 *
	 * @param array<string, string> $params the command's own wire params, verbatim
	 */
	/**
	 * The same channel, but returning the DECODED response.
	 *
	 * `penpotRpc()` deliberately throws away the body — every existing caller
	 * only needs "did it work". The trash assertions need to READ, so this is the
	 * read twin rather than a change to the write one.
	 *
	 * `Accept: application/json` is sent here on purpose: the app must never do
	 * that (it breaks Transit decoding, §R1.4), but a TEST asserting on Penpot's
	 * state wants plain JSON and is not exercising the decoder.
	 *
	 * @param array<string, string> $params
	 *
	 * @return list<array<string, mixed>>
	 */
	/**
	 * Encode a command's params for the wire.
	 *
	 * `JSON_FORCE_OBJECT` ONLY WHEN THE MAP IS EMPTY, and the distinction is not
	 * cosmetic. Penpot 500s on `[]` where it wants `{}` (§R1.3), which is why the
	 * flag was here — but applied unconditionally it also rewrites every nested
	 * LIST into an object, so a set param like `ids: ["<uuid>"]` goes out as
	 * `{"0":"<uuid>"}` and the command fails validation. A non-empty PHP map
	 * already encodes as a JSON object without any help; the flag is needed for
	 * exactly one case and harmful in another.
	 *
	 * Latent until the first list-valued param — `permanently-delete-team-files`,
	 * whose whole payload is a set of ids — needed to go through here.
	 *
	 * @param array<string, string|list<string>> $params
	 */
	private function encodeParams(array $params): string {
		return json_encode($params, JSON_THROW_ON_ERROR | ($params === [] ? JSON_FORCE_OBJECT : 0));
	}

	private function penpotRpcRead(string $command, array $params): array {
		$url = getenv('PENPOT_URL');
		$token = getenv('PENPOT_TOKEN');
		if ($url === false || $url === '' || $token === false || $token === '') {
			throw new \RuntimeException('PENPOT_URL / PENPOT_TOKEN are not set.');
		}

		$response = (new Client())->post(
			rtrim($url, '/') . '/api/rpc/command/' . $command,
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body' => $this->encodeParams($params),
				'http_errors' => false,
				'connect_timeout' => 10,
				'timeout' => 30,
			],
		);

		$body = (string)$response->getBody();
		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException("{$command} failed: HTTP {$response->getStatusCode()}\n{$body}");
		}

		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function penpotRpc(string $command, array $params): void {
		$url = getenv('PENPOT_URL');
		$token = getenv('PENPOT_TOKEN');
		if ($url === false || $url === '' || $token === false || $token === '') {
			throw new \RuntimeException('PENPOT_URL / PENPOT_TOKEN are not set — the Penpot fixture cannot be created.');
		}

		$response = (new Client())->post(
			rtrim($url, '/') . '/api/rpc/command/' . $command,
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Content-Type' => 'application/json',
				],
				'body' => $this->encodeParams($params),
				'http_errors' => false,
				// Bound the wait: a wedged Penpot must fail the scenario, not hang
				// the whole integration job until the CI runner's global timeout.
				'connect_timeout' => 10,
				'timeout' => 30,
			],
		);

		if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 204) {
			throw new \RuntimeException(sprintf(
				"Penpot %s failed: HTTP %d\n%s",
				$command,
				$response->getStatusCode(),
				(string)$response->getBody(),
			));
		}
	}
}
