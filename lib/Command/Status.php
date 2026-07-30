<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Command;

use OCA\PenpotSync\Service\ArchiveService;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\StorageService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ penpot_sync:status <path>`
 *
 * Read-only inspector: given a path in the sync actor's files, print the Penpot
 * metadata stamped on that node and — the point of the command — the membership
 * {@see MembershipResolver} derives for it by walking its ancestors.
 *
 * ## WHY THIS EXISTS
 *
 * The resolver is *the single most load-bearing rule in the app* (saga §6.29),
 * and until now it had no way to be run against a real folder tree. This command
 * gives it one: after a pull, `status` on a mirrored file reports the exact
 * `in_project` / `drafts` / `personal` / `none` verdict the reconciler and every
 * write path depend on — so the integration suite asserts the resolver on a live
 * Nextcloud, not only in unit mocks. It is also the honest "why is this file
 * here / where does Penpot think it lives" answer for an operator.
 *
 * It never writes. Safe to run anytime.
 */
final class Status extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private StorageService $storage,
		private MembershipResolver $resolver,
		private PenpotMetadata $metadata,
		private ArchiveService $archives,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('penpot_sync:status')
			->setDescription('Show the Penpot metadata and resolved membership for a path (read-only).')
			->addArgument('path', InputArgument::REQUIRED, "A path in the sync actor's files, e.g. 'Penpot/Acme/Login.penpot'.");
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = trim((string)$input->getArgument('path'), '/');

		try {
			$home = $this->rootFolder->getUserFolder($this->storage->resolveActorUid());
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		if ($path === '' || !$home->nodeExists($path)) {
			$output->writeln('<error>No such node: ' . $path . '</error>');

			return 1;
		}

		$node = $home->get($path);
		$output->writeln('Path: ' . $path);
		$output->writeln('Type: ' . ($node instanceof Folder ? 'folder' : 'file'));

		if ($node instanceof File) {
			$file = $this->metadata->readFile($node->getId());
			$output->writeln('penpot_id: ' . ($file?->penpotId ?? ''));
			$output->writeln('penpot_revision: ' . ($file?->revision ?? ''));
			$output->writeln('penpot_mode: ' . ($file?->mode ?? ''));
			// The stamp says what the file is SUPPOSED to hold; this says what it
			// actually holds. They can disagree — a `sync` file whose archive never
			// arrived reads `sync` / `pointer`, which is precisely the drift the
			// pull self-heals. Printing both is what makes that visible instead of
			// something you infer from a byte count.
			$output->writeln(sprintf('Content: %s (%d bytes)', $this->describe($node), $node->getSize()));
			$output->writeln('penpot_team_id: ' . ($file?->teamId ?? ''));
		} elseif ($node instanceof Folder) {
			$markers = $this->metadata->readFolder($node->getId());
			$output->writeln('penpot_project_id: ' . $markers->projectId);
			$output->writeln('penpot_team_id: ' . $markers->teamId);
		}

		$membership = $this->resolver->resolve($node);
		$output->writeln(sprintf(
			'Membership: %s (team=%s project=%s)',
			$membership->state(),
			$membership->teamId ?? '',
			$membership->projectId ?? '',
		));

		// THE FOLDER WALK VERIFIES THE STAMP (§C6.7). `penpot_team_id` is cached on
		// the file because the browser cannot afford to walk a freely-nested tree
		// to build a deep link — but a cache with no way to check it is just a
		// rumour. The resolver is the authority, so where the two disagree, say so
		// here rather than let a link quietly open the wrong team's workspace.
		//
		// Only a MISMATCH is reported, and only when the walk actually found a
		// team: a file resolving to no team is the *unmapped* state, where the
		// stamp is the last surviving record of where the design lives and is
		// therefore right to differ.
		if ($node instanceof File
			&& $membership->teamId !== null
			&& ($file?->teamId ?? '') !== ''
			&& $file->teamId !== $membership->teamId) {
			$output->writeln(sprintf(
				'<comment>Team mismatch: stamped %s, folders say %s. The next pull re-stamps it.</comment>',
				$file->teamId,
				$membership->teamId,
			));
		}

		return 0;
	}

	/** What the file on disk actually is: a real export, a pointer, or nothing. */
	private function describe(File $node): string {
		if ($node->getSize() === 0) {
			return 'empty';
		}

		return $this->archives->holdsArchive($node) ? 'archive' : 'pointer';
	}
}
