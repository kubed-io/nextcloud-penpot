<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:list-mappings [--json]`
 *
 * Print the configured team mappings — the same list the admin panel renders.
 *
 * Defaults to a table because the common use is a human checking what is
 * configured; `--json` gives the exact stored shape for scripts and for the
 * integration suite, which asserts against it.
 */
final class ListMappings extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:list-mappings')
			->setDescription('List the configured Penpot team mappings.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON instead of a table.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mappings = $this->service->list();

		if ($input->getOption('json')) {
			$output->writeln(json_encode(
				array_map(static fn (Mapping $m): array => $m->toArray(), $mappings),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
			));

			return 0;
		}

		if ($mappings === []) {
			$output->writeln('<comment>No Penpot teams are mapped.</comment>');
			$output->writeln('Add one with: occ penpot_sync:add-mapping <team-id>');

			return 0;
		}

		$output->writeln(sprintf('%-18s %-38s %-24s %-6s %s', 'ID', 'TEAM ID', 'TEAM', 'MODE', 'FOLDER MODE'));

		foreach ($mappings as $mapping) {
			$output->writeln(sprintf(
				'%-18s %-38s %-24s %-6s %s',
				$mapping->id,
				$mapping->teamId,
				$mapping->teamName !== '' ? $mapping->teamName : '(unknown)',
				$mapping->mode,
				$mapping->folderMode,
			));
		}

		return 0;
	}
}
