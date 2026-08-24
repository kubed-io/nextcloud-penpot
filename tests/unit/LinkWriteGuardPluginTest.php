<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\PenpotSync\DAV\LinkWriteGuardPlugin;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\MoveRules;
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Unit tests for {@see LinkWriteGuardPlugin} — the two refusals a person can read.
 *
 * The plugin answers on two hooks and they guard different things. `beforeWriteContent`
 * is about CONTENT: a link is read-only on disk. `method:MOVE` is about the reason
 * travelling: the rules it enforces belong to {@see MoveRules} and are enforced on every
 * route by {@see \OCA\PenpotSync\Listener\MoveGuardListener} — but only here does the
 * message survive the trip to the client.
 *
 * The load-bearing rule: a `link`-mode design file refuses a WebDAV write with a
 * Sabre {@see Forbidden} (a 403), while every other state — sync, unmapped, and a
 * `.penpot` this app has never heard of — stays writable. Anything that cannot be
 * classified is never blocked; the failure this guard prevents is a write that
 * gets silently discarded, which is not worth risking an unwritable folder over.
 *
 * Ported from the sibling apps' identical suite. Collaborators are `final`, so
 * they are doubled via the unit bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(LinkWriteGuardPlugin::class)]
final class LinkWriteGuardPluginTest extends TestCase {
	private PenpotMetadata $metadata;
	private LinkWriteGuardPlugin $plugin;

	protected function setUp(): void {
		$this->metadata = $this->createMock(PenpotMetadata::class);
		$this->plugin = new LinkWriteGuardPlugin(
			$this->metadata,
			new MoveRules(
				$this->metadata,
				$this->createStub(MembershipResolver::class),
				$this->createStub(MappingService::class),
				$this->createStub(IL10N::class),
			),
			$this->createStub(IRootFolder::class),
			$this->createStub(IUserSession::class),
			new NullLogger(),
		);
	}

	private function davFile(string $name = 'Login screen.penpot', int $id = 7): DavFile {
		$node = $this->createMock(DavFile::class);
		$node->method('getName')->willReturn($name);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function fire(INode $node): bool {
		$data = 'PK' . "\x03\x04"; // a plausible ZIP header — the bytes never matter
		$modified = false;
		return $this->plugin->beforeWriteContent('files/Login screen.penpot', $node, $data, $modified);
	}

	public function testBlocksWritingToALinkFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', Mapping::MODE_LINK, 't1'));

		$this->expectException(Forbidden::class);
		$this->fire($this->davFile());
	}

	/** The refusal names the file, so a desktop client's error is actionable. */
	public function testTheRefusalNamesTheFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', Mapping::MODE_LINK, 't1'));

		try {
			$this->fire($this->davFile('Home page.penpot'));
			self::fail('expected the write to be refused');
		} catch (Forbidden $e) {
			self::assertStringContainsString('Home page.penpot', $e->getMessage());
		}
	}

	public function testAllowsWritingToASyncFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', Mapping::MODE_SYNC, 't1'));

		self::assertTrue($this->fire($this->davFile()));
	}

	public function testAllowsWritingToAnUnmappedFile(): void {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', PenpotMetadata::MODE_UNMAPPED, 't1'));

		self::assertTrue($this->fire($this->davFile()));
	}

	public function testIgnoresFilesThatAreNotDesigns(): void {
		$this->metadata->expects(self::never())->method('readFile');

		self::assertTrue($this->fire($this->davFile('notes.txt')));
	}

	public function testIgnoresNonFileNodes(): void {
		$this->metadata->expects(self::never())->method('readFile');

		$node = $this->createMock(INode::class);
		$node->method('getName')->willReturn('Login screen.penpot');
		self::assertTrue($this->fire($node));
	}

	/** An untracked `.penpot` is the user's own file and none of our business. */
	public function testFailsOpenForAnUntrackedFile(): void {
		$this->metadata->method('readFile')->willReturn(null);

		self::assertTrue($this->fire($this->davFile()));
	}

	public function testFailsOpenWhenTheMetadataReadThrows(): void {
		$this->metadata->method('readFile')->willThrowException(new \RuntimeException('db down'));

		self::assertTrue($this->fire($this->davFile()));
	}

	// ── the refusal a person can read ───────────────────────────────────────

	/**
	 * THE REASON HAS TO SURVIVE THE TRIP, and this is the only route on which it
	 * does. {@see \OCA\PenpotSync\Listener\MoveGuardListener} stops the same move
	 * on every route, but `HookConnector::rename()` catches its abort and discards
	 * the message, and `Directory::moveInto()` then answers `Forbidden('')` with an
	 * empty string. So this test asserts the MESSAGE, not just the 403: a refusal
	 * with an empty body is the failure the whole handler exists to fix.
	 */
	public function testARefusedMoveCarriesItsReason(): void {
		$refusal = $this->moveWithRules($this->refusingRules());

		self::assertInstanceOf(Forbidden::class, $refusal);
		self::assertNotSame('', trim($refusal->getMessage()));
		self::assertStringContainsString('Login screen.penpot', $refusal->getMessage());
	}

	/** A move the rules allow is handed straight on, like any other request. */
	public function testAnAllowedMoveIsNotTouched(): void {
		self::assertNull($this->moveWithRules($this->allowingRules()));
	}

	/**
	 * FAIL OPEN: nobody logged in means nothing to resolve a path against. The
	 * listener is still behind this, so the move is refused either way — silently,
	 * as it always was, rather than wrongly.
	 */
	public function testFailsOpenWithNoUserInSession(): void {
		self::assertNull($this->moveWithRules($this->refusingRules(), withUser: false));
	}

	/** Run one MOVE through the plugin; returns the Forbidden it threw, or null. */
	private function moveWithRules(MoveRules $rules, bool $withUser = true): ?Forbidden {
		$source = $this->createStub(File::class);
		$source->method('getName')->willReturn('Login screen.penpot');
		$source->method('getId')->willReturn(7);

		$destination = $this->createStub(Folder::class);
		$destination->method('getId')->willReturn(21);

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			static fn (string $path): Node => str_ends_with($path, '.penpot') ? $source : $destination,
		);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$session = $this->createStub(IUserSession::class);
		if ($withUser) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		}

		$plugin = new LinkWriteGuardPlugin($this->metadata, $rules, $root, $session, new NullLogger());
		$plugin->initialize(new Server());

		$request = $this->createStub(RequestInterface::class);
		$request->method('getPath')->willReturn('files/alice/Team/Project/Login screen.penpot');
		$request->method('getHeader')->willReturn('http://nc/remote.php/dav/files/alice/Elsewhere/Login screen.penpot');

		try {
			$plugin->onMove($request, $this->createStub(ResponseInterface::class));
		} catch (Forbidden $e) {
			return $e;
		}

		return null;
	}

	/** Rules that refuse: the file is a link, and the destination is another project. */
	private function refusingRules(): MoveRules {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', Mapping::MODE_LINK, 't1'));
		$resolver = $this->createStub(MembershipResolver::class);
		// The destination is resolved first (it builds `$to`), the source second.
		$resolver->method('resolve')->willReturnOnConsecutiveCalls(
			new Membership('project-2', 'team-1'),
			new Membership('project-1', 'team-1'),
		);

		return new MoveRules($this->metadata, $resolver, $this->noMappings(), $this->identityTranslator());
	}

	/** Rules that allow: a `sync` file holds a real archive and moves freely. */
	private function allowingRules(): MoveRules {
		$this->metadata->method('readFile')
			->willReturn(new PenpotFileMetadata('f1', 'r1', Mapping::MODE_SYNC, 't1'));

		return new MoveRules(
			$this->metadata,
			$this->createStub(MembershipResolver::class),
			$this->noMappings(),
			$this->identityTranslator(),
		);
	}

	/**
	 * A mapping service that knows nothing, which is what every test in this file
	 * wants: these are the FILE rules (§6.43), and the folder rules the mapping
	 * service feeds are MoveGuardListenerTest's subject.
	 */
	private function noMappings(): MappingService {
		$mappings = $this->createStub(MappingService::class);
		$mappings->method('getByTeamId')->willReturn(null);

		return $mappings;
	}

	private function identityTranslator(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);

		return $l;
	}
}
