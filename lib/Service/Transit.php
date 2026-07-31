<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\Exception\PenpotApiException;

/**
 * Decoder for Penpot's **Transit-JSON** wire format.
 *
 * Penpot's RPC bus does not speak plain JSON. It speaks Transit
 * (https://github.com/cognitect/transit-format), a JSON-based encoding that
 * carries richer types than JSON has — keywords, UUIDs, instants, sets — and,
 * critically for us, **compresses repeated map keys into back-references**.
 *
 * WHY THIS IS ITS OWN CLASS, AND WHY IT IS THE FIRST THING BUILT (saga Ch2,
 * Course 1). A naive `json_decode()` on a Penpot response *appears to work*.
 * The outer structure is valid JSON, the first record reads correctly, and a
 * small payload round-trips convincingly. It is the SECOND and later records
 * that are wrong — because Transit replaces their keys with cache references.
 * That failure mode (correct on small inputs, silently wrong on large ones) is
 * exactly the kind that survives a demo and breaks a production pull, so the
 * decoder is built and tested before anything is built on top of it.
 *
 * ## The four things this decoder handles
 *
 * **1. Tagged scalars** — a string whose first two characters are `~` + a tag:
 *
 *     "~:name"      keyword       → "name"          (a map key or enum value)
 *     "~u4eda…"     UUID          → "4eda…"         (every Penpot id)
 *     "~m1785091…"  instant       → "1785091…"      (millis since epoch)
 *     "~i123"       big integer   → "123"
 *     "~~foo"       escaped       → "~foo"          (a literal leading tilde)
 *
 * EVERY TAGGED SCALAR DECODES TO A **STRING**, including instants and integers.
 * That is deliberate, not an oversight:
 *
 *  - Penpot ids are opaque to us. We store them as metadata and send them back
 *    verbatim, so a UUID value object would buy nothing and cost every call site
 *    an unwrap.
 *  - Instants and bigints are kept as their raw digits so nothing is lost on a
 *    32-bit build or to float coercion. The two callers that need a number
 *    (`revn` comparisons, timestamps) cast at the point of use, where the
 *    intended type is obvious.
 *
 * Note that UNTAGGED JSON scalars are untouched: `"~:revn",5` decodes to the
 * int `5`, because Transit left it a plain JSON number. So a record mixes real
 * ints (revn, vern) with digit-strings (created-at, modified-at) — check the
 * fixtures in TransitTest for which is which rather than assuming.
 *
 * **2. Maps** — encoded as an array whose first element is the marker `"^ "`,
 * then alternating key/value pairs:
 *
 *     ["^ ", "~:name", "My firsty", "~:revn", 5]  →  ['name' => 'My firsty', 'revn' => 5]
 *
 * **3. The write cache (the dangerous part).** Transit assigns every map key it
 * has seen an index, and replaces later occurrences with `^<index>` where the
 * index is base-44 encoded from `'0'` (`^0`…`^9`, `^:`, `^;`, … `^[`, then two
 * digits `^10`). This is why the second record in a Penpot list response looks
 * like nonsense on its own:
 *
 *     [["^ ","~:name","My firsty","~:revn",5],
 *      ["^ ","^0","Another","^1",2]]
 *              └── "^0" IS "~:name", "^1" IS "~:revn"
 *
 * The cache is **stateful across the whole document** and must be populated in
 * strict document order — which is why decoding is a single recursive pass with
 * one shared cache, and not something that can be parallelised or done lazily.
 *
 * **4. Tagged composites** — a two-element array whose first element is a tag:
 *
 *     ["~#set", [...]]  →  the list (we do not model set-ness; Penpot only uses
 *                          it for `features`, which we treat as a plain list)
 *
 * **5. Tagged values in MAP FORM** — the one shape that is a genuine JSON
 * object, and the reason {@see assertNotPlainJson()} has an exemption:
 *
 *     {"~#uri": "https://…/assets/by-id/<uuid>"}  →  the string
 *
 * Transit encodes a tagged value at the TOP of a document as a single-entry
 * object rather than the two-element array, because there is no enclosing array
 * to hang the tag on. `export-binfile`'s `end` event is exactly this (saga
 * §5.5), and it is the *only* payload in this app that arrives that way — every
 * RPC command answers a map or a list of maps.
 *
 * ## What this class does NOT do
 *
 * There is no encoder. Every request we send uses plain JSON with plain string
 * values, which Penpot's endpoints accept — verified live across
 * `get-teams`, `get-all-projects`, `get-project-files`, `rename-file`,
 * `create-project` and `import-binfile` (saga §6.20/§6.38/§6.54). Adding a
 * Transit *writer* would be speculative work for a problem we do not have.
 *
 * @psalm-type TransitValue = null|bool|int|float|string|array<array-key, mixed>
 */
final class Transit {
	/** Marker that identifies an encoded map. Note the trailing space — it is significant. */
	private const MAP_MARKER = '^ ';

	/**
	 * Transit's real cache ceiling: `MAX_CACHE_ENTRIES = 44 * 44 = 1936`, because
	 * an index is encoded as one or two base-44 digits.
	 *
	 * THIS WAS 94, AND IT SILENTLY BROKE EVERY LARGE RESPONSE (saga §C6.9). A
	 * capped cache does not fail at the cap — it keeps decoding with a cache that
	 * has stopped growing, so every later `^n` resolves against the wrong slot or
	 * misses entirely. Proven on a captured 65 KB `get-file` body: the old
	 * constant produced 109 misses; the correct one produces none.
	 *
	 * We only READ the cache, so this bounds a malformed document rather than
	 * implementing the encoder's reset.
	 */
	/** First character of Transit's cache-index alphabet — index 0 is `'0'` (0x30). */
	private const CACHE_BASE_CHAR = '0';

	/** Radix of Transit's cache-index encoding. */
	private const CACHE_RADIX = 44;

	private const CACHE_MAX = self::CACHE_RADIX * self::CACHE_RADIX;

	/**
	 * Decode a Transit-JSON document into plain PHP arrays and scalars.
	 *
	 * @param string $body The raw response body.
	 *
	 * @return TransitValue
	 *
	 * @throws PenpotApiException if the body is not valid JSON, or contains a
	 *                            cache reference that points at nothing (which
	 *                            means we have mis-tracked the cache — a bug
	 *                            worth failing loudly on, never worth guessing
	 *                            past).
	 */
	public function decode(string $body): mixed {
		try {
			/** @var TransitValue $raw */
			$raw = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new PenpotApiException(
				'Penpot returned a body that is not valid JSON: ' . $e->getMessage(),
				0,
				$e,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		$this->assertNotPlainJson($raw);

		// One cache, one pass, strict document order. See the class docblock.
		$cache = [];

		return $this->walk($raw, $cache);
	}

	/**
	 * Refuse a body that is plain JSON rather than Transit.
	 *
	 * WHY THIS GUARD EXISTS. Penpot content-negotiates: send
	 * `Accept: application/json` and it answers plain camelCase JSON instead of
	 * Transit. That body is still *valid JSON*, so this decoder happily walks it
	 * — and produces garbage quietly, in two compounding ways: a plain object
	 * carries no `"^ "` map marker, so it is walked as a LIST and comes back with
	 * numeric keys `0..n`; and even unwrapped, its keys are `teamName` where every
	 * caller here reads `team-name`.
	 *
	 * That is a whole class of silent wrongness produced by ONE tidy-looking
	 * request header, so the failure is made loud at the decoder rather than left
	 * to surface as "why is every field null?" three layers up. PenpotClient's
	 * `call()` carries the matching warning at the header itself.
	 *
	 * The check is narrow on purpose: a JSON **object** is the unambiguous
	 * signature, because Transit encodes every map as an ARRAY beginning with
	 * `"^ "` and never as a JSON object. It is checked at the top level AND at
	 * the first element, since Penpot's listing commands return a LIST of
	 * records — `[{...},{...}]` — so the objects are one level down.
	 *
	 * THE ONE EXEMPTION is the tagged value in map form ({@see isTaggedMap()}):
	 * `{"~#uri": "…"}` IS a JSON object and IS valid Transit. Without the
	 * exemption this guard fires on `export-binfile`'s `end` event and reports a
	 * content-negotiation problem that isn't there — the most misleading error
	 * this class could produce, since the advice it gives ("send no Accept
	 * header") is already being followed.
	 *
	 * @param mixed $raw
	 *
	 * @throws PenpotApiException
	 */
	private function assertNotPlainJson(mixed $raw): void {
		if (!is_array($raw) || $raw === []) {
			return;
		}

		if ($this->isTaggedMap($raw)) {
			return;
		}

		// A JSON object decodes to a PHP array with at least one string key. A
		// Transit map decodes to a LIST whose first element is the "^ " marker.
		if (!is_string(array_key_first($raw))) {
			// Not an object itself — but a listing response is a list OF objects,
			// which is the shape Penpot's get-* commands actually return.
			$first = $raw[array_key_first($raw)];

			if (!is_array($first) || $first === [] || !is_string(array_key_first($first))) {
				return;
			}
		}

		throw new PenpotApiException(
			'Penpot returned plain JSON, not Transit. This almost always means an '
			. '"Accept: application/json" request header — Penpot content-negotiates, and that '
			. 'header switches it to camelCase JSON, which this decoder cannot read and every '
			. 'kebab-case key lookup would miss. Send no Accept header.',
			0,
			null,
			PenpotApiException::KIND_PROTOCOL,
		);
	}

	/**
	 * Recursive decode step.
	 *
	 * @param TransitValue $node
	 * @param list<string> $cache Write cache, by reference — order matters.
	 *
	 * @return TransitValue
	 *
	 * @throws PenpotApiException
	 */
	private function walk(mixed $node, array &$cache): mixed {
		if (is_string($node)) {
			return $this->scalar($node, $cache);
		}

		if (!is_array($node)) {
			// int, float, bool, null — Transit passes these through unchanged.
			return $node;
		}

		if ($node === []) {
			return [];
		}

		if ($this->isTaggedMap($node)) {
			// {"~#uri": "…"} — a tagged value with no enclosing array to hang the
			// tag on. The tag is NOT cached: Transit's write cache holds map keys
			// and tags encountered inside a document, and this form only ever
			// appears as the whole document (the `end` event's payload). Caching
			// it would shift every later index in a document that has none.
			/** @var TransitValue $tagged */
			$tagged = $node[array_key_first($node)];

			return $this->walk($tagged, $cache);
		}

		if ($this->isMap($node)) {
			return $this->map($node, $cache);
		}

		if ($this->isTaggedComposite($node, $cache)) {
			// ["~#set", [...]] and friends — the payload is the second element.
			//
			// THE TAG ITSELF IS CACHED, and it must be cached BEFORE the payload
			// is walked, because that is the order the encoder saw it. Recursing
			// straight to the payload (the obvious implementation, and this
			// decoder's second bug) silently drops one cache slot, shifting every
			// later index by one. `get-teams` is the payload that catches it: its
			// `~#set` sits at index 1, so without this every back-reference from
			// index 1 onward decodes to the WRONG — but still real — field name.
			//
			// Decoding the tag through scalar() also resolves the SECOND
			// occurrence, where the tag arrives as a back-reference (`["^1", …]`)
			// rather than as `"~#set"` — see isTaggedComposite().
			$this->scalar((string)$node[0], $cache);

			/** @var TransitValue $payload */
			$payload = $node[1];

			return $this->walk($payload, $cache);
		}

		// An ordinary list. Values are decoded in order (the cache is populated
		// by whatever map keys they contain, which is why order is preserved).
		$out = [];
		foreach ($node as $item) {
			/** @var TransitValue $item */
			$out[] = $this->walk($item, $cache);
		}

		return $out;
	}

	/**
	 * Decode one encoded map into an associative array.
	 *
	 * @param array<array-key, mixed> $node A list whose [0] is MAP_MARKER.
	 * @param list<string> $cache
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PenpotApiException
	 */
	private function map(array $node, array &$cache): array {
		$values = array_values($node);
		array_shift($values); // drop the "^ " marker

		$out = [];
		$count = count($values);

		for ($i = 0; $i + 1 < $count; $i += 2) {
			$rawKey = $values[$i];

			if (!is_string($rawKey)) {
				// Non-string map keys are legal Transit but Penpot never emits
				// them. Stringify rather than throw: an unexpected key shape is
				// not worth failing a whole pull over.
				$key = (string)json_encode($rawKey);
			} else {
				$key = $this->key($rawKey, $cache);
			}

			/** @var TransitValue $rawValue */
			$rawValue = $values[$i + 1];
			$out[$key] = $this->walk($rawValue, $cache);
		}

		return $out;
	}

	/**
	 * Resolve a map KEY, maintaining the write cache.
	 *
	 * This is the one place the cache is written. A key that is cacheable (a
	 * tagged string long enough to be worth caching) is appended to the cache in
	 * the order it is first seen; a `^n` reference reads it back.
	 *
	 * @param list<string> $cache
	 *
	 * @throws PenpotApiException on a reference to an entry that does not exist.
	 */
	private function key(string $raw, array &$cache): string {
		if ($this->isCacheReference($raw)) {
			$index = $this->cacheIndex($raw);

			if (!isset($cache[$index])) {
				// Never guess. A miss means our cache tracking diverged from the
				// encoder's, and every subsequent key in the document would be
				// wrong too — silently. Fail loudly instead.
				throw new PenpotApiException(
					sprintf(
						'Penpot response referenced Transit cache entry "%s" (index %d) but only %d entries were seen. '
						. 'This means the decoder mis-tracked the write cache.',
						$raw,
						$index,
						count($cache),
					),
					0,
					null,
					PenpotApiException::KIND_PROTOCOL,
				);
			}

			return $cache[$index];
		}

		$decoded = $this->scalarString($raw);

		if ($this->isCacheableKey($raw) && count($cache) < self::CACHE_MAX) {
			$cache[] = $decoded;
		}

		return $decoded;
	}

	/**
	 * Decode a string that appears in VALUE position, maintaining the cache.
	 *
	 * VALUES SHARE THE SAME CACHE AS KEYS, and this is not a detail — it is the
	 * bug that this decoder's first draft had, caught by a real captured payload
	 * (see {@see isCacheableValue()}). A cacheable value such as `~:membership` (the
	 * `permissions.type` in every `get-teams` response) consumes a cache slot
	 * exactly as a key does. Skip it and every subsequent index is off by one —
	 * which decodes to *plausible but wrong* field names, silently.
	 *
	 * @param list<string> $cache
	 *
	 * @throws PenpotApiException
	 */
	private function scalar(string $raw, array &$cache): string {
		if ($this->isCacheReference($raw)) {
			$index = $this->cacheIndex($raw);

			if (isset($cache[$index])) {
				return $cache[$index];
			}

			// In value position a stray "^n" is far more likely to be a literal
			// string that merely looks like a reference than a tracking bug, so
			// this one is NOT fatal — unlike the key-position case above.
			return $raw;
		}

		$decoded = $this->scalarString($raw);

		if ($this->isCacheableValue($raw) && count($cache) < self::CACHE_MAX) {
			$cache[] = $decoded;
		}

		return $decoded;
	}

	/**
	 * Strip a Transit scalar tag, returning the payload as a string.
	 *
	 * ALWAYS a string — keywords, UUIDs, instants and bigints alike. The return
	 * type says so and there is no branch that produces anything else. See the
	 * class docblock for why: ids are opaque, and numeric payloads keep their raw
	 * digits so nothing is lost to 32-bit ints or float coercion.
	 */
	private function scalarString(string $raw): string {
		if (!str_starts_with($raw, '~') || strlen($raw) < 2) {
			return $raw;
		}

		$tag = $raw[1];
		$rest = substr($raw, 2);

		return match ($tag) {
			// Keyword (`~:name`) and symbol (`~$foo`) — the tag is decoration.
			':', '$' => $rest,
			// UUID — Penpot's ids. Kept as the plain 8-4-4-4-12 string.
			'u' => $rest,
			// Composite tag (`~#set`) — the `#` is RETAINED. It never reaches a
			// caller (walk() consumes tagged composites), but it is what lets a
			// cached tag be told apart from a cached keyword when the tag comes
			// back as a reference. See isTaggedComposite().
			'#' => '#' . $rest,
			// Escaped literal tilde: "~~foo" is the string "~foo".
			'~' => '~' . $rest,
			// Everything else (`~m` instant, `~i` bigint, `~d` double, `~b`
			// bytes, unknown future tags) keeps its payload verbatim. Callers
			// that need a number cast it; nothing we read today needs more.
			default => $rest,
		};
	}

	/** True if this array is an encoded map (first element is the `"^ "` marker). */
	private function isMap(array $node): bool {
		return ($node[0] ?? null) === self::MAP_MARKER;
	}

	/**
	 * True if this array is a tagged value in MAP form: a single-entry JSON
	 * object whose key is a literal tag, e.g. `{"~#uri": "https://…"}`.
	 *
	 * Deliberately strict on all three counts — one entry, a string key, and a
	 * literal `~#` prefix. A cache reference is NOT accepted here (unlike
	 * {@see isTaggedComposite()}): this form only ever appears as a whole
	 * document, so there is no earlier occurrence for a reference to point at,
	 * and loosening it would start swallowing ordinary single-key objects.
	 */
	private function isTaggedMap(array $node): bool {
		if (count($node) !== 1) {
			return false;
		}

		$key = array_key_first($node);

		return is_string($key) && str_starts_with($key, '~#');
	}

	/**
	 * True if this array is a tagged composite: exactly two elements whose first
	 * is a tag, e.g. `["~#set", [...]]`.
	 *
	 * THE SECOND OCCURRENCE LOOKS DIFFERENT. Because the tag is itself cached,
	 * a repeated composite arrives as `["^1", [...]]` — a back-reference where
	 * the literal tag used to be. Matching only on `~#` therefore catches the
	 * first `~#set` in a response and misses every later one, leaving them as
	 * two-element lists (`["set", [...]]`) instead of the unwrapped payload.
	 * Live `get-teams` has exactly this shape: team 0 carries `~#set`, team 1
	 * carries `^1`.
	 *
	 * So a leading cache reference counts as a tag when the entry it points at
	 * is one — which is why this needs the cache.
	 *
	 * @param list<string> $cache
	 */
	private function isTaggedComposite(array $node, array $cache): bool {
		if (count($node) !== 2 || !is_string($node[0] ?? null)) {
			return false;
		}

		$head = (string)$node[0];

		if (str_starts_with($head, '~#')) {
			return true;
		}

		if (!$this->isCacheReference($head)) {
			return false;
		}

		// A back-reference is a tag only if what it resolves to was one. Tags are
		// cached with their `#` retained (scalarString leaves `~#set` as `#set`),
		// which is precisely what distinguishes them from cached keywords.
		$resolved = $cache[$this->cacheIndex($head)] ?? '';

		return str_starts_with($resolved, '#');
	}

	/**
	 * True if this string is a cache reference (`^0`, `^1`, … `^[`, `^10`).
	 *
	 * The map marker `"^ "` is deliberately excluded — it shares the prefix but
	 * is structural, not a reference.
	 */
	private function isCacheReference(string $raw): bool {
		if (strlen($raw) < 2 || $raw[0] !== '^' || $raw === self::MAP_MARKER) {
			return false;
		}

		// Every character after "^" must be in the index alphabet.
		for ($i = 1, $len = strlen($raw); $i < $len; $i++) {
			$ord = ord($raw[$i]) - ord(self::CACHE_BASE_CHAR);
			if ($ord < 0 || $ord >= self::CACHE_RADIX) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decode Transit's base-44 cache index: `^0` → 0, `^1` → 1, … `^10` → 44.
	 *
	 * The alphabet is 44 characters starting at `'0'` (0x30), so it runs
	 * `0-9 : ; < = > ? @ A-Z [`. Single-character indices are a direct offset and
	 * cover the overwhelming majority — a Penpot record rarely carries more than
	 * 44 distinct keys, and the live fixtures top out at `^<` (12).
	 *
	 * NOTE the alphabet stops at `[`; lowercase letters are NOT valid index
	 * characters, so `^z` is not a cache reference. {@see isCacheReference()}
	 * rejects it on exactly that basis rather than by length.
	 */
	private function cacheIndex(string $raw): int {
		$digits = substr($raw, 1);
		$index = 0;

		for ($i = 0, $len = strlen($digits); $i < $len; $i++) {
			$index = $index * self::CACHE_RADIX + (ord($digits[$i]) - ord(self::CACHE_BASE_CHAR));
		}

		return $index;
	}

	/**
	 * Whether a raw token would have been added to the encoder's write cache.
	 *
	 * THE RULE, DERIVED FROM A REAL PAYLOAD RATHER THAN GUESSED. Transit caches
	 * only **keywords** (`~:foo`) and **tags** (`~#foo`) longer than the
	 * reference that would replace them. It does NOT cache other tagged scalars —
	 * notably instants (`~m…`) and UUIDs (`~u…`), even though those are long,
	 * repeated, and superficially look like excellent cache candidates.
	 *
	 * This decoder's first draft cached every `~`-tagged string over 3 chars. That
	 * is wrong, and wrong in the worst available way: it drifts the index by one
	 * for every instant or UUID seen, so later keys decode to REAL BUT INCORRECT
	 * field names. `created-at` reads back as `modified-at`. Nothing throws.
	 *
	 * It was caught because a captured `get-teams` response threw on a reference
	 * one past the end of the cache — and the fix was verified by walking that
	 * same payload and checking all twelve back-references (`^0`…`^;`) resolve to
	 * the field names the record actually has:
	 *
	 *     ^0 → features   ^1 → #set        ^2 → permissions  ^3 → type
	 *     ^4 → membership ^5 → is-owner    ^6 → is-admin     ^7 → can-edit
	 *     ^8 → name       ^9 → modified-at ^: → id           ^; → created-at
	 *
	 * Note `^4 → membership` is a cached **value**, not a key — see `scalar()`.
	 *
	 * The length guard mirrors Transit's own: a token is only cached if the
	 * reference (`^` + index, so 2–3 chars) would actually be shorter.
	 *
	 * ## KEYS ARE CACHED WHATEVER THEY LOOK LIKE (saga §C6.9)
	 *
	 * This used to demand a `~:` or `~#` prefix here too, mirroring the value
	 * rule. Transit does not: **every** map key over three characters is cached,
	 * plain strings included. The `get-teams` payload above happens to have only
	 * keyword keys, so the mistake was invisible for two whole courses — until a
	 * bigger record with plain-string keys shifted every index after the first
	 * one and produced 109 bad references in a single response.
	 */
	private function isCacheableKey(string $raw): bool {
		return strlen($raw) > 3;
	}

	/**
	 * Is this string cacheable in VALUE position?
	 *
	 * Stricter than {@see isCacheableKey()}, and the asymmetry is the whole point
	 * (saga §C6.9). Transit caches EVERY map key long enough to be worth caching,
	 * whatever it looks like — but in value position only the *tagged* forms:
	 * keywords (`~:`), tags (`~#`) and symbols (`~$`). A plain string value like
	 * "fdata/path-data" is NOT cached, while the identical string used as a key
	 * WOULD be.
	 *
	 * Treating both positions the same — which this decoder did — under-caches
	 * every plain-string key and shifts every subsequent index. On a captured
	 * `get-file` body that was 109 bad references out of 206 entries.
	 */
	private function isCacheableValue(string $raw): bool {
		return (str_starts_with($raw, '~:') || str_starts_with($raw, '~#') || str_starts_with($raw, '~$'))
			&& strlen($raw) > 3;
	}
}
