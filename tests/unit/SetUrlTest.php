<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Command\SetUrl;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ penpot_sync:set-url` — the headless path, and the primary interface for
 * this slice (there is no UI requirement to configure the app).
 *
 * The validation is deliberately shallow — a URL that parses with an http(s)
 * scheme and a host is accepted, because the command must work *before* the
 * Penpot instance exists (helm/occ config-injection ordering). Whether Penpot is
 * actually reachable is a connection test's job, not this command's.
 */
final class SetUrlTest extends TestCase {
	private IAppConfig $config;
	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->tester = new CommandTester(new SetUrl($this->config));
	}

	public function testStoresAValidHttpsUrl(): void {
		$this->config->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, InstanceSettings::KEY_URL, 'https://penpot.example.com');

		$exit = $this->tester->execute(['url' => 'https://penpot.example.com']);

		self::assertSame(0, $exit);
		self::assertStringContainsString('https://penpot.example.com', $this->tester->getDisplay());
	}

	public function testNormalisesTrailingSlashBeforeStoring(): void {
		$this->config->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, InstanceSettings::KEY_URL, 'https://penpot.example.com');

		self::assertSame(0, $this->tester->execute(['url' => 'https://penpot.example.com/']));
	}

	public function testAcceptsAnInClusterHttpUrlWithAPort(): void {
		$this->config->expects(self::once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				InstanceSettings::KEY_URL,
				'http://penpot.cloud.svc.cluster.local:8080',
			);

		self::assertSame(
			0,
			$this->tester->execute(['url' => 'http://penpot.cloud.svc.cluster.local:8080']),
		);
	}

	public function testRejectsAUrlWithNoScheme(): void {
		$this->config->expects(self::never())->method('setValueString');

		$exit = $this->tester->execute(['url' => 'penpot.example.com']);

		self::assertSame(1, $exit);
		self::assertStringContainsString('valid absolute URL', $this->tester->getDisplay());
	}

	public function testRejectsANonHttpScheme(): void {
		$this->config->expects(self::never())->method('setValueString');

		$exit = $this->tester->execute(['url' => 'ftp://penpot.example.com']);

		self::assertSame(1, $exit);
		self::assertStringContainsString('http or https', $this->tester->getDisplay());
	}

	public function testRejectsAnEmptyUrl(): void {
		$this->config->expects(self::never())->method('setValueString');

		self::assertSame(1, $this->tester->execute(['url' => '   ']));
	}
}
