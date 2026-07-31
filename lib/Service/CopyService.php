<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * A Nextcloud copy becomes a real new design in Penpot (`copy.feature`,
 * saga §C6.8).
 *
 * ## THIS REVERSED A DECISION, AND THE REVERSAL IS THE INTERESTING PART
 *
 * `copy.feature` used to say, in its loudest scenario, *"No copy, anywhere, ever
 * writes to Penpot"* — a Ctrl+C is someone organising files, not authoring work
 * (§6.1). It was overturned deliberately on two grounds: §6.1 is about *content*
 * never flowing back (shape data still never does), and an inert copy is a
 * `.penpot` file that opens nothing and is indistinguishable from a real mirror
 * — the exact quiet lie {@see PullService}'s prune and restore.feature exist to
 * prevent. Both siblings register a copy as a new remote resource; this is
 * parity, arrived at from the other direction.
 *
 * ## ONE GESTURE, TWO MECHANISMS, AND THE SCHEMA DECIDES WHICH
 *
 * `duplicate-file` has **no project parameter** (proven live, §C6.8), so the new
 * design always lands in the SOURCE design's project. Where the Nextcloud copy
 * landed decides whether that is already right:
 *
 *     copy lands in the SAME project    → duplicate-file                (1 call)
 *     copy lands in ANOTHER project     → duplicate-file + move-files   (2 calls)
 *     copy lands outside every mapping  → nothing is created at all
 *
 * The comparison is made against the duplicate's OWN `projectId`, echoed back in
 * its record, rather than against a resolved source — the server's answer about
 * where it put the thing beats our inference about where it should have.
 *
 * ## NO BYTES TRAVEL, SO MODE IS NOT A CASE HERE
 *
 * Penpot copies the design from its id. A `link` file holding zero bytes
 * (§C6.6) duplicates as completely as a stored `sync` archive, and no export is
 * ever performed. This is the one place penpot is *simpler* than both siblings,
 * which copy by pushing the file's own content and would have nothing to push.
 *
 * ## THE NAME COMES FROM THE COPY
 *
 * Nextcloud has already named the copy ("Login screen (copy).penpot"), and that
 * is the user's stated intent, so it is what the design is called — extension
 * stripped (§6.4), truncated to Penpot's 250-char schema limit rather than
 * refused, because losing a name's tail is a smaller harm than refusing to copy.
 */
final class CopyService {
	/** Penpot's schema limit on a file name (`[:string {:max 250}]`, §C6.8). */
	private const MAX_NAME = 250;

	public function __construct(
		private readonly PenpotClient $client,
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly DestinationResolver $destinations,
		private readonly PersonalTokenService $personalTokens,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Handle a copied `.penpot` file.
	 *
	 * @param Node $source the file that was copied FROM (still exists)
	 * @param File $target the freshly created copy
	 */
	public function onCopy(Node $source, File $target): void {
		$stamped = $this->metadata->readFile($source->getId());
		$sourceId = $stamped?->penpotId ?? '';
		if ($sourceId === '') {
			// Copying an untracked `.penpot` is copying a file. Penpot is never
			// contacted, and the copy inherits nothing because there was nothing.
			$this->metadata->clear($target->getId());

			return;
		}

		$membership = $this->resolver->resolve($target);
		// NOT `$membership->projectId`. A copy landing at a TEAM ROOT resolves to
		// no project folder but is squarely inside the mapping — those files are
		// the team's Drafts, a real project (§6.35). Reading the raw projectId
		// here is what made "copy up a directory" produce a Nextcloud file and no
		// design at all, silently (§C6.10).
		$project = $this->destinations->projectFor($membership);
		if ($project === null) {
			// OUTSIDE EVERY MAPPING. There is no project to create in, and
			// inventing one would be the surprise write §6.1 refuses. The source's
			// id is kept as a historical record: inert here, because no pull will
			// ever look at this file, and genuinely useful because it records which
			// design these bytes came from — which is what makes a restore possible.
			$this->metadata->writeFile($target->getId(), [
				PenpotMetadata::KEY_ID => $sourceId,
				PenpotMetadata::KEY_MODE => PenpotMetadata::MODE_UNMAPPED,
			]);

			return;
		}

		try {
			$new = $this->client->duplicateFile(
				$sourceId,
				$this->penpotName($target->getName()),
				$this->personalTokens->tokenForActor(),
			);
		} catch (\Throwable $e) {
			// §6.18 rule 3: a remote failure never rewrites local state. The
			// Nextcloud copy stands, with its bytes intact — but it must NOT carry
			// the source's id, or two files would claim one design, which is the
			// ambiguity the old inert-copy rule existed to avoid.
			$this->metadata->clear($target->getId());
			$this->logger->warning('penpot_sync copy: could not duplicate the design; the copy is untracked', [
				'app' => Application::APP_ID,
				'sourcePenpotId' => $sourceId,
				'file' => $target->getName(),
				'exception' => $e,
			]);

			return;
		}

		$newId = (string)($new['id'] ?? '');
		if ($newId === '') {
			$this->metadata->clear($target->getId());
			$this->logger->warning('penpot_sync copy: duplicate-file returned no id', [
				'app' => Application::APP_ID,
				'sourcePenpotId' => $sourceId,
			]);

			return;
		}

		// WHERE PENPOT ACTUALLY PUT IT, not where we assumed. The duplicate lands
		// in the source's project; a copy dragged into a different project folder
		// (or up to the team root, which is Drafts — §6.35) needs the second call.
		$landedIn = (string)($new['projectId'] ?? $new['project-id'] ?? '');
		if ($landedIn !== $project) {
			$this->client->moveFiles($project, [$newId], $this->personalTokens->tokenForActor());
		}

		// The copy is its own design now: its own id, and the team it landed in.
		// Mode follows the source, because a `sync` copy still holds the archive
		// it was copied with. No revision is stamped — the copy has never been
		// pulled, so the next pull's drift check must run rather than be skipped.
		$this->metadata->writeFile($target->getId(), [
			PenpotMetadata::KEY_ID => $newId,
			PenpotMetadata::KEY_MODE => $stamped->mode !== '' ? $stamped->mode : Mapping::MODE_LINK,
			PenpotMetadata::KEY_TEAM_ID => $membership->teamId ?? '',
		]);

		$this->logger->info('penpot_sync copy: duplicated a design', [
			'app' => Application::APP_ID,
			'from' => $sourceId,
			'to' => $newId,
			'project' => $project,
		]);
	}

	/**
	 * The Nextcloud copy's filename as a Penpot name: extension off (§6.4),
	 * truncated to the schema limit rather than rejected.
	 *
	 * Truncation is deliberate. Penpot answers a too-long name with a validation
	 * error, which would turn "your copy has a long name" into "your copy is not
	 * a design" — a much worse outcome than a shortened title the user can edit.
	 */
	private function penpotName(string $fileName): string {
		$bare = preg_replace('/\.penpot$/', '', $fileName) ?? $fileName;

		return mb_substr($bare, 0, self::MAX_NAME);
	}
}
