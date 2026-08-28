<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\BulkPushService;
use OCA\PenpotSync\Service\DestinationResolver;
use OCA\PenpotSync\Service\ImportService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Sync to Penpot" — which files it picks up, and which it must not.
 *
 * The selection rule is the whole risk surface here. Importing a file that
 * already names a design would put a second copy of somebody's work in their
 * team; importing a zero-byte pointer would create an empty design beside a
 * mirror that holds nothing. Both are silent, and both are what these pin.
 *
 * The import itself is {@see ImportServiceTest}'s, and the wire format is the
 * integration suite's.
 */
final class BulkPushServiceTest extends TestCase {
	private const TEAM = 'team-abc';
	private const PROJECT = 'project-1';

	/** @var array<int, bool> fileId => does it hold a real archive */
	private array $archiveByFile = [];

	/** @var array<int, string> fileId => the penpot_id it carries ('' for untracked) */
	private array $idByFile = [];

	/** @var list<int> the ids of the files that were imported, in order */
	private array $imported = [];

	/** @var array<int, bool> whether the guard was up when THIS file was imported */
	private array $guardedAtImport = [];

	private SyncGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new SyncGuard();
		$this->archiveByFile = [];
		$this->idByFile = [];
		$this->imported = [];
		$this->guardedAtImport = [];
		$this->unmappedFiles = [];
		$this->stubbornFiles = [];
	}

	// ── what a push picks up ────────────────────────────────────────────────

	/** The whole point of the button: an archive nobody has pushed becomes a design. */
	public function testAnUntrackedArchiveIsImported(): void {
		$result = $this->pushOver([$this->design(1, holdsArchive: true, penpotId: '')]);

		self::assertSame([1], $this->imported);
		self::assertSame(1, $result['pushed']);
		self::assertSame(0, $result['failed']);
	}

	/** Nested designs are reached — the mapped tree is root/project/design. */
	public function testTheWalkDescendsIntoProjectFolders(): void {
		$nested = $this->folder([$this->design(1, holdsArchive: true, penpotId: '')]);

		$this->pushOver([$nested]);

		self::assertSame([1], $this->imported);
	}

	// ── what a push must never touch ────────────────────────────────────────

	/**
	 * §6.1'S ACTUAL LINE. A live mirror names a design Penpot already holds, and
	 * re-importing it would put a second copy of somebody's work in their team —
	 * which is the failure this whole service is one guard away from being.
	 */
	public function testAFileThatAlreadyMirrorsADesignIsSkipped(): void {
		$result = $this->pushOver([$this->design(1, holdsArchive: true, penpotId: 'design-1')]);

		self::assertSame([], $this->imported);
		self::assertSame(0, $result['pushed']);
	}

	/**
	 * AN `unmapped` FILE IS PUSHED, and getting this backwards is the easy mistake:
	 * it carries a `penpot_id`, so an "is it managed?" test skips it.
	 *
	 * But that id names a design that was TRASHED when the file left the mapping,
	 * and `designs/move.feature` is explicit that it must never be reattached to —
	 * "an arrival becomes its own design, whatever it arrived carrying". So the
	 * bytes are somebody's work sitting in a mapped folder with nothing in Penpot
	 * answering to them, which is precisely what this button exists for.
	 */
	public function testAnUnmappedFileIsImportedAsItsOwnDesign(): void {
		$result = $this->pushOver([
			$this->design(1, holdsArchive: true, penpotId: 'stale-id', unmapped: true),
		]);

		self::assertSame([1], $this->imported, 'an unmapped archive is an arrival, not a mirror');
		self::assertSame(1, $result['pushed']);
	}

	/**
	 * A zero-byte pointer has nothing to import, and inventing an empty design
	 * beside it is the destructive act {@see ImportService} exists to avoid.
	 */
	public function testAFileHoldingNoArchiveIsSkipped(): void {
		$result = $this->pushOver([$this->design(1, holdsArchive: false, penpotId: '')]);

		self::assertSame([], $this->imported);
		self::assertSame(0, $result['pushed']);
	}

	/** Anything that is not a `.penpot` is not this app's to send. */
	public function testAnOrdinaryFileIsSkipped(): void {
		$this->pushOver([$this->file(4, 'Budget.xlsx')]);

		self::assertSame([], $this->imported);
	}

	/**
	 * A `link` mapping's mirrors are pointers by construction, so there is nothing
	 * to push — and the skip is counted rather than silent, because the admin
	 * pressed a button and this mapping deliberately did nothing.
	 */
	public function testALinkMappingIsSkippedWhole(): void {
		$result = $this->pushOver(
			[$this->design(1, holdsArchive: true, penpotId: '')],
			Mapping::MODE_LINK,
		);

		self::assertSame([], $this->imported, 'a link mapping must never push, whatever its files hold');
		self::assertSame(1, $result['skipped']);
		self::assertSame(0, $result['processed']);
	}

	// ── the seatbelts ───────────────────────────────────────────────────────

	/**
	 * THE GUARD. Every stamp the import writes fires a write event, and the
	 * writeback listeners answer those — so an unguarded push would re-enter
	 * itself through the very listeners it is imitating.
	 */
	public function testEveryImportHappensBehindTheSyncGuard(): void {
		$this->pushOver([
			$this->design(1, holdsArchive: true, penpotId: ''),
			$this->design(2, holdsArchive: true, penpotId: ''),
		]);

		self::assertSame([1 => true, 2 => true], $this->guardedAtImport);
		self::assertFalse($this->guard->active(), 'the guard has to come back down afterwards');
	}

	/**
	 * One file Penpot refuses must not cost the rest of the run — and it is
	 * counted, so the panel can say so rather than reporting a clean sweep.
	 */
	public function testAFileThatWillNotImportDoesNotStopTheRest(): void {
		$result = $this->pushOver([
			$this->design(1, holdsArchive: true, penpotId: '', stubborn: true),
			$this->design(2, holdsArchive: true, penpotId: ''),
		]);

		self::assertSame([2], $this->imported);
		self::assertSame(1, $result['pushed']);
		self::assertSame(1, $result['failed']);
		self::assertSame('error', $result['status']);
	}

	/** A mapping whose folder was never provisioned is not an error on the way out. */
	public function testAMappingWithNoFolderPushesNothing(): void {
		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn(null);

		$result = $this->service($storage)->push(null);

		self::assertSame(0, $result['processed']);
		self::assertSame('ok', $result['status']);
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	/**
	 * Run one push over a root holding $children.
	 *
	 * @param list<\OCP\Files\Node> $children
	 *
	 * @return array{processed:int, pushed:int, failed:int, skipped:int, status:string, message:?string}
	 */
	private function pushOver(array $children, string $mode = Mapping::MODE_SYNC): array {
		$storage = $this->createStub(StorageService::class);
		$storage->method('findRoot')->willReturn($this->folder($children));

		return $this->service($storage, $mode)->push(null);
	}

	private function service(StorageService $storage, string $mode = Mapping::MODE_SYNC): BulkPushService {
		$mappings = $this->createStub(MappingService::class);
		$mappings->method('list')->willReturn([
			new Mapping('m1', self::TEAM, 'Northwind', 'Penpot', false, $mode),
		]);

		$metadata = $this->createStub(PenpotMetadata::class);
		$metadata->method('readFile')->willReturnCallback(
			fn (int $id): ?PenpotFileMetadata => ($this->idByFile[$id] ?? '') === ''
				? null
				: new PenpotFileMetadata(
					$this->idByFile[$id],
					'r1',
					($this->unmappedFiles[$id] ?? false) ? PenpotMetadata::MODE_UNMAPPED : Mapping::MODE_SYNC,
					self::TEAM,
				),
		);

		$archives = $this->createStub(ArchiveService::class);
		$archives->method('holdsArchive')->willReturnCallback(
			fn (File $node): bool => $this->archiveByFile[$node->getId()] ?? false,
		);

		$resolver = $this->createStub(MembershipResolver::class);
		$resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));

		$destinations = $this->createStub(DestinationResolver::class);
		$destinations->method('projectForContentIn')->willReturn(self::PROJECT);

		$imports = $this->createStub(ImportService::class);
		$imports->method('adopt')->willReturnCallback(
			function (File $node) : ?string {
				$id = $node->getId();
				if (($this->stubbornFiles[$id] ?? false) === true) {
					throw new \RuntimeException('Penpot refused the archive');
				}
				$this->guardedAtImport[$id] = $this->guard->active();
				$this->imported[] = $id;

				return 'new-design-' . $id;
			},
		);

		return new BulkPushService(
			$mappings,
			$storage,
			$metadata,
			$archives,
			$resolver,
			$destinations,
			$imports,
			$this->guard,
			new NullLogger(),
		);
	}

	/** @var array<int, bool> fileId => should the import throw */
	private array $stubbornFiles = [];


	/** @param list<\OCP\Files\Node> $children */
	private function folder(array $children, int $id = 900): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn('/admin/files/Penpot');
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}

	/** A `.penpot` file, with whatever it holds and whatever it names. */
	private function design(
		int $id,
		bool $holdsArchive,
		string $penpotId,
		bool $stubborn = false,
		bool $unmapped = false,
	): File {
		$this->archiveByFile[$id] = $holdsArchive;
		$this->idByFile[$id] = $penpotId;
		$this->stubbornFiles[$id] = $stubborn;
		$this->unmappedFiles[$id] = $unmapped;

		return $this->file($id, 'Gizmo.penpot');
	}

	/** @var array<int, bool> fileId => is its mode `unmapped` */
	private array $unmappedFiles = [];

	private function file(int $id, string $name): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/admin/files/Penpot/' . $name);

		return $file;
	}
}
