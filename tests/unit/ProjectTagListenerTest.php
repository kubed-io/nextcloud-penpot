<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Listener\ProjectTagListener;
use OCA\PenpotSync\Service\ProjectFolderService;
use OCA\PenpotSync\Service\ProjectTags;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\TagAssignedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routing half of the opt-in (`create-project.feature`).
 *
 * Small on purpose — {@see ProjectFolderServiceTest} covers what tagging DOES.
 * What only the listener can get wrong is which events it acts on at all: a tag
 * that is not ours, an object that is not a folder, an object type that is not
 * `files`, and the app's own tagging during a pull.
 */
final class ProjectTagListenerTest extends TestCase {
	private ProjectFolderService $projects;
	private ProjectTags $tags;
	private IRootFolder $rootFolder;
	private Folder $userFolder;
	private IUserSession $session;
	private StorageService $storage;
	private SyncGuard $guard;
	private ProjectTagListener $listener;

	/** Whose home the listener resolved the tagged ids in. */
	private string $homeAskedFor = '';

	protected function setUp(): void {
		parent::setUp();
		$this->projects = $this->createMock(ProjectFolderService::class);
		$this->tags = $this->createMock(ProjectTags::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->guard = new SyncGuard();

		// Recorded rather than `expects()`d, so a test can assert WHICH home was
		// asked for without re-stubbing a method the setUp already configured —
		// a double-stubbed mock is how a fixture starts returning null quietly.
		$this->rootFolder->method('getUserFolder')->willReturnCallback(
			function (string $uid): Folder {
				$this->homeAskedFor = $uid;

				return $this->userFolder;
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('drk');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);

		$this->storage = $this->createMock(StorageService::class);

		$this->listener = new ProjectTagListener(
			$this->projects,
			$this->tags,
			$this->rootFolder,
			$this->session,
			$this->storage,
			$this->guard,
			new NullLogger(),
		);
	}

	public function testATaggedFolderIsRouted(): void {
		$folder = $this->createMock(Folder::class);
		$this->tags->method('includedIn')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->willReturn($folder);

		$this->projects->expects($this->once())->method('onTagged')->with($folder);

		$this->listener->handle($this->assignment());
	}

	/** Users tag things for their own reasons. Only ours means anything here. */
	public function testAnotherTagIsIgnored(): void {
		$this->tags->method('includedIn')->willReturn(false);

		$this->projects->expects($this->never())->method('onTagged');

		$this->listener->handle($this->assignment());
	}

	/** The tag on a FILE means nothing to this app — only folders are projects. */
	public function testTheTagOnAFileIsIgnored(): void {
		$this->tags->method('includedIn')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->willReturn($this->createMock(File::class));

		$this->projects->expects($this->never())->method('onTagged');

		$this->listener->handle($this->assignment());
	}

	/**
	 * THE PULL TAGS FOLDERS TOO. Without this the pull's own marking would come
	 * straight back asking to create a project for a folder that already is one.
	 */
	public function testTheAppsOwnTaggingIsIgnored(): void {
		$this->tags->method('includedIn')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->willReturn($this->createMock(Folder::class));

		$this->projects->expects($this->never())->method('onTagged');

		$this->guard->run(function (): void {
			$this->listener->handle($this->assignment());
		});
	}

	/**
	 * `occ tag:files:add` fires the same event with NO session. Giving up there
	 * would make the CLI channel silently inert — the tag lands, nothing happens,
	 * nothing says why — so the sync actor's home is used instead.
	 */
	public function testATagAssignedWithNoSessionFallsBackToTheSyncActor(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->storage->method('resolveActorUid')->willReturn('admin');

		$listener = new ProjectTagListener(
			$this->projects,
			$this->tags,
			$this->rootFolder,
			$session,
			$this->storage,
			$this->guard,
			new NullLogger(),
		);

		$folder = $this->createMock(Folder::class);
		$this->tags->method('includedIn')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->willReturn($folder);

		$this->projects->expects($this->once())->method('onTagged')->with($folder);

		$listener->handle($this->assignment());

		self::assertSame('admin', $this->homeAskedFor);
	}

	/** With a session it is the acting user's own mount, so their permissions apply. */
	public function testATagAssignedInASessionResolvesInThatUsersHome(): void {
		$this->tags->method('includedIn')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->willReturn($this->createMock(Folder::class));

		$this->listener->handle($this->assignment());

		self::assertSame('drk', $this->homeAskedFor);
	}

	/** Tags land on comments and other object types; only `files` are nodes. */
	public function testANonFileObjectTypeIsIgnored(): void {
		$this->projects->expects($this->never())->method('onTagged');

		$this->listener->handle(new TagAssignedEvent('comments', ['50'], [1]));
	}

	private function assignment(): TagAssignedEvent {
		return new TagAssignedEvent('files', ['50'], [1]);
	}
}
