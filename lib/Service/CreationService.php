<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * A new `.penpot` file becomes a real design in Penpot (`create-design.feature`,
 * saga §6.33/§C6.11).
 *
 * ## THE SECOND DELIBERATE CARVE-OUT OF §6.1
 *
 * §6.1 locks Nextcloud out of design CONTENT, and it still does: nothing here
 * pushes shape data. Creating a *container* on an explicit user gesture is a
 * different act, and it is the one both siblings make. `create-file` was
 * confirmed live in §C6.11 — kebab `project-id`, `name` required — which closed
 * open question #27.
 *
 * ## THE SCOPING RULE: ONLY WHERE THE PROJECT IS UNAMBIGUOUS
 *
 * Penpot has no team-level or rootless design; `create-file` requires a project.
 * So creation happens only where one resolves, and a team root resolves to that
 * team's Drafts (§6.35) via the shared {@see DestinationResolver} — the same
 * lookup the copy and move paths use, for the same reason.
 *
 * Landing outside every mapping creates nothing. The file is simply a file, and
 * that is what keeps a mapped folder usable as an ordinary folder.
 *
 * ## THE GUARD NEITHER SIBLING NEEDS: AN UPLOAD IS NOT A CREATE
 *
 * n8n and Grafana mirror JSON, so a file appearing in a mapped folder is either
 * new or an edit — both of which mean "register it". Ours can be a **real
 * `.penpot` archive** someone dragged in, and creating an empty design for it
 * would be actively destructive: the file would hold a full design while the
 * Penpot side held nothing, and the next `sync` pull would overwrite those bytes
 * with the empty export.
 *
 * So a file that already holds an archive is left alone. Turning an uploaded
 * archive into a design is an IMPORT (`import-binfile`), which is restore's job
 * and needs a human to say which design it is meant to be — not something to
 * infer from a drag.
 */
final class CreationService {
	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly DestinationResolver $destinations,
		private readonly ArchiveService $archives,
		private readonly MappingService $mappings,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/** A `.penpot` file was written. Register it in Penpot if it is genuinely new. */
	public function onWritten(File $node): void {
		if (($this->metadata->readFile($node->getId())?->penpotId ?? '') !== '') {
			// Already ours. A re-write of a tracked mirror is the pull, an editor,
			// or a sync client — never a creation.
			return;
		}

		if ($this->archives->holdsArchive($node)) {
			// An uploaded archive. See the class docblock: importing it is a
			// different, human-directed act, and creating an empty design for it
			// would set the file and the design against each other.
			$this->logger->info('penpot_sync create: a .penpot archive was added; left untracked (import is not a create)', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
			]);

			return;
		}

		$membership = $this->resolver->resolve($node);
		// A DESIGN ARRIVING IS WHAT MAKES ITS FOLDER A PROJECT (`projects/create.feature`).
		// Not `projectFor()`: that one only answers, and a "+ New" in a folder Penpot
		// has never seen used to file the design into Drafts and leave the folder
		// meaning nothing.
		$project = $this->destinations->projectForContentIn($node, $membership);
		if ($project === null) {
			// Outside every mapping — an ordinary file in an ordinary folder.
			return;
		}

		$name = $this->penpotName($node->getName());
		if ($name === '') {
			return;
		}

		try {
			$created = $this->client->createFile($project, $name);
		} catch (\Throwable $e) {
			// §6.18 rule 3: the local file stands, untracked. The user still has
			// the file they made; it is simply not a design yet.
			$this->logger->warning('penpot_sync create: could not create the design; the file is untracked', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'project' => $project,
				'exception' => $e,
			]);

			return;
		}

		$newId = (string)($created['id'] ?? '');
		if ($newId === '') {
			$this->logger->warning('penpot_sync create: create-file returned no id', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
			]);

			return;
		}

		// BORN IN ITS MAPPING'S MODE. It used to be born a `link` unconditionally,
		// justified by "a promotion is one command away" — and that command is gone.
		// Under a `sync` mapping the file would have been a pointer nothing could
		// ever turn into an archive, sitting in a folder whose every other design
		// holds one. The mapping decides the mode; a file created inside it is no
		// exception.
		//
		// THE STAMP FIRST, THE BYTES SECOND, and the order is load-bearing: the
		// export below writes through the Node API and would re-enter this method,
		// where an id already on the file is what says "not a creation".
		$mode = $this->modeFor($membership);
		$this->metadata->writeFile($node->getId(), [
			PenpotMetadata::KEY_ID => $newId,
			PenpotMetadata::KEY_MODE => $mode,
			PenpotMetadata::KEY_TEAM_ID => $membership->teamId ?? '',
		]);

		$this->logger->info('penpot_sync create: created a design in Penpot', [
			'app' => Application::APP_ID,
			'penpot_id' => $newId,
			'project' => $project,
			'name' => $name,
		]);

		if ($mode === Mapping::MODE_SYNC) {
			$this->storeFirstArchive($node, $newId, $created);
		}
	}

	/**
	 * Export the design that was just created, so the file is a mirror at once.
	 *
	 * ## WHY THE CREATE PAYS FOR AN EXPORT IT USED TO SKIP
	 *
	 * This used to end at the metadata write, reasoning that a design created
	 * empty has nothing worth storing and that the next pull's drift check would
	 * fill the body in on {@see ArchiveService}'s self-healing path. True, and it
	 * left a state nothing else in the app produces on purpose: a file stamped
	 * `sync` holding zero bytes and carrying no revision — which
	 * {@see \OCA\PenpotSync\Command\Status} reports as `sync` / `pointer` and calls
	 * drift in as many words. `designs/create.feature` asserts an archive and a
	 * revision on the line after the gesture, and it is right to: "the design is
	 * created" and "the file is its mirror" should not be minutes apart, with the
	 * gap visible to anyone who opens the folder.
	 *
	 * An empty design exports to a perfectly valid `.penpot`; there is no such
	 * thing as too early here. The cost is one export per creation, which is a
	 * human gesture and not a bulk path.
	 *
	 * ## FENCED, BECAUSE THIS IS THE APP WRITING TO ITS OWN MIRROR
	 *
	 * {@see \OCA\PenpotSync\Listener\CreateListener} is what turns a write into a
	 * creation, and the guard is what tells the app's own writes from a person's.
	 * The id stamped a moment ago would already make the re-entry a no-op, so this
	 * is belt and braces — but it is the same belt every other internal write
	 * wears, and the alternative is relying on ordering that a later edit could
	 * quietly reverse.
	 *
	 * ## A FAILED EXPORT IS NOT A FAILED CREATION (§6.18 rule 3)
	 *
	 * The design exists in Penpot and the file names it. Leaving the revision
	 * unstamped is exactly the signal the pull's drift check reads, so the body
	 * arrives on the next sync down the path this method just stopped relying on.
	 *
	 * @param array<string, mixed> $created the `create-file` response — a full file
	 *                                      record, so it carries the `revn` and
	 *                                      `modified-at` the drift signal is built
	 *                                      from (backend `files_create.clj` returns
	 *                                      `bfc/get-file`)
	 */
	private function storeFirstArchive(File $node, string $penpotId, array $created): void {
		try {
			$this->guard->run(function () use ($node, $penpotId): void {
				$this->archives->storeArchive($node, $penpotId);
			});
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync create: the design was made but its archive did not arrive; the next pull will fetch it', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'file' => $node->getName(),
				'exception' => $e,
			]);

			return;
		}

		$this->metadata->writeFile($node->getId(), [
			PenpotMetadata::KEY_REVISION => ArchiveService::signal(
				(string)($created['revn'] ?? ''),
				is_string($created['modified-at'] ?? null) ? $created['modified-at'] : '',
			),
		]);
	}

	/**
	 * The mode a design created here is born in — its mapping's.
	 *
	 * Falls back to `link` when the team resolves to no mapping, which is the safe
	 * direction: `link` stores nothing, so guessing it costs nothing, while
	 * guessing `sync` would promise an archive no mapping asked for.
	 */
	private function modeFor(Membership $membership): string {
		$teamId = $membership->teamId ?? '';
		if ($teamId === '') {
			return Mapping::MODE_LINK;
		}

		return $this->mappings->getByTeamId($teamId)?->mode ?? Mapping::MODE_LINK;
	}

	/** The filename as a Penpot name: extension off (§6.4), capped at the schema's 250. */
	private function penpotName(string $fileName): string {
		$bare = preg_replace('/\.penpot$/', '', $fileName) ?? $fileName;

		return mb_substr(trim($bare), 0, 250);
	}
}
