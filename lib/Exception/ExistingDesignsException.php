<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Exception;

/**
 * A `link` mapping was asked for over a folder that already holds designs
 * (`mapping/create.feature`).
 *
 * ## WHY THIS IS NOT JUST ANOTHER `InvalidArgumentException`
 *
 * Every other refusal in {@see \OCA\PenpotSync\Service\MappingService::add()} is
 * final: the team is taken, the folder is taken, there is no token. This one is a
 * QUESTION — the admin can answer it, and answering it destroys files. So the two
 * front doors need to tell it apart from the others, and neither should do that by
 * matching on the message:
 *
 *   - the panel turns it into a confirmation and re-submits with the
 *     acknowledgement, which means it needs the COUNT as a number rather than as
 *     a sentence it would have to parse back out;
 *   - `occ` prints it like any other refusal, because a CLI has nowhere to ask.
 *
 * It extends `InvalidArgumentException` so every existing caller — the command's
 * catch, the controller's 422 — keeps working unchanged; only the code that wants
 * to offer the choice has to know the type exists.
 */
final class ExistingDesignsException extends \InvalidArgumentException {
	public function __construct(
		string $message,
		public readonly int $designs,
	) {
		parent::__construct($message);
	}
}
