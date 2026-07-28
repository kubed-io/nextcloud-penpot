<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Command\SetMode;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ penpot_sync:set-mode` — the per-file promote/demote (saga §6.22).
 *
 * The asymmetry is the whole subject of this suite. Promotion adds bytes and can
 * be retried freely; demotion **deletes a local backup** that nobody upstream is
 * keeping a copy of. So the tests that matter most here are not the happy paths
 * — they are the ones that prove nothing is destroyed or mislabelled when
 * something goes wrong.
 */
final class SetModeTest extends TestCase {
	private const PATH = 'Penpot/Acme/Login.penpot';

	private PenpotMetadata $metadata;
	private ArchiveService $archives;
	private Folder $home;
	private File $file;

	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->archives = $this->createMock(ArchiveService::class);

		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(30);
		$this->file->method('getName')->willReturn('Login.penpot');

		$this->home = $this->createMock(Folder::class);
		$this->home->method('nodeExists')->willReturn(true);
		$this->home->method('get')->willReturn($this->file);
	}

	// ── promotion: additive, and safe to fail ───────────────────────────────

	public function testPromotingFetchesTheArchiveAndStampsSync(): void {
		$this->givenStamped(Mapping::MODE_LINK);
		$this->archives->expects($this->once())->method('storeArchive')->with($this->file, 'file-1')->willReturn(54171);
		$this->metadata->expects($this->once())
			->method('writeFile')
			->with(30, [PenpotMetadata::KEY_MODE => Mapping::MODE_SYNC]);

		$tester = $this->setMode([self::PATH, 'sync']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('54171 bytes', $tester->getDisplay());
		// Worth saying out loud every time: this is the operation a user is most
		// likely to think might push their local file into Penpot.
		self::assertStringContainsString('Nothing was written to Penpot', $tester->getDisplay());
	}

	/**
	 * A FAILED PROMOTION CHANGES NOTHING AT ALL. Stamping the mode first would
	 * leave a file claiming to be a backup while holding a pointer — and the
	 * pull's self-healing check would then re-export it later, converting a
	 * visible failure into a delayed surprise.
	 */
	public function testAFailedExportLeavesTheFileInLinkMode(): void {
		$this->givenStamped(Mapping::MODE_LINK);
		$this->archives->method('storeArchive')->willThrowException(new PenpotApiException('the export stream errored'));
		$this->metadata->expects($this->never())->method('writeFile');

		$tester = $this->setMode([self::PATH, 'sync']);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('still in "link" mode', $tester->getDisplay());
	}

	// ── demotion: the one lossy direction ───────────────────────────────────

	/**
	 * THE CONFIRMATION IS THE FEATURE. Answering no must leave both the bytes and
	 * the stamp exactly as they were.
	 */
	public function testDemotingWithoutConfirmationDeletesNothing(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->method('holdsArchive')->willReturn(true);
		$this->archives->expects($this->never())->method('storeLink');
		$this->metadata->expects($this->never())->method('writeFile');

		$tester = $this->setMode([self::PATH, 'link'], answers: ['no']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('Nothing was deleted', $tester->getDisplay());
	}

	/** The warning has to say what is being lost, not just ask. */
	public function testTheDemotionWarningNamesWhatIsAtStake(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->method('holdsArchive')->willReturn(true);

		$display = $this->setMode([self::PATH, 'link'], answers: ['no'])->getDisplay();

		self::assertStringContainsString('LOCAL backup', $display);
		self::assertStringContainsString('fresh export', $display);
	}

	public function testConfirmingADemotionReplacesTheArchiveWithAPointer(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->method('holdsArchive')->willReturn(true);
		$this->archives->expects($this->once())
			->method('storeLink')
			// The pointer keeps the design's PENPOT name — the mirror's name minus
			// the Nextcloud-side extension (§6.4).
			->with($this->file, 'file-1', 'Login', '5@t1', '', 'team-9');
		$this->metadata->expects($this->once())
			->method('writeFile')
			->with(30, [PenpotMetadata::KEY_MODE => Mapping::MODE_LINK]);

		$tester = $this->setMode([self::PATH, 'link'], answers: ['yes']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('Penpot was never contacted', $tester->getDisplay());
	}

	/** `--force` is the deliberate way to skip the question in a script. */
	public function testForceSkipsTheConfirmation(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->method('holdsArchive')->willReturn(true);
		$this->archives->expects($this->once())->method('storeLink');

		self::assertSame(0, $this->setMode([self::PATH, 'link', '--force' => true])->getStatusCode());
	}

	/**
	 * Nothing to lose, nothing to confirm: a file stamped `sync` that never
	 * actually got its archive is demoted without a prompt. Asking anyway would
	 * teach the habit of dismissing the warning.
	 */
	public function testDemotingAFileWithNoStoredArchiveDoesNotPrompt(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->method('holdsArchive')->willReturn(false);
		$this->archives->expects($this->once())->method('storeLink');

		$tester = $this->setMode([self::PATH, 'link']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringNotContainsString('LOCAL backup', $tester->getDisplay());
	}

	// ── refusals ────────────────────────────────────────────────────────────

	public function testAnUnknownModeIsRefused(): void {
		$tester = $this->setMode([self::PATH, 'archive']);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('"sync" or "link"', $tester->getDisplay());
	}

	/** A file that carries no `penpot_id` is not ours to change the mode of. */
	public function testAnUntrackedFileIsRefused(): void {
		$this->metadata->method('readFile')->willReturn(null);

		$tester = $this->setMode([self::PATH, 'sync']);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('not a mirrored Penpot design', $tester->getDisplay());
	}

	/**
	 * Asking for the mode a file is already in is a no-op — but a SPOKEN one.
	 * Exiting silently would read as a failure.
	 */
	public function testAskingForTheCurrentModeSaysSoAndDoesNothing(): void {
		$this->givenStamped(Mapping::MODE_SYNC);
		$this->archives->expects($this->never())->method('storeArchive');
		$this->archives->expects($this->never())->method('storeLink');

		$tester = $this->setMode([self::PATH, 'sync']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('Already in "sync" mode', $tester->getDisplay());
	}

	public function testAFolderIsRefusedBecauseModesArePerFile(): void {
		$this->home = $this->createMock(Folder::class);
		$this->home->method('nodeExists')->willReturn(true);
		$this->home->method('get')->willReturn($this->createMock(Folder::class));

		$tester = $this->setMode(['Penpot/Acme', 'sync']);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('Modes are per-file', $tester->getDisplay());
	}

	private function givenStamped(string $mode): void {
		$this->metadata->method('readFile')->willReturn(new PenpotFileMetadata('file-1', '5@t1', $mode));
	}

	/**
	 * @param array<array-key, string|bool> $input
	 * @param list<string> $answers responses fed to the confirmation prompt
	 */
	private function setMode(array $input, array $answers = []): CommandTester {
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->home);

		$storage = $this->createStub(StorageService::class);
		$storage->method('resolveActorUid')->willReturn('penpot');

		$resolver = $this->createStub(MembershipResolver::class);
		$resolver->method('resolve')->willReturn(new Membership('proj-1', 'team-9'));

		$command = new SetMode($rootFolder, $storage, $resolver, $this->metadata, $this->archives, new SyncGuard());
		// The question helper only exists on a command attached to an Application,
		// and the demotion path asks for it by type — so this is not scaffolding,
		// it is the environment the confirmation actually runs in.
		(new Application())->add($command);

		$tester = new CommandTester($command);
		if ($answers !== []) {
			$tester->setInputs($answers);
		}

		$named = [];
		foreach ($input as $key => $value) {
			$named[is_int($key) ? ($key === 0 ? 'path' : 'mode') : $key] = $value;
		}
		$tester->execute($named);

		return $tester;
	}
}
