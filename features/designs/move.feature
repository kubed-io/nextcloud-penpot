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

  @in-nextcloud @gesture @todo
  Scenario Outline: Move a design between projects
    Given a design file named "Travelling.penpot" in "<source>"
    When I move the file into "<destination>"
    Then the design is in the "<lands in>" Penpot project
    And the file holds:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |
      | content        | an archive         |

  # notes: ../AGENTS.md#filing-a-draft-dragging-from-the-team-root-into-a-project
  # notes: ../AGENTS.md#un-filing-dragging-from-a-project-out-to-the-team-root

    Examples: a project, the team root which IS Drafts, and back again
      | source           | destination      | lands in  |
      | Penpot/Move From | Penpot/Move To   | Move To   |
      | Penpot/Move From | Penpot           | Drafts    |
      | Penpot           | Penpot/Move To   | Move To   |

  # notes: ../AGENTS.md#a-subfolder-is-nextclouds-layout-not-penpots

    Examples: and a plain subfolder is Nextcloud's layout, which Penpot cannot see
      | source           | destination          | lands in  |
      | Penpot/Move From | Penpot/Move From/wip | Move From |
      | Pointers/Confined | Pointers/Confined/wip | Confined |

  # notes: ../AGENTS.md#a-design-moved-to-another-project-in-penpot-relocates-its-mirror
  @in-penpot @gesture @todo
  Scenario Outline: Move a design in Penpot
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

  @in-nextcloud @gesture @todo
  Scenario Outline: Move a design into another team
    Given the user has a personal Penpot token
    And a design file named "Crossing.penpot" in "Penpot/Move From"
    When I move the file into "<destination>"
    Then the design is in the "<lands in>" Penpot project
    And the file holds:
      | penpot_id      | the original id       |
      | penpot_team_id | the team it landed in |

    Examples: a Team Folder and a personal team are both just teams
      | destination            | lands in    |
      | Shared/Client Work     | Client Work |
      | Sketchbook/Sketches    | Sketches    |

    # One move changing team and project together, keeping the id, the revision and
    # the history. A design is never re-created to cross a team boundary.

    # ── RULE: leaving every mapping leaves the bytes, and coming back adopts ──
    # notes: ../AGENTS.md#moving-a-design-out-of-both-mappings-unmaps-it-from-either-side

  @in-nextcloud @gesture @todo
  Scenario Outline: Move a design out of every mapping
    Given a design file named "Going Loose.penpot" in "<source>"
    When I move the file into "Scratch"
    Then the design still exists in Penpot
    And the file holds:
      | penpot_id      | the original id |
      | penpot_team_id | absent          |
      | penpot_mode    | "unmapped"      |
      | content        | an archive      |

    Examples: from either storage kind, because leaving is leaving
      | source          |
      | Penpot/Let Go   |
      | Shared/Let Go   |

    # Penpot has no recycle bin and needs none: the archive is a valid ".penpot" on
    # its own, so nothing is lost — the app simply stops mirroring it.

  @in-nextcloud @gesture @todo
  Scenario: Move an unmapped design back into a project
    Given an unmapped design file at "Scratch/Going Loose.penpot" whose design is still in Penpot
    When I move the file into "Penpot/Welcome Back"
    Then the design is in the "Welcome Back" Penpot project
    And the file holds:
      | penpot_id      | the original id    |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |

    # The id in the file is what makes this a RETURN rather than an import — the
    # design it names is still there, so nothing new is created.

  # notes: ../AGENTS.md#a-design-file-arriving-in-a-project-becomes-a-design
  @in-nextcloud @gesture @todo
  Scenario: Move an untracked design file into a project
    Given an untracked design file at "Scratch/Uploaded.penpot"
    When I move the file into "Penpot/Adopt Me"
    Then the design is in the "Adopt Me" Penpot project
    And the file holds:
      | penpot_id      | set                |
      | penpot_team_id | the mapping's team |
      | penpot_mode    | the mapping's mode |
      | content        | an archive         |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it leaves
    # the file untracked. A mapping that ignores a design sitting inside it is not one.

  @in-nextcloud @gesture @todo
  Scenario: Move a ".penpot" file Penpot will not accept into a project
    Given an untracked design file at "Scratch/Broken.penpot" whose archive Penpot rejects
    When I move the file into "Penpot/Adopt Me"
    Then the failure is reported to the user, naming what Penpot said
    And the file holds no Penpot metadata at all

    # Best-effort: try the import, and report what came back. The only file that stays
    # untracked is one Penpot itself would not take.

  # notes: ../AGENTS.md#a-design-moved-to-another-team-in-penpot-leaves-this-mapping
  @in-penpot @gesture @todo
  Scenario: Move a design out of a sync mapping in Penpot
    Given a design file named "Departing.penpot" in "Penpot/Upstream"
    When someone moves the design into the "Archive Team" Penpot team
    Then the file is gone from "Penpot/Upstream"
    And the file is recoverable from the Nextcloud trash
    And the design still exists in Penpot

    # The file IS the design's content, and what happened in Penpot is reversible,
    # so the local gesture must be too.

  # notes: ../AGENTS.md#a-design-moved-to-another-team-in-penpot-leaves-this-mapping
  @in-penpot @gesture @todo
  Scenario: Move a design out of a link mapping in Penpot
    Given a design file named "Departing.penpot" in "Pointers/Upstream"
    When someone moves the design into the "Archive Team" Penpot team
    Then the file is gone from "Pointers/Upstream", leaving no trash entry
    And the design still exists in Penpot

    # NOT the refusal below: nothing here happened in Nextcloud. The guard stops a
    # person dragging a link; it has no say over a design moved in Penpot's own UI.

    # ── RULE: a duplicate arriving in a project keeps the id already there ────
    # The person answers what the CONTENT should be; the identity is never theirs to pick.
    # notes: ../AGENTS.md#a-duplicate-arriving-in-a-project-keeps-the-id-already-there

  @in-nextcloud @gesture @todo
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
  @in-nextcloud @gesture @todo
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

  @in-nextcloud @gesture @todo
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
