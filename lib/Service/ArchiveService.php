<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * What is actually *inside* a mirrored `.penpot` file (saga Ch2 Course 4, §6.22).
 *
 * The `link`/`sync` axis is not about which way edits flow — §6.1 already
 * settled that content is one-way forever. It is about **weight**:
 *
 *     link  — nothing at all: zero bytes. `export-binfile` is never called.
 *     sync  — the real archive, exported from Penpot and stored.
 *
 * A `sync` file is a backup **and** a link, never one at the expense of the
 * other: the metadata that makes "Open in Penpot" work is stamped identically
 * either way, so promoting a file adds bytes and takes nothing away.
 *
 * ## WHY THIS IS ITS OWN SERVICE
 *
 * Two callers need the same two answers, and they are not the same caller:
 * {@see PullService} sets a file's content while walking a team, and the
 * per-file mode change ({@see \OCA\PenpotSync\Command\SetMode}) sets exactly one
 * on demand. Keeping "what is a link" and "what is an archive" in one place is
 * what stopped the two paths from disagreeing about it — and is why flipping a
 * link from a JSON body to zero bytes (§C6.6) was a one-method change.
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
	 * The ZIP local-file-header magic — how we tell a stored archive from an
	 * empty `link` without trusting the metadata stamp.
	 *
	 * The stamp and the bytes CAN disagree: an export that failed halfway through
	 * a promotion leaves a file stamped `sync` holding nothing. That is a state
	 * the next pull must notice and repair, which it can only do by looking (saga
	 * §6.18 rule 3 — a remote failure never rewrites local state, so the empty
	 * file stays until a later export succeeds).
	 */
	private const ZIP_MAGIC = "PK\x03\x04";

	public function __construct(
		private readonly PenpotClient $client,
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
	 * Make this mirror a `link`: **zero bytes**. Penpot is never contacted.
	 *
	 * Called for every `link` file on every pull, and once by a demotion, where
	 * it is what actually deletes the archive.
	 *
	 * ## WHY A LINK HOLDS NOTHING AT ALL (saga §C6.6)
	 *
	 * It used to hold a small JSON pointer naming the ids and the instance base.
	 * That body was written before the metadata keys had a consumer, and once
	 * `penpot_id` / `penpot_revision` / `penpot_mode` ride the file's own
	 * metadata — server-side, over DAV, and indexed — the body was a **second
	 * copy of facts already recorded elsewhere**. Two copies of a fact drift; the
	 * only question is when. The body even split the drift signal back into its
	 * two halves, which this class's own {@see signal()} docblock warned was
	 * exactly how the two would diverge.
	 *
	 * ## AND NOT AN EMPTY ARCHIVE, WHICH WOULD BE WORSE THAN EITHER
	 *
	 * A ZIP containing a metadata entry begins with the same `PK\x03\x04` magic a
	 * real export does, so {@see holdsArchive()} could not tell them apart. That
	 * would quietly disable the prune's grace-window rescue (a doomed pointer
	 * would look like it already held its backup and be trashed without one),
	 * make a demotion demand confirmation to delete an archive that does not
	 * exist, and report `archive` in `occ penpot_sync:status`. Zero bytes is the
	 * one representation that CANNOT be mistaken for an export.
	 *
	 * ## THE BYTES STAY AUTHORITATIVE — THIS STRENGTHENS THAT, NOT WEAKENS IT
	 *
	 * The mode stamp says what a file is *supposed* to hold; only the bytes say
	 * what it does, which is what lets the pull self-heal a promotion whose
	 * export died halfway (§6.18 rule 3). An empty file fails
	 * {@see holdsArchive()} on the size check before it ever reads a byte, so
	 * "no content" is now unambiguous rather than inferred from a body we wrote.
	 *
	 * ## IDEMPOTENT ON PURPOSE
	 *
	 * The old body was rewritten on every pull to keep its `revn` current. There
	 * is nothing left to keep current, and rewriting an already-empty file would
	 * still move its mtime and etag — which makes every desktop client re-sync
	 * every `link` file after every pull. So an empty file is left strictly
	 * alone.
	 *
	 * @return bool true when bytes were actually written — i.e. the file held
	 *              something and was truncated. The caller needs this to know the
	 *              node's mtime is now `now`, which is what decides whether its
	 *              cached value is worth comparing against (§C6.24).
	 */
	public function storeLink(File $node): bool {
		if ($node->getSize() === 0) {
			return false;
		}

		$node->putContent('');

		return true;
	}

	/**
	 * THE DRIFT SIGNAL, and the only place its shape is written down.
	 *
	 * `revn` alone cannot tell "same revn, newer modified-at" apart, which the
	 * scheduled pull needs (saga §5.5), so the stamp is the two joined. Callers
	 * compare it whole and **never parse it** — there is no longer any exception.
	 *
	 * There used to be one: the JSON pointer body kept the two halves in separate
	 * fields, so a demotion had to take the signal back apart to write it, and
	 * this docblock warned that "joining in one file and splitting in another is
	 * how the two drift." A `link` holds no body now (§C6.6), so the splitter is
	 * gone and the signal is opaque everywhere — the drift it warned about is
	 * structurally impossible rather than merely documented.
	 */
	public static function signal(string $revn, string $modifiedAt): string {
		if ($modifiedAt === '') {
			return $revn;
		}

		return $revn . '@' . $modifiedAt;
	}
}
