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
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\RestoreService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Restore, in the three layers that decide what "restore" even means
 * (`delete.feature`, `restore.feature`).
 *
 * ## THE TESTS THAT MATTER HERE ARE THE TWO LIES
 *
 * `restore-deleted-team-files` has been seen to report success twice without
 * doing the work, in two different ways, and both are cheap to regress:
 *
 *   - §C6.11 — an id that is not in the trash gets 200 and an `end` event
 *     carrying an EMPTY SET. No error.
 *   - §6.49 — the `end` event arrived while `deleted_at` was still set.
 *
 * A caller that believes either one tells the user their design is back when it
 * is not, and they stop looking for it. So "reported success but restored
 * nothing" and "reported success but is still in the trash" are both pinned
 * below, and both assert the same thing: the outcome is not treated as success.
 *
 * The other axis is layer selection — the app must never spend a write on a
 * design that never left, and must never quietly do nothing for one that is
 * gone for good.
 */
final class RestoreServiceTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const PROJECT = 'df59d46b-a997-80d9-8008-6452575b0a69';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private MembershipResolver $resolver;
	private RestoreService $restores;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->resolver = $this->createMock(MembershipResolver::class);

		$tokens = $this->createStub(PersonalTokenService::class);
		$tokens->method('tokenForActor')->willReturn(null);

		$this->restores = new RestoreService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$tokens,
			new NullLogger(),
		);
	}

	// ── layer 2: the design is in Penpot's trash ────────────────────────────

	/** The ordinary round trip: trash it, restore it, and the design comes back too. */
	public function testRestoringAMirrorBringsTheDesignBackOutOfPenpotsTrash(): void {
		$this->givenStamped();
		$this->givenInPenpotTrashThenNot();

		$this->client->expects($this->once())->method('restoreDeletedFiles')
			->with(self::TEAM, [self::PENPOT_ID], null)
			->willReturn([self::PENPOT_ID]);

		$this->restores->onRestored($this->file());
	}

	/**
	 * §C6.11's lie: 200, an `end` event, and an empty set.
	 *
	 * Pinned as "the re-read never happens" because that is the observable
	 * difference — a caller that believed the stream would go on to confirm and
	 * report success. Nothing is thrown: the local file is already back, and the
	 * user's restore is not undone over a remote failure.
	 */
	public function testARestoreThatRestoredNothingIsNotTreatedAsSuccess(): void {
		$this->givenStamped();

		// One listing only — the pre-check. Reaching a second would mean the empty
		// set was read as success and the confirming re-read ran.
		$this->client->expects($this->once())->method('deletedFiles')
			->with(self::TEAM)
			->willReturn([['id' => self::PENPOT_ID]]);
		$this->client->method('restoreDeletedFiles')->willReturn([]);

		$this->restores->onRestored($this->file());
	}

	/**
	 * §6.49's lie: Penpot named our id, and the design is still in the trash.
	 *
	 * This did not reproduce on 2.17.0. The check stays because one
	 * non-reproduction does not disprove a race, and the confirming read costs one
	 * cheap listing against the alternative of a false "your design is back".
	 */
	public function testARestoreStillInTheTrashAfterwardsIsNotTreatedAsSuccess(): void {
		$this->givenStamped();

		$this->client->expects($this->exactly(2))->method('deletedFiles')
			->with(self::TEAM)
			->willReturn([['id' => self::PENPOT_ID]]);
		$this->client->method('restoreDeletedFiles')->willReturn([self::PENPOT_ID]);

		$this->restores->onRestored($this->file());
	}

	/** Penpot being down never undoes the local restore. */
	public function testAFailedRestoreNeverThrows(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willThrowException(
			new \OCA\PenpotSync\Exception\PenpotApiException('Penpot is unreachable'),
		);

		$this->restores->onRestored($this->file());

		$this->addToAssertionCount(1);
	}

	// ── layer 1: the design never left ──────────────────────────────────────

	/**
	 * The mirror was trashed while Penpot was unreachable, or someone restored the
	 * design in Penpot's own UI first. Taking the file out of the trash IS the
	 * whole restore, and spending a write on it would be pure noise.
	 */
	public function testADesignThatStillExistsIsNeverRestoredIntoPenpot(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([]);
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('getProjectFiles')->with(self::PROJECT)
			->willReturn([['id' => self::PENPOT_ID]]);

		$this->client->expects($this->never())->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	// ── layer 3: it is gone, and that is not built ──────────────────────────

	/**
	 * Past the grace window, or permanently deleted. Importing the archive is
	 * `restore.feature`'s slice and does not exist — so nothing is sent, and the
	 * one thing this must not do is call the restore command anyway on the chance
	 * it works. §6.20: a purged id cannot be resurrected, tested directly.
	 */
	public function testADesignThatIsGoneForGoodIsNotSilentlyRecreated(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([]);
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
		$this->client->method('getProjectFiles')->with(self::PROJECT)->willReturn([]);

		$this->client->expects($this->never())->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	// ── the files this must not touch ───────────────────────────────────────

	/** An untracked file coming out of the trash is just a file coming out of the trash. */
	public function testRestoringAnUntrackedFileNeverContactsPenpot(): void {
		$this->metadata->method('readFile')->willReturn(null);

		$this->client->expects($this->never())->method('deletedFiles');
		$this->client->expects($this->never())->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	/**
	 * No team on the stamp and none resolvable → Penpot is left alone entirely.
	 *
	 * Both trash commands are TEAM-scoped (§6.49), so without a team there is
	 * nothing to ask, and guessing one would aim a write at the wrong team.
	 */
	public function testRestoringWithoutATeamLeavesPenpotAlone(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_LINK, ''));
		$this->resolver->method('resolve')->willReturn(new Membership(null, null));

		$this->client->expects($this->never())->method('deletedFiles');
		$this->client->expects($this->never())->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function givenStamped(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, self::TEAM));
	}

	/** In the trash when asked, out of it when asked again — a restore that worked. */
	private function givenInPenpotTrashThenNot(): void {
		$this->client->method('deletedFiles')->willReturnOnConsecutiveCalls(
			[['id' => self::PENPOT_ID]],
			[],
		);
	}

	private function file(): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(4242);
		$node->method('getName')->willReturn('Login.penpot');

		return $node;
	}
}
