<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\ProjectFolderService;
use OCA\PenpotSync\Service\ProjectTags;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\TagAssignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Turns "a user put the `penpot` tag on a folder" into a Penpot project
 * ({@see ProjectFolderService}, `project-folder.feature`).
 *
 * ## THERE IS NO UNASSIGN LISTENER, AND THAT IS THE FEATURE
 *
 * `TagUnassignedEvent` exists and this app deliberately does not subscribe to
 * it. *Untagging is unmapping, not deleting* — the same rule as moving a design
 * out of a mapping (§6.23). Destroying someone's Penpot project, with every
 * design in it, because they took a label off a folder would be the worst
 * surprise this app could produce.
 *
 * Not subscribing is a stronger guarantee than subscribing and returning early:
 * "Penpot is never contacted" is then true by construction rather than by a
 * branch someone could later add an `else` to. The scenario that asserts it has
 * nothing to assert against.
 *
 * ## ONLY FOLDERS, ONLY THE `penpot` TAG
 *
 * A tag lands on files too, and users tag things for their own reasons all over
 * the instance. Everything that is not a folder carrying our one tag name falls
 * straight through.
 *
 * ## TWO CHANNELS, AND THE SECOND ONE HAS NO USER
 *
 * The gesture is a browser assigning a tag, which has a session — the ids are
 * resolved against that user's own mount, so their permissions apply. But
 * `occ tag:files:add` fires the *same* event with **no session at all**, and a
 * listener that gave up there would make the CLI channel silently inert: the tag
 * lands on the folder, nothing happens, and nothing says why. So the fallback is
 * the sync actor's home — the same uid `penpot_sync:status` reads through, which
 * is by definition where the mapped folders live.
 *
 * The attribution follows honestly: with no session
 * {@see \OCA\PenpotSync\Service\PersonalTokenService::tokenForActor()} yields
 * null and the project is created by the service account, which is exactly who
 * asked for it.
 *
 * ## THE PULL TAGS FOLDERS TOO, WHICH IS WHY THE GUARD IS HERE
 *
 * {@see \OCA\PenpotSync\Service\PullService::ensureProjectFolder()} stamps the
 * same tag on every folder it mirrors, so without the guard the pull's own
 * marking would come back through this listener asking to create a project for a
 * folder that already is one. `ProjectFolderService` would refuse it on the
 * project-id check anyway — this is the outer of the two doors, and the cheaper.
 *
 * @implements IEventListener<TagAssignedEvent>
 */
final class ProjectTagListener implements IEventListener {
	public function __construct(
		private ProjectFolderService $projects,
		private ProjectTags $tags,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private StorageService $storage,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof TagAssignedEvent) {
			return;
		}
		if ($event->getObjectType() !== 'files' || $this->guard->active()) {
			return;
		}
		if (!$this->tags->includedIn($event->getTags())) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($this->actingUid());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync project-tag: no home folder to resolve the tagged node in', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return;
		}

		foreach ($event->getObjectIds() as $objectId) {
			try {
				$node = $userFolder->getFirstNodeById((int)$objectId);
			} catch (\Throwable $e) {
				$this->logger->debug('penpot_sync project-tag: could not resolve the tagged node', [
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'exception' => $e,
				]);

				continue;
			}
			if (!$node instanceof Folder) {
				continue; // the tag on a file means nothing to this app
			}

			try {
				$this->projects->onTagged($node);
			} catch (\Throwable $e) {
				// The service handles its own failures; this is here so a bug in
				// that promise cannot surface as a failed tag assignment. The tag
				// stands and the folder is unchanged, which is recoverable.
				$this->logger->warning('penpot_sync project-tag: handling failed; the folder is unchanged', [
					'app' => Application::APP_ID,
					'folder' => $node->getPath(),
					'exception' => $e,
				]);
			}
		}
	}

	/** The session's user when there is one, else the sync actor — see the class docblock. */
	private function actingUid(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';

		return $uid !== '' ? $uid : $this->storage->resolveActorUid();
	}
}
