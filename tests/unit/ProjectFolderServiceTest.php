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
 * A folder becomes a Penpot project — by opt-in (`create-project.feature`).
 *
 * The asymmetry under test: every Penpot project becomes a folder automatically,
 * but a folder becomes a project ONLY when someone tags it. So the interesting
 * cases are all about what does NOT happen — an untagged folder, a folder
 * outside every mapping, a folder that is already a project, an untracked
 * `.penpot` sitting inside one.
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
