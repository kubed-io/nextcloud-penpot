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
 * `occ penpot_sync:set-groups <id> <groups>`
 *
 * Change the Nextcloud groups a mapped folder is shared with.
 *
 * ## THE ONLY EDIT A MAPPING HAS
 *
 * Everything else a mapping carries is settled when it is created (saga §C6.33):
 * the team, the folder name, the storage backend, the default mode and the
 * folder mode are all immutable, because changing any of them would force a live
 * migration of already-mirrored content. Removing the mapping and adding it again
 * makes that cost visible instead of hiding it behind a flag.
 *
 * Re-sharing a folder moves no content, which is why it is the one that stayed —
 * and it is the same field the admin panel's PUT owns. This command exists so the
 * CLI has the same single edit the UI does, rather than the admin surface being
 * the only way to reach it.
 *
 * ## WHAT IT DOES NOT DO
 *
 * It records the groups. Re-sharing the PROVISIONED folder happens where all
 * provisioning happens — {@see \OCA\PenpotSync\Service\StorageService::ensureRoot()},
 * which every pull calls and which re-asserts the mapping's groups on the folder.
 * Handing a mapping a group and finding the share on the folder are therefore two
 * events, and the second one is the sync's.
 */
final class SetGroups extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:set-groups')
			->setDescription('Change the Nextcloud groups a mapped folder is shared with (a mapping\'s only editable field).')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (see penpot_sync:list-mappings).')
			->addArgument(
				'groups',
				InputArgument::OPTIONAL,
				'Comma-separated Nextcloud groups. Omit to share with none.',
				'',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');

		try {
			// A comma-separated string is accepted verbatim by the model's group
			// normaliser, so the CLI needs no parsing of its own.
			$updated = $this->service->updateGroups($id, (string)$input->getArgument('groups'));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('List them with: occ penpot_sync:list-mappings');

			return 1;
		}

		$output->writeln(sprintf(
			'Mapping %s is now shared with: %s',
			$updated->id,
			$updated->ncGroups === [] ? '(no groups)' : implode(', ', $updated->ncGroups),
		));
		$output->writeln('The share itself is re-asserted on the next sync.');

		return 0;
	}
}
