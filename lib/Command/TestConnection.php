<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\ConnectionResult;
use OCA\PenpotSync\Service\ConnectionTester;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:test-connection`
 *
 * The headless twin of the admin Test connection button — the *same*
 * {@see ConnectionTester}, so the CLI and the UI can never disagree about
 * whether a token is unset, rejected, or simply unable to see any team.
 *
 * ## HOW THIS DIFFERS FROM `penpot_sync:probe`
 *
 * `probe` is a diagnostic: it walks teams *and* projects (and optionally files)
 * and prints everything, to answer "is it my code or the connection?" during
 * development. This is the operator's check — one call, one verdict, an exit
 * code a script can gate on. Keeping both is deliberate; collapsing them would
 * make the fast check slow and the deep check terse.
 *
 * Exit 0 when connected — INCLUDING the "no teams visible" case, which is a
 * successful connection that happens to have nothing to map yet. A script
 * checking reachability should not fail on a missing invite.
 */
final class TestConnection extends Command {
	public function __construct(
		private ConnectionTester $tester,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:test-connection')
			->setDescription('Check the Penpot connection (same as the admin Test connection button).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->tester->test();

		if (!$result->success) {
			$output->writeln('<error>' . $result->message . '</error>');

			return 1;
		}

		if ($result->kind === ConnectionResult::KIND_NO_TEAMS) {
			$output->writeln('<comment>' . $result->message . '</comment>');

			return 0;
		}

		$output->writeln('<info>' . $result->message . '</info>');

		return 0;
	}
}
