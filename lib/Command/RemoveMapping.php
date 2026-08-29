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

		if ($mapping === null || !$this->service->remove($id)) {
			$output->writeln('<error>No mapping with id ' . $id . '.</error>');
			$output->writeln('List them with: occ penpot_sync:list-mappings');

			return 1;
		}

		// THE MIRRORS AFTER THE MAPPING, AND THAT ORDER IS A CHOICE. It reads
		// backwards — the teardown finds the mirrors through the mapping — but the
		// mapping OBJECT is already loaded and that is all
		// {@see StorageService::findRoot()} needs, so the config is not consulted
		// again. What the order buys is the failure mode: `remove()` writes app
		// config and can throw, while `tearDown()` is total by construction. Torn
		// down first, a throw here would leave the mapping configured over a tree
		// already dismantled. This way the worst case is a removed mapping with its
		// pointers still lying about, which the next removal or a person can clear.
		//
		$torn = $this->teardown->tearDown($mapping);

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
