# Notes, decisions and history for this feature: ../AGENTS.md#designsmove

Feature: Moving a design
  As a Nextcloud user
  I want a move to mean the same thing in Penpot
  So that relocating a file never duplicates a design or silently desyncs one

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped
    And a Penpot team "Archive Team" that this app does not map

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a design belongs to the project its file sits in ────────────────
    # The base case from both sides — a re-file in Penpot, never a delete and recreate.
    # notes: ../AGENTS.md#dragging-a-sync-design-into-another-project-re-files-it-in-penpot

  @in-nextcloud @gesture
  Scenario Outline: Move a design between project folders within Nextcloud
    Given a design file named "Travelling.penpot" in "<source>"
    When I move the file into "<destination>"
    Then the design is in the "<lands in>" Penpot project
    And the file holds:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |
      | content        | the mapping's body |

  # notes: ../AGENTS.md#filing-a-draft-dragging-from-the-team-root-into-a-project
  # notes: ../AGENTS.md#un-filing-dragging-from-a-project-out-to-the-team-root

    Examples: a project, the team root which IS Drafts, and back again
      | source           | destination      | lands in  |
      | Penpot/Move From | Penpot/Move To   | Move To   |
      | Penpot/Move From | Penpot           | Drafts    |
      | Penpot           | Penpot/Move To   | Move To   |

  # notes: ../AGENTS.md#a-subfolder-is-nextclouds-layout-not-penpots

    Examples: and a plain subfolder is Nextcloud's layout, which Penpot cannot see
      | source            | destination           | lands in  |
      | Penpot/Move From  | Penpot/Move From/wip  | Move From |
      | Pointers/Confined | Pointers/Confined/wip | Confined  |

  # notes: ../AGENTS.md#a-design-moved-to-another-project-in-penpot-relocates-its-mirror
  @in-penpot @gesture
  Scenario Outline: Move a design between Penpot projects
    Given a design file named "Relocated.penpot" in "Penpot/Upstream From"
    When someone moves the design into the "<project>" Penpot project
    Then the file is gone from "Penpot/Upstream From"
    And the file arrives at "<lands at>", holding:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |

    Examples: Drafts is a state, so its mirror surfaces at the team root
      | project     | lands at                            |
      | Upstream To | Penpot/Upstream To/Relocated.penpot |
      | Drafts      | Penpot/Relocated.penpot             |

    # ── RULE: a design carries its team as well as its project ────────────────
    # notes: ../AGENTS.md#moving-a-design-from-a-personal-project-into-a-mapped-team-project

  # notes: ../AGENTS.md#a-cross-team-move-always-crosses-a-storage-boundary
  # @blocked — the only two teams this suite can map sit on different storages,
  # and a file's metadata does not survive the crossing.
  @in-nextcloud @gesture @blocked
  Scenario Outline: Move a design into another team
    Given a design file named "Crossing.penpot" in "<source>"
    When I move the file into "<destination>"
    Then the design is in the "<lands in>" Penpot project
    And the file holds:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |

    Examples: between the two storage kinds, in both directions
      | source            | destination        | lands in    |
      | Penpot/Move From  | Shared/Client Work | Client Work |
      | Shared/Move From  | Penpot/Client Work | Client Work |

    # One move changing team and project together, keeping the id, the revision and
    # the history. A design is never re-created to cross a team boundary.

    # ── RULE: leaving every mapping trashes the design, and coming back revives it ──
    # notes: ../AGENTS.md#moving-a-design-out-of-both-mappings-unmaps-it-from-either-side

  # notes: ../AGENTS.md#a-cross-team-move-always-crosses-a-storage-boundary
  @in-nextcloud @gesture
  Scenario: Move a design out of every mapping
    Given a design file named "Going Loose.penpot" in "Penpot/Let Go"
    When I move the file into "Scratch"
    Then the design "Going Loose" is in Penpot's trash
    And the file holds:
      | penpot_id      | the original id |
      | penpot_team_id | absent          |
      | penpot_mode    | "unmapped"      |
      | content        | an archive      |

    # The id stays on the file because it is what makes the return below a reattach
    # rather than an import, and the trash is what keeps that id worth naming.

  # notes: ../AGENTS.md#coming-back-revives-whatever-penpot-still-has
  @in-nextcloud @gesture
  Scenario Outline: Move a design file into a project when Penpot still has its design
    Given an unmapped design file at "Scratch/Coming Back.penpot" carrying its Penpot id
    And its design is <where> in Penpot
    When I move the file into "Penpot/Welcome Back"
    Then the design is in the "Welcome Back" Penpot project
    And the design "Coming Back" is not in Penpot's trash
    And the file holds:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |

    Examples: parked or never gone, the id in the file names it either way
      | where   |
      | trashed |
      | live    |

  # notes: ../AGENTS.md#an-arrival-penpot-cannot-match-becomes-a-new-design
  @in-nextcloud @gesture
  Scenario Outline: Move a design file into a project when Penpot has no design for it
    Given a design file at "Scratch/Uploaded.penpot" carrying <what>
    When I move the file into "Penpot/Adopt Me"
    Then the design is in the "Adopt Me" Penpot project
    And the file holds:
      | penpot_id      | a new one, never the one it arrived with |
      | penpot_team_id | the mapping's team                       |
      | penpot_mode    | the mapping's mode                       |
      | content        | an archive                               |

    Examples: an id nothing answers to and no id at all are the same question
      | what                       |
      | an id no design answers to |
      | no Penpot id at all        |

    # Nothing is lost either way, because the bytes were never anywhere but Nextcloud:
    # the archive is imported (§6.33) and the design starts a fresh history.

  # notes: ../AGENTS.md#there-is-nowhere-for-a-failure-to-be-reported-to
  # @todo — the report travels now; nothing in this suite can read a bell entry.
  @in-nextcloud @gesture @todo
  Scenario: Move a ".penpot" file Penpot will not accept into a project
    Given an untracked design file at "Scratch/Broken.penpot" whose archive Penpot rejects
    When I move the file into "Penpot/Adopt Me"
    Then the failure is reported to the user, naming what Penpot said
    And the file holds no Penpot metadata at all

    # Best-effort: try the import, and report what came back. The only file that stays
    # untracked is one Penpot itself would not take.

  # notes: ../AGENTS.md#a-design-moved-to-another-team-in-penpot-leaves-this-mapping
  @in-penpot @gesture
  Scenario Outline: Someone moves a design into an unmapped team in Penpot
    Given a design file named "Departing.penpot" in "<source>"
    When someone moves the design into the "Archive Team" Penpot team
    Then the file is gone from "<source>", leaving no trash entry
    And the design still exists in Penpot

    Examples: a mirror and a pointer leave the same way
      | source            |
      | Penpot/Upstream   |
      | Pointers/Upstream |

    # A move is not a delete, and a trashed mirror would read as one. Nobody deleted
    # anything: Penpot still holds the design, in the team it was moved to.

    # ── RULE: a duplicate arriving in a project keeps the id already there ────
    # The person answers what the CONTENT should be; the identity is never theirs to pick.
    # notes: ../AGENTS.md#a-duplicate-arriving-in-a-project-keeps-the-id-already-there

  # @blocked — `I select "<kept>"` is Nextcloud's conflict dialog and there is no
  # browser here; WebDAV never asks the question, it just overwrites.
  @in-nextcloud @gesture @blocked
  Scenario Outline: Keeping one version of a duplicate leaves one file and one design
    Given a design file named "Turnbuckle.penpot" in "Penpot/Crowded"
    And an unmapped design file at "Scratch/Turnbuckle.penpot" carrying "<its id>"
    And that file's archive differs from the design's
    When I move that file into "Penpot/Crowded"
    And I select "<kept>"
    Then "Penpot/Crowded/Turnbuckle.penpot" holds the archive of "<the body that wins>"
    And its design in Penpot still exists and holds that same archive
    And "Penpot/Crowded/Turnbuckle.penpot" holds:
      | penpot_id      | the id the destination already had |
      | penpot_team_id | the mapping's team                 |
      | penpot_mode    | the mapping's mode                 |

    Examples: the answer decides whose body it keeps, and the id it arrived with never does
      | kept                 | its id                | the body that wins     |
      | the existing version | the same penpot_id    | the file already there |
      | the existing version | a different penpot_id | the file already there |
      | the existing version | no penpot_id at all   | the file already there |
      | the new version      | the same penpot_id    | the file that arrived  |
      | the new version      | a different penpot_id | the file that arrived  |
      | the new version      | no penpot_id at all   | the file that arrived  |

  # notes: ../AGENTS.md#keeping-both-versions-of-a-duplicate-makes-the-arrival-its-own-design
  # @blocked — the same dialog as above, and `both versions` is the branch of it
  # that only a browser can take.
  @in-nextcloud @gesture @blocked
  Scenario: Keeping both versions of a duplicate makes the arrival its own design
    Given a design file named "Turnbuckle.penpot" in "Penpot/Crowded"
    And an unmapped design file at "Scratch/Turnbuckle.penpot" carrying "the same penpot_id"
    And that file's archive differs from the design's
    When I move that file into "Penpot/Crowded"
    And I select "both versions"
    Then "Penpot/Crowded/Turnbuckle.penpot" holds:
      | penpot_id   | the id the destination already had |
      | penpot_mode | the mapping's mode                 |
    And its design in Penpot is named "Turnbuckle" and holds the archive it always had
    And "Penpot/Crowded/Turnbuckle (1).penpot" holds:
      | penpot_id   | its own, not the one it arrived with |
      | penpot_mode | the mapping's mode                   |
    And its design in Penpot is named "Turnbuckle (1)" and holds the archive that arrived

    # ── RULE: a link is not movable, and a link mapping is not a destination ──
    # notes: ../AGENTS.md#a-link-cannot-be-moved-out-of-the-project-it-points-into

  @in-nextcloud @gesture
  Scenario Outline: Moving a link, or into a link mapping, is refused
    Given a design file named "Pointer.penpot" in "<source>"
    When I try to move the file into "<destination>"
    Then the move is refused with a message
    And the file stays in "<source>"
    And the original file and its design are unchanged

  # notes: ../AGENTS.md#a-refusal-has-to-reach-the-person-and-the-listener-cannot-carry-it

    Examples: a link is read-only in Nextcloud, and there is nowhere it may go
      | source            | destination        |
      | Pointers/Confined | Pointers/Elsewhere |
      | Pointers/Confined | Pointers           |
      | Pointers/Confined | Scratch            |

    Examples: and a link mapping is filled from Penpot, whatever is arriving
      | source           | destination       |
      | Penpot/Move From | Pointers/Confined |

    # ── RULE: a move that cannot finish leaves the file as it was ─────────────

  # notes: ../AGENTS.md#there-is-nowhere-for-a-failure-to-be-reported-to
  # @todo — the report travels now; nothing in this suite can read a bell entry.
  @in-nextcloud @gesture @todo
  Scenario: Move a design while Penpot is unreachable
    Given a design file named "Travelling.penpot" in "Penpot/Move From"
    And Penpot is unreachable
    When I move the file into "Penpot/Move To"
    Then the failure is reported to the user
    And the design is in the "Move From" Penpot project
    And the file holds:
      | penpot_id   | the original id    |
      | penpot_mode | the mapping's mode |
