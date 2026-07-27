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

	public function testUpdatesTheDefaultMode(): void {
		$saved = $this->service()->add(Mapping::fromArray(['team_id' => self::TEAM_ID]));

		$updated = $this->service()->update($saved->id, new Mapping(
			$saved->id,
			$saved->teamId,
			$saved->teamName,
			Mapping::MODE_SYNC,
			$saved->folderMode,
		));

		self::assertSame(Mapping::MODE_SYNC, $updated->mode);
		self::assertSame(Mapping::MODE_SYNC, $this->service()->getById($saved->id)?->mode);
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
			$saved->mode,
			$saved->folderMode,
		));
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
