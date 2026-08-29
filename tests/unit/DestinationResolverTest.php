<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Service\DestinationResolver;
use OCA\PenpotSync\Service\Membership;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\ProjectFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * WHERE A DESIGN THAT HAS JUST ARRIVED BELONGS — the rule, on its own.
 *
 * Every write path funnels through {@see DestinationResolver::projectForContentIn()}
 * (created, moved in, copied in, pushed in bulk), so this one method decides
 * whether somebody's design lands in the folder they dropped it in or somewhere
 * up the tree. It had no test of its own until the rule reversed under it.
 *
 * ## THE REVERSAL THESE PIN
 *
 * It used to answer with the nearest project ANCESTOR whenever there was one, so
 * a design dragged into `Bubbles/pustice` where `Bubbles` was already a project
 * stayed in `Bubbles` and `pustice` became nothing. Two identical-looking folders
 * behaved differently on a marker nobody can see, decided by whichever folder
 * happened to get a design first. Reported from a live instance, and it made
 * `projects/create.feature`'s own headline — *a folder is a project when a design
 * is in it* — false of every folder below a project.
 *
 * The folder the design lands in is the project now. The ancestor is a fallback,
 * and {@see testALinkMappingFallsBackToTheProjectAboveRatherThanDrafts()} is why
 * that fallback is not simply Drafts.
 */
final class DestinationResolverTest extends TestCase {
	private const TEAM = 'df59d46b-a997-80d9-8008-6452575a4b87';
	private const ANCESTOR = '4eda2e11-843e-8045-8008-51824bdafd88';
	private const PROMOTED = '86f123cb-0682-808c-8008-69d4e8b803ec';
	private const DRAFTS = '0f9b6c2a-5d31-4e88-a1f0-9c7b3d2e5a44';

	private PenpotClient $client;
	private ProjectFolderService $projects;
	private DestinationResolver $destinations;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(PenpotClient::class);
		$this->projects = $this->createMock(ProjectFolderService::class);
		$this->destinations = new DestinationResolver($this->client, $this->projects, new NullLogger());
	}

	/** The plain case: a folder Penpot has never seen becomes a project. */
	public function testTheFolderADesignLandsInBecomesTheProject(): void {
		$this->projects->method('adoptForContent')->willReturn(self::PROMOTED);

		self::assertSame(
			self::PROMOTED,
			$this->destinations->projectForContentIn($this->design(), new Membership(null, self::TEAM)),
		);
	}

	/**
	 * THE REVERSAL. The membership carries a project — resolved from an ANCESTOR,
	 * because the folder the design landed in has no marker of its own — and this
	 * used to return it and stop. Now the landing folder is promoted and the
	 * ancestor is only what we fall back to.
	 *
	 * `Bubbles/pustice`, from the report that caused this.
	 */
	public function testASubfolderOfAProjectBecomesAProjectOfItsOwn(): void {
		$this->projects->method('adoptForContent')->willReturn(self::PROMOTED);

		self::assertSame(
			self::PROMOTED,
			$this->destinations->projectForContentIn($this->design(), new Membership(self::ANCESTOR, self::TEAM)),
			'the folder the design was dropped into is the project, not the one above it',
		);
	}

	/**
	 * A LINK MAPPING REFUSES PROMOTION — the tree there is filled FROM Penpot, so
	 * nothing this app does may create structure in it — and the fallback has to be
	 * the project the mirror is sitting under.
	 *
	 * Drafts would be actively wrong: it would move somebody's design out of the
	 * project Penpot has it in, because they made a folder. The old code could not
	 * get this wrong (it never reached the fallback when an ancestor existed); the
	 * new one has to choose, and this is the choice.
	 */
	public function testALinkMappingFallsBackToTheProjectAboveRatherThanDrafts(): void {
		$this->projects->method('adoptForContent')->willReturn(null);
		$this->client->expects($this->never())->method('getAllProjects');

		self::assertSame(
			self::ANCESTOR,
			$this->destinations->projectForContentIn($this->design(), new Membership(self::ANCESTOR, self::TEAM)),
		);
	}

	/**
	 * THE MAPPING ROOT IS DRAFTS (§6.35). `adoptForContent()` answers null there —
	 * a root has no path below a mapping to be named by — and there is no ancestor
	 * project either, so the design belongs to the team's default project.
	 */
	public function testADesignAtTheMappingRootGoesToDrafts(): void {
		$this->projects->method('adoptForContent')->willReturn(null);
		$this->client->method('getAllProjects')->willReturn([
			['id' => self::ANCESTOR, 'team-id' => self::TEAM, 'is-default' => false],
			['id' => self::DRAFTS, 'team-id' => self::TEAM, 'is-default' => true],
		]);

		self::assertSame(
			self::DRAFTS,
			$this->destinations->projectForContentIn($this->design(), new Membership(null, self::TEAM)),
		);
	}

	/** Outside every mapping there is nothing to write to, and no guess to make. */
	public function testOutsideEveryMappingThereIsNoDestination(): void {
		$this->projects->expects($this->never())->method('adoptForContent');

		self::assertNull(
			$this->destinations->projectForContentIn($this->design(), new Membership(null, null)),
		);
	}

	/**
	 * {@see DestinationResolver::projectFor()} is the READING half and did not
	 * change: it answers where a node already IS, promotes nothing, and is what
	 * {@see \OCA\PenpotSync\Service\MotionService::sourceProject()} asks about the
	 * folder a design came FROM. Adopting there would create a project for the
	 * folder someone just dragged a design out of.
	 */
	public function testTheReadingHalfStillAnswersWithTheAncestorAndPromotesNothing(): void {
		$this->projects->expects($this->never())->method('adoptForContent');

		self::assertSame(
			self::ANCESTOR,
			$this->destinations->projectFor(new Membership(self::ANCESTOR, self::TEAM)),
		);
	}

	/** A `.penpot` whose parent folder is the node this all hangs off. */
	private function design(): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(31);
		$parent->method('getPath')->willReturn('/admin/files/Design Files/Bubbles/pustice');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(30);
		$file->method('getName')->willReturn('so dope man (1).penpot');
		$file->method('getParent')->willReturn($parent);

		return $file;
	}
}
