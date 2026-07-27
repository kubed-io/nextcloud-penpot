<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\ScheduleConfig;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:show-config`
 *
 * Print what the app is currently configured with — the base URL, and whether a
 * service-account token is stored. It exists from the start because it is what
 * the integration suite asserts against, and because "did my `set-url` actually
 * land?" is the first question anyone asks.
 *
 * THE TOKEN IS NEVER PRINTED, only its presence. Nothing about it is useful to
 * see, and `occ` output lands in terminals, scrollback, and CI logs. Whether the
 * stored token actually *works* is `penpot_sync:probe`'s job, not this one's —
 * the split mirrors the siblings' show-config/test-connection pair.
 *
 * Exits non-zero when nothing is configured, so a shell script can gate on it.
 */
final class ShowConfig extends Command {
	public function __construct(
		private InstanceSettings $settings,
		private IAppConfig $config,
		private MappingService $mappings,
		private ScheduleConfig $schedule,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:show-config')
			->setDescription('Show the current Penpot Sync configuration.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$url = $this->settings->getUrl();

		if ($url === '') {
			$output->writeln('<comment>No Penpot base URL configured.</comment>');
			$output->writeln('Set one with: occ penpot_sync:set-url https://penpot.example.com');
			return 1;
		}

		$output->writeln('Penpot base URL: <info>' . $url . '</info>');

		$hasToken = $this->config->getValueString(Application::APP_ID, PenpotClient::KEY_TOKEN, '') !== '';

		$output->writeln($hasToken
			? 'Service-account token: <info>stored</info>'
			: 'Service-account token: <comment>not set</comment> (occ penpot_sync:set-token <token>)');

		$mappings = $this->mappings->list();

		$output->writeln('Mapped teams: ' . ($mappings === []
			? '<comment>none</comment> (occ penpot_sync:add-mapping <team-id>)'
			: '<info>' . count($mappings) . '</info>'));

		foreach ($mappings as $mapping) {
			$output->writeln(sprintf(
				'  - %s (%s) mode=%s folder-mode=%s',
				$mapping->teamName !== '' ? $mapping->teamName : '(unknown)',
				$mapping->teamId,
				$mapping->mode,
				$mapping->folderMode,
			));
		}

		// Reported even though nothing reads it yet, because it is stored state an
		// operator can set — and a setting you can change but cannot read back is
		// how "did that apply?" becomes an unanswerable question.
		$output->writeln(sprintf(
			'Scheduled pull: %s, every %s <comment>(not running — the pull job is not built yet)</comment>',
			$this->schedule->isEnabled() ? '<info>enabled</info>' : '<comment>disabled</comment>',
			ScheduleConfig::formatInterval($this->schedule->getIntervalSeconds()),
		));

		return 0;
	}
}
