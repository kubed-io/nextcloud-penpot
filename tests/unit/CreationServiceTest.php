<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\CreationService;
use OCA\PenpotSync\Service\DestinationResolver;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Creating a design from a new `.penpot` file (`create-design.feature`).
 *
 * Two rules carry this service, and each has its own cluster below: creation
 * happens only where a PROJECT resolves (Penpot has no rootless design), and an
 * uploaded ARCHIVE is never turned into an empty design — that one is unique to
 * this app, because neither sibling can receive a file that already contains the
 * thing they would otherwise create.
 */
final class CreationServiceTest extends TestCase {
	private const NEW_ID = '86f123cb-0682-808c-8008-6885698e25e5';
	private const PROJECT = '61d8ecb9-c430-8120-8008-622627f23540';
	private const DRAFTS = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private ArchiveService $archives;
	private CreationService $creations;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);
		$this->archives = $this->createMock(ArchiveService::class);

		$this->creations = new CreationService(
			$this->client,
			$this->metadata,
			$this->resolver,
			// The real destination resolver over the mocked client, so the Drafts
			// lookup is exercised rather than assumed away (§C6.10's lesson).
			new DestinationResolver($this->client, new NullLogger()),
			$this->archives,
			new NullLogger(),
		);
	}

	// ── where a design is created ───────────────────────────────────────────

	public function testANewFileInAProjectFolderCreatesADesignThere(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('createFile')->willReturn(['id' => self::NEW_ID]);

		$this->client->expects($this->once())->method('createFile')->with(self::PROJECT, 'New design');
		$this->metadata->expects($this->once())->method('writeFile')->with(
			30,
			[
				PenpotMetadata::KEY_ID => self::NEW_ID,
				PenpotMetadata::KEY_MODE => Mapping::MODE_LINK,
				PenpotMetadata::KEY_TEAM_ID => self::TEAM,
			],
		);

		$this->creations->onWritten($this->file());
	}

	/**
	 * At a team root there is no project folder, so it resolves to no project —
	 * but those files are the team's Drafts, a real project (§6.35). The same
	 * distinction the copy path had to learn the hard way.
	 */
	public function testANewFileAtTheTeamRootIsCreatedInDrafts(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::PROJECT, 'team-id' => self::TEAM, 'is-default' => false],
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
		]);
		$this->client->method('createFile')->willReturn(['id' => self::NEW_ID]);

		$this->client->expects($this->once())->method('createFile')->with(self::DRAFTS, 'New design');

		$this->creations->onWritten($this->file());
	}

	/** Outside every mapping there is no project, and inventing one is not an option. */
	public function testANewFileOutsideEveryMappingCreatesNothing(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));

		$this->client->expects($this->never())->method('createFile');
		$this->metadata->expects($this->never())->method('writeFile');

		$this->creations->onWritten($this->file());
	}

	// ── an upload is not a create ───────────────────────────────────────────

	/**
	 * THE GUARD NEITHER SIBLING NEEDS. A dragged-in `.penpot` already holds a
	 * whole design. Creating an EMPTY one for it would leave the file and Penpot
	 * contradicting each other, and the next `sync` pull would overwrite the real
	 * archive with the empty export — actively destroying what was uploaded.
	 */
	public function testAnUploadedArchiveIsLeftAloneRatherThanCreatedAsEmpty(): void {
		$this->metadata->method('readFile')->willReturn(null);
		$this->archives->method('holdsArchive')->willReturn(true);
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));

		$this->client->expects($this->never())->method('createFile');
		$this->metadata->expects($this->never())->method('writeFile');

		$this->creations->onWritten($this->file());
	}

	// ── never twice ─────────────────────────────────────────────────────────

	/**
	 * A file that is already ours is a re-write — the pull, a client, an editor —
	 * never a creation. Without this every pull would create a second design for
	 * every mirror it touched.
	 */
	public function testAnAlreadyTrackedFileIsNeverCreatedAgain(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::NEW_ID, '5@t1', Mapping::MODE_LINK, self::TEAM));

		$this->client->expects($this->never())->method('createFile');

		$this->creations->onWritten($this->file());
	}

	// ── the name, and failure ───────────────────────────────────────────────

	public function testTheDesignIsNamedAfterTheFileWithoutTheExtension(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('createFile')->willReturn(['id' => self::NEW_ID]);

		$this->client->expects($this->once())->method('createFile')->with(self::PROJECT, 'Homepage v2');

		$this->creations->onWritten($this->file('Homepage v2.penpot'));
	}

	/** §6.18 rule 3: the user still has the file they made; it is just not a design. */
	public function testAFailedCreateLeavesTheFileUntracked(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('createFile')->willThrowException(new \RuntimeException('boom'));

		$this->metadata->expects($this->never())->method('writeFile');

		$this->creations->onWritten($this->file());
	}

	/** A 200 with no id is a failure too, and is treated as one. */
	public function testACreateWithNoIdIsTreatedAsAFailure(): void {
		$this->givenUntrackedEmptyFile();
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('createFile')->willReturn([]);

		$this->metadata->expects($this->never())->method('writeFile');

		$this->creations->onWritten($this->file());
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function givenUntrackedEmptyFile(): void {
		$this->metadata->method('readFile')->willReturn(null);
		$this->archives->method('holdsArchive')->willReturn(false);
	}

	private function file(string $name = 'New design.penpot'): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(30);
		$node->method('getName')->willReturn($name);

		return $node;
	}
}
