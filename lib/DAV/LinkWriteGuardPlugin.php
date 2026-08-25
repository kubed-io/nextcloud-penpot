<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\MoveRules;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

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
		private readonly MoveRules $rules,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Kept from {@see initialize} so {@see onMove} can place a destination path. */
	private ?Server $server = null;

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		// Run early (a low priority number wins) so we refuse before any bytes are
		// streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
		// AND THE REFUSALS THE LISTENER CANNOT VOICE. {@see \OCA\PenpotSync\Listener\MoveGuardListener}
		// stops a refused move on every route there is, but the message it carries is
		// caught and discarded by `HookConnector::rename()`, and `Directory::moveInto()`
		// then answers `Forbidden('')` with an empty string. So a person dragging a
		// project out of its team folder got a failure dialog with nothing in it —
		// exactly the outcome those two carefully written, translated messages exist to
		// prevent. Refusing here is what puts the reason in the response.
		//
		// The priority runs this ahead of Sabre's own `httpMove` (100).
		$server->on('method:MOVE', [$this, 'onMove'], 10);

		// THE VERB THE GUARD HAD NEVER COVERED. `beforeWriteContent` catches PUT
		// and Sabre's COPY goes through it per file, so a link could not be
		// written or duplicated — but DELETE reached nothing at all, and trashing
		// a link project was a plain 204. A read-only mirror you can delete is not
		// read-only; `projects/delete.feature` says so and nothing enforced it.
		$server->on('method:DELETE', [$this, 'onDelete'], 10);

		// AND THE VERB `beforeWriteContent` CANNOT SEE AT ALL. That event fires for
		// an EXISTING node, and it classifies from the file's own metadata — so a
		// brand new `.penpot` has none, reads as "not a link", and was written
		// straight into a link mapping. Sabre emits `beforeCreateFile` instead when
		// the path does not exist yet, and it hands over the PARENT, which is the
		// only thing that can answer where a file that does not exist may be made.
		//
		// Two scenarios in `designs/create.feature` were @todo against this hole and
		// one of them was tagged as though the code existed.
		$server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 10);
	}

	/**
	 * Refuse a MOVE the rules refuse, in words the person can read.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 * @return bool always true — this handler either throws or hands the request on
	 *
	 * The rules are {@see MoveRules}, shared with the listener rather than restated, so
	 * the two answers cannot drift apart. All this adds is the translation between
	 * Sabre's `files/<uid>/<relative>` and the node the rules want.
	 *
	 * FAIL OPEN, AS EVERYWHERE IN THIS PLUGIN. A source that cannot be resolved or a
	 * destination Sabre cannot place leaves the move alone — the listener is still
	 * behind it, so the worst case is the refusal this app has always made, silently,
	 * rather than a move that should have been refused slipping through.
	 */
	public function onMove(RequestInterface $request, ResponseInterface $response): bool {
		$destination = $request->getHeader('Destination');
		if ($destination === null || $destination === '' || $this->server === null) {
			return true;
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return true;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$source = $userFolder->get($this->relativeTo($request->getPath()));
			// THE DESTINATION DOES NOT EXIST YET, so what the rules are handed is the
			// folder it is binding INTO — which is what they distil a target down to
			// anyway ({@see MoveRules::refusalForLandingIn}).
			$targetRelative = $this->relativeTo($this->server->calculateUri($destination));
			$parentPath = dirname($targetRelative);
			$targetParent = $parentPath === '.' || $parentPath === '' ? $userFolder : $userFolder->get($parentPath);
		} catch (\Throwable) {
			return true;
		}

		// THE NAME AS WELL AS THE PARENT. A rename is a MOVE to a sibling path, so
		// without the destination's name the rules cannot tell the two apart and a
		// link rename reads as a move that changed nothing.
		$refusal = $this->rules->refusalForLandingIn($source, $targetParent, basename($targetRelative));
		if ($refusal === null) {
			return true;
		}

		$this->logger->warning('penpot_sync: refused a WebDAV move', [
			'app' => Application::APP_ID,
			'from' => $request->getPath(),
		]);

		throw new Forbidden($refusal);
	}

	/**
	 * Refuse a DELETE inside a `link` mapping, in words the person can read.
	 *
	 * ONE RULE FOR THE WHOLE SUBTREE, and that is the difference from
	 * {@see onMove()}. A move asks about the node and where it is going; a delete
	 * only has the node, and under a link mapping the answer is the same whatever
	 * it is — a file, a project folder, or a plain folder holding either. The tree
	 * belongs to Penpot and Nextcloud is a read-only mirror of it, so the question
	 * this asks is simply *which mapping is this under*.
	 *
	 * FAIL OPEN, as everywhere in this plugin: a node that cannot be resolved
	 * leaves the delete alone.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 * @return bool always true — this handler either throws or hands the request on
	 */
	public function onDelete(RequestInterface $request, ResponseInterface $response): bool {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return true;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($this->relativeTo($request->getPath()));
		} catch (\Throwable) {
			return true;
		}

		$refusal = $this->rules->refusalForDeleting($node);
		if ($refusal === null) {
			return true;
		}

		$this->logger->warning('penpot_sync: refused a WebDAV delete', [
			'app' => Application::APP_ID,
			'path' => $request->getPath(),
		]);

		throw new Forbidden($refusal);
	}

	/**
	 * Refuse a NEW `.penpot` the rules will not let exist there.
	 *
	 * ## WHY THE BODY IS READ FROM A HEADER AND NOT FROM `$data`
	 *
	 * `$data` is the request stream. Reading it here to see whether it is empty
	 * would consume the bytes Sabre is about to hand to the storage, so the one
	 * safe way to ask "is this a create or an upload?" before anything is written
	 * is `Content-Length` — which is exactly the distinction
	 * {@see \OCA\PenpotSync\Service\CreationService} draws one layer down, from the
	 * node's size. Same question, same answer, asked early enough to refuse.
	 *
	 * A CHUNKED PUT SENDS NO `Content-Length`, and that reads as non-empty here,
	 * which is the right way round: an upload big enough to be chunked is
	 * self-evidently not "+ New → Penpot design", and the link rule above does not
	 * consult the body at all.
	 *
	 * FAIL OPEN, as everywhere in this plugin.
	 *
	 * @param mixed $data unused; reading it would consume the upload stream
	 * @param bool|null $modified
	 */
	public function beforeCreateFile(string $path, &$data, INode $parentNode, &$modified): bool {
		$name = basename($path);
		if (!str_ends_with($name, PullService::EXTENSION)) {
			return true;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '' || $this->server === null) {
			return true;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$parentPath = dirname($this->relativeTo($path));
			$parent = $parentPath === '.' || $parentPath === '' ? $userFolder : $userFolder->get($parentPath);
		} catch (\Throwable) {
			return true;
		}

		$length = $this->server->httpRequest->getHeader('Content-Length');
		$refusal = $this->rules->refusalForCreating($parent, $name, $length === '0');
		if ($refusal === null) {
			return true;
		}

		$this->logger->warning('penpot_sync: refused a WebDAV create', [
			'app' => Application::APP_ID,
			'path' => $path,
		]);

		throw new Forbidden($refusal);
	}

	/** `files/<uid>/<relative>` as Sabre spells it → the `<relative>` the app works in. */
	private function relativeTo(string $davPath): string {
		$relative = preg_replace('#^files/[^/]+/#', '', ltrim($davPath, '/'));

		return is_string($relative) ? $relative : '';
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
