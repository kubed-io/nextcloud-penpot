<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Listener\DeleteListener;
use OCA\PenpotSync\Service\DeletionService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routing half of delete: which of the two steps a `BeforeNodeDeletedEvent`
 * is (`delete-design.feature`).
 *
 * ## WHY THIS TEST EXISTS AT ALL
 *
 * The service tests cover what each step DOES. This covers whether this listener
 * is the right home for it — and it exists because the first version was not.
 *
 * That version handled BOTH steps, discriminating by path, on the strength of a
 * comment in nextcloud-n8n. Nextcloud fires **no typed event at all** for a trash
 * purge; the trashbin emits a legacy `\OCP\Trashbin` `preDelete` hook instead
 * (nextcloud-grafana had this documented as "proven live", and penpot's
 * integration suite confirmed it by failing). The purge now lives in
 * {@see \OCA\PenpotSync\Listener\TrashPurgeHook}, and this class does the soft
 * step only.
 *
 * So the assertions below are mostly about what this listener must NOT touch. A
 * node already in the trash arriving here is either a route we do not know about
 * or a second delete of something already in Penpot's trash — and in both cases
 * acting on it is wrong.
 */
final class DeleteListenerTest extends TestCase {
	private DeletionService $deletions;
	private DeleteListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->deletions = $this->createMock(DeletionService::class);
		$this->listener = new DeleteListener($this->deletions, new SyncGuard(), new NullLogger());
	}

	public function testAnOrdinaryDeleteIsTheSoftStep(): void {
		$this->deletions->expects($this->once())->method('onTrashed');
		$this->deletions->expects($this->never())->method('onPurged');

		$this->listener->handle($this->deleteOf('Login.penpot', '/admin/files/Penpot/Login.penpot'));
	}

	/**
	 * A NODE ALREADY IN THE TRASH IS NOT THIS CLASS'S BUSINESS.
	 *
	 * The purge arrives at TrashPurgeHook, not here. If one reaches this listener
	 * anyway it is a second delete of a design Penpot has already trashed, and
	 * calling `delete-file` again is a wasted round trip at best.
	 */
	public function testANodeAlreadyInTheTrashIsIgnored(): void {
		$this->deletions->expects($this->never())->method('onTrashed');
		$this->deletions->expects($this->never())->method('onPurged');

		$this->listener->handle($this->deleteOf(
			'Login.penpot.d1785457295',
			'/admin/files_trashbin/files/Login.penpot.d1785457295',
		));
	}

	/** Not a `.penpot` at all — never ours, wherever it lives. */
	public function testANonPenpotFileIsIgnored(): void {
		$this->deletions->expects($this->never())->method('onTrashed');
		$this->deletions->expects($this->never())->method('onPurged');

		$this->listener->handle($this->deleteOf('notes.txt', '/admin/files/Penpot/notes.txt'));
	}

	/**
	 * WAS `testAFolderIsNeverRouted`, and the inversion is the point of §C6.38's
	 * follow-up: nothing deleted a project, in any circumstance. This class
	 * returned on anything that was not a `File`, `DeletionService` had no folder
	 * method, and `PenpotClient` had no `delete-project` — while the scenario for
	 * it sat tagged `@todo`, which claims the code exists.
	 */
	public function testAFolderIsRoutedToTheFolderDelete(): void {
		$this->deletions->expects($this->once())->method('onFolderTrashed');
		$this->deletions->expects($this->never())->method('onTrashed');
		$this->deletions->expects($this->never())->method('onPurged');

		$this->listener->handle($this->deleteOfFolder('/admin/files/Penpot/Doomed'));
	}

	/**
	 * A FOLDER ALREADY IN THE TRASH IS NOT THIS CLASS'S BUSINESS EITHER, and the
	 * trashbin check has to come BEFORE the folder branch for that to hold — the
	 * purge route reaches Penpot through its own hook, and routing a trashbin
	 * folder here would delete the projects a second time.
	 */
	public function testAFolderInsideTheTrashbinIsNotRouted(): void {
		$this->deletions->expects($this->never())->method('onFolderTrashed');

		$this->listener->handle($this->deleteOfFolder('/admin/files_trashbin/files/Doomed.d1700000000'));
	}

	/**
	 * A USER FOLDER CANNOT IMPERSONATE THE TRASHBIN.
	 *
	 * The check was `/files_trashbin/`, which is a substring anyone can create: a
	 * folder of that name in a home gives `/alice/files/files_trashbin/…` and every
	 * delete inside it silently stopped reaching Penpot. Core's real path carries
	 * `files/` after the mount, which is what makes it a mount rather than a name.
	 */
	public function testAUserFolderNamedLikeTheTrashbinIsStillRouted(): void {
		$this->deletions->expects($this->once())->method('onFolderTrashed');

		$this->listener->handle($this->deleteOfFolder('/admin/files/files_trashbin/Doomed'));
	}

	public function testAFolderDeleteFailureNeverAbortsTheGesture(): void {
		// This event fires BEFORE the delete, so a throw would cancel a gesture
		// the user has every right to make.
		$this->deletions->method('onFolderTrashed')->willThrowException(new \RuntimeException('penpot said no'));

		$this->listener->handle($this->deleteOfFolder('/admin/files/Penpot/Doomed'));
		$this->addToAssertionCount(1);
	}

	private function deleteOfFolder(string $path): BeforeNodeDeletedEvent {
		$node = $this->createMock(Folder::class);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getPath')->willReturn($path);

		$event = $this->createMock(BeforeNodeDeletedEvent::class);
		$event->method('getNode')->willReturn($node);

		return $event;
	}

	private function deleteOf(string $name, string $path): BeforeNodeDeletedEvent {
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn($path);

		$event = $this->createMock(BeforeNodeDeletedEvent::class);
		$event->method('getNode')->willReturn($node);

		return $event;
	}
}
