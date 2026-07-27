<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\Exception\PenpotApiException;

/**
 * "Test connection" — one implementation, three front doors.
 *
 * The admin button, `occ penpot_sync:test-connection`, and the mapping page's
 * team picker all need the same answer, and it must read identically in all
 * three. Both sibling apps learned this the slow way: a CLI that says "failed"
 * where the UI says "the API key was rejected" costs a support round-trip every
 * time.
 *
 * ## WHAT IT REPORTS IS A DESIGN DECISION, NOT A DETAIL
 *
 * Not "OK" — **which teams the token can actually see** (saga §6.12/§6.18).
 * Penpot visibility is always membership-scoped, so that list is precisely what
 * decides what can be mapped. A token that authenticates perfectly and sees
 * zero teams is a real, ordinary state, and it is exactly the state that blocks
 * every mapping. "Connection OK" would hide the one fact an admin needs.
 *
 * ## THE THREE FAILURES IT MUST TELL APART (admin-connection.feature)
 *
 * | State | What the admin must do |
 * |---|---|
 * | no URL / no token | finish configuring |
 * | token rejected | mint a new one |
 * | unreachable | fix networking, or `allow_local_remote_servers` |
 *
 * Collapsing these into "connection failed" sends people to the wrong fix. The
 * `enable-access-tokens` case is called out specifically because it is
 * off by default upstream and produces a plain 401 — indistinguishable from a
 * typo'd token unless the message names it.
 */
final class ConnectionTester {
	public function __construct(
		private readonly PenpotClient $client,
	) {
	}

	/**
	 * Run the check.
	 *
	 * Never throws: every caller wants to *render* the failure, not handle an
	 * exception. The typed result is what makes the three front doors agree.
	 */
	public function test(): ConnectionResult {
		try {
			$teams = $this->client->ping();
		} catch (PenpotApiException $e) {
			return new ConnectionResult(false, $e->getKind(), $this->describe($e), []);
		}

		if ($teams === []) {
			// Authenticated, but a member of nothing. NOT an error — and not
			// something to paper over either, since nothing can be mapped until
			// an invite lands.
			return new ConnectionResult(
				true,
				ConnectionResult::KIND_NO_TEAMS,
				'Connected to Penpot, but this token can see no teams. '
				. 'Invite the service account to a Penpot team before mapping it.',
				[],
			);
		}

		return new ConnectionResult(
			true,
			ConnectionResult::KIND_OK,
			sprintf(
				'Connected to Penpot. Visible team%s: %s',
				count($teams) === 1 ? '' : 's',
				implode(', ', $teams),
			),
			$teams,
		);
	}

	/**
	 * Turn a client exception into something an admin can act on.
	 *
	 * The client's own messages are already written for humans; this adds the
	 * *next step* where the right one is knowable from the kind alone.
	 */
	private function describe(PenpotApiException $e): string {
		return match ($e->getKind()) {
			PenpotApiException::KIND_UNCONFIGURED => $e->getMessage(),
			// Names BOTH front doors on purpose: this same string is rendered by
			// the admin button and printed by the CLI, so an instruction that
			// assumes one of them is wrong half the time it is read.
			PenpotApiException::KIND_UNAUTHORIZED => 'Penpot rejected the service-account token. '
				. 'It may have been revoked, or the Penpot instance may not have '
				. '`enable-access-tokens` set — that flag is off by default and its '
				. 'absence looks exactly like a bad token. Mint a new token in Penpot, '
				. 'then paste it into the Instance card above or run '
				. '`occ penpot_sync:set-token`.',
			PenpotApiException::KIND_FORBIDDEN => 'The service-account token was accepted but is not '
				. 'allowed to do this. Check the account\'s role in Penpot.',
			PenpotApiException::KIND_UNREACHABLE => $e->getMessage(),
			default => $e->getMessage(),
		};
	}
}
