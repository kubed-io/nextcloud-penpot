<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\Mapping;
use PHPUnit\Framework\TestCase;

/**
 * The mapping value object's invariants.
 *
 * These are pure-function tests with no collaborators, which is exactly why they
 * are worth having: {@see Mapping::fromArray()} is the single gate every mapping
 * passes through — from `occ`, from the settings controller, and from stored
 * JSON on every read — so a hole here is a hole in all three.
 */
final class MappingTest extends TestCase {
	private const TEAM_ID = '4eda2e11-843e-8045-8008-51824bda07a1';

	public function testDefaultsToLinkAndNested(): void {
		$mapping = Mapping::fromArray(['team_id' => self::TEAM_ID]);

		// Both defaults are the conservative choice: `link` downloads nothing,
		// and `nested` is the only folder model that is actually implemented.
		self::assertSame(Mapping::MODE_LINK, $mapping->mode);
		self::assertSame(Mapping::FOLDER_MODE_NESTED, $mapping->folderMode);
	}

	public function testGeneratesAnIdWhenNoneGiven(): void {
		$a = Mapping::fromArray(['team_id' => self::TEAM_ID]);
		$b = Mapping::fromArray(['team_id' => self::TEAM_ID]);

		self::assertNotSame('', $a->id);
		self::assertNotSame($a->id, $b->id, 'each mapping needs its own local id');
	}

	public function testKeepsAnExplicitId(): void {
		// Round-tripping a stored row must not renumber it, or every read would
		// invent new ids and `remove-mapping <id>` would stop working.
		$mapping = Mapping::fromArray(['id' => 'abc123', 'team_id' => self::TEAM_ID]);

		self::assertSame('abc123', $mapping->id);
	}

	public function testRequiresATeamId(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('team_id is required');

		Mapping::fromArray([]);
	}

	/**
	 * A non-UUID team id is caught here rather than becoming a puzzling 404 from
	 * Penpot several layers later.
	 */
	public function testRejectsATeamIdThatIsNotAUuid(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('must be a UUID');

		Mapping::fromArray(['team_id' => 'Northwind']);
	}

	/**
	 * Penpot's own ids do not all carry a conventional RFC-4122 version nibble —
	 * this is a real id from the live instance. A stricter check would reject
	 * real teams, so the validator is deliberately shape-only.
	 */
	public function testAcceptsPenpotsNonRfcUuidShape(): void {
		$mapping = Mapping::fromArray(['team_id' => '3fc1681a-2199-8124-8008-635c7fbaa6d5']);

		self::assertSame('3fc1681a-2199-8124-8008-635c7fbaa6d5', $mapping->teamId);
	}

	public function testRejectsAnUnknownMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('mode must be');

		Mapping::fromArray(['team_id' => self::TEAM_ID, 'mode' => 'backup']);
	}

	public function testRejectsAnUnknownFolderMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('folder_mode must be');

		Mapping::fromArray(['team_id' => self::TEAM_ID, 'folder_mode' => 'flat']);
	}

	/**
	 * `keyed` must PARSE even though it is not implemented — the value object's
	 * job is round-tripping stored data. Refusing to *create* one is
	 * MappingService's job, and separating the two is what lets a stored keyed
	 * row still be listed and removed rather than silently vanishing from the
	 * admin page.
	 */
	public function testParsesKeyedFolderModeEvenThoughItIsNotImplemented(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'folder_mode' => Mapping::FOLDER_MODE_KEYED,
		]);

		self::assertSame(Mapping::FOLDER_MODE_KEYED, $mapping->folderMode);
	}

	/**
	 * The Grafana-parity rule: a blank folder name is NOT an error — it defaults
	 * to the source object's name. Here that resolution happens at the model when
	 * a team name is already known, and in MappingService when it is not (because
	 * the name comes back from Penpot).
	 */
	public function testFolderNameDefaultsToTheTeamName(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Northwind',
		]);

		self::assertSame('Northwind', $mapping->ncFolder);
	}

	public function testAnExplicitFolderNameWins(): void {
		// The destination is the ADMIN's to name; the team name is only the
		// default. (Project folders inside it are a different rule entirely —
		// those are always Penpot's, §6.36.)
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Northwind',
			'nc_folder' => 'Design Files',
		]);

		self::assertSame('Design Files', $mapping->ncFolder);
	}

	public function testStripsSurroundingSlashesFromTheFolderName(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => '/Designs/',
		]);

		self::assertSame('Designs', $mapping->ncFolder);
	}

	/**
	 * A path would invent an intermediate folder that no Penpot object
	 * corresponds to — and that nothing would ever clean up.
	 */
	public function testRejectsAFolderPath(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('single folder name');

		Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'teams/design',
		]);
	}

	public function testDefaultsToATeamFolderWithNoGroups(): void {
		$mapping = Mapping::fromArray(['team_id' => self::TEAM_ID]);

		// Matches both siblings: groupfolders is the preferred backend, so an
		// omitted flag means "use a Team Folder".
		self::assertTrue($mapping->useTeamFolder);
		self::assertSame([], $mapping->ncGroups);
	}

	public function testAcceptsGroupsAsAnArrayOrACommaSeparatedString(): void {
		// The CLI passes a comma-separated string straight through; the settings
		// panel posts an array. Both must land identically.
		$fromArray = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_groups' => ['design', 'admin'],
		]);
		$fromString = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_groups' => 'design, admin',
		]);

		self::assertSame(['design', 'admin'], $fromArray->ncGroups);
		self::assertSame($fromArray->ncGroups, $fromString->ncGroups);
	}

	public function testDeduplicatesAndDropsEmptyGroups(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_groups' => ['design', '', 'design', '  admin  '],
		]);

		self::assertSame(['design', 'admin'], $mapping->ncGroups);
	}

	public function testTeamFolderFlagCanBeTurnedOff(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'use_team_folder' => false,
		]);

		self::assertFalse($mapping->useTeamFolder);
	}

	/**
	 * A Penpot team rename must NOT silently rename the admin's folder — the
	 * folder name is their choice, and the team name was only ever its default.
	 */
	public function testRenamingTheTeamLeavesTheFolderNameAlone(): void {
		$mapping = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Northwind',
		]);

		$renamed = $mapping->withTeamName('Northwind Design');

		self::assertSame('Northwind Design', $renamed->teamName);
		self::assertSame('Northwind', $renamed->ncFolder);
	}

	public function testRoundTripsThroughAnArray(): void {
		$original = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Northwind',
			'mode' => Mapping::MODE_SYNC,
		]);

		self::assertEquals($original, Mapping::fromArray($original->toArray()));
	}

	public function testWithTeamNameReplacesOnlyTheName(): void {
		$original = Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Northwind',
		]);

		$renamed = $original->withTeamName('Northwind Design');

		self::assertSame('Northwind Design', $renamed->teamName);
		self::assertSame($original->id, $renamed->id);
		self::assertSame($original->teamId, $renamed->teamId);
		self::assertSame($original->mode, $renamed->mode);
		self::assertSame($original->folderMode, $renamed->folderMode);
	}

	public function testTrimsWhitespace(): void {
		$mapping = Mapping::fromArray([
			'team_id' => '  ' . self::TEAM_ID . '  ',
			'team_name' => '  Northwind  ',
		]);

		self::assertSame(self::TEAM_ID, $mapping->teamId);
		self::assertSame('Northwind', $mapping->teamName);
	}
}
