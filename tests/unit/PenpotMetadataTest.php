<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The metadata contract the pull and the resolver build on.
 *
 * These pin the two things that would fail *silently* if wrong: the
 * `link` → `reference` wire translation (carried over from both siblings — a
 * stored `link` is `is_callable()` and crashes every PROPFIND), and the strict
 * file-key / folder-key split (a file must never be stamped with a folder
 * marker, or the resolver would find a project id on the file itself and skip
 * the ancestor walk).
 *
 * The manager is mocked but backed by a REAL in-memory `$store` (keyed by node
 * id), so the tests exercise the actual stored bytes — including asserting that
 * `link` is persisted as the literal `reference`, which a mock returning canned
 * values would hide. It is a mock rather than a hand-written class because
 * `IFilesMetadataManager` / `IFilesMetadata` are large public OCP interfaces
 * that ship with `nextcloud/ocp`; mocking auto-implements every method and lets
 * us wire only the handful the service actually calls.
 */
final class PenpotMetadataTest extends TestCase {
	/** @var array<int, array<string,string>> node id → its stored keys (raw wire values). */
	private array $store = [];

	/** @var array<string, bool> key → indexed, as recorded by initMetadata(). */
	private array $inits = [];

	private PenpotMetadata $metadata;

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->inits = [];
		$this->metadata = new PenpotMetadata($this->makeManager());
	}

	public function testRegisterInitialisesEveryKeyAndIndexesTheRightOnes(): void {
		$this->metadata->register();

		self::assertSame(PenpotMetadata::KEYS, array_keys($this->inits), 'every key must be registered');

		// The four ids/mode are indexed so the reconciler can find a folder by
		// its project/team id and files by mode; the revision is a plain prop.
		$indexed = array_keys(array_filter($this->inits));
		self::assertSame(
			[
				PenpotMetadata::KEY_ID,
				PenpotMetadata::KEY_MODE,
				PenpotMetadata::KEY_PROJECT_ID,
				PenpotMetadata::KEY_TEAM_ID,
			],
			$indexed,
		);
		self::assertFalse($this->inits[PenpotMetadata::KEY_REVISION], 'revision is not queried by value');
	}

	public function testLinkModeIsStoredAsReferenceOnTheWire(): void {
		$this->metadata->writeFile(42, [
			PenpotMetadata::KEY_ID => 'file-uuid',
			PenpotMetadata::KEY_MODE => Mapping::MODE_LINK,
		]);

		// The RAW stored value must be `reference`, never `link` — storing `link`
		// crashes core PROPFIND. This is the assertion a canned mock would hide.
		self::assertSame('reference', $this->store[42][PenpotMetadata::KEY_MODE]);

		// …and it reads back in the canonical vocabulary.
		$read = $this->metadata->readFile(42);
		self::assertNotNull($read);
		self::assertTrue($read->isLink());
		self::assertSame(Mapping::MODE_LINK, $read->mode);
	}

	public function testSyncAndUnmappedModesStoreAsIs(): void {
		$this->metadata->writeFile(1, [PenpotMetadata::KEY_ID => 'a', PenpotMetadata::KEY_MODE => Mapping::MODE_SYNC]);
		$this->metadata->writeFile(2, [PenpotMetadata::KEY_ID => 'b', PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED]);

		self::assertSame(Mapping::MODE_SYNC, $this->store[1][PenpotMetadata::KEY_MODE]);
		self::assertSame(PenpotMetadata::MODE_UNMAPPED, $this->store[2][PenpotMetadata::KEY_MODE]);

		$sync = $this->metadata->readFile(1);
		$unmapped = $this->metadata->readFile(2);
		self::assertNotNull($sync);
		self::assertNotNull($unmapped);
		self::assertTrue($sync->isSync());
		self::assertTrue($unmapped->isUnmapped());
	}

	public function testReadFileReturnsNullWhenThereIsNoRecord(): void {
		// A file with no record is the *untracked* state, not an error — the
		// caller must be able to tell it apart from a stored-but-empty file.
		self::assertNull($this->metadata->readFile(999));
	}

	public function testWriteFileIgnoresFolderKeys(): void {
		// The strict split: a folder marker passed to writeFile() must never be
		// stored on the file, or the resolver would read a project id off the
		// file itself and skip the ancestor walk.
		$this->metadata->writeFile(7, [
			PenpotMetadata::KEY_ID => 'file-uuid',
			PenpotMetadata::KEY_PROJECT_ID => 'should-be-ignored',
		]);

		self::assertArrayHasKey(PenpotMetadata::KEY_ID, $this->store[7]);
		self::assertArrayNotHasKey(PenpotMetadata::KEY_PROJECT_ID, $this->store[7]);
	}

	public function testWriteFolderStoresBothMarkersAndReadsThemBack(): void {
		$this->metadata->writeFolder(3, [
			PenpotMetadata::KEY_PROJECT_ID => 'proj-uuid',
			PenpotMetadata::KEY_TEAM_ID => 'team-uuid',
		]);

		$markers = $this->metadata->readFolder(3);
		self::assertSame('proj-uuid', $markers->projectId);
		self::assertSame('team-uuid', $markers->teamId);
		self::assertTrue($markers->hasProject());
		self::assertTrue($markers->hasTeam());
	}

	public function testWriteFolderIgnoresFileKeys(): void {
		$this->metadata->writeFolder(8, [
			PenpotMetadata::KEY_TEAM_ID => 'team-uuid',
			PenpotMetadata::KEY_ID => 'should-be-ignored',
		]);

		self::assertArrayHasKey(PenpotMetadata::KEY_TEAM_ID, $this->store[8]);
		self::assertArrayNotHasKey(PenpotMetadata::KEY_ID, $this->store[8]);
	}

	public function testReadFolderIsTotalAndReturnsBareWhenNoRecord(): void {
		// readFolder never returns null — a bare folder is the common rung the
		// resolver steps over, so a plain ('' / '') keeps its walk null-free.
		$markers = $this->metadata->readFolder(12345);
		self::assertSame('', $markers->projectId);
		self::assertSame('', $markers->teamId);
		self::assertTrue($markers->isBare());
	}

	public function testClearRemovesTheRecord(): void {
		$this->metadata->writeFile(5, [PenpotMetadata::KEY_ID => 'x']);
		self::assertNotNull($this->metadata->readFile(5));

		$this->metadata->clear(5);
		self::assertNull($this->metadata->readFile(5));
	}

	public function testWritingNothingDoesNotCreateARecord(): void {
		// An empty value array is a no-op — it must not materialise a blank
		// record that readFile() would then report as a (mode-less) managed file.
		$this->metadata->writeFile(6, []);
		self::assertNull($this->metadata->readFile(6));
	}

	/**
	 * A mocked {@see IFilesMetadataManager} backed by `$this->store`, so tests
	 * assert on the RAW stored bytes. `getMetadata` throws when a record is
	 * absent and `$generate` is false (the *untracked* signal), and otherwise
	 * hands back a per-node {@see IFilesMetadata} mock whose set/get/has write
	 * straight through to the store.
	 */
	private function makeManager(): IFilesMetadataManager {
		$manager = $this->createMock(IFilesMetadataManager::class);

		$manager->method('getMetadata')->willReturnCallback(
			function (int $fileId, bool $generate = false): IFilesMetadata {
				if (!isset($this->store[$fileId])) {
					if (!$generate) {
						throw new FilesMetadataNotFoundException('no record for ' . $fileId);
					}
					$this->store[$fileId] = [];
				}
				return $this->makeMetadata($fileId);
			},
		);
		$manager->method('deleteMetadata')->willReturnCallback(
			function (int $fileId): void {
				unset($this->store[$fileId]);
			},
		);
		$manager->method('initMetadata')->willReturnCallback(
			function (string $key, string $type, bool $indexed, int $editPermission): void {
				$this->inits[$key] = $indexed;
			},
		);
		// saveMetadata is left as the mock's default no-op: the per-node mock
		// writes through to the store, so there is nothing to flush.

		return $manager;
	}

	/** A per-node {@see IFilesMetadata} mock reading and writing `$this->store[$fileId]`. */
	private function makeMetadata(int $fileId): IFilesMetadata {
		$meta = $this->createMock(IFilesMetadata::class);
		$meta->method('hasKey')->willReturnCallback(
			fn (string $key): bool => isset($this->store[$fileId][$key]),
		);
		$meta->method('getString')->willReturnCallback(
			fn (string $key): string => $this->store[$fileId][$key] ?? '',
		);
		$meta->method('setString')->willReturnCallback(
			function (string $key, string $value, bool $index = false) use ($fileId, $meta): IFilesMetadata {
				$this->store[$fileId][$key] = $value;
				return $meta;
			},
		);
		return $meta;
	}
}
