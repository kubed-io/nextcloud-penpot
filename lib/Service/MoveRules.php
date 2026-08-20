<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * WHICH MOVES THIS APP REFUSES, AND IN WHAT WORDS — asked, never thrown.
 *
 * The two rules are unchanged and still live in one place; what moved is who does
 * the throwing. They have to be ASKED from two places, because Nextcloud gives no
 * single place that both stops a move and tells the person why.
 *
 *  - {@see \OCA\PenpotSync\Listener\MoveGuardListener} asks on
 *    `BeforeNodeRenamedEvent` and aborts. That is the only hook reaching EVERY
 *    route — `occ`, another app, a script — and the only way to stop a rename.
 *  - {@see \OCA\PenpotSync\DAV\LinkWriteGuardPlugin} asks on Sabre's
 *    `method:MOVE` and answers 403 with the reason. That is the only place a
 *    readable refusal actually reaches a client.
 *
 * ## WHY THE LISTENER CANNOT SPEAK, AND WHY NOTHING ELSE CAN BE THROWN
 *
 * `OC\Files\Node\HookConnector::rename()` catches `AbortedEventException` by
 * name, logs it, and sets `run = false`. That catch is where the message dies:
 * `View::rename()` returns false and `Directory::moveInto()` answers
 * `throw new \Sabre\DAV\Exception\Forbidden('')` — an empty string, by literal.
 * So both of this app's refusals reached the Files app as a 403 with nothing in
 * it, and the person was told no without being told why.
 *
 * The obvious repair does not work. `OC_Hook::emit()` wraps every slot in
 * `catch (Throwable)`, logs it and CARRIES ON — only `HintException` and
 * `ServerNotAvailableException` are re-thrown. So `AbortedEventException` is not
 * one option among several: it is the ONLY thing a listener on this route can
 * throw that refuses anything at all. The Grafana sibling measured that in CI
 * when it swapped in `OCP\Files\ForbiddenException` to rescue the message and
 * turned nine refusals into HTTP 201 — allowed.
 *
 * Both halves were read out of a running Nextcloud rather than reasoned about.
 *
 * ## THE TARGET IS A PATH AND A NAME, NOT A NODE
 *
 * The DAV side asks BEFORE the destination exists, so it has nowhere to get a
 * node from. The listener's target does not exist yet either — which is why
 * {@see positionOf} has always started at the parent.
 */
final class MoveRules {
	public function __construct(
		private readonly PenpotMetadata $metadata,
		private readonly MembershipResolver $resolver,
		private readonly IL10N $l,
	) {
	}

	/**
	 * The reason this move must be refused, or null when it may go ahead.
	 *
	 * A `sync` file earns its freedom by holding a real archive, and an untracked
	 * `.penpot` is ordinary tolerated content — neither is constrained here.
	 */
	public function refusalFor(Node $source, Node $target): ?string {
		return $this->evaluate($source, $this->positionOf($target));
	}

	/**
	 * The same question asked from the DAV layer, where the destination is a path.
	 *
	 * Sabre knows the FOLDER a move is binding into, and nothing about the node that
	 * will appear there — which is the same information {@see positionOf} distils a
	 * target down to, one step earlier. Passing the parent straight in is therefore
	 * the identical question, not an approximation of it.
	 *
	 * A null parent means the destination could not be read, which refuses the move
	 * for the reason {@see positionOf} gives: an unreadable destination is exactly
	 * where a silent desync would be created.
	 */
	public function refusalForLandingIn(Node $source, ?Node $targetParent): ?string {
		return $this->evaluate($source, $targetParent === null ? null : $this->resolver->resolve($targetParent));
	}

	/** @param Membership|null $to where the source would end up, already resolved */
	private function evaluate(Node $source, ?Membership $to): ?string {
		if ($source instanceof Folder) {
			return $this->forProjectFolder($source, $to);
		}
		if ($source instanceof File) {
			return $this->forLinkFile($source, $to);
		}

		return null;
	}

	/** Rule 1 (§6.30) — a project folder stays inside the team folder it belongs to. */
	private function forProjectFolder(Folder $source, ?Membership $to): ?string {
		if (!$this->metadata->readFolder($source->getId())->hasProject()) {
			// A plain folder has no Penpot identity to strand, and the team root
			// is the mapping's business.
			return null;
		}

		$from = $this->resolver->resolve($source)->teamId;
		if ($from === null) {
			// A project folder under no team folder is a personal project (§6.31),
			// which has no team boundary to leave.
			return null;
		}

		if ($to?->teamId === $from) {
			return null;
		}

		return $this->l->t(
			'"%s" mirrors a Penpot project, so it has to stay inside the team folder it belongs '
			. 'to. Moving a project between teams has to be done in Penpot itself. Move it '
			. 'anywhere within that team folder instead, or move the individual designs.',
			[$source->getName()],
		);
	}

	/** Rule 2 (§6.43) — a `link` file may not change project. */
	private function forLinkFile(File $source, ?Membership $to): ?string {
		if (!str_ends_with($source->getName(), PullService::EXTENSION)) {
			return null;
		}
		$meta = $this->metadata->readFile($source->getId());
		if ($meta === null || !$meta->isManaged() || !$meta->isLink()) {
			// Untracked content, or a `sync` file — which earns its freedom by
			// holding a real archive and is not constrained here.
			return null;
		}

		if ($to !== null && $this->samePosition($this->resolver->resolve($source), $to)) {
			// Within its own project — including a plain subfolder Penpot cannot
			// even see. Pure local filing.
			return null;
		}

		return $this->l->t(
			'"%s" is a link to a design that lives in Penpot — it holds no copy of the design '
			. 'itself, so moving it out of its project would leave you with an empty file. '
			. 'Move it within its own project instead.',
			[$source->getName()],
		);
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
