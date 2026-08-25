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

	// ── method:DELETE, the verb the guard had never covered ─────────────────

	/**
	 * A DELETE inside a `link` mapping is refused, WITH ITS REASON.
	 *
	 * This handler did not exist. `beforeWriteContent` catches PUT and Sabre runs
	 * COPY through it per file, so a link could not be written or duplicated —
	 * but DELETE reached nothing at all and trashing a link project was a plain
	 * 204. A read-only mirror you can delete is not read-only.
	 */
	public function testARefusedDeleteCarriesItsReason(): void {
		$refusal = $this->deleteWithTeam('team-link', linkTeam: 'team-link');

		self::assertInstanceOf(Forbidden::class, $refusal);
		self::assertNotSame('', trim($refusal->getMessage()));
		self::assertStringContainsString('Existing', $refusal->getMessage());
	}

	/**
	 * A DELETE under a `sync` mapping is handed straight on — it goes to Penpot's
	 * trash and comes back (§C6.11), so refusing it would break the ordinary case.
	 */
	public function testADeleteUnderASyncMappingIsNotTouched(): void {
		self::assertNull($this->deleteWithTeam('team-sync', linkTeam: 'team-link'));
	}

	/** FAIL OPEN, as everywhere in this plugin. */
	public function testADeleteFailsOpenWithNoUserInSession(): void {
		self::assertNull($this->deleteWithTeam('team-link', linkTeam: 'team-link', withUser: false));
	}

	// ── beforeCreateFile: where a NEW design may be authored (§6.34, §6.44) ──

	/**
	 * A link mapping is filled FROM Penpot and nothing may be added from this side.
	 *
	 * THE HOLE THIS CLOSES IS THE ONE `beforeWriteContent` CANNOT SEE. That hook
	 * classifies from the file's own metadata, and a file that does not exist yet
	 * has none — so it read as "not a link" and the write went through. Two
	 * scenarios in `designs/create.feature` were tagged against this, and one of
	 * them claimed the code already existed.
	 */
	public function testRefusesANewDesignInALinkMapping(): void {
		$refusal = $this->createIn('team-link', linkTeam: 'team-link', length: '0');

		self::assertInstanceOf(Forbidden::class, $refusal);
		self::assertStringContainsString('New design.penpot', $refusal->getMessage());
	}

	/** Whatever is arriving — an upload is refused there too, not only a create. */
	public function testRefusesAnUploadedArchiveIntoALinkMapping(): void {
		self::assertInstanceOf(
			Forbidden::class,
			$this->createIn('team-link', linkTeam: 'team-link', length: '2048'),
		);
	}

	/**
	 * Outside every mapping there is nowhere for a design to become real: Penpot
	 * has no rootless design and `create-file` requires a project (§6.44).
	 */
	public function testRefusesANewDesignOutsideEveryMapping(): void {
		$refusal = $this->createIn(null, linkTeam: 'team-link', length: '0');

		self::assertInstanceOf(Forbidden::class, $refusal);
		self::assertStringContainsString('New design.penpot', $refusal->getMessage());
	}

	/**
	 * …but an ARCHIVE dropped in a plain folder is a file like any other.
	 *
	 * The narrow half of the rule, and the one that keeps Nextcloud being Nextcloud:
	 * the refusal above is about the "+ New" gesture writing zero bytes, not about
	 * `.penpot` being an unwelcome extension. Getting this wrong would make the app
	 * refuse to store a design someone downloaded.
	 */
	public function testAllowsAnUploadedArchiveOutsideEveryMapping(): void {
		self::assertNull($this->createIn(null, linkTeam: 'team-link', length: '4096'));
	}

	/** A chunked PUT sends no Content-Length, and an upload is never the create. */
	public function testAChunkedUploadOutsideEveryMappingIsAllowed(): void {
		self::assertNull($this->createIn(null, linkTeam: 'team-link', length: null));
	}

	/** The ordinary case: a sync mapping's root is that team's Drafts (§6.35). */
	public function testAllowsANewDesignInASyncMapping(): void {
		self::assertNull($this->createIn('team-sync', linkTeam: 'team-link', length: '0'));
	}

	/** Only designs are constrained; the folder is nobody's business otherwise. */
	public function testIgnoresANewFileThatIsNotADesign(): void {
		self::assertNull($this->createIn(null, linkTeam: 'team-link', length: '0', name: 'notes.txt'));
	}

	/** FAIL OPEN, as everywhere in this plugin. */
	public function testACreateFailsOpenWithNoUserInSession(): void {
		self::assertNull($this->createIn('team-link', linkTeam: 'team-link', length: '0', withUser: false));
	}

	/**
	 * Run one create through the plugin; returns the Forbidden it threw, or null.
	 *
	 * $team is what the DESTINATION FOLDER resolves to — null meaning outside every
	 * mapping — and $length is the request's `Content-Length`, which is how the
	 * plugin tells a create from an upload without consuming the body.
	 */
	private function createIn(
		?string $team,
		string $linkTeam,
		?string $length,
		string $name = 'New design.penpot',
		bool $withUser = true,
	): ?Forbidden {
		$parent = $this->createStub(Folder::class);
		$parent->method('getName')->willReturn('Confined');
		$parent->method('getId')->willReturn(7);

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturn($parent);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$session = $this->createStub(IUserSession::class);
		if ($withUser) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		}

		$resolver = $this->createStub(MembershipResolver::class);
		$resolver->method('resolve')->willReturn(new Membership(null, $team));

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('getByTeamId')->willReturnCallback(
			static fn (string $id): Mapping => new Mapping(
				'm1',
				$id,
				'A Team',
				'Folder',
				false,
				$id === $linkTeam ? Mapping::MODE_LINK : Mapping::MODE_SYNC,
			),
		);

		$rules = new MoveRules($this->metadata, $resolver, $mappings, $this->identityTranslator());
		$plugin = new LinkWriteGuardPlugin($this->metadata, $rules, $root, $session, new NullLogger());

		// A STUBBED RequestInterface, not a real `Sabre\HTTP\Request`: the unit
		// bootstrap provides the OCP and Sabre INTERFACES, not the HTTP package, so
		// constructing one errors with "Class Sabre\HTTP\Request not found" — which
		// is what eight of these tests did on their first CI run.
		$request = $this->createStub(RequestInterface::class);
		$request->method('getHeader')->willReturn($length);

		$server = new Server();
		$server->httpRequest = $request;
		$plugin->initialize($server);

		$data = null;
		$modified = false;
		try {
			$plugin->beforeCreateFile(
				'files/alice/Pointers/Confined/' . $name,
				$data,
				$this->createStub(INode::class),
				$modified,
			);
		} catch (Forbidden $e) {
			return $e;
		}

		return null;
	}

	/**
	 * Run one DELETE through the plugin; returns the Forbidden it threw, or null.
	 *
	 * $team is what the node resolves to; $linkTeam is the one mapped in `link`
	 * mode. Passing the same value for both is a delete inside a link mapping.
	 */
	private function deleteWithTeam(string $team, string $linkTeam, bool $withUser = true): ?Forbidden {
		$node = $this->createStub(Folder::class);
		$node->method('getName')->willReturn('Existing');
		$node->method('getId')->willReturn(7);

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturn($node);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$session = $this->createStub(IUserSession::class);
		if ($withUser) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		}

		$resolver = $this->createStub(MembershipResolver::class);
		$resolver->method('resolve')->willReturn(new Membership(null, $team));

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('getByTeamId')->willReturnCallback(
			static fn (string $id): Mapping => new Mapping(
				'm1',
				$id,
				'A Team',
				'Folder',
				false,
				$id === $linkTeam ? Mapping::MODE_LINK : Mapping::MODE_SYNC,
			),
		);

		$rules = new MoveRules($this->metadata, $resolver, $mappings, $this->identityTranslator());
		$plugin = new LinkWriteGuardPlugin($this->metadata, $rules, $root, $session, new NullLogger());
		$plugin->initialize(new Server());

		$request = $this->createStub(RequestInterface::class);
		$request->method('getPath')->willReturn('files/alice/Pointers/Existing');

		try {
			$plugin->onDelete($request, $this->createStub(ResponseInterface::class));
		} catch (Forbidden $e) {
			return $e;
		}

		return null;
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
