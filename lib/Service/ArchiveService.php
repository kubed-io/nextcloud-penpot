<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\Files\File;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What is actually *inside* a mirrored `.penpot` file (saga Ch2 Course 4, §6.22).
 *
 * The `link`/`sync` axis is not about which way edits flow — §6.1 already
 * settled that content is one-way forever. It is about **weight**:
 *
 *     link  — a small JSON pointer. `export-binfile` is never called for it.
 *     sync  — the real archive, exported from Penpot and stored.
 *
 * A `sync` file is a backup **and** a link, never one at the expense of the
 * other: the metadata that makes "Open in Penpot" work is stamped identically
 * either way, so promoting a file adds bytes and takes nothing away.
 *
 * ## WHY THIS IS ITS OWN SERVICE
 *
 * Two callers need the same two answers, and they are not the same caller:
 * {@see PullService} writes bodies while walking a team, and the per-file mode
 * change ({@see \OCA\PenpotSync\Command\SetMode}) writes exactly one on demand.
 * Left in the pull, the mode command would have to reach into it or duplicate
 * the JSON shape — and a link body that differs by which code path wrote it is
 * the kind of drift that only shows up as a support question.
 *
 * ## THE DEMOTION IS THE ONLY LOSSY OPERATION IN THIS APP
 *
 * `sync` → `link` deletes a stored archive. Penpot is never contacted and the
 * design is untouched, but the bytes are *local* — nobody upstream is holding a
 * copy of them for us, and re-acquiring them costs a fresh export (and is
 * impossible at all if the design has since been deleted). Hence
 * {@see storeLink()} is deliberately dumb and callable, and the confirmation
 * lives at the command surface where a human is standing.
 */
final class ArchiveService {
	/**
	 * The ZIP local-file-header magic — how we tell a stored archive from a
	 * stored pointer without trusting the metadata stamp.
	 *
	 * The stamp and the bytes CAN disagree: an export that failed halfway
	 * through a promotion leaves a file stamped `sync` holding a link body. That
	 * is a state the next pull must notice and repair, which it can only do by
	 * looking (saga §6.18 rule 3 — a remote failure never rewrites local state,
	 * so the stale body stays until a later export succeeds).
	 */
	private const ZIP_MAGIC = "PK\x03\x04";

	public function __construct(
		private readonly PenpotClient $client,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * True when this node's stored content really is a `.penpot` archive.
	 *
	 * Reads FOUR BYTES, not the file. A `sync` archive is a full export with
	 * embedded binaries, and this is called once per file per pull — pulling
	 * megabytes through PHP to look at a magic number would make the drift check
	 * more expensive than the export it is trying to avoid.
	 */
	public function holdsArchive(File $node): bool {
		if ($node->getSize() < strlen(self::ZIP_MAGIC)) {
			return false;
		}

		try {
			$handle = $node->fopen('rb');
			if (!is_resource($handle)) {
				return false;
			}

			$head = (string)fread($handle, strlen(self::ZIP_MAGIC));
			fclose($handle);
		} catch (\Throwable $e) {
			// An unreadable mirror file is not a reason to abort a pull. Report
			// "no archive" and let the caller re-export — the worst case is one
			// redundant export, and the alternative is a pull that dies on a
			// single bad node.
			$this->logger->warning('penpot_sync: could not read a mirror file to check for an archive', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'exception' => $e,
			]);

			return false;
		}

		return $head === self::ZIP_MAGIC;
	}

	/**
	 * Export the design and store the real archive as the file's content.
	 *
	 * THE OLD CONTENT SURVIVES A FAILURE. Every failure path throws before
	 * `putContent()` is reached, so a file that already held a good archive still
	 * holds it — §6.18 rule 3, applied to the one operation that could otherwise
	 * replace a backup with nothing.
	 *
	 * @return int the number of bytes stored
	 *
	 * @throws PenpotApiException if the export or the download fails
	 */
	public function storeArchive(File $node, string $penpotId): int {
		$archive = $this->client->exportBinfile($penpotId);
		$node->putContent($archive);

		$this->logger->info('penpot_sync: stored a Penpot archive', [
			'app' => Application::APP_ID,
			'file' => $node->getName(),
			'penpot_id' => $penpotId,
			'bytes' => strlen($archive),
		]);

		return strlen($archive);
	}

	/**
	 * Store the `link` pointer body — a small JSON reference, no design data.
	 *
	 * Penpot is never contacted. Called for every `link` file on every pull (it
	 * is cheap and keeps the pointer's `revn` honest), and once by a demotion,
	 * where it is what actually deletes the archive.
	 *
	 * NO DEEP-LINK URL IS FABRICATED. The body carries the ids and the instance
	 * base; the exact workspace route is confirmed live with the Files-app
	 * surface that needs it, not guessed here.
	 */
	public function storeLink(File $node, string $penpotId, string $name, string $revn, string $modifiedAt, string $teamId): void {
		$payload = [
			'penpot' => 'reference/v1',
			'id' => $penpotId,
			'name' => $name,
			'revn' => $revn,
			'modified_at' => $modifiedAt,
			'team_id' => $teamId,
			'instance_url' => $this->config->getValueString(Application::APP_ID, InstanceSettings::KEY_URL, ''),
		];

		// JSON_THROW_ON_ERROR, not a silent (string) cast: json_encode can return
		// false (e.g. malformed UTF-8 in a file name), and writing an empty body
		// would be a silently corrupt mirror file. Matches PenpotClient's encoding.
		$node->putContent(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}
}
