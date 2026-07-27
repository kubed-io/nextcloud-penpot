<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\PushService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The rename writeback (saga Ch2 Course 4). These pin the decision logic the
 * listener leans on and the integration suite only spot-checks:
 *
 *   - which node types are one of ours to push (`.penpot` file, project folder)
 *     and which are ignored (plain file, unmanaged `.penpot`, team root);
 *   - the `.penpot` extension is stripped before it reaches Penpot (§6.4), and
 *     an empty stem is refused rather than sent;
 *   - attribution: the acting user's personal token rides the call when set,
 *     the service account (null) otherwise (§6.18).
 *
 * The real Penpot API and filesystem are mocked — the wire format is the
 * integration suite's job.
 */
final class PushServiceTest extends TestCase {
	private const FILE_ID = '61d8ecb9-c430-8120-8008-6225c5b12134';
	private const PROJECT_ID = '4eda2e11-843e-8045-8008-51824bdafd88';

	private PenpotClient $client;
	private PenpotMetadata $metadata;
	private PersonalTokenService $personalTokens;
	private IUserSession $userSession;
	private PushService $push;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->personalTokens = $this->createMock(PersonalTokenService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->push = new PushService(
			$this->client,
			$this->metadata,
			$this->personalTokens,
			$this->userSession,
			new NullLogger(),
		);
	}

	public function testRenamesAManagedPenpotFileByStrippingTheExtension(): void {
		$this->signedInAs('kelly', personalToken: null);
		$this->metadata->method('readFile')->with(30)
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));

		// The bare stem reaches Penpot, never the `.penpot` affordance; no token → service account.
		$this->client->expects($this->once())->method('renameFile')
			->with(self::FILE_ID, 'Login screen', null);

		self::assertTrue($this->push->pushRename($this->file(30, 'Login screen.penpot')));
	}

	public function testAttributesToThePersonalTokenWhenTheUserHasOne(): void {
		$this->signedInAs('kelly', personalToken: 'kelly-token');
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));

		$this->client->expects($this->once())->method('renameFile')
			->with(self::FILE_ID, 'Login screen', 'kelly-token');

		$this->push->pushRename($this->file(30, 'Login screen.penpot'));
	}

	public function testIgnoresAFileThatIsNotAPenpotMirror(): void {
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, 'notes.txt')));
	}

	public function testIgnoresAnUnmanagedPenpotFile(): void {
		$this->metadata->method('readFile')->willReturn(null);
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, 'hand-made.penpot')));
	}

	public function testRefusesAnEmptyPenpotName(): void {
		// A file literally named ".penpot" has no stem — not a legal Penpot name.
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata(self::FILE_ID, '5@x', 'link'));
		$this->client->expects($this->never())->method('renameFile');

		self::assertFalse($this->push->pushRename($this->file(30, '.penpot')));
	}

	public function testRenamesAProjectFolder(): void {
		$this->signedInAs('kelly', personalToken: null);
		$this->metadata->method('readFolder')->with(20)
			->willReturn(new FolderMarkers(self::PROJECT_ID, ''));

		$this->client->expects($this->once())->method('renameProject')
			->with(self::PROJECT_ID, 'Marketing', null);

		self::assertTrue($this->push->pushRename($this->folder(20, 'Marketing')));
	}

	public function testIgnoresTheTeamRootFolder(): void {
		// The root carries only a team id — renaming it is not a project rename.
		$this->metadata->method('readFolder')
			->willReturn(new FolderMarkers('', 'team-abc'));
		$this->client->expects($this->never())->method('renameProject');

		self::assertFalse($this->push->pushRename($this->folder(10, 'Design Files')));
	}

	private function signedInAs(string $uid, ?string $personalToken): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->personalTokens->method('tokenFor')->with($uid)->willReturn($personalToken);
	}

	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function folder(int $id, string $name): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn($name);
		return $folder;
	}
}
