<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Stubs for classes that exist in a real Nextcloud at runtime but ship in
 * neither `nextcloud/ocp` nor any Composer package — other bundled apps' event
 * classes, core internals, Sabre/DAV server classes, and so on.
 *
 * Loaded TWICE, by design: Psalm reads it as `<stubs>` (psalm.xml) so our *use*
 * of these classes stays type-checked instead of `UndefinedClass`-suppressed,
 * and `tests/bootstrap.php` requires it so the unit suite can construct them.
 * A listener that takes a foreign event needs the class to exist in both places,
 * and one declaration serving both is what keeps them from drifting.
 *
 * Its companion, `ocp-stubs.php`, covers the `OCP\*` surface. The split is by
 * ownership, not by consumer: OCP there, everything else here.
 *
 * Add entries one at a time, each with a comment naming the class that needs it
 * — see the sibling apps' versions for the established shape.
 */

namespace OCA\Files\Event {
	// Fired by the bundled Files app right before it emits its <script> tags. Not
	// shipped in nextcloud/ocp. Stubbed so LoadFilesScriptListener's
	// `@implements IEventListener<LoadAdditionalScriptsEvent>` type-checks.
	if (!class_exists(LoadAdditionalScriptsEvent::class, false)) {
		class LoadAdditionalScriptsEvent extends \OCP\EventDispatcher\Event {
		}
	}
}

namespace OCA\Files_Trashbin\Events {
	// Dispatched by the bundled Files_Trashbin app once a file is back out of the
	// trash — the only typed signal on that side of the line, and the opposite
	// number of the purge, which fires nothing typed at all (§C6.13) and is caught
	// with a legacy hook instead.
	//
	// Its real base is OCP\Files\Events\Node\AbstractNodesEvent. The stub declares
	// the two accessors directly instead of extending it — same shape ocp-stubs.php
	// gives NodeRenamedEvent, and for the same reason: nothing autoloads the OCP
	// base standalone, so extending it would fatal the unit bootstrap.
	//
	// TARGET is the node at its restored path; SOURCE is where it sat in the trash.
	if (!class_exists(NodeRestoredEvent::class, false)) {
		class NodeRestoredEvent extends \OCP\EventDispatcher\Event {
			public function getSource(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}

			public function getTarget(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
}

namespace OC\Core\Command\Maintenance\Mimetype {
	// The generator behind `occ maintenance:mimetype:update-js`. It lives in
	// core/, so it is neither public API nor a Composer package — but it is the
	// only way to regenerate core/js/mimetypelist.js in-process, which is exactly
	// what Register/UnregisterMimetype must do to make the alias visible to the
	// frontend. Signature mirrored from core (stable33).
	if (!class_exists(GenerateMimetypeFileBuilder::class, false)) {
		class GenerateMimetypeFileBuilder {
			/**
			 * @param array<string, string> $aliases
			 * @param array<string, string> $names
			 */
			public function generateFile(array $aliases, array $names): string {
				return '';
			}
		}
	}
}
