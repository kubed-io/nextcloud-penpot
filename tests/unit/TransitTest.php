<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Transit;
use PHPUnit\Framework\TestCase;

/**
 * The Transit decoder — Penpot's wire format.
 *
 * THE FIXTURES IN THIS FILE ARE REAL RESPONSE BODIES, captured verbatim from a
 * live Penpot instance (saga Ch2, Course 1). That is deliberate and load-bearing:
 * the two bugs this decoder shipped with in its first draft were both **invisible
 * on hand-written examples** and both caught the moment a real multi-record
 * payload was decoded. A fixture written from the format documentation would have
 * passed against the broken decoder.
 *
 * Both bugs had the same shape — a dropped cache slot — and the same failure
 * mode: keys resolve to *real but wrong* field names, silently. `created-at`
 * reads back as `modified-at`. Nothing throws. That is the whole reason this
 * class exists before anything that calls it.
 */
final class TransitTest extends TestCase {
	private Transit $transit;

	protected function setUp(): void {
		parent::setUp();
		$this->transit = new Transit();
	}

	// ── scalars ─────────────────────────────────────────────────────────────

	public function testDecodesTaggedScalars(): void {
		$out = $this->transit->decode(
			'["^ ","~:name","My firsty","~:id","~u61d8ecb9-c430-8120-8008-6225c5b12134",'
			. '"~:created-at","~m1785020723908","~:revn",5,"~:is-shared",false]',
		);

		self::assertSame([
			'name' => 'My firsty',
			'id' => '61d8ecb9-c430-8120-8008-6225c5b12134',
			'created-at' => '1785020723908',
			'revn' => 5,
			'is-shared' => false,
		], $out);
	}

	public function testDecodesAnEscapedTilde(): void {
		self::assertSame(
			['name' => '~literal'],
			$this->transit->decode('["^ ","~:name","~~literal"]'),
		);
	}

	public function testPassesPlainStringsThrough(): void {
		self::assertSame(
			['name' => 'plugins/runtime'],
			$this->transit->decode('["^ ","~:name","plugins/runtime"]'),
		);
	}

	// ── the write cache: bug #1 ─────────────────────────────────────────────

	/**
	 * REGRESSION — the first draft cached every `~`-tagged token over 3 chars,
	 * including instants (`~m…`) and UUIDs (`~u…`). Only keywords (`~:`) and tags
	 * (`~#`) are cached, so each instant or UUID drifted the index by one.
	 *
	 * Here `~m…` and `~u…` sit BETWEEN cached keys. If they were cached, `^2`
	 * would resolve to the instant rather than to `id`.
	 */
	public function testInstantsAndUuidsDoNotConsumeCacheSlots(): void {
		$out = $this->transit->decode(
			'[["^ ","~:name","A","~:modified-at","~m1783903949043","~:id","~u4eda2e11-843e-8045-8008-51819d3bce9d"],'
			. '["^ ","^0","B","^1","~m1783904127847","^2","~u4eda2e11-843e-8045-8008-51824bda07a1"]]',
		);

		self::assertSame('A', $out[0]['name']);
		self::assertSame(
			['name' => 'B', 'modified-at' => '1783904127847', 'id' => '4eda2e11-843e-8045-8008-51824bda07a1'],
			$out[1],
			'A back-reference must resolve to the key the encoder cached, not to a shifted one.',
		);
	}

	/**
	 * A cached VALUE consumes a slot exactly as a key does. `~:membership` is a
	 * value in Penpot's `permissions.type`, and it is what `^4` points at in a
	 * real `get-teams` response.
	 */
	public function testCachedValuesConsumeSlotsToo(): void {
		$out = $this->transit->decode(
			'[["^ ","~:type","~:membership","~:name","A"],'
			. '["^ ","^0","^1","^2","B"]]',
		);

		self::assertSame(['type' => 'membership', 'name' => 'B'], $out[1]);
	}

	// ── the write cache: bug #2 ─────────────────────────────────────────────

	/**
	 * REGRESSION — a composite TAG (`~#set`) is cached, and the first draft
	 * recursed straight to the payload without caching it. In a real `get-teams`
	 * response the `~#set` sits at index 1, so every back-reference from index 1
	 * onward resolved one slot early.
	 */
	public function testACompositeTagConsumesACacheSlot(): void {
		$out = $this->transit->decode(
			'[["^ ","~:features",["~#set",["a","b"]],"~:name","A"],'
			. '["^ ","^0",["^1",["c"]],"^2","B"]]',
		);

		self::assertSame(['a', 'b'], $out[0]['features']);
		self::assertSame(
			'B',
			$out[1]['name'],
			'"^2" must be "name"; if the ~#set tag were not cached it would resolve to "features".',
		);
	}

	/**
	 * A repeated composite arrives as `["^1", [...]]` — a back-reference where the
	 * literal tag was. It must still unwrap to its payload, not stay a 2-element
	 * list.
	 */
	public function testACompositeTagIsUnwrappedWhenItArrivesAsAReference(): void {
		$out = $this->transit->decode(
			'[["^ ","~:features",["~#set",["a"]],"~:name","A"],'
			. '["^ ","^0",["^1",["c","d"]],"^2","B"]]',
		);

		self::assertSame(['c', 'd'], $out[1]['features']);
	}

	// ── the real captured payloads ──────────────────────────────────────────

	/**
	 * A verbatim `get-teams` body. Exercises both bugs at once: the `~#set` at
	 * index 1, the cached `~:membership` value at index 4, and instants/UUIDs
	 * interleaved throughout. This is the payload that caught both.
	 */
	public function testDecodesARealGetTeamsResponse(): void {
		$body = '[["^ ","~:features",["~#set",["fdata/path-data","plugins/runtime"]],'
			. '"~:permissions",["^ ","~:type","~:membership","~:is-owner",true,"~:is-admin",true,"~:can-edit",true],'
			. '"~:name","Default","~:modified-at","~m1783903949043",'
			. '"~:id","~u4eda2e11-843e-8045-8008-51819d3bce9d","~:created-at","~m1783903949043","~:is-default",true],'
			. '["^ ","^0",["^1",["plugins/runtime"]],"^2",["^ ","^3","^4","^5",true,"^6",true,"^7",true],'
			. '"^8","Ferronescotia","^9","~m1783904127847","^:","~u4eda2e11-843e-8045-8008-51824bda07a1",'
			. '"^;","~m1783904127847","^<",false]]';

		$out = $this->transit->decode($body);

		self::assertCount(2, $out);

		self::assertSame('Default', $out[0]['name']);
		self::assertSame('4eda2e11-843e-8045-8008-51819d3bce9d', $out[0]['id']);
		self::assertSame(['fdata/path-data', 'plugins/runtime'], $out[0]['features']);
		self::assertSame('membership', $out[0]['permissions']['type']);
		self::assertTrue($out[0]['is-default']);

		// Every field of the second record comes from the cache.
		self::assertSame([
			'features' => ['plugins/runtime'],
			'permissions' => [
				'type' => 'membership',
				'is-owner' => true,
				'is-admin' => true,
				'can-edit' => true,
			],
			'name' => 'Ferronescotia',
			'modified-at' => '1783904127847',
			'id' => '4eda2e11-843e-8045-8008-51824bda07a1',
			'created-at' => '1783904127847',
			'is-default' => false,
		], $out[1]);
	}

	/**
	 * A verbatim `get-all-projects` body — three records, so records 1 and 2 are
	 * almost entirely back-references. This is the listing the pull walks.
	 */
	public function testDecodesARealGetAllProjectsResponse(): void {
		$body = '[["^ ","~:id","~u4eda2e11-843e-8045-8008-51819d3f622b","~:team-id","~u4eda2e11-843e-8045-8008-51819d3bce9d",'
			. '"~:created-at","~m1783903949053","~:modified-at","~m1785084303270","~:is-default",true,'
			. '"~:name","Drafts","~:team-name","Default","~:is-default-team",true],'
			. '["^ ","^0","~u61d8ecb9-c430-8120-8008-622627f23540","^1","~u4eda2e11-843e-8045-8008-51824bda07a1",'
			. '"^2","~m1785020824515","^3","~m1785086989746","^4",false,"^5","My Stuff","^6","Ferronescotia","^7",false]]';

		$out = $this->transit->decode($body);

		self::assertSame('Drafts', $out[0]['name']);
		self::assertTrue($out[0]['is-default']);

		self::assertSame('My Stuff', $out[1]['name']);
		self::assertSame('61d8ecb9-c430-8120-8008-622627f23540', $out[1]['id']);
		self::assertSame('4eda2e11-843e-8045-8008-51824bda07a1', $out[1]['team-id']);
		self::assertSame('Ferronescotia', $out[1]['team-name']);
		self::assertFalse($out[1]['is-default']);
	}

	/**
	 * A verbatim `get-project-files` body — the listing that drives reconciliation.
	 * `revn` is the drift signal (saga §5.5), so it must survive as an int.
	 */
	public function testDecodesARealGetProjectFilesResponse(): void {
		$body = '[["^ ","~:team-id","~u4eda2e11-843e-8045-8008-51824bda07a1","~:name","My firsty","~:revn",5,'
			. '"~:modified-at","~m1785035702554","~:vern",0,"~:id","~u61d8ecb9-c430-8120-8008-6225c5b12134",'
			. '"~:is-shared",false,"~:project-id","~u61d8ecb9-c430-8120-8008-622627f23540",'
			. '"~:created-at","~m1785020723908"]]';

		$out = $this->transit->decode($body);

		self::assertSame('My firsty', $out[0]['name']);
		self::assertSame(5, $out[0]['revn']);
		self::assertSame('61d8ecb9-c430-8120-8008-6225c5b12134', $out[0]['id']);
		self::assertSame('61d8ecb9-c430-8120-8008-622627f23540', $out[0]['project-id']);
		self::assertFalse($out[0]['is-shared']);
	}

	/**
	 * A verbatim `rename-file` 200 body (saga §6.54) — the first write path.
	 */
	public function testDecodesARealRenameFileResponse(): void {
		$out = $this->transit->decode(
			'["^ ","~:id","~u61d8ecb9-c430-8120-8008-6225c5b12134","~:name","Renamed By Probe",'
			. '"~:created-at","~m1785020723908","~:modified-at","~m1785096673763"]',
		);

		self::assertSame('Renamed By Probe', $out['name']);
		self::assertSame('61d8ecb9-c430-8120-8008-6225c5b12134', $out['id']);
	}

	// ── failure behaviour ───────────────────────────────────────────────────

	public function testThrowsOnABodyThatIsNotJson(): void {
		$this->expectException(PenpotApiException::class);
		$this->transit->decode('<html>502 Bad Gateway</html>');
	}

	/**
	 * A reference past the end of the cache means our tracking diverged from the
	 * encoder's — so every later key would be wrong too. Failing loudly is the
	 * point: this exact throw is what surfaced both bugs above.
	 */
	public function testThrowsOnACacheReferenceThatPointsAtNothing(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/mis-tracked the write cache/');

		$this->transit->decode('[["^ ","~:name","A"],["^ ","^9","B"]]');
	}

	public function testAnEmptyListDecodesToAnEmptyArray(): void {
		self::assertSame([], $this->transit->decode('[]'));
	}

	/**
	 * REGRESSION — Penpot content-negotiates, and `Accept: application/json`
	 * switches it to plain camelCase JSON. That body is still valid JSON, so
	 * without this guard the decoder walks it happily and produces garbage twice
	 * over: a plain object has no `"^ "` marker so it is treated as a LIST
	 * (numeric keys `0..n`), and its keys are `teamName` where every caller here
	 * reads `team-name`.
	 *
	 * One tidy-looking request header, every field silently null. Caught live —
	 * the client was sending that header, and the probe only passed because it
	 * used raw curl without it.
	 */
	public function testRefusesPlainJsonWithAnActionableMessage(): void {
		// A verbatim `get-all-projects` record as returned WITH the Accept header.
		$body = '[{"id":"4eda2e11-843e-8045-8008-51819d3f622b","teamId":"4eda2e11-843e-8045-8008-51819d3bce9d",'
			. '"isDefault":true,"name":"Drafts","teamName":"Default"}]';

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/Accept: application\/json/');

		$this->transit->decode($body);
	}

	public function testRefusesATopLevelPlainJsonObject(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/plain JSON, not Transit/');

		$this->transit->decode('{"id":"abc","teamName":"Default"}');
	}

	/**
	 * The guard must not fire on real Transit — every fixture above already
	 * proves that, but this pins the boundary explicitly: a Transit map is a
	 * LIST beginning with the `"^ "` marker, never a JSON object.
	 */
	public function testTheGuardDoesNotFireOnRealTransit(): void {
		$out = $this->transit->decode('["^ ","~:name","My firsty"]');

		self::assertSame(['name' => 'My firsty'], $out);
	}
}
