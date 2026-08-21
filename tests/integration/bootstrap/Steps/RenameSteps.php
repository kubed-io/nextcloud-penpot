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
	 * @When /^I rename the file to "([^"]*)"$/
	 */
	public function iRenameTheFileTo(string $filename): void {
		$from = $this->currentFile();
		$to = $this->currentFolder . '/' . $filename;
		$this->davMove($from, $to);
		$this->currentFilePath = $to;
	}

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
		$this->penpotRpc('rename-file', ['id' => $id, 'name' => $name]);
		$this->theAdminRunsAPull();
	}

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
}
