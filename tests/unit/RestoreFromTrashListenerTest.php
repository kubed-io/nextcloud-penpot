<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\PenpotSync\Listener\RestoreFromTrashListener;
use OCA\PenpotSync\Service\RestoreService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routing half of restore (`delete-design.feature`).
 *
 * Small on purpose — the service tests cover what a restore DOES, and this
 * covers the two things only the listener can get wrong:
 *
 *   1. **It reads the TARGET, not the source.** The source is the node's path
 *      inside the trash, where the name carries a `.dTIMESTAMP` suffix — so a
 *      listener that reads it fails the `.penpot` extension check and silently
 *      never fires. The target is the node back at its restored path, with the
 *      fileid (and therefore the metadata) it has carried all along.
 *   2. **It stands down under the SyncGuard.** The app takes mirrors out of the
 *      trash itself; its own motion must never be read as a user's gesture.
 */
final class RestoreFromTrashListenerTest extends TestCase {
	private RestoreService $restores;
	private SyncGuard $guard;
	private RestoreFromTrashListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->restores = $this->createMock(RestoreService::class);
		$this->guard = new SyncGuard();
		// The last two are only reached by the Team Folder path, which resolves a
		// PATH rather than receiving a node (saga §C6.27); the typed-event tests
		// below never touch them.
		$this->listener = new RestoreFromTrashListener(
			$this->restores,
			$this->guard,
			$this->createStub(IRootFolder::class),
			$this->createStub(IUserSession::class),
			new NullLogger(),
		);
	}

	public function testARestoredMirrorIsRouted(): void {
		$this->restores->expects($this->once())->method('onRestored');

		$this->listener->handle($this->restoreOf('Login.penpot'));
	}

	/**
	 * THE TRASHED NAME IS NOT THE RESTORED NAME. If this listener ever reads the
	 * source, every restore stops firing — and it stops SILENTLY, which is the
	 * worst kind. Pinned by giving the source a name that fails the check.
	 */
	public function testTheRestoredNameIsReadFromTheTarget(): void {
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('Login.penpot.d1785457295');
		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn('Login.penpot');

		$event = $this->createMock(NodeRestoredEvent::class);
		$event->method('getSource')->willReturn($source);
		$event->method('getTarget')->willReturn($target);

		$this->restores->expects($this->once())->method('onRestored')->with($target);

		$this->listener->handle($event);
	}

	/** The app's own motion (the prune's snapshot dance) is not a user restore. */
	public function testTheAppsOwnMotionIsIgnored(): void {
		$this->restores->expects($this->never())->method('onRestored');

		$this->guard->run(function (): void {
			$this->listener->handle($this->restoreOf('Login.penpot'));
		});
	}

	/** Not a `.penpot` — never ours. */
	public function testANonPenpotFileIsIgnored(): void {
		$this->restores->expects($this->never())->method('onRestored');

		$this->listener->handle($this->restoreOf('notes.txt'));
	}

	public function testAFolderIsNeverRouted(): void {
		$this->restores->expects($this->never())->method('onRestored');

		$event = $this->createMock(NodeRestoredEvent::class);
		$event->method('getTarget')->willReturn($this->createMock(Folder::class));

		$this->listener->handle($event);
	}

	private function restoreOf(string $name): NodeRestoredEvent {
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn($name);

		$event = $this->createMock(NodeRestoredEvent::class);
		$event->method('getTarget')->willReturn($node);

		return $event;
	}

	/**
	 * ONE GESTURE, TWO DOORS, ONE RESTORE.
	 *
	 * On a plain folder files_trashbin dispatches the typed NodeRestoredEvent AND
	 * emits the legacy `post_restore` hook. Connecting the hook for Team Folders
	 * (saga §C6.27) therefore made the plain backend fire twice — which CI caught
	 * as `design/plain` failing while `design/team` passed, the exact inverse of
	 * the failure the hook was added to fix.
	 *
	 * `once()` is the whole assertion.
	 */
	public function testOneRestoreReachesPenpotOnceEvenIfBothDoorsOpen(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(4242);
		$node->method('getName')->willReturn('Design.penpot');

		$this->restores->expects($this->once())->method('onRestored')->with($node);

		// typed event first…
		$this->listener->handle(new NodeRestoredEvent($this->createStub(Folder::class), $node));
		// …then the legacy hook for the same file, which must be a no-op.
		$this->listener->postRestore(['filePath' => '/Penpot/Project/Design.penpot']);
	}
}
