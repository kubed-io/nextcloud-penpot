<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
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

		$this->client = $this->createStub(PenpotClient::class);
		$this->client->method('getTeams')->willReturn([
			['id' => self::TEAM_ID, 'name' => 'Ferronescotia'],
			['id' => self::OTHER_TEAM_ID, 'name' => 'Default'],
		]);
	}

	private function service(): MappingService {
		// A fresh instance per call, because the service memoises the parsed list
		// for the request — reusing one would hide persistence bugs behind the
		// cache.
		return new MappingService($this->config, $this->client);
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

		self::assertSame('Ferronescotia', $saved->teamName);
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

		(new MappingService($this->config, $client))
			->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));
	}

	/**
	 * Groups are the one thing an update may change — the same "everything else
	 * stays editable" line Grafana draws, minus the fields whose meaning differs
	 * here.
	 */
	public function testUpdatesTheGroups(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$updated = $this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			$saved->ncFolder,
			['design'],
			$saved->useTeamFolder,
			$saved->mode,
			$saved->folderMode,
		));

		self::assertSame(['design'], $updated->ncGroups);
		self::assertSame(['design'], $this->service()->getById($saved->id)?->ncGroups);
	}

	/**
	 * link ⇄ sync is IMMUTABLE here, unlike Grafana's `mode`, because the axis
	 * means something different (saga §6.22): it decides whether we hold the
	 * bytes. sync→link would delete every downloaded archive under the mapping;
	 * link→sync would export every file at once. Per-FILE promotion is the
	 * supported path, because it can ask first.
	 */
	public function testModeIsImmutable(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be changed');

		$this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			$saved->ncFolder,
			$saved->ncGroups,
			$saved->useTeamFolder,
			Mapping::MODE_SYNC,
			$saved->folderMode,
		));
	}

	/** Re-pointing it would have to move the whole mirrored tree. Grafana locks it too. */
	public function testTheFolderIsImmutable(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be changed');

		$this->service()->update($saved->id, $saved->withNcFolder('Design Files'));
	}

	/** Switching backend would have to migrate the folder and all its shares. */
	public function testTheTeamFolderFlagIsImmutable(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be changed');

		$this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			$saved->ncFolder,
			$saved->ncGroups,
			!$saved->useTeamFolder,
			$saved->mode,
			$saved->folderMode,
		));
	}

	/**
	 * §6.53. Flipping this live would restructure every folder under the mapping
	 * AND rewrite every project name in Penpot — a two-sided destructive
	 * migration behind a dropdown.
	 */
	public function testFolderModeIsImmutable(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be changed');

		$this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			$saved->ncFolder,
			$saved->ncGroups,
			$saved->useTeamFolder,
			$saved->mode,
			Mapping::FOLDER_MODE_KEYED,
		));
	}

	public function testTheTeamIsImmutable(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be changed');

		$this->service()->update($saved->id, new Mapping(
			$saved->id,
			self::OTHER_TEAM_ID,
			$saved->teamName,
			$saved->ncFolder,
			$saved->ncGroups,
			$saved->useTeamFolder,
			$saved->mode,
			$saved->folderMode,
		));
	}

	/**
	 * The folder name is only known after Penpot answers with the team name, so
	 * this default is materialised in the SERVICE, not the model.
	 */
	public function testMaterialisesTheFolderNameFromPenpotsTeamName(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertSame('Ferronescotia', $saved->ncFolder);
		self::assertSame('Ferronescotia', $this->service()->getById($saved->id)?->ncFolder);
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

		$service = new MappingService($this->config, $client);
		$saved = $service->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		self::assertStringNotContainsString('/', $saved->ncFolder);
		self::assertSame('Design-Brand', $saved->ncFolder);

		// The real point: it must still be readable back. A stored row that
		// fromArray() rejects would silently disappear from list().
		self::assertCount(1, (new MappingService($this->config, $client))->list());
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

	/**
	 * Blank on update means "keep what is there", not "clear it" — so a caller
	 * that omits the folder is not asking to change it, and must not trip the
	 * immutability check.
	 */
	public function testABlankFolderOnUpdateIsNotAChange(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$updated = $this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			'',
			['design'],
			$saved->useTeamFolder,
			$saved->mode,
			$saved->folderMode,
		));

		self::assertSame('Ferronescotia', $updated->ncFolder);
		self::assertSame(['design'], $updated->ncGroups);
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
		self::assertSame('Ferronescotia', $teams[self::TEAM_ID]['name']);
	}
}
