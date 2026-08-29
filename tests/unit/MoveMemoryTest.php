<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MoveMemory;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The note a moving design leaves for the other side of its own gesture.
 *
 * Tiny, and pinned anyway: what it holds is a design's IDENTITY, and every way
 * this can be wrong hands one file the id of another — the "two files, one
 * design, forever" state {@see \OCA\PenpotSync\Service\MotionService} spends
 * three branches avoiding.
 */
final class MoveMemoryTest extends TestCase {
	private const ID_A = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const ID_B = '4eda2e11-843e-8045-8008-51824bda07a1';

	public function testWhatWasRememberedComesBack(): void {
		$memory = new MoveMemory();
		$memory->remember(30, $this->meta(self::ID_A));

		self::assertSame(self::ID_A, $memory->recall(30)?->penpotId);
	}

	public function testAFileNothingNotedRecallsNothing(): void {
		self::assertNull((new MoveMemory())->recall(30));
	}

	public function testForgettingIsFinal(): void {
		$memory = new MoveMemory();
		$memory->remember(30, $this->meta(self::ID_A));
		$memory->forget(30);

		self::assertNull($memory->recall(30));
	}

	public function testTheLatestNoteForAFileWins(): void {
		$memory = new MoveMemory();
		$memory->remember(30, $this->meta(self::ID_A));
		$memory->remember(30, $this->meta(self::ID_B));

		self::assertSame(self::ID_B, $memory->recall(30)?->penpotId);
	}

	/**
	 * THE ONE THAT WOULD HAVE BEEN SILENT. Evicting with `array_shift()` reindexes
	 * an integer-keyed array — every surviving note would be renumbered to 0, 1,
	 * 2 and would then answer for a file it has nothing to do with. Nothing about
	 * that throws; a design simply comes back wearing another design's id.
	 *
	 * Overflows the cap by a wide margin so the eviction definitely runs, then
	 * asks the survivors whether they are still themselves.
	 */
	public function testEvictionDoesNotRenumberTheNotesItKeeps(): void {
		$memory = new MoveMemory();
		for ($fileId = 1; $fileId <= 700; $fileId++) {
			$memory->remember($fileId, $this->meta('design-' . $fileId));
		}

		self::assertSame('design-700', $memory->recall(700)?->penpotId, 'the newest is kept');
		self::assertSame('design-300', $memory->recall(300)?->penpotId, 'and still names its own design');
		self::assertNull($memory->recall(1), 'the oldest went first');
		self::assertNull($memory->recall(0), 'and nothing was renumbered down to zero');
	}

	/**
	 * FOLDER NOTES LIVE IN THEIR OWN MAP, and this is the reason why: Nextcloud
	 * draws file ids and folder ids from ONE sequence, so a shared map would let
	 * a folder's note answer a design's recall and hand `MotionService` a project
	 * id where it asked for a `penpot_id`.
	 *
	 * Asserting both directions on the SAME id is the only way to state that.
	 */
	public function testAFolderNoteAndAFileNoteWithTheSameIdDoNotCollide(): void {
		$memory = new MoveMemory();

		$memory->remember(7, $this->meta(self::ID_A));
		$memory->rememberFolder(7, new FolderMarkers(self::ID_B, 'team-9'));

		self::assertSame(self::ID_A, $memory->recall(7)?->penpotId, 'the file note is untouched');
		self::assertSame(self::ID_B, $memory->recallFolder(7)?->projectId, 'and so is the folder note');
	}

	public function testForgettingAFolderIsFinal(): void {
		$memory = new MoveMemory();
		$memory->rememberFolder(9, new FolderMarkers(self::ID_A, ''));

		$memory->forgetFolder(9);

		self::assertNull($memory->recallFolder(9));
	}

	/** Forgetting one kind must not forget the other. */
	public function testForgettingAFolderLeavesTheFileNoteStanding(): void {
		$memory = new MoveMemory();
		$memory->remember(11, $this->meta(self::ID_A));
		$memory->rememberFolder(11, new FolderMarkers(self::ID_B, ''));

		$memory->forgetFolder(11);

		self::assertSame(self::ID_A, $memory->recall(11)?->penpotId);
	}

	/** The same eviction rule, and the same reindexing trap, as the file map. */
	public function testFolderEvictionDoesNotRenumberTheNotesItKeeps(): void {
		$memory = new MoveMemory();
		for ($folderId = 1; $folderId <= 700; $folderId++) {
			$memory->rememberFolder($folderId, new FolderMarkers('project-' . $folderId, ''));
		}

		self::assertSame('project-700', $memory->recallFolder(700)?->projectId, 'the newest is kept');
		self::assertSame('project-300', $memory->recallFolder(300)?->projectId, 'and still names its own project');
		self::assertNull($memory->recallFolder(1), 'the oldest went first');
		self::assertNull($memory->recallFolder(0), 'and nothing was renumbered down to zero');
	}

	private function meta(string $penpotId): PenpotFileMetadata {
		return new PenpotFileMetadata($penpotId, '5@x', Mapping::MODE_SYNC, 'team-1');
	}
}
