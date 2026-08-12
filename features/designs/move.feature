# Notes, decisions and history for this feature: ../AGENTS.md#designsmove

Feature: Moving a design
  As a Nextcloud user
  I want moving a design file to re-file the design in Penpot
  So that the folder I drag it into is the project it belongs to

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Reference Team |
      | folder | Pointers       |
      | mode   | link           |
    And a mapping with the following values:
      | team   | Second Team |
      | folder | Shared      |
      | mode   | sync        |
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |

    # ── RULE: a design belongs to the project its file sits in ────────────────
    # notes: ../AGENTS.md#dragging-a-sync-design-into-another-project-re-files-it-in-penpot

  @in-nextcloud @gesture
  Scenario Outline: Move a design between projects
    Given a mirrored design "Travelling" in the project "Move From"
    And a mirrored project "Move To"
    When I move "<from>/Travelling.penpot" to "<to>/Travelling.penpot"
    Then Penpot project "<lands in>" holds a design named "Travelling"
    And "<to>/Travelling.penpot" holds:
      | penpot_id      | the id it had before the move |
      | penpot_team_id | the team's id                 |
      | penpot_mode    | "sync"                        |
      | content        | an archive                    |

    Examples: filing and un-filing are the same move, with Drafts at one end
      | from             | to               | lands in  |
      | Penpot/Move From | Penpot/Move To   | Move To   |
      | Penpot/Move From | Penpot           | Drafts    |
      | Penpot           | Penpot/Move To   | Move To   |

    # The team root IS Drafts, so dragging in or out of a project is one behaviour
    # rather than a "file" gesture and an "un-file" gesture.

  # notes: ../AGENTS.md#a-subfolder-is-nextclouds-layout-not-penpots
  @in-nextcloud @gesture
  Scenario Outline: Move a design into a plain subfolder of its own project
    Given a Penpot team named "<team>" exists
    And a mirrored design "Wanderer" in the project "<project>"
    And a folder at "<folder>/<project>/wip" that is not a project
    When I move "<folder>/<project>/Wanderer.penpot" to "<folder>/<project>/wip/Wanderer.penpot"
    Then Penpot project "<project>" holds a design named "Wanderer"
    And the file "<folder>/<project>/wip/Wanderer.penpot" still carries its Penpot id

    Examples: Nextcloud owns layout, and Penpot has no concept of a subfolder
      | team           | folder   | project       |
      | Design Team    | Penpot   | Stays Put     |
      | Reference Team | Pointers | Also Confined |

    # Confinement is to the PROJECT, not to a folder — which is why a link may be
    # filed away in a subfolder too.

    # ── RULE: a link is confined to the project it points into ────────────────
    # notes: ../AGENTS.md#a-link-cannot-be-moved-out-of-the-project-it-points-into

  @in-nextcloud @gesture
  Scenario Outline: Move a link out of its project
    Given a Penpot team named "Reference Team" exists
    And a mirrored design "Pointer" in the project "Confined"
    And a mirrored project "Elsewhere"
    When I try to move "Pointers/Confined/Pointer.penpot" to "<destination>"
    Then the move is refused
    And Penpot project "Confined" holds a design named "Pointer"
    And the file "Pointers/Confined/Pointer.penpot" carries a Penpot id

    Examples: every destination that would change what the pointer points at
      | destination                        |
      | Pointers/Elsewhere/Pointer.penpot  |
      | Pointers/Pointer.penpot            |
      | Pointer.penpot                     |

    # ── RULE: leaving every mapping leaves the bytes ──────────────────────────

  @in-nextcloud @gesture @unbuilt
  Scenario: Move a design out of every mapping
    Given a mirrored design "Going Loose" in the project "Let Go"
    And a folder at "Outside" that is not mapped
    When I move "Penpot/Let Go/Going Loose.penpot" to "Outside/Going Loose.penpot"
    Then Penpot project "Let Go" holds a design named "Going Loose"
    And "Outside/Going Loose.penpot" holds:
      | penpot_id   | the id it had before the move |
      | penpot_mode | "unmapped"                    |
      | content     | an archive                    |

    # The archive stays a valid ".penpot" on its own, so nothing is lost — the app
    # simply stops mirroring it.

    # ── RULE: a design file arriving in a project becomes a design ────────────
    # notes: ../AGENTS.md#a-design-file-arriving-in-a-project-becomes-a-design

  @in-nextcloud @gesture @unbuilt
  Scenario: Move an untracked design file into a project
    Given a mirrored project "Adopt Me"
    And an untracked ".penpot" archive at "Uploaded.penpot"
    When I move "Uploaded.penpot" to "Penpot/Adopt Me/Uploaded.penpot"
    Then Penpot project "Adopt Me" holds a design named "Uploaded"
    And "Penpot/Adopt Me/Uploaded.penpot" holds:
      | penpot_id | set        |
      | content   | an archive |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it leaves
    # the file untracked. A mapping that ignores a design sitting inside it is not
    # a mapping.

  @in-nextcloud @gesture @unbuilt
  Scenario: Move a ".penpot" file Penpot will not accept into a project
    Given a mirrored project "Adopt Me"
    And an untracked ".penpot" file whose archive Penpot rejects
    When I move it into "Penpot/Adopt Me"
    Then the failure is reported to the user, naming what Penpot said
    And "Penpot/Adopt Me/Uploaded.penpot" holds:
      | penpot_id | absent |

    # Best-effort: try the import, and report what came back. The only file that
    # stays untracked is one Penpot itself would not take.

    # ── RULE: a design carries its team as well as its project ────────────────
    # notes: ../AGENTS.md#moving-a-design-from-a-personal-project-into-a-mapped-team-project

  @in-nextcloud @gesture @unbuilt
  Scenario Outline: Move a design into another team
    Given the user has a personal Penpot token
    And a mirrored design "Crossing" in the project "Move From"
    And a mirrored project "<project>" under "<folder>"
    When I move "Penpot/Move From/Crossing.penpot" to "<folder>/<project>/Crossing.penpot"
    Then the design "Crossing" is in the "<project>" project in Penpot
    And "<folder>/<project>/Crossing.penpot" holds:
      | penpot_id      | the id it had before the move |
      | penpot_team_id | the team it landed in         |

    Examples: a personal team is a team, so it is the same move
      | folder    | project     |
      | Shared    | Client Work |
      | Sketchbook | Sketches   |

    # One move, changing team and project together, keeping the id, revision and
    # history. A design is never re-created to cross a team boundary.

    # ── RULE: a move Penpot will not take still stands locally ────────────────

  @in-nextcloud @gesture @todo
  Scenario: Move a design while Penpot is unreachable
    Given a mirrored design "Travelling" in the project "Move From"
    And a mirrored project "Move To"
    And Penpot is unreachable
    When I move "Penpot/Move From/Travelling.penpot" to "Penpot/Move To/Travelling.penpot"
    Then the file "Penpot/Move To/Travelling.penpot" carries a Penpot id
    And the failure is reported to the user
    And Penpot project "Move From" holds a design named "Travelling"

    # ── RULE: a design moved in Penpot takes its mirror with it ───────────────
    # notes: ../AGENTS.md#a-design-moved-to-another-project-in-penpot-relocates-its-mirror

  @in-penpot @gesture @todo
  Scenario Outline: Move a design in Penpot
    Given a mirrored design "Relocated" in the project "Upstream From"
    And a mirrored project "Upstream To"
    When someone moves the design "Relocated" into "<project>" in Penpot
    Then there is no node at "Penpot/Upstream From/Relocated.penpot"
    And the file "<lands at>" carries a Penpot id
    And the file "<lands at>" is not in the Nextcloud trash

    Examples: Drafts is a state, so its mirror surfaces at the team root
      | project     | lands at                            |
      | Upstream To | Penpot/Upstream To/Relocated.penpot |
      | Drafts      | Penpot/Relocated.penpot             |

  # notes: ../AGENTS.md#a-design-moved-to-another-team-in-penpot-leaves-this-mapping
  @in-penpot @gesture @unbuilt
  Scenario: Move a design to an unmapped team in Penpot
    Given a mirrored design "Departing" in the project "Upstream From"
    When someone moves the design "Departing" into a team this app does not map
    Then the file "Penpot/Upstream From/Departing.penpot" is in the Nextcloud trash

    # Not a special case: from this mapping's point of view the design is simply
    # gone, and a vanished design's mirror is trashed like any other.
