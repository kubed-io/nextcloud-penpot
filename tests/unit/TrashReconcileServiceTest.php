<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\TrashControl;
use OCA\PenpotSync\Service\TrashedFile;
use OCA\PenpotSync\Service\TrashedFolder;
use OCA\PenpotSync\Service\TrashReconcileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The reap: a design destroyed in Penpot takes its trashed mirror with it.
 *
 * ## ONE PURGE CASE AND A DOZEN WAYS TO BE SPARED ONE, AND THAT RATIO IS THE POINT
 *
 * This is the only place in the app that destroys a file nobody asked it to touch,
 * and the file it destroys is by definition the LAST COPY of a design that no
 * longer exists anywhere else. So the interesting tests are not "does it purge" —
 * that is one line — but every way the answer has to be "leave it alone":
 *
 *   - the design is live, or still in Penpot's trash (the two ordinary states)
 *   - Penpot cannot say (unreachable, a 500, a param spelling this app got wrong)
 *   - the design exists but is somewhere this app does not mirror
 *   - the trash listing itself could not be read
 *   - Penpot changed its answer between two looks, which is what a restore
 *     mid-transaction looks like from here
 *   - the entry is not ours: no metadata, another team's, another mode
 *
 * A wrong "spare" costs a stale trash entry the next pull looks at again. A wrong
 * "purge" is unrecoverable. The tests are weighted the way the consequences are.
 */
final class TrashReconcileServiceTest extends TestCase {
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const OTHER_TEAM = 'df59d46b-a997-80d9-8008-6452575b0a69';
	private const PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const ACTOR = 'dana';
	private const FILE_ID = 4242;
	private const OTHER_FILE_ID = 4243;
	private const OTHER_PENPOT_ID = '61d8ecb9-c430-8120-8008-6225c5b12135';
	private const FOLDER_ID = 70;
	private const PROJECT = 'df59d46b-a997-80d9-8008-6452575b0a70';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private TrashControl $trash;
	private StorageService $storage;

	/** Set by {@see trashHolding()} so a test can assert the entry was destroyed. */
	private int $purges = 0;

	/** The folder twin of {@see $purges}, set by {@see trashedProject()}. */
	private int $folderPurges = 0;

	/** How many times a trashed folder's subtree walk was actually run. */
	private int $folderWalks = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->trash = $this->createMock(TrashControl::class);
		$this->storage = $this->createMock(StorageService::class);
		$this->storage->method('resolveActorUid')->willReturn(self::ACTOR);
		$this->purges = 0;
		$this->folderPurges = 0;
		$this->folderWalks = 0;
	}

	// ── the one case that destroys something ────────────────────────────────

	/**
	 * Not live, not in Penpot's trash, and Penpot says the id names nothing. That is
	 * the only combination that reaps, and all three have to hold.
	 */
	public function testAMirrorWhoseDesignIsGoneIsPurged(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->with(self::PENPOT_ID)->willReturn(false);

		self::assertSame(1, $this->reap());
		self::assertSame(1, $this->purges);
	}

	// ── the two ordinary states, answered without asking Penpot ─────────────

	/**
	 * SOMEONE RESTORED IT IN PENPOT while its mirror sat in the trash. The pull just
	 * listed it, so `$seen` proves it exists and no call is needed at all.
	 */
	public function testALiveDesignSparesItsTrashedMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);

		$this->client->expects($this->never())->method('fileExists');

		self::assertSame(0, $this->reap([self::PENPOT_ID => true]));
		self::assertSame(0, $this->purges);
	}

	/**
	 * STILL IN PENPOT'S TRASH — the ordinary state of a mirror that was trashed and
	 * not touched since. This is the state the whole feature must not confuse with
	 * "destroyed", and the trash listing answers it for free.
	 */
	public function testADesignStillInPenpotsTrashSparesItsMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([self::PENPOT_ID => true]);

		$this->client->expects($this->never())->method('fileExists');

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	// ── every way Penpot can fail to say "gone" ─────────────────────────────

	/**
	 * MOVED TO A TEAM THIS APP DOES NOT MIRROR. Absent from both listings and alive:
	 * the exact case `$seen` cannot answer and the probe exists for.
	 */
	public function testADesignThatExistsElsewhereSparesItsMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturn(true);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * "I DO NOT KNOW" IS NOT "GONE", and this is the assertion the whole class hangs
	 * on. {@see PenpotClient::fileExists()} answers null for an unreachable Penpot, a
	 * 500, or a param spelling this app got wrong — every one of which would
	 * otherwise read as a definite not-found and destroy somebody's last copy.
	 */
	public function testAProbeThatCannotTellSparesTheMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturn(null);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * A TRASH LISTING WE COULD NOT READ SPARES EVERY MIRROR, and spares them before
	 * the probe runs. Without that listing "not in Penpot's trash" is unprovable, and
	 * a probe alone would read a perfectly recoverable design as gone — Penpot's
	 * `get-file-summary` answers NOT-FOUND for a trashed design.
	 */
	public function testAnUnreadableTrashListingSparesEveryMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willThrowException(new PenpotApiException('Penpot is unreachable'));

		$this->client->expects($this->never())->method('fileExists');

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * THE WINDOW WHERE ALL THREE CHECKS ARE WRONG TOGETHER (§6.49, §C6.15).
	 *
	 * Penpot's restore returns before its transaction settles, and inside that window
	 * a design being restored is absent from the project listing, absent from the
	 * trash listing, and NOT-FOUND to the probe — every source agrees, and every one
	 * is wrong. The pull that carries this pass runs immediately after a restore, so
	 * this is a window the app walks into on purpose.
	 *
	 * A service that asks once cannot tell that from a design that is really gone,
	 * and it destroys the last copy of something somebody just asked to have back.
	 * This fails against exactly that service: the first look says gone, and only the
	 * re-read sees the restore land.
	 */
	public function testADesignThatComesBackBetweenTheTwoLooksIsSpared(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		// Absent on the first look — the settling restore — and named again on the
		// second, because by then the transaction has landed.
		$this->client->method('recoverableFileIds')->willReturnOnConsecutiveCalls(
			[],
			[self::PENPOT_ID => true],
		);
		$this->client->method('fileExists')->willReturn(false);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/** The same window, seen from the probe rather than the listing. */
	public function testAProbeThatChangesItsMindBetweenTheTwoLooksSparesTheMirror(): void {
		$this->trashHolding($this->mirror());
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturnOnConsecutiveCalls(false, true);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	// ── the entries that are not ours to judge ──────────────────────────────

	/** A file that is not a design never costs a metadata read, let alone a purge. */
	public function testANonDesignTrashEntryIsNotEvenLookedUp(): void {
		$this->trashHolding(new TrashedFile(99, 'Notes.txt', function (): void {
			$this->purges++;
		}));

		$this->metadata->expects($this->never())->method('readFile');

		self::assertSame(0, $this->reap());
	}

	/** A `.penpot` the app never mirrored carries no stamp, so nothing names it. */
	public function testAnUntrackedDesignFileIsSpared(): void {
		$this->trashHolding($this->mirror());
		$this->metadata->method('readFile')->willReturn(null);

		$this->client->expects($this->never())->method('recoverableFileIds');

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * ANOTHER MAPPING'S MIRROR IS ANOTHER MAPPING'S BUSINESS. The actor's trash is
	 * one bin for every mapping, so each pull has to filter it — and judging a design
	 * against the wrong team's trash listing would read it as gone every time.
	 */
	public function testAMirrorFromAnotherTeamIsSpared(): void {
		$this->trashHolding($this->mirror());
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, self::OTHER_TEAM),
		);

		$this->client->expects($this->never())->method('recoverableFileIds');

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * A MIRROR STAMPED BEFORE `penpot_team_id` EXISTED (§C6.7) has no team, so it
	 * cannot be attributed to a mapping at all. Unattributed means unreaped, which is
	 * the safe direction — an empty team must never match a mapping's.
	 */
	public function testAMirrorWithNoTeamOnItIsSpared(): void {
		$this->trashHolding($this->mirror());
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, ''),
		);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/**
	 * AN `unmapped` FILE LEFT ITS MAPPING and its design is not this app's business
	 * any more — the same thing `purge.feature` says about the user-driven purge.
	 */
	public function testAnUnmappedFileIsSpared(): void {
		$this->trashHolding($this->mirror());
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata(self::PENPOT_ID, '5@t1', PenpotMetadata::MODE_UNMAPPED, self::TEAM),
		);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->purges);
	}

	/** A link mapping has no trashed mirrors by construction, so it reads no trash. */
	public function testALinkMappingNeverReadsTheTrash(): void {
		$this->trash->expects($this->never())->method('listTrashed');

		self::assertSame(0, $this->reap([], $this->mapping(Mapping::MODE_LINK)));
	}

	/**
	 * NO SYNC ACTOR IS NOT A PULL FAILURE. `resolveActorUid()` throws on an instance
	 * whose built-in admin group has no members; the reconcile is a pass inside the
	 * pull rather than the point of it, so it reports nothing and gets out of the way.
	 */
	public function testNoSyncActorReapsNothingRatherThanThrowing(): void {
		$storage = $this->createMock(StorageService::class);
		$storage->method('resolveActorUid')->willThrowException(new \RuntimeException('no admin'));
		$this->storage = $storage;

		$this->trash->expects($this->never())->method('listTrashed');

		self::assertSame(0, $this->reap());
	}

	/**
	 * A PURGE THAT FAILS IS ONE ENTRY, NOT THE PASS. A member without delete
	 * permission on a Team Folder, or a backend that refused: the entry stays — which
	 * is the recoverable direction — and the mirror beside it is still judged.
	 */
	public function testAFailedPurgeDoesNotStopTheOnesAfterIt(): void {
		$second = '86f123cb-0682-808c-8008-68a7a2a13c4e';
		$this->trashHolding(
			new TrashedFile(self::FILE_ID, 'Doomed.penpot', static function (): void {
				throw new \RuntimeException('the Team Folder backend refused');
			}),
			new TrashedFile(77, 'Also Doomed.penpot', function (): void {
				$this->purges++;
			}),
		);
		$this->metadata->method('readFile')->willReturnCallback(
			static fn (int $id): PenpotFileMetadata => new PenpotFileMetadata(
				$id === self::FILE_ID ? self::PENPOT_ID : $second,
				'5@t1',
				Mapping::MODE_SYNC,
				self::TEAM,
			),
		);
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturn(false);

		self::assertSame(1, $this->reap());
		self::assertSame(1, $this->purges);
	}

	// ── the trashed PROJECT FOLDER (`projects/purge.feature`) ───────────────

	/**
	 * A trashed project whose designs Penpot destroyed has nothing left to be
	 * restored to, so the folder goes too.
	 *
	 * The folder is the unit because the trash offers no smaller one: its designs
	 * are nested inside that single item, so the file pass above never sees them.
	 */
	public function testATrashedProjectWhoseDesignsAreGoneIsPurged(): void {
		$this->foldersInTrash($this->trashedProject([self::FILE_ID], holdsOtherFiles: false));
		$this->givenProjectFolder();
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->with(self::PENPOT_ID)->willReturn(false);

		self::assertSame(1, $this->reap());
		self::assertSame(1, $this->folderPurges);
	}

	/**
	 * ONE SPREADSHEET SPARES THE WHOLE FOLDER, and that is the point of the scenario
	 * beside this one. A file with no far side may not be destroyed by something
	 * that happened in Penpot — and since a trash item cannot be partly purged,
	 * sparing the file means sparing the folder.
	 *
	 * Identical to the test above but for the one flag, which is what makes it a
	 * claim about the spreadsheet rather than about anything else.
	 */
	public function testATrashedProjectHoldingAnyOtherFileIsSpared(): void {
		$this->foldersInTrash($this->trashedProject([self::FILE_ID], holdsOtherFiles: true));
		$this->givenProjectFolder();
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturn(false);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->folderPurges);
	}

	/**
	 * EVERY DESIGN, NOT ANY. One still recoverable in Penpot is one reason the
	 * folder is still worth something: restoring it would bring the project back,
	 * which is exactly what `projects/restore` asserts happens.
	 */
	public function testATrashedProjectWithOneRecoverableDesignIsSpared(): void {
		$this->foldersInTrash($this->trashedProject([self::FILE_ID, self::OTHER_FILE_ID], holdsOtherFiles: false));
		$this->givenProjectFolder();
		$this->metadata->method('readFile')->willReturnCallback(
			static fn (int $id): PenpotFileMetadata => new PenpotFileMetadata(
				$id === self::FILE_ID ? self::PENPOT_ID : self::OTHER_PENPOT_ID,
				'5@t1',
				Mapping::MODE_SYNC,
				self::TEAM,
			),
		);
		// The second one is still in Penpot's trash, so it can still come back.
		$this->client->method('recoverableFileIds')->willReturn([self::OTHER_PENPOT_ID => true]);
		$this->client->method('fileExists')->willReturn(false);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->folderPurges);
	}

	/**
	 * A folder this app never marked is somebody's own, whatever is inside it.
	 *
	 * `penpot_project_id` is the only thing that ever made a folder a project
	 * (§C6.38), so its absence is the whole answer — and a trashed folder has no
	 * path left to resolve any other way.
	 */
	public function testATrashedFolderThatWasNeverAProjectIsSpared(): void {
		$this->foldersInTrash($this->trashedProject([self::FILE_ID], holdsOtherFiles: false));
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers('', ''));
		$this->givenSyncMirror();
		$this->client->method('recoverableFileIds')->willReturn([]);
		$this->client->method('fileExists')->willReturn(false);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->folderPurges);
		// AND IT WAS NEVER OPENED. Reading `penpot_project_id` off the folder is a
		// metadata lookup; walking its subtree is a recursive trip through a trash
		// backend. The cheap question has to be asked first, because the pull asks
		// this same listing every five minutes and wants nothing else from it.
		self::assertSame(0, $this->folderWalks, 'a folder with no project marker must not be walked');
	}

	/** An empty project folder proves nothing about Penpot, so it is left alone. */
	public function testATrashedProjectHoldingNoDesignsIsSpared(): void {
		$this->foldersInTrash($this->trashedProject([], holdsOtherFiles: false));
		$this->givenProjectFolder();

		$this->client->expects($this->never())->method('recoverableFileIds');

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->folderPurges);
	}

	/** A trashed project of ANOTHER team is not this mapping's to judge. */
	public function testATrashedProjectFromAnotherTeamIsSpared(): void {
		$this->foldersInTrash($this->trashedProject([self::FILE_ID], holdsOtherFiles: false));
		$this->givenProjectFolder();
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, self::OTHER_TEAM),
		);

		self::assertSame(0, $this->reap());
		self::assertSame(0, $this->folderPurges);
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	/** @param array<string, bool> $seen */
	private function reap(array $seen = [], ?Mapping $mapping = null): int {
		$service = new TrashReconcileService(
			$this->client,
			$this->metadata,
			$this->trash,
			$this->storage,
			new NullLogger(),
			// No settle: Penpot is a mock here, so there is no transaction to outlast
			// and the wait would only make the suite slower. The second READ still
			// happens, which is the behaviour these tests pin.
			settleMicroseconds: 0,
		);

		return $service->reap($mapping ?? $this->mapping(), $seen);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC): Mapping {
		return Mapping::fromArray([
			'team_id' => self::TEAM,
			'team_name' => 'North Wind',
			'nc_folder' => 'Penpot',
			'use_team_folder' => false,
			'mode' => $mode,
		]);
	}

	private function trashHolding(TrashedFile ...$entries): void {
		$this->trash->method('listTrashed')->willReturn($entries);
	}

	/** One trashed mirror whose purge is counted rather than performed. */
	private function mirror(): TrashedFile {
		return new TrashedFile(self::FILE_ID, 'Erased Upstream.penpot', function (): void {
			$this->purges++;
		});
	}

	private function foldersInTrash(TrashedFolder ...$entries): void {
		$this->trash->method('listTrashedFolders')->willReturn($entries);
	}

	/** The folder carries a project id, which is what made it a project at all. */
	private function givenProjectFolder(): void {
		$this->metadata->method('readFolder')->willReturn(new FolderMarkers(self::PROJECT, ''));
	}

	/**
	 * One trashed project folder whose purge is counted rather than performed.
	 *
	 * The contents arrive as a closure and {@see $folderWalks} counts the calls, so a
	 * caller that walks a subtree it did not need is a test failure rather than a
	 * silent cost.
	 *
	 * @param list<int> $designIds
	 */
	private function trashedProject(array $designIds, bool $holdsOtherFiles): TrashedFolder {
		return new TrashedFolder(
			self::FOLDER_ID,
			'Emptied',
			function () use ($designIds, $holdsOtherFiles): array {
				$this->folderWalks++;

				return [$designIds, $holdsOtherFiles];
			},
			static function (): void {
				throw new \LogicException('the reap must never RESTORE a folder');
			},
			function (): void {
				$this->folderPurges++;
			},
		);
	}

	private function givenSyncMirror(): void {
		$this->metadata->method('readFile')->willReturn(
			new PenpotFileMetadata(self::PENPOT_ID, '5@t1', Mapping::MODE_SYNC, self::TEAM),
		);
	}
}
