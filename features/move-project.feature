# MOVING A PROJECT — the folder, and the one rule that makes it different from
# moving a design: a project folder's POSITION is constrained where a design's is
# not. Moving a design is move-design.feature.
#
# WHY A PROJECT FOLDER IS PINNED TO ITS TEAM FOLDER. A project belongs to exactly
# one Penpot team, and the team folder IS that team in Nextcloud. Dragging the
# folder out of it would assert a membership Penpot has no way to represent — so
# it is refused, visibly, with the alternative spelled out. Inside its own team
# folder the user may put it wherever they like: Nextcloud owns layout, Penpot
# owns membership (saga §6.29/§6.30).
#
# THE INVARIANT THAT COVERS BOTH FILES lives in move-design.feature — "no move,
# of any file or folder, ever deletes anything in Penpot" — and is not restated
# here, because one copy of a rule is the point of splitting these.

Feature: Moving a Penpot project folder
  As a Nextcloud user
  I want to arrange project folders inside my team folder
  So that the tree suits me without ever changing which team a project is in
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team is mapped to the folder "Penpot"

  @in-nextcloud @gesture @todo
  Scenario: A project folder can be moved anywhere inside its team folder
    Given a plain folder "Clients" inside the team folder
    When I move a project folder into "Clients"
    Then the move succeeds
    And Penpot is never contacted
    And files inside it still belong to the same project
    And the folder still resolves to the same team, found further up
    And a pull does not move the folder back
    # Free organisation is the whole point of §6.29 — Penpot is flat, we needn't be.

  @in-nextcloud @gesture
  Scenario: A project folder cannot be moved out of its team folder
    Given a mirrored project "Stays Inside"
    When I try to move "Penpot/Stays Inside" to "Stays Inside"
    Then the move is refused
    And the folder "Penpot/Stays Inside" carries a Penpot project id
    # The folder is still there, still stamped — the refusal happened BEFORE the
    # move, which is the whole point of guarding on the `Before` event.

  @in-nextcloud @gesture @todo
  Scenario: The project-folder refusal explains why, and what to do instead
    Given a mirrored project "Stays Inside"
    When I try to move it outside its team folder
    Then the refusal explains a project cannot leave its team from Nextcloud
    And it explains that moving a project between teams must be done in Penpot
    # Split from the scenario above, which proves the refusal HAPPENS; this one
    # is about what it SAYS, and needs the exception body surfaced through DAV.
    # Saga §6.30. Reparenting a project in Penpot (`move-project`) is real and
    # confirmed, but it is a destructive cross-team mutation that changes who can
    # see the work — far outside §6.1. Refuse loudly; never silently undo.

  @in-nextcloud @gesture @unbuilt
  Scenario: A project folder cannot be moved into a different team's folder
    Given a second team folder mapped to another Penpot team
    When I try to move a project folder into it
    Then the move is refused with the same explanation
    And neither team's mapping is modified

    # ══ MOVED IN PENPOT ════════════════════════════════════════════════════════
    #
    # The same behaviour from the other end, and it arrives via a sync run rather
    # than an event. Penpot is authoritative for project membership, so a design
    # re-filed upstream relocates its mirror — it is not a conflict to resolve, it
    # is the source of truth changing.

  # ── the same rule in a personal team ────────────────────────────────────────
  # A personal project is a project. The WHO (the user's own token) and the WHERE
  # (their home root, no team-folder ancestor) differ; the rule does not — see
  # personal-projects.feature for what actually is special about them.

  @unbuilt
  Scenario: A user can move their personal project folders anywhere in their home
    Given a personal project folder "Sketches" at the user's home root
    And a plain folder "Design" in the user's home
    When the user moves "Sketches" into "Design"
    Then the move succeeds
    And files inside "Sketches" still belong to the "Sketches" project
    And a pull does not move the folder back
    # Same free-nesting rule as team projects (saga §6.29). There is no team
    # folder to stay inside, so the §6.30 restriction has nothing to bite on.

    # ── the credential boundary ──────────────────────────────────────────────────
