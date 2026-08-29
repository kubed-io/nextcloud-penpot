<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\MirrorTimes;
use OCP\Files\Cache\ICache;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MirrorTimes} — giving a mirror the timestamps of the thing it
 * mirrors, without giving back the churn the drift check exists to avoid.
 *
 * Two things here are Penpot-specific and would pass silently if got wrong:
 *
 *  - **the format.** Penpot sends epoch MILLISECONDS as a string; the n8n and Grafana
 *    siblings send ISO-8601. A ported `strtotime()` parser returns false on Penpot's
 *    values, which becomes null, which means "leave the clock alone" — so the feature
 *    would set nothing at all and look like it worked.
 *  - **the asymmetry.** A folder gets its creation time and NOT its mtime, because
 *    core propagates a folder's mtime from its children and would overwrite ours on
 *    every pull that writes any design.
 */
#[CoversClass(MirrorTimes::class)]
final class MirrorTimesTest extends TestCase {
	private MirrorTimes $times;

	protected function setUp(): void {
		$this->times = new MirrorTimes(new NullLogger());
	}

	// ── parse: Penpot's format, not the siblings' ──────────────────────────────

	public function testParseReadsEpochMillisecondsAsAString(): void {
		// The shape Penpot actually sends, taken from a live record.
		self::assertSame(1785020723, MirrorTimes::parse('1785020723908'));
	}

	public function testParseAlsoAcceptsAnInteger(): void {
		// A transport that decodes it as a number is not wrong, and should not
		// silently disable the feature.
		self::assertSame(1785020723, MirrorTimes::parse(1785020723908));
	}

	public function testParseRejectsTheSiblingsIsoFormat(): void {
		// THE REGRESSION GUARD. If someone "simplifies" this into the shared
		// strtotime() parser the siblings use, this test keeps passing — but
		// testParseReadsEpochMillisecondsAsAString starts failing, which is the pair
		// that makes the swap impossible to land quietly.
		self::assertNull(MirrorTimes::parse('2026-07-24T16:25:42Z'));
	}

	public function testParseReturnsNullForAnythingUnusable(): void {
		// Null means "leave the clock alone", never "stamp the epoch" — so a schema
		// change on Penpot's side degrades to the old behaviour instead of dating
		// every mirror 1970.
		self::assertNull(MirrorTimes::parse(null));
		self::assertNull(MirrorTimes::parse(''));
		self::assertNull(MirrorTimes::parse('   '));
		self::assertNull(MirrorTimes::parse('not a number'));
		self::assertNull(MirrorTimes::parse(0));
		self::assertNull(MirrorTimes::parse(['1785020723908']));
	}

	// ── mtime ──────────────────────────────────────────────────────────────────

	public function testStampsTheModificationTimeWhenItDiffers(): void {
		$node = $this->createMock(Node::class);
		$node->method('getMTime')->willReturn(1000);
		$node->expects(self::once())->method('touch')->with(2000);

		$this->times->apply($node, 2000, null);
	}

	public function testLeavesTheModificationTimeAloneWhenItAlreadyMatches(): void {
		// THE ANTI-CHURN TEST: touch() propagates a fresh etag to the parent folder,
		// which is what sync clients poll — so a settled mirror must not be touched.
		$node = $this->createMock(Node::class);
		$node->method('getMTime')->willReturn(2000);
		$node->expects(self::never())->method('touch');

		$this->times->apply($node, 2000, null);
	}

	public function testForceStampsEvenWhenTheClockLooksRight(): void {
		// The caller just wrote bytes, so the node's mtime is `now` whatever its
		// cached info says — comparing would be reading a value we just invalidated.
		$node = $this->createMock(Node::class);
		$node->method('getMTime')->willReturn(2000);
		$node->expects(self::once())->method('touch')->with(2000);

		$this->times->apply($node, 2000, null, true);
	}

	public function testANullModificationTimeTouchesNothingEvenForced(): void {
		$node = $this->createMock(Node::class);
		$node->expects(self::never())->method('touch');

		$this->times->apply($node, null, null, true);
	}

	// ── creation time ──────────────────────────────────────────────────────────

	public function testStampsTheCreationTimeThroughTheCacheWhenItDiffers(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('update')->with(42, ['creation_time' => 900]);

		$this->times->apply($this->nodeWithCache($cache, creationTime: 100), null, 900);
	}

	public function testLeavesTheCreationTimeAloneWhenItAlreadyMatches(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::never())->method('update');

		$this->times->apply($this->nodeWithCache($cache, creationTime: 900), null, 900);
	}

	public function testForceDoesNotApplyToTheCreationTime(): void {
		// Unlike mtime, writing a body does not disturb a creation time, so the
		// comparison is always meaningful and $force has nothing to override.
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::never())->method('update');

		$this->times->apply($this->nodeWithCache($cache, creationTime: 900), null, 900, true);
	}

	/**
	 * A project folder is stamped with a creation time and NO mtime — the shape
	 * {@see \OCA\PenpotSync\Service\PullService::ensureProjectFolder()} calls with.
	 * Core propagates a folder's mtime from its children, so stamping it would be a
	 * fight we lose on every pull that writes any design.
	 */
	public function testAFolderTakesItsCreationTimeAndKeepsNextcloudsMtime(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('update')->with(42, ['creation_time' => 900]);

		$folder = $this->nodeWithCache($cache, creationTime: 0);
		$folder->expects(self::never())->method('touch');

		$this->times->apply($folder, null, 900);
	}

	// ── failure is never fatal ─────────────────────────────────────────────────

	public function testAClockThatWillNotSetIsSwallowed(): void {
		// The bytes, the metadata and the tags are already committed by the time a
		// caller reaches this, so a storage that refuses must not turn a good pull
		// into a failed one. `once()` is the assertion: a touch that silently did
		// nothing could otherwise pass a test whose only claim was "nothing threw".
		$node = $this->createMock(Node::class);
		$node->method('getMTime')->willReturn(1000);
		$node->method('getName')->willReturn('design.penpot');
		$node->expects(self::once())
			->method('touch')
			->with(2000)
			->willThrowException(new \RuntimeException('read-only storage'));

		$this->times->apply($node, 2000, null);
	}

	public function testACreationTimeThatWillNotSetLeavesTheMtimeStanding(): void {
		// One failing clock must not roll back the one that already worked.
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('update')->willThrowException(new \RuntimeException('cache is read-only'));

		$node = $this->nodeWithCache($cache, creationTime: 100);
		$node->method('getMTime')->willReturn(1000);
		$node->method('getName')->willReturn('design.penpot');
		$node->expects(self::once())->method('touch')->with(2000);

		$this->times->apply($node, 2000, 900);
	}

	/** A Node whose storage hands back $cache, with a known creation time. */
	private function nodeWithCache(ICache $cache, int $creationTime): Node {
		$storage = $this->createStub(IStorage::class);
		$storage->method('getCache')->willReturn($cache);

		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$node->method('getCreationTime')->willReturn($creationTime);
		$node->method('getStorage')->willReturn($storage);
		return $node;
	}
}
