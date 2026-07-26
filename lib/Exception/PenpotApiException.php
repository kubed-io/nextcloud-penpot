<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Exception;

/**
 * Every failure the Penpot client can raise, in one small typed family.
 *
 * WHY ONE CLASS WITH A KIND, rather than a class per failure: callers overwhelmingly
 * want one of two things — "did this work?" or "is this worth retrying?". A hierarchy
 * would push a match/instanceof ladder into every call site to answer questions the
 * kind already answers. The sibling apps land on the same shape for the same reason.
 *
 * THE KINDS MAP TO REAL, OBSERVED PENPOT BEHAVIOUR (saga Ch1):
 *
 *   UNCONFIGURED  no base URL / no token stored — a setup problem, not a fault.
 *   UNREACHABLE   DNS, TLS, connection refused, or Nextcloud's own egress guard.
 *   UNAUTHORIZED  token missing, expired, or revoked.
 *   FORBIDDEN     authenticated but not a member of that team (saga §6.12: Penpot's
 *                 visibility is membership-scoped, ALWAYS — there is no admin scope,
 *                 so this is an ordinary state, not a misconfiguration).
 *   NOT_FOUND     the object is gone, or was soft-deleted (saga §6.20: a deleted
 *                 file returns `object-not-found` at its original id).
 *   VALIDATION    Penpot rejected the params — including the param-NAME traps
 *                 (saga §6.54: `rename-file` wants `id`, not `file-id`, and says so
 *                 with `:params-validation` + a missing-key explain body).
 *   PROTOCOL      the transport did something we cannot interpret: undecodable
 *                 Transit, or an SSE stream that ended without `end` or `error`.
 *                 THIS IS THE IMPORTANT ONE — Penpot returns **HTTP 200 with an
 *                 error event in the body** for SSE commands (saga §5.1/§6.20), so
 *                 a client that trusts the status code reports success on failure.
 */
class PenpotApiException extends \RuntimeException {
	public const KIND_UNCONFIGURED = 'unconfigured';
	public const KIND_UNREACHABLE = 'unreachable';
	public const KIND_UNAUTHORIZED = 'unauthorized';
	public const KIND_FORBIDDEN = 'forbidden';
	public const KIND_NOT_FOUND = 'not_found';
	public const KIND_VALIDATION = 'validation';
	public const KIND_PROTOCOL = 'protocol';

	/**
	 * @param string $kind One of the KIND_* constants.
	 * @param string|null $penpotCode Penpot's own error code (e.g. `object-not-found`,
	 *                                `params-validation`) when the body carried one —
	 *                                preserved verbatim for logs and bug reports.
	 */
	public function __construct(
		string $message,
		int $code = 0,
		?\Throwable $previous = null,
		private readonly string $kind = self::KIND_PROTOCOL,
		private readonly ?string $penpotCode = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function getPenpotCode(): ?string {
		return $this->penpotCode;
	}

	/**
	 * Whether retrying the same call might succeed without anyone changing anything.
	 *
	 * Used by the pull to decide "skip this object and carry on" vs "stop, the
	 * instance is down". Deliberately conservative: a validation error or a 403 will
	 * never fix itself, so retrying them just multiplies noise in the log.
	 */
	public function isRetryable(): bool {
		return in_array($this->kind, [self::KIND_UNREACHABLE, self::KIND_PROTOCOL], true);
	}
}
