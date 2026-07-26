# Project folders as objects in their own right — renaming them, tagging them,
# and the things you deliberately cannot do to them.
#
# WHY THIS IS A SEPARATE FILE FROM rename.feature (saga §6.39). The shape rhymes
# with renaming a file, but nearly every specific differs, and an implementer who
# reuses one listener for both will get it wrong:
#
#                     | rename a FILE            | rename a PROJECT FOLDER
#   NC event          | NodeRenamedEvent (file)  | NodeRenamedEvent (FOLDER)
#   Identified by     | penpot_id                | penpot_project_id
#   RPC               | rename-file {id,name}    | rename-project {id,name}
#   Response          | 200 + SimplifiedFile     | 204, NO BODY
#   Extension         | must strip/re-add .penpot| none — folder names are bare
#   Decided?          | OPEN FORK (saga §6.2)    | LOCKED, propagates (§6.36)
#
# BOTH RPCs ARE CONFIRMED WORKING (saga §6.38, first live exercise):
#   create-project {team-id, name}  → 200 + full project record  (KEBAB-case)
#   rename-project {id, name}       → 204, no body
#
# THE NAME GUARD RUNS BACKWARDS FROM EXPECTATION (saga §6.38). Penpot accepts
# essentially any non-empty string up to 250 chars — confirmed live: upper case,
# lower case, emoji, dots, leading spaces, and even "Has/Slash" all create fine.
# NEXTCLOUD is the stricter side. So:
#   - Nextcloud → Penpot: whatever you can name a folder, Penpot will accept.
#     The only real check is non-empty. The guard here is cheap reassurance.
#   - Penpot → Nextcloud: a project named "Has/Slash" CANNOT be a folder of that
#     name. This is the case that actually breaks, and §6.36's names-always-match
#     invariant genuinely cannot hold for it.
#
# @todo — no lib/Listener/ exists yet.

@todo
Feature: Project folders — renaming, tagging, and what is not allowed
  As a Nextcloud user
  I want a project folder to behave like the Penpot project it represents
  So that the folder tree stays honest without me maintaining it by hand

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── renaming: propagates, unlike file rename ────────────────────────────────

  Scenario: Renaming a project folder renames the Penpot project
    When I rename the "My Stuff" folder to "Acme"
    Then "rename-project" is called with the project's id and the new name
    And the Penpot project is named "Acme"
    And the folder keeps its project id and its project tag
    And its position in the folder tree is unchanged

  Scenario: Renaming a project in Penpot renames the folder on the next pull
    When the project is renamed to "Acme" in Penpot
    And a pull runs
    Then the folder is renamed to "Acme"
    And the folder stays exactly where the user had put it

  Scenario: A project folder rename does not touch the files inside it
    Given mirrored ".penpot" files inside the "My Stuff" folder
    When I rename the folder to "Acme"
    Then no file inside it is renamed
    And no file's "penpot_id" changes
    And "rename-file" is never called

  Scenario: A failed project rename leaves the local rename standing
    When I rename the folder and the Penpot call fails
    Then the folder keeps its new name locally
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # Saga §6.18 rule 3 — a remote failure never destroys local state.

  Scenario: A project rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a project folder
    Then "rename-project" is called using that user's own token

  # ── the name guard ──────────────────────────────────────────────────────────

  Scenario: An empty or whitespace-only folder name is refused
    When I try to rename a project folder to a name that is empty once trimmed
    Then the rename is refused with an explanation
    And Penpot is never contacted
    # The one rule Penpot actually enforces: [:string {:max 250, :min 1}].

  Scenario: A name longer than Penpot allows is refused before it is sent
    When I try to rename a project folder to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  Scenario: A folder tagged as a project must have a usable name first
    Given a plain folder inside the Team Folder whose name is unusable as a project name
    When a user applies the app's project tag to it
    Then the app refuses and explains what is wrong with the name
    And the tag is not left applied
    And no Penpot project is created
    # Refusing and leaving the tag off means the user can rename and re-tag —
    # a two-step the user controls, rather than a half-created state (saga §6.39).
    # NOTE: creating a project from a tagged folder is itself still gated on the
    # open §6.7/§6.15 fork; only the guard's shape is settled here.

  # ── the reverse direction: Penpot names Nextcloud cannot represent ──────────

  @todo
  Scenario: A Penpot project whose name is not a legal folder name is still mirrored
    Given a Penpot project named "Has/Slash"
    When the pull runs
    Then a folder is created with a sanitised version of the name
    And the folder carries the real project id, which stays authoritative
    And the app reports that the folder name could not match exactly
    # Confirmed live (saga §6.38): Penpot accepts "/" in project names; Nextcloud
    # folders cannot contain it. This is the ONE real exception to §6.36's
    # names-always-match rule. The exact sanitisation rule is undecided —
    # saga open question #35.

  # ── copying: deliberately disabled ──────────────────────────────────────────

  Scenario: Copying a project folder is refused
    When I try to copy the "My Stuff" project folder
    Then the copy is refused with an explanation
    And no new Penpot project is created
    And no duplicate project folder is left behind
    # DISABLED DELIBERATELY (saga §6.40), not merely unbuilt. Three reasons:
    #  (1) the copy would carry the same project id, so two folders claim one
    #      project — and every file in the copied tree would too;
    #  (2) Nextcloud auto-increments a copy to "My Stuff (2)", which instantly
    #      violates §6.36's names-always-match rule — and "fixing" it by rename
    #      would rename the ORIGINAL Penpot project;
    #  (3) on this cluster a single folder can also carry n8n and Grafana
    #      mappings, so a folder copy asks three independent apps to agree on
    #      what a duplicate means, with no coordination between them.

  Scenario: Copying an ordinary folder inside a Team Folder is unaffected
    Given a plain folder "Clients" inside the Team Folder with no Penpot metadata
    When I copy it
    Then the copy succeeds normally
    And Penpot is never contacted
    # Only folders carrying a project id are refused. Everything else is ordinary.

  Scenario: Copying a single ".penpot" file is unaffected
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file
    Then the copy succeeds and is stripped of its "penpot_id"
    # The file rule is already coherent — see copy.feature.

  # ── moving: free inside the team, refused outside (saga §6.30) ──────────────

  Scenario: A project folder moves freely inside its team folder
    Given a plain folder "Clients" inside the Team Folder
    When I move the "My Stuff" project folder into "Clients"
    Then the move succeeds and Penpot is never contacted
    And files inside still resolve to the "My Stuff" project

  Scenario: A project folder cannot be moved out of its team folder
    When I try to move the "My Stuff" project folder outside the Team Folder
    Then the move is refused
    And the refusal explains a project cannot leave its team from Nextcloud
    And Penpot is never contacted

  # ── deleting a project folder ───────────────────────────────────────────────

  Scenario: Deleting a project folder in Nextcloud never deletes the Penpot project
    When I delete the "My Stuff" project folder
    Then Penpot is never contacted
    And the Penpot project and its designs are completely unaffected
    And the folder is recoverable from the Nextcloud trash
    When a pull runs
    Then the project folder is recreated, because the project still exists in Penpot
    # Deleting the folder is a local act. The pull restores the mirror, which is
    # the correct outcome — the project never went anywhere.
