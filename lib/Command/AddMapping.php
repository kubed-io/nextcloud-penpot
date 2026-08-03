<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:add-mapping <team-id> [--mode=link] [--folder-mode=nested]`
 *
 * Map a Penpot team. The same {@see MappingService::add()} the settings panel
 * calls — validation, the visibility precondition, and persistence all live in
 * the service, so this command adds no rules of its own and cannot drift from
 * the UI.
 *
 * ## WHY A TEAM ID AND NOT A TEAM NAME
 *
 * Penpot permits duplicate team names, and a mapping is keyed on the id anyway
 * (a team rename must not orphan it). Accepting a name would mean guessing which
 * team was meant. `occ penpot_sync:list-teams` prints the ids next to the names,
 * which is the intended two-step.
 *
 * The team NAME is not a parameter at all — it is read from Penpot during the
 * visibility check, because the server is authoritative for it (saga §6.13).
 */
final class AddMapping extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:add-mapping')
			->setDescription('Map a Penpot team (same as the admin Settings panel, via CLI).')
			->addArgument('team-id', InputArgument::REQUIRED, 'The Penpot team id (see penpot_sync:list-teams).')
			->addOption(
				'folder',
				null,
				InputOption::VALUE_REQUIRED,
				'Nextcloud folder name. Defaults to the Penpot team\'s own name.',
				'',
			)
			->addOption(
				'groups',
				null,
				InputOption::VALUE_REQUIRED,
				'Comma-separated Nextcloud groups the folder is shared with.',
				'',
			)
			->addOption(
				'team-folder',
				null,
				InputOption::VALUE_NONE,
				'Use a groupfolders Team Folder. Requires the groupfolders app; '
				. 'without this flag a plain shared folder is used.',
			)
			->addOption(
				'mode',
				null,
				InputOption::VALUE_REQUIRED,
				'Default mode for files under this mapping: link or sync.',
				Mapping::MODE_LINK,
			)
			->addOption(
				'folder-mode',
				null,
				InputOption::VALUE_REQUIRED,
				'How projects map to folders: nested (keyed is designed but not implemented).',
				Mapping::FOLDER_MODE_NESTED,
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$mapping = Mapping::fromArray([
				'team_id' => (string)$input->getArgument('team-id'),
				'nc_folder' => (string)$input->getOption('folder'),
				// A comma-separated string is accepted verbatim by the model's
				// group normaliser, so the CLI needs no parsing of its own.
				'nc_groups' => (string)$input->getOption('groups'),
				'use_team_folder' => (bool)$input->getOption('team-folder'),
				'mode' => (string)$input->getOption('mode'),
				'folder_mode' => (string)$input->getOption('folder-mode'),
			]);

			$saved = $this->service->add($mapping);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		} catch (PenpotApiException $e) {
			// Deliberately distinct from the validation case above: "Penpot is
			// unreachable" and "that team is not visible" send an operator to
			// completely different fixes, so they must not print alike.
			$output->writeln('<error>Could not check the team against Penpot: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln(sprintf(
			'<info>Mapped Penpot team %s (%s).</info>',
			$saved->teamName !== '' ? $saved->teamName : $saved->teamId,
			$saved->id,
		));
		$output->writeln('  folder: ' . $saved->ncFolder
			. ($saved->useTeamFolder ? ' (Team Folder)' : ' (shared folder)'));
		$output->writeln('  groups: ' . ($saved->ncGroups === [] ? '(none)' : implode(', ', $saved->ncGroups)));
		$output->writeln('  mode: ' . $saved->mode . ', folder mode: ' . $saved->folderMode);
		$output->writeln('<comment>Nothing is mirrored yet — the pull is not built (saga Ch2, Course 3).</comment>');

		return 0;
	}
}
