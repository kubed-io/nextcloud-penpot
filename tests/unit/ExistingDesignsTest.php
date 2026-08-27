<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ExistingDesigns;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCA\PenpotSync\Service\TrashControl;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The designs already under a folder a link mapping is about to claim.
 *
 * ## THE TWO TESTS THAT EARN THEIR KEEP
 *
 * Finding `.penpot` files in a tree is not hard, and the tests for it are cheap.
 * The two worth writing are about what must NOT happen:
 *
 *   - **the guard is up.** These files are `unmapped`, and an unmapped design
 *     KEEPS its `penpot_id` — so each delete fires the event `DeleteListener`
 *     answers by deleting the design in PENPOT. Clearing a folder so it can be
 *     mirrored would delete the designs it is about to mirror.
 *   - **nothing reaches the trash.** A trashed design offers a restore, and
 *     restoring into a link mapping cannot work. That is the whole reason this
 *     purges rather than trashing, so it is asserted rather than assumed.
 *
 * Collaborators are `final`, so they are doubled via the unit bootstrap's
 * `dg/bypass-finals`.
 */
#[CoversClass(ExistingDesigns::class)]
final class ExistingDesignsTest extends TestCase {
	private SyncGuard $guard;

	/** @var list<string> the paths deleted, in order */
	private array $deleted = [];

	/** @var array<string, bool> whether the guard was up when each was deleted */
	private array $guardedAtDelete = [];

	/** @var array<string, bool> whether the trash was paused when each was deleted */
	private array $trashPausedAtDelete = [];

	/** Whether {@see trash()} is currently inside `withoutTrash()`. */
	private bool $trashPaused = false;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new SyncGuard();
		$this->deleted = [];
		$this->guardedAtDelete = [];
		$this->trashPausedAtDelete = [];
		$this->trashPaused = false;
	}

	// ── what is found ───────────────────────────────────────────────────────

	public function testFindsDesignsAtTheRoot(): void {
		$found = $this->service($this->folder([$this->design('Keeper.penpot')]))->under($this->mapping());

		self::assertCount(1, $found);
	}

	/**
	 * ANYWHERE IN THE TREE, which is the half a shallow check would miss — and the
	 * live folder that prompted this rule kept two of its three designs one level
	 * down, in a project subfolder.
	 */
	public function testFindsDesignsNestedInSubfolders(): void {
		$root = $this->folder([
			$this->design('Top.penpot'),
			$this->folder([
				$this->design('Middle.penpot'),
				$this->folder([$this->design('Deep.penpot')]),
			]),
		]);

		self::assertCount(3, $this->service($root)->under($this->mapping()));
	}

	/** Anything that is not a design is none of this rule's business. */
	public function testIgnoresEverythingThatIsNotADesign(): void {
		$root = $this->folder([
			$this->design('Budget.xlsx'),
			$this->design('notes.txt'),
			$this->folder([]),
		]);

		self::assertSame([], $this->service($root)->under($this->mapping()));
	}

	/**
	 * A folder nothing has used yet holds nothing to warn about — and is the
	 * overwhelmingly common case, so it must not be an error.
	 */
	public function testAMappingWithNoFolderYetFindsNothing(): void {
		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn(null);

		$service = new ExistingDesigns($storage, $this->trash(), $this->guard, new NullLogger());

		self::assertSame([], $service->under($this->mapping()));
	}

	/**
	 * AN UNREADABLE FOLDER IS NOT AN EMPTY ONE, and answering "nothing found" would
	 * let the mapping be created over designs nobody could see — the exact state
	 * this class exists to prevent. It fails closed instead, as the type both front
	 * doors already turn into a refusal. Raised by Copilot on #48.
	 */
	public function testAnUnreadableFolderRefusesRatherThanReadingAsEmpty(): void {
		$root = $this->createStub(Folder::class);
		$root->method('getName')->willReturn('Designs');
		$root->method('getPath')->willReturn('/admin/files/Designs');
		$root->method('getDirectoryListing')->willThrowException(new \RuntimeException('no access'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('could not be read');

		$this->service($root)->under($this->mapping());
	}

	// ── what must not happen ────────────────────────────────────────────────

	/**
	 * THE SEATBELT. An unmapped design keeps its `penpot_id`, so without the guard
	 * `DeleteListener` turns each of these into a `delete-file` against Penpot —
	 * and clearing a folder so it can mirror a team would delete that team's work.
	 */
	public function testEveryPurgeHappensBehindTheSyncGuard(): void {
		$designs = [$this->design('One.penpot'), $this->design('Two.penpot')];

		$this->service($this->folder($designs))->purge($designs);

		self::assertSame(['One.penpot' => true, 'Two.penpot' => true], $this->guardedAtDelete);
		self::assertFalse($this->guard->active(), 'the guard has to come back down afterwards');
	}

	/**
	 * NOTHING REACHES THE TRASH, which is the reason this class exists at all: a
	 * trashed design offers a restore into a link mapping, and there is nowhere for
	 * those bytes to go.
	 */
	public function testNothingIsPurgedThroughTheTrash(): void {
		$designs = [$this->design('One.penpot')];

		$this->service($this->folder($designs))->purge($designs);

		self::assertSame(['One.penpot' => true], $this->trashPausedAtDelete);
	}

	/** A file that will not go is logged and stepped over, and counted nowhere. */
	public function testAFileThatWillNotDeleteDoesNotStopTheRest(): void {
		$designs = [$this->design('Stuck.penpot', stubborn: true), $this->design('Two.penpot')];

		self::assertSame(1, $this->service($this->folder($designs))->purge($designs));
		self::assertSame(['Two.penpot'], $this->deleted);
	}

	public function testPurgingNothingTouchesNothing(): void {
		self::assertSame(0, $this->service($this->folder([]))->purge([]));
		self::assertSame([], $this->deleted);
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	private function service(Folder $root): ExistingDesigns {
		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn($root);

		return new ExistingDesigns($storage, $this->trash(), $this->guard, new NullLogger());
	}

	/**
	 * A trash control that records whether it was "paused" around each delete —
	 * which is what {@see TrashControl::withoutTrash()} really does, and the only
	 * property this class depends on.
	 */
	private function trash(): TrashControl {
		$trash = $this->createStub(TrashControl::class);
		$trash->method('withoutTrash')->willReturnCallback(function (callable $fn): mixed {
			$this->trashPaused = true;
			try {
				return $fn();
			} finally {
				$this->trashPaused = false;
			}
		});

		return $trash;
	}

	private function mapping(): Mapping {
		return new Mapping('m1', 't1', 'Northwind', 'Designs', false, Mapping::MODE_LINK);
	}

	/** @param list<Node> $children */
	private function folder(array $children): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('Designs');
		$folder->method('getPath')->willReturn('/admin/files/Designs');
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}

	private function design(string $name, bool $stubborn = false): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/admin/files/Designs/' . $name);
		$file->method('delete')->willReturnCallback(function () use ($name, $stubborn): void {
			if ($stubborn) {
				throw new \RuntimeException('locked');
			}
			$this->guardedAtDelete[$name] = $this->guard->active();
			$this->trashPausedAtDelete[$name] = $this->trashPaused;
			$this->deleted[] = $name;
		});

		return $file;
	}
}
