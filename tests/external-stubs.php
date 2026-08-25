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

namespace Sabre\DAV {
	// The Sabre/DAV server surface LinkWriteGuardPlugin builds on. Sabre ships
	// inside a running Nextcloud, not in nextcloud/ocp, so the unit suite has to
	// declare the symbols the plugin touches: the node interface it type-checks
	// against, the server it hooks `beforeWriteContent` and `method:MOVE` on (and
	// asks to place a Destination header), and the plugin base it extends.
	// Declarations only — the real behaviour is exercised by the integration
	// suite against a live server.
	if (!interface_exists(INode::class, false)) {
		interface INode {
			public function getName(): string;
		}
	}
	if (!class_exists(Server::class, false)) {
		class Server {
			/**
			 * The request being served, which real Sabre exposes as a public
			 * property and this stub therefore does too.
			 *
			 * NEEDED BY `beforeCreateFile`, which is the one hook that is handed
			 * neither the request nor the node — only a path and the parent. Telling
			 * a "+ New" (zero bytes) from an upload without consuming the body means
			 * reading `Content-Length`, and the server is where it is reachable from.
			 * The MOVE and DELETE handlers take a RequestInterface argument and do
			 * not need this.
			 */
			public \Sabre\HTTP\RequestInterface $httpRequest;

			public function on(string $eventName, callable $callBack, int $priority = 100): bool {
				return true;
			}

			public function addPlugin(ServerPlugin $plugin): void {
			}

			/** Turns an absolute Destination header into a path relative to the DAV root. */
			public function calculateUri(string $uri): string {
				return ltrim((string)parse_url($uri, PHP_URL_PATH), '/');
			}
		}
	}
	if (!class_exists(ServerPlugin::class, false)) {
		abstract class ServerPlugin {
			abstract public function initialize(Server $server): void;
		}
	}
}

namespace Sabre\HTTP {
	// The `method:*` handler signature. Sabre hands the whole request over, which is
	// how the move guard reads the Destination header without reaching into an
	// untyped public property on the server.
	if (!interface_exists(RequestInterface::class, false)) {
		interface RequestInterface {
			public function getPath(): string;

			public function getHeader(string $name): ?string;
		}
	}
	if (!interface_exists(ResponseInterface::class, false)) {
		interface ResponseInterface {
		}
	}
}

namespace Sabre\DAV\Exception {
	// The native deny. Sabre turns a thrown Forbidden into a clean 403 and never
	// writes the bytes, which is the whole mechanism behind the link write guard.
	if (!class_exists(Forbidden::class, false)) {
		class Forbidden extends \Exception {
		}
	}
}

namespace OCA\DAV\Connector\Sabre {
	// Nextcloud's own Sabre file node — the concrete type LinkWriteGuardPlugin
	// narrows to before asking for a file id, since a plain Sabre INode has no id
	// and nothing to look metadata up by.
	if (!class_exists(File::class, false)) {
		class File implements \Sabre\DAV\INode {
			public function getName(): string {
				return '';
			}

			public function getId(): int {
				return 0;
			}
		}
	}
}

namespace OCA\DAV\Events {
	// Fired by the bundled DAV app while assembling each Sabre server. It is the
	// supported seam for a third-party app to attach its own ServerPlugin, and
	// what RegisterDavPluginsListener listens for.
	if (!class_exists(SabrePluginAddEvent::class, false)) {
		class SabrePluginAddEvent extends \OCP\EventDispatcher\Event {
			public function getServer(): \Sabre\DAV\Server {
				return new \Sabre\DAV\Server();
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
