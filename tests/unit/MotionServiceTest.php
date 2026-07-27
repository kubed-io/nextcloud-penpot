<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

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

	// ── fixtures ────────────────────────────────────────────────────────────

	private function givenManagedFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@x', 'link'));
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
