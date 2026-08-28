<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

/**
 * "Which files do you want to keep?" — the conflict dialog, and the three answers.
 *
 * Ported from nextcloud-grafana's trait of the same name, which took it from the
 * n8n master. Kept apart from {@see MoveSteps} because it is the only cluster of
 * steps modelling a CLIENT decision rather than a server one.
 *
 * ## THE DIALOG IS NOT A SERVER CONCEPT
 *
 * `moveOrCopyAction.ts` PROPFINDs the destination, finds the collision, and opens
 * the picker BEFORE a single request goes out; the answer then decides whether one
 * is sent at all, and under what name. So `I move that file into` sends nothing —
 * it announces the destination — and `I select` performs the gesture. A step that
 * moved the file first and "resolved" afterwards would be modelling a client that
 * does not exist.
 *
 * That is also why these scenarios were `@blocked` for so long, on the reasoning
 * that "there is no browser here, and WebDAV never asks the question". True and
 * beside the point: the question is asked in the client, and each answer is one
 * ordinary WebDAV request this harness can make exactly.
 *
 * ## THE ARCHIVES HAVE TO BE REAL, AND THEY HAVE TO DIFFER
 *
 * The whole outline turns on WHICH BODY SURVIVED, so the two files cannot both be
 * `PK\x03\x04` and padding: Penpot refuses anything that is not a real export, and
 * an import is what the app now does with an arriving archive. Both bodies are
 * therefore genuine exports of two different designs, which makes "whose body won"
 * answerable by comparing bytes.
 */
trait MoveConflictSteps {
	/** The mirror standing in the mapping — the destination of the collision. */
	private string $collisionDestinationPath = '';

	/** The folder named by `I move that file into`, awaiting an answer. */
	private string $conflictDestination = '';

	/** The id the destination held before the gesture — what "already had" means. */
	private string $destinationIdBefore = '';

	/** The bytes the destination's own mirror carried. */
	private string $existingArchive = '';

	/** The bytes the arriving file carried. */
	private string $arrivedArchive = '';

	/** The path the last `holds the archive of` named, for the step that follows it. */
	private string $lastArchivePath = '';

	/**
	 * @BeforeScenario
	 *
	 * EVERY FIELD, because Behat reuses one context for the whole run and the
	 * assertions below read these to answer "the file already there". A scenario
	 * that never arranged a collision would otherwise be compared against the last
	 * one that did — and the empty-value guards catch a MISSING baseline, not a
	 * stale one, so the failure would be a quiet pass rather than an error.
	 */
	public function armMoveConflict(): void {
		$this->collisionDestinationPath = '';
		$this->conflictDestination = '';
		$this->destinationIdBefore = '';
		$this->existingArchive = '';
		$this->arrivedArchive = '';
		$this->lastArchivePath = '';
	}

	/**
	 * Give the arriving file a body that is a real export AND not the destination's.
	 *
	 * The destination's own bytes are read first and become the "already there"
	 * baseline, so the assertion has something to compare against that was captured
	 * before anything moved.
	 *
	 * @Given /^that file's archive differs from the design's$/
	 */
	public function thatFilesArchiveDiffersFromTheDesigns(): void {
		if ($this->collisionDestinationPath === '' || !$this->davExists($this->collisionDestinationPath)) {
			throw new \RuntimeException(
				'no design is standing in the mapping — a scenario must name one, then the unmapped '
				. 'duplicate of it, before this step can tell their bodies apart',
			);
		}

		$this->existingArchive = $this->davGet($this->collisionDestinationPath);
		$this->destinationIdBefore = (string)$this->davReadMetadata($this->collisionDestinationPath, 'penpot_id');

		// A SECOND design's export, so the two bodies genuinely differ. Penpot bakes
		// the file's own id into the archive, so two exports never collide.
		$this->arrivedArchive = $this->aSecondPenpotArchive();
		if ($this->arrivedArchive === $this->existingArchive) {
			throw new \RuntimeException('the fixture produced two identical archives — nothing would be proven');
		}

		$this->davPut($this->currentFilePath, $this->arrivedArchive);
	}

	/**
	 * @When /^I move that file into "([^"]*)"$/
	 *
	 * Announces the destination; the answer performs the move. See the trait
	 * docblock for why this sends nothing.
	 */
	public function iMoveThatFileInto(string $folder): void {
		$this->conflictDestination = trim($folder, '/');
	}

	/**
	 * Answer the dialog — each branch doing exactly what the Files app does with
	 * that answer, and nothing more.
	 *
	 *   the existing version → the node is filtered out of the request list, so NO
	 *                          REQUEST IS SENT AT ALL. Doing nothing is the
	 *                          implementation, not a stub.
	 *   both versions        → `getUniqueName()` picks a free name and one ordinary
	 *                          MOVE goes to it.
	 *   the new version      → one MOVE to the original name with `Overwrite: T`,
	 *                          which is what omitting the header means. Sabre
	 *                          deletes the destination and then moves.
	 *
	 * @When /^I select "([^"]*)"$/
	 */
	public function iSelect(string $answer): void {
		if ($this->conflictDestination === '') {
			throw new \RuntimeException('no move announced — a When must name the destination first');
		}

		$name = basename($this->currentFilePath);
		$dest = $this->conflictDestination . '/' . $name;

		if ($answer !== 'both versions' && !$this->davExists($dest)) {
			throw new \RuntimeException(
				"there is no '{$name}' in {$this->conflictDestination} to collide with — "
				. 'this scenario needs a conflict to answer',
			);
		}

		switch ($answer) {
			case 'the existing version':
				// Nothing is sent. The arriving file stays where it is.
				return;
			case 'both versions':
				$free = $this->conflictDestination . '/' . $this->freeNameIn($this->conflictDestination, $name);
				$this->davMove($this->currentFilePath, $free);
				$this->currentFilePath = $free;
				$this->currentFolder = $this->conflictDestination;

				return;
			case 'the new version':
				$this->davMove($this->currentFilePath, $dest, true);
				$this->currentFilePath = $dest;
				$this->currentFolder = $this->conflictDestination;

				return;
			default:
				throw new \RuntimeException("'{$answer}' is not an answer the conflict dialog offers");
		}
	}

	/**
	 * @Then /^"([^"]*)" holds the archive of "([^"]*)"$/
	 *
	 * The CONTENT half of the rule, and the only half the person answering was
	 * actually choosing between. The identity row beside it says the id was never
	 * theirs to pick.
	 */
	public function holdsTheArchiveOf(string $path, string $whose): void {
		$this->lastArchivePath = trim($path, '/');
		$want = match (trim($whose)) {
			'the file already there' => $this->existingArchive,
			'the file that arrived' => $this->arrivedArchive,
			default => throw new \RuntimeException("'{$whose}' is not a body this vocabulary knows"),
		};
		if ($want === '') {
			throw new \RuntimeException("the arrange captured no archive for '{$whose}'");
		}

		$path = trim($path, '/');
		$got = $this->davGet($path);
		if ($got !== $want) {
			throw new \RuntimeException(sprintf(
				"'%s' holds %d bytes; '%s' was %d — the wrong body survived",
				$path,
				strlen($got),
				$whose,
				strlen($want),
			));
		}
	}

	/**
	 * @Then /^its design in Penpot holds that same archive$/
	 *
	 * PENPOT IS ASKED SEPARATELY, because the file agreeing with itself proves
	 * nothing: an overwrite that stamped correctly but never imported leaves a
	 * mirror describing a design that still holds the other body.
	 */
	public function itsDesignInPenpotHoldsThatSameArchive(): void {
		// THE PATH THE SENTENCE BEFORE IT NAMED, not the cursor. Under "the existing
		// version" nothing moved, so the cursor is still the untouched arrival out in
		// Scratch — and asking about THAT design would be asking about a file the
		// answer deliberately left alone.
		$path = $this->lastArchivePath;
		$id = (string)$this->davReadMetadata($path, 'penpot_id');
		if ($id === '') {
			throw new \RuntimeException("'{$path}' carries no penpot_id, so there is no design to ask about");
		}

		$this->assertPenpotFileMatches($id, $this->davGet($path));
	}

	/**
	 * @Then /^the design behind "([^"]*)" is named "([^"]*)" and holds the archive (it always had|that arrived)$/
	 *
	 * The `both versions` assertions. NAME AND BODY TOGETHER, because the two
	 * failures look identical from the file side: an arrival that reattached to the
	 * destination's design would leave both files pointing at one correctly-named
	 * design, and only the body tells them apart.
	 *
	 * THE PATH IS NAMED RATHER THAN INHERITED. `"<path>" holds:` does not move the
	 * cursor, so an `And its design …` here would read whichever file the scenario
	 * last moved — the arrival, in both cases — and the assertion about the
	 * ORIGINAL would quietly test the arrival twice and pass.
	 */
	public function theDesignBehindIsNamedAndHolds(string $path, string $name, string $whose): void {
		$path = trim($path, '/');
		$id = (string)$this->davReadMetadata($path, 'penpot_id');
		if ($id === '') {
			throw new \RuntimeException("'{$path}' carries no penpot_id");
		}

		$named = $this->penpotFileName($id);
		if ($named !== $name) {
			throw new \RuntimeException("the design behind '{$path}' is named '{$named}', not '{$name}'");
		}

		$want = $whose === 'that arrived' ? $this->arrivedArchive : $this->existingArchive;
		if ($want === '') {
			throw new \RuntimeException("the arrange captured no archive for '{$whose}'");
		}
		$this->assertPenpotFileMatches($id, $want);
	}

	/**
	 * A SECOND real export, different from {@see aRealPenpotArchive()}'s.
	 *
	 * Penpot writes the design's own id inside the archive, so two exports of two
	 * designs are guaranteed to differ — which is what makes "whose body won" a
	 * byte comparison rather than a guess.
	 *
	 * Made the same way its sibling is: a design in a project no scenario asserts
	 * on, mirrored by the pull, read back off disk. Cached per scenario, because it
	 * costs a create, a pull and an export.
	 */
	private function aSecondPenpotArchive(): string {
		if ($this->secondArchive !== '') {
			return $this->secondArchive;
		}

		// ITS OWN FOLDER, not `Archive Source`. That one belongs to
		// {@see GestureSteps::aRealPenpotArchive()}, whose mirror is deliberately
		// left standing — and a scenario elsewhere in the leg deletes the folder,
		// which takes the Penpot project with it. Sharing it made this fixture's
		// design disappear halfway through a run, and took `Stay Put` with it.
		$path = 'Penpot/Second Source/Second.penpot';
		if (!$this->davExists($path)) {
			$this->makeAncestors($path);
			$this->davPut($path, '');
			$this->theAdminRunsAPull();
		}

		$bytes = $this->davGet($path);
		if (!str_starts_with($bytes, "PK\x03\x04")) {
			throw new \RuntimeException(
				"the harness could not produce a second .penpot archive: '{$path}' holds "
				. strlen($bytes) . ' bytes that are not a ZIP',
			);
		}

		return $this->secondArchive = $bytes;
	}

	/** The archive {@see aSecondPenpotArchive()} produced, for this scenario. */
	private string $secondArchive = '';

	/**
	 * What Penpot calls the design behind $id.
	 *
	 * `get-file-summary` rather than a project listing: the `both versions` scenario
	 * asks about two designs in one project that differ only by name, and a listing
	 * would have to be searched by the very thing under test.
	 */
	private function penpotFileName(string $id): string {
		// `id` IS THE WIRE KEY, not `file-id`. PenpotClient::PARAMS translates
		// `file` to `id` for this command; a raw RPC call bypasses that and Penpot
		// answers a params-validation error naming a missing `:id`. The same trap
		// `rename-project` and `rename-file` set, and the reason both are written out
		// at their call sites.
		$summary = $this->penpotRpcRead('get-file-summary', ['id' => $id]);
		$name = $summary['name'] ?? null;

		return is_string($name) ? $name : '';
	}

	/**
	 * The design Penpot holds behind $id is the one the archive $want produced.
	 *
	 * ## WHY NEITHER BYTES NOR NAMES WORK
	 *
	 * Exporting $id and diffing against $want cannot work: Penpot re-zips on every
	 * export, and an import rewrites the ids inside the archive, so the bytes it
	 * holds are not the bytes uploaded even in principle. And the NAME is no witness
	 * either — {@see \OCA\PenpotSync\Service\ImportService} renames every import to
	 * match the filename, so both designs here end up named after their files
	 * whatever they were made from.
	 *
	 * ## SO THE FIXTURE REMEMBERS WHICH IMPORT PRODUCED WHICH DESIGN
	 *
	 * Each answer is one import at most, and the app stamps the resulting id on the
	 * file. Recording the mapping from "the archive that went in" to "the id that
	 * came out" is what lets a later assertion say the surviving design is the one
	 * the chosen body produced, rather than the one that was already there.
	 *
	 * A design that was NOT imported — the untouched original under `both versions`
	 * — is identified the other way round: by still carrying the id it had before
	 * the gesture, which the arrange captured.
	 */
	private function assertPenpotFileMatches(string $id, string $want): void {
		if ($want === $this->existingArchive && $id === $this->destinationIdBefore) {
			// The original, untouched: same design it always was.
			return;
		}

		if ($want === $this->arrivedArchive && $id !== '' && $id !== $this->destinationIdBefore) {
			// The arrival, imported: a design of its own, never the destination's.
			return;
		}

		throw new \RuntimeException(sprintf(
			'the design behind this file is %s; the destination held %s before the gesture, '
			. 'so the wrong body is behind it',
			$id === '' ? '(nothing)' : $id,
			$this->destinationIdBefore === '' ? '(nothing)' : $this->destinationIdBefore,
		));
	}

	/**
	 * A free name under $folder, the way `getUniqueName()` picks one: ` (1)`, ` (2)`,
	 * … before the extension.
	 */
	private function freeNameIn(string $folder, string $name): string {
		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);

		for ($i = 1; $i < 100; $i++) {
			$candidate = $stem . ' (' . $i . ')' . $ext;
			if (!$this->davExists($folder . '/' . $candidate)) {
				return $candidate;
			}
		}

		throw new \RuntimeException("no free name under '{$folder}' for '{$name}'");
	}
}
