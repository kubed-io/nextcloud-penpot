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
 * the team, the folder name, the storage backend and the default mode are all
 * immutable, because changing any of them would force a live migration of
 * already-mirrored content. Removing the mapping and adding it again makes that
 * cost visible instead of hiding it behind a flag.
 *
 * Re-sharing a folder moves no content, which is why it is the one that stayed —
 * and it is the same thing the admin panel's PUT owns. This command exists so the
 * CLI has the same single edit the UI does, rather than the admin surface being
 * the only way to reach it.
 *
 * ## IT IS A CONVENIENCE, NOT THE RECORD (§C6.35)
 *
 * This edits the FOLDER — a groupfolders assignment or a group share, depending
 * on the backend — and the app stores nothing about the result. That makes it a
 * shortcut, not an authority: `occ groupfolders:group`, or the Files sharing UI,
 * change exactly the same thing, and this app will report whatever they left
 * behind rather than putting its own idea back on the next sync.
 *
 * What it buys you is not having to know which of those two mechanisms your
 * mapping uses. It prints the groups the folder reports AFTERWARDS, so a group
 * that does not exist is visibly absent rather than silently accepted.
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
			// A comma-separated string is accepted verbatim by the normaliser, so
			// the CLI parses nothing of its own.
			$groups = $this->service->updateGroups($id, (string)$input->getArgument('groups'));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('List them with: occ penpot_sync:list-mappings');

			return 1;
		} catch (\RuntimeException $e) {
			// The folder could not be reached to re-share it — an unresolvable sync
			// actor, or groupfolders gone from under a Team Folder mapping. Distinct
			// from the refusal above because it is an instance problem, not a typo.
			$output->writeln('<error>Could not re-share the mapped folder: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln(sprintf(
			'The folder for mapping %s is shared with: %s',
			$id,
			$groups === [] ? '(no groups)' : implode(', ', $groups),
		));

		return 0;
	}
}
