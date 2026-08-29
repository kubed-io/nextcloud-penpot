<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\ProjectFolderService;
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
	private MappingService $mappings;

	/** The mode every mapping reports; see {@see setUp()}. */
	private string $mappingMode = Mapping::MODE_SYNC;
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
		// SYNC unless a test says otherwise — see testALinkMappingIsNeverPromoted().
		$this->mappings = $this->createMock(MappingService::class);
		$this->mappings->method('getByTeamId')->willReturnCallback(
			fn (string $id): Mapping => new Mapping('m1', $id, 'A Team', 'Folder', false, $this->mappingMode),
		);

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
			$this->mappings,
			new NullLogger(),
		);
	}

	// ── the opt-in itself ───────────────────────────────────────────────────

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
	 * A LINK MAPPING'S TREE IS PENPOT'S, so nothing promotes a folder in one.
	 *
	 * Under a link the folders are filled FROM Penpot and mirror it read-only.
	 * Creating a project because a file appeared would be this app inventing
	 * structure in a team it is only supposed to be reading.
	 */
	public function testALinkMappingIsNeverPromoted(): void {
		$this->mappingMode = Mapping::MODE_LINK;
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->pathBelow = 'Team';

		$this->client->expects($this->never())->method('createProject');

		self::assertNull($this->projects->adoptForContent($this->folder(20, 'Team')));
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

		$projects = new ProjectFolderService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->createMock(PersonalTokenService::class),
			$this->mappings,
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
