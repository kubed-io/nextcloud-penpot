<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\BulkPushService;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\PullStatus;
use OCA\PenpotSync\Service\PushStatus;
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
 * The saga names this `penpot_sync:sync pull` (Ch2 Course 3), anticipating the
 * `push` direction that has now landed. Keeping `sync` as the verb and the
 * direction as an argument meant that arrival was a branch rather than a new
 * command, and the muscle memory (`occ penpot_sync:sync …`) never changed.
 *
 * The two directions are NOT symmetric and the asymmetry is the point: a pull
 * mirrors everything Penpot has, while a push only makes designs of archives
 * Penpot has never seen ({@see BulkPushService}). Neither ever overwrites a design
 * that already exists (§6.1).
 */
final class Sync extends Command {
	private const DIR_PULL = 'pull';
	private const DIR_PUSH = 'push';

	public function __construct(
		private PullService $pull,
		private PullStatus $status,
		private BulkPushService $push,
		private PushStatus $pushStatus,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:sync')
			->setDescription('Sync between Penpot and Nextcloud in either direction. The CLI twin of the two admin buttons.')
			->addArgument(
				'direction',
				InputArgument::OPTIONAL,
				'Sync direction: "pull" (Penpot into Nextcloud) or "push" (make designs of the archives already here).',
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
		if ($direction !== self::DIR_PULL && $direction !== self::DIR_PUSH) {
			$output->writeln('<error>Direction must be "pull" or "push".</error>');

			return 1;
		}

		$mappingId = (string)$input->getOption('mapping');

		if ($direction === self::DIR_PUSH) {
			return $this->runPush($input, $output, $mappingId === '' ? null : $mappingId);
		}

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

		// The other removal, and the only irreversible one this command can cause —
		// so it is never folded into the line above. A design destroyed in Penpot
		// takes its already-trashed mirror with it (`designs/purge.feature`).
		if ($result['reaped'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d mirror(s) were emptied from the Nextcloud trash because their design '
				. 'was permanently deleted in Penpot.</comment>',
				$result['reaped'],
			));
		}

		// The FOLDER-shaped removal, which the two above cannot cover because both
		// count files. A project deleted in Penpot retires its folder — emptied to
		// the trash, or kept and stripped of its project id when it still holds
		// something that was never Penpot's (`projects/delete.feature`).
		if ($result['orphaned'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d folder(s) stopped being a Penpot project, because the project was '
				. 'deleted in Penpot.</comment>',
				$result['orphaned'],
			));
		}

		if ($result['status'] !== 'ok') {
			$output->writeln('<error>Some mappings failed: ' . (string)$result['message'] . '</error>');

			return 1;
		}

		$output->writeln('<info>Pull complete.</info>');

		return 0;
	}

	/**
	 * `occ penpot_sync:sync push` — the CLI twin of "Sync to Penpot".
	 *
	 * SYNCHRONOUS, UNLIKE THE BUTTON. The panel queues a job because a request
	 * that dies half way leaves no record of how far it got; a CLI invocation has
	 * an operator watching it and an exit code to answer with, so it runs inline
	 * and reports. Both stamp {@see PushStatus} for the same reason the pull does:
	 * "when did this last sync?" must have one answer per direction, whatever
	 * started it.
	 */
	private function runPush(InputInterface $input, OutputInterface $output, ?string $mappingId): int {
		if (!$input->getOption('force') && $this->pushStatus->isBusy()) {
			$output->writeln(
				'<error>A push is already running. Wait for it to finish, or re-run with '
				. '--force if a previous run was killed and left this stuck.</error>',
			);

			return 1;
		}

		$this->pushStatus->markStarted();

		// EVERY EXIT CLEARS `running`, exactly as the pull branch does — a status
		// stuck at `running` makes `isBusy()` true forever and wedges the panel.
		try {
			$result = $this->push->push($mappingId);
		} catch (\Throwable $e) {
			$this->pushStatus->markFailed($e->getMessage());
			$output->writeln('<error>The push failed: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$this->pushStatus->markFinished($result);

		$output->writeln(sprintf(
			'Pushed %d design(s) from %d candidate file(s); %d failed, %d link mapping(s) skipped.',
			$result['pushed'],
			$result['processed'],
			$result['failed'],
			$result['skipped'],
		));

		if ($result['message'] !== null) {
			$output->writeln('<comment>' . $result['message'] . '</comment>');
		}

		return $result['failed'] === 0 ? 0 : 1;
	}
}
