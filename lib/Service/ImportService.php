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
 * A `.penpot` ARCHIVE arriving inside a mapping becomes a real design (§6.33).
 *
 * ## WHY THIS IS ITS OWN SERVICE AND NOT A BRANCH IN THREE OTHERS
 *
 * An archive can arrive four ways — uploaded, created-with-content, copied in,
 * dragged in — and each is a different Nextcloud event handled by a different
 * class. What happens next is identical in all four, and it used not to happen at
 * all: {@see CreationService} logged *"a .penpot archive was added; left
 * untracked (import is not a create)"* and stopped, {@see MotionService} returned
 * on `!$meta->isManaged()`, and {@see CopyService} had no source id to duplicate
 * from. Three classes independently declining the same thing.
 *
 * The reasoning behind that was right about the DANGER and wrong about the
 * conclusion. Creating an EMPTY design for a file that already holds an archive
 * would be destructive: the file would hold a full design while Penpot's side held
 * nothing, and the next `sync` pull would overwrite those bytes with the empty
 * export. That is still true, and it is exactly why the answer is to IMPORT the
 * bytes rather than to invent a design beside them.
 *
 * ## WHAT AN IMPORT IS, AND WHAT IT IS NOT
 *
 * It is not a restore. A restore knows which design the bytes used to be and tries
 * to put them back; that is impossible at the original id (§6.20 — a deleted
 * Penpot file cannot be resurrected) and is {@see RestoreService}'s problem. This
 * is the simpler act: these bytes are a design, this folder is a project, so make
 * the design and stamp the file with what came back.
 *
 * ## THE NAME COMES FROM THE FILE, IN A SECOND CALL
 *
 * `import-binfile` accepts a `name` and ignores it (§6.20, live) — the design is
 * called whatever the archive says inside, which is the name it had when someone
 * exported it. §6.4 says a design's name IS its filename minus the extension, so
 * the import is followed by a rename whenever the two disagree. `duplicate-file`
 * is the opposite and honours its `name`; that asymmetry is why both are written
 * down rather than remembered.
 *
 * ## A FAILED IMPORT LEAVES AN ORDINARY FILE (§6.18 rule 3)
 *
 * Never a half-tracked one. The bytes are the user's and they are still a valid
 * `.penpot` they can open elsewhere; the file simply is not a design here. That is
 * honest and recoverable, and it is the state the app was already in before any of
 * this — so the failure path is the old behaviour exactly.
 */
final class ImportService {
	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly ArchiveService $archives,
		private readonly MappingService $mappings,
		private readonly PersonalTokenService $personalTokens,
		private readonly SyncNotifier $notifier,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Import this file's archive into $project, and stamp what came back.
	 *
	 * @param string $teamId the team the destination belongs to, for the file's
	 *                       cached `penpot_team_id` (§C6.7) — the browser reaches
	 *                       for it without walking the tree
	 *
	 * @return string|null the new design's id, or null when the file was left as an
	 *                     ordinary file (not an archive, or Penpot refused it)
	 */
	public function adopt(File $node, string $project, ?string $teamId): ?string {
		if (!$this->archives->holdsArchive($node)) {
			// Not an archive: nothing to import. An EMPTY `.penpot` is a create and
			// belongs to {@see CreationService}; anything else ending in `.penpot`
			// that is not a ZIP is a file the app has no business touching.
			return null;
		}

		$name = $this->penpotName($node->getName());
		if ($name === '') {
			return null;
		}

		// A HANDLE, NOT `getContent()`. A `.penpot` export is the whole design and
		// is routinely tens of megabytes; reading one into a string would hold it
		// twice inside a request that is already holding a filesystem open.
		$handle = null;
		try {
			$handle = $node->fopen('rb');
			if (!is_resource($handle)) {
				throw new \RuntimeException('could not open the archive for reading');
			}
			$newId = $this->client->importBinfile(
				$project,
				$name,
				$handle,
				$this->personalTokens->tokenForActor(),
			);
		} catch (\Throwable $e) {
			// §6.18 rule 3. The bytes stand, untracked — which is what this app did
			// with every archive before the import existed.
			$this->logger->warning('penpot_sync import: could not import the archive; the file stays untracked', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'project' => $project,
				'exception' => $e,
			]);

			// AND SAY SO, NAMING WHAT PENPOT SAID. A file that silently fails to
			// become a design is the worst outcome available: it sits in a mapped
			// folder looking exactly like every design around it, and the only
			// symptom is that nothing in Penpot ever answers to it.
			$this->notifier->importFailed(
				$this->personalTokens->actingUserId(),
				$node->getId(),
				$node->getName(),
				$e->getMessage(),
			);

			return null;
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}

		$this->renameToMatchTheFile($newId, $name);

		// MODE FROM THE MAPPING, exactly as a created design is born in its
		// mapping's mode. A `link` mapping never reaches here — nothing may be
		// added to one from this side — so in practice this is `sync`, and the
		// fallback is the safe direction: `link` promises no archive.
		$this->metadata->writeFile($node->getId(), [
			PenpotMetadata::KEY_ID => $newId,
			PenpotMetadata::KEY_MODE => $this->modeFor($teamId),
			PenpotMetadata::KEY_TEAM_ID => $teamId ?? '',
		]);

		$this->logger->info('penpot_sync import: adopted an archive as a Penpot design', [
			'app' => Application::APP_ID,
			'penpot_id' => $newId,
			'project' => $project,
			'file' => $node->getName(),
		]);

		return $newId;
	}

	/**
	 * Make the design answer to the filename, since the import would not.
	 *
	 * BEST EFFORT, and deliberately not fatal. The design exists and the file names
	 * it; a rename that fails leaves the two disagreeing about a NAME, which the
	 * next pull settles by renaming the file to match Penpot. That is a visible,
	 * self-correcting outcome — unlike throwing here, which would abandon a design
	 * that has already been created.
	 */
	private function renameToMatchTheFile(string $penpotId, string $name): void {
		try {
			$this->client->renameFile($penpotId, $name, $this->personalTokens->tokenForActor());
		} catch (\Throwable $e) {
			$this->logger->warning('penpot_sync import: the design was created but could not be renamed', [
				'app' => Application::APP_ID,
				'penpot_id' => $penpotId,
				'name' => $name,
				'exception' => $e,
			]);
		}
	}

	/** The mode a design imported into this team is born in — its mapping's. */
	private function modeFor(?string $teamId): string {
		if ($teamId === null || $teamId === '') {
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
