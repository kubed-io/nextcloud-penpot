<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\PersonalTokenService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:set-personal-token <user> <token>`
 * `occ penpot_sync:set-personal-token <user> --clear`
 *
 * The `occ` twin of the personal settings card.
 *
 * ## WHY AN ADMIN CAN SET ANOTHER USER'S TOKEN
 *
 * `occ` runs as the instance administrator, who can already read and write any
 * user's configuration — this grants nothing new. It exists so a personal token
 * can be provisioned declaratively (the cluster case: a user's token arriving
 * from a secret store rather than being pasted into a browser), which is the
 * same reason every other setting here has a CLI twin.
 *
 * The user id is validated so a typo becomes a clear error rather than a token
 * silently stored against a user that does not exist — where it would never be
 * read, and nothing would ever say why.
 */
final class SetPersonalToken extends Command {
	public function __construct(
		private PersonalTokenService $tokens,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:set-personal-token')
			->setDescription('Store one user\'s personal Penpot token (attribution only).')
			->addArgument('user', InputArgument::REQUIRED, 'The Nextcloud user id.')
			->addArgument('token', InputArgument::OPTIONAL, 'That user\'s Penpot access token.')
			->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the user\'s stored token instead.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');

		if (!$this->userManager->userExists($userId)) {
			$output->writeln('<error>No such Nextcloud user: ' . $userId . '</error>');

			return 1;
		}

		if ($input->getOption('clear')) {
			$this->tokens->clearToken($userId);
			$output->writeln('<info>Removed the personal Penpot token for ' . $userId . '.</info>');
			$output->writeln('Their mirrored files keep working; future changes are attributed to the service account.');

			return 0;
		}

		$token = trim((string)$input->getArgument('token'));

		if ($token === '') {
			$output->writeln('<error>Provide a token, or pass --clear to remove the stored one.</error>');

			return 1;
		}

		$this->tokens->setToken($userId, $token);

		$output->writeln('<info>Stored the personal Penpot token for ' . $userId . '.</info>');
		$output->writeln('<comment>Attribution only — this token is never used to mirror content.</comment>');

		return 0;
	}
}
