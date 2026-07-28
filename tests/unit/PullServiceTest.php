<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
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
	private ArchiveService $archives;
	private PullService $pull;

	protected function setUp(): void {
		parent::setUp();
		$this->mappings = $this->createMock(MappingService::class);
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->storage = $this->createMock(StorageService::class);
		$this->archives = $this->createMock(ArchiveService::class);

		$this->pull = new PullService(
			$this->mappings,
			$this->client,
			$this->metadata,
			$this->storage,
			$this->archives,
			new SyncGuard(),
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

	// ── `sync` mode: the only thing that costs anything (saga §6.22) ────────

	/**
	 * THE COST PROPERTY THE WHOLE MODE AXIS EXISTS FOR. A team of `link` files
	 * reconciles names, placement and revisions for nothing — the listing already
	 * carries all three (§5.5) — and `export-binfile` is never called.
	 *
	 * If this ever fails, a 500-file team has quietly become 500 exports a pull.
	 */
	public function testALinkTeamNeverExports(): void {
		$this->givenOneFile(Mapping::MODE_LINK, stampedMode: '', stored: '', holdsArchive: false);

		$this->archives->expects($this->never())->method('storeArchive');
		$this->archives->expects($this->once())->method('storeLink');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(0, $result['exported']);
	}

	/**
	 * An unchanged `sync` file that already holds its archive costs nothing
	 * either. The drift signal is compared first precisely so that the common
	 * case — nothing changed upstream — never touches the filesystem or the wire.
	 */
	public function testAnUnchangedSyncFileIsNotReExported(): void {
		$this->givenOneFile(Mapping::MODE_SYNC, stampedMode: Mapping::MODE_SYNC, stored: '5@t1', holdsArchive: true);

		$this->archives->expects($this->never())->method('storeArchive');
		// The pointer body is NOT rewritten over a stored archive either — that
		// would delete the backup on every pull, which is the exact accident this
		// ordering exists to prevent.
		$this->archives->expects($this->never())->method('storeLink');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(0, $result['exported']);
	}

	/** The design moved on upstream, so the archive is refetched. */
	public function testASyncFileWhoseRevisionMovedIsReExported(): void {
		$this->givenOneFile(Mapping::MODE_SYNC, stampedMode: Mapping::MODE_SYNC, stored: '4@t0', holdsArchive: true);

		$this->archives->expects($this->once())->method('storeArchive')->willReturn(1234);

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['exported']);
		self::assertSame(0, $result['failed']);
	}

	/**
	 * THE SELF-HEALING CASE, and the reason the check is not a pure string
	 * compare. A file stamped `sync` that holds no archive is a promotion whose
	 * export never landed. Trusting the stamp alone would leave it a pointer
	 * wearing a backup's label until someone went looking.
	 */
	public function testASyncFileMissingItsArchiveIsExportedEvenWithoutDrift(): void {
		$this->givenOneFile(Mapping::MODE_SYNC, stampedMode: Mapping::MODE_SYNC, stored: '5@t1', holdsArchive: false);

		$this->archives->expects($this->once())->method('storeArchive')->willReturn(99);

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['exported']);
	}

	/**
	 * A FAILED EXPORT DOES NOT ADVANCE THE REVISION STAMP (saga §6.18 rule 3).
	 * Recording the new signal would tell every later pull "this mirror is
	 * current" about a file that never got its bytes — one transient 502 and the
	 * backup silently stops updating forever.
	 */
	public function testAFailedExportLeavesTheRevisionStampAloneSoTheNextPullRetries(): void {
		$this->givenOneFile(Mapping::MODE_SYNC, stampedMode: Mapping::MODE_SYNC, stored: '4@t0', holdsArchive: true);

		$this->archives->method('storeArchive')
			->willThrowException(new PenpotApiException('boom'));

		$this->metadata->expects($this->once())
			->method('writeFile')
			->with(30, self::callback(static fn (array $v): bool => !array_key_exists(PenpotMetadata::KEY_REVISION, $v)));

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		// Reported, and NOT an error: the file's name, placement and ids all
		// reconciled, and the previous archive is untouched.
		self::assertSame(1, $result['failed']);
		self::assertSame(0, $result['exported']);
		self::assertNull($result['error']);
	}

	/**
	 * A MAPPING DEFAULT NEVER REWRITES A FILE'S OWN MODE. Flipping a mapping to
	 * `sync` must not retroactively download every file that a user deliberately
	 * left as a link — nor the reverse, which would delete a pile of archives.
	 */
	public function testAnExistingFileKeepsItsOwnModeAgainstTheMappingDefault(): void {
		$this->givenOneFile(Mapping::MODE_SYNC, stampedMode: Mapping::MODE_LINK, stored: '5@t1', holdsArchive: false);

		$this->archives->expects($this->never())->method('storeArchive');

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false, mode: Mapping::MODE_SYNC))['exported']);
	}

	/**
	 * One team, one Drafts project, one existing file — the fixture every
	 * mode test above varies.
	 *
	 * @param string $mappingMode the mapping's default (only reaches a NEW file)
	 * @param string $stampedMode what is stamped on the existing file ('' = none)
	 * @param string $stored the file's stored revision signal
	 */
	private function givenOneFile(string $mappingMode, string $stampedMode, string $stored, bool $holdsArchive): void {
		$file = $this->emptyFile(30);
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$file]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-drafts', 'name' => 'Drafts', 'team-id' => self::TEAM_ID, 'is-default' => true],
		]);
		// `revn` 5 at `modified-at` t1 — the signal the stored value is compared to.
		$this->client->method('getProjectFiles')->willReturn([
			['id' => 'file-1', 'name' => 'Login', 'revn' => 5, 'modified-at' => 't1'],
		]);

		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata('file-1', $stored, $stampedMode !== '' ? $stampedMode : $mappingMode),
		);
		$this->archives->method('holdsArchive')->willReturn($holdsArchive);
	}

	private function mapping(bool $useTeamFolder, string $mode = Mapping::MODE_LINK): Mapping {
		return Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'North Wind',
			'nc_folder' => 'Penpot',
			'use_team_folder' => $useTeamFolder,
			'mode' => $mode,
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
