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
 * `occ penpot_sync:add-mapping <team-id> [--folder=] [--groups=] [--team-folder] [--mode=link]`
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
				'Comma-separated Nextcloud groups to share the folder with. '
				. 'Change them later with penpot_sync:set-groups.',
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
			// THE ONE OPTION THAT DESTROYS SOMETHING, so it is opt-in, it is spelled
			// out, and without it the mapping is refused rather than made quietly.
			// The refusal names the count; this is how an admin answers it.
			->addOption(
				'purge-designs',
				null,
				InputOption::VALUE_NONE,
				'Permanently delete any .penpot files already under the folder, which a '
				. 'link mapping requires. They do NOT go to the trash and cannot be '
				. 'recovered. Without this, a folder holding designs is refused.',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$mapping = Mapping::fromArray([
				'team_id' => (string)$input->getArgument('team-id'),
				'nc_folder' => (string)$input->getOption('folder'),
				'use_team_folder' => (bool)$input->getOption('team-folder'),
				'mode' => (string)$input->getOption('mode'),
			]);

			// Groups ride alongside rather than on the mapping: they are applied to
			// the folder, not stored (§C6.35). A comma-separated string is accepted
			// verbatim by the normaliser, so the CLI parses nothing of its own.
			$saved = $this->service->add(
				$mapping,
				(string)$input->getOption('groups'),
				(bool)$input->getOption('purge-designs'),
			);
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
		// Read back off the folder rather than echoing the flag: a group that does
		// not exist cannot be shared with, and the admin should see that here
		// rather than believe the mapping is set up the way they typed it.
		$groups = $this->service->groupsOf($saved);

		$output->writeln('  folder: ' . $saved->ncFolder
			. ($saved->useTeamFolder ? ' (Team Folder)' : ' (shared folder)'));
		$output->writeln('  groups: ' . ($groups === [] ? '(none)' : implode(', ', $groups)));
		$output->writeln('  mode: ' . $saved->mode);

		return 0;
	}
}
