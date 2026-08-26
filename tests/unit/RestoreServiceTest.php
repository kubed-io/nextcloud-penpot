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
use OCA\PenpotSync\Service\SyncNotifier;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Restore, in the three layers that decide what "restore" even means
 * (`delete-design.feature`, `restore-design.feature`).
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

		$this->restores = new RestoreService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->tokens(),
			$this->createMock(SyncNotifier::class),
			new NullLogger(),
			// No settle: Penpot is a mock here, so there is no in-flight delete to
			// wait on and the wait would only make the suite slower.
			settleMicroseconds: 0,
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
		// Not in the project yet, and there after the second call — the window
		// §6.49 describes, reproduced in miniature.
		// THREE values, because a confirmation is now two reads: absent on the first
		// call, then present on both reads of the second confirmation. A two-value
		// stub would hand the settle re-read an empty array — the default for the
		// return type — and this test would fail as though the fix were broken.
		$this->client->method('getProjectFiles')->willReturnOnConsecutiveCalls(
			[],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
		);

		// ONE configuration, expectation and return together: stubbing the same
		// method twice leaves which rule supplies the value up to PHPUnit's
		// matcher order, and a second rule with no `willReturn` hands back the
		// return type's default — an empty array here, which this service reads as
		// "restored nothing" and would make the test pass for the wrong reason.
		$this->client->expects($this->exactly(2))->method('restoreDeletedFiles')
			->willReturn([self::PENPOT_ID]);

		$this->restores->onRestored($this->file());
	}

	/**
	 * THE BUG THE INTEGRATION SUITE FOUND, in miniature.
	 *
	 * `delete-file` lands asynchronously, a beat after it answers. A restore issued
	 * inside that beat is confirmed against a listing the pending delete has not
	 * reached — and is then overwritten by it. So the design is listed, and a
	 * moment later it is not, and the next pull trashes the mirror all over again.
	 *
	 * A service that asks once cannot tell that from a real restore. This test
	 * fails against exactly that service: the first read says yes, and only the
	 * re-read after the settle catches the design going back into the trash.
	 */
	public function testARestoreUndoneByAnInFlightDeleteIsIssuedAgain(): void {
		$this->givenStamped();
		$this->givenResolvesToProject();
		$this->givenInPenpotTrash();
		// Listed, then gone — the in-flight delete landing between the two reads.
		// Then listed and still listed, because the second restore lands after it.
		$this->client->method('getProjectFiles')->willReturnOnConsecutiveCalls(
			[['id' => self::PENPOT_ID]],
			[],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
		);

		$this->client->expects($this->exactly(2))->method('restoreDeletedFiles')
			->willReturn([self::PENPOT_ID]);

		$this->restores->onRestored($this->file());
	}

	/**
	 * THE UNDO ARRIVES LATE, AND ASKING TWICE IS NOT ENOUGH.
	 *
	 * Measured against a live Penpot: `delete-file` answers at once, lists the
	 * design in the trash within ~0.3s, and then runs a delayed job about **3.8
	 * seconds later** that removes the file again — even though it was restored in
	 * between. It is a scheduled job, not a race: at delete→restore gaps of 0s, 1s
	 * and 2s the undo landed 5/5 times, always ~3.8s after the DELETE.
	 *
	 * The previous implementation read the listing, slept a fixed 2.5s, and read it
	 * once more. Both reads therefore happened BEFORE the undo, so it reported a
	 * lossless restore and returned — and the design vanished a second later. That
	 * is precisely what the integration suite kept catching, with the app's own log
	 * line claiming success in the same run.
	 *
	 * So this pins the capability the fix adds rather than the constant it uses: a
	 * disappearance that happens on a LATER look is still caught. The settle is
	 * injected short here — the point is the number of looks, not the wall clock.
	 */
	public function testAnUndoThatArrivesAfterTheFirstReReadIsStillCaught(): void {
		$restores = new RestoreService(
			$this->client,
			$this->metadata,
			$this->resolver,
			$this->tokens(),
			$this->createMock(SyncNotifier::class),
			new NullLogger(),
			// Long enough to poll several times at the 250ms interval, short enough
			// to sit in a unit suite.
			settleMicroseconds: 1_000_000,
		);

		$this->givenStamped();
		$this->givenResolvesToProject();
		$this->givenInPenpotTrash();

		// Listed, and still listed on the first two re-reads — which is all the old
		// implementation would ever have seen — then gone. The second restore lands
		// after the delayed delete has fired, which is why it holds (6/6 live).
		$this->client->method('getProjectFiles')->willReturnOnConsecutiveCalls(
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
			[['id' => self::PENPOT_ID]],
		);

		$this->client->expects($this->exactly(2))->method('restoreDeletedFiles')
			->willReturn([self::PENPOT_ID]);

		$restores->onRestored($this->file());
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

		// Twice, not once: the listing is read, then re-read after the settle.
		$this->client->expects($this->exactly(2))->method('getProjectFiles')
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

	/**
	 * AND A DRAFTS DESIGN IS NOT "GONE FOREVER". A mirror at the team root has no
	 * project folder above it, so membership resolves the project to null — which
	 * an earlier version read as "nowhere to look" and reported as the permanent
	 * loss below. §6.35: the team root IS a project, it just has no folder.
	 */
	public function testADraftsDesignThatStillExistsIsRecognisedAsIntact(): void {
		$this->givenStamped();
		$this->client->method('deletedFiles')->willReturn([]);
		$this->resolver->method('resolve')->willReturn(new Membership(null, self::TEAM));
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
		]);

		$this->client->expects($this->once())->method('getProjectFiles')
			->with(self::DRAFTS)
			->willReturn([['id' => self::PENPOT_ID]]);
		$this->client->expects($this->never())->method('restoreDeletedFiles');

		$this->restores->onRestored($this->file());
	}

	// ── layer 3: it is gone, and that is not built ──────────────────────────

	/**
	 * Past the grace window, or permanently deleted. Importing the archive is
	 * `restore-design.feature`'s slice and does not exist — so nothing is sent, and the
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

	/** The actor's own token, which these tests never exercise. */
	private function tokens(): PersonalTokenService {
		$tokens = $this->createStub(PersonalTokenService::class);
		$tokens->method('tokenForActor')->willReturn(null);

		return $tokens;
	}

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
