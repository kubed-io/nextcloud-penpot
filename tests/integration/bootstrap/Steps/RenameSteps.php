<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * A name is ONE value living in two places, and these steps are the two places.
 *
 * A design's filename (minus the `.penpot` Penpot never carries, §6.4) and the
 * design's name in Penpot are the same fact. So a rename made on either side has
 * to reach the other, and every scenario here is one direction of that.
 *
 * ## WHY THE VERBS TAKE NO PATH
 *
 * `I rename the file to "New Name.penpot"` names no folder, on purpose. The
 * scenario said where the file was once, in its `Given`, and repeating it in the
 * `When` and again in three `Then`s is how the old vocabulary made a four-row
 * outline unreadable. The cursor lives in {@see ArrangeSteps} and is re-resolved
 * BY ID, which matters most for the Penpot direction: a rename that arrives
 * through a pull picks its own filename, so nothing in the Gherkin could name the
 * new path even if it wanted to.
 *
 * ## WHY THE PENPOT DIRECTION PULLS
 *
 * Penpot has no webhook this app listens to (#19 stays unexplained), so news from
 * Penpot travels exactly one way: the next sync. `someone renames the design …`
 * therefore renames it over the RPC bus and then runs a pull, because that pair
 * IS the behaviour — the scenario is not "Penpot renamed it" but "a rename in
 * Penpot reaches Nextcloud", and the pull is how.
 */
trait RenameSteps {
	/** The status and body of the last refused rename, for its assertion to read. */
	private int $lastRenameStatus = 0;
	private string $lastRenameBody = '';

	/**
	 * The gesture, on the file the scenario has on stage.
	 *
	 * A rename is a MOVE to a sibling path — same DAV verb, same Nextcloud event.
	 * Telling a rename from a move is the listener's job, not the transport's.
	 *
	 * IT SNAPSHOTS PENPOT'S DESIGN NAMES FIRST, so the untracked-file scenarios can
	 * say "no design is renamed in Penpot" without the spec growing a sentence that
	 * exists only for the harness. Cheap — one probe — and taken on every rename
	 * rather than only where it is read, because a `When` that behaves differently
	 * depending on the `Then` after it is the harder thing to reason about.
	 *
	 * @When /^I rename the file to "([^"]*)"$/
	 */
	public function iRenameTheFileTo(string $filename): void {
		$this->designNamesBeforeRename = $this->penpotDesignNames();
		$from = $this->currentFile();
		$to = $this->currentFolder . '/' . $filename;
		$this->davMove($from, $to);
		$this->currentFilePath = $to;
	}

	/**
	 * Penpot's design names before a rename, so one that should not have travelled
	 * can be shown not to have.
	 *
	 * @var list<string>
	 */
	private array $designNamesBeforeRename = [];

	/**
	 * The same gesture, expected to be REFUSED.
	 *
	 * Split from the plain form for the same reason `I try to move` is split from
	 * `I move`: a refusal's interesting result is the response, and a step that
	 * threw on a non-2xx could never assert one.
	 *
	 * @When /^I try to rename the file to "([^"]*)"$/
	 */
	public function iTryToRenameTheFileTo(string $filename): void {
		$from = $this->currentFile();
		$result = $this->davMoveResult($from, $this->currentFolder . '/' . $filename);
		$this->lastRenameStatus = $result['status'];
		$this->lastRenameBody = $result['body'];
	}

	/**
	 * A refusal has to SAY SOMETHING, which is the half that was broken.
	 *
	 * Both of this app's refusals were invisible until #32: the listener threw a
	 * message written for exactly that moment and the person dragging the file got
	 * a 403 with an empty body. So the status alone is not the claim — a scenario
	 * saying "refused with a message" is asserting the repair still holds.
	 *
	 * @Then /^the rename is refused with a message$/
	 */
	public function theRenameIsRefusedWithAMessage(): void {
		if ($this->lastRenameStatus < 400 || $this->lastRenameStatus >= 500) {
			throw new \RuntimeException(
				"expected the rename to be refused, got HTTP {$this->lastRenameStatus}",
			);
		}
		// Sabre wraps the reason in a DAV error document; the message is the text
		// inside it, so tags are stripped rather than parsed — any of core's
		// exception shapes then reads the same.
		$message = trim(strip_tags($this->lastRenameBody));
		if ($message === '') {
			throw new \RuntimeException(
				"the rename was refused with HTTP {$this->lastRenameStatus} but an EMPTY body — "
				. 'that is the #32 bug, where the reason reached the log and never the client.',
			);
		}
	}

	/**
	 * @Then /^the file is named "([^"]*)"$/
	 */
	public function theFileIsNamed(string $filename): void {
		$actual = basename($this->currentFile());
		if ($actual !== $filename) {
			throw new \RuntimeException("expected the file to be named '{$filename}', found '{$actual}'");
		}
	}

	/**
	 * The other half of the same fact, read out of Penpot.
	 *
	 * ASSERTED BY ID, NOT BY NAME. Asking whether Penpot holds *a* design called
	 * "New Name" cannot tell a rename from a delete-and-recreate, and that
	 * distinction is the whole point of the scenario — so this resolves the name of
	 * the design the file has always pointed at.
	 *
	 * @Then /^the design is named "([^"]*)" in Penpot$/
	 */
	public function theDesignIsNamedInPenpot(string $name): void {
		$id = $this->currentFileId;
		if ($id === '') {
			throw new \RuntimeException('no design is on stage to check the name of');
		}
		$actual = $this->penpotDesignNameById($id);
		if ($actual === null) {
			throw new \RuntimeException("Penpot no longer holds a design with the id {$id}");
		}
		if ($actual !== $name) {
			throw new \RuntimeException(
				"expected the design {$id} to be named '{$name}' in Penpot, found '{$actual}'",
			);
		}
	}

	/**
	 * A rename made in Penpot, and the sync that carries the news.
	 *
	 * @When /^someone renames the design to "([^"]*)" in Penpot$/
	 */
	public function someoneRenamesTheDesignToInPenpot(string $name): void {
		if ($this->currentFileId === '') {
			throw new \RuntimeException('no design is on stage to rename in Penpot');
		}
		// `id`, NOT `file`. PenpotClient::renameFile() passes `file`, but that is its
		// INTERNAL name: `PenpotClient::PARAMS` translates it to the wire key `id`
		// on the way out. This harness posts to the RPC bus directly, so it has to
		// speak the wire — the same way the seeding path already says `project-id`
		// rather than `project`.
		//
		// Penpot told us so itself, in a schema, which is the only reason this is
		// written down rather than guessed twice:
		//
		//   RenameFileParams [:map [:name [:string …]] [:id :app.common.schema/uuid]]
		//   {:path [:id], :type :malli.core/missing-key}
		//   Value: {:name "New Name", :file "9789fcd3-…"}
		$this->penpotRpc('rename-file', ['id' => $this->currentFileId, 'name' => $name]);
		$this->theAdminRunsAPull();
		// The pull chose the filename, so the cursor has to be re-found by id.
		$this->currentFilePath = '';
	}

	/**
	 * A rename in Penpot onto a name a SIBLING already has.
	 *
	 * Named rather than cursored because two designs are on stage and "the design"
	 * would be ambiguous — the same reason the arrange remembers ids by filename.
	 *
	 * @When /^someone renames the "([^"]*)" design to "([^"]*)" in Penpot$/
	 */
	public function someoneRenamesTheNamedDesignToInPenpot(string $which, string $name): void {
		$id = $this->declaredDesignIds[$which . '.penpot'] ?? '';
		if ($id === '') {
			throw new \RuntimeException("no design named '{$which}' is on stage");
		}
		// REMEMBERED BEFORE THE PULL, because afterwards this design's name is one
		// two files answer to and its id cannot be looked up by name any more. See
		// `the id of the renamed design` in MetadataSteps.
		$this->idOfRenamedDesign = $id;
		$this->penpotRpc('rename-file', ['id' => $id, 'name' => $name]);
		$this->theAdminRunsAPull();
	}

	/** The design {@see someoneRenamesTheNamedDesignToInPenpot()} last renamed. */
	private string $idOfRenamedDesign = '';

	/**
	 * @Then /^"([^"]*)" holds no file named "([^"]*)"$/
	 */
	public function holdsNoFileNamed(string $folder, string $filename): void {
		$path = trim($folder, '/') . '/' . $filename;
		if ($this->davExists($path)) {
			throw new \RuntimeException("expected no file at '{$path}', but it is there");
		}
	}

	/**
	 * ONE DESIGN, ONE FILE — the claim a rename is most likely to break.
	 *
	 * A pull that renamed by creating instead of moving would leave the old
	 * filename beside the new one, both carrying the same Penpot id, and every
	 * other assertion in the scenario would still pass: the new name is there, the
	 * old name is gone from the *cursor's* point of view, and the metadata is
	 * right. Counting is the only thing that catches it.
	 *
	 * @Then /^there is exactly one file for that design$/
	 */
	public function thereIsExactlyOneFileForThatDesign(): void {
		$id = $this->currentFileId;
		$found = [];
		foreach ($this->davChildren($this->currentFolder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			if (($this->davReadMetadata($child, 'penpot_id') ?? '') === $id) {
				$found[] = basename($child);
			}
		}
		if (count($found) !== 1) {
			throw new \RuntimeException(sprintf(
				"expected exactly one file for the design %s under '%s', found %d: %s",
				$id,
				$this->currentFolder,
				count($found),
				implode(', ', $found) ?: '(none)',
			));
		}
	}

	/**
	 * The metadata table, on the file the scenario has on stage.
	 *
	 * Delegates to {@see MetadataSteps::holds()} rather than re-implementing the
	 * vocabulary: `the file holds:` and `"X" holds:` are the same assertion with
	 * and without a path, and two checkers would be two ways for the same row to
	 * drift.
	 *
	 * @Then /^the file holds:$/
	 */
	public function theFileHolds(TableNode $table): void {
		$this->holds($this->currentFile(), $table);
	}

	/**
	 * The name Penpot currently has for a design id, or null when it has none.
	 *
	 * Reads `probe --files`, whose design lines are `  <name>  revn=<n>  <uuid>` —
	 * the `revn=` is what tells a design line from the project line above it, which
	 * ends in `[<team>]`. Same parse as {@see PruneSteps::fileIdNamed()}, in the
	 * other direction.
	 */
	private function penpotDesignNameById(string $id): ?string {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while looking up the design {$id}:\n{$res['output']}");
		}
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^\s+(.*?)\s+revn=\S+\s+' . preg_quote($id, '/') . '\s*$/', $line, $m) === 1) {
				return trim($m[1]);
			}
		}
		return null;
	}

	/**
	 * A folder still holds a plain file of the user's.
	 *
	 * THE POINT IS THAT NOTHING WAS RE-MADE. A project folder renamed from either
	 * side must be the SAME folder afterwards, carrying everything that was in it —
	 * including files this app has no opinion about. A rename implemented as
	 * create-new-and-move-the-designs would satisfy every id assertion in the
	 * scenario and quietly drop `Budget.xlsx`, so this row is the one that notices.
	 *
	 * @Then /^"([^"]*)" holds "([^"]*)"$/
	 */
	public function folderHolds(string $folder, string $name): void {
		$path = trim($folder, '/') . '/' . $name;
		if (!$this->davExists($path)) {
			throw new \RuntimeException(
				"expected '{$folder}' to still hold '{$name}', but there is nothing at '{$path}'",
			);
		}
	}

	/**
	 * A project renamed in Penpot, and the sync that carries the news.
	 *
	 * "That project" is the one the arrange most recently put on stage — the same
	 * referent `the original id` uses for a folder, and for the same reason: a
	 * project's path is what the gesture changes, so it cannot be the thing that
	 * identifies it.
	 *
	 * `id` is the wire key, NOT `project`. `PenpotClient::renameProject()` passes
	 * `project` and `PenpotClient::PARAMS` translates it — the same trap
	 * `rename-file` set, written out again here because the next person will copy
	 * one of these two lines.
	 *
	 * @When /^someone renames that project to "([^"]*)" in Penpot$/
	 */
	public function someoneRenamesThatProjectToInPenpot(string $name): void {
		$id = $this->declaredProjectIds[$this->lastDeclaredProject] ?? '';
		if ($id === '') {
			throw new \RuntimeException('no project is on stage to rename in Penpot');
		}
		$this->penpotRpc('rename-project', ['id' => $id, 'name' => $name]);
		$this->theAdminRunsAPull();
	}
	/**
	 * A rename this app was never entitled to propagate.
	 *
	 * ASSERTED ACROSS THE WHOLE INSTANCE rather than in one project, because an
	 * untracked file names no project — that is the whole point of it. The names
	 * before the gesture are snapshotted by the rename step itself for the same
	 * reason `no design is created in Penpot` snapshots ids: there is no sentence
	 * in the spec where a "before" would belong.
	 *
	 * @Then /^no design is renamed in Penpot$/
	 */
	public function noDesignIsRenamedInPenpot(): void {
		$now = $this->penpotDesignNames();
		$added = array_values(array_diff($now, $this->designNamesBeforeRename));
		$gone = array_values(array_diff($this->designNamesBeforeRename, $now));
		if ($added !== [] || $gone !== []) {
			throw new \RuntimeException(sprintf(
				"Penpot's design names changed across a rename it should not have seen — gained [%s], lost [%s]",
				implode(', ', $added),
				implode(', ', $gone),
			));
		}
	}

	/**
	 * Nothing this app stores is on the file — not a stale id, not a mode.
	 *
	 * STRONGER THAN "no penpot_id". A file the app declined to track must carry
	 * NONE of its keys: a lone `penpot_mode` left behind would make the file read
	 * as managed-but-broken to every later walk, which is the shape of bug this
	 * assertion exists to catch.
	 *
	 * @Then /^the file holds no Penpot metadata at all$/
	 * @Then /^it still holds no Penpot metadata$/
	 */
	public function theFileHoldsNoPenpotMetadataAtAll(): void {
		$path = $this->currentFile();
		$found = [];
		foreach (['penpot_id', 'penpot_mode', 'penpot_team_id', 'penpot_revision', 'penpot_project_id'] as $key) {
			$value = $this->davReadMetadata($path, $key) ?? '';
			if ($value !== '') {
				$found[] = "{$key}={$value}";
			}
		}

		if ($found !== []) {
			throw new \RuntimeException(
				"'{$path}' was supposed to carry nothing of this app's, and holds: " . implode(', ', $found),
			);
		}
	}

	/**
	 * Every design name Penpot can see, across every team the probe lists.
	 *
	 * @return list<string>
	 */
	private function penpotDesignNames(): array {
		$res = $this->occ('penpot_sync:probe --files');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("probe failed while listing Penpot's designs:\n{$res['output']}");
		}

		$names = [];
		foreach (explode("\n", $res['output']) as $line) {
			if (preg_match('/^\s+(.*?)\s+revn=\S+\s+[0-9a-f-]{36}\s*$/', $line, $m) === 1) {
				$names[] = trim($m[1]);
			}
		}

		return $names;
	}
	/**
	 * Penpot is perfectly happy with two designs wearing one name.
	 *
	 * THE OTHER HALF OF "THE SUFFIX IS NEXTCLOUD'S ALONE". The files are
	 * `Alpha.penpot` and `Alpha (1).penpot` because a folder cannot hold two files
	 * of one name; the designs behind them are both `Alpha`, and a suffix that
	 * leaked upstream would be this app inventing a rename nobody asked for.
	 *
	 * Asserted BY ID off the two files on stage, so it cannot pass by finding some
	 * other `Alpha` an earlier scenario left in the team.
	 *
	 * @Then /^both designs are named "([^"]*)" in Penpot$/
	 */
	public function bothDesignsAreNamedInPenpot(string $name): void {
		$ids = [];
		foreach ($this->davChildren($this->currentFolder) as $child) {
			if (!str_ends_with($child, '.penpot')) {
				continue;
			}
			$id = $this->davReadMetadata($child, 'penpot_id') ?? '';
			if ($id !== '') {
				$ids[$child] = $id;
			}
		}

		if (count($ids) !== 2) {
			throw new \RuntimeException(sprintf(
				"expected two tracked designs in '%s', found %d: %s",
				$this->currentFolder,
				count($ids),
				implode(', ', array_keys($ids)) ?: '(none)',
			));
		}

		$wrong = [];
		foreach ($ids as $path => $id) {
			$named = $this->penpotDesignNameById($id);
			if ($named !== $name) {
				$wrong[] = "{$path} -> '" . ($named ?? '(gone)') . "'";
			}
		}

		if ($wrong !== []) {
			throw new \RuntimeException(
				"both designs should still be '{$name}' in Penpot; Nextcloud's suffix reached it: "
				. implode(', ', $wrong),
			);
		}
	}
}
