<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Refuses to let a `link`-mode design file be overwritten over WebDAV.
 *
 * ## THIS APP WAS THE ONLY ONE WITHOUT ONE
 *
 * Both siblings have carried this plugin for a while; penpot_sync never grew one,
 * and it is the app where it matters MOST. A `link` here is not a small pointer
 * document the way a linked dashboard or workflow is — it is a **zero-byte file**.
 * Nothing about it on disk says "do not write to me", so a desktop client
 * resolving a conflict, a `curl` PUT, or dragging a real `.penpot` archive on top
 * of one all used to land bytes in it without complaint.
 *
 * What followed was quiet rather than loud, which is why it went unnoticed: the
 * file kept `penpot_mode = link`, so it still LOOKED like a pointer while holding
 * an archive nobody would ever read. {@see \OCA\PenpotSync\Service\CreationService}
 * does not adopt it (it already carries a `penpot_id`), nothing pushes it — Penpot
 * has no write path for design content at all — and the next pull empties it again
 * ({@see \OCA\PenpotSync\Service\ArchiveService::storeLink()}, which truncates any
 * link that has grown a body). So the write was always destined to be discarded;
 * the only question was whether the user found out now, or later when the bytes
 * had already gone. A 403 answers that now.
 *
 * ## WHY A SABRE PLUGIN AND NOT A `BeforeNodeWrittenEvent` LISTENER
 *
 * Inherited verbatim from the siblings, and worth restating because the obvious
 * choice is the broken one. `BeforeNodeWrittenEvent` is produced from the legacy
 * `write` filesystem hook, and {@see \OCA\DAV\Connector\Sabre\File::put()} only
 * emits that pre-write hook on its non-part-file branch. Almost every storage
 * uploads through a `.part` file first, so the event never fires for a normal PUT
 * and the write slips straight through. Sabre's `beforeWriteContent` is emitted by
 * the Sabre server *before* `File::put()` runs, so it fires for every PUT
 * regardless of the part-file dance.
 *
 * ## NO LOOP GUARD IS NEEDED, FOR A DIFFERENT REASON THAN THE SIBLINGS'
 *
 * Every write this app makes to a mirror goes through the Node API —
 * `ArchiveService` calls `$node->putContent()` to store an archive and again to
 * empty a link — and the Node API does not pass through Sabre. The pull can write
 * link bodies as often as it likes without ever reaching this plugin, so unlike
 * the {@see \OCA\PenpotSync\Service\SyncGuard} fencing
 * {@see \OCA\PenpotSync\Listener\CreateListener}, there is nothing here to fence.
 *
 * ## FAIL OPEN, ALWAYS
 *
 * Anything unreadable is treated as NOT a link. A guard that blocks writes when it
 * cannot classify a file would turn a metadata hiccup into an unwritable folder,
 * and the failure this exists to prevent is a discarded write — not a lost one.
 */
final class LinkWriteGuardPlugin extends ServerPlugin {
	public function __construct(
		private readonly PenpotMetadata $metadata,
		private readonly LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		// Run early (a low priority number wins) so we refuse before any bytes are
		// streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
	}

	/**
	 * @param mixed $data
	 * @param bool|null $modified
	 */
	public function beforeWriteContent(string $path, INode $node, &$data, &$modified): bool {
		if (!$node instanceof DavFile) {
			return true; // not a file node we care about
		}
		$name = $node->getName();
		if (!str_ends_with($name, PullService::EXTENSION)) {
			return true; // only mirrored designs are constrained
		}

		// Classify from the file's own metadata; anything we cannot read is not a
		// link, and must not be blocked.
		try {
			$fileId = $node->getId();
			$managed = $this->metadata->readFile($fileId);
		} catch (\Throwable) {
			return true;
		}
		if (!$managed?->isLink()) {
			// A `sync` mirror holds a real archive and an untracked `.penpot` is
			// nobody's business but its owner's. Both may be written freely.
			return true;
		}

		$this->logger->warning('penpot_sync: refused a WebDAV write to a link-mode design file', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'file' => $name,
		]);

		throw new Forbidden(
			'“' . $name . '” is a linked Penpot design — a pointer to a design that lives in Penpot, '
			. 'so there is nothing here to write to. Open it in Penpot to make changes.',
		);
	}
}
