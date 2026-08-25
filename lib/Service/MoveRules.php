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
 * WHICH GESTURES THIS APP REFUSES, AND IN WHAT WORDS — asked, never thrown.
 *
 * Three rules. Two are about MODE, which is the only thing a move must not
 * change; the third ({@see refusalForCreating}) is about a design having
 * somewhere to go at all. §C6.38 retired a fourth — *a project folder stays
 * inside its team folder* (§6.30, locked "for now" and now unlocked) — because it
 * was guarding against a limit Penpot does not have; see {@see forFolder}.
 *
 * The class is still called MoveRules because a move is where it started and
 * three of the four verbs it now answers for (move, delete, create) reduce to the
 * same question: what does the MAPPING at that position allow?
 *
 * They live in one place and are ASKED from two, because Nextcloud gives no
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
		private readonly MappingService $mappings,
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
		return $this->evaluate($source, $this->positionOf($target), $target->getName());
	}

	/**
	 * The reason this node may not be deleted, or null when it may.
	 *
	 * ONE QUESTION, NOT TWO, which is what makes this shorter than the move rules
	 * rather than a copy of them. A move asks about the node AND where it is
	 * going; a delete has only the node — and under a `link` mapping the answer is
	 * the same whatever the node is. A file, a project folder, a plain folder
	 * holding either: the tree is Penpot's and Nextcloud mirrors it read-only, so
	 * the only thing worth asking is which mapping it sits under.
	 *
	 * Deliberately silent everywhere else. A `sync` mirror deleted here goes to
	 * Penpot's trash and comes back (§C6.11), and an ordinary file in a mapped
	 * folder is nobody's business but its owner's — refusing either would break
	 * the thing that makes a mapped folder usable as a folder.
	 */
	public function refusalForDeleting(Node $node): ?string {
		if (!$this->isLinkTeam($this->resolver->resolve($node)->teamId)) {
			return null;
		}

		return $this->l->t(
			'"%s" is inside a folder that mirrors a Penpot team in link mode, so what is here is '
			. 'filled from Penpot and kept in step with it. Deleting it here would only bring it '
			. 'back on the next sync. Delete it in Penpot instead.',
			[$node->getName()],
		);
	}

	/**
	 * Rule 3 (§6.34, §6.44) — where a NEW `.penpot` may be authored.
	 *
	 * ## TWO REFUSALS, AND ONLY ONE OF THEM CARES WHAT IS IN THE FILE
	 *
	 * A `link` mapping is filled FROM Penpot and nothing may be added from this
	 * side, *whatever is arriving* — the same sentence {@see forFolder} already
	 * says about a folder. An empty create and a dragged-in archive are refused
	 * alike, because neither could ever become the design it looks like: Penpot has
	 * no write path for design content, so a file authored into a link tree would
	 * be emptied again by the next pull ({@see ArchiveService::storeLink()}).
	 *
	 * Landing outside every mapping is the other rule, and it is narrower: it
	 * refuses the CREATE and allows the UPLOAD. "+ New → Penpot design" writes an
	 * empty file, and an empty `.penpot` is not a document someone can go on to
	 * author the way an empty JSON is — Penpot has no rootless design and
	 * `create-file` requires a project, so there is nowhere for it to become real.
	 * An archive someone drags into a plain folder is a different act entirely: it
	 * is a file, it holds something, and Nextcloud stores files.
	 *
	 * THIS IS WHERE THIS APP DIVERGES FROM BOTH SIBLINGS, which write the plain
	 * file and leave it inert. They can afford to: an empty `.json` dashboard is
	 * still a thing you can type into. A zero-byte `.penpot` is not.
	 *
	 * @param bool $empty whether the body is empty — the app's own create/upload
	 *                    discriminator, read from `Content-Length` at the DAV edge
	 *                    and from the node's size in the listener
	 */
	public function refusalForCreating(Node $parent, string $name, bool $empty): ?string {
		if (!str_ends_with($name, PullService::EXTENSION)) {
			return null;
		}

		$membership = $this->resolver->resolve($parent);

		if ($this->isLinkTeam($membership->teamId)) {
			return $this->l->t(
				'"%s" cannot be created there: that folder mirrors a Penpot team in link mode, so '
				. 'what is in it is filled from Penpot and nothing may be added from this side. '
				. 'Create the design in Penpot and it will appear here.',
				[$name],
			);
		}

		if (!$empty || $membership->state() !== Membership::STATE_NONE) {
			return null;
		}

		return $this->l->t(
			'"%s" cannot be created there: a new design has to be made inside a folder that '
			. 'mirrors a Penpot team, because Penpot keeps every design in a project and there '
			. 'is no project here to put it in. Make it inside a mapped folder instead.',
			[$name],
		);
	}

	/**
	 * Rule 4 (§6.43, §6.34) — which copies this app refuses.
	 *
	 * ## BOTH ENDS, UNLIKE A CREATE, AND FOR A REASON A CREATE DOES NOT HAVE
	 *
	 * {@see refusalForCreating} asks only about the destination, because a file
	 * being made has no history. A copy does: it has a SOURCE, and if that source
	 * is a `link` then what would be copied is a zero-byte pointer. The person
	 * would be left holding an empty file that looks like a design and is not —
	 * anywhere at all, including inside the same link mapping, because nothing on
	 * either side can ever fill it in.
	 *
	 * That is the same sentence {@see forLinkFile} makes about a move, minus its
	 * one carve-out: a link may MOVE within its own project, since Penpot cannot
	 * see a subfolder and the file keeps being the pointer it was. A copy makes a
	 * SECOND file, and the second one is the empty husk.
	 *
	 * The destination half is {@see refusalForCreating}'s link rule exactly —
	 * a link mapping is filled from Penpot and nothing may be added from this side,
	 * whatever is arriving — so it is delegated rather than restated.
	 */
	public function refusalForCopying(Node $source, ?Node $targetParent, string $targetName): ?string {
		// ── A FOLDER FIRST, BECAUSE IT CARRIES EVERYTHING BELOW IT ──────────────
		//
		// A collection COPY is one request that creates the whole subtree, and
		// Sabre does the recursive creates itself — so none of the per-file hooks
		// fire and the destination's basename is a folder name, which ends in no
		// extension and would fall straight through a `.penpot` test. That is a
		// bypass of both halves of this rule at once: a folder of links copied out
		// becomes a tree of empty husks, and a folder of designs copied INTO a link
		// mapping adds to a tree that is Penpot's to fill.
		//
		// Asked WITHOUT {@see forFolder}'s mapping-root carve-out, deliberately.
		// That carve-out exists because reorganising a link mapping's own top-level
		// folder is the mapping's business; copying one is not reorganising it, it
		// is making a second tree somewhere else.
		if ($source instanceof Folder) {
			if ($this->isLinkTeam($this->resolver->resolve($source)->teamId)) {
				return $this->l->t(
					'"%s" is inside a folder that mirrors Penpot in link mode, so it holds pointers '
					. 'rather than the designs themselves. Copying it would leave you with a folder of '
					. 'empty files. Duplicate what you need in Penpot instead.',
					[$source->getName()],
				);
			}

			return $targetParent === null ? null : $this->refusalForLandingInLinkTeam($targetParent, $source->getName());
		}

		if (!str_ends_with($targetName, PullService::EXTENSION)) {
			return null;
		}

		if ($source instanceof File) {
			$meta = $this->metadata->readFile($source->getId());
			if ($meta !== null && $meta->isManaged() && $meta->isLink()) {
				return $this->l->t(
					'"%s" is a link to a design that lives in Penpot — it holds no copy of the design '
					. 'itself, so copying it would leave you with an empty file. Duplicate the design '
					. 'in Penpot instead, and the copy will appear here.',
					[$source->getName()],
				);
			}
		}

		if ($targetParent === null) {
			return null;
		}

		// A copy always carries bytes, so the "empty create" half of the rule below
		// cannot apply — `false` says so rather than leaving it to be inferred.
		return $this->refusalForCreating($targetParent, $targetName, false);
	}

	/** The destination half of the link rule, shared by the file and folder arms. */
	private function refusalForLandingInLinkTeam(Node $targetParent, string $name): ?string {
		if (!$this->isLinkTeam($this->resolver->resolve($targetParent)->teamId)) {
			return null;
		}

		return $this->l->t(
			'"%s" cannot be copied in there: that folder mirrors a Penpot team in link mode, so its '
			. 'contents are filled from Penpot and nothing may be added from this side.',
			[$name],
		);
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
	public function refusalForLandingIn(Node $source, ?Node $targetParent, ?string $targetName = null): ?string {
		return $this->evaluate(
			$source,
			$targetParent === null ? null : $this->resolver->resolve($targetParent),
			$targetName,
		);
	}

	/**
	 * @param Membership|null $to where the source would end up, already resolved
	 * @param string|null $targetName the name it would land under, when the caller
	 *                                knows it. A MOVE and a RENAME are the same DAV
	 *                                verb and the same event, so the name is the only
	 *                                thing that tells them apart — see {@see forLinkFile}.
	 */
	private function evaluate(Node $source, ?Membership $to, ?string $targetName): ?string {
		if ($source instanceof Folder) {
			return $this->forFolder($source, $to);
		}
		if ($source instanceof File) {
			return $this->forLinkFile($source, $to, $targetName);
		}

		return null;
	}

	/**
	 * Rule 1 (§C6.38) — a folder may not move in or out of a `link` mapping.
	 *
	 * ## WHAT THIS RULE REPLACED, AND WHY THE OLD ONE WAS WRONG
	 *
	 * Until §C6.38 this was §6.30: *a project folder stays inside the team folder
	 * it belongs to*, refusing every move that changed the team — including one
	 * that left every mapping. It was stated three times in the old spec and it
	 * was wrong all three, for two separate reasons:
	 *
	 *   - `move-project` takes `{project-id, team-id}`, so crossing a team is ONE
	 *     call, exactly as `move-files` is for a single design. The refusal was
	 *     protecting against a limit Penpot does not have.
	 *   - Dragging a project OUT of every mapping is not a desync, it is an
	 *     unmapping — the same thing a single design leaving already does. The
	 *     Penpot project stands untouched; the folder simply stops mirroring it.
	 *
	 * What is left is the rule that is actually about safety rather than about a
	 * misread API, and it is a MODE rule: a mode belongs to the team, and no
	 * gesture in Nextcloud may change one.
	 *
	 *   - **out of a `link` mapping** — a link folder holds no archives, only
	 *     pointers, so wherever it went it would arrive as a tree of empty files.
	 *     Its own team included: there is nowhere for it to go.
	 *   - **into a `link` mapping** — a link mapping is filled FROM Penpot and
	 *     nothing else, whatever is arriving.
	 *
	 * Applies to every folder, not only a project folder: "whatever is arriving"
	 * is the destination half of the rule, and a plain folder full of designs
	 * arriving in a link team would be the same empty-file surprise one level up.
	 */
	private function forFolder(Folder $source, ?Membership $to): ?string {
		if ($this->metadata->readFolder($source->getId())->hasTeam()) {
			// THE MAPPING ROOT ITSELF, which is the mapping's business and not a
			// project's — the same carve-out the old rule made, for the same reason.
			// Without it the source rule would read the root's own team marker and
			// refuse to let anyone reorganise a link mapping's top-level folder,
			// while the identical gesture on a sync mapping stayed free.
			return null;
		}

		if ($this->isLinkTeam($this->resolver->resolve($source)->teamId)) {
			return $this->l->t(
				'"%s" is inside a folder that mirrors Penpot in link mode, so it holds pointers '
				. 'rather than the designs themselves. Moving it would leave you with a folder of '
				. 'empty files, so it has to stay where Penpot puts it — its own team folder '
				. 'included. Move the project in Penpot instead.',
				[$source->getName()],
			);
		}

		if ($this->isLinkTeam($to?->teamId)) {
			return $this->l->t(
				'"%s" cannot be moved in there: that folder mirrors a Penpot team in link mode, so '
				. 'its contents are filled from Penpot and nothing may be added from this side. '
				. 'Create the project in Penpot and it will appear here.',
				[$source->getName()],
			);
		}

		return null;
	}

	/**
	 * True when this team is mapped in `link` mode.
	 *
	 * A null team is not a link team — it is no team at all, which is the
	 * unmapped destination §C6.38 explicitly allows. An UNMAPPED team id is not
	 * one either: the resolver reads a marker off a folder, and a mapping torn
	 * down since that marker was written leaves the folder still carrying it.
	 * Refusing on a mapping that no longer exists would strand the folder for a
	 * reason the user could not act on.
	 */
	private function isLinkTeam(?string $teamId): bool {
		if ($teamId === null || $teamId === '') {
			return false;
		}

		return $this->mappings->getByTeamId($teamId)?->mode === Mapping::MODE_LINK;
	}

	/**
	 * Rule 2 (§6.43) — a `link` file may not change project, and may not be renamed.
	 *
	 * ## TWO GESTURES, ONE EVENT, AND THE NAME IS ALL THAT SEPARATES THEM
	 *
	 * A rename IS a move to a sibling path: the same DAV verb, the same Nextcloud
	 * event, the same pair of nodes. So the position test below cannot see one —
	 * a rename resolves to the same project it started in and was waved straight
	 * through. That is why the spec's `Rename a link in Nextcloud` was `@unbuilt`
	 * while `A link cannot be moved out of the project it points into` had been
	 * green for courses.
	 *
	 * The name is the discriminator, and it is passed in rather than read off a
	 * node because the DAV side asks BEFORE the destination exists.
	 *
	 * **Why a rename is refused at all, when a move within the project is not.**
	 * A pointer holds no bytes; its name is the design's name, and the design's
	 * name is Penpot's. A local rename would therefore survive exactly until the
	 * next pull renamed it back — a silent undo, which is a worse answer than a no.
	 * Where the file SITS has no such owner: Penpot cannot see a subfolder, so
	 * filing a link inside one is pure local arrangement and stays allowed.
	 */
	private function forLinkFile(File $source, ?Membership $to, ?string $targetName = null): ?string {
		if (!str_ends_with($source->getName(), PullService::EXTENSION)) {
			return null;
		}
		$meta = $this->metadata->readFile($source->getId());
		if ($meta === null || !$meta->isManaged() || !$meta->isLink()) {
			// Untracked content, or a `sync` file — which earns its freedom by
			// holding a real archive and is not constrained here.
			return null;
		}

		if ($targetName !== null && $targetName !== $source->getName()) {
			return $this->l->t(
				'"%s" is a link to a design that lives in Penpot, so its name is Penpot\'s to set — '
				. 'renaming it here would only be undone by the next sync. Rename the design in '
				. 'Penpot and the new name will arrive here.',
				[$source->getName()],
			);
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
