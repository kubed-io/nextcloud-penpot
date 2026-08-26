<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * What a mirror holds, as one table.
 *
 * ## WHY THIS EXISTS
 *
 * Metadata is not a subject. It is the END STATE of whatever was just done — so
 * the useful question is never "does this file have a penpot_id", it is "I did
 * X; what does the mirror hold now". A column of single-fact assertions cannot
 * answer that: it shows what someone thought to check and says nothing about the
 * keys they left out.
 *
 * ## AN END STATE IS ABSOLUTE, NOT A DIFF
 *
 * An earlier cut of this trait offered `unchanged` / `changed`, backed by a
 * `Given I note the state of "…"`. That step was an ACTION wearing a `Given` —
 * harness bookkeeping leaking into the specification, in the one position that
 * is supposed to say only how the world already is.
 *
 * So a row names the thing the value came FROM instead: `the design's id` is
 * resolved out of Penpot by name, at assertion time, against the design the
 * `Given` already named. That says what "the id survived a rename" was reaching
 * for, and says it better — the id is not merely different-from-before, it is
 * *that design's*.
 *
 * ## THE VOCABULARY IS DELIBERATELY SMALL
 *
 * A table that can say anything stops being readable, so a value is one of:
 *
 *   the design's id     resolved from Penpot by the file's own name. Presence is
 *                       too weak for an id — one that is merely non-empty could be
 *                       any design's, and naming THIS one is the whole point.
 *   set                 present and non-empty. For genuinely opaque bookkeeping —
 *                       the revision is `revn` and `modified-at` joined (§5.5), and
 *                       pinning a literal would assert the engine's internals.
 *   absent              not stored at all. An assertion in its own right: a file
 *                       deliberately carries no copy of its project (§C6.7).
 *   an archive          real ZIP bytes on disk.
 *   empty               zero bytes. A `link` holds nothing (§C6.6).
 *   the design's        for a clock: it came from Penpot, not from the sync run.
 *   "<literal>"         an exact value, quoted.
 *
 * …and three that name no value on purpose, because the point of the row is that
 * the mapping decided it. An outline runs the same gesture into a `sync` mapping,
 * a Team Folder and a `link` one; spelling `sync` here would split one claim into
 * three scenarios.
 *
 *   the mapping's team  the mapped team's id, resolved from the path.
 *   the mapping's mode  `sync`, or the `reference` a `link` stores as.
 *   the mapping's body  an archive under `sync`, zero bytes under `link`.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext}; reads
 * the metadata over DAV and the content through `occ penpot_sync:status`, so it
 * sees what a client sees rather than what the app believes.
 */
trait MetadataSteps {
	/**
	 * The properties a table may name, and how each is read.
	 *
	 * A NAME NOT IN HERE IS REFUSED rather than read as nothing. Every unknown
	 * property used to come back `null`, which made `absent` pass for it — so a
	 * table could assert something this trait had no idea how to look at and go
	 * green. `penpot_project_id` was exactly that case: `projects/create.feature`
	 * asks whether a plain folder has one, and the answer was yes-but-unreadable.
	 */
	private const READABLE = [
		'penpot_id', 'penpot_mode', 'penpot_team_id', 'penpot_revision', 'penpot_project_id', 'content',
	];

	/**
	 * The act the DAV scenarios name, which the assertion then performs.
	 *
	 * A NO-OP ON PURPOSE, and worth saying so. `the file holds:` below issues the
	 * PROPFIND itself, so putting a second one here would ask the same question
	 * twice and prove nothing about the answer. The sentence earns its place by
	 * naming WHO is asking — these scenarios are about what a plain WebDAV client
	 * can read without this app's help, and that is the whole claim.
	 *
	 * @When /^a WebDAV client requests the file's properties$/
	 */
	public function aWebDavClientRequestsTheFilesProperties(): void {
	}

	/**
	 * Assert what a mirror holds, one row per property.
	 *
	 * @Then /^"([^"]*)" holds:$/
	 */
	public function holds(string $path, TableNode $table): void {
		// A MISSING PATH IS A DIAGNOSTIC, NOT A 404. Without this the failure is
		// Sabre's "File with name … could not be located", which says nothing about
		// WHY — and the answer is almost always "it is there under the name core
		// actually chose". Listing the folder turns three CI cycles into one.
		if (!$this->davExists($path)) {
			$folder = dirname($path);
			$held = [];
			foreach ($this->davChildren($folder) as $child) {
				$held[] = basename($child) . ' (' . (($this->davReadMetadata($child, 'penpot_id') ?? '') ?: 'untracked') . ')';
			}

			throw new \RuntimeException(sprintf(
				"the scenario expects '%s', and it is not there. '%s' holds: %s",
				$path,
				$folder,
				implode(', ', $held) ?: '(nothing)',
			));
		}

		// READ ONLY WHAT THE TABLE ASKS FOR. Reading the whole set eagerly meant
		// every table paid for `content`, which is resolved from `occ status` and
		// exists only for FILES — so `"Penpot/Notes" holds: | penpot_project_id |`
		// on a plain folder blew up looking for bytes nobody asked about.
		$now = $this->readState($path, array_keys($table->getRowsHash()));
		$failures = [];

		foreach ($table->getRowsHash() as $property => $expected) {
			$problem = $this->check($path, $property, $expected, $now[$property] ?? null);
			if ($problem !== null) {
				$failures[] = "  {$property}: {$problem}";
			}
		}

		if ($failures !== []) {
			throw new \RuntimeException(
				"'{$path}' is not in the state the scenario describes:\n"
				. implode("\n", $failures)
				. "\n\nwhat it actually holds:\n" . $this->describe($now),
			);
		}
	}

	/** One row. Returns a human sentence on failure, or null when it holds. */
	private function check(string $path, string $property, string $expected, ?string $actual): ?string {
		// A CLOCK IS NOT A STORED PROPERTY. `modified` / `created` are Nextcloud's
		// own times, stamped FROM Penpot's (§C6.24), so the only thing a table can
		// say about them is whose they are. Anything else is a scenario asking a
		// question this vocabulary cannot answer, and passing it quietly would be
		// worse than refusing.
		if (in_array($property, ['modified', 'created'], true)) {
			if ($expected !== "the design's") {
				throw new \RuntimeException(
					"'{$property}' can only be \"the design's\" — it is a clock stamped from Penpot, "
					. "not a value to compare. The table asked for '{$expected}'.",
				);
			}
			$this->carriesItsPenpotDates($path);
			return null;
		}

		// THE WIRE VALUE FOR `link` IS `reference`, because the literal string
		// "link" is is_callable() and crashes core's PROPFIND. That quirk is spelt
		// out once, in view-design.feature, where the DAV surface IS the subject.
		// Everywhere else a table reads in the vocabulary the admin chose.
		if ($property === 'penpot_mode' && $expected === '"link"') {
			$expected = '"reference"';
		}

		switch ($expected) {
			case 'the id it had before the rename':
			case 'the id it had before the move':
			case 'the id it had before it was trashed':
				// STRONGER THAN `the design's id`, which resolves the id of whatever
				// design now carries that name — and so cannot tell a rename from a
				// delete-and-recreate. This one pins the identity across the gesture.
				if ($this->idBeforeGesture === '') {
					return 'the gesture captured no id to compare against';
				}
				return $actual === $this->idBeforeGesture
					? null : "expected the id it already had ({$this->idBeforeGesture}), found '{$actual}'";

			case 'the id of the renamed design':
				// THE DESIGN THE SCENARIO JUST RENAMED IN PENPOT, captured by
				// {@see RenameSteps::someoneRenamesTheNamedDesignToInPenpot()} before the
				// pull moved the name out from under it.
				//
				// Needed because the arrival is the file that DID NOT keep the name:
				// two designs are called "Alpha" in Penpot, one file is
				// `Alpha.penpot` and the other `Alpha (1).penpot`, and the whole
				// claim is which id sits at which path. A by-name lookup cannot
				// answer that — both designs answer to "Alpha" — so the id has to
				// come from the gesture rather than from a listing.
				if ($this->idOfRenamedDesign === '') {
					return 'no design was renamed in Penpot for this to refer to';
				}
				return $actual === $this->idOfRenamedDesign
					? null : "expected the renamed design ({$this->idOfRenamedDesign}), found '{$actual}'";

			case 'the original id':
				// THE PATH'S OWN ID FIRST, and the cursor only as a fallback.
				//
				// `Rename a design in Penpot to a name another one already has` puts
				// TWO designs on stage and asserts both — so the cursor is whichever
				// was declared last (Beta), and reading it made
				// `"…/Alpha.penpot" holds | penpot_id | the original id |` compare
				// Alpha's file against BETA's id. The arrange already keys declared
				// designs by filename for exactly this, and
				// {@see ArrangeSteps::checkIdentity()} already resolves it that way.
				$declared = $this->declaredDesignIds[basename($path)] ?? '';
				if ($declared !== '') {
					return $actual === $declared
						? null : "expected the id it already had ({$declared}), found '{$actual}'";
				}
				// THE CURSOR'S ID, captured when the scenario put the file on stage.
				// Stronger than `the design's id`, which resolves whatever design now
				// wears that name and so cannot tell a rename from a
				// delete-and-recreate — which is the entire claim of every rename
				// scenario. {@see ArrangeSteps} for the cursor.
				if ($this->currentFileId === '') {
					return 'the arrange put no design on stage to compare against';
				}
				return $actual === $this->currentFileId
					? null : "expected the id it already had ({$this->currentFileId}), found '{$actual}'";

			case 'sync':
			case 'link':
			case 'reference':
				// A BARE MODE IS A LITERAL, in the one place that spells the wire
				// value out. Everywhere else a table says `"link"` and this trait
				// translates it to the stored `reference`; `designs/view.feature` is
				// where the DAV surface itself is the subject, so it writes what a
				// client actually reads — `sync` and `reference`, unquoted.
				$want = $expected === 'link' ? 'reference' : $expected;
				return $actual === $want
					? null : "expected '{$want}', found '{$actual}'";

			case "the mapping's mode":
				// A row that names no mode on purpose. Three mappings run the same
				// outline, and the point is that the file's mode is whatever its
				// MAPPING said — naming `sync` or `link` here would turn one claim
				// into three scenarios. The wire value for `link` is `reference`
				// (the literal `link` is is_callable() and crashes core's PROPFIND).
				$mode = $this->modeOfMappingFor($path);
				$want = $mode === 'link' ? 'reference' : $mode;
				return $actual === $want
					? null : "expected the mapping's mode ('{$want}'), found '{$actual}'";

			case "the design's id":
				// Scoped to the path's own mapping where one is declared; see
				// {@see ArrangeSteps::designIdInMapping()} for why a bare name is not
				// an address.
				$want = $this->designIdInMapping($path) ?? $this->fileIdNamed($this->designNameOf($path));
				return $actual === $want
					? null : "expected the id of the design '{$this->designNameOf($path)}' ({$want}), found '{$actual}'";

			case "the mapping's team":
				// The team of the mapping this path sits under — not whichever team
				// the mappings table happened to name last.
				//
				// SPELT TO MATCH `the mapping's mode`, and that is not cosmetic. This
				// claim was written both ways — `the team's id` in four files and
				// `the mapping's team` in nine, with `connection/sync-now.feature`
				// using BOTH, twenty-six lines apart. Only the first spelling had a
				// case here, so every scenario reaching for the second one died in
				// `default` on "not a value this vocabulary knows" — a fixture
				// failure wearing the shape of an app failure.
				//
				// One claim, one spelling. The pair a mapping fixes about a file is
				// its team and its mode, and they now read as a pair.
				$want = $this->teamIdForPath($path) ?: $this->theNamedTeam();
				return $actual === $want
					? null : "expected the mapped team's id ({$want}), found '{$actual}'";

			case "the mapping's body":
				// WHAT THE MODE IMPLIES, which is the only way one outline can assert
				// "nothing was blanked" across both kinds of mapping. A `sync` mirror
				// holds the archive; a `link` is a pointer and holds zero bytes
				// (§C6.6), so demanding `an archive` of both asks a link file to be
				// something the spec says it must never be.
				//
				// That mismatch is exactly what kept `Move a design between projects`
				// off the run: its second Examples block moves a link within its own
				// project, and the shared `Then` wanted an archive from it.
				$want = $this->modeOfMappingFor($path) === 'link' ? 'empty' : 'archive';
				return $actual === $want
					? null : "expected the body its mapping implies ('{$want}'), the file is '{$actual}'";

			case 'a new one, never the one it arrived with':
				// TWO CLAIMS IN ONE ROW, and the second is the load-bearing half.
				// "Set" alone would pass against the bug this exists to catch: an
				// arrival whose stale id was pushed at Penpot unchanged, leaving the
				// file pointing at a design that does not exist. So the id must be
				// real AND different from whatever the file carried in.
				//
				// An arrival that carried NO id satisfies the second half trivially,
				// which is correct rather than sloppy — the row it shares an outline
				// with is the one where the difference is observable, and asserting
				// "different from nothing" is exactly as strong as the state allows.
				if (($actual ?? '') === '') {
					return 'expected a new design id, found nothing';
				}
				// TWO WAYS TO HAVE "ARRIVED", because two gestures reach this row. A
				// drag into a mapping arrived carrying an id ({@see MoveSteps}); a
				// restore arrived carrying whatever it held when it was trashed, which
				// is what every gesture step captures as `idBeforeGesture`. Both are
				// the id the file came in with, and either one still being on the file
				// afterwards is the same bug.
				$arrived = $this->idArrivedWith !== '' ? $this->idArrivedWith : $this->idBeforeGesture;
				if ($arrived !== '' && $actual === $arrived) {
					return "expected a NEW id; the file still carries the one it arrived with ({$actual})";
				}
				return null;
			case 'set':
				return ($actual ?? '') !== ''
					? null : 'expected a value, found nothing';

			case 'absent':
				return ($actual ?? '') === ''
					? null : "expected it not to be stored, found '{$actual}'";

			case 'an archive':
				return $actual === 'archive'
					? null : "expected real ZIP bytes, the file is '{$actual}'";

			case 'empty':
				return $actual === 'empty'
					? null : "expected zero bytes, the file is '{$actual}'";

			default:
				// LITERALS ARE QUOTED by this table's convention, so an unquoted value
				// reaching here is vocabulary nobody implemented. Comparing it as a
				// literal asserts the wrong thing and reports it as an app failure.
				if (!str_starts_with($expected, '"') || !str_ends_with($expected, '"')) {
					throw new \RuntimeException(
						"the table says '{$expected}', which is not a value this vocabulary knows."
						. ' Quote it to mean a literal, or add a case for it.',
					);
				}
				$literal = trim($expected, '"');
				return $actual === $literal
					? null : "expected '{$literal}', found '{$actual}'";
		}
	}

	/**
	 * The Penpot design a mirror stands for, by name.
	 *
	 * THE FILENAME IS THE DESIGN'S NAME, minus the extension Penpot never carries
	 * (§6.4) — an invariant rename-design.feature pins in both directions, which
	 * is exactly what makes it safe to resolve an id from a path.
	 */
	private function designNameOf(string $path): string {
		return preg_replace('/\.penpot$/', '', basename($path)) ?? '';
	}

	/**
	 * Every readable property of a mirror, in one pass.
	 *
	 * Metadata comes over DAV because that is the surface a client reads; content
	 * comes from `occ penpot_sync:status`, the only thing that can tell a real
	 * archive from a pointer from nothing.
	 *
	 * @param list<string> $properties the names the table actually asked for
	 * @return array<string,string|null>
	 */
	private function readState(string $path, array $properties): array {
		$state = [];
		foreach ($properties as $property) {
			// A clock is not a stored property; check() resolves those itself and
			// never looks at $actual, so reading one here would be wasted work.
			if (in_array($property, ['modified', 'created'], true)) {
				continue;
			}
			if (!in_array($property, self::READABLE, true)) {
				throw new \RuntimeException(sprintf(
					"'%s' is not a property this vocabulary knows how to read. Known: %s.",
					$property,
					implode(', ', self::READABLE),
				));
			}
			$state[$property] = $property === 'content'
				? $this->contentKind($path)
				: $this->davReadMetadata($path, $property);
		}
		return $state;
	}

	/** `archive`, `pointer` or `empty` — what the file on disk actually is. */
	private function contentKind(string $path): string {
		if (preg_match('/Content: (\w+)/', $this->status($path), $m) !== 1) {
			throw new \RuntimeException("could not read the content state of '{$path}' from status output.");
		}
		return $m[1];
	}

	/** @param array<string,string|null> $state */
	private function describe(array $state): string {
		$out = [];
		foreach ($state as $property => $value) {
			$out[] = sprintf('  %-16s %s', $property, ($value ?? '') === '' ? '(nothing)' : $value);
		}
		return implode("\n", $out);
	}
}
