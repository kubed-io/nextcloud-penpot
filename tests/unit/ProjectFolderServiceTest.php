<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
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
 * A folder becomes a Penpot project — **when a design is in it**, or when someone
 * tags it (`projects/create.feature`).
 *
 * The asymmetry under test: every Penpot project becomes a folder automatically,
 * but only SOME folders become projects. This file used to say that happened
 * "ONLY when someone tags it", which stopped being true when promotion by content
 * landed — so the two routes are now tested side by side, and the thing worth
 * pinning is that they agree. Which gesture promoted a folder must not change
 * what the folder means.
 *
 * The interesting cases are still mostly about what does NOT happen — an EMPTY
 * folder, a folder outside every mapping, a mapping root, a folder that is
 * already a project, an untracked `.penpot` sitting inside one.
 */
final class ProjectFolderServiceTest extends TestCase {
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const NEW_PROJECT = '86f123cb-0682-808c-8008-69d4e8b803ec';
	private const OTHER_PROJECT = '61d8ecb9-c430-8120-8008-622627f23540';
	private const DESIGN_A = '86f123cb-0682-808c-8008-6885698e25e5';
	private const DESIGN_B = '86f123cb-0682-808c-8008-6885698e25f7';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private ProjectTags $tags;
	private ProjectFolderService $projects;

	/** @var array<int, FolderMarkers> node id -> folder markers */
	private array $folderMarkers = [];

	/** @var array<int, ?PenpotFileMetadata> node id -> file stamp */
	private array $fileStamps = [];

	/**
	 * What the STUB should answer for a folder's path below the mapping.
	 *
	 * Null here means "do not override" — the double falls back to the node's own
	 * name, which is what the real resolver returns for a folder sitting directly
	 * under a mapping, and what every flat folder in these tests is.
	 *
	 * IT DOES NOT MEAN WHAT NULL MEANS ON THE REAL RESOLVER.
	 * {@see MembershipResolver::pathBelowMapping()} returns null for a node outside
	 * every mapping and for a mapping ROOT — cases with no name at all. Those are
	 * exercised by setting this to an actual value or by the dedicated tests, never
	 * by leaving it null.
	 */
	private ?string $pathBelow = null;

	/** When true the stub answers null, the way the real resolver does for a root. */
	private bool $pathBelowIsNull = false;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->tags = $this->createMock(ProjectTags::class);

		$this->metadata->method('readFolder')->willReturnCallback(
			fn (int $id): FolderMarkers => $this->folderMarkers[$id] ?? new FolderMarkers('', ''),
		);
		$this->metadata->method('readFile')->willReturnCallback(
			fn (int $id): ?PenpotFileMetadata => $this->fileStamps[$id] ?? null,
		);
		$this->resolver->method('pathBelowMapping')->willReturnCallback(
			fn (Node $node): ?string => $this->pathBelowIsNull ? null : ($this->pathBelow ?? $node->getName()),
		);

		$this->projects = new ProjectFolderService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->createMock(PersonalTokenService::class),
			$this->tags,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	// ── the opt-in itself ───────────────────────────────────────────────────

	public function testTaggingAFolderInsideAMappingCreatesTheProject(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);

		$this->client->expects($this->once())->method('createProject')
			->with(self::TEAM, 'Client Work');
		$this->metadata->expects($this->once())->method('writeFolder')
			->with(50, [PenpotMetadata::KEY_PROJECT_ID => self::NEW_PROJECT]);

		$this->projects->onTagged($this->folder(50, 'Client Work'));
	}

	/**
	 * TAGGING THE MAPPED ROOT CREATES NOTHING, AND TAKES THE TAG BACK OFF.
	 *
	 * The root carries a team id, so the team lookup succeeds and the old code fell
	 * through to the name check — where `pathBelowMapping()`'s null became an empty
	 * string and the folder was refused with "the folder name cannot be used as a
	 * Penpot project name". That sends someone off to rename a folder that is
	 * perfectly fine. A team root simply is not a project.
	 *
	 * The tag comes off even so, which is the one thing that differs from a folder
	 * outside every mapping: THAT tag is left alone because the folder is none of
	 * this app's business, while a mapped root is entirely its business. The pull
	 * tags only folders it has stamped with a project id, so a root left wearing
	 * the tag would be the single place the badge means nothing.
	 *
	 * No `createProject`, and no warning either — trying it is reasonable.
	 */
	public function testTaggingTheMappedRootCreatesNothingAndUntagsIt(): void {
		$this->inTeam();

		$this->client->expects($this->never())->method('createProject');
		$this->tags->expects($this->once())->method('remove')->with(50);

		$this->projects->onTagged($this->rootFolder(50));
	}

	/**
	 * A NESTED FOLDER'S PROJECT IS NAMED BY ITS PATH, not by the folder.
	 *
	 * `projects/create.feature` says so directly — `Penpot/Team/Deep` becomes the
	 * project `Team/Deep` — and the pull has always read a project name that way
	 * round, handing it to `newFolder()` so the slashes become folders. This
	 * direction used the bare name, so tagging `Penpot/Clients/Client Work` created
	 * a project called `Client Work` and the two sides disagreed.
	 */
	public function testTaggingANestedFolderNamesTheProjectByItsPath(): void {
		$this->inTeam();
		$this->pathBelow = 'Clients/Client Work';
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);

		$this->client->expects($this->once())->method('createProject')
			->with(self::TEAM, 'Clients/Client Work');

		$this->projects->onTagged($this->folder(50, 'Client Work'));
	}

	/**
	 * THE REASON TO ALLOW OPTING IN LATE. A folder someone has been filling with
	 * designs becomes a project WITH its contents — one `move-files` for the lot,
	 * because the command takes a set.
	 */
	public function testDesignsAlreadyInsideAreFiledIntoTheNewProject(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);
		$this->fileStamps[61] = $this->stamp(self::DESIGN_A);
		$this->fileStamps[62] = $this->stamp(self::DESIGN_B);

		$this->client->expects($this->once())->method('moveFiles')
			->with(self::NEW_PROJECT, [self::DESIGN_A, self::DESIGN_B]);

		$this->projects->onTagged($this->folder(50, 'Client Work', [
			$this->design(61, 'Login.penpot'),
			$this->design(62, 'Signup.penpot'),
		]));
	}

	/**
	 * The descent must read the tree the way {@see MembershipResolver} reads it
	 * upwards: a subfolder carrying its own project id is a NEARER ancestor, so
	 * its designs are not ours to claim. Getting this wrong would re-file another
	 * project's designs into this one.
	 */
	public function testDesignsUnderANestedProjectFolderAreLeftAlone(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);
		$this->folderMarkers[70] = new FolderMarkers(self::OTHER_PROJECT, '');
		$this->fileStamps[61] = $this->stamp(self::DESIGN_A);
		$this->fileStamps[71] = $this->stamp(self::DESIGN_B);

		$nested = $this->folder(70, 'Already A Project', [$this->design(71, 'Theirs.penpot')]);

		$this->client->expects($this->once())->method('moveFiles')
			->with(self::NEW_PROJECT, [self::DESIGN_A]);

		$this->projects->onTagged($this->folder(50, 'Client Work', [
			$this->design(61, 'Login.penpot'),
			$nested,
		]));
	}

	/** A plain subfolder is not a boundary — the resolver walks straight past it. */
	public function testDesignsInAPlainSubfolderAreFiledToo(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);
		$this->fileStamps[71] = $this->stamp(self::DESIGN_B);

		$plain = $this->folder(70, 'Drafts', [$this->design(71, 'Sketch.penpot')]);

		$this->client->expects($this->once())->method('moveFiles')
			->with(self::NEW_PROJECT, [self::DESIGN_B]);

		$this->projects->onTagged($this->folder(50, 'Client Work', [$plain]));
	}

	/**
	 * An untracked `.penpot` is an upload or a hand-made file this app has never
	 * registered. Creating designs for those is CreationService's carve-out — not
	 * something to infer from a folder tag.
	 */
	public function testAnUntrackedPenpotFileIsNotFiled(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);

		$this->client->expects($this->never())->method('moveFiles');

		$this->projects->onTagged($this->folder(50, 'Client Work', [
			$this->design(61, 'Uploaded.penpot'),
			$this->design(62, 'notes.txt'),
		]));
	}

	/** An empty folder is a perfectly good project. No `move-files` round trip for nothing. */
	public function testAnEmptyFolderCostsNoMoveFiles(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);

		$this->client->expects($this->never())->method('moveFiles');

		$this->projects->onTagged($this->folder(50, 'Client Work'));
	}

	// ── where it does nothing ───────────────────────────────────────────────

	/**
	 * The pull tags every folder it mirrors, so this is the COMMON path. A second
	 * create would leave two folders claiming one project — the exact failure
	 * `copy-project.feature` refuses folder copies to avoid.
	 */
	public function testAFolderThatIsAlreadyAProjectIsNotCreatedAgain(): void {
		$this->folderMarkers[50] = new FolderMarkers(self::OTHER_PROJECT, '');

		$this->client->expects($this->never())->method('createProject');
		$this->metadata->expects($this->never())->method('writeFolder');

		$this->projects->onTagged($this->folder(50, 'Already Mirrored'));
	}

	/**
	 * Tags are instance-wide: someone can put `penpot` on a folder in Documents.
	 * No team resolves for it even in principle, so nothing is sent — and the tag
	 * is LEFT ALONE, because stripping a user's own tag off a folder this app has
	 * no business touching would be a worse surprise than an inert label.
	 */
	public function testAFolderOutsideEveryMappingIsLeftEntirelyAlone(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));

		$this->client->expects($this->never())->method('createProject');
		$this->tags->expects($this->never())->method('remove');

		$this->projects->onTagged($this->folder(50, 'Holiday Photos'));
	}

	// ── refusals: the tag comes back off ────────────────────────────────────

	/**
	 * Penpot's own rule is [:string {:max 250, :min 1}], checked locally so the
	 * refusal costs no round trip. Removing the tag is what makes this a two-step
	 * the user controls (rename, re-tag) rather than a half-created state.
	 */
	public function testAnUnusableNameIsRefusedBeforePenpotIsContacted(): void {
		$this->inTeam();

		$this->client->expects($this->never())->method('createProject');
		$this->tags->expects($this->once())->method('remove')->with(50);

		$this->projects->onTagged($this->folder(50, str_repeat('x', 251)));
	}

	/** §6.18 rule 3: a remote failure never destroys local state — the folder stands. */
	public function testAFailedCreateLeavesTheFolderUnstampedAndUntagged(): void {
		$this->inTeam();
		$this->client->method('createProject')->willThrowException(new \RuntimeException('boom'));

		$this->metadata->expects($this->never())->method('writeFolder');
		$this->tags->expects($this->once())->method('remove')->with(50);

		$this->projects->onTagged($this->folder(50, 'Client Work'));
	}

	/** A 200 with no id is a failure too, and is treated as one. */
	public function testACreateWithNoIdIsTreatedAsAFailure(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn([]);

		$this->metadata->expects($this->never())->method('writeFolder');
		$this->tags->expects($this->once())->method('remove')->with(50);

		$this->projects->onTagged($this->folder(50, 'Client Work'));
	}

	/**
	 * The project exists and the folder is stamped; only the re-filing failed. The
	 * stamp is what every later lookup reads, so keeping it is what lets the next
	 * pull see the truth — dropping it would leave a project in Penpot that
	 * nothing in Nextcloud pointed at.
	 */
	public function testAFailedRefileStillLeavesTheFolderStamped(): void {
		$this->inTeam();
		$this->client->method('createProject')->willReturn(['id' => self::NEW_PROJECT]);
		$this->client->method('moveFiles')->willThrowException(new \RuntimeException('boom'));
		$this->fileStamps[61] = $this->stamp(self::DESIGN_A);

		$this->metadata->expects($this->once())->method('writeFolder')
			->with(50, [PenpotMetadata::KEY_PROJECT_ID => self::NEW_PROJECT]);
		$this->tags->expects($this->never())->method('remove');

		$this->projects->onTagged($this->folder(50, 'Client Work', [$this->design(61, 'Login.penpot')]));
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function inTeam(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
	}

	private function stamp(string $penpotId): PenpotFileMetadata {
		return new PenpotFileMetadata($penpotId, '5@t1', Mapping::MODE_LINK, self::TEAM);
	}

	// ── adoptForContent: promotion by CONTENT rather than by tag ────────────

	/**
	 * A DESIGN LANDING IN A PLAIN FOLDER IS WHAT MAKES IT A PROJECT.
	 *
	 * The rule this class used to state the opposite of — *"by opt-in, never by
	 * accident"* — and the reason the reversal is safe is the test below it: an
	 * EMPTY folder is still nobody's business but its owner's.
	 */
	public function testADesignArrivingPromotesItsFolder(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team/Deep';
		$this->client->method('createProject')->willReturn(['id' => 'project-new']);

		$this->metadata->expects($this->once())->method('writeFolder')
			->with(20, [PenpotMetadata::KEY_PROJECT_ID => 'project-new']);
		$this->tags->expects($this->once())->method('apply')->with(20);

		self::assertSame('project-new', $this->projects->adoptForContent($this->folder(20, 'Deep')));
	}

	/**
	 * THE NAME IS THE PATH BELOW THE MAPPING, not the folder's own name — the same
	 * rule §C6.38 made true for a rename, arriving here for a create.
	 */
	public function testTheAdoptedProjectIsNamedByItsPath(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team/Deep/Deeper';
		$this->client->expects($this->once())->method('createProject')
			->with(self::TEAM, 'Team/Deep/Deeper', null)
			->willReturn(['id' => 'project-new']);

		$this->projects->adoptForContent($this->folder(20, 'Deeper'));
	}

	/**
	 * THE MAPPING ROOT IS DRAFTS, NOT A PROJECT (§6.35). `pathBelowMapping()`
	 * returns null for a root, and reading that as "not a project" is what stops
	 * the first design dropped into `Penpot/` inventing a project named after the
	 * mapped folder. A null return sends the caller to Drafts.
	 */
	public function testADesignAtTheMappingRootPromotesNothing(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelowIsNull = true;
		$this->client->expects($this->never())->method('createProject');

		self::assertNull($this->projects->adoptForContent($this->folder(20, 'Penpot')));
	}

	/** Already a project: the common path, and it must cost no round trip. */
	public function testAFolderThatIsAlreadyAProjectIsReturnedAsIs(): void {
		$this->folderMarkers[20] = new FolderMarkers('project-existing', '');
		$this->client->expects($this->never())->method('createProject');

		self::assertSame('project-existing', $this->projects->adoptForContent($this->folder(20, 'Team')));
	}

	/** Outside every mapping there is no team to create a project in. */
	public function testAFolderOutsideEveryMappingPromotesNothing(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));
		$this->client->expects($this->never())->method('createProject');

		self::assertNull($this->projects->adoptForContent($this->folder(20, 'Holiday photos')));
	}

	/**
	 * PROMOTION BY CONTENT RE-FILES WHAT WAS ALREADY THERE, exactly as the tag does.
	 *
	 * A managed design can already sit below a plain folder — one that left a
	 * mapping and came back, or one whose own promotion Penpot refused a moment
	 * earlier. Filing only the design that happened to arrive last would leave two
	 * designs in one folder showing up in two Penpot projects, which is the failure
	 * this class's late-opt-in contract exists to prevent. Which route promoted the
	 * folder must not change what the folder means.
	 */
	public function testAdoptionFilesTheDesignsAlreadyInTheFolder(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team';
		$this->client->method('createProject')->willReturn(['id' => 'project-new']);

		$this->fileStamps[31] = $this->stamp('design-already-here');
		$folder = $this->folder(20, 'Team', [$this->design(31, 'Older.penpot')]);

		$this->client->expects($this->once())->method('moveFiles')
			->with('project-new', ['design-already-here'], null);

		self::assertSame('project-new', $this->projects->adoptForContent($folder));
	}

	/**
	 * TWO DESIGNS ARRIVING AT ONCE FILE INTO ONE PROJECT, not two.
	 *
	 * Dragging three designs into a new folder is three concurrent requests in
	 * three processes, and Penpot allows two projects of the same name in a team —
	 * so the unmitigated race splits the designs across duplicates. The loser now
	 * returns the WINNER's id, which keeps the files together at the cost of one
	 * empty project nobody references.
	 *
	 * Simulated by the marker appearing between the read and the stamp, which is
	 * exactly where the window is.
	 */
	public function testAConcurrentArrivalDefersToTheProjectThatLanded(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team';

		// Not a project when adoptForContent() looks; a project by the time the
		// create returns — someone else got there during the round trip.
		$reads = 0;
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->metadata->method('readFolder')->willReturnCallback(
			static function () use (&$reads): FolderMarkers {
				$reads++;

				return $reads === 1 ? new FolderMarkers('', '') : new FolderMarkers('project-theirs', '');
			},
		);
		$this->metadata->expects($this->never())->method('writeFolder');
		$this->client->method('createProject')->willReturn(['id' => 'project-mine']);
		$this->tags->expects($this->never())->method('apply');

		$projects = new ProjectFolderService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->createMock(PersonalTokenService::class),
			$this->tags,
			new SyncGuard(),
			new NullLogger(),
		);

		self::assertSame('project-theirs', $projects->adoptForContent($this->folder(20, 'Team')));
	}

	/**
	 * A REFUSAL FROM PENPOT IS A NULL AND A LOG LINE, NOT AN ERROR.
	 *
	 * This fires as a side effect of a drag or a "+ New", so the user is never
	 * shown a failure for a gesture that worked — the design still lands, in the
	 * team's Drafts, and the folder is simply not a project yet. The folder must
	 * not be stamped: a marker naming a project that was never created is worse
	 * than no marker.
	 */
	public function testAPenpotRefusalLeavesTheFolderUnstamped(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team';
		$this->client->method('createProject')->willThrowException(new \RuntimeException('nope'));

		$this->metadata->expects($this->never())->method('writeFolder');
		$this->tags->expects($this->never())->method('apply');

		self::assertNull($this->projects->adoptForContent($this->folder(20, 'Team')));
	}

	/** A mapped ROOT: the resolver gives it no path below a mapping. */
	private function rootFolder(int $id): Folder {
		$this->pathBelowIsNull = true;

		return $this->folder($id, 'Penpot');
	}

	/** @param list<Node> $children */
	private function folder(int $id, string $name, array $children = []): Folder {
		$node = $this->createMock(Folder::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn('/admin/files/Penpot/' . $name);
		$node->method('getDirectoryListing')->willReturn($children);

		return $node;
	}

	private function design(int $id, string $name): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);

		return $node;
	}
}
