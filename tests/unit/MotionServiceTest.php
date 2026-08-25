<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\DestinationResolver;
use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\ImportService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\MotionService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\ProjectFolderService;
use OCA\PenpotSync\Service\ProjectTags;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The move writeback (saga Ch2 Course 4, `move-design.feature`). These pin the one
 * decision that matters — *did the file's Penpot project actually change?* —
 * because everything destructive about a move in the siblings is absent here:
 * this service can only ever call `move-files`, and never deletes, creates or
 * touches content (§6.1).
 *
 * What is pinned:
 *
 *   - a move that changes no project never contacts Penpot (the common case);
 *   - a move into a different project re-files exactly that one file;
 *   - a move into a team root is Penpot's Drafts (§6.35), resolved to that team's
 *     default project rather than treated as "no project";
 *   - a move out of every mapped folder pushes NOTHING — unmapping is Course 5's
 *     explicit decision, never inferred from a drag;
 *   - unmanaged `.penpot` files are not ours to move.
 *
 * ## AND SINCE §C6.38, THE FOLDER HALF
 *
 * A project folder used to be waved through with one `return false`. It now takes
 * three branches, and the one worth staring at is the third:
 *
 *   - crossed into another mapped team → one `move-project`, id intact;
 *   - dragged within its own team → nothing, because the NAME is what changed and
 *     PushService pushes that from the same event;
 *   - left every mapping → Penpot is not contacted at all, and the marker comes
 *     off instead, nested projects included.
 *
 * The comparison is team-to-team rather than "is there a team now", which
 * {@see testAProjectFolderTidiedInsideUnmappedSpaceIsLeftAlone()} exists to hold
 * in place: the cheap version of that check unmaps a personal project (§6.31) the
 * first time its owner tidies their home.
 */
final class MotionServiceTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private ProjectFolderService $projects;
	private const PROJECT_A = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const PROJECT_B = '7c11a0d4-1f52-4a7e-9b3c-2f9d0e4a1b66';
	private const DRAFTS = '0f9b6c2a-5d31-4e88-a1f0-9c7b3d2e5a44';
	private const TEAM = 'df59d46b-a997-80d9-8008-6452575a4b87';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private DestinationResolver $destinations;
	private PersonalTokenService $personalTokens;
	private ProjectTags $tags;
	private ArchiveService $archives;
	private MappingService $mappings;
	private MotionService $motion;

	protected function setUp(): void {
		parent::setUp();
		$this->projects = $this->createMock(ProjectFolderService::class);
		$this->archives = $this->createMock(ArchiveService::class);
		$this->mappings = $this->createMock(MappingService::class);
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->personalTokens = $this->createMock(PersonalTokenService::class);
		$this->tags = $this->createMock(ProjectTags::class);
		// The REAL destination resolver over the mocked client, deliberately: the
		// Drafts lookup is the behaviour a team-root move depends on, and mocking
		// it away is what let the copy path ship with the opposite rule (§C6.10).
		$this->destinations = new DestinationResolver($this->client, $this->projects, new NullLogger());
		$this->motion = new MotionService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->destinations,
			$this->personalTokens,
			$this->tags,
			new SyncGuard(),
			$this->createMock(ImportService::class),
			$this->archives,
			$this->mappings,
			new NullLogger(),
		);
	}

	public function testAMoveWithinTheSameProjectNeverContactsPenpot(): void {
		// A plain subfolder of the project, or a second folder mapping to it —
		// either way the nearest project id is unchanged, so there is nothing
		// for Penpot to know about.
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => new Membership(self::PROJECT_A, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target()));
	}

	public function testAMoveIntoAnotherProjectRefilesTheFile(): void {
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => new Membership(self::PROJECT_B, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->personalTokens->method('tokenForActor')->willReturn('dana-token');

		$this->client->expects($this->once())->method('moveFiles')
			->with(self::PROJECT_B, [self::PENPOT_ID], 'dana-token');

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	public function testAMoveOutToTheTeamRootLandsInThatTeamsDrafts(): void {
		// Drafts is a STATE, not a folder (§6.35): a team with no project above
		// the file IS Penpot's Drafts, which is a real (default) project.
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => new Membership(null, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::PROJECT_A, 'team-id' => self::TEAM, 'is-default' => false],
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
		]);

		$this->client->expects($this->once())->method('moveFiles')
			->with(self::DRAFTS, [self::PENPOT_ID], null);

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	/**
	 * The file left every mapping, so its design is PARKED in Penpot's trash —
	 * keeping its id, revision and history against the day it comes back.
	 *
	 * This used to assert the opposite ("Penpot keeps it exactly where it is:
	 * unmapping and deleting are Course 5's to decide explicitly, and a drag is not
	 * evidence of either"). What that produced was a design in a project whose
	 * folder maps nowhere, mirrored by nothing and indistinguishable from live
	 * work — the absence of a decision, which was the worst of the three options.
	 */
	public function testAMoveOutOfEveryMappedFolderParksTheDesign(): void {
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->archives->method('holdsArchive')->willReturn(true);

		// Trashed in Penpot, never re-filed: there is no project to re-file into.
		$this->client->expects($this->once())->method('deleteFile')->with(self::PENPOT_ID, null);
		$this->client->expects($this->never())->method('moveFiles');
		// The id STAYS. It is the claim the return redeems; clearing it would make
		// every comeback an import that mints a new design and drops the history.
		$this->metadata->expects($this->once())->method('writeFile')->with(
			$this->anything(),
			[
				PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
				PenpotMetadata::KEY_TEAM_ID => '',
			],
		);

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	/** A `sync` mirror already holds its archive, so parking costs no export. */
	public function testParkingAFileWithoutAnArchiveTakesOneLastSnapshot(): void {
		// The bytes have to be secured BEFORE the trashing: Penpot keeps a deleted
		// design exportable only while its own trash holds it.
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->archives->method('holdsArchive')->willReturn(false);

		$this->archives->expects($this->once())->method('storeArchive');

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	/**
	 * A TEAM WITH NO RESOLVABLE PROJECT IS NOT A DEPARTURE, and telling the two
	 * apart is the whole reason this test survived the parking change.
	 *
	 * Both states reach the same `$to === null`, but this file never left its
	 * mapping — we simply could not work out which project it landed in. Parking it
	 * would soft-delete somebody's design because a LOOKUP failed, which is
	 * destructive and caused by our own blind spot rather than by the user. Better
	 * an un-pushed move; the next pull reconciles it.
	 */
	public function testATeamWithNoVisibleDraftsProjectPushesNothingAndParksNothing(): void {
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => new Membership(null, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::PROJECT_A, 'team-id' => self::TEAM, 'is-default' => false],
		]);
		$this->client->expects($this->never())->method('moveFiles');
		$this->client->expects($this->never())->method('deleteFile');

		self::assertFalse($this->motion->onMove($this->source(), $this->target()));
	}

	public function testAnUnmanagedPenpotFileIsNotOursToMove(): void {
		// SINCE §6.33 AN UNTRACKED `.penpot` IS NOT AUTOMATICALLY NOBODY'S: if it
		// holds an archive and landed inside a mapping it is imported. So this
		// path now RESOLVES the destination, and the resolver has to answer with
		// a real Membership — a stub's readonly properties are uninitialised, and
		// reading one is a fatal rather than a null.
		$this->resolver->method('resolve')->willReturn(Membership::none());
		// Creating it in Penpot on the way in is the §6.33 carve-out, a later
		// course — never a side effect of a drag.
		$this->metadata->method('readFile')->willReturn(null);
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target('hand-made.penpot')));
	}

	/**
	 * A `link` NEVER reaches the push, and this is checked here as well as in the
	 * guard on purpose.
	 *
	 * The guard (§6.43) refuses every project-changing move of a link before it
	 * happens, so in practice only a within-project link move can arrive — which
	 * needs no `move-files` anyway. But relying on that means the guard is the
	 * only thing standing between a pointer and a `move-files` call: relax it and
	 * this service would silently start re-filing files that hold no bytes.
	 *
	 * So the rule is stated in both places, and this test is what makes removing
	 * it a deliberate act rather than a quiet consequence.
	 */
	public function testALinkIsNeverPushed(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_LINK));
		$this->givenMembership([
			'target' => new Membership(self::PROJECT_B, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target()));
	}

	public function testAPlainFileIsIgnored(): void {
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target('notes.txt')));
	}

	public function testAPlainFolderIsIgnored(): void {
		// Folder layout is Nextcloud's (§6.29). A plain folder carries no project
		// id, so there is nothing here to re-team — and the projects nested below
		// it renamed rather than moved, which is PushService's half of the event.
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', ''));
		$this->client->expects($this->never())->method('moveProject');

		self::assertFalse($this->motion->onMove($this->sourceFolder(), $this->folder()));
	}

	// ── §C6.38: a project folder moves too ──────────────────────────────────

	public function testAProjectFolderDraggedIntoAnotherTeamMovesTheProject(): void {
		$this->givenProjectFolder();
		$this->givenMembership([
			'target' => new Membership(self::PROJECT_A, 'team-NEW'),
			'oldParent' => new Membership(null, self::TEAM),
		]);
		$this->personalTokens->method('tokenForActor')->willReturn('dana-token');

		$this->client->expects($this->once())->method('moveProject')
			->with(self::PROJECT_A, 'team-NEW', 'dana-token');

		self::assertTrue($this->motion->onMove($this->sourceFolder(), $this->folder()));
	}

	public function testAProjectFolderDraggedWithinItsTeamContactsNobody(): void {
		// The position means nothing to Penpot; the NAME changed and PushService
		// has already pushed that from the same event. Zero requests here.
		$this->givenProjectFolder();
		$this->givenMembership([
			'target' => new Membership(self::PROJECT_A, self::TEAM),
			'oldParent' => new Membership(null, self::TEAM),
		]);
		$this->client->expects($this->never())->method('moveProject');

		self::assertFalse($this->motion->onMove($this->sourceFolder(), $this->folder()));
	}

	public function testAProjectFolderDraggedOutOfEveryMappingIsUnmapped(): void {
		// NOTHING IS DELETED IN PENPOT. The project stands; the folder stops being
		// the thing that mirrors it, which is the marker coming off.
		$this->givenProjectFolder();
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(null, self::TEAM),
		]);

		$this->client->expects($this->never())->method('moveProject');
		$this->metadata->expects($this->once())->method('clear')->with(40);
		$this->tags->expects($this->once())->method('remove')->with(40);

		self::assertTrue($this->motion->onMove($this->sourceFolder(), $this->folder()));
	}

	/**
	 * A PROJECT UNDER A PLAIN FOLDER IS STILL REACHED, which the first cut of
	 * `unmap()` got wrong: it descended only into folders that already carried a
	 * marker, so `Let Go/notes/Deep` kept its `penpot_project_id` out in unmapped
	 * space — the exact id the method exists to remove, in the exact tree shape
	 * §6.29 exists to allow.
	 */
	public function testUnmappingDescendsThroughAPlainFolderToReachANestedProject(): void {
		$deep = $this->markedFolder(42, 'Deep', hasProject: true);
		$notes = $this->markedFolder(43, 'notes', hasProject: false, children: [$deep]);

		$this->givenFolderMarkers([40 => true, 43 => false, 42 => true]);
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(null, self::TEAM),
		]);

		$cleared = [];
		$this->metadata->method('clear')->willReturnCallback(
			static function (int $id) use (&$cleared): void {
				$cleared[] = $id;
			},
		);

		self::assertTrue($this->motion->onMove($this->sourceFolder(), $this->folder([$notes])));
		// 43 is the plain folder: walked THROUGH, never cleared.
		self::assertSame([40, 42], $cleared);
	}

	public function testUnmappingReachesANestedProjectToo(): void {
		// THE RESOLVER WALKS UP, so a `penpot_project_id` left on a nested folder in
		// unmapped space is not inert — it would still answer for anything dropped
		// beside it, reporting a project with no team above it.
		$nested = $this->createMock(Folder::class);
		$nested->method('getId')->willReturn(41);
		$nested->method('getPath')->willReturn('/dana/files/Scratch/Let Go/Deeper');
		$nested->method('getDirectoryListing')->willReturn([]);

		$this->metadata->method('readFolder')->willReturn(new FolderMarkers(self::PROJECT_A, ''));
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(null, self::TEAM),
		]);

		$cleared = [];
		$this->metadata->method('clear')->willReturnCallback(
			static function (int $id) use (&$cleared): void {
				$cleared[] = $id;
			},
		);

		self::assertTrue($this->motion->onMove($this->sourceFolder(), $this->folder([$nested])));
		self::assertSame([40, 41], $cleared);
	}

	public function testAProjectFolderTidiedInsideUnmappedSpaceIsLeftAlone(): void {
		// THE COMPARISON IS TEAM TO TEAM, not "is there a team now". Reading the
		// destination alone would unmap this a second time — and would unmap a
		// personal project (§6.31) the first time its owner tidied their home.
		$this->givenProjectFolder();
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => Membership::none(),
		]);

		$this->metadata->expects($this->never())->method('clear');
		$this->client->expects($this->never())->method('moveProject');

		self::assertFalse($this->motion->onMove($this->sourceFolder(), $this->folder()));
	}

	/**
	 * A DRAG BETWEEN TWO MAPPED TEAM FOLDERS RE-STAMPS THE FILE'S TEAM (§C6.7).
	 *
	 * `penpot_team_id` is cached on the file because the browser cannot walk a
	 * freely-nested tree to build a deep link. With two teams mapped to two
	 * folders, dragging a mirror from one tree to the other genuinely changes
	 * which Penpot team owns the design — and a stamp left naming the old team is
	 * a link that opens the WRONG team's workspace. The resolver is the authority
	 * for where the node now sits, so it writes the correction.
	 */
	public function testAMoveBetweenTeamsRestampsTheTeamId(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_SYNC, 'team-OLD'));
		$this->givenMembership([
			'target' => new Membership('proj-new', 'team-NEW'),
			'oldParent' => new Membership('proj-old', 'team-OLD'),
		]);

		$this->metadata->expects($this->once())
			->method('writeFile')
			->with(30, [PenpotMetadata::KEY_TEAM_ID => 'team-NEW']);

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	/**
	 * A move WITHIN one team touches no metadata at all. The stamp is already
	 * right, and rewriting it would churn the file's metadata on every ordinary
	 * drag between two project folders — the common case by far.
	 */
	public function testAMoveWithinOneTeamDoesNotRestamp(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_SYNC, 'team-SAME'));
		$this->givenMembership([
			'target' => new Membership('proj-new', 'team-SAME'),
			'oldParent' => new Membership('proj-old', 'team-SAME'),
		]);

		$this->metadata->expects($this->never())->method('writeFile');

		self::assertTrue($this->motion->onMove($this->source(), $this->target()));
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	/**
	 * A managed file in `sync` mode — the only kind that reaches the push.
	 *
	 * A `link` is refused a project change by MoveGuardListener before the event
	 * this service listens to is ever emitted, and {@see testALinkIsNeverPushed()}
	 * pins the belt-and-braces return that says so here too.
	 */
	private function givenManagedFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', Mapping::MODE_SYNC));
	}

	/**
	 * Program the resolver: the moved NODE resolves to `target`, and the source's
	 * old parent folder to `oldParent`. They are told apart by node id — 30 is the
	 * moved file, 40 the moved folder, and 11 the parent it came from.
	 *
	 * @param array{target: Membership, oldParent: Membership} $by
	 */
	private function givenMembership(array $by): void {
		$this->resolver->method('resolve')->willReturnCallback(
			static fn (Node $node): Membership => in_array($node->getId(), [30, 40], true)
				? $by['target'] : $by['oldParent'],
		);
	}

	/**
	 * The pre-move FOLDER. Only its parent is ever read — the moved node itself is
	 * `folder()` — but it is a Folder mock rather than {@see source()}'s File so
	 * the fixture describes a gesture that can actually happen.
	 */
	private function sourceFolder(): Folder {
		$oldParent = $this->createMock(Folder::class);
		$oldParent->method('getId')->willReturn(11);

		$source = $this->createMock(Folder::class);
		$source->method('getId')->willReturn(40);
		$source->method('getParent')->willReturn($oldParent);

		return $source;
	}

	/**
	 * A child folder for the unmap walk.
	 *
	 * @param list<Folder> $children
	 * @return Folder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function markedFolder(int $id, string $name, bool $hasProject, array $children = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn($name);
		$folder->method('getPath')->willReturn('/dana/files/Scratch/' . $name);
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}

	/**
	 * Which node ids carry a project marker, for a walk that reads several.
	 *
	 * @param array<int, bool> $byId
	 */
	private function givenFolderMarkers(array $byId): void {
		$this->metadata->method('readFolder')->willReturnCallback(
			static fn (int $id): FolderMarkers => new FolderMarkers(
				($byId[$id] ?? false) ? self::PROJECT_A : '',
				'',
			),
		);
	}

	private function givenProjectFolder(): void {
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers(self::PROJECT_A, ''));
	}

	/**
	 * The moved FOLDER. Shares node id 40 with nothing else, so
	 * {@see givenMembership()} tells it apart from the source's old parent.
	 *
	 * @param list<Folder> $children
	 */
	private function folder(array $children = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(40);
		$folder->method('getName')->willReturn('Let Go');
		$folder->method('getPath')->willReturn('/dana/files/Scratch/Let Go');
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}

	/** The pre-move node. Its parent is the folder the file came from. */
	private function source(): File {
		$oldParent = $this->createMock(Folder::class);
		$oldParent->method('getId')->willReturn(11);

		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(30);
		$source->method('getParent')->willReturn($oldParent);

		return $source;
	}

	private function target(string $name = 'Login screen.penpot'): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(30);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dana/files/Design/' . $name);

		return $file;
	}
}
