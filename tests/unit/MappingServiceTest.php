<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\StorageService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The mapping store's rules.
 *
 * The three that matter most — and that would each fail *silently* if wrong —
 * are the §6.18 visibility precondition, the §6.53 folder-mode immutability, and
 * the refusal to create a `keyed` mapping. Each one, if skipped, produces a
 * mapping that looks correct in the admin list and does the wrong thing (or
 * nothing) later.
 *
 * The store is backed by a single JSON appconfig value, so these tests keep a
 * real in-memory string and let the service parse it — testing the persisted
 * shape too, rather than only the object graph.
 */
final class MappingServiceTest extends TestCase {
	private const TEAM_ID = '4eda2e11-843e-8045-8008-51824bda07a1';
	private const OTHER_TEAM_ID = '3fc1681a-2199-8124-8008-635c7fbaa6d5';

	private string $stored = '[]';

	/** @var IAppConfig&Stub */
	private IAppConfig $config;

	/** @var PenpotClient&Stub */
	private PenpotClient $client;

	/**
	 * Provisioning is StorageService's, and it is stubbed AVAILABLE here.
	 *
	 * `add()` now builds the folder as well as saving the row (§C6.32), so every
	 * test in this file would otherwise need a filesystem. What the folder ends up
	 * being is StorageService's own concern and the integration suite's to prove;
	 * these tests are about the store's RULES.
	 *
	 * @var StorageService&Stub
	 */
	private StorageService $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->stored = '[]';

		$this->config = $this->createStub(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === MappingService::KEY_MAPPINGS
				? $this->stored
				: $default,
		);
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === MappingService::KEY_MAPPINGS) {
					$this->stored = $value;
				}

				return true;
			},
		);

		$this->storage = $this->createStub(StorageService::class);
		$this->storage->method('isAvailable')->willReturn(true);

		$this->client = $this->createStub(PenpotClient::class);
		$this->client->method('getTeams')->willReturn([
			['id' => self::TEAM_ID, 'name' => 'Northwind'],
			['id' => self::OTHER_TEAM_ID, 'name' => 'Default'],
		]);
	}

	private function service(): MappingService {
		// A fresh instance per call, because the service memoises the parsed list
		// for the request — reusing one would hide persistence bugs behind the
		// cache.
		return new MappingService($this->config, $this->client, $this->storage);
	}

	public function testAddsAVisibleTeam(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertSame(self::TEAM_ID, $saved->teamId);
		self::assertCount(1, $this->service()->list(), 'the mapping must survive a reload');
	}

	/**
	 * The §6.18 precondition. Without this check the mapping would be created and
	 * then pull nothing, forever — surfacing days later as "why is this folder
	 * empty?" with nothing connecting it to a missing Penpot invite.
	 */
	public function testRefusesATeamTheServiceAccountCannotSee(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not visible to the service account');

		$this->service()->add(Mapping::fromArray([
			'team_id' => '11111111-2222-3333-4444-555555555555',
		]));
	}

	public function testTakesTheTeamNameFromPenpotNotTheCaller(): void {
		// The server is authoritative for the name (§6.13 point 3), so a caller's
		// guess is overwritten rather than trusted.
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'team_name' => 'Whatever The Caller Typed',
		]));

		self::assertSame('Northwind', $saved->teamName);
	}

	public function testATeamCanOnlyBeMappedOnce(): void {
		$this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already mapped');

		$this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
	}

	/**
	 * `keyed` parses (so stored rows round-trip) but must never be CREATED —
	 * accepting it and behaving as `nested` would be a silent lie the admin could
	 * only detect from the resulting folder layout.
	 */
	public function testRefusesToCreateAKeyedMapping(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not implemented');

		$this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'folder_mode' => Mapping::FOLDER_MODE_KEYED,
		]));
	}

	/**
	 * A Penpot outage must not be reported as "that team does not exist" — the
	 * two need completely different fixes, so the exception type is preserved
	 * rather than collapsed into a validation error.
	 */
	public function testPropagatesAConnectionFailureRatherThanCallingItInvalid(): void {
		$client = $this->createStub(PenpotClient::class);
		$client->method('getTeams')->willThrowException(
			new PenpotApiException('unreachable', 0, null, PenpotApiException::KIND_UNREACHABLE),
		);

		$this->expectException(PenpotApiException::class);

		(new MappingService($this->config, $client, $this->storage))
			->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
	}

	/**
	 * Groups are the ONE thing a mapping may change, and now the only thing the
	 * API can express (§C6.33).
	 *
	 * Five tests used to live here, one per immutable field, each handing
	 * `update()` a whole mapping with one field moved and asserting a refusal.
	 * They tested a door with no handle: the sole caller rebuilds every other
	 * field from storage and could not have moved one. `updateGroups()` takes
	 * groups, so immutability is now a fact about the signature — there is
	 * nothing left to refuse, and nothing left to test.
	 */
	public function testUpdatesTheGroups(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$updated = $this->service()->updateGroups($saved->id, ['design']);

		self::assertSame(['design'], $updated->ncGroups);
		self::assertSame(['design'], $this->service()->getById($saved->id)?->ncGroups);
	}

	/** Everything else the mapping carries survives a group change untouched. */
	public function testUpdatingGroupsChangesNothingElse(): void {
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'Design Files',
			'use_team_folder' => true,
			'mode' => Mapping::MODE_SYNC,
		]));

		$updated = $this->service()->updateGroups($saved->id, 'design, admin');

		self::assertSame(['design', 'admin'], $updated->ncGroups);
		self::assertSame($saved->teamId, $updated->teamId);
		self::assertSame('Design Files', $updated->ncFolder);
		self::assertTrue($updated->useTeamFolder);
		self::assertSame(Mapping::MODE_SYNC, $updated->mode);
		self::assertSame($saved->folderMode, $updated->folderMode);
	}

	public function testUpdatingAnUnknownMappingIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('No mapping with id');

		$this->service()->updateGroups('no-such-id', ['design']);
	}

	/**
	 * The folder name is only known after Penpot answers with the team name, so
	 * this default is materialised in the SERVICE, not the model.
	 */
	public function testMaterialisesTheFolderNameFromPenpotsTeamName(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertSame('Northwind', $saved->ncFolder);
		self::assertSame('Northwind', $this->service()->getById($saved->id)?->ncFolder);
	}

	/**
	 * Penpot permits "/" in a team name; a Nextcloud folder name cannot carry
	 * one. Borrowing such a name verbatim would persist an nc_folder that
	 * Mapping::fromArray() then REJECTS on every later read — so the mapping
	 * would vanish from the list with nothing saying why.
	 */
	public function testASlashInTheTeamNameDoesNotProduceAnUnreadableMapping(): void {
		$client = $this->createStub(PenpotClient::class);
		$client->method('getTeams')->willReturn([
			['id' => self::TEAM_ID, 'name' => 'Design/Brand'],
		]);

		$service = new MappingService($this->config, $client, $this->storage);
		$saved = $service->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertStringNotContainsString('/', $saved->ncFolder);
		self::assertSame('Design-Brand', $saved->ncFolder);

		// The real point: it must still be readable back. A stored row that
		// fromArray() rejects would silently disappear from list().
		self::assertCount(1, (new MappingService($this->config, $client, $this->storage))->list());
	}

	public function testAnExplicitFolderNameSurvivesTheLookup(): void {
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'Design Files',
		]));

		self::assertSame('Design Files', $saved->ncFolder);
	}

	/**
	 * Two teams mirroring into one folder would interleave their project
	 * subfolders, and the pull would fight over the same names on every run.
	 */
	public function testTwoMappingsCannotShareAFolder(): void {
		$this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'Designs',
		]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already used');

		$this->service()->add(Mapping::fromArray([
			'team_id' => self::OTHER_TEAM_ID,
			'nc_folder' => 'Designs',
		]));
	}

	/**
	 * Nextcloud folder names are not reliably case-sensitive across storages, so
	 * "Designs" and "designs" may or may not be the same folder depending on the
	 * backend. Refusing both is the only answer that is right everywhere.
	 */
	public function testFolderUniquenessIsCaseInsensitive(): void {
		$this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'Designs',
		]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already used');

		$this->service()->add(Mapping::fromArray([
			'team_id' => self::OTHER_TEAM_ID,
			'nc_folder' => 'designs',
		]));
	}

	public function testGroupsAndTeamFolderPersist(): void {
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_groups' => ['design', 'admin'],
			'use_team_folder' => false,
		]));

		$reloaded = $this->service()->getById($saved->id);

		self::assertSame(['design', 'admin'], $reloaded?->ncGroups);
		self::assertFalse($reloaded?->useTeamFolder);
	}

	public function testRemovesAMapping(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertTrue($this->service()->remove($saved->id));
		self::assertSame([], $this->service()->list());
	}

	public function testRemovingSomethingThatIsNotThereReportsFalse(): void {
		self::assertFalse($this->service()->remove('nope'));
	}

	public function testRemovingOneLeavesTheOthers(): void {
		$first = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
		$second = $this->service()->add(Mapping::fromArray(['team_id' => self::OTHER_TEAM_ID]));

		$this->service()->remove($first->id);

		$remaining = $this->service()->list();
		self::assertCount(1, $remaining);
		self::assertSame($second->id, $remaining[0]->id);
	}

	/**
	 * A corrupt row must not take the whole admin page down with it — the other
	 * mappings still have to be listable and removable.
	 */
	public function testSkipsMalformedStoredRows(): void {
		$this->stored = json_encode([
			['team_id' => self::TEAM_ID, 'mode' => 'link', 'folder_mode' => 'nested'],
			['team_id' => 'not-a-uuid'],
			'this is not even an object',
		], JSON_THROW_ON_ERROR);

		$mappings = $this->service()->list();

		self::assertCount(1, $mappings);
		self::assertSame(self::TEAM_ID, $mappings[0]->teamId);
	}

	public function testTreatsUnparseableStorageAsEmpty(): void {
		$this->stored = 'not json at all';

		self::assertSame([], $this->service()->list());
	}

	public function testVisibleTeamsIsKeyedById(): void {
		$teams = $this->service()->visibleTeams();

		self::assertArrayHasKey(self::TEAM_ID, $teams);
		self::assertSame('Northwind', $teams[self::TEAM_ID]['name']);
	}
}
