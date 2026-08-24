<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Listener\MoveGuardListener;
use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\MoveRules;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * The two moves this app refuses (`projects/move.feature`, `designs/move.feature`):
 *
 *   §C6.38 — nothing crosses the edge of a `link` mapping, in either direction;
 *   §6.43  — a `link` file may not change project.
 *
 * BOTH RULES ARE ABOUT MODE, and that is the whole shape of the guard now. It used
 * to hold a third — §6.30, *a project folder may not leave its team folder* — which
 * §C6.38 retired: `move-project` crosses a team in one call, and a project dragged
 * out of every mapping is an unmapping rather than a desync. The tests that pinned
 * that refusal are directly below, inverted, because a rule reversing is exactly
 * where a test suite earns its keep: each one now asserts the move GOES THROUGH.
 *
 * These tests are as much about what must NOT be refused as what must. A guard
 * that over-refuses is worse than none, because it breaks ordinary tidying with
 * an alarming message — so every gesture outside the two rules above is pinned
 * as passing straight through.
 */
final class MoveGuardListenerTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const PROJECT = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const OTHER_PROJECT = '7c11a0d4-1f52-4a7e-9b3c-2f9d0e4a1b66';
	private const TEAM = 'df59d46b-a997-80d9-8008-6452575a4b87';
	private const OTHER_TEAM = 'a2c07f18-9b64-4d3e-8f21-0c5e7a9b41d0';

	/** The node id of whatever is being moved; the destination's parent is 21. */
	private const MOVING = 20;

	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private MappingService $mappings;

	/**
	 * Team ids the mapping stub should report as `link`. Empty by default — see
	 * {@see setUp()} for why this is a property rather than a per-test stub.
	 *
	 * @var list<string>
	 */
	private array $linkTeams = [];

	private SyncGuard $guard;
	private \OCP\IL10N $l;
	private MoveGuardListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->mappings = $this->createMock(MappingService::class);
		// EVERY TEAM IS `sync` UNLESS A TEST NAMES IT. The stub reads a property
		// rather than being re-stubbed per test, because a second `method()` call on
		// a PHPUnit mock does not replace the first — it queues behind it and never
		// runs, which would make a "link" test silently assert the sync path.
		$this->mappings->method('getByTeamId')->willReturnCallback(
			fn (string $teamId): ?Mapping => new Mapping(
				'm1',
				$teamId,
				'A Team',
				'Folder',
				false,
				in_array($teamId, $this->linkTeams, true) ? Mapping::MODE_LINK : Mapping::MODE_SYNC,
			),
		);
		$this->guard = new SyncGuard();
		$l = $this->createStub(\OCP\IL10N::class);
		// The identity translator: these two messages are the app's only user-facing
		// prose outside settings, and the tests below assert on their WORDING.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);

		$this->l = $l;
		$this->rebuildListener();
	}

	/**
	 * The rules are a real MoveRules, not a double: they ARE the behaviour under
	 * test here, and the listener is now only the half that aborts.
	 *
	 * Extracted so one test can swap the mapping service for a stub that reports
	 * no mapping at all — a case the property-driven stub in {@see setUp()} cannot
	 * express, because it always answers with a mapping.
	 */
	private function rebuildListener(): void {
		$this->listener = new MoveGuardListener(
			new MoveRules($this->metadata, $this->resolver, $this->mappings, $this->l),
			$this->guard,
		);
	}

	// ── §C6.38, the rule that reversed ──────────────────────────────────────

	public function testAllowsAProjectFolderCrossingIntoAnotherTeam(): void {
		// WAS `testRefusesAProjectFolderLeavingItsTeamFolder`. `move-project` takes
		// the project across in one call, keeping its id, its designs and their
		// history — so the drag is a re-file, not the desync §6.30 assumed.
		$this->givenProjectFolder();
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::OTHER_TEAM),
		);

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAProjectFolderLeavingEveryTeamFolder(): void {
		// WAS `testRefusesAProjectFolderLeavingEveryTeamFolder`. Dragged out to the
		// user's own files: nothing is deleted in Penpot and nothing is stranded —
		// the folder simply stops being a mirror, which MotionService records by
		// stripping the marker.
		$this->givenProjectFolder();
		$this->givenPositions(new Membership(self::PROJECT, self::TEAM), Membership::none());

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAProjectFolderMovingWithinItsTeamFolder(): void {
		// Unchanged by §C6.38, and still worth pinning: this is the common case and
		// the one a broadened guard would break first.
		$this->givenProjectFolder();
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::TEAM),
		);

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAPersonalProjectFolderWithNoTeamAboveIt(): void {
		// §6.31: a personal project mounts at the user's home root, so it has no
		// team above it — and no mapping to read a mode off either.
		$this->givenProjectFolder();
		$this->givenPositions(new Membership(self::PROJECT, null), Membership::none());

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	// ── §C6.38, the rule that replaced it: a link mapping has a hard edge ────

	public function testRefusesAProjectFolderLeavingALinkMapping(): void {
		// A link folder holds pointers, not designs. Wherever it went it would
		// arrive as a tree of empty files.
		$this->linkTeams = [self::TEAM];
		$this->givenProjectFolder();
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::OTHER_TEAM),
		);

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/link mode/');

		$this->listener->handle($this->move($this->folder(), $this->destination()));
	}

	public function testRefusesAProjectFolderMovingWithinItsOwnLinkMapping(): void {
		// THE SOURCE RULE IS TOTAL — "its own team included". A link project has
		// nowhere to go at all, which is the one place this differs from the file
		// rule below, where a move within the project is pure local filing.
		$this->linkTeams = [self::TEAM];
		$this->givenProjectFolder();
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::TEAM),
		);

		$this->expectException(AbortedEventException::class);

		$this->listener->handle($this->move($this->folder(), $this->destination()));
	}

	public function testRefusesAFolderMovingIntoALinkMapping(): void {
		// The DESTINATION half, and it is about the plain folder as much as the
		// project: a link mapping is filled from Penpot, whatever is arriving.
		$this->linkTeams = [self::OTHER_TEAM];
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', ''));
		$this->givenPositions(
			new Membership(null, self::TEAM),
			new Membership(null, self::OTHER_TEAM),
		);

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/filled from Penpot/');

		$this->listener->handle($this->move($this->folder(), $this->destination()));
	}

	public function testAllowsAFolderWhoseTeamIsNoLongerMapped(): void {
		// The resolver reads a MARKER off a folder, and tearing a mapping down does
		// not go round scrubbing them. Refusing on a mapping that no longer exists
		// would strand the folder for a reason nobody could act on.
		$this->mappings = $this->createMock(MappingService::class);
		$this->mappings->method('getByTeamId')->willReturn(null);
		$this->rebuildListener();

		$this->givenProjectFolder();
		$this->givenPositions(new Membership(self::PROJECT, self::TEAM), Membership::none());

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAPlainFolder(): void {
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', ''));
		$this->givenPositions(new Membership(null, self::TEAM), new Membership(null, self::TEAM));

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsTheTeamRootItself(): void {
		// The root carries only a team id — it is the mapping's folder, and moving
		// or renaming it is the mapping's own business, not a project's.
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', self::TEAM));
		$this->givenPositions(new Membership(null, self::TEAM), Membership::none());

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	// ── §6.43, link files ───────────────────────────────────────────────────

	public function testRefusesALinkFileMovingToAnotherProject(): void {
		$this->givenFile(mode: 'link');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(self::OTHER_PROJECT, self::TEAM),
		);

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/is a link to a design/');

		$this->listener->handle($this->move($this->file(), $this->destination()));
	}

	public function testRefusesALinkFileMovingToTheTeamRoot(): void {
		// The team root means Drafts (§6.35) — a real project change, which a
		// pointer is not allowed to make.
		$this->givenFile(mode: 'link');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::TEAM),
		);

		$this->expectException(AbortedEventException::class);

		$this->listener->handle($this->move($this->file(), $this->destination()));
	}

	public function testRefusesALinkFileMovingOutOfEveryMapping(): void {
		// Allowing this would hand someone an empty husk that looks like a backup.
		$this->givenFile(mode: 'link');
		$this->givenPositions(new Membership(self::PROJECT, self::TEAM), Membership::none());

		$this->expectException(AbortedEventException::class);
		// The refusal used to be asserted on the word "sync", because it ended
		// "switch it to sync mode first". There is no per-file mode change any
		// more, so it names the rule and the reason and stops — as both siblings'
		// guards already did. What must survive is that it explains WHY, which is
		// the pointer having no copy of the design.
		$this->expectExceptionMessageMatches('/holds no copy of the design/');

		$this->listener->handle($this->move($this->file(), $this->destination()));
	}

	public function testAllowsALinkFileMovingWithinItsOwnProject(): void {
		// A plain subfolder Penpot cannot even see — pure local filing.
		$this->givenFile(mode: 'link');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(self::PROJECT, self::TEAM),
		);

		$this->listener->handle($this->move($this->file(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsASyncFileToMoveAnywhere(): void {
		// A `sync` file holds a real archive, so it earns its freedom (§6.43) —
		// MotionService re-files it in Penpot instead of refusing it here.
		$this->givenFile(mode: 'sync');
		$this->givenPositions(new Membership(self::PROJECT, self::TEAM), Membership::none());

		$this->listener->handle($this->move($this->file(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAnUntrackedPenpotFile(): void {
		// Ordinary tolerated content — it moves like any other file.
		$this->metadata->method('readFile')->willReturn(null);

		$this->listener->handle($this->move($this->file(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAPlainFile(): void {
		$this->metadata->expects($this->never())->method('readFile');

		$this->listener->handle($this->move($this->file('notes.txt'), $this->destination()));
		$this->addToAssertionCount(1);
	}

	// ── §6.43, and the gesture the position test could not see ──────────────

	/**
	 * A RENAME IS A MOVE TO A SIBLING PATH — same verb, same event, same pair of
	 * nodes — so the position comparison resolves it to the project it started in
	 * and waves it through. That is exactly what happened: `A link cannot be moved
	 * out of the project it points into` had been green for courses while
	 * `Rename a link in Nextcloud` sat `@unbuilt`, and the two are one rule.
	 *
	 * The name is the only thing that separates them.
	 */
	public function testRefusesRenamingALinkFile(): void {
		$this->givenFile(mode: 'link');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(self::PROJECT, self::TEAM),
		);

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/its name is Penpot\'s to set/');

		$this->listener->handle($this->move($this->file(), $this->destination('Renamed.penpot')));
	}

	public function testAllowsRenamingASyncFile(): void {
		// A `sync` mirror holds the design, so its name is the user's to change —
		// PushService pushes the rename on to Penpot.
		$this->givenFile(mode: 'sync');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(self::PROJECT, self::TEAM),
		);

		$this->listener->handle($this->move($this->file(), $this->destination('Renamed.penpot')));
		$this->addToAssertionCount(1);
	}

	public function testAllowsMovingALinkFileWithinItsProjectWhenTheNameIsUnchanged(): void {
		// The other half of the same rule, and the reason the discriminator is the
		// NAME and not the gesture: Penpot cannot see a subfolder, so filing a link
		// into one is pure local arrangement and stays free.
		$this->givenFile(mode: 'link');
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(self::PROJECT, self::TEAM),
		);

		$this->listener->handle($this->move($this->file(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	// ── the wall between the two directions ─────────────────────────────────

	public function testNeverRefusesTheAppsOwnMoves(): void {
		// The pull is reconciling TO Penpot, so by definition it cannot desync
		// from it — and a guard that fought the pull would break the mirror.
		$this->metadata->expects($this->never())->method('readFolder');

		$this->guard->run(function (): void {
			$this->listener->handle($this->move($this->folder(), $this->destination()));
		});
		$this->addToAssertionCount(1);
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function givenProjectFolder(): void {
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers(self::PROJECT, ''));
	}

	private function givenFile(string $mode): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', $mode));
	}

	/** The moving node resolves to $from; the destination's parent to $to. */
	private function givenPositions(Membership $from, Membership $to): void {
		$this->resolver->method('resolve')->willReturnCallback(
			static fn (Node $node): Membership => $node->getId() === self::MOVING ? $from : $to,
		);
	}

	private function folder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(self::MOVING);
		$folder->method('getName')->willReturn('Marketing');

		return $folder;
	}

	private function file(string $name = 'Login screen.penpot'): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::MOVING);
		$file->method('getName')->willReturn($name);

		return $file;
	}

	/**
	 * The target of a before-move event: a node at the new path, parent existing.
	 *
	 * The NAME defaults to the source's, so every move test reads as a move. A
	 * test that passes a different one is describing a RENAME — which is the same
	 * event, and the only thing that tells the two apart.
	 */
	private function destination(string $name = 'Login screen.penpot'): Folder {
		$newParent = $this->createMock(Folder::class);
		$newParent->method('getId')->willReturn(21);

		$target = $this->createMock(Folder::class);
		$target->method('getId')->willReturn(0);
		$target->method('getName')->willReturn($name);
		$target->method('getParent')->willReturn($newParent);

		return $target;
	}

	private function move(Node $source, Node $target): BeforeNodeRenamedEvent {
		$event = $this->createMock(BeforeNodeRenamedEvent::class);
		$event->method('getSource')->willReturn($source);
		$event->method('getTarget')->willReturn($target);

		return $event;
	}
}
