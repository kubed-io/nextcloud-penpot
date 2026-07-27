<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\Membership;
use PHPUnit\Framework\TestCase;

/**
 * The four membership states (saga §6.29 / §6.31 / §6.35), as pure combinations
 * of "found a team id?" × "found a project id?". These are worth pinning on
 * their own because the resolver reports the two ids and everything downstream
 * branches on the STATE — a wrong mapping here (personal mistaken for broken,
 * Drafts mistaken for unmapped) is the kind of bug the saga overturned twice.
 */
final class MembershipTest extends TestCase {
	public function testTeamAndProjectIsInProject(): void {
		$m = new Membership('proj', 'team');

		self::assertSame(Membership::STATE_IN_PROJECT, $m->state());
		self::assertTrue($m->inProject());
		self::assertTrue($m->belongsToPenpot());
		self::assertFalse($m->inDrafts());
		self::assertFalse($m->isPersonal());
	}

	public function testTeamWithoutProjectIsDrafts(): void {
		// "In a team, in no project" is precisely Penpot's Drafts (saga §6.35) —
		// NOT unmapped. This is one of the two states the saga corrected.
		$m = new Membership(null, 'team');

		self::assertSame(Membership::STATE_DRAFTS, $m->state());
		self::assertTrue($m->inDrafts());
		self::assertTrue($m->belongsToPenpot());
		self::assertFalse($m->inProject());
	}

	public function testProjectWithoutTeamIsPersonal(): void {
		// A project with no team above it is a personal project (saga §6.31),
		// the one valid teamless case — NOT a broken mapping.
		$m = new Membership('proj', null);

		self::assertSame(Membership::STATE_PERSONAL, $m->state());
		self::assertTrue($m->isPersonal());
		self::assertTrue($m->belongsToPenpot());
		self::assertFalse($m->inProject());
	}

	public function testNeitherIsNone(): void {
		$m = Membership::none();

		self::assertSame(Membership::STATE_NONE, $m->state());
		self::assertFalse($m->belongsToPenpot());
		self::assertNull($m->projectId);
		self::assertNull($m->teamId);
	}
}
