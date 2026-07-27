<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\FolderMarkers;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\MembershipResolver;
use OCA\PenpotSync\Service\PenpotMetadata;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The nearest-ancestor membership walk — saga §6.29, the single most
 * load-bearing rule in the app, and the one piece with no sibling precedent
 * (both siblings talk to a flat REST API and derive nothing from folder
 * position).
 *
 * Each scenario mirrors one in mapping-membership.feature. The tree is built
 * from mocked {@see Node}s (id + parent, `getParent()` throws past the root) and
 * the folder metadata is a stubbed {@see PenpotMetadata::readFolder()} keyed by
 * node id — so these tests exercise the walk logic in isolation from the
 * metadata storage, which {@see PenpotMetadataTest} covers separately.
 *
 * `Node` is mocked rather than hand-implemented because `OCP\Files\Node` is a
 * large public OCP interface; a mock auto-implements every method and we wire
 * only `getId()` and `getParent()`.
 */
final class MembershipResolverTest extends TestCase {
	private const TEAM_ID = 'team-ferronescotia';
	private const PROJECT_ID = 'proj-my-stuff';
	private const OTHER_PROJECT_ID = 'proj-design-system';

	/** @var array<int, FolderMarkers> node id → its own markers */
	private array $markers = [];

	private MembershipResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->markers = [];
		$metadata = $this->createStub(PenpotMetadata::class);
		$metadata->method('readFolder')->willReturnCallback(
			fn (int $id): FolderMarkers => $this->markers[$id] ?? new FolderMarkers('', ''),
		);
		$this->resolver = new MembershipResolver($metadata);
	}

	public function testFileDirectlyInProjectFolderResolvesToProjectAndTeam(): void {
		// team folder(1) ▸ project folder(2) ▸ file(3)
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$project = $this->folder(2, $team, projectId: self::PROJECT_ID);
		$file = $this->node(3, $project);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->inProject());
		self::assertSame(self::PROJECT_ID, $membership->projectId);
		self::assertSame(self::TEAM_ID, $membership->teamId);
	}

	public function testFileNestedDeeperInsideAProjectStillBelongsToThatProject(): void {
		// team(1) ▸ project(2) ▸ plain "wip"(3) ▸ file(4) — "wip" has no metadata.
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$project = $this->folder(2, $team, projectId: self::PROJECT_ID);
		$wip = $this->node(3, $project); // bare
		$file = $this->node(4, $wip);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->inProject());
		self::assertSame(self::PROJECT_ID, $membership->projectId);
	}

	public function testNearestProjectIdWinsWhenProjectFoldersAreNested(): void {
		// team(1) ▸ "My Stuff"(2) ▸ "Design System"(3) ▸ file(4).
		// The file must resolve to the NEAREST project, Design System.
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$outer = $this->folder(2, $team, projectId: self::PROJECT_ID);
		$inner = $this->folder(3, $outer, projectId: self::OTHER_PROJECT_ID);
		$file = $this->node(4, $inner);

		$membership = $this->resolver->resolve($file);

		self::assertSame(self::OTHER_PROJECT_ID, $membership->projectId, 'nearest ancestor, not outermost');
		self::assertSame(self::TEAM_ID, $membership->teamId);
	}

	public function testProjectFoldersCanBeGroupedUnderOrdinaryFolders(): void {
		// team(1) ▸ plain "Clients"(2) ▸ project "My Stuff"(3) ▸ file(4).
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$clients = $this->node(2, $team); // bare, no Penpot counterpart
		$project = $this->folder(3, $clients, projectId: self::PROJECT_ID);
		$file = $this->node(4, $project);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->inProject());
		self::assertSame(self::PROJECT_ID, $membership->projectId);
		self::assertSame(self::TEAM_ID, $membership->teamId, 'team is found further up, past Clients');
	}

	public function testFileAtTheTeamRootIsInDrafts(): void {
		// A team ancestor but no project ancestor ⇒ Drafts (saga §6.35).
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$file = $this->node(2, $team);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->inDrafts());
		self::assertSame(self::TEAM_ID, $membership->teamId);
		self::assertNull($membership->projectId);
	}

	public function testFileInAnyPlainFolderUnderATeamIsAlsoInDrafts(): void {
		// team(1) ▸ plain "Inbox"(2) ▸ plain "2026"(3) ▸ file(4) — all bare.
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$inbox = $this->node(2, $team);
		$year = $this->node(3, $inbox);
		$file = $this->node(4, $year);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->inDrafts());
		self::assertSame(self::TEAM_ID, $membership->teamId);
	}

	public function testFileWithNoPenpotAncestorBelongsToNoMapping(): void {
		// A folder tree with no Penpot metadata anywhere, terminating at the root.
		$outer = $this->node(1, null); // bare, getParent() throws (root)
		$inner = $this->node(2, $outer); // bare
		$file = $this->node(3, $inner);

		$membership = $this->resolver->resolve($file);

		self::assertSame(Membership::STATE_NONE, $membership->state());
		self::assertFalse($membership->belongsToPenpot());
		self::assertNull($membership->projectId);
		self::assertNull($membership->teamId);
	}

	public function testPersonalProjectHasAProjectButNoTeamAncestor(): void {
		// A personal project folder mounts at the user's home root: it carries a
		// project id but has no team-id ancestor (saga §6.31). That is VALID —
		// personal, not a broken mapping.
		$home = $this->node(1, null); // the user's files root, bare, getParent() throws
		$project = $this->folder(2, $home, projectId: self::PROJECT_ID);
		$file = $this->node(3, $project);

		$membership = $this->resolver->resolve($file);

		self::assertTrue($membership->isPersonal());
		self::assertSame(self::PROJECT_ID, $membership->projectId);
		self::assertNull($membership->teamId);
		self::assertTrue($membership->belongsToPenpot());
	}

	public function testProjectFolderResolvesItsOwnTeamFromAnAncestor(): void {
		// Resolving a project FOLDER (not a file): the walk includes the node
		// itself, so its own project id counts and the team is found above it —
		// two levels up, depth irrelevant.
		$team = $this->folder(1, null, teamId: self::TEAM_ID);
		$mid = $this->node(2, $team); // bare intermediate
		$project = $this->folder(3, $mid, projectId: self::PROJECT_ID);

		$membership = $this->resolver->resolve($project);

		self::assertTrue($membership->inProject());
		self::assertSame(self::PROJECT_ID, $membership->projectId);
		self::assertSame(self::TEAM_ID, $membership->teamId);
	}

	public function testWalkTerminatesAtTheRootWithoutError(): void {
		// A lone node whose getParent() immediately throws — the walk must end
		// cleanly rather than propagate the NotFoundException.
		$lone = $this->node(1, null);

		$membership = $this->resolver->resolve($lone);

		self::assertSame(Membership::STATE_NONE, $membership->state());
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	/** A folder node that also records its own Penpot markers by id. */
	private function folder(int $id, ?Node $parent, string $projectId = '', string $teamId = ''): Node {
		if ($projectId !== '' || $teamId !== '') {
			$this->markers[$id] = new FolderMarkers($projectId, $teamId);
		}
		return $this->node($id, $parent);
	}

	/**
	 * A mocked node with an id and a parent link. `getParent()` throws
	 * {@see NotFoundException} when there is no parent, exactly as the real Node
	 * does past the storage root — which is how the resolver knows to stop.
	 */
	private function node(int $id, ?Node $parent): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		if ($parent === null) {
			$node->method('getParent')->willThrowException(new NotFoundException('no parent above ' . $id));
		} else {
			$node->method('getParent')->willReturn($parent);
		}
		return $node;
	}
}
