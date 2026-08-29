<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:set-url <url>`
 *
 * Point the app at a Penpot instance — the headless equivalent of typing the URL
 * into the admin Instance card, and the reason this slice is usable with no UI
 * at all.
 *
 * A base URL is not a secret, so unlike the siblings' `set-token` this writes a
 * plain AppConfig value; `occ config:app:get penpot_sync penpot_url` reads it
 * back verbatim. It is stored **normalised** (trailing slashes stripped) so that
 * every later consumer can concatenate paths without defensive trimming.
 *
 * Validation is deliberately shallow: the URL must parse and carry an http(s)
 * scheme and a host. Whether Penpot is actually *there* is a different question,
 * answered by `penpot_sync:test-connection` — this command must work before the
 * instance is reachable, because config is often injected before it comes up.
 */
final class SetUrl extends Command {
	public function __construct(
		private IAppConfig $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:set-url')
			->setDescription('Set the Penpot base URL, as the admin Settings panel would.')
			->addArgument('url', InputArgument::REQUIRED, 'Penpot base URL, e.g. https://penpot.example.com');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$url = trim((string)$input->getArgument('url'));

		$normalised = rtrim($url, '/');
		if ($normalised === '') {
			$output->writeln('<error>URL must not be empty.</error>');
			return 1;
		}

		$parts = parse_url($normalised);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			$output->writeln('<error>Not a valid absolute URL: ' . $normalised . '</error>');
			$output->writeln('Expected something like https://penpot.example.com');
			return 1;
		}
		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			$output->writeln('<error>URL scheme must be http or https, got: ' . $parts['scheme'] . '</error>');
			return 1;
		}

		$this->config->setValueString(Application::APP_ID, InstanceSettings::KEY_URL, $normalised);
		$output->writeln('<info>Penpot base URL set to ' . $normalised . '</info>');

		return 0;
	}
}
