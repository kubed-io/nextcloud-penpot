<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\ImportService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PersonalTokenService;
use OCA\PenpotSync\Service\SyncNotifier;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What an import STAMPS on the file, which is the half every caller inherits.
 *
 * Five classes reach {@see ImportService::adopt()} — a create, a copy, two move
 * paths and now a restore — and each of them mocks this service in its own tests.
 * So the metadata write below is covered nowhere else, and it is the part with a
 * trap in it: {@see PenpotMetadata::writeFile()} leaves OMITTED keys alone, so a
 * key that needs clearing has to be named explicitly.
 */
final class ImportServiceTest extends TestCase {
	private const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const PROJECT = 'df59d46b-a997-80d9-8008-6452575b0a69';
	private const NEW_ID = 'a3fd10a0-fb1c-8118-8008-7c30b6d9a6d2';
	private const FILE_ID = 4242;

	/**
	 * THE STALE REVISION, WHICH USED TO SURVIVE THE NEW ID (raised in review on #46).
	 *
	 * Every file that reaches an import can already be carrying a `penpot_revision`:
	 * a mirror whose design was destroyed and is being restored, or one that left
	 * its mapping and came back. The import mints a NEW design id (§6.20 — Penpot
	 * cannot resurrect the old one), and a revision belonging to the dead design
	 * stamped beside it is a claim about a pairing that never existed.
	 *
	 * Empty is the honest value — nothing has been exported for THIS design yet —
	 * and it is also what makes the next pull's `driftedOrMissing()` export and
	 * stamp the real one. Left alone, the file could claim to be current at a
	 * revision the new design has never had.
	 */
	public function testTheStampClearsTheRevisionThatBelongedToTheOldDesign(): void {
		$metadata = $this->createMock(PenpotMetadata::class);

		$metadata->expects(self::once())->method('writeFile')
			->with(self::FILE_ID, [
				PenpotMetadata::KEY_ID => self::NEW_ID,
				PenpotMetadata::KEY_REVISION => '',
				PenpotMetadata::KEY_MODE => Mapping::MODE_SYNC,
				PenpotMetadata::KEY_TEAM_ID => self::TEAM,
			]);

		$this->service($metadata)->adopt($this->archiveFile(), self::PROJECT, self::TEAM);
	}

	/** A file with no archive in it is not an import, and is never stamped. */
	public function testAFileThatHoldsNoArchiveIsLeftUntracked(): void {
		$metadata = $this->createMock(PenpotMetadata::class);
		$archives = $this->createStub(ArchiveService::class);
		$archives->method('holdsArchive')->willReturn(false);

		$metadata->expects(self::never())->method('writeFile');

		self::assertNull(
			$this->service($metadata, $archives)->adopt($this->archiveFile(), self::PROJECT, self::TEAM),
		);
	}

	private function service(PenpotMetadata $metadata, ?ArchiveService $archives = null): ImportService {
		$client = $this->createStub(PenpotClient::class);
		$client->method('importBinfile')->willReturn(self::NEW_ID);

		if ($archives === null) {
			$archives = $this->createStub(ArchiveService::class);
			$archives->method('holdsArchive')->willReturn(true);
		}

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('getByTeamId')->willReturn(Mapping::fromArray([
			'team_id' => self::TEAM,
			'team_name' => 'North Wind',
			'nc_folder' => 'Penpot',
			'use_team_folder' => false,
			'mode' => Mapping::MODE_SYNC,
		]));

		return new ImportService(
			$client,
			$metadata,
			$archives,
			$mappings,
			$this->createStub(PersonalTokenService::class),
			$this->createStub(SyncNotifier::class),
			new NullLogger(),
		);
	}

	/**
	 * A node whose `fopen()` hands back a real stream — the client is stubbed, so
	 * the bytes are never read, but `adopt()` checks it is a resource before it will
	 * send anything.
	 */
	private function archiveFile(): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(self::FILE_ID);
		$node->method('getName')->willReturn('Restored.penpot');
		$node->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'rb+'));

		return $node;
	}
}
