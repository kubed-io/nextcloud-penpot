# DELETING A PROJECT — the folder, which is ONE Penpot call rather than one per
# design. Deleting a design is delete-design.feature.
#
# WHY IT IS NOT A LOOP. `delete-project` removes the project and everything in it
# server-side, so the app never walks the designs. That matters beyond
# efficiency: a per-design loop could fail halfway and leave a project that is
# half-deleted on one side and whole on the other, which is precisely the state
# nothing in this app is allowed to produce.
#
# WHAT IS NOT A PROJECT. A plain folder that merely sits inside a mapped folder
# carries no `penpot_project_id`, so deleting it touches nothing in Penpot — and
# the TEAM ROOT is never deletable as a project, because it is the mapping, not a
# project in it.
#
# The design-side rules about the two bins, the permanent-delete guard, and link
# dismissal all live in delete-design.feature and are not restated here.

Feature: Deleting a Penpot project folder
  As a Nextcloud user
  I want deleting a project folder to delete the project in Penpot
  So that removing a folder means the same thing on both sides
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a project folder deletes the project in Penpot
    Given a mirrored design "Inside" in the project "Doomed"
    When I delete the "Doomed" project folder
    Then "delete-project" is called with that project's id
    And Penpot no longer lists a project named "Doomed"
    And the design "Inside" is in Penpot's trash
    And the folder is recoverable from the Nextcloud trash
    # The two trashes line up: Nextcloud's is reversible and so is Penpot's, on
    # a comparable window. This is the same shape as deleting a single mirror
    # (above) one level up the tree.

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a project folder does not need a per-design call
    Given a mirrored project "Doomed" holding 3 designs
    When I delete the "Doomed" project folder
    Then "delete-file" is never called
    And exactly one "delete-project" call is made
    # Penpot cascades server-side, so mirroring its behaviour is ONE call, not
    # N+1 — and doing it per-file would be worse than redundant: it would leave
    # the project itself alive and empty if the last call failed.

  @in-nextcloud @gesture @todo
  Scenario: A plain folder inside a mapped folder deletes without touching Penpot
    Given a plain folder "Just My Notes" inside the mapped folder
    When I delete it
    Then Penpot is never contacted
    # Only a folder carrying `penpot_project_id` is a project. This is the same
    # rule the tag opt-in rests on (create-project.feature), stated for delete.

  @in-nextcloud @gesture @unbuilt
  Scenario: The team root is never deletable as a project
    When I try to delete the mapped folder itself
    Then "delete-project" is never called for the team's Drafts project
    # Penpot answers `:non-deletable-project` for a team's default project. The
    # team root carries `penpot_team_id`, not `penpot_project_id`, so it does not
    # resolve as a project folder and never reaches the call.

  @in-penpot @todo
  Scenario: A project deleted in Penpot leaves no folder claiming its id
    Given a mirrored design "Orphan" in the project "Deleted Upstream"
    When the project is deleted in Penpot
    And the team is mirrored again
    Then the mirror of "Orphan" is in the Nextcloud trash
    And the folder does not still carry the dead project's id
    # THE GAP THE LIVE PROBE FOUND (§C6.19). The designs prune correctly, with
    # rescue archives — but the FOLDER survives, still stamped with a project id
    # that no longer resolves, still wearing the `penpot` tag. Anything dropped
    # into it afterwards resolves to a project Penpot will refuse.
    #
    # `get-all-projects` filters deleted projects out, so the pull cannot tell
    # "deleted" from "never existed" — which is exactly why the pull must not
    # DELETE the folder either. Un-stamping it (and un-tagging it) turns it back
    # into an ordinary folder, which is the truthful end state.

    # ── the hard step: emptying the trash purges Penpot ───────────────────────

  @todo
  Scenario: Restoring a design also restores its project if that was deleted too
    Given a Penpot project that was deleted, containing a design
    When the design is restored from Penpot's trash
    Then its containing project is restored as well
    And the project folder reappears on the next pull
    # Penpot's restore clears deleted_at on the project as well as the file.

  # ── the same gesture in a personal team, and it does NOT mean the same ──────
  # The one place the personal case genuinely diverges, which is exactly why it
  # belongs beside the team-project answer rather than in a file of its own: a
  # reader asking "what does deleting a project folder do?" needs both answers
  # in one place.

  @unbuilt
  Scenario: Deleting a personal project folder never touches Penpot
    Given a personal project folder in the user's home
    When the user deletes the folder
    Then Penpot is never contacted
    And the Penpot project and its designs are completely unaffected
    And the folder is recoverable from the Nextcloud trash
