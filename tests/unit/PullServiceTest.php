<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The pull reconciler (saga Ch2 Course 3). These tests pin the branching the
 * integration suite can only spot-check on one live team:
 *
 *   - projects are filtered to the mapping's team;
 *   - the default (Drafts) project mirrors its files to the team ROOT, never a
 *     `Drafts` folder (§6.35);
 *   - every mirrored node is stamped — team id on the root, project id on a
 *     project folder, file id/revn/mode on a `.penpot`;
 *   - an illegally-named object (a `/` in the Penpot name) is skipped, not
 *     mirrored, and does not abort the run;
 *   - a Team-Folder mapping is skipped while only the plain backend is built.
 *
 * The Nextcloud filesystem is mocked ({@see Folder}/{@see File} are large public
 * OCP interfaces, so a mock auto-implements every method and only the handful
 * the pull calls are wired). The wire decoding, the real metadata store, and the
 * actual folder writes are the integration suite's job.
 */
final class PullServiceTest extends TestCase {
	private const TEAM_ID = '3fc1681a-2199-8124-8008-000000000001';
	private const OTHER_TEAM_ID = '3fc1681a-2199-8124-8008-0000000000ff';

	private MappingService $mappings;
	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private StorageService $storage;
	private PullService $pull;

	protected function setUp(): void {
		parent::setUp();
		$this->mappings = $this->createMock(MappingService::class);
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->storage = $this->createMock(StorageService::class);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://penpot.example');

		$this->pull = new PullService(
			$this->mappings,
			$this->client,
			$this->metadata,
			$this->storage,
			new SyncGuard(),
			$config,
			new NullLogger(),
		);
	}

	public function testMirrorsTeamProjectsToFoldersAndFilesToLinks(): void {
		$mapping = $this->mapping(useTeamFolder: false);

		$acmeFolder = $this->emptyFolder(20);
		$draftFile = $this->emptyFile(30);
		$acmeFile = $this->emptyFile(40);
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([]);
		$root->method('nodeExists')->willReturn(false);
		$root->method('newFolder')->willReturn($acmeFolder);
		$root->method('newFile')->willReturn($draftFile);
		$acmeFolder->method('newFile')->willReturn($acmeFile);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);

		// Two projects on this team (a default/Drafts and a normal one) plus a
		// project on a DIFFERENT team that must be filtered out entirely.
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-drafts', 'name' => 'Drafts', 'team-id' => self::TEAM_ID, 'is-default' => true],
			['id' => 'proj-acme', 'name' => 'Acme', 'team-id' => self::TEAM_ID, 'is-default' => false],
			['id' => 'proj-other', 'name' => 'Other', 'team-id' => self::OTHER_TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturnMap([
			['proj-drafts', [['id' => 'file-draft', 'name' => 'Sketch', 'revn' => 2]]],
			['proj-acme', [['id' => 'file-acme', 'name' => 'Login', 'revn' => 5]]],
		]);

		// Root gets the team marker; the Acme folder gets the project marker.
		// (Drafts never gets a folder, so no third writeFolder.)
		$this->metadata->expects($this->exactly(2))->method('writeFolder');
		// One stamp per mirrored file.
		$this->metadata->expects($this->exactly(2))->method('writeFile');

		$result = $this->pull->pullOne($mapping);

		self::assertSame(2, $result['processed'], 'only this team\'s two projects are processed');
		self::assertSame(1, $result['folders'], 'only the non-default project becomes a folder');
		self::assertSame(2, $result['files']);
		self::assertSame(0, $result['skipped']);
		self::assertNull($result['error']);
	}

	public function testSkipsTeamFolderMappingWhilePlainBackendOnly(): void {
		$mapping = $this->mapping(useTeamFolder: true);
		$this->storage->method('isAvailable')->willReturn(false);
		$this->storage->expects($this->never())->method('ensureRoot');

		$result = $this->pull->pullOne($mapping);

		self::assertSame(1, $result['skipped']);
		self::assertSame(0, $result['files']);
	}

	public function testSkipsProjectWithIllegalFolderName(): void {
		$mapping = $this->mapping(useTeamFolder: false);
		$root = $this->emptyFolder(10);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-bad', 'name' => 'a/b', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->metadata->expects($this->never())->method('writeFile');

		$result = $this->pull->pullOne($mapping);

		self::assertSame(1, $result['processed']);
		self::assertSame(0, $result['folders']);
		self::assertSame(1, $result['skipped']);
	}

	private function mapping(bool $useTeamFolder): Mapping {
		return Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Ferrone Scotia',
			'nc_folder' => 'Penpot',
			'use_team_folder' => $useTeamFolder,
			'mode' => Mapping::MODE_LINK,
		]);
	}

	private function emptyFolder(int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		return $folder;
	}

	private function emptyFile(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}
}
