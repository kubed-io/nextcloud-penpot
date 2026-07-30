<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\MotionService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The move writeback (saga Ch2 Course 4, `move.feature`). These pin the one
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
 *   - folders and unmanaged `.penpot` files are not ours to move.
 */
final class MotionServiceTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const PROJECT_A = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const PROJECT_B = '7c11a0d4-1f52-4a7e-9b3c-2f9d0e4a1b66';
	private const DRAFTS = '0f9b6c2a-5d31-4e88-a1f0-9c7b3d2e5a44';
	private const TEAM = 'df59d46b-a997-80d9-8008-6452575a4b87';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private PersonalTokenService $personalTokens;
	private MotionService $motion;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->personalTokens = $this->createMock(PersonalTokenService::class);
		$this->motion = new MotionService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->personalTokens,
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

	public function testAMoveOutOfEveryMappedFolderPushesNothing(): void {
		// The file left the mirror entirely. Penpot keeps it exactly where it is:
		// unmapping and deleting are Course 5's to decide explicitly, and a drag
		// is not evidence of either.
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => Membership::none(),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target()));
	}

	public function testATeamWithNoVisibleDraftsProjectPushesNothing(): void {
		// Better an un-pushed move than a file re-filed into a guess.
		$this->givenManagedFile();
		$this->givenMembership([
			'target' => new Membership(null, self::TEAM),
			'oldParent' => new Membership(self::PROJECT_A, self::TEAM),
		]);
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::PROJECT_A, 'team-id' => self::TEAM, 'is-default' => false],
		]);
		$this->client->expects($this->never())->method('moveFiles');

		self::assertFalse($this->motion->onMove($this->source(), $this->target()));
	}

	public function testAnUnmanagedPenpotFileIsNotOursToMove(): void {
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

	public function testAFolderIsIgnored(): void {
		// Folder layout is Nextcloud's (§6.29) — a project folder has no position
		// in Penpot to update, and the one illegal folder move is refused earlier
		// by MoveGuardListener.
		$this->client->expects($this->never())->method('moveFiles');

		$folder = $this->createMock(Folder::class);
		self::assertFalse($this->motion->onMove($this->source(), $folder));
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

		self::assertTrue($this->service->onMove($this->source(), $this->target()));
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

		self::assertTrue($this->service->onMove($this->source(), $this->target()));
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
	 * Program the resolver: the target file resolves to `target`, and the source's
	 * old parent folder to `oldParent`. They are told apart by node id.
	 *
	 * @param array{target: Membership, oldParent: Membership} $by
	 */
	private function givenMembership(array $by): void {
		$this->resolver->method('resolve')->willReturnCallback(
			static fn (Node $node): Membership => $node->getId() === 30 ? $by['target'] : $by['oldParent'],
		);
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
