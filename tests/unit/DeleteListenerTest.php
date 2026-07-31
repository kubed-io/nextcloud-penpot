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
 * is (`delete.feature`).
 *
 * ## WHY THIS TEST EXISTS AT ALL
 *
 * The service tests cover what each step DOES. This covers whether the right one
 * is chosen — and it exists because the first version got it wrong in a way no
 * service test could see: **Nextcloud renames a node on its way into the trash**,
 * appending the deletion time (`Login.penpot.d1785457295`). The listener checked
 * `str_ends_with($name, '.penpot')`, which is false by the time the purge fires,
 * so it returned early and the design was never destroyed in Penpot.
 *
 * The unit suite was green throughout, because a mocked node never gets renamed
 * — the rename belongs to Nextcloud. Only a real gesture against a real server
 * showed it, which it did on the first run of the integration scenario.
 *
 * Getting this routing backwards is the worst bug available in this app: it
 * would permanently destroy a design on an ordinary delete.
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
	 * THE REGRESSION. A purge sees the deletion-stamped name, and it must still
	 * be recognised as ours.
	 */
	public function testAPurgeIsTheHardStepDespiteTheTrashbinRename(): void {
		$this->deletions->expects($this->once())->method('onPurged');
		$this->deletions->expects($this->never())->method('onTrashed');

		$this->listener->handle($this->deleteOf(
			'Login.penpot.d1785457295',
			'/admin/files_trashbin/files/Login.penpot.d1785457295',
		));
	}

	/** A trashed file of some other type is not ours, stamped name or not. */
	public function testATrashedNonPenpotFileIsIgnored(): void {
		$this->deletions->expects($this->never())->method('onPurged');
		$this->deletions->expects($this->never())->method('onTrashed');

		$this->listener->handle($this->deleteOf(
			'notes.txt.d1785457295',
			'/admin/files_trashbin/files/notes.txt.d1785457295',
		));
	}

	/**
	 * A trash-BYPASSED delete has no soft step at all, so it counts as the purge.
	 * Otherwise turning the Nextcloud trash off would quietly stop deletes
	 * reaching Penpot — except this one arrives at its normal path, so it routes
	 * soft. Pinned as the KNOWN limitation it is, not as correct behaviour.
	 */
	public function testATrashBypassedDeleteCurrentlyRoutesSoft(): void {
		$this->deletions->expects($this->once())->method('onTrashed');

		$this->listener->handle($this->deleteOf('Login.penpot', '/admin/files/Penpot/Login.penpot'));
	}

	public function testAFolderIsNeverRouted(): void {
		$this->deletions->expects($this->never())->method('onTrashed');
		$this->deletions->expects($this->never())->method('onPurged');

		$folder = $this->createMock(Folder::class);
		$event = $this->createMock(BeforeNodeDeletedEvent::class);
		$event->method('getNode')->willReturn($folder);

		$this->listener->handle($event);
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
