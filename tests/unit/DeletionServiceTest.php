<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\DeletionService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Delete, in the two steps that mirror the two trashes (`delete.feature`).
 *
 * The tests that matter here are the ones guarding the PURGE, because
 * `permanently-delete-team-files` has no safety of its own: it destroys whatever
 * id it is handed, in the trash or not (saga §C6.11, proven live on a restored
 * design). Every "never purges" case below is that seatbelt.
 */
final class DeletionServiceTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private DeletionService $deletions;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);

		$tokens = $this->createStub(PersonalTokenService::class);
		$tokens->method('tokenForActor')->willReturn(null);

		$this->deletions = new DeletionService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$tokens,
			new NullLogger(),
		);
	}

	// ── the soft step ───────────────────────────────────────────────────────

	/** A delete reaches Penpot's trash — soft on both sides, which is the design. */
	public function testTrashingAMirrorMovesTheDesignToPenpotsTrash(): void {
		$this->givenStamped();

		$this->client->expects($this->once())->method('deleteFile')->with(self::PENPOT_ID, null);
		$this->client->expects($this->never())->method('permanentlyDeleteFiles');

		$this->deletions->onTrashed($this->file());
	}

	/** No id, nothing to delete — and this is what keeps a mapped folder ordinary. */
	public function testTrashingAnUntrackedFileNeverContactsPenpot(): void {
		$this->metadata->method('readFile')->willReturn(null);

		$this->client->expects($this->never())->method('deleteFile');

		$this->deletions->onTrashed($this->file());
	}

	/**
	 * Being asked to delete something already gone is not an error — it is the
	 * outcome the user wanted. The local delete proceeds regardless.
	 */
	public function testAFailedRemoteDeleteDoesNotThrow(): void {
		$this->givenStamped();
		$this->client->method('deleteFile')->willThrowException(new \RuntimeException('already gone'));

		$this->deletions->onTrashed($this->file());

		$this->addToAssertionCount(1);
	}

	// ── the hard step, and its seatbelt ─────────────────────────────────────

	/** In the trash → destroy it. The one irreversible thing this app can cause. */
	public function testPurgingADesignThatIsInPenpotsTrashDestroysIt(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([['id' => self::PENPOT_ID]]);

		$this->client->expects($this->once())
			->method('permanentlyDeleteFiles')
			->with(self::TEAM, [self::PENPOT_ID], null);

		$this->deletions->onPurged($this->file());
	}

	/**
	 * THE CASE THAT WOULD DESTROY LIVE WORK. Someone restored the design in
	 * Penpot's own UI; the trashed mirror still carries its id. Handing that id
	 * to the purge command would destroy a live design, because the command does
	 * not check (§C6.11). The trash listing is the only thing that stops it.
	 */
	public function testPurgingNeverDestroysADesignThatIsNotInPenpotsTrash(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([['id' => 'some-other-design']]);

		$this->client->expects($this->never())->method('permanentlyDeleteFiles');

		$this->deletions->onPurged($this->file());
	}

	/** An empty trash listing purges nothing at all. */
	public function testPurgingWithAnEmptyTrashListingDestroysNothing(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([]);

		$this->client->expects($this->never())->method('permanentlyDeleteFiles');

		$this->deletions->onPurged($this->file());
	}

	/**
	 * The listing is read BEFORE the destroy, every time. Pinned as an ordering
	 * assertion because a refactor that cached or skipped it would be invisible
	 * in every other test here.
	 */
	public function testTheTrashListingIsAlwaysReadBeforePurging(): void {
		$this->givenStamped();
		$this->client->expects($this->once())->method('deletedFiles')->with(self::TEAM)
			->willReturn([['id' => self::PENPOT_ID]]);

		$this->deletions->onPurged($this->file());
	}

	/** No team on the stamp and none resolvable → Penpot is left alone entirely. */
	public function testPurgingWithoutATeamLeavesPenpotAlone(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_LINK, ''));
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));

		$this->client->expects($this->never())->method('deletedFiles');
		$this->client->expects($this->never())->method('permanentlyDeleteFiles');

		$this->deletions->onPurged($this->file());
	}

	/** An untracked file being purged is just a file leaving the trash. */
	public function testPurgingAnUntrackedFileNeverContactsPenpot(): void {
		$this->metadata->method('readFile')->willReturn(null);

		$this->client->expects($this->never())->method('deletedFiles');
		$this->client->expects($this->never())->method('permanentlyDeleteFiles');

		$this->deletions->onPurged($this->file());
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function givenStamped(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, self::TEAM));
	}

	private function file(): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(30);
		$node->method('getName')->willReturn('Doomed.penpot');

		return $node;
	}
}
