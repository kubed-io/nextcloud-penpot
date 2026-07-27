<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\PullService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:sync [pull] [--mapping=ID]`
 *
 * Run the pull (Penpot → Nextcloud) — the CLI twin of the future scheduled job
 * and admin button, calling the one {@see PullService::pull()} they all will.
 *
 * ## WHY `pull` IS AN ARGUMENT AND NOT THE COMMAND NAME
 *
 * The saga names this `penpot_sync:sync pull` (Ch2 Course 3): the same command
 * grows a `push` direction in Course 4. Keeping `sync` as the verb and the
 * direction as an argument means that later course adds a branch, not a new
 * command — and the muscle memory (`occ penpot_sync:sync …`) never changes.
 * Only `pull` is implemented today; anything else is refused with a clear
 * message rather than silently doing a pull.
 */
final class Sync extends Command {
	private const DIR_PULL = 'pull';

	public function __construct(
		private PullService $pull,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:sync')
			->setDescription('Mirror Penpot into Nextcloud (pull). The CLI twin of the scheduled job.')
			->addArgument(
				'direction',
				InputArgument::OPTIONAL,
				'Sync direction. Only "pull" is implemented; "push" lands in a later course.',
				self::DIR_PULL,
			)
			->addOption(
				'mapping',
				null,
				InputOption::VALUE_REQUIRED,
				'Restrict the run to a single mapping id (see penpot_sync:list-mappings). Default: every mapping.',
				'',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$direction = (string)$input->getArgument('direction');
		if ($direction !== self::DIR_PULL) {
			$output->writeln('<error>Only "pull" is implemented. Push lands in a later course.</error>');

			return 1;
		}

		$mappingId = (string)$input->getOption('mapping');

		try {
			$result = $this->pull->pull($mappingId === '' ? null : $mappingId);
		} catch (\OutOfBoundsException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln(sprintf(
			'Pulled %d project(s): %d folder(s), %d file(s), %d skipped.',
			$result['processed'],
			$result['folders'],
			$result['files'],
			$result['skipped'],
		));

		if ($result['status'] !== 'ok') {
			$output->writeln('<error>Some mappings failed: ' . (string)$result['message'] . '</error>');

			return 1;
		}

		$output->writeln('<info>Pull complete.</info>');

		return 0;
	}
}
