<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\MappingService;
use OCA\PenpotSync\Service\MappingTeardownService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:remove-mapping <id>`
 *
 * Stop mirroring a Penpot team.
 *
 * ## NOTHING IS REMOVED FROM PENPOT — SAY SO, EVERY TIME
 *
 * This app never deletes upstream content without an explicit, confirmed user
 * action (saga §6.19), and a mapping does not exist on Penpot's side at all, so
 * there is nothing there to tear down.
 *
 * WHAT HAPPENS LOCALLY IS NO LONGER "NOTHING", and this docblock used to say it
 * was. `mapping/delete.feature` settled the open decision: the mirrors that hold
 * nothing go, the mirrors that hold a design stay and become unmapped. The line
 * printed below reports which, because "removed the mapping" and "removed the
 * mapping and 40 pointers" are different events and the admin should not have to
 * go and look.
 *
 * Takes the mapping id rather than the team id: ids come straight from
 * `list-mappings` output, and using the same key for remove and list keeps the
 * two commands composable.
 */
final class RemoveMapping extends Command {
	public function __construct(
		private MappingService $service,
		private MappingTeardownService $teardown,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:remove-mapping')
			->setDescription('Remove a Penpot team mapping (stops mirroring; never touches Penpot).')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (see penpot_sync:list-mappings).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		$mapping = $this->service->getById($id);

		// THE MIRRORS FIRST, WHILE THE MAPPING IS STILL THERE TO FIND THEM BY. The
		// teardown resolves the mapped folder through the mapping itself, so running
		// it after the removal would have nothing left to walk.
		$torn = $mapping === null ? ['removed' => 0, 'unmapped' => 0] : $this->teardown->tearDown($mapping);

		if ($mapping === null || !$this->service->remove($id)) {
			$output->writeln('<error>No mapping with id ' . $id . '.</error>');
			$output->writeln('List them with: occ penpot_sync:list-mappings');

			return 1;
		}

		$output->writeln(sprintf(
			'<info>Removed the mapping for %s.</info>',
			$mapping->teamName !== '' ? $mapping->teamName : $mapping->teamId,
		));
		$output->writeln('<comment>Nothing was deleted in Penpot.</comment>');
		$output->writeln(sprintf(
			'<comment>In Nextcloud: removed %d empty pointer(s), left %d design(s) in place, now unmapped.</comment>',
			$torn['removed'],
			$torn['unmapped'],
		));

		return 0;
	}
}
