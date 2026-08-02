<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Listener\MoveGuardListener;
use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
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
 * The two moves this app refuses (`move-design.feature` / `move-project.feature`):
 *
 *   §6.30 — a project folder may not leave its team folder;
 *   §6.43 — a `link` file may not change project.
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
	private SyncGuard $guard;
	private MoveGuardListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->guard = new SyncGuard();
		$l = $this->createStub(\OCP\IL10N::class);
		// The identity translator: these two messages are the app's only user-facing
		// prose outside settings, and the tests below assert on their WORDING.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);

		$this->listener = new MoveGuardListener($this->metadata, $this->resolver, $this->guard, $l);
	}

	// ── §6.30, project folders ──────────────────────────────────────────────

	public function testRefusesAProjectFolderLeavingItsTeamFolder(): void {
		$this->givenProjectFolder();
		$this->givenPositions(
			new Membership(self::PROJECT, self::TEAM),
			new Membership(null, self::OTHER_TEAM),
		);

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/mirrors a Penpot project/');

		$this->listener->handle($this->move($this->folder(), $this->destination()));
	}

	public function testRefusesAProjectFolderLeavingEveryTeamFolder(): void {
		// Dragged out to the user's own files — no team above it at all.
		$this->givenProjectFolder();
		$this->givenPositions(new Membership(self::PROJECT, self::TEAM), Membership::none());

		$this->expectException(AbortedEventException::class);

		$this->listener->handle($this->move($this->folder(), $this->destination()));
	}

	public function testAllowsAProjectFolderMovingWithinItsTeamFolder(): void {
		// Nextcloud owns folder layout (§6.29): Penpot has no notion of where the
		// project folder sits, so nesting it under "Clients/" is free.
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
		// team boundary to leave.
		$this->givenProjectFolder();
		$this->givenPositions(new Membership(self::PROJECT, null), Membership::none());

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsAPlainFolder(): void {
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', ''));

		$this->listener->handle($this->move($this->folder(), $this->destination()));
		$this->addToAssertionCount(1);
	}

	public function testAllowsTheTeamRootItself(): void {
		// The root carries only a team id — it is the mapping's folder, and moving
		// or renaming it is the mapping's own business, not a project's.
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', self::TEAM));

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
		$this->expectExceptionMessageMatches('/sync/');

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

	/** The target of a before-move event: a node at the new path, parent existing. */
	private function destination(): Folder {
		$newParent = $this->createMock(Folder::class);
		$newParent->method('getId')->willReturn(21);

		$target = $this->createMock(Folder::class);
		$target->method('getId')->willReturn(0);
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
