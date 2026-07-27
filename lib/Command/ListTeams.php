<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:list-teams`
 *
 * The teams the service account can see, with their ids — the lookup step
 * before `add-mapping`, and the CLI equivalent of the mapping card's team
 * picker.
 *
 * ## IT MARKS WHAT IS ALREADY MAPPED
 *
 * Because the next thing anyone does with this output is map something, and
 * "team is already mapped" is the most common way `add-mapping` fails. Showing
 * it here turns a failed command into information the operator already had.
 *
 * ## AN EMPTY LIST IS NOT AN ERROR
 *
 * Penpot's visibility is membership-scoped (saga §6.12), so a perfectly valid
 * token can legitimately see nothing. That is the state that blocks all mapping,
 * so it exits 0 with an explanation of the fix rather than a bare "no results" —
 * or worse, a failure that suggests the connection is broken.
 */
final class ListTeams extends Command {
	public function __construct(
		private MappingService $mappings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:list-teams')
			->setDescription('List the Penpot teams the service account can see.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$teams = $this->mappings->visibleTeams();
		} catch (PenpotApiException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		if ($teams === []) {
			$output->writeln('<comment>The service account can see no Penpot teams.</comment>');
			$output->writeln('Invite it to a team in Penpot, then run this again.');

			return 0;
		}

		$output->writeln(sprintf('%-38s %-28s %s', 'TEAM ID', 'NAME', 'MAPPED'));

		foreach ($teams as $id => $team) {
			$name = is_string($team['name'] ?? null) ? $team['name'] : '(unnamed)';
			$mapped = $this->mappings->getByTeamId($id) !== null;

			$output->writeln(sprintf(
				'%-38s %-28s %s',
				$id,
				$name,
				$mapped ? 'yes' : '-',
			));
		}

		return 0;
	}
}
