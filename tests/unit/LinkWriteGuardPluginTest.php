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
use OCA\PenpotSync\Service\PenpotFileMetadata;
use OCA\PenpotSync\Service\PenpotMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;

/**
 * Unit tests for {@see LinkWriteGuardPlugin} — a link is read-only on disk.
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
		$this->plugin = new LinkWriteGuardPlugin($this->metadata, new NullLogger());
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
}
