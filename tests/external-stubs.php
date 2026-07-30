<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Psalm stubs for classes that exist in a real Nextcloud at runtime but ship in
 * neither `nextcloud/ocp` nor any Composer package — Sabre/DAV server classes,
 * other bundled apps' event classes, and so on.
 *
 * Add entries here one at a time, each with a comment naming the class that
 * needs it — see the sibling apps' versions for the established shape.
 *
 * The first two arrived with the Files-app surface (saga Ch2 Course 6): the
 * bundled Files app's script-load event, and core's mimetype-list generator that
 * the mimetype repair steps drive. Stubbing them — rather than suppressing
 * `UndefinedClass` wholesale — keeps Psalm type-checking our *use* of them.
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
