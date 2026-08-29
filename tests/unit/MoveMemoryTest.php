<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

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

	private function meta(string $penpotId): PenpotFileMetadata {
		return new PenpotFileMetadata($penpotId, '5@x', Mapping::MODE_SYNC, 'team-1');
	}
}
