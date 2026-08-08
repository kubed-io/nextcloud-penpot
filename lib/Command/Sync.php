<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\PullStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:sync [pull] [--mapping=ID]`
 *
 * Run the pull (Penpot → Nextcloud) — the CLI twin of the future scheduled job
 * and admin button, calling the one {@see PullService::pull()} they all will.
 *
 * ## WHY `pull` IS AN ARGUMENT AND NOT THE COMMAND NAME
 *
 * The saga names this `penpot_sync:sync pull` (Ch2 Course 3): the same command
 * grows a `push` direction in Course 4. Keeping `sync` as the verb and the
 * direction as an argument means that later course adds a branch, not a new
 * command — and the muscle memory (`occ penpot_sync:sync …`) never changes.
 * Only `pull` is implemented today; anything else is refused with a clear
 * message rather than silently doing a pull.
 */
final class Sync extends Command {
	private const DIR_PULL = 'pull';

	public function __construct(
		private PullService $pull,
		private PullStatus $status,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:sync')
			->setDescription('Mirror Penpot into Nextcloud (pull). The CLI twin of the scheduled job.')
			->addArgument(
				'direction',
				InputArgument::OPTIONAL,
				'Sync direction. Only "pull" is implemented; "push" lands in a later course.',
				self::DIR_PULL,
			)
			->addOption(
				'mapping',
				null,
				InputOption::VALUE_REQUIRED,
				'Restrict the run to a single mapping id (see penpot_sync:list-mappings). Default: every mapping.',
				'',
			)
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Run even if another sync is recorded as still going. Use when a previous run was killed.',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$direction = (string)$input->getArgument('direction');
		if ($direction !== self::DIR_PULL) {
			$output->writeln('<error>Only "pull" is implemented. Push lands in a later course.</error>');

			return 1;
		}

		$mappingId = (string)$input->getOption('mapping');

		// ONE PULLER AT A TIME: two runs over one folder tree race on the same
		// files. The section's button and the scheduled job already refuse; so
		// does this now.
		//
		// WITH AN ESCAPE HATCH, because a CLI is not a button. `isBusy()` reads a
		// STORED flag, so a run killed outright — SIGKILL, an evicted pod — leaves
		// it stuck at `running` forever, and the CLI is the headless door an
		// operator reaches for when the UI is the thing misbehaving. Refusing
		// without a way through would wedge the one tool that could unwedge it.
		if (!$input->getOption('force') && $this->status->isBusy()) {
			$output->writeln(
				'<error>A sync is already running. Wait for it to finish, or re-run with '
				. '--force if a previous run was killed and left this stuck.</error>',
			);

			return 1;
		}

		// THE RUN IS RECORDED WHATEVER STARTED IT. The scheduled job and the
		// admin panel both stamp {@see PullStatus}, and the CLI did not — so
		// `show-config` reported the last SCHEDULED run as "the last run" while a
		// CLI sync minutes earlier left no trace. One store, every trigger.
		$this->status->markStarted();

		// EVERY EXIT FROM HERE CLEARS `running`, which is why this catches
		// \Throwable rather than the one exception with a nice message. A
		// PenpotApiException genuinely does escape `pull()` today — an unreachable
		// Penpot or a rejected token — and leaving the status stuck at `running`
		// would make `isBusy()` true forever, so the panel would refuse to start
		// another sync until the value was cleared by hand.
		try {
			$result = $this->pull->pull($mappingId === '' ? null : $mappingId);
		} catch (\OutOfBoundsException $e) {
			$this->status->markFailed($e->getMessage());
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		} catch (\Throwable $e) {
			$this->status->markFailed($e->getMessage());
			$output->writeln('<error>The sync failed: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$this->status->markFinished($result);

		$output->writeln(sprintf(
			'Pulled %d project(s): %d folder(s), %d file(s), %d archive(s) exported, %d skipped.',
			$result['processed'],
			$result['folders'],
			$result['files'],
			$result['exported'],
			$result['skipped'],
		));

		// Reported separately from `skipped`, and separately from an error,
		// because it is a third thing: those files ARE mirrored and current in
		// every respect but their bytes, the previous archive is untouched, and
		// the next pull retries. Silence here would let a team quietly stop
		// being backed up while every pull still says "ok".
		if ($result['failed'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d archive(s) could not be exported. The previous content was kept; '
				. 'the next pull will try again.</comment>',
				$result['failed'],
			));
		}

		// The prune is the one thing here that removes something, so it always
		// says so — and says where it went. `rescued` is the pointer that became a
		// real archive on its way out; `lost` is the one Penpot could no longer
		// export, which is the honest word for a pointer to a design that is gone.
		if ($result['pruned'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d design(s) no longer exist in Penpot. Their mirrors were moved to the '
				. 'Nextcloud trash: %d saved as a final archive first, %d could not be recovered.</comment>',
				$result['pruned'],
				$result['rescued'],
				$result['lost'],
			));
		}

		if ($result['status'] !== 'ok') {
			$output->writeln('<error>Some mappings failed: ' . (string)$result['message'] . '</error>');

			return 1;
		}

		$output->writeln('<info>Pull complete.</info>');

		return 0;
	}
}
