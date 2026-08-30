<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

/**
 * The seatbelt every downward walk over user-shaped data in this app shares.
 *
 * ## WHY A CEILING AT ALL
 *
 * A `Folder` tree is user-shaped: its depth is whatever somebody made it, and
 * this app walks one on nearly every gesture — the pull's two indexes, the
 * delete's project sweep, the teardown's mirror sweep, the tag opt-in's design
 * sweep, the copy, the move scan, the trash inspection. None of those has a
 * natural bound, so each carries one. It is a bound on a walk nobody should ever
 * reach the end of, not a limit anyone is expected to hit.
 *
 * ## WHY IT LIVES HERE INSTEAD OF ONCE PER CLASS
 *
 * It used to be declared fourteen times — `private const MAX_DEPTH = 100` in
 * thirteen services plus `SCAN_DEPTH` in {@see MoveRules} — each with a comment
 * saying it was "the same ceiling every other recursion uses". That comment was
 * the only thing making it true, and a comment cannot keep two numbers equal.
 *
 * They have to agree for a reason that is not merely tidiness. Several of these
 * walks read the SAME tree for different halves of one question — the pull's
 * prune and its upsert, the delete's projects and its designs — and a walk that
 * stopped shallower than its partner would attribute files the partner still
 * claims. §C6.20 is the bug that shape produces: silent duplicates, no error
 * anywhere. One constant is how they stay equal without anyone remembering to.
 *
 * Each class still names it locally (`private const MAX_DEPTH = Walk::MAX_DEPTH`)
 * so `self::MAX_DEPTH` continues to read naturally at the guard, which is the
 * only place it is ever used.
 */
final class Walk {
	/**
	 * How deep any downward walk over `Folder` nodes may descend.
	 *
	 * A call at depth `d` lists the nodes one below it, so a guard written
	 * `if ($depth >= self::MAX_DEPTH)` reaches exactly this many levels down.
	 * {@see PullService::isLegalProjectName()} depends on that reading, and says
	 * so at length — it refuses a project name of exactly this many segments
	 * because such a name lands on the last rung the walks can still see.
	 */
	public const MAX_DEPTH = 100;
}
