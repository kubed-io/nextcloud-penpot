<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\PenpotClient;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `ArchiveService` — what is actually inside a mirrored `.penpot` file.
 *
 * The two facts worth pinning here are both about the boundary between the
 * stamped mode and the stored bytes, because that is where this app can lose
 * something. The mode says what a file is *meant* to be; the bytes say what it
 * is. They can disagree — an export that failed halfway through a promotion
 * leaves exactly that state — and every decision downstream depends on the code
 * being able to tell.
 */
final class ArchiveServiceTest extends TestCase {
	/** The first four bytes of any ZIP, and therefore of any `.penpot`. */
	private const ZIP = "PK\x03\x04";

	private PenpotClient $client;
	private ArchiveService $archives;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->archives = new ArchiveService($this->client, new NullLogger());
	}

	// ── telling an archive from a link ──────────────────────────────────────

	public function testRecognisesARealArchiveByItsMagicBytes(): void {
		self::assertTrue($this->archives->holdsArchive($this->fileHolding(self::ZIP . 'and then the rest')));
	}

	/**
	 * A `link` is empty, so it fails the magic check on SIZE before a byte is
	 * read — which is the point: this is how a file stamped `sync` but holding no
	 * archive gets noticed and healed by the next pull.
	 */
	public function testAnEmptyLinkIsNotAnArchive(): void {
		self::assertFalse($this->archives->holdsArchive($this->fileHolding('')));
	}

	/**
	 * A legacy JSON pointer body, written by a version before §C6.6, is not an
	 * archive either. Kept as its own case because such files exist in the wild
	 * until their next pull truncates them, and every guard that protects a real
	 * archive has to keep treating them as "no bytes".
	 */
	public function testALegacyPointerBodyIsNotAnArchive(): void {
		self::assertFalse($this->archives->holdsArchive($this->fileHolding('{"penpot":"reference/v1"}')));
	}

	/**
	 * A file too short to even contain the magic number is answered without
	 * opening it. Not just an optimisation — `fopen` on a zero-byte node in some
	 * storage backends is a round trip for a question the size already answered.
	 */
	public function testAnEmptyFileIsNotAnArchiveAndIsNotOpened(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(0);
		$file->expects($this->never())->method('fopen');

		self::assertFalse($this->archives->holdsArchive($file));
	}

	/**
	 * AN UNREADABLE NODE MUST NOT ABORT A PULL. Answering "no archive" costs at
	 * worst one redundant export; throwing would cost the whole team's
	 * reconciliation over a single bad file.
	 */
	public function testAnUnreadableFileIsReportedAsHoldingNoArchive(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(500);
		$file->method('fopen')->willThrowException(new \RuntimeException('storage is having a day'));

		self::assertFalse($this->archives->holdsArchive($file));
	}

	// ── storing ─────────────────────────────────────────────────────────────

	public function testStoringAnArchiveWritesExactlyWhatPenpotExported(): void {
		$archive = self::ZIP . str_repeat('x', 100);
		$this->client->method('exportBinfile')->with('file-1')->willReturn($archive);

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('putContent')->with($archive);

		self::assertSame(strlen($archive), $this->archives->storeArchive($file, 'file-1'));
	}

	/**
	 * THE FAILURE ORDERING (saga §6.18 rule 3). A failed export must leave the
	 * previous archive intact — so nothing may be written before the bytes are
	 * actually in hand. This is the one operation in the app that could otherwise
	 * replace a backup with nothing.
	 */
	public function testAFailedExportNeverTouchesTheExistingContent(): void {
		$this->client->method('exportBinfile')->willThrowException(new PenpotApiException('SSE said error'));

		$file = $this->createMock(File::class);
		$file->expects($this->never())->method('putContent');

		$this->expectException(PenpotApiException::class);

		$this->archives->storeArchive($file, 'file-1');
	}

	/**
	 * A `link` is emptied, not described. This is the assertion that stops anyone
	 * reintroducing a body: whatever the file held, it holds nothing afterwards.
	 */
	public function testStoringALinkEmptiesTheFile(): void {
		$written = null;
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(1_964_389);
		$file->method('putContent')->willReturnCallback(static function (string $body) use (&$written): void {
			$written = $body;
		});

		$this->archives->storeLink($file);

		self::assertSame('', $written);
	}

	/**
	 * A legacy JSON body is truncated on contact — which is the entire migration
	 * story for files written before §C6.6. No repair step, no version check: the
	 * next pull calls storeLink() on every `link` and the body is gone.
	 */
	public function testALegacyPointerBodyIsTruncated(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(220);
		$file->expects($this->once())->method('putContent')->with('');

		$this->archives->storeLink($file);
	}

	/**
	 * AN ALREADY-EMPTY LINK IS LEFT STRICTLY ALONE. Rewriting it would be a no-op
	 * in content and a very real one in metadata: putContent moves the mtime and
	 * etag, so every desktop client would re-download every `link` file after
	 * every pull. The pull calls this once per link per pass, so this guard is
	 * the difference between a quiet sync and a recurring storm.
	 */
	public function testAnAlreadyEmptyLinkIsNotRewritten(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(0);
		$file->expects($this->never())->method('putContent');

		$this->archives->storeLink($file);
	}

	/** Emptying a file never contacts Penpot — that is what makes demotion free. */
	public function testWritingALinkNeverContactsPenpot(): void {
		$this->client->expects($this->never())->method('exportBinfile');

		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(99);

		$this->archives->storeLink($file);
	}

	/**
	 * The signal is JOINED HERE AND NEVER TAKEN APART. It used to have a splitter,
	 * because the JSON pointer body kept the two halves in separate fields — and
	 * that pairing was a live drift hazard the class documented against itself.
	 * §C6.6 deleted the body and the splitter with it, so this now pins one
	 * direction only: callers compare the signal whole.
	 *
	 * @param array{0: string, 1: string} $parts
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('signals')]
	public function testTheRevisionSignalIsJoinedIntoOneOpaqueString(array $parts, string $joined): void {
		self::assertSame($joined, ArchiveService::signal($parts[0], $parts[1]));
	}

	/** @return array<string, array{0: array{0: string, 1: string}, 1: string}> */
	public static function signals(): array {
		return [
			'both halves' => [['5', 't1'], '5@t1'],
			// Penpot does not always give us a modified-at; a bare revn is the
			// signal then, and must not come back as a revn of "".
			'no modified-at' => [['5', ''], '5'],
			'never stamped' => [['', ''], ''],
			// Penpot's timestamps are opaque to us, and the signal is never
			// parsed, so an @ inside one is simply carried.
			'an at-sign in the timestamp' => [['5', 't1@x'], '5@t1@x'],
		];
	}

	/** A File whose stored content is $body, readable through fopen(). */
	private function fileHolding(string $body): File {
		$handle = fopen('php://memory', 'r+b');
		self::assertIsResource($handle);
		fwrite($handle, $body);
		rewind($handle);

		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(strlen($body));
		$file->method('fopen')->willReturn($handle);

		return $file;
	}
}
