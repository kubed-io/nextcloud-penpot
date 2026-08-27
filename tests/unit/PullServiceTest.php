<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\MirrorTimes;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\ProjectTags;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCA\PenpotSync\Service\TrashControl;
use OCA\PenpotSync\Service\TrashReconcileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
 *   - a project's name is a PATH — a `/` in it is a level of nesting, mirrored as
 *     one, while a name that cannot become a path at all is skipped without
 *     aborting the run (a `/` in a FILE's name is still simply illegal);
 *   - a Team-Folder mapping is skipped while only the plain backend is built;
 *   - and **the prune** — which of the many ways a listing can be incomplete
 *     must switch it off entirely, since every one of them looks exactly like
 *     "Penpot deleted everything".
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
	private ProjectTags $tags;
	private MirrorTimes $times;
	private PullService $pull;

	/**
	 * Node id -> what {@see PenpotMetadata::readFile()} reports for it. One map
	 * instead of a per-test stub, because several tests now need DIFFERENT answers
	 * for different nodes in the same run, and re-stubbing one mock method twice is
	 * how a fixture starts lying.
	 *
	 * @var array<int, PenpotFileMetadata>
	 */
	private array $stamps = [];

	/**
	 * Node id -> folder markers. Needed since the upsert index learned to descend
	 * (§C6.20): the walk asks each subfolder whether it is a project of its own,
	 * so a blanket "no markers" answer could not express a nested project folder.
	 *
	 * @var array<int, FolderMarkers>
	 */
	private array $folderMarkersById = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mappings = $this->createMock(MappingService::class);
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->storage = $this->createMock(StorageService::class);
		$this->archives = $this->createMock(ArchiveService::class);
		$this->tags = $this->createMock(ProjectTags::class);

		// An unstamped node reads back as null — *untracked*, the state that makes a
		// file the user's rather than ours.
		$this->metadata->method('readFile')->willReturnCallback(
			fn (int $nodeId): ?PenpotFileMetadata => $this->stamps[$nodeId] ?? null,
		);
		// A folder with no markers. Unlike readFile there is no null state here, so
		// this has to be a real value object — a mock's auto-stub would hand back an
		// object whose readonly promoted properties were never initialised.
		$this->metadata->method('readFolder')->willReturnCallback(
			fn (int $id): FolderMarkers => $this->folderMarkersById[$id] ?? new FolderMarkers('', ''),
		);

		$this->times = $this->createMock(MirrorTimes::class);

		$this->pull = new PullService(
			$this->mappings,
			$this->client,
			$this->metadata,
			$this->storage,
			$this->archives,
			$this->tags,
			new SyncGuard(),
			// MirrorTimes reaches into the storage/cache stack, so it is mocked here
			// and covered on its own in MirrorTimesTest — the pull only owes the field
			// mapping and the "did we just write?" flag.
			$this->times,
			// The real one over a container that resolves nothing: `files_trashbin`
			// is not loaded in the unit environment, so `withoutTrash()` falls
			// through to running the callback — which is exactly its documented
			// behaviour on an instance without the trash app.
			new TrashControl(
				$this->createStub(ContainerInterface::class),
				$this->createStub(IUserManager::class),
				new NullLogger(),
			),
			// The trash pass is covered on its own in TrashReconcileServiceTest; here
			// it is a mock so no test in this file has to arrange a trash it never
			// mentions. `reap()` returns 0 by default, which is the answer for every
			// scenario below.
			$this->createMock(TrashReconcileService::class),
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

	/**
	 * A name that cannot become a folder path at all — an empty segment here, and
	 * `..` below. Not the same thing as a `/`, which is now a level of nesting.
	 */
	public function testSkipsProjectWithAnEmptyPathSegment(): void {
		$this->thenTheProjectNamedIsSkipped('a//b');
	}

	/**
	 * `..` IS THE ONE THAT MATTERS. Every other rejected name is untidy; this one
	 * would walk a project's folder OUT of the mapping root, and a `.` would
	 * resolve to the root itself and mirror a project's files over it.
	 */
	public function testSkipsProjectWhoseNameWouldEscapeTheRoot(): void {
		$this->thenTheProjectNamedIsSkipped('../Elsewhere');
	}

	private function thenTheProjectNamedIsSkipped(string $name): void {
		$root = $this->emptyFolder(10);
		$root->expects($this->never())->method('newFolder');
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-bad', 'name' => $name, 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->metadata->expects($this->never())->method('writeFile');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['processed']);
		self::assertSame(0, $result['folders']);
		self::assertSame(1, $result['skipped']);
	}

	// ── a project's name is a PATH, in both directions (§C6.38) ─────────────

	/**
	 * THE BUG THIS SECTION EXISTS FOR, measured live: PushService has always
	 * renamed a project to its path below the mapping when its folder moves, and
	 * the pull rejected every one of those names as illegal — so the app wrote
	 * names it then refused to read back. A project named `Bubbles/foo` produced
	 * no folder at all.
	 *
	 * ONLY THE LAST SEGMENT IS THE PROJECT. `Bubbles` is scaffolding and must carry
	 * no markers, or the resolver's nearest-ancestor walk would attribute the
	 * project's designs to a folder Penpot has never heard of.
	 */
	public function testASlashedProjectNameBecomesNestedFolders(): void {
		$bubbles = $this->emptyFolder(20);
		$foo = $this->emptyFolder(21);
		$bubbles->method('newFolder')->willReturn($foo);
		$bubbles->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([]);
		$root->method('nodeExists')->willReturn(false);
		$root->expects($this->once())->method('newFolder')->with('Bubbles')->willReturn($bubbles);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Bubbles/foo', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$bubbles->expects($this->once())->method('newFolder')->with('foo');
		// NO TAG, on either of them. The id is the only marker a project folder gets.
		$this->tags->expects($this->never())->method('apply');

		$stamped = [];
		$this->metadata->method('writeFolder')->willReturnCallback(
			static function (int $id, array $values) use (&$stamped): void {
				$stamped[$id] = $values;
			},
		);
		// The leaf is tagged as a project; the folder holding it is not.
		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['folders']);
		self::assertSame(0, $result['skipped']);
		self::assertSame([10, 21], array_keys($stamped), 'the root and the leaf are stamped, the scaffolding is not');
		self::assertSame('proj-b', $stamped[21][PenpotMetadata::KEY_PROJECT_ID]);
	}

	/**
	 * The folder is MOVED, not re-made, because the id is what identifies it — so
	 * the designs and the user's own files inside it come along.
	 *
	 * `Bubbles` becoming `Bubbles/foo` asks a folder to move inside itself, which
	 * no filesystem will do. It parks under the root first, and the two moves in
	 * order are the whole point of this test.
	 */
	public function testAProjectFolderMovesWhenItsPenpotNameGainsALevel(): void {
		$node = $this->emptyFolder(20);
		$node->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');
		$this->folderMarkersById[20] = new FolderMarkers('proj-b', '');

		$nested = $this->emptyFolder(21);
		$nested->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$node]);
		$root->method('nodeExists')->willReturn(false);
		$root->method('newFolder')->willReturn($nested);
		$node->method('getParent')->willReturn($root);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Bubbles/foo', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$moves = [];
		$node->expects($this->exactly(2))->method('move')->willReturnCallback(
			function (string $to) use (&$moves, &$node): Folder {
				$moves[] = $to;

				return $node;
			},
		);

		$this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame([
			'/admin/files/Penpot/.penpot-moving-20',
			'/admin/files/Penpot/Bubbles/foo',
		], $moves);
	}

	/**
	 * And the same folder going back to `Bubbles` — which asks it to take the name
	 * its own parent is using. The parent is cleared out from under it while it is
	 * parked, so the move home lands on the real name rather than `Bubbles (2)`.
	 */
	public function testAProjectFolderLosingALevelClearsTheFolderItLeaves(): void {
		$node = $this->emptyFolder(21);
		$node->method('getPath')->willReturn('/admin/files/Penpot/Bubbles/foo');
		$this->folderMarkersById[21] = new FolderMarkers('proj-b', '');

		$inside = [$node];
		$bubbles = $this->createMock(Folder::class);
		$bubbles->method('getId')->willReturn(20);
		$bubbles->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');
		$bubbles->method('nodeExists')->willReturn(false);
		// A CLOSURE, NOT AN ARROW FUNCTION. `fn` captures by VALUE at creation, so
		// `$inside` would be frozen holding the node this test is about to move out.
		$bubbles->method('getDirectoryListing')->willReturnCallback(
			static function () use (&$inside): array {
				return $inside;
			},
		);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$bubbles]);
		$root->method('nodeExists')->willReturn(false);
		$bubbles->method('getParent')->willReturn($root);
		$node->method('getParent')->willReturn($bubbles);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Bubbles', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$moves = [];
		$node->expects($this->exactly(2))->method('move')->willReturnCallback(
			function (string $to) use (&$moves, &$inside, &$node): Folder {
				$moves[] = $to;
				$inside = [];

				return $node;
			},
		);
		// The empty scaffolding goes to the trash rather than standing forever.
		$bubbles->expects($this->once())->method('delete');
		$root->expects($this->never())->method('newFolder');

		$this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame([
			'/admin/files/Penpot/.penpot-moving-21',
			'/admin/files/Penpot/Bubbles',
		], $moves);
	}

	/**
	 * THE INDEX HAS TO DESCEND, and this is what it costs when it does not: the
	 * folder for a nested project is invisible to a scan of the root's children,
	 * so the pull creates a SECOND folder for it at the root — every pull, each
	 * one stamped with the same project id.
	 */
	public function testANestedProjectFolderIsFoundRatherThanDuplicated(): void {
		$foo = $this->emptyFolder(21);
		$foo->method('getPath')->willReturn('/admin/files/Penpot/Bubbles/foo');
		$this->folderMarkersById[21] = new FolderMarkers('proj-b', '');

		$bubbles = $this->createMock(Folder::class);
		$bubbles->method('getId')->willReturn(20);
		$bubbles->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');
		$bubbles->method('getDirectoryListing')->willReturn([$foo]);
		$bubbles->method('nodeExists')->willReturn(false);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$bubbles]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Bubbles/foo', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$root->expects($this->never())->method('newFolder');
		$bubbles->expects($this->never())->method('newFolder');
		$foo->expects($this->never())->method('move');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['folders']);
		self::assertSame(0, $result['skipped']);
	}

	/**
	 * AND THE FOLDERS IT MADE ON THE WAY DOWN GO WITH IT. `a/b/c` can create `a` and
	 * only then find that `b` is a file — and nothing would ever look at the `a` it
	 * left behind: the tidy only runs behind a move that happened, and the orphan
	 * reap only considers folders carrying a project id. Raised by Copilot on #50.
	 */
	public function testScaffoldingIsRolledBackWhenTheRestOfThePathFails(): void {
		$blocker = $this->emptyFile(30);
		$blocker->method('getPath')->willReturn('/admin/files/Penpot/a/b');

		$made = $this->createMock(Folder::class);
		$made->method('getId')->willReturn(20);
		$made->method('getPath')->willReturn('/admin/files/Penpot/a');
		$made->method('getDirectoryListing')->willReturn([]);
		$made->method('nodeExists')->willReturn(true);
		$made->method('get')->willReturn($blocker);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([]);
		$root->method('nodeExists')->willReturn(false);
		$root->method('newFolder')->willReturn($made);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'a/b/c', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);

		$made->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['skipped']);
		self::assertSame(0, $result['folders']);
	}

	/**
	 * ADOPTION TAKES A BARE FOLDER ONLY. Two Penpot projects may share a name (§31),
	 * so the folder already sitting on it can belong to the OTHER one — and adopting
	 * it would re-stamp it with this project's id, handing one project's folder, its
	 * designs and its history to another. The newcomer takes a free name instead.
	 * Raised by Copilot on #50.
	 */
	public function testAFolderAlreadyClaimedByAnotherProjectIsNotAdopted(): void {
		$taken = $this->emptyFolder(20);
		$taken->method('getPath')->willReturn('/admin/files/Penpot/Cogs');
		$this->folderMarkersById[20] = new FolderMarkers('proj-other', '');
		$fresh = $this->emptyFolder(21);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$taken]);
		$root->method('nodeExists')->willReturnCallback(static fn (string $n): bool => $n === 'Cogs');
		$root->method('get')->willReturn($taken);
		$root->expects($this->once())->method('newFolder')->with('Cogs (2)')->willReturn($fresh);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-other', 'name' => 'Cogs', 'team-id' => self::TEAM_ID, 'is-default' => false],
			['id' => 'proj-b', 'name' => 'Cogs', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$stamped = [];
		$this->metadata->method('writeFolder')->willReturnCallback(
			static function (int $id, array $values) use (&$stamped): void {
				$stamped[$id] = $values;
			},
		);

		$this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame('proj-other', $stamped[20][PenpotMetadata::KEY_PROJECT_ID], 'the folder keeps the project it already had');
		self::assertSame('proj-b', $stamped[21][PenpotMetadata::KEY_PROJECT_ID]);
	}

	/**
	 * AND THE SUFFIX IS NOT RE-COMPUTED EVERY PULL. `Cogs` is taken — by the other
	 * project above, or by a user's own file — so this folder is `Cogs (2)` and
	 * stays it. Taking a free name here instead would rename it `Cogs (3)` on this
	 * pull and `Cogs (4)` on the next, forever: the wanted name never frees up, and
	 * the suffix is computed against a listing holding this very folder.
	 */
	public function testAProjectFolderOnASuffixedNameStaysOnIt(): void {
		$node = $this->emptyFolder(21);
		$node->method('getPath')->willReturn('/admin/files/Penpot/Cogs (2)');
		$this->folderMarkersById[21] = new FolderMarkers('proj-b', '');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$node]);
		$root->method('nodeExists')->willReturnCallback(static fn (string $n): bool => $n === 'Cogs');
		$node->method('getParent')->willReturn($root);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Cogs', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$node->expects($this->never())->method('move');
		$root->expects($this->never())->method('newFolder');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['folders']);
	}

	/**
	 * A FILE standing where a project's parent folder belongs is refused outright,
	 * and that is deliberately unlike every other name collision here. `freeName()`
	 * would mirror the project under `Bubbles (2)/` — a path Penpot never named —
	 * and the next pull would find neither and do it again one suffix higher.
	 */
	public function testAFileInTheWayOfAProjectPathIsASkipNotASuffix(): void {
		$blocker = $this->emptyFile(20);
		$blocker->method('getPath')->willReturn('/admin/files/Penpot/Bubbles');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getPath')->willReturn('/admin/files/Penpot');
		$root->method('getDirectoryListing')->willReturn([$blocker]);
		$root->method('nodeExists')->willReturn(true);
		$root->method('get')->willReturn($blocker);
		$root->expects($this->never())->method('newFolder');

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-b', 'name' => 'Bubbles/foo', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['skipped'], 'one project skipped, not a failed mapping');
		self::assertSame(0, $result['folders']);
		self::assertNull($result['error']);
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
		// storeLink() is NOT called over a stored archive either — since §C6.6 it
		// empties the file, so calling it here would delete the backup on every
		// pull. That is the exact accident this ordering exists to prevent, and
		// the stakes went UP when a link stopped being a body and became a
		// truncation.
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

	// ── the prune: the most dangerous thing this app does (saga §6.25/§6.46) ──

	/**
	 * WHY A MIRROR VANISHED DECIDES WHERE IT GOES, and the two answers are not
	 * equally recoverable.
	 *
	 * A design that was DELETED in Penpot may have this file as its last copy in
	 * existence, so the mirror goes to the Nextcloud trash. A design that was MOVED
	 * is alive and well somewhere we no longer mirror, and a trashed mirror would
	 * read as a deletion that never happened. The prune tells them apart with
	 * `get-file-summary` plus the team's trash listing, and every test below is one
	 * cell of that table.
	 */
	public function testADesignDeletedInPenpotIsSnapshottedThenTrashed(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: true, trashed: true);
		$this->archives->method('holdsArchive')->willReturn(false);

		$this->archives->expects($this->once())->method('storeArchive')->with($stale, 'file-gone')->willReturn(4096);
		$stale->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertSame(1, $result['rescued']);
		self::assertSame(0, $result['lost']);
	}

	/**
	 * A design PURGED in Penpot — gone from every listing and from its trash — is
	 * the case where the local file is genuinely the last copy, which is precisely
	 * why it must land somewhere recoverable rather than be discarded as a move.
	 */
	public function testADesignPurgedInPenpotStillOnlyReachesTheNextcloudTrash(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: false, trashed: false);
		$this->archives->method('holdsArchive')->willReturn(true);

		$stale->expects($this->once())->method('delete');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	/**
	 * A DESIGN THAT MOVED LEAVES NO TRASH ENTRY. Nothing was deleted, so offering a
	 * restore would describe something that did not happen — and the design itself
	 * is untouched in whichever team it went to.
	 */
	public function testADesignMovedOutOfTheMappingInPenpotLeavesNoTrashEntry(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: true, trashed: false);
		$this->archives->method('holdsArchive')->willReturn(true);

		// No last-gasp export either: the design is fine, so there is nothing to
		// rescue it from.
		$this->archives->expects($this->never())->method('storeArchive');
		$stale->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertSame(0, $result['rescued']);
		self::assertSame(0, $result['lost']);
	}

	/**
	 * A PROBE THAT CANNOT ANSWER MUST NOT LOOK LIKE A "NO".
	 *
	 * `fileExists()` returns null when Penpot is unreachable, unauthorised, or
	 * answering a schema we read wrong. Reading that as "gone" would send the
	 * mirror down the discard path and destroy it permanently — so null is treated
	 * exactly as "still there", and the trash listing alone decides.
	 */
	public function testAnUnanswerableProbeKeepsTheMirrorRatherThanDestroyingIt(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: null, trashed: true);
		$this->archives->method('holdsArchive')->willReturn(true);

		$stale->expects($this->once())->method('delete');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	/**
	 * THE ROW ABOVE PASSED WHILE THE BUG WAS LIVE, which is the point of this one.
	 *
	 * With `trashed: true` the trash listing keeps the mirror on its own, so the
	 * probe's answer never decides anything and a guard written `!== false` — which
	 * counts "could not tell" as a YES — looks perfectly fine. It is only when the
	 * probe is unsure AND the trash listing is empty that the two spellings diverge,
	 * and there `!== false` takes the PERMANENT discard on a file whose design may
	 * be gone for good.
	 *
	 * So this is the same claim as above with the one input changed that makes it
	 * load-bearing. Found by review on #44, not by the suite.
	 */
	public function testAnUnanswerableProbeWithAnEmptyTrashStillKeepsTheMirror(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: null, trashed: false);

		// THE RESCUE IS THE DISCRIMINATOR, and picking it took a second attempt.
		// `delete()` cannot tell these paths apart here: `TrashControl` finds no
		// trash app to pause in the unit environment, so a discard and a trashing
		// are the same call, and asserting on it gives a test that passes whichever
		// branch runs. The last-gasp export is the difference — the discard path
		// skips it (the design is fine, there is nothing to rescue) and the
		// recoverable path takes it, and `rescued` counts it.
		$this->archives->method('holdsArchive')->willReturn(false);
		$this->archives->expects($this->once())->method('storeArchive')->willReturn(4096);

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertSame(1, $result['rescued'], 'an unsure probe must take the recoverable path, not the discard');
	}

	/**
	 * A TRASH LISTING WE CANNOT READ IS ALSO NOT A "NO" — same rule, other probe.
	 * Without this, a Penpot that went down between the two calls would turn every
	 * pruned mirror into a permanent delete.
	 */
	public function testATrashListingThatFailsKeepsTheMirror(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->client->method('fileExists')->willReturn(true);
		$this->client->method('deletedFiles')->willThrowException(new PenpotApiException('down'));
		$this->archives->method('holdsArchive')->willReturn(true);

		$stale->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertNull($result['error'], 'an unreadable trash listing is not a failed pull');
	}

	/**
	 * A LINK IS NEVER SNAPSHOTTED, NEVER PROMOTED, AND NEVER TRASHED.
	 *
	 * It holds no bytes, so there is nothing for a restore to reconnect to. This is
	 * the branch that used to fall through to the rescue — and because a link is
	 * exactly the file that holds no archive, every departing link was exported and
	 * re-stamped `sync`. That was the last surviving link→sync promotion in the app;
	 * per-file mode changes were retired courses ago.
	 */
	public function testAVanishedLinkIsDiscardedWithoutBecomingASyncFile(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_LINK);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: true, trashed: true);
		$this->archives->method('holdsArchive')->willReturn(false);

		$this->archives->expects($this->never())->method('storeArchive');
		$this->metadata->expects($this->never())->method('writeFile');
		$stale->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertSame(0, $result['rescued'], 'a link has nothing to rescue');
	}

	/**
	 * Past Penpot's grace window the export simply fails. The mirror is still
	 * trashed — leaving it would be a mirror of nothing — and the loss is counted
	 * rather than a snapshot being claimed.
	 */
	public function testASnapshotThatCannotBeTakenIsReportedNotFaked(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: false, trashed: false);
		$this->archives->method('holdsArchive')->willReturn(false);
		$this->archives->method('storeArchive')->willThrowException(new PenpotApiException('gone for good'));

		$stale->expects($this->once())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['pruned']);
		self::assertSame(0, $result['rescued']);
		self::assertSame(1, $result['lost']);
		self::assertNull($result['error'], 'an unrecoverable snapshot is reported, not a failed pull');
	}

	/**
	 * A MIRROR THAT COULD NOT BE TRASHED COUNTS FOR NOTHING — not even its
	 * snapshot. The CLI prints `rescued` and `lost` as a breakdown OF `pruned`, so
	 * three numbers that do not add up would read as a bug in whichever one the
	 * operator trusted least. The archive is still on disk and the next pull's
	 * delete is now free; it simply did not prune anything this time.
	 */
	public function testAMirrorThatCannotBeTrashedIsCountedNowhere(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: true, trashed: true);
		$this->archives->method('holdsArchive')->willReturn(false);
		$this->archives->method('storeArchive')->willReturn(4096);
		$stale->method('delete')->willThrowException(new \RuntimeException('locked'));

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(0, $result['pruned']);
		self::assertSame(0, $result['rescued']);
		self::assertSame(0, $result['lost']);
		self::assertNull($result['error'], 'one stuck mirror is not a failed pull');
	}

	/** The same, on the discard path: a delete that throws prunes nothing. */
	public function testAMirrorThatCannotBeDiscardedIsCountedNowhere(): void {
		$stale = $this->mirror(31, 'file-gone', mode: Mapping::MODE_SYNC);
		$this->givenRootHolding([$stale], listing: []);
		$this->givenPenpotSays(exists: true, trashed: false);
		$this->archives->method('holdsArchive')->willReturn(true);
		$stale->method('delete')->willThrowException(new \RuntimeException('locked'));

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	/**
	 * THE ONE THAT MATTERS MOST. A project skipped for any reason means its files
	 * were never enumerated, which is indistinguishable from Penpot no longer
	 * having them. Pruning on that evidence would trash a whole project's mirrors
	 * because of a slash in a name — so an incomplete listing prunes nothing at
	 * all, not even the files it *did* see.
	 */
	public function testAnIncompleteListingPrunesNothing(): void {
		$stale = $this->mirror(31, 'file-gone');
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$stale]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		// An empty segment — a name that cannot become a path at all. A plain `/` is
		// a level of nesting now, and would be mirrored rather than skipped.
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-bad', 'name' => 'a//b', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);

		$stale->expects($this->never())->method('delete');
		$this->archives->expects($this->never())->method('storeArchive');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(1, $result['skipped']);
		self::assertSame(0, $result['pruned']);
	}

	/**
	 * A LISTING FAILURE PRUNES NOTHING EITHER — stated here as a test rather than
	 * left to control flow, because "the exception happens to escape before the
	 * prune" is exactly the kind of protection a later refactor removes silently.
	 */
	public function testAFailedListingPrunesNothing(): void {
		$stale = $this->mirror(31, 'file-gone');
		$this->givenRootHolding([$stale], listing: []);
		$this->client->method('getProjectFiles')->willThrowException(new PenpotApiException('502'));

		$stale->expects($this->never())->method('delete');

		$result = $this->pull->pullOne($this->mapping(useTeamFolder: false));

		self::assertSame(0, $result['pruned']);
		self::assertSame('502', $result['error']);
	}

	/**
	 * An unstamped file is a file the user put there. Position proves nothing
	 * under free nesting, so the `penpot_id` stamp is the only thing that makes a
	 * node ours to remove — and a mapped folder stays usable as an ordinary one.
	 */
	public function testAnUnstampedFileIsNeverPruned(): void {
		$theirs = $this->createMock(File::class);
		$theirs->method('getId')->willReturn(31);
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$theirs]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-drafts', 'name' => 'Drafts', 'team-id' => self::TEAM_ID, 'is-default' => true],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$theirs->expects($this->never())->method('delete');

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	/**
	 * The prune walks the whole tree, because free nesting lets a mirror sit in
	 * any plain subfolder the user made (saga §6.29). A one-level prune would
	 * leave the moved ones behind forever while looking like it worked.
	 */
	public function testAMirrorInAPlainSubfolderIsStillPruned(): void {
		$stale = $this->mirror(31, 'file-gone');
		$nested = $this->createMock(Folder::class);
		$nested->method('getId')->willReturn(20);
		$nested->method('getDirectoryListing')->willReturn([$stale]);
		$nested->method('nodeExists')->willReturn(false);

		$this->givenRootHolding([$nested], listing: []);
		$this->archives->method('holdsArchive')->willReturn(true);

		$stale->expects($this->once())->method('delete');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	/**
	 * A stale mirror alongside a live one: only the vanished design is trashed.
	 * The seen-set is per-`penpot_id`, not per-folder, so a design that moved
	 * project in Penpot is never mistaken for a deleted one.
	 */
	public function testAStillPresentDesignIsLeftAlone(): void {
		$live = $this->mirror(30, 'file-1', revision: '5@t1');
		$stale = $this->mirror(31, 'file-gone');
		$this->givenRootHolding([$live, $stale], listing: [
			['id' => 'file-1', 'name' => 'Login', 'revn' => 5, 'modified-at' => 't1'],
		]);
		$this->archives->method('holdsArchive')->willReturn(true);

		$live->expects($this->never())->method('delete');
		$stale->expects($this->once())->method('delete');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['pruned']);
	}

	// ── a project deleted in Penpot (`projects/delete.feature`) ─────────────

	/**
	 * The folder-shaped half of the prune, and until now it did not exist.
	 *
	 * `collectMirrors()` gathers FILES, so deleting a project in Penpot trashed its
	 * designs and left the FOLDER standing, still carrying a `penpot_project_id`
	 * that named nothing. Nothing ever looked at it again — and a dead marker is
	 * indistinguishable from a live one to everything that reads one.
	 */
	public function testAProjectDeletedInPenpotTakesItsEmptyFolderToTheTrash(): void {
		$orphan = $this->emptyFolder(40);
		$this->folderMarkersById[40] = new FolderMarkers('proj-gone', '');
		$this->givenRootHolding([$orphan], []);

		$orphan->expects($this->once())->method('delete');

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['orphaned']);
	}

	/**
	 * The other ending. Deleting a user's spreadsheets because a Penpot project
	 * went away is not this app's call, so the folder stays and merely stops being
	 * a project — the id cleared and the `penpot` tag off with it.
	 */
	public function testAnOrphanedFolderHoldingOtherFilesKeepsThemAndLosesItsId(): void {
		$orphan = $this->createMock(Folder::class);
		$orphan->method('getId')->willReturn(40);
		$orphan->method('nodeExists')->willReturn(false);
		$orphan->method('getDirectoryListing')->willReturn([$this->emptyFile(41)]);
		$this->folderMarkersById[40] = new FolderMarkers('proj-gone', '');
		$this->givenRootHolding([$orphan], []);

		$orphan->expects($this->never())->method('delete');
		$this->metadata->expects($this->atLeastOnce())->method('writeFolder')
			->willReturnCallback(static function (int $id, array $values): void {
				if ($id === 40) {
					self::assertSame([PenpotMetadata::KEY_PROJECT_ID => ''], $values);
				}
			});
		$this->tags->expects($this->once())->method('remove')->with(40);

		self::assertSame(1, $this->pull->pullOne($this->mapping(useTeamFolder: false))['orphaned']);
	}

	/**
	 * A folder whose project Penpot STILL lists is not an orphan, however empty it
	 * is. The negative half, and the one that would turn this feature into a
	 * project-folder shredder if it broke.
	 */
	public function testAFolderWhoseProjectStillExistsIsLeftAlone(): void {
		$live = $this->emptyFolder(40);
		$this->folderMarkersById[40] = new FolderMarkers('proj-live', '');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$live]);
		$root->method('nodeExists')->willReturn(false);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-live', 'name' => 'Live', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([]);

		$live->expects($this->never())->method('delete');

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false))['orphaned']);
	}

	/**
	 * THE SEATBELT, and it is sharper than the prune's. A project SKIPPED for any
	 * reason is absent from the named set while being perfectly alive in Penpot —
	 * so without the completeness gate, one unusable project would send every
	 * other project's folder to the trash on the next pull.
	 */
	public function testAnIncompleteListingReapsNoFolders(): void {
		$orphan = $this->emptyFolder(40);
		$this->folderMarkersById[40] = new FolderMarkers('proj-gone', '');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$orphan]);
		$root->method('nodeExists')->willReturn(false);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		// A project Penpot named with no id is skipped, which is what makes the
		// listing incomplete.
		$this->client->method('getAllProjects')->willReturn([
			['id' => '', 'name' => 'Nameless', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);

		$orphan->expects($this->never())->method('delete');

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false))['orphaned']);
	}

	/**
	 * A folder that will not delete keeps its dead id for one more tick and is
	 * counted nowhere — the same shape every other per-node failure here has.
	 */
	public function testAnOrphanThatCannotBeTrashedIsCountedNowhere(): void {
		$orphan = $this->emptyFolder(40);
		$orphan->method('delete')->willThrowException(new \RuntimeException('locked'));
		$this->folderMarkersById[40] = new FolderMarkers('proj-gone', '');
		$this->givenRootHolding([$orphan], []);

		self::assertSame(0, $this->pull->pullOne($this->mapping(useTeamFolder: false))['orphaned']);
	}

	/**
	 * A root holding $nodes, mirroring a Drafts project whose files are $listing.
	 *
	 * @param list<File|Folder> $nodes what already sits in the mapped folder
	 * @param list<array<string, mixed>> $listing what Penpot says the team has
	 */
	private function givenRootHolding(array $nodes, array $listing): void {
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn($nodes);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-drafts', 'name' => 'Drafts', 'team-id' => self::TEAM_ID, 'is-default' => true],
		]);
		if ($listing !== []) {
			$this->client->method('getProjectFiles')->willReturn($listing);
		}
	}

	/** A mirror file: node id $id, stamped with Penpot file id $penpotId in `link` mode. */
	private function mirror(int $id, string $penpotId, string $revision = '', string $mode = Mapping::MODE_LINK): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$this->stamps[$id] = new PenpotFileMetadata($penpotId, $revision, $mode, self::TEAM_ID);

		return $file;
	}

	/**
	 * Why Penpot stopped naming a design, as the two probes the prune actually makes.
	 *
	 * @param bool|null $exists what `get-file-summary` says — null being "could not tell"
	 * @param bool $trashed whether the team's trash listing names it
	 */
	private function givenPenpotSays(?bool $exists, bool $trashed, string $penpotId = 'file-gone'): void {
		$this->client->method('fileExists')->willReturn($exists);
		$this->client->method('deletedFiles')->willReturn($trashed ? [['id' => $penpotId]] : []);
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

		$this->stamps[30] = new PenpotFileMetadata('file-1', $stored, $stampedMode !== '' ? $stampedMode : $mappingMode);
		$this->archives->method('holdsArchive')->willReturn($holdsArchive);
	}
	/**
	 * A MIRROR THE USER FILED INTO A PLAIN SUBFOLDER IS STILL THE SAME MIRROR.
	 *
	 * The upsert index used to read only a project folder's direct children while
	 * the prune walked the whole tree (§C6.20). So a user files a design into
	 * `wip/` — which move-design.feature explicitly allows, because Penpot has no
	 * concept of subfolders — and the next pull cannot see it, creates a SECOND
	 * mirror at the canonical path, and the prune then leaves both alone because
	 * Penpot does still list that id. Two files, one design, forever, no
	 * complaint anywhere.
	 *
	 * Pinned on the OBSERVABLE consequence: `newFile()` must never be called,
	 * because the file already exists.
	 */
	public function testAMirrorFiledIntoASubfolderIsFoundRatherThanDuplicated(): void {
		$mapping = $this->mapping(useTeamFolder: false);

		$mirror = $this->emptyFile(40);
		$this->stamps[40] = new PenpotFileMetadata('file-acme', '5@t1', Mapping::MODE_LINK, self::TEAM_ID);

		$wip = $this->createMock(Folder::class);
		$wip->method('getId')->willReturn(21);
		$wip->method('getDirectoryListing')->willReturn([$mirror]);
		$wip->method('nodeExists')->willReturn(false);

		$acmeFolder = $this->createMock(Folder::class);
		$acmeFolder->method('getId')->willReturn(20);
		$acmeFolder->method('getDirectoryListing')->willReturn([$wip]);
		$acmeFolder->method('nodeExists')->willReturn(false);
		// THE ASSERTION. A second mirror would be born here.
		$acmeFolder->expects($this->never())->method('newFile');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$acmeFolder]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-acme', 'name' => 'Acme', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([
			['id' => 'file-acme', 'name' => 'Login', 'revn' => 5],
		]);
		// The Acme folder is found by its project marker, so no folder is made either.
		$this->folderMarkersById[20] = new FolderMarkers('proj-acme', '');

		$this->pull->pullOne($mapping);
	}

	/**
	 * The descent stops at a NEARER project ancestor — those mirrors belong to
	 * that project, not this one. Same rule as MembershipResolver read downwards,
	 * and the same one ProjectFolderService uses to collect designs on opt-in.
	 * Without it, one project's pull would adopt another project's files.
	 *
	 * ## THE NESTED FILE NOW CARRIES A DIFFERENT ID, AND HAD TO
	 *
	 * It used to carry `file-acme` — the very id this project's listing reports —
	 * which conflated two different claims: "a file in another project's folder" and
	 * "another project's file". They were indistinguishable while a miss simply
	 * created a new mirror.
	 *
	 * They are not indistinguishable any more. Penpot decides which project a design
	 * is in, so a mirror stamped `file-acme` sitting under another project's folder
	 * is not a bystander — it is THIS design's mirror, in the wrong place, and the
	 * pull now moves it rather than writing a duplicate beside it
	 * ({@see testAMirrorFollowsItsDesignIntoAnotherProject()}).
	 *
	 * So the fixture says what it always meant: another project's file is one with
	 * another design's id. The index rule under test is unchanged — the descent
	 * still stops at folder 22 — and this now proves it without also asserting the
	 * duplication bug.
	 */
	public function testTheIndexStopsAtANestedProjectFolder(): void {
		$mapping = $this->mapping(useTeamFolder: false);

		$theirs = $this->emptyFile(50);
		$this->stamps[50] = new PenpotFileMetadata('file-theirs', '5@t1', Mapping::MODE_LINK, self::TEAM_ID);

		$nested = $this->createMock(Folder::class);
		$nested->method('getId')->willReturn(22);
		$nested->method('getDirectoryListing')->willReturn([$theirs]);
		$nested->method('nodeExists')->willReturn(false);

		$made = $this->emptyFile(60);
		$acmeFolder = $this->createMock(Folder::class);
		$acmeFolder->method('getId')->willReturn(20);
		$acmeFolder->method('getDirectoryListing')->willReturn([$nested]);
		$acmeFolder->method('nodeExists')->willReturn(false);
		// The nested folder is another project's, so `file-acme` is NOT found
		// here and a mirror is correctly created in this one.
		$acmeFolder->expects($this->once())->method('newFile')->willReturn($made);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$acmeFolder]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-acme', 'name' => 'Acme', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([
			['id' => 'file-acme', 'name' => 'Login', 'revn' => 5],
		]);
		$this->folderMarkersById[20] = new FolderMarkers('proj-acme', '');
		$this->folderMarkersById[22] = new FolderMarkers('proj-other', '');

		$this->pull->pullOne($mapping);
	}

	/**
	 * A DESIGN THAT CHANGED PROJECT IN PENPOT TAKES ITS MIRROR WITH IT.
	 *
	 * The design is still in the team's listing, so the prune correctly leaves the
	 * mirror alone — and the upsert indexes only the destination folder, so it did
	 * not find it and wrote a SECOND file. Two files, one design, and nothing ever
	 * reconciled them: the stale one stays in the seen-set forever.
	 *
	 * Asserting `newFile` is NEVER called is the whole point. "A file ends up in the
	 * right folder" was true of the buggy behaviour too.
	 */
	public function testAMirrorFollowsItsDesignIntoAnotherProject(): void {
		$mapping = $this->mapping(useTeamFolder: false);

		// The mirror, sitting under the project the design used to be in.
		$mirror = $this->emptyFile(50);
		$mirror->method('getName')->willReturn('Login.penpot');
		$this->stamps[50] = new PenpotFileMetadata('file-acme', '5@t1', Mapping::MODE_LINK, self::TEAM_ID);

		$oldProject = $this->createMock(Folder::class);
		$oldProject->method('getId')->willReturn(22);
		$oldProject->method('getDirectoryListing')->willReturn([$mirror]);
		$oldProject->method('nodeExists')->willReturn(false);

		$newProject = $this->createMock(Folder::class);
		$newProject->method('getId')->willReturn(20);
		$newProject->method('getDirectoryListing')->willReturn([]);
		$newProject->method('nodeExists')->willReturn(false);
		$newProject->method('getPath')->willReturn('/admin/files/Penpot/Acme');
		$newProject->expects($this->never())->method('newFile');

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(10);
		$root->method('getDirectoryListing')->willReturn([$newProject, $oldProject]);
		$root->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturn($root);
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'proj-acme', 'name' => 'Acme', 'team-id' => self::TEAM_ID, 'is-default' => false],
		]);
		$this->client->method('getProjectFiles')->willReturn([
			['id' => 'file-acme', 'name' => 'Login', 'revn' => 5],
		]);
		$this->folderMarkersById[20] = new FolderMarkers('proj-acme', '');
		$this->folderMarkersById[22] = new FolderMarkers('proj-was', '');

		// MOVED, not copied: the same node lands in the new project's folder.
		$mirror->expects($this->once())->method('move')->with('/admin/files/Penpot/Acme/Login.penpot');

		$this->pull->pullOne($mapping);
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
