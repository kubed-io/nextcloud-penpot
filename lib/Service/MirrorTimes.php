<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Gives a mirror the timestamps of the thing it mirrors: Penpot's `modified-at`
 * becomes the node's modification time, `created-at` its creation time.
 *
 * ## Why this exists
 *
 * Nextcloud's own clocks are honest about the NODE — "the app wrote this file at
 * 15:02" — and that is never the question a person sorting a folder of designs by
 * date is asking. Left alone, a design edited at 15:00 and mirrored at 15:02 reads
 * `15:02`, and one nobody has opened in a year reads the moment its mirror was first
 * written. Worse here than in the siblings: a `link` is zero bytes and is never
 * rewritten after birth (§C6.6), so its date is frozen at whenever we happened to
 * first see it.
 *
 * ## THE TWO CLOCKS ARE NOT SYMMETRIC, AND ONE OF THEM IS A TRAP
 *
 * Measured on a live instance rather than assumed:
 *
 *  - `creation_time` is ours to set and **survives a child write** — set a folder's
 *    to 2019 and writing a design inside it leaves it at 2019.
 *  - `mtime` on a FOLDER is **propagated by Nextcloud from its children**: set it to
 *    2020, write one design inside, and it snaps to `now`.
 *
 * So a project folder gets its **creation time only**. Stamping a folder's mtime
 * would mean fighting the propagator on every pull that writes any design — we set
 * it, core overwrites it, we set it again — which is churn, and churn is what the
 * change-detection work existed to remove. It would also be *less* useful: a
 * propagated folder mtime honestly means "something in this project changed", while
 * Penpot's project `modified-at` only moves when the project is renamed (measured:
 * `created-at == modified-at` on an untouched project).
 *
 * Files have no such conflict, so they get both.
 *
 * ## The other trap: Penpot does not speak ISO-8601
 *
 * The siblings' sources send `2026-07-24T16:25:42Z`. Penpot sends **epoch
 * milliseconds, as a string**: `"1785020723908"`. `strtotime()` returns false on
 * that, and a null timestamp means "leave the clock alone" — so a straight port of
 * the n8n/grafana parser would quietly set nothing at all and look like it worked.
 * {@see parse()} is therefore Penpot's own, and has a test that would fail against
 * the siblings' format.
 *
 * ## Conditional, always
 *
 * `touch()` leaves a file's own etag alone but propagates a fresh etag to its parent
 * folder — which is exactly what sync clients poll to decide "re-scan this". So an
 * unconditional stamp would not churn the files, it would churn the folder, every
 * tick, forever. Every write here is conditional, which also makes it self-healing: a
 * mirror written before this existed is corrected on the next pull, then left alone.
 */
final class MirrorTimes {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stamp `$mtime` / `$creationTime` onto `$node`, writing only what actually differs.
	 *
	 * A null value means "leave that clock alone" — never "stamp the epoch", which is
	 * what makes an absent or unparseable source timestamp harmless rather than
	 * destructive. Folders pass `$mtime = null` deliberately (see the class docblock).
	 *
	 * Best-effort: callers reach this after the body, the metadata and the tags are
	 * already committed, so a clock that will not set is logged and swallowed. It must
	 * never turn a good pull into a failed one, and the next pull retries.
	 *
	 * @param bool $force the caller just rewrote the body, so the node's mtime is `now`
	 *                    and there is nothing meaningful to compare against
	 */
	public function apply(Node $node, ?int $mtime, ?int $creationTime, bool $force = false): void {
		try {
			if ($mtime !== null && ($force || $node->getMTime() !== $mtime)) {
				$node->touch($mtime);
			}
			if ($creationTime !== null && $node->getCreationTime() !== $creationTime) {
				// No OCP setter for creation time; the public cache API is the route
				// (Node::getStorage -> IStorage::getCache -> ICache::update, all
				// @since 9.0.0). `$force` is deliberately not honoured here — writing a
				// body does not disturb a creation time, so the comparison is always
				// meaningful and always sufficient.
				$node->getStorage()->getCache()->update($node->getId(), ['creation_time' => $creationTime]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync: could not stamp the source timestamps onto the mirror', [
				'app' => Application::APP_ID,
				'node' => $node->getName(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * A Penpot timestamp as a Unix second.
	 *
	 * Penpot sends **epoch milliseconds**, and sends them as a JSON *string*
	 * (`"1785020723908"`) — not the ISO-8601 the n8n and Grafana siblings use. An
	 * integer is accepted too, because a transport that decodes it as a number is not
	 * wrong and should not silently disable the feature.
	 *
	 * Returns null for anything absent, empty, or non-numeric — so a schema change on
	 * Penpot's side degrades to "keep Nextcloud's own clock", which is merely the old
	 * behaviour, rather than to a mirror dated 1970.
	 */
	public static function parse(mixed $value): ?int {
		if (is_string($value)) {
			$value = trim($value);
			if ($value === '' || !ctype_digit(ltrim($value, '-'))) {
				return null;
			}
			$value = (int)$value;
		}
		if (!is_int($value) || $value <= 0) {
			return null;
		}
		return intdiv($value, 1000);
	}
}
