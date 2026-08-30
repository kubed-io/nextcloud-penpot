<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\CopyService;
use OCA\PenpotSync\Service\DestinationResolver;
use OCA\PenpotSync\Service\ImportService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\ProjectFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Copy → a real new design in Penpot (`copy-design.feature`, saga §C6.8).
 *
 * The decision this service exists to make is *where the copy landed*, because
 * `duplicate-file` has no project parameter: the new design always appears in
 * the SOURCE's project, so a copy that belongs elsewhere needs a second call.
 * Every test below is one row of that decision.
 *
 * The comparison is deliberately made against the duplicate's OWN `projectId`
 * rather than a resolved source project — the server's answer about where it put
 * the thing beats our inference about where it should have gone — so the fixture
 * controls that field explicitly.
 */
final class CopyServiceTest extends TestCase {
	private const SOURCE_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private ProjectFolderService $projects;
	private const NEW_ID = '86f123cb-0682-808c-8008-6885698e25e5';
	private const PROJECT_A = '61d8ecb9-c430-8120-8008-622627f23540';
	private const DRAFTS = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private CopyService $copies;

	protected function setUp(): void {
		parent::setUp();
		$this->projects = $this->createMock(ProjectFolderService::class);
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);

		$tokens = $this->createStub(PersonalTokenService::class);
		$tokens->method('tokenForActor')->willReturn(null);

		$this->copies = new CopyService(
			$this->client,
			$this->metadata,
			$this->resolver,
			// The REAL resolver over the mocked client. Stubbing this out is
			// exactly how the team-root bug shipped green (§C6.10).
			new DestinationResolver($this->client, $this->projects, new NullLogger()),
			$tokens,
			new NullLogger(),
			$this->createMock(ImportService::class),
		);
	}

	// ── the two gestures ────────────────────────────────────────────────────

	/**
	 * A copy made in place: the duplicate already lands where it belongs, so the
	 * second call must NOT happen. This is the common case and the cheap one.
	 */
	public function testCopyingInPlaceDuplicatesAndDoesNotMove(): void {
		$this->givenSource(Mapping::MODE_SYNC);
		$this->givenDestination(self::PROJECT_A);
		$this->givenDuplicateLandsIn(self::PROJECT_A);

		$this->client->expects($this->never())->method('moveFiles');
		$this->metadata->expects($this->once())->method('writeFile')->with(
			30,
			[
				PenpotMetadata::KEY_ID => self::NEW_ID,
				PenpotMetadata::KEY_MODE => Mapping::MODE_SYNC,
				PenpotMetadata::KEY_TEAM_ID => self::TEAM,
			],
		);

		$this->copies->onCopy($this->source(), $this->target());
	}

	/**
	 * THE ONE THE USER HIT, and the one the first fixture got wrong.
	 *
	 * A copy landing at the TEAM ROOT resolves to `projectId = null` with a real
	 * team — because there is no project FOLDER above it. That is not "outside
	 * every mapping": those files are the team's Drafts, a real project (§6.35).
	 *
	 * The original version of this test handed the service `Membership(DRAFTS,
	 * TEAM)` — a shape the resolver never produces for a team root — so it passed
	 * against code that skipped Penpot entirely. The fixture below is the one
	 * that reproduces reality: null project, team set, Drafts found by lookup.
	 */
	public function testCopyingUpToTheTeamRootDuplicatesThenMovesIntoDrafts(): void {
		$this->givenSource(Mapping::MODE_LINK);
		$this->givenTeamRootDestination();
		$this->givenDuplicateLandsIn(self::PROJECT_A);

		$this->client->expects($this->once())
			->method('moveFiles')
			->with(self::DRAFTS, [self::NEW_ID], null);

		$this->copies->onCopy($this->source(), $this->target());
	}

	/**
	 * And it must still create the design even when Drafts happens to BE the
	 * source's project — the duplicate is already in the right place, so only the
	 * move is skipped, never the duplicate.
	 */
	public function testATeamRootCopyOfADraftsFileStillDuplicates(): void {
		$this->givenSource(Mapping::MODE_LINK);
		$this->givenTeamRootDestination();
		$this->givenDuplicateLandsIn(self::DRAFTS);

		$this->client->expects($this->once())->method('duplicateFile');
		$this->client->expects($this->never())->method('moveFiles');

		$this->copies->onCopy($this->source(), $this->target());
	}

	/**
	 * A `link` holds zero bytes (§C6.6) and still copies completely, because
	 * Penpot duplicates the design server-side from its id. Neither sibling can
	 * do this — they copy by pushing the file's own content. So: no export, ever.
	 */
	public function testALinkCopiesWithoutAnyExport(): void {
		$this->givenSource(Mapping::MODE_LINK);
		$this->givenDestination(self::PROJECT_A);
		$this->givenDuplicateLandsIn(self::PROJECT_A);

		$this->client->expects($this->never())->method('exportBinfile');
		$this->client->expects($this->once())->method('duplicateFile');

		$this->copies->onCopy($this->source(), $this->target());
	}

	// ── the name ────────────────────────────────────────────────────────────

	/** The Penpot name is the COPY's filename, extension stripped (§6.4). */
	public function testTheNewDesignIsNamedAfterTheCopyWithoutTheExtension(): void {
		$this->givenSource(Mapping::MODE_LINK);
		$this->givenDestination(self::PROJECT_A);
		$this->client->expects($this->once())
			->method('duplicateFile')
			->with(self::SOURCE_ID, 'Login screen (copy)', null)
			->willReturn(['id' => self::NEW_ID, 'projectId' => self::PROJECT_A]);

		$this->copies->onCopy($this->source(), $this->target('Login screen (copy).penpot'));
	}

	/**
	 * Penpot's schema caps a name at 250 characters. Truncating loses a tail;
	 * refusing would turn "your copy has a long name" into "your copy is not a
	 * design", which is the worse of the two.
	 */
	public function testAnOverLongNameIsTruncatedRatherThanRefused(): void {
		$this->givenSource(Mapping::MODE_LINK);
		$this->givenDestination(self::PROJECT_A);
		$this->client->expects($this->once())
			->method('duplicateFile')
			->with(self::SOURCE_ID, str_repeat('x', 250), null)
			->willReturn(['id' => self::NEW_ID, 'projectId' => self::PROJECT_A]);

		$this->copies->onCopy($this->source(), $this->target(str_repeat('x', 300) . '.penpot'));
	}

	// ── where nothing is created ────────────────────────────────────────────

	/**
	 * Outside every mapping there is no project to create in, and inventing one
	 * would be the surprise write §6.1 refuses. The source id is kept as an inert
	 * historical record of which design these bytes came from — which is what
	 * makes a later restore possible.
	 */
	public function testCopyingOutsideEveryMappingCreatesNothingAndKeepsTheIdAsARecord(): void {
		$this->givenSource(Mapping::MODE_SYNC);
		// NO project AND no team — the genuine "outside everything" shape, which
		// is what distinguishes it from a team root (null project, team set).
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));

		$this->client->expects($this->never())->method('duplicateFile');
		$this->metadata->expects($this->once())->method('writeFile')->with(
			30,
			[
				PenpotMetadata::KEY_ID => self::SOURCE_ID,
				PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
			],
		);

		$this->copies->onCopy($this->source(), $this->target());
	}

	/** Copying a `.penpot` we never tracked is copying a file. Nothing happens. */
	public function testCopyingAnUntrackedFileNeverContactsPenpot(): void {
		// SINCE §6.33 AN UNTRACKED `.penpot` IS NOT AUTOMATICALLY NOBODY'S: if it
		// holds an archive and landed inside a mapping it is imported. So this
		// path now RESOLVES the destination, and the resolver has to answer with
		// a real Membership — a stub's readonly properties are uninitialised, and
		// reading one is a fatal rather than a null.
		$this->resolver->method('resolve')->willReturn(Membership::none());
		$this->metadata->method('readFile')->willReturn(null);

		$this->client->expects($this->never())->method('duplicateFile');
		$this->metadata->expects($this->once())->method('clear')->with(30);

		$this->copies->onCopy($this->source(), $this->target());
	}

	// ── failure ─────────────────────────────────────────────────────────────

	/**
	 * §6.18 rule 3: a remote failure never rewrites local state. The Nextcloud
	 * copy stands with its bytes — but it must NOT carry the source's id, because
	 * two files claiming one design is precisely the ambiguity that made the old
	 * inert-copy rule necessary in the first place.
	 */
	public function testAFailedDuplicateLeavesTheCopyUntrackedNotClaimingTheOriginal(): void {
		$this->givenSource(Mapping::MODE_SYNC);
		$this->givenDestination(self::PROJECT_A);
		$this->client->method('duplicateFile')->willThrowException(new \RuntimeException('boom'));

		$this->metadata->expects($this->never())->method('writeFile');
		$this->metadata->expects($this->once())->method('clear')->with(30);

		$this->copies->onCopy($this->source(), $this->target());
	}

	/** A 200 that carries no id is a failure too, and is treated as one. */
	public function testADuplicateWithNoIdIsTreatedAsAFailure(): void {
		$this->givenSource(Mapping::MODE_SYNC);
		$this->givenDestination(self::PROJECT_A);
		$this->client->method('duplicateFile')->willReturn(['projectId' => self::PROJECT_A]);

		$this->metadata->expects($this->never())->method('writeFile');
		$this->metadata->expects($this->once())->method('clear')->with(30);

		$this->copies->onCopy($this->source(), $this->target());
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	// ── a whole project folder ──────────────────────────────────────────────

	/**
	 * THE WALK IS THE ONLY NEW THING, so it is what these lock.
	 *
	 * Core fires `NodeCopiedEvent` once for a folder and never per child —
	 * measured on a live instance — so `onFolderCopy()` walks the SOURCE and
	 * pairs each design with the copy at the same relative path. Everything after
	 * the pairing is `onCopy()`, covered above.
	 */
	public function testEveryDesignBelowACopiedFolderIsPaired(): void {
		$sourceAlpha = $this->namedFile('Alpha.penpot', 91);
		$sourceBeta = $this->namedFile('Beta.penpot', 92);
		$copyAlpha = $this->namedFile('Alpha.penpot', 93);
		$copyBeta = $this->namedFile('Beta.penpot', 94);

		// Two designs in, two duplicate calls out — one per pair.
		$this->metadata->method('readFile')->willReturnCallback(
			fn (int $id): ?PenpotFileMetadata => in_array($id, [91, 92], true)
				? new PenpotFileMetadata('design-' . $id, '5@x', Mapping::MODE_SYNC, self::TEAM)
				: null,
		);
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT_A, self::TEAM));
		$this->client->expects($this->exactly(2))->method('duplicateFile')
			->willReturn(['id' => 'new-id', 'project-id' => self::PROJECT_A]);

		$this->copies->onFolderCopy(
			$this->treeFolder([$sourceAlpha, $sourceBeta]),
			$this->treeFolder([$copyAlpha, $copyBeta]),
		);
	}

	/**
	 * A design whose counterpart is missing is DROPPED, not guessed at. Half a
	 * copy handled wrongly is worse than half a copy left untracked.
	 */
	public function testADesignWithNoCounterpartInTheCopyIsSkipped(): void {
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata('design-91', '5@x', Mapping::MODE_SYNC, self::TEAM),
		);
		$this->client->expects($this->never())->method('duplicateFile');

		$this->copies->onFolderCopy(
			$this->treeFolder([$this->namedFile('Alpha.penpot', 91)]),
			$this->treeFolder([]),
		);
	}

	/** Only `.penpot` is a design; the spreadsheet beside it is nobody's business. */
	public function testAnOrdinaryFileBelowACopiedFolderIsIgnored(): void {
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata('design-91', '5@x', Mapping::MODE_SYNC, self::TEAM),
		);
		$this->client->expects($this->never())->method('duplicateFile');

		$this->copies->onFolderCopy(
			$this->treeFolder([$this->namedFile('Budget.xlsx', 91)]),
			$this->treeFolder([$this->namedFile('Budget.xlsx', 93)]),
		);
	}

	/**
	 * ONE BAD DESIGN IS ONE UNTRACKED FILE. The Nextcloud copy has already
	 * happened, so a failure on the first pair must not leave the rest of
	 * somebody's designs untouched.
	 */
	public function testAFailingDesignDoesNotAbandonTheRestOfTheCopy(): void {
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata('design-91', '5@x', Mapping::MODE_SYNC, self::TEAM),
		);
		$this->resolver->method('resolve')->willThrowException(new \RuntimeException('boom'));

		// Reaching the second pair at all is the assertion: without per-pair
		// containment the first exception ends the whole walk.
		$this->resolver->expects($this->exactly(2))->method('resolve');

		$this->copies->onFolderCopy(
			$this->treeFolder([$this->namedFile('Alpha.penpot', 91), $this->namedFile('Beta.penpot', 92)]),
			$this->treeFolder([$this->namedFile('Alpha.penpot', 93), $this->namedFile('Beta.penpot', 94)]),
		);
	}

	/** A file copied as a "folder" is not a tree; the walk simply has nothing to do. */
	public function testAFileSourceIsNotWalked(): void {
		$this->client->expects($this->never())->method('duplicateFile');

		$this->copies->onFolderCopy($this->source(), $this->treeFolder([]));
	}

	private function namedFile(string $name, int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/dana/files/Penpot/My Stuff/' . $name);
		// THE PARENT IS NOT OPTIONAL. `DestinationResolver` is the real one here
		// (§C6.10), and it walks UP from the file to decide where the design
		// belongs — an unstubbed `getParent()` returns null, which it reads as
		// "past the storage root" and answers Drafts, so nothing is duplicated
		// and the test fails on a fixture rather than on the walk it is about.
		$file->method('getParent')->willReturn($this->parentFolder());

		return $file;
	}

	/**
	 * A folder that lists $children and can `get()` any of them by name — which is
	 * the whole surface {@see CopyService::designPairs()} touches.
	 *
	 * @param list<File> $children
	 */
	private function treeFolder(array $children): Folder {
		$byName = [];
		foreach ($children as $child) {
			$byName[$child->getName()] = $child;
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => isset($byName[$name]),
		);
		$folder->method('get')->willReturnCallback(
			fn (string $name): File => $byName[$name] ?? throw new \RuntimeException("no {$name}"),
		);

		return $folder;
	}

	private function givenSource(string $mode): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::SOURCE_ID, '5@t1', $mode, self::TEAM));
	}

	private function givenDestination(string $projectId): void {
		$this->resolver->method('resolve')->willReturn(new Membership($projectId, self::TEAM));
	}

	/**
	 * The team root: NO project ancestor, but a real team. `get-all-projects`
	 * then supplies the team's default project, which is Drafts.
	 */
	private function givenTeamRootDestination(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::PROJECT_A, 'team-id' => self::TEAM, 'is-default' => false],
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
		]);
	}

	private function givenDuplicateLandsIn(string $projectId): void {
		$this->client->method('duplicateFile')
			->willReturn(['id' => self::NEW_ID, 'projectId' => $projectId]);
	}

	/**
	 * THE PARENT IS LOAD-BEARING NOW. {@see DestinationResolver::projectForContentIn()}
	 * asks the folder a design landed in whether it is a project, instead of
	 * short-circuiting on the nearest project ANCESTOR — so a fixture with no
	 * parent resolves past the storage root and lands in Drafts. The mocked
	 * {@see ProjectFolderService} answers null for it, which is the "already
	 * covered by the membership" case these tests are about.
	 */
	private function source(): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(11);
		$node->method('getParent')->willReturn($this->parentFolder());

		return $node;
	}

	private function target(string $name = 'My firsty (copy).penpot'): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(30);
		$node->method('getName')->willReturn($name);
		$node->method('getParent')->willReturn($this->parentFolder());

		return $node;
	}

	/** The folder a design sits in. Not a project of its own in these fixtures. */
	private function parentFolder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(31);
		$folder->method('getPath')->willReturn('/admin/files/Penpot/Team');

		return $folder;
	}
}
