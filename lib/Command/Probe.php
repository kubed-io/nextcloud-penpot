<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\PenpotClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:probe [--files]`
 *
 * Exercise the client against the configured Penpot and print what came back.
 *
 * WHY THIS SHIPS WITH THE CLIENT AND NOT LATER (saga Ch2, Course 1): the next
 * course builds the admin surface, and the one after builds the pull. Both will
 * be debugged against a live instance, and without this the first question —
 * *"is it my code or is it the connection?"* — has no cheap answer. It is also
 * the natural "Test connection" backend before a settings card exists to call it.
 *
 * WHAT IT REPORTS IS CHOSEN, NOT INCIDENTAL. It prints the **teams the token can
 * see**, because Penpot's visibility is always membership-scoped (saga §6.12) —
 * there is no admin view. An authenticated token that sees no teams is a real,
 * ordinary state, and it is exactly the state that blocks mapping (saga §6.18).
 * "Connection OK" would hide the one fact an admin needs.
 */
final class Probe extends Command {
	public function __construct(
		private PenpotClient $client,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:probe')
			->setDescription('Check the Penpot connection and list what the token can see.')
			->addOption('files', null, InputOption::VALUE_NONE, 'Also list the files in each project.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$teams = $this->client->ping();
		} catch (PenpotApiException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('<comment>kind: ' . $e->getKind() . '</comment>');

			return 1;
		}

		if ($teams === []) {
			// Authenticated, but a member of nothing. Not an error — and not a
			// state to paper over, since no team can be mapped until it's fixed.
			$output->writeln('<comment>Connected, but this token can see no teams.</comment>');
			$output->writeln('Invite the service account to a Penpot team as "viewer" first.');

			return 0;
		}

		$output->writeln('<info>Connected.</info> Visible teams: ' . implode(', ', $teams));

		try {
			$projects = $this->client->getAllProjects();
		} catch (PenpotApiException $e) {
			$output->writeln('<error>Could not list projects: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('');
		$output->writeln('Projects (' . count($projects) . '):');

		foreach ($projects as $project) {
			$name = is_string($project['name'] ?? null) ? $project['name'] : '(unnamed)';
			$id = is_string($project['id'] ?? null) ? $project['id'] : '';
			$team = is_string($project['team-name'] ?? null) ? $project['team-name'] : '';

			$output->writeln(sprintf('  %-24s %s  [%s]', $name, $id, $team));

			if (!$input->getOption('files') || $id === '') {
				continue;
			}

			try {
				foreach ($this->client->getProjectFiles($id) as $file) {
					$fileName = is_string($file['name'] ?? null) ? $file['name'] : '(unnamed)';
					$revn = $file['revn'] ?? '?';
					$output->writeln(sprintf('      %-20s revn=%s  %s', $fileName, (string)$revn, (string)($file['id'] ?? '')));
				}
			} catch (PenpotApiException $e) {
				$output->writeln('      <error>' . $e->getMessage() . '</error>');
			}
		}

		return 0;
	}
}
