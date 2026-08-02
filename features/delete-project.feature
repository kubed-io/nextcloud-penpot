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
#
# ## A CORRECTION THIS FILE EXISTS TO CARRY (saga §C6.25)
#
# This spec used to say "deleting a personal project folder never touches
# Penpot". That was not a rule — it was the CURRENT DEFECT written up as one.
# Deleting a project folder reaches Penpot **not at all** today, for two stacked
# reasons (DeleteListener bails on anything that is not a File, and Nextcloud
# fires BeforeNodeDeletedEvent for the folder ONLY, with no per-child event), and
# the folder then comes back on the next pull — which reads as the app undoing
# the user's deletion. Somewhere between "we will deal with this later" and the
# spec, later became never.
#
# The mirror's whole premise is parity: a folder the user tagged into existence
# is one they can delete the same way, in a personal team exactly as in a mapped
# one. Only the credential differs.
#
# ## WHAT PENPOT ACTUALLY DOES, measured (saga §C6.11)
#
#   delete-project {id}   → HTTP 204, and it is ENTIRELY SOFT. Sets
#                           project.deleted_at to now + deletion-delay (7 days by
#                           default) and a worker cascades the SAME future
#                           timestamp to every file in it.
#   restore               → there is NO restore-project RPC. A project returns
#                           only as a SIDE EFFECT of restoring one of its files.
#   an EMPTY project      → has no file to carry it back, so it cannot be
#                           restored through the API at all. It expires.
#
# DELETE CASCADES; RESTORE DOES NOT — measured by deleting a project holding two
# designs and restoring only one: the project came back, that design came back,
# the other stayed in the trash. So "restore the project folder" has to mean
# "restore every design that was in it, in ONE call" — not for tidiness, but
# because a per-file loop that failed halfway would leave a project holding some
# of its designs and no signal that anything was wrong.
#
# THE GRACE WINDOW LINES UP WITH THE NEXTCLOUD TRASH almost exactly, which is
# what makes the mirror honest: soft on both sides, recoverable on both sides,
# for roughly the same week. restore-project.feature owns the other half.

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

  # ── the same gesture in a personal team, and it means the SAME thing ────────
  # A personal project is a project. The who and the where differ; the rule does
  # not. This is stated explicitly because the spec previously claimed the
  # opposite — see the correction note at the top of this file.

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a personal project folder deletes that project in Penpot
    Given a personal project folder in the user's home
    When the user deletes the folder
    Then the project is deleted in Penpot under the user's own token
    And its designs go to Penpot's trash with it
    And the folder is recoverable from the Nextcloud trash
    # SAME RULE AS A TEAM PROJECT, different credential. A personal project is
    # not a read-only view of Penpot — the whole point of the mirror is parity,
    # so a folder the user tagged into existence is one they can delete the same
    # way. The user's own token performs it, because the service account cannot
    # see their personal team at all (personal-projects.feature).
