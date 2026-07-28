<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\Mapping;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `occ penpot_sync:set-mode <path> <sync|link> [--force]`
 *
 * Promote a mirrored design to a stored archive, or demote it back to a pointer
 * (saga §6.22). "Which files are worth backing up" is a human judgement that
 * cannot be derived — a mapping carries a default, and this is how one file
 * departs from it.
 *
 * ## THE TWO DIRECTIONS ARE NOT SYMMETRICAL
 *
 * **Promotion is additive and safe.** It exports the design and stores the real
 * archive. Nothing is written to Penpot, the `penpot_id` is unchanged, and every
 * restriction a `link` file carries (§6.43) lifts at once — because those
 * restrictions were never a policy about links, they are a property of holding
 * no bytes.
 *
 * **Demotion deletes a local backup.** Penpot is not contacted and the design is
 * completely unaffected, but the archive is bytes nobody upstream is keeping for
 * us: re-acquiring them costs a fresh export, and costs *nothing at all* if the
 * design has since been deleted in Penpot. So it is confirmed, and `--force` is
 * the deliberate way to skip the question in a script.
 *
 * ## WHY A COMMAND AND NOT A FILE ACTION (yet)
 *
 * The Files-app surface lands with the rest of the frontend. Shipping the CLI
 * first is the same ordering the whole app follows: the mechanism gets proven
 * against a real Penpot and a real filesystem before a button is drawn on top of
 * it — and unlike a button, this is testable from the integration suite today.
 */
final class SetMode extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private StorageService $storage,
		private MembershipResolver $resolver,
		private PenpotMetadata $metadata,
		private ArchiveService $archives,
		private SyncGuard $guard,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:set-mode')
			->setDescription('Promote a mirrored design to "sync" (stores the archive) or demote it to "link".')
			->addArgument('path', InputArgument::REQUIRED, "A path in the sync actor's files, e.g. 'Penpot/Acme/Login.penpot'.")
			->addArgument('mode', InputArgument::REQUIRED, 'sync or link.')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation when demoting (which deletes the stored archive).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mode = strtolower(trim((string)$input->getArgument('mode')));
		if (!in_array($mode, [Mapping::MODE_SYNC, Mapping::MODE_LINK], true)) {
			$output->writeln('<error>Mode must be "sync" or "link".</error>');

			return 1;
		}

		$node = $this->resolveFile(trim((string)$input->getArgument('path'), '/'), $output);
		if ($node === null) {
			return 1;
		}

		$stamped = $this->metadata->readFile($node->getId());
		if ($stamped === null || $stamped->penpotId === '') {
			$output->writeln('<error>That file is not a mirrored Penpot design (it carries no penpot_id).</error>');

			return 1;
		}

		if ($stamped->mode === $mode) {
			// Not an error, and not silence either: a no-op the user asked for
			// should say so, or "nothing happened" reads as a failure.
			$output->writeln(sprintf('<comment>Already in "%s" mode. Nothing to do.</comment>', $mode));

			return 0;
		}

		return $mode === Mapping::MODE_SYNC
			? $this->promote($node, $stamped->penpotId, $output)
			: $this->demote($node, $stamped->penpotId, $input, $output);
	}

	/**
	 * Fetch the archive, then stamp the mode.
	 *
	 * THE ORDER IS THE POINT. Stamping first would leave a file claiming to be a
	 * backup while holding a pointer if the export failed — and the pull's
	 * self-healing check would then quietly re-export it later, turning a visible
	 * failure into a delayed surprise. Export first, and a failure changes
	 * nothing at all.
	 */
	private function promote(File $node, string $penpotId, OutputInterface $output): int {
		$output->writeln('Exporting from Penpot…');

		try {
			// The write is ours, not a user's gesture — raise the guard so the
			// content change never looks like something to push back.
			$bytes = $this->guard->run(fn (): int => $this->archives->storeArchive($node, $penpotId));
		} catch (PenpotApiException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('<comment>The file is unchanged and still in "link" mode.</comment>');

			return 1;
		}

		$this->metadata->writeFile($node->getId(), [PenpotMetadata::KEY_MODE => Mapping::MODE_SYNC]);

		$output->writeln(sprintf('<info>Promoted to "sync".</info> Stored %d bytes.', $bytes));
		$output->writeln('Nothing was written to Penpot.');

		return 0;
	}

	/**
	 * Confirm, then replace the archive with a pointer.
	 *
	 * The revision stamp is deliberately LEFT ALONE. It describes which Penpot
	 * revision this mirror reflects, and demoting does not change that — the
	 * pointer still names revision N. Clearing it would make the next promotion
	 * look like drift rather than a promotion.
	 */
	private function demote(File $node, string $penpotId, InputInterface $input, OutputInterface $output): int {
		$holdsArchive = $this->archives->holdsArchive($node);

		if ($holdsArchive && !$input->getOption('force')) {
			$output->writeln('<comment>This deletes the stored archive — a LOCAL backup.</comment>');
			$output->writeln('Penpot is not contacted and the design is unaffected, but getting these');
			$output->writeln('bytes back costs a fresh export, and is impossible if the design is deleted.');

			$helper = $this->getHelper('question');
			if (!$helper instanceof \Symfony\Component\Console\Helper\QuestionHelper) {
				// No interactive helper available (a stripped console). Refusing is
				// the only safe answer: the alternative is deleting a backup because
				// we could not ask.
				$output->writeln('<error>Cannot confirm interactively. Re-run with --force if you are sure.</error>');

				return 1;
			}

			if (!$helper->ask($input, $output, new ConfirmationQuestion('Delete the stored archive? [y/N] ', false))) {
				$output->writeln('Cancelled. Nothing was deleted.');

				return 0;
			}
		}

		$membership = $this->resolver->resolve($node);
		$stamped = $this->metadata->readFile($node->getId());
		// The stamp is the joined drift signal; the pointer body keeps the two
		// halves in separate fields, so it has to come back apart here.
		[$revn, $modifiedAt] = ArchiveService::splitSignal($stamped?->revision ?? '');

		$this->guard->run(function () use ($node, $penpotId, $revn, $modifiedAt, $membership): void {
			$this->archives->storeLink(
				$node,
				$penpotId,
				// The pointer's `name` is the design's Penpot name, which is the
				// mirror's name minus the Nextcloud-side extension (§6.4).
				preg_replace('/\.penpot$/', '', $node->getName()) ?? $node->getName(),
				$revn,
				$modifiedAt,
				$membership->teamId ?? '',
			);
		});

		$this->metadata->writeFile($node->getId(), [PenpotMetadata::KEY_MODE => Mapping::MODE_LINK]);

		$output->writeln('<info>Demoted to "link".</info> The stored archive was removed.');
		$output->writeln('Penpot was never contacted; the design is unaffected.');

		return 0;
	}

	/** Resolve a path in the sync actor's home to a File, reporting why not. */
	private function resolveFile(string $path, OutputInterface $output): ?File {
		try {
			$home = $this->rootFolder->getUserFolder($this->storage->resolveActorUid());
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return null;
		}

		if ($path === '' || !$home->nodeExists($path)) {
			$output->writeln('<error>No such node: ' . $path . '</error>');

			return null;
		}

		$node = $home->get($path);
		if (!$node instanceof File) {
			$output->writeln('<error>That path is a folder. Modes are per-file.</error>');

			return null;
		}

		return $node;
	}
}
