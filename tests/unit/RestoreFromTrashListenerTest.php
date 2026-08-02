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
use OCP\IUser;
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
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private RestoreFromTrashListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->restores = $this->createMock(RestoreService::class);
		$this->guard = new SyncGuard();
		// Only the Team Folder path uses these two: that hook hands over a PATH
		// rather than a node, so it has to resolve one (saga §C6.27). The
		// typed-event tests leave them unconfigured, which is why postRestore()
		// must bail safely on an unresolvable user or path.
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->listener = new RestoreFromTrashListener(
			$this->restores,
			$this->guard,
			$this->rootFolder,
			$this->userSession,
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

		// The hook path must actually REACH the guard, or this test would pass
		// without it — postRestore() bails early on an unresolvable user or path,
		// so a bare stub would prove nothing. Wire the resolution through.
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alex');
		$this->userSession->method('getUser')->willReturn($user);
		$home = $this->createStub(Folder::class);
		$home->method('get')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('alex')->willReturn($home);

		$this->restores->expects($this->once())->method('onRestored')->with($node);

		// The stub NodeRestoredEvent's accessors throw by design, so the event is
		// mocked here exactly as the tests above do it.
		$event = $this->createMock(NodeRestoredEvent::class);
		$event->method('getTarget')->willReturn($node);

		// typed event first…
		$this->listener->handle($event);
		// …then the legacy hook for the SAME file, which must be a no-op.
		$this->listener->postRestore(['filePath' => '/Penpot/Project/Design.penpot']);
	}

	/**
	 * The other order, because which door opens first is not ours to rely on.
	 */
	public function testTheGuardHoldsWhicheverDoorOpensFirst(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(4242);
		$node->method('getName')->willReturn('Design.penpot');

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alex');
		$this->userSession->method('getUser')->willReturn($user);
		$home = $this->createStub(Folder::class);
		$home->method('get')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->willReturn($home);

		$this->restores->expects($this->once())->method('onRestored')->with($node);

		$event = $this->createMock(NodeRestoredEvent::class);
		$event->method('getTarget')->willReturn($node);

		$this->listener->postRestore(['filePath' => '/Penpot/Project/Design.penpot']);
		$this->listener->handle($event);
	}
}
