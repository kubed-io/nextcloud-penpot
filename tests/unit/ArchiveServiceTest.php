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
use OCP\IAppConfig;
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
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://penpot.example.com');

		$this->archives = new ArchiveService($this->client, $config, new NullLogger());
	}

	// ── telling an archive from a pointer ───────────────────────────────────

	public function testRecognisesARealArchiveByItsMagicBytes(): void {
		self::assertTrue($this->archives->holdsArchive($this->fileHolding(self::ZIP . 'and then the rest')));
	}

	/**
	 * A `link` body is JSON, so it fails the magic check — which is the point:
	 * this is how a file stamped `sync` but holding a pointer gets noticed and
	 * healed by the next pull.
	 */
	public function testAPointerBodyIsNotAnArchive(): void {
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

	/** The pointer body carries the ids and the instance base — and no design data. */
	public function testThePointerBodyCarriesTheIdsAndNoContent(): void {
		$written = null;
		$file = $this->createMock(File::class);
		$file->method('putContent')->willReturnCallback(static function (string $body) use (&$written): void {
			$written = $body;
		});

		$this->archives->storeLink($file, 'file-1', 'Login', '5', 't1', 'team-9');

		$payload = json_decode((string)$written, true);
		self::assertSame('reference/v1', $payload['penpot']);
		self::assertSame('file-1', $payload['id']);
		// The Penpot name, WITHOUT the Nextcloud-side `.penpot` extension (§6.4).
		self::assertSame('Login', $payload['name']);
		self::assertSame('team-9', $payload['team_id']);
		self::assertSame('https://penpot.example.com', $payload['instance_url']);
	}

	/** Writing a pointer never contacts Penpot — that is what makes demotion free. */
	public function testWritingAPointerNeverContactsPenpot(): void {
		$this->client->expects($this->never())->method('exportBinfile');

		$this->archives->storeLink($this->createMock(File::class), 'file-1', 'Login', '5', 't1', 'team-9');
	}

	/**
	 * THE SIGNAL MUST SURVIVE A ROUND TRIP, because a demotion writes a pointer
	 * from a stamp and the pointer keeps the two halves apart. The pull joins,
	 * the demotion splits, and when those two lived in different files they
	 * disagreed: `revn` got the whole `5@t1` and `modified_at` got nothing —
	 * a wrong pointer that nothing downstream would have failed on.
	 *
	 * @param array{0: string, 1: string} $parts
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('signals')]
	public function testTheRevisionSignalSurvivesARoundTrip(array $parts, string $joined): void {
		self::assertSame($joined, ArchiveService::signal($parts[0], $parts[1]));
		self::assertSame($parts, ArchiveService::splitSignal($joined));
	}

	/** @return array<string, array{0: array{0: string, 1: string}, 1: string}> */
	public static function signals(): array {
		return [
			'both halves' => [['5', 't1'], '5@t1'],
			// Penpot does not always give us a modified-at; a bare revn is the
			// signal then, and must not come back as a revn of "".
			'no modified-at' => [['5', ''], '5'],
			'never stamped' => [['', ''], ''],
			// Penpot's timestamps are opaque to us — splitting on the FIRST `@`
			// keeps a value containing one from eating the revn.
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
