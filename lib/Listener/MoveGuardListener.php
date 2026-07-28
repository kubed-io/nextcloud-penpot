<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Listener;

use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCA\PenpotSync\Service\PullService;
use OCA\PenpotSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * Gate-keeps a move *before* it happens (`move.feature`) — cut from both
 * siblings' `MoveGuardListener`, and carrying the two moves this app refuses.
 * Throwing {@see AbortedEventException} aborts the operation and shows the
 * message to the user, so a refused gesture fails *with an explanation* at the
 * moment they make it, never mysteriously hours later on the next pull. The
 * consequences of an *allowed* move belong to
 * {@see \OCA\PenpotSync\Service\MotionService}.
 *
 * ## RULE 1 — A PROJECT FOLDER MAY NOT LEAVE ITS TEAM FOLDER (saga §6.30)
 *
 * A refusal rather than a silent undo, because both alternatives are worse:
 * reparenting the project in Penpot (`move-project` can do it) is a destructive
 * cross-team mutation far outside §6.1, and allowing the move would strand a
 * folder carrying a `penpot_project_id` under a team it no longer sits in, so
 * every later resolution (§6.29) would disagree with Penpot.
 *
 * Moving a project folder ANYWHERE INSIDE its team folder is free and
 * meaningful: Nextcloud is authoritative for folder layout (§6.29) and Penpot
 * has no concept of the position, so nothing is pushed and nothing is refused.
 *
 * ## RULE 2 — A `link` FILE IS CONFINED TO ITS PROJECT (saga §6.43, locked)
 *
 * A `sync` file is a real archive: move it anywhere and the user still holds
 * something genuinely valuable. **A `link` file is a pointer** — move it out of
 * its project and they hold an empty husk that looks like a design and is not.
 * So a link moves freely *within* its project (including into plain subfolders,
 * which Penpot cannot even see) and every project-changing move is refused, with
 * the same escape hatch each time: **promote it to `sync` first.** That is not a
 * fob-off — it is precisely the action that converts the pointer into something
 * able to survive the gesture being attempted.
 *
 * `sync` files earn their freedom by holding real content, so they are not
 * constrained here at all; `MotionService` re-files them in Penpot. The escape
 * hatch the refusal offers is real and reachable today:
 * `occ penpot_sync:set-mode <path> sync` fetches the archive, after which the
 * file moves like any other.
 *
 * ## THE TWO MESSAGES ARE TRANSLATED, AND THAT IS NOT CEREMONY
 *
 * These strings are shown to a user mid-gesture, in a dialog, as the entire
 * explanation for why their drag did not work. They are the only user-visible
 * prose this app produces outside the settings pages, which are translated too.
 * Each names the rule, the reason, and the way forward — a refusal that only
 * says "not allowed" is worse than no refusal, because the user retries it.
 *
 * ## WHAT IS NOT GUARDED
 *
 * The team root is the mapping's own folder — moving or renaming it is the
 * mapping's business, not a project's. An untracked or unmanaged `.penpot` file
 * is ordinary tolerated content and moves like any other file.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly SyncGuard $guard,
		private readonly IL10N $l,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		if ($this->guard->active()) {
			// The pull's own follow-renames are never refused — it is reconciling
			// TO Penpot, so by definition it cannot desync from it.
			return;
		}

		$source = $event->getSource();
		if ($source instanceof Folder) {
			$this->guardProjectFolder($source, $event->getTarget());
			return;
		}
		if ($source instanceof File) {
			$this->guardLinkFile($source, $event->getTarget());
		}
	}

	/** Rule 1 (§6.30) — a project folder stays inside the team folder it belongs to. */
	private function guardProjectFolder(Folder $source, Node $target): void {
		if (!$this->metadata->readFolder($source->getId())->hasProject()) {
			// A plain folder has no Penpot identity to strand, and the team root
			// is the mapping's business.
			return;
		}

		$from = $this->resolver->resolve($source)->teamId;
		if ($from === null) {
			// A project folder under no team folder is a personal project (§6.31),
			// which has no team boundary to leave.
			return;
		}

		if ($this->positionOf($target)?->teamId === $from) {
			return;
		}

		throw new AbortedEventException($this->l->t(
			'"%s" mirrors a Penpot project, so it has to stay inside the team folder it belongs '
			. 'to. Moving a project between teams has to be done in Penpot itself. Move it '
			. 'anywhere within that team folder instead, or move the individual designs.',
			[$source->getName()],
		));
	}

	/** Rule 2 (§6.43) — a `link` file may not change project. */
	private function guardLinkFile(File $source, Node $target): void {
		if (!str_ends_with($source->getName(), PullService::EXTENSION)) {
			return;
		}
		$meta = $this->metadata->readFile($source->getId());
		if ($meta === null || !$meta->isManaged() || !$meta->isLink()) {
			// Untracked content, or a `sync` file — which earns its freedom by
			// holding a real archive and is not constrained here.
			return;
		}

		$to = $this->positionOf($target);
		if ($to !== null && $this->samePosition($this->resolver->resolve($source), $to)) {
			// Within its own project — including a plain subfolder Penpot cannot
			// even see. Pure local filing.
			return;
		}

		throw new AbortedEventException($this->l->t(
			'"%s" is a link to a design that lives in Penpot — it holds no copy of the design '
			. 'itself, so moving it out of its project would leave you with an empty file. Switch '
			. 'it to "sync" mode first (occ penpot_sync:set-mode) and Nextcloud will keep the real '
			. 'archive, which can be moved anywhere.',
			[$source->getName()],
		));
	}

	/**
	 * Where a node would sit at its NEW path.
	 *
	 * The target of a `BeforeNodeRenamedEvent` does not exist yet, so it carries
	 * no metadata of its own and the resolution has to start at its parent. Null
	 * means the destination could not be read at all, which fails every
	 * comparison here and therefore refuses the move. Refusing on doubt is the
	 * right way round: an unreadable destination is exactly where a silent
	 * desync would be created.
	 */
	private function positionOf(Node $target): ?Membership {
		try {
			$parent = $target->getParent();
		} catch (NotFoundException) {
			return null;
		}

		return $this->resolver->resolve($parent);
	}

	/**
	 * True when two resolutions describe the same place in Penpot.
	 *
	 * BOTH ids are compared, not just the project. Two team roots each resolve to
	 * "no project" while meaning two *different* Drafts (§6.35), so comparing
	 * project ids alone would wave a cross-team move straight through.
	 */
	private function samePosition(Membership $a, Membership $b): bool {
		return $a->projectId === $b->projectId && $a->teamId === $b->teamId;
	}
}
