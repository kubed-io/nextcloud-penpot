<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingTeardownService;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCA\PenpotSync\Service\TrashControl;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Removing a mapping, and what becomes of the mirrors (`mapping/delete.feature`).
 *
 * ## THE TWO CLAIMS WORTH GUARDING HERE
 *
 * The behaviour is a one-line fork — archive or no archive — and the tests for it
 * are cheap. The two that earn their keep are the ones about what must NOT happen:
 *
 *   - **the guard is up.** Every removal is a `Node::delete()`, which fires the
 *     same event a person's delete does, and `DeleteListener` answers that by
 *     deleting the design in PENPOT. A teardown that forgot the guard would turn
 *     "stop mirroring this team" into "delete this team's work", from an action
 *     whose whole promise is that it touches nothing there.
 *   - **an untracked `.penpot` is nobody's business.** The mapped folder has to
 *     stay usable as a folder, so a design somebody put there themselves is
 *     neither removed nor re-labelled.
 *
 * Collaborators are `final`, so they are doubled via the unit bootstrap's
 * `dg/bypass-finals`.
 */
#[CoversClass(MappingTeardownService::class)]
final class MappingTeardownServiceTest extends TestCase {
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';

	private PenpotMetadata $metadata;
	private ArchiveService $archives;
	private SyncGuard $guard;

	/** @var array<int, bool> fileId => holds a real archive */
	private array $archiveByFile = [];

	/** @var array<int, string> fileId => the penpot_id it carries ('' for untracked) */
	private array $idByFile = [];

	/** @var list<int> the ids of the files that were deleted, in order */
	private array $deleted = [];

	/** @var array<int, bool> whether the guard was up when THIS file was deleted */
	private array $guardedAtDelete = [];

	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->archives = $this->createStub(ArchiveService::class);
		$this->guard = new SyncGuard();
		$this->archiveByFile = [];
		$this->idByFile = [];
		$this->deleted = [];
		$this->guardedAtDelete = [];
	}

	// ── the fork ────────────────────────────────────────────────────────────

	/** A pointer holds nothing, so with the mapping gone there is nothing to keep. */
	public function testAPointerIsRemoved(): void {
		$result = $this->tearDownOver([$this->mirror(1, holdsArchive: false)]);

		self::assertSame([1], $this->deleted);
		self::assertSame(['removed' => 1, 'unmapped' => 0], $result);
	}

	/** An archive may be the last copy of the design. It stays, and stops mirroring. */
	public function testAnArchiveIsKeptAndUnmapped(): void {
		$this->metadata->expects(self::once())->method('writeFile')->with(7, [
			PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
			PenpotMetadata::KEY_TEAM_ID => '',
		]);

		$result = $this->tearDownOver([$this->mirror(7, holdsArchive: true)]);

		self::assertSame([], $this->deleted, 'a design holding an archive must never be removed');
		self::assertSame(['removed' => 0, 'unmapped' => 1], $result);
	}

	/**
	 * THE `penpot_id` IS NOT TOUCHED, and that is what makes this an unmap rather
	 * than a wipe: it is the only thing that lets re-mapping the team reattach this
	 * file to the design it already holds instead of importing a second copy.
	 */
	public function testUnmappingLeavesThePenpotIdAlone(): void {
		$this->metadata->expects(self::once())->method('writeFile')->with(
			7,
			self::logicalNot(self::arrayHasKey(PenpotMetadata::KEY_ID)),
		);

		$this->tearDownOver([$this->mirror(7, holdsArchive: true)]);
	}

	/** One mapping, one walk, both endings — the two scenarios' shapes together. */
	public function testAMixedTreeGetsBothEndings(): void {
		$result = $this->tearDownOver([
			$this->mirror(1, holdsArchive: false),
			$this->mirror(2, holdsArchive: true),
			$this->mirror(3, holdsArchive: false),
		]);

		self::assertSame([1, 3], $this->deleted);
		self::assertSame(['removed' => 2, 'unmapped' => 1], $result);
	}

	// ── what must not happen ────────────────────────────────────────────────

	/**
	 * THE SEATBELT. Without the guard, `DeleteListener` turns each of these
	 * deletes into a `delete-file` against Penpot — so removing a link mapping
	 * would delete the team's designs from an action documented as never touching
	 * Penpot at all.
	 */
	public function testEveryRemovalHappensBehindTheSyncGuard(): void {
		$this->tearDownOver([
			$this->mirror(1, holdsArchive: false),
			$this->mirror(2, holdsArchive: false),
		]);

		self::assertSame([1 => true, 2 => true], $this->guardedAtDelete);
		self::assertFalse($this->guard->active(), 'the guard has to come back down afterwards');
	}

	/**
	 * A `.penpot` somebody put in the mapped folder themselves carries no
	 * `penpot_id`. It was never a mirror, so the teardown neither removes it nor
	 * re-labels it — the mapped folder stays usable as a folder.
	 */
	public function testAnUntrackedDesignIsLeftAlone(): void {
		$this->metadata->expects(self::never())->method('writeFile');

		$result = $this->tearDownOver([$this->mirror(9, holdsArchive: false, penpotId: '')]);

		self::assertSame([], $this->deleted);
		self::assertSame(['removed' => 0, 'unmapped' => 0], $result);
	}

	/** Nor is anything that is not a design, whatever it holds. */
	public function testAnOrdinaryFileIsLeftAlone(): void {
		$this->metadata->expects(self::never())->method('writeFile');

		$result = $this->tearDownOver([$this->file(4, 'Budget.xlsx')]);

		self::assertSame([], $this->deleted);
		self::assertSame(['removed' => 0, 'unmapped' => 0], $result);
	}

	/**
	 * A mapping whose folder was never provisioned — or has been deleted by hand —
	 * has no mirrors to answer for, and must not be an error on the way out.
	 */
	public function testAMappingWithNoFolderTearsDownNothing(): void {
		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn(null);

		$service = new MappingTeardownService(
			$storage,
			$this->metadata,
			$this->archives,
			$this->trash(),
			$this->guard,
			new NullLogger(),
		);

		self::assertSame(['removed' => 0, 'unmapped' => 0], $service->tearDown($this->mapping()));
	}

	/**
	 * A file that will not delete is logged and stepped over. The mapping's removal
	 * is the act the admin asked for; one stubborn pointer must not fail it, nor
	 * stop the mirrors after it being dealt with.
	 */
	public function testAFileThatWillNotDeleteDoesNotStopTheRest(): void {
		$result = $this->tearDownOver([
			$this->mirror(1, holdsArchive: false, stubborn: true),
			$this->mirror(2, holdsArchive: false),
		]);

		self::assertSame([2], $this->deleted);
		self::assertSame(['removed' => 1, 'unmapped' => 0], $result);
	}

	// ── the tree the walk is given ──────────────────────────────────────────

	/**
	 * Mirrors nested below a project folder are reached too — the mapped tree is
	 * root/project/design, so a walk that read only the root's children would tear
	 * down nothing at all in practice.
	 */
	public function testTheWalkDescendsIntoProjectFolders(): void {
		$nested = $this->folder([$this->mirror(1, holdsArchive: false)]);
		$result = $this->tearDownOver([$nested]);

		self::assertSame([1], $this->deleted);
		self::assertSame(['removed' => 1, 'unmapped' => 0], $result);
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * Run one teardown over a root holding $children.
	 *
	 * NOT `tearDown()`: that is {@see \PHPUnit\Framework\TestCase}'s own hook, and
	 * a private one here is a fatal error before a single test runs — "access level
	 * must be protected or weaker".
	 *
	 * @param list<\OCP\Files\Node> $children
	 *
	 * @return array{removed:int, unmapped:int}
	 */
	private function tearDownOver(array $children): array {
		$root = $this->folder($children);

		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn($root);

		$this->metadata->method('readFile')->willReturnCallback(
			fn (int $id): ?PenpotFileMetadata => ($this->idByFile[$id] ?? '') === ''
				? null
				: new PenpotFileMetadata($this->idByFile[$id], 'r1', Mapping::MODE_LINK, self::TEAM),
		);
		$this->archives->method('holdsArchive')->willReturnCallback(
			fn (File $node): bool => $this->archiveByFile[$node->getId()] ?? false,
		);

		$service = new MappingTeardownService(
			$storage,
			$this->metadata,
			$this->archives,
			$this->trash(),
			$this->guard,
			new NullLogger(),
		);

		return $service->tearDown($this->mapping());
	}

	/**
	 * A trash control that runs the callback with the trash "paused" — which is
	 * what the real one does, and all this class needs from it. The claim that a
	 * pointer leaves NO trash entry is `TrashControl`'s own to keep; here the
	 * double simply must not swallow the delete.
	 */
	private function trash(): TrashControl {
		$trash = $this->createStub(TrashControl::class);
		$trash->method('withoutTrash')->willReturnCallback(static fn (callable $fn): mixed => $fn());

		return $trash;
	}

	private function mapping(): Mapping {
		return new Mapping('m1', self::TEAM, 'Northwind', 'Design Files', false, Mapping::MODE_LINK);
	}

	/** @param list<\OCP\Files\Node> $children */
	private function folder(array $children): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn('/admin/files/Design Files');
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}

	/** A mirrored design: a `.penpot` carrying a `penpot_id`. */
	private function mirror(int $id, bool $holdsArchive, string $penpotId = 'design-1', bool $stubborn = false): File {
		$this->archiveByFile[$id] = $holdsArchive;
		$this->idByFile[$id] = $penpotId;

		return $this->file($id, 'Gizmo.penpot', $stubborn);
	}

	/**
	 * Any file, recording its delete (and whether the guard was up) when it happens.
	 *
	 * `$stubborn` is a parameter rather than a second `->method('delete')` on the
	 * returned mock: PHPUnit keeps BOTH configurations and the first one added wins,
	 * so a later `willThrowException()` would be silently ignored and the test would
	 * assert the opposite of what it says.
	 */
	private function file(int $id, string $name, bool $stubborn = false): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/admin/files/Design Files/' . $name);
		$file->method('delete')->willReturnCallback(function () use ($id, $stubborn): void {
			if ($stubborn) {
				throw new \RuntimeException('locked');
			}
			$this->guardedAtDelete[$id] = $this->guard->active();
			$this->deleted[] = $id;
		});

		return $file;
	}
}
