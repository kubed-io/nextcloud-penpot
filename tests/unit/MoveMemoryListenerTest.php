<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Listener\MoveMemoryListener;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MoveMemory;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * Reading a design's identity while it still has one.
 *
 * The listener itself decides almost nothing — {@see MoveMemory} holds, and
 * {@see \OCA\PenpotSync\Service\MotionService} decides. What it does own is WHEN
 * a note is left, and each of the three refusals below is a note that would have
 * been wrong rather than merely wasteful: a folder has no design identity, an
 * untracked `.penpot` is an import wherever it lands, and the pull's own renames
 * are the app talking to itself.
 */
final class MoveMemoryListenerTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';

	private PenpotMetadata $metadata;
	private MoveMemory $memory;
	private SyncGuard $guard;
	private MoveMemoryListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->memory = new MoveMemory();
		$this->guard = new SyncGuard();
		$this->listener = new MoveMemoryListener($this->metadata, $this->memory, $this->guard);
	}

	public function testAManagedDesignIsRemembered(): void {
		$this->givenStamp(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_SYNC, 'team-1'));

		$this->listener->handle($this->move($this->file()));

		self::assertSame(self::PENPOT_ID, $this->memory->recall(30)?->penpotId);
	}

	/**
	 * An untracked `.penpot` is the §6.33 import wherever it lands, so a note
	 * against it could only ever be an identity it does not have.
	 */
	public function testAnUntrackedDesignIsNotRemembered(): void {
		$this->givenStamp(null);

		$this->listener->handle($this->move($this->file()));

		self::assertNull($this->memory->recall(30));
	}

	public function testAnOrdinaryFileIsNotRemembered(): void {
		$this->metadata->expects($this->never())->method('readFile');

		$this->listener->handle($this->move($this->file('Budget.xlsx')));

		self::assertNull($this->memory->recall(30));
	}

	public function testAFolderIsNotRemembered(): void {
		$this->metadata->expects($this->never())->method('readFile');

		$this->listener->handle($this->move($this->createMock(Folder::class)));
	}

	/**
	 * THE GUARD, for the same reason as every other listener: the pull's own
	 * follow-renames are reconciling TO Penpot, so there is no push to prepare
	 * for and nothing worth remembering.
	 */
	public function testThePullsOwnRenamesLeaveNoNote(): void {
		$this->givenStamp(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_SYNC, 'team-1'));

		$this->guard->run(function (): void {
			$this->listener->handle($this->move($this->file()));
		});

		self::assertNull($this->memory->recall(30));
	}

	private function givenStamp(?PenpotFileMetadata $meta): void {
		$this->metadata->method('readFile')->willReturn($meta);
	}

	private function file(string $name = 'Login screen.penpot'): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(30);
		$file->method('getName')->willReturn($name);

		return $file;
	}

	private function move(Node $source): BeforeNodeRenamedEvent {
		$event = $this->createMock(BeforeNodeRenamedEvent::class);
		$event->method('getSource')->willReturn($source);

		return $event;
	}
}
