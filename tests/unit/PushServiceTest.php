<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\PushService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The rename writeback (saga Ch2 Course 4). These pin the decision logic the
 * listener leans on and the integration suite only spot-checks:
 *
 *   - which node types are one of ours to push (`.penpot` file, project folder)
 *     and which are ignored (plain file, unmanaged `.penpot`, team root);
 *   - the `.penpot` extension is stripped before it reaches Penpot (§6.4), and
 *     an empty stem is refused rather than sent;
 *   - attribution: the acting user's personal token rides the call when set,
 *     the service account (null) otherwise (§6.18).
 *
 * The real Penpot API and filesystem are mocked — the wire format is the
 * integration suite's job.
 */
final class PushServiceTest extends TestCase {
	private const FILE_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const PROJECT_ID = '4eda2e11-843e-8045-8008-51824bdafd88';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private PersonalTokenService $personalTokens;
	private MembershipResolver $resolver;

	/** What the resolver reports for every folder; see {@see setUp()}. */
	private Membership $position;

	private PushService $push;

	protected function setUp(): void {
		parent::setUp();
		$this->position = new Membership(null, 'team-abc');
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->personalTokens = $this->createMock(PersonalTokenService::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		// INSIDE A MAPPING BY DEFAULT. Every folder test below is about what gets
		// renamed, not about whether the folder is mirrored at all — and the one
		// test that IS about that says so by overriding this.
		$this->resolver->method('resolve')->willReturnCallback(
			fn (): Membership => $this->position,
		);
		$this->push = new PushService(
			$this->client,
			$this->metadata,
			$this->personalTokens,
			$this->resolver,
			new NullLogger(),
		);
	}

	public function testRenamesAManagedPenpotFileByStrippingTheExtension(): void {
		$this->attributingTo(null);
		$this->metadata->method('readFile')->with(30)
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));

		// The bare stem reaches Penpot, never the `.penpot` affordance; no token → service account.
		$this->client->expects($this->once())->method('renameFile')
			->with(self::FILE_ID, 'Login screen', null);

		self::assertTrue($this->push->pushRename($this->file(30, 'Login screen.penpot')));
	}

	public function testAttributesToThePersonalTokenWhenTheUserHasOne(): void {
		$this->attributingTo('dana-token');
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));

		$this->client->expects($this->once())->method('renameFile')
			->with(self::FILE_ID, 'Login screen', 'dana-token');

		$this->push->pushRename($this->file(30, 'Login screen.penpot'));
	}

	public function testIgnoresAFileThatIsNotAPenpotMirror(): void {
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, 'notes.txt')));
	}

	public function testIgnoresAnUnmanagedPenpotFile(): void {
		$this->metadata->method('readFile')->willReturn(null);
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, 'hand-made.penpot')));
	}

	public function testRefusesAnEmptyPenpotName(): void {
		// A file literally named ".penpot" has no stem — not a legal Penpot name.
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, '.penpot')));
	}

	public function testRenamesAProjectFolder(): void {
		$this->attributingTo(null);
		$this->metadata->method('readFolder')->with(20)
			->willReturn(new FolderMarkers(self::PROJECT_ID, ''));
		$this->resolver->method('pathBelowMapping')->willReturn('Marketing');

		$this->client->expects($this->once())->method('renameProject')
			->with(self::PROJECT_ID, 'Marketing', null);

		self::assertTrue($this->push->pushRename($this->folder(20, 'Marketing')));
	}

	/**
	 * A PROJECT'S NAME IS ITS PATH BELOW THE MAPPING, so a folder three deep is
	 * not called by its own name.
	 *
	 * This is the half the push side used to get wrong, and the failure was
	 * invisible in the flat case: for `Penpot/Old` the path below the mapping and
	 * the folder name are the same string, so every test passed while
	 * `Penpot/foo/Old` was being announced to Penpot as `Old`. The pull has always
	 * read a project name as a path (`PullService::ensureProjectFolder()` hands it
	 * to `newFolder()`), so the two directions disagreed about one fact.
	 *
	 * `projects/rename.feature` pins it: renaming `Penpot/foo/Old` to
	 * `Penpot/foo/New` expects the Penpot project to be named `foo/New`.
	 */
	public function testANestedProjectFolderIsNamedByItsPathBelowTheMapping(): void {
		$this->attributingTo(null);
		$this->metadata->method('readFolder')->with(21)
			->willReturn(new FolderMarkers(self::PROJECT_ID, ''));
		$this->resolver->method('pathBelowMapping')->willReturn('foo/New');

		$this->client->expects($this->once())->method('renameProject')
			->with(self::PROJECT_ID, 'foo/New', null);

		self::assertTrue($this->push->pushRename($this->folder(21, 'New')));
	}

	/**
	 * A folder the resolver cannot place is not renamed at all.
	 *
	 * `pathBelowMapping()` returns null for a node outside every mapping and for a
	 * mapping ROOT, and neither is a project — so the guard is what keeps a
	 * `renameProject` call with an empty name off the wire.
	 */
	public function testDoesNotRenameAProjectItCannotName(): void {
		$this->metadata->method('readFolder')->with(22)
			->willReturn(new FolderMarkers(self::PROJECT_ID, ''));
		$this->resolver->method('pathBelowMapping')->willReturn(null);

		$this->client->expects($this->never())->method('renameProject');

		self::assertFalse($this->push->pushRename($this->folder(22, 'Orphan')));
	}

	public function testIgnoresTheTeamRootFolder(): void {
		// The root carries a team id, and renaming it renames nothing: a project's
		// name is its path BELOW the root, so every one of those paths is exactly
		// what it was. Walking the tree would send one `rename-project` per project,
		// each to the name it already has.
		$this->metadata->method('readFolder')
			->willReturn(new FolderMarkers('', 'team-abc'));
		$this->client->expects($this->never())->method('renameProject');

		self::assertFalse($this->push->pushRename($this->folder(10, 'Design Files')));
	}

	public function testIgnoresAFolderOutsideEveryMapping(): void {
		// AND DOES NOT WALK IT. This is what keeps an ordinary folder rename
		// anywhere else in the instance from costing a directory listing per level.
		$this->position = Membership::none();
		$folder = $this->folder(23, 'Holiday photos');
		$folder->expects($this->never())->method('getDirectoryListing');
		$this->client->expects($this->never())->method('renameProject');

		self::assertFalse($this->push->pushRename($folder));
	}

	/**
	 * THE COST OF THE PATH MODEL, pinned. Dragging `Penpot/foo` into
	 * `Penpot/Clients` renames every project named THROUGH it — Penpot has no
	 * parent field, so there is no atomic re-parent to send and each one is its own
	 * `rename-project`.
	 *
	 * `foo` itself is NOT a project here, which is the case that made this a walk
	 * rather than a special case: a plain folder someone groups their projects
	 * under has no Penpot counterpart at all, and moving it still renames
	 * everything below it.
	 */
	public function testRenamesEveryProjectBelowAMovedFolder(): void {
		$this->attributingTo(null);

		$bar = $this->folder(31, 'bar');
		$baz = $this->folder(32, 'baz');
		$bar->method('getDirectoryListing')->willReturn([$baz]);
		$foo = $this->folder(30, 'foo');
		$foo->method('getDirectoryListing')->willReturn([$bar]);

		$this->metadata->method('readFolder')->willReturnCallback(
			static fn (int $id): FolderMarkers => match ($id) {
				31 => new FolderMarkers('project-bar', ''),
				32 => new FolderMarkers('project-baz', ''),
				default => new FolderMarkers('', ''),
			},
		);
		$this->resolver->method('pathBelowMapping')->willReturnCallback(
			static fn (Folder $folder): ?string => match ($folder->getId()) {
				31 => 'Clients/foo/bar',
				32 => 'Clients/foo/bar/baz',
				default => 'Clients/foo',
			},
		);

		$renamed = [];
		$this->client->method('renameProject')->willReturnCallback(
			static function (string $projectId, string $name) use (&$renamed): void {
				$renamed[$projectId] = $name;
			},
		);

		self::assertTrue($this->push->pushRename($foo));
		self::assertSame(
			['project-bar' => 'Clients/foo/bar', 'project-baz' => 'Clients/foo/bar/baz'],
			$renamed,
		);
	}

	/** Who the write attributes to; null is the service account (§6.18). */
	private function attributingTo(?string $token): void {
		$this->personalTokens->method('tokenForActor')->willReturn($token);
	}

	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	/**
	 * A folder mock. `getDirectoryListing()` is left unstubbed so it answers with
	 * an empty array — a leaf — and the tests that need children say so.
	 *
	 * @return Folder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function folder(int $id, string $name): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn($name);
		$folder->method('getPath')->willReturn('/dana/files/' . $name);
		return $folder;
	}
}
