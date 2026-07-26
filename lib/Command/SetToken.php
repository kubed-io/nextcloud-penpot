<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Service\PenpotClient;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:set-token <token>`
 *
 * Store the **service-account** Penpot access token, encrypted.
 *
 * WHICH TOKEN THIS IS (saga §6.18): the required one — the single credential
 * that performs every pull. Penpot has no admin scope and no service-account
 * concept of its own (saga §6.8), so this is an ordinary personal access token
 * belonging to an account created for the purpose, which each team must invite
 * as `viewer` before that team can be mapped.
 *
 * It is NOT the optional per-user token that attributes writes; that lands with
 * the personal-settings card and is stored at user scope.
 *
 * CLI-FIRST IS THE HOUSE STYLE, and here it is also the safer one: passing a
 * token as an argument keeps it out of a browser session, and `occ` is how this
 * app gets configured declaratively in a cluster.
 */
final class SetToken extends Command {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:set-token')
			->setDescription('Store the Penpot service-account access token (encrypted).')
			->addArgument('token', InputArgument::REQUIRED, 'A Penpot access token');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = trim((string)$input->getArgument('token'));

		if ($token === '') {
			$output->writeln('<error>The token cannot be empty.</error>');
			return 1;
		}

		$this->config->setValueString(
			Application::APP_ID,
			PenpotClient::KEY_TOKEN,
			$this->crypto->encrypt($token),
			sensitive: true,
		);

		$output->writeln('<info>Penpot service-account token stored.</info>');
		$output->writeln('Verify it with: occ penpot_sync:probe');

		return 0;
	}
}
