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
 * `restore-deleted-team-files` has been seen to report success without doing the
 * work in two different ways, and both are cheap to regress:
 *
 *   - §C6.11 — an id that is not in the trash gets 200 and an `end` event
 *     carrying an EMPTY SET. No error.
 *   - §6.49 — the SSE returns before the transaction settles, so the event can
 *     arrive while `deleted_at` is still set. A second call clears it.
 *
 * A caller that believes either one tells the user their design is back when it
 * is not, and they stop looking for it.
 *
 * The sharpest test here is neither of those, though: it is
 * {@see testSuccessIsMeasuredAgainstTheProjectListingNotTheTrash()}. This
 * class's first draft confirmed the restore by asking whether the design had
 * left the TRASH, which sounds equivalent to "it is back" and is not — inside
 * §6.49's window the two listings disagree, and the integration suite failed on
 * the slice's headline scenario about half the time as a result. Getting the
 * check right mattered less than asking the right thing.
 *
 * The other axis is layer selection — the app must never spend a write on a
 * design that never left, and must never quietly do nothing for one that is
 * gone for good.
 */
final class RestoreServiceTest extends TestCase {
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const PROJECT = 'df59d46b-a997-80d9-8008-6452575b0a69';
	private const DRAFTS = '4eda2e11-843e-8045-8008-51824bdafd88';

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
		$this->givenInPenpotTrash();
		$this->givenResolvesToProject();
		$this->givenProjectHolds([self::PENPOT_ID]);

		$this->client->expects($this->once())->method('restoreDeletedFiles')
			->with(self::TEAM, [self::PENPOT_ID], null)
			->willReturn([self::PENPOT_ID]);

		$this->restores->onRestored($this->file());
	}

	/**
	 * THE ORACLE, PINNED. Success is "the design is back in the listing the PULL
	 * reads", not "the design is out of the trash".
	 *
	 * Those two disagree inside the window where Penpot's restore has returned but
	 * its transaction has not settled (§6.49) — and that disagreement is not
	 * theoretical: it failed the integration suite's headline scenario about half
	 * the time. A version that asks the trash listing passes this test's setup
	 * while the design is still unlisted and the next pull trashes the mirror.
	 */
	public function testSuccessIsMeasuredAgainstTheProjectListingNotTheTrash(): void {
		$this->givenStamped();
		$this->givenResolvesToProject();
		$this->givenInPenpotTrash();
		$this->client->method('restoreDeletedFiles')->willReturn([self::PENPOT_ID]);
		// …but not yet in the project, then there after the second call.
		$this->client->method('getProjectFiles')->willReturnOnConsecutiveCalls([], [['id' => self::PENPOT_ID]]);

		// §6.49's remedy, and the whole reason this test exists.
		$this->client->expects($this->exactly(2))->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	/** A design at the team root is in Drafts — a real project, resolved by id (§6.35). */
	public function testAMirrorAtTheTeamRootIsConfirmedAgainstDrafts(): void {
		$this->givenStamped();
		$this->givenInPenpotTrash();
		// No project folder above it: membership resolves to the team, not a project.
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->client->method('getAllProjects')->willReturn([
			['id' => 'other-team-drafts', 'team-id' => 'not-our-team', 'is-default' => true],
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
			['id' => self::PROJECT, 'team-id' => self::TEAM, 'is-default' => false],
		]);
		$this->client->method('restoreDeletedFiles')->willReturn([self::PENPOT_ID]);

		$this->client->expects($this->once())->method('getProjectFiles')
			->with(self::DRAFTS)
			->willReturn([['id' => self::PENPOT_ID]]);

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
	 * §6.49's lie, all the way through: Penpot named our id twice and the design
	 * is still not listed. Two restores is where it stops — the second call is the
	 * documented remedy, not the start of a retry loop, and a third would just be
	 * hope.
	 *
	 * Nothing is thrown. The user's file is already back where they put it.
	 */
	public function testADesignStillUnlistedAfterASecondCallIsReportedAsAFailure(): void {
		$this->givenStamped();
		$this->givenInPenpotTrash();
		$this->givenResolvesToProject();
		$this->givenProjectHolds([]);

		$this->client->expects($this->exactly(2))->method('restoreDeletedFiles')
			->willReturn([self::PENPOT_ID]);

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

	/** The design is in the team's trash — which is what selects layer 2. */
	private function givenInPenpotTrash(): void {
		$this->client->method('deletedFiles')->willReturn([['id' => self::PENPOT_ID]]);
	}

	/** The mirror sits in a project folder, so membership names that project. */
	private function givenResolvesToProject(): void {
		$this->resolver->method('resolve')->willReturn(new Membership(self::PROJECT, self::TEAM));
	}

	/** @param list<string> $ids what `get-project-files` reports for that project */
	private function givenProjectHolds(array $ids): void {
		$this->client->method('getProjectFiles')->with(self::PROJECT)
			->willReturn(array_map(static fn (string $id): array => ['id' => $id], $ids));
	}

	private function file(): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(4242);
		$node->method('getName')->willReturn('Login.penpot');

		return $node;
	}
}
