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
#     name. In `nested` mode that's invalid and reported (below); in `keyed` mode
#     it isn't a problem at all, because the "/" is the path (saga §6.53).
#
# @todo — no lib/Listener/ exists yet.

Feature: Project folders — renaming, tagging, and what is not allowed
  As a Nextcloud user
  I want a project folder to behave like the Penpot project it represents
  So that the folder tree stays honest without me maintaining it by hand

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

    # ── renaming: propagates, unlike file rename ────────────────────────────────

  @todo
  Scenario: Renaming a project folder renames the Penpot project
    When I rename the "My Stuff" folder to "Acme"
    Then "rename-project" is called with the project's id and the new name
    And the Penpot project is named "Acme"
    And the folder keeps its project id and its project tag
    And its position in the folder tree is unchanged

  @todo
  Scenario: Renaming a project in Penpot renames the folder on the next pull
    When the project is renamed to "Acme" in Penpot
    And a pull runs
    Then the folder is renamed to "Acme"
    And the folder stays exactly where the user had put it

  @todo
  Scenario: A project folder rename does not touch the files inside it
    Given mirrored ".penpot" files inside the "My Stuff" folder
    When I rename the folder to "Acme"
    Then no file inside it is renamed
    And no file's "penpot_id" changes
    And "rename-file" is never called

  @todo
  Scenario: A failed project rename leaves the local rename standing
    When I rename the folder and the Penpot call fails
    Then the folder keeps its new name locally
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # Saga §6.18 rule 3 — a remote failure never destroys local state.

  @todo
  Scenario: A project rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a project folder
    Then "rename-project" is called using that user's own token

    # ── the name guard ──────────────────────────────────────────────────────────

  @todo
  Scenario: An empty or whitespace-only folder name is refused
    When I try to rename a project folder to a name that is empty once trimmed
    Then the rename is refused with an explanation
    And Penpot is never contacted
    # The one rule Penpot actually enforces: [:string {:max 250, :min 1}].

  @todo
  Scenario: A name longer than Penpot allows is refused before it is sent
    When I try to rename a project folder to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  @todo
  Scenario: In nested mode the app never sends a slash to Penpot
    Given the mapping's folder mode is "nested"
    When a project is created or renamed through this app
    Then the resulting Penpot project name never contains "/"
    # A Nextcloud folder name can't contain "/" anyway, so this is automatic for
    # renames — but it must also hold for the create path (§6.39's guard), which
    # is where a name could otherwise be composed rather than typed.

  @todo
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

    # ── "/" in a project name: INVALID IN NESTED MODE (saga §6.53) ─────────────
    # Everything below is scoped to `nested` mode — the default, where Nextcloud
    # nests freely and a "/" in a project name would mean nothing. In `keyed` mode
    # a "/" is not an error at all: it IS the path. That's the whole point of
    # making folder mode a per-mapping choice (admin-mapping.feature).
    #
    # Checked live against Nextcloud's IFilenameValidator: the ONLY forbidden
    # characters are "\" and "/" (plus ".."/"." as segments, ".htaccess", and the
    # .part/.filepart extensions). Everything else — "a:b", "a*b", "CON",
    # ".hidden" — is a perfectly legal folder name. So this is a two-character
    # problem, not a general sanitisation problem.
    #
    # THE APP REJECTS IT AT THE SOURCE where it can: it owns project creation
    # (§6.39's guard) and project renames (§6.36), so a "/" never enters Penpot
    # through this app in nested mode. The scenarios below cover the only case left
    # — a name typed directly in Penpot's own UI.

  @todo
  Scenario: In nested mode, a project whose name contains a slash is skipped with a clear reason
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder is created for that project
    And no files from that project are mirrored
    And the admin is told the project cannot be mirrored because "/" is not allowed in a folder name
    And the message names the project so it can be renamed in Penpot

  @todo
  Scenario: One unmappable project does not block the rest of the team
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    And other projects with ordinary names in the same team
    When the pull runs
    Then every other project is mirrored normally
    And only the unmappable project is skipped

  @todo
  Scenario: Renaming the project in Penpot fixes it on the next pull
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash" that was skipped
    When it is renamed to "Has Slash" in Penpot
    And the pull runs
    Then a folder named "Has Slash" is created
    And its files are mirrored normally

  @todo
  Scenario: The app never invents a substitute name
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder named "Has-Slash" or "Has Slash" is created for it
    # Sanitising is REJECTED (saga §6.51): "foo/bar" and "foo-bar" would both
    # become "foo-bar", silently collapsing two distinct projects into one folder
    # with no way to tell which is which. That breaks the names-always-match rule
    # invisibly, which is worse than refusing visibly. Inferring a parent folder
    # from the "/" is `keyed` mode — a deliberate per-mapping choice (§6.53), not
    # something to fall back into because one name happened to contain a slash.

    # ── copying: deliberately disabled ──────────────────────────────────────────

  @todo
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

  @todo
  Scenario: Copying an ordinary folder inside a Team Folder is unaffected
    Given a plain folder "Clients" inside the Team Folder with no Penpot metadata
    When I copy it
    Then the copy succeeds normally
    And Penpot is never contacted
    # Only folders carrying a project id are refused. Everything else is ordinary.

  @todo
  Scenario: Copying a single ".penpot" file is unaffected
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file
    Then the copy succeeds and is stripped of its "penpot_id"
    # The file rule is already coherent — see copy.feature.

    # ── moving: free inside the team, refused outside (saga §6.30) ──────────────

  @todo
  Scenario: A project folder moves freely inside its team folder
    Given a plain folder "Clients" inside the Team Folder
    When I move the "My Stuff" project folder into "Clients"
    Then the move succeeds and Penpot is never contacted
    And files inside still resolve to the "My Stuff" project

  @todo
  Scenario: A project folder cannot be moved out of its team folder
    When I try to move the "My Stuff" project folder outside the Team Folder
    Then the move is refused
    And the refusal explains a project cannot leave its team from Nextcloud
    And Penpot is never contacted

    # ── deleting a project folder ───────────────────────────────────────────────

  @todo
  Scenario: Deleting a project folder in Nextcloud never deletes the Penpot project
    When I delete the "My Stuff" project folder
    Then Penpot is never contacted
    And the Penpot project and its designs are completely unaffected
    And the folder is recoverable from the Nextcloud trash
    When a pull runs
    Then the project folder is recreated, because the project still exists in Penpot
    # Deleting the folder is a local act. The pull restores the mirror, which is
    # the correct outcome — the project never went anywhere.

  # ══ A FOLDER BECOMES A PROJECT — BY OPT-IN, NEVER BY ACCIDENT ══════════════
  #
  # NOT BUILT. Specified here because the asymmetry it creates is the whole
  # point, and because the alternative — inferring intent from a folder's
  # existence — is the kind of automatic behaviour this app has refused
  # everywhere else (§6.33 on creation, move.feature on drag-in).
  #
  #     every Penpot project      →  a folder in Nextcloud     (automatic)
  #     SOME Nextcloud folders    →  a project in Penpot       (opt-in only)
  #
  # A folder created inside a mapped folder is an ORDINARY FOLDER. Nothing is
  # sent, nothing is inferred, and it can hold anything the user likes — notes,
  # exports, a subfolder of references. Mapped folders are real folders, and
  # §C6.? already established they must behave like ordinary ones.
  #
  # The opt-in is the `penpot` TAG. Assigning it says "make this a project",
  # which is a deliberate act with a name, exactly as "+ New → Penpot design" is
  # for files. The tag is also how the app MARKS the folders it mirrors, so the
  # two directions share one visible marker: if it carries the tag, it is a
  # Penpot project, whoever made it one.
  #
  # WHY A TAG AND NOT A BUTTON: Nextcloud already has tag assignment as a
  # first-class gesture with an event (`TagAssignedEvent`), the sibling apps use
  # it for exactly this kind of opt-in, and it survives a rename or a move in a
  # way a name convention could not.

  @in-nextcloud @gesture @todo
  Scenario: A new folder inside a mapped folder is just a folder
    Given the first visible team is mapped as a plain folder "Penpot"
    When I create a folder at "Penpot/Just My Notes"
    Then Penpot is never contacted
    And no project named "Just My Notes" is created in Penpot
    And the folder carries no Penpot project id
    # The permissive half, and it has to come first: a mapped folder that
    # silently turned every subfolder into a Penpot project would make the
    # folder unusable for anything else.

  @in-nextcloud @gesture @todo
  Scenario: Tagging a folder "penpot" creates the project in Penpot
    Given a plain folder "Client Work" inside a mapped folder
    When I assign the "penpot" tag to it
    Then "create-project" is called for that team
    And the folder is stamped with the new project's id
    And designs already inside it are filed into that project
    # The last line is the interesting one: a folder someone has been filling
    # with designs becomes a project WITH its contents, which is the reason to
    # opt in late rather than having to decide up front.

  @in-nextcloud @gesture @todo
  Scenario: Removing the "penpot" tag does not delete the project
    Given a mirrored project folder carrying the "penpot" tag
    When I remove the tag
    Then Penpot is never contacted
    And the project still exists in Penpot
    # Untagging is unmapping, not deleting — the same rule as moving a design
    # out of a mapping (§6.23). Destroying a project because someone removed a
    # label would be the worst kind of surprise.

  @in-penpot @todo
  Scenario: A project created in Penpot arrives as a tagged folder
    Given a mirrored team
    And a new project "Bubbles" is created in Penpot
    When the team is mirrored again
    Then a folder "Bubbles" appears in the mapped folder
    And it carries the new project's Penpot id
    And it carries the "penpot" tag
    # THE TAG IS THE SHARED MARKER. A user cannot tell — and should not have to —
    # whether a project folder started life in Penpot or was opted in from
    # Nextcloud. Both carry the tag; both are projects.
    #
    # The folder itself is already built and live (see reconcile.feature); only
    # the TAG is missing, which is what makes this the smallest slice of the
    # feature above.
