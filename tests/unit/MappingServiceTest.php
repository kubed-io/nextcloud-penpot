<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ExistingDesigns;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\StorageService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The mapping store's rules.
 *
 * The ones that matter most — and that would each fail *silently* if wrong — are
 * the §6.18 visibility precondition, the folder-before-persist order (§C6.32),
 * and groups never reaching appconfig (§C6.35). Each one, if skipped, produces a
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
	 * `add()` builds the folder as well as saving the row (§C6.32), so every test
	 * in this file would otherwise need a filesystem. What the folder ends up being
	 * is StorageService's own concern and the integration suite's to prove; these
	 * tests are about the store's RULES.
	 *
	 * The stub is a small FAKE rather than a silent double, because groups now live
	 * on the folder: it remembers what `ensureRoot()` was asked to apply and hands
	 * it back from `groupsOf()`. Without that, "the service reads groups from
	 * storage" would be untestable here — every assertion would see `[]` and pass
	 * for the wrong reason.
	 *
	 * @var StorageService&Stub
	 */
	private StorageService $storage;

	/**
	 * The designs already under a folder, stubbed EMPTY by default.
	 *
	 * The overwhelming case is a folder nothing has used, and every test here that
	 * predates the rule is about the store's own checks rather than what is on
	 * disk. The scenarios that care set it themselves.
	 *
	 * @var ExistingDesigns&Stub
	 */
	private ExistingDesigns $existing;

	/**
	 * What the fake storage believes each mapping's folder is shared with.
	 *
	 * @var array<string, list<string>>
	 */
	private array $appliedGroups = [];

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

		$this->appliedGroups = [];
		$this->existing = $this->createStub(ExistingDesigns::class);
		$this->existing->method('under')->willReturn([]);

		$this->storage = $this->createStub(StorageService::class);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureRoot')->willReturnCallback(
			function (Mapping $mapping, array|string|null $groups = null): Folder {
				// null means "just make sure the folder is there" — every pull — and
				// must leave sharing alone. Only an explicit set changes it (§C6.35).
				if ($groups !== null) {
					$this->appliedGroups[$mapping->id] = StorageService::normaliseGroups($groups);
				}

				return $this->createStub(Folder::class);
			},
		);
		$this->storage->method('groupsOf')->willReturnCallback(
			fn (Mapping $mapping): array => $this->appliedGroups[$mapping->id] ?? [],
		);

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
		return new MappingService($this->config, $this->client, $this->storage, $this->existing);
	}

	// ── a link mapping may not be made over designs that already exist ──────

	/**
	 * THE ONE IRREVERSIBLE ACT IN THIS APP IS OPT-IN, and this is the test that
	 * keeps it that way. A link mirror holds no bytes, so an archive inside a link
	 * mapping is a contradiction every later rule has to guess about — but clearing
	 * it means destroying files with no trash entry, so it may only happen on an
	 * acknowledgement the admin actually gave.
	 */
	public function testALinkMappingOverExistingDesignsIsRefused(): void {
		$this->existingHolds(2);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already holds 2 designs');

		$this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'mode' => Mapping::MODE_LINK,
		]));
	}

	/** The refusal says what would happen, in the words that make it a decision. */
	public function testTheRefusalSaysTheDesignsWouldBeDestroyed(): void {
		$this->existingHolds(1);

		try {
			$this->service()->add(Mapping::fromArray([
				'team_id' => self::TEAM_ID,
				'mode' => Mapping::MODE_LINK,
			]));
			self::fail('expected the mapping to be refused');
		} catch (\InvalidArgumentException $e) {
			// SINGULAR, because "1 designs" in a warning about permanent deletion
			// reads as a bug and undermines the sentence it appears in.
			self::assertStringContainsString('already holds 1 design.', $e->getMessage());
			self::assertStringContainsString('permanently deleted', $e->getMessage());
			self::assertStringContainsString('not recoverable', $e->getMessage());
		}
	}

	/** Acknowledged, it goes ahead — and the designs are destroyed, not trashed. */
	public function testAnAcknowledgedLinkMappingPurgesThem(): void {
		$designs = $this->existingHolds(2);

		$existing = $this->createMock(ExistingDesigns::class);
		$existing->method('under')->willReturn($designs);
		$existing->expects(self::once())->method('purge')->with($designs);

		$saved = (new MappingService($this->config, $this->client, $this->storage, $existing))
			->add(Mapping::fromArray([
				'team_id' => self::TEAM_ID,
				'mode' => Mapping::MODE_LINK,
			]), [], true);

		self::assertSame(self::TEAM_ID, $saved->teamId);
	}

	/**
	 * SYNC IS UNTOUCHED. Designs already in the tree are adopted and imported when
	 * a sync mapping arrives (§6.33), so nothing is destroyed and nothing has to be
	 * confirmed — asking would be a warning about an act that never happens.
	 */
	public function testASyncMappingOverExistingDesignsIsAllowed(): void {
		$existing = $this->createMock(ExistingDesigns::class);
		// NEVER EVEN ASKED. The check is skipped for `sync` rather than asked and
		// ignored, so a folder of ten thousand files costs a sync mapping nothing.
		$existing->expects(self::never())->method('under');
		$existing->expects(self::never())->method('purge');

		$saved = (new MappingService($this->config, $this->client, $this->storage, $existing))
			->add(Mapping::fromArray([
				'team_id' => self::TEAM_ID,
				'mode' => Mapping::MODE_SYNC,
			]));

		self::assertSame(self::TEAM_ID, $saved->teamId);
	}

	/** An empty folder needs no acknowledgement, which is the ordinary case. */
	public function testALinkMappingOverAnEmptyFolderNeedsNoAcknowledgement(): void {
		$existing = $this->createMock(ExistingDesigns::class);
		$existing->method('under')->willReturn([]);
		$existing->expects(self::never())->method('purge');

		(new MappingService($this->config, $this->client, $this->storage, $existing))
			->add(Mapping::fromArray([
				'team_id' => self::TEAM_ID,
				'mode' => Mapping::MODE_LINK,
			]));
	}

	/**
	 * Stub $count designs under the folder the next `add()` will claim.
	 *
	 * @return list<File>
	 */
	private function existingHolds(int $count): array {
		$designs = [];
		for ($i = 0; $i < $count; $i++) {
			$designs[] = $this->createStub(File::class);
		}

		$this->existing = $this->createStub(ExistingDesigns::class);
		$this->existing->method('under')->willReturn($designs);

		return $designs;
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
		$this->expectExceptionMessage('was not found using the given credentials');

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

		(new MappingService($this->config, $client, $this->storage, $this->existing))
			->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
	}

	/**
	 * A mapping that could not be provisioned must not be saved.
	 *
	 * `add()` establishes "the folder exists when this returns" (§C6.32), so the
	 * order matters: provisioning runs BEFORE the write. If it throws — no sync
	 * actor, the name already taken by a file — the failure leaves nothing behind
	 * rather than a row claiming a folder that was never built.
	 */
	public function testAMappingWhoseFolderCannotBeBuiltIsNotSaved(): void {
		$storage = $this->createStub(StorageService::class);
		$storage->method('isAvailable')->willReturn(true);
		$storage->method('ensureRoot')->willThrowException(new \RuntimeException('no sync actor'));

		$service = new MappingService($this->config, $this->client, $storage, $this->existing);

		try {
			$service->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
			self::fail('expected the provisioning failure to surface');
		} catch (\RuntimeException $e) {
			self::assertSame('no sync actor', $e->getMessage());
		}

		self::assertSame([], (new MappingService($this->config, $this->client, $storage, $this->existing))->list());
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

		// The RETURN is what the folder reports afterwards, not the input echoed
		// back — a group that does not exist cannot be shared with.
		self::assertSame(['design'], $updated);
		self::assertSame(['design'], $this->service()->groupsOf($saved));
	}

	/**
	 * A group change writes to the FOLDER and to nothing else (§C6.35).
	 *
	 * The strongest single statement of the new design: appconfig is untouched, so
	 * there is no stored copy to disagree with the folder, and no reconciler that
	 * could put an old list back over an admin's hand edit.
	 */
	public function testUpdatingGroupsStoresNothing(): void {
		$this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
		$before = $this->stored;

		$this->service()->updateGroups($this->service()->list()[0]->id, 'design, admin');

		self::assertSame($before, $this->stored);
		self::assertStringNotContainsString('design', $this->stored);
	}

	/** Everything else the mapping carries survives a group change untouched. */
	public function testUpdatingGroupsChangesNothingElse(): void {
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'nc_folder' => 'Design Files',
			'use_team_folder' => true,
			'mode' => Mapping::MODE_SYNC,
		]));

		self::assertSame(['design', 'admin'], $this->service()->updateGroups($saved->id, 'design, admin'));

		$reloaded = $this->service()->getById($saved->id);

		self::assertSame($saved->teamId, $reloaded?->teamId);
		self::assertSame('Design Files', $reloaded?->ncFolder);
		self::assertTrue($reloaded?->useTeamFolder);
		self::assertSame(Mapping::MODE_SYNC, $reloaded?->mode);
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

		$service = new MappingService($this->config, $client, $this->storage, $this->existing);
		$saved = $service->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertStringNotContainsString('/', $saved->ncFolder);
		self::assertSame('Design-Brand', $saved->ncFolder);

		// The real point: it must still be readable back. A stored row that
		// fromArray() rejects would silently disappear from list().
		self::assertCount(1, (new MappingService($this->config, $client, $this->storage, $this->existing))->list());
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
		$this->expectExceptionMessage('already mapped to another team');

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
		$this->expectExceptionMessage('already mapped to another team');

		$this->service()->add(Mapping::fromArray([
			'team_id' => self::OTHER_TEAM_ID,
			'nc_folder' => 'designs',
		]));
	}

	public function testTheTeamFolderFlagPersists(): void {
		$saved = $this->service()->add(Mapping::fromArray([
			'team_id' => self::TEAM_ID,
			'use_team_folder' => false,
		]));

		self::assertFalse($this->service()->getById($saved->id)?->useTeamFolder);
	}

	/**
	 * Groups given at create reach the FOLDER and never appconfig (§C6.35).
	 *
	 * They are a parameter to `add()`, not a field on the mapping, for the same
	 * reason they are not persisted: the folder is the record. Reloading proves it
	 * — the groups come back from storage, and the stored JSON has never heard of
	 * them.
	 */
	public function testGroupsGivenAtCreateGoToTheFolderNotTheStore(): void {
		$saved = $this->service()->add(
			Mapping::fromArray(['team_id' => self::TEAM_ID]),
			['design', 'admin'],
		);

		self::assertSame(['design', 'admin'], $this->service()->groupsOf($saved));
		self::assertStringNotContainsString('design', $this->stored);
		self::assertStringNotContainsString('nc_groups', $this->stored);
	}

	/**
	 * `describe()` is the shape the admin page and `list-mappings --json` render:
	 * everything stored, plus the folder's live groups. It must not be what
	 * `persist()` writes, or the groups would be cached back into appconfig and
	 * the whole arrangement would quietly revert.
	 */
	public function testDescribeAddsTheFoldersGroupsToTheStoredShape(): void {
		$saved = $this->service()->add(
			Mapping::fromArray(['team_id' => self::TEAM_ID]),
			'design',
		);

		self::assertSame(['design'], $this->service()->describe($saved)['nc_groups']);
		self::assertArrayNotHasKey('nc_groups', $saved->toArray());
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
