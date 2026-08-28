<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * The record of the last push — the "Sync to Penpot" twin of {@see PullStatus}.
 *
 * ## WHY A SUBCLASS AND NOT A CONSTRUCTOR ARGUMENT
 *
 * A `string $direction` on {@see PullStatus} would have been the smaller diff and
 * a worse one: nine injection sites name that class in a constructor, and Nextcloud's
 * container resolves those BY TYPE. A scalar argument would need every one of them
 * wired by hand, and the two directions would be one typo apart from silently
 * sharing a record. A distinct type is a distinct service, which is what the
 * container is good at, and it makes `PushStatus $status` mean something at every
 * call site that reads it.
 *
 * Everything else — the merge-don't-replace rule, the busy check, the counters —
 * is the parent's and is deliberately not restated here.
 */
final class PushStatus extends PullStatus {
	/**
	 * NOT NAMED `KEY`, and the name is the whole reason.
	 *
	 * The parent's own constant is `private const KEY`, which PHP resolves
	 * lexically — two unrelated privates never collide at runtime. Psalm reads it
	 * differently: it infers the parent's as the LITERAL type `'pull_status'` and
	 * then reports a same-named child constant as failing to satisfy it
	 * (InvalidClassConstantType), because a redeclared constant is expected to
	 * narrow rather than diverge.
	 *
	 * Suppressing that would be arguing with a checker that has a point. A
	 * different name says what is true — this is a second key, not a narrower
	 * version of the first — and leaves the parent's constant private, which is
	 * where it belongs.
	 */
	private const PUSH_KEY = 'push_status';

	#[\Override]
	protected function key(): string {
		return self::PUSH_KEY;
	}
}
