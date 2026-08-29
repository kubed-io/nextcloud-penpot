<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;

/**
 * The one system tag this app puts on FOLDERS: `penpot`, meaning *this folder is
 * a Penpot project* (`projects/create.feature`, saga §C6.18).
 *
 * ## ONE TAG, TWO JOBS
 *
 * The tag is mainly the app's **marker**: {@see PullService::ensureProjectFolder()}
 * stamps it on every folder it mirrors, and {@see ProjectFolderService} stamps it
 * on every folder promoted from this side, so a project folder is visible as one
 * in the Files app without opening any sidebar.
 *
 * It is also a second, explicit **opt-in** — assigning it by hand asks for the
 * folder to become a project ({@see ProjectFolderService::onTagged()}). That is
 * not how a folder normally becomes one; a design landing in it is (§C6.38). The
 * gesture survives because the integration harness needs a project folder that
 * holds no design yet.
 *
 * A user cannot tell — and should not have to — whether a project folder started
 * life in Penpot or was promoted from Nextcloud. Both carry the tag; both are
 * projects.
 *
 * ## WHY A TAG AND NOT A NAME CONVENTION OR A BUTTON
 *
 * Tag assignment is a first-class Nextcloud gesture with an event
 * (`TagAssignedEvent`), it survives a rename and a move, and the sibling apps
 * already use tags for exactly this kind of opt-in (n8n's `OwnershipTags`). A
 * name convention would break on the first rename; a bespoke button would be a
 * surface to build, document, and keep working across Files-app versions.
 *
 * ## THE TAG IS NOT AUTHORITATIVE — `penpot_project_id` IS
 *
 * This differs from n8n, whose mode tags mirror an authoritative metadata field.
 * Here the tag *starts* things and then only decorates: once a folder carries a
 * project id, that id is what every lookup reads ({@see MembershipResolver}).
 * Removing the tag therefore unmaps nothing and destroys nothing — see
 * {@see \OCA\PenpotSync\Listener\ProjectTagListener} for why the unassign event
 * is not even subscribed to.
 */
final class ProjectTags {
	/**
	 * Bare `penpot`, not `penpot:project`.
	 *
	 * The siblings namespace their tags because they carry several with distinct
	 * meanings (`n8n:sync` / `n8n:link` / `n8n:ignore`) and a prefix keeps them
	 * sorted together. This app has exactly one, its meaning is "this is Penpot",
	 * and a user typing it into the tag box should get the obvious word.
	 */
	public const TAG_PROJECT = 'penpot';

	public function __construct(
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagMapper,
	) {
	}

	/**
	 * Put the tag on a folder. Idempotent — safe on every pull, which is exactly
	 * how it is called.
	 */
	public function apply(int $folderId): void {
		$tag = $this->ensureTag();
		$this->tagMapper->assignTags((string)$folderId, 'files', [$tag->getId()]);
	}

	/**
	 * True when the tag is among the given tag ids.
	 *
	 * `TagAssignedEvent::getTags()` yields tag *ids*; the listener needs names.
	 *
	 * @param array<int|string> $tagIds
	 */
	public function includedIn(array $tagIds): bool {
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			if ($tag->getName() === self::TAG_PROJECT) {
				return true;
			}
		}

		return false;
	}

	/** Take the tag off a folder. Used only to undo a refused opt-in. */
	public function remove(int $folderId): void {
		try {
			$tag = $this->tagManager->getTag(self::TAG_PROJECT, true, true);
		} catch (TagNotFoundException) {
			return;
		}

		$objId = (string)$folderId;
		if ($this->tagMapper->haveTag([$objId], 'files', $tag->getId())) {
			$this->tagMapper->unassignTags($objId, 'files', [$tag->getId()]);
		}
	}

	/**
	 * Look up (or first-time create) the system tag: visible in the Files app and
	 * assignable by any user, which is what makes it usable as an opt-in.
	 */
	private function ensureTag(): ISystemTag {
		try {
			return $this->tagManager->createTag(self::TAG_PROJECT, true, true);
		} catch (TagAlreadyExistsException) {
			return $this->tagManager->getTag(self::TAG_PROJECT, true, true);
		}
	}
}
