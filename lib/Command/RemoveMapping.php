<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:remove-mapping <id>`
 *
 * Stop mirroring a Penpot team.
 *
 * ## THIS DELETES NOTHING — SAY SO, EVERY TIME
 *
 * Nothing is removed from Penpot (this app never deletes upstream content
 * without an explicit, confirmed user action — saga §6.19), and nothing local is
 * removed either. What *should* happen to already-mirrored files when a mapping
 * goes away is a real open decision with its own feature file
 * (remove-mapping.feature) and it belongs to Course 5. Until then the safe
 * behaviour is to leave the files alone and say so plainly, so nobody assumes a
 * cleanup happened that did not.
 *
 * Takes the mapping id rather than the team id: ids come straight from
 * `list-mappings` output, and using the same key for remove and list keeps the
 * two commands composable.
 */
final class RemoveMapping extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:remove-mapping')
			->setDescription('Remove a Penpot team mapping (stops mirroring; deletes nothing).')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (see penpot_sync:list-mappings).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		$mapping = $this->service->getById($id);

		if ($mapping === null || !$this->service->remove($id)) {
			$output->writeln('<error>No mapping with id ' . $id . '.</error>');
			$output->writeln('List them with: occ penpot_sync:list-mappings');

			return 1;
		}

		$output->writeln(sprintf(
			'<info>Removed the mapping for %s.</info>',
			$mapping->teamName !== '' ? $mapping->teamName : $mapping->teamId,
		));
		$output->writeln('<comment>Nothing was deleted — not in Penpot, and not in Nextcloud.</comment>');

		return 0;
	}
}
