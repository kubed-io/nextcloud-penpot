<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The admin Instance card — the whole of this slice's UI surface.
 *
 * Two things are worth asserting and both have bitten the sibling apps before:
 * the form id must NOT be app-prefixed (the settings frontend strips a leading
 * "<app>_" before the save API call, so a prefixed id silently fails to save),
 * and the URL must come back normalised so every later consumer can concatenate
 * paths without defensive trimming.
 */
final class InstanceSettingsTest extends TestCase {
	/** @var IAppConfig&\PHPUnit\Framework\MockObject\Stub */
	private IAppConfig $config;
	private InstanceSettings $settings;

	protected function setUp(): void {
		parent::setUp();
		// A stub, not a mock — these tests assert on the schema and on the value
		// getUrl() returns, never on how AppConfig was called.
		$this->config = $this->createStub(IAppConfig::class);
		$this->settings = new InstanceSettings($this->config);
	}

	public function testSchemaIdIsNotAppPrefixed(): void {
		$schema = $this->settings->getSchema();

		// A prefixed id (penpot_sync_instance) gets mangled to "instance" by the
		// frontend and then fails the backend's exact-match lookup.
		self::assertSame('instance', $schema['id']);
		self::assertStringStartsNotWith(Application::APP_ID, $schema['id']);
	}

	public function testSchemaTargetsThisAppsAdminSection(): void {
		$schema = $this->settings->getSchema();

		self::assertSame(Application::APP_ID, $schema['section_id']);
		self::assertSame('admin', $schema['section_type']);
	}

	/**
	 * URL + token, in that order — the same Instance card `nextcloud-grafana`
	 * ships. An earlier cut split them across two cards; that produced an admin
	 * page shaped unlike either sibling to express a distinction the admin never
	 * sees (the second credential is per-USER and lives on the personal page).
	 */
	public function testSchemaExposesTheUrlAndTheToken(): void {
		$schema = $this->settings->getSchema();

		self::assertCount(2, $schema['fields']);
		self::assertSame(InstanceSettings::KEY_URL, $schema['fields'][0]['id']);
		self::assertSame('url', $schema['fields'][0]['type']);
		self::assertSame(PenpotClient::KEY_TOKEN, $schema['fields'][1]['id']);
		self::assertSame('password', $schema['fields'][1]['type']);
	}

	/**
	 * Without `sensitive`, core stores the token in PLAINTEXT and echoes it back
	 * to the browser — and PenpotClient, which decrypts on read, would then fail
	 * to use it. The flag is what makes the card and the CLI agree.
	 */
	public function testTheTokenFieldIsSensitive(): void {
		$schema = $this->settings->getSchema();

		self::assertTrue($schema['fields'][1]['sensitive'] ?? false);
	}

	/**
	 * A sensitive field renders blank even when populated, so the copy is the
	 * ONLY signal of whether a token is stored. Both siblings learned this.
	 */
	public function testTheTokenCopyReportsWhetherOneIsStored(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$empty = (new InstanceSettings($config))->getSchema();

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('ENCRYPTED');
		$stored = (new InstanceSettings($config))->getSchema();

		self::assertStringContainsString('No token stored yet', $empty['fields'][1]['description']);
		self::assertStringContainsString('is stored', $stored['fields'][1]['description']);
		self::assertNotSame(
			$empty['fields'][1]['placeholder'],
			$stored['fields'][1]['placeholder'],
			'the placeholder must also distinguish the two states',
		);
	}

	public function testGetUrlReturnsEmptyStringWhenUnset(): void {
		$this->config->method('getValueString')->willReturn('');

		self::assertSame('', $this->settings->getUrl());
	}

	public function testGetUrlStripsTrailingSlashes(): void {
		$this->config->method('getValueString')
			->willReturn('https://penpot.example.com///');

		self::assertSame('https://penpot.example.com', $this->settings->getUrl());
	}

	public function testGetUrlLeavesACleanUrlAlone(): void {
		$this->config->method('getValueString')
			->willReturn('http://penpot.cloud.svc.cluster.local:8080');

		self::assertSame(
			'http://penpot.cloud.svc.cluster.local:8080',
			$this->settings->getUrl(),
		);
	}
}
