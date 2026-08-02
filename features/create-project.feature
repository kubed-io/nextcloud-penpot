# HOW A FOLDER BECOMES A PENPOT PROJECT — the creating half, from either side.
#
# ## WHAT THIS FILE OWNS
#
# A project's IDENTITY: how a folder acquires one, and the marker that says it
# has. Every VERB a project can be on the receiving end of lives with the other
# instances of that verb, so "what happens when I rename a project?" and "what
# happens when I rename a design?" sit side by side rather than in two files:
#
#   renaming a project   → rename-project.feature
#   copying one          → copy-project.feature   (refused — and why)
#   moving one           → move-project.feature
#   deleting one         → delete-project.feature
#   restoring one        → restore-project.feature
#
# This file used to own all of those, which was the same mistake gestures.feature
# made in the other direction — organising by the KIND OF THING acted on instead
# of by the BEHAVIOUR — and it cost the same thing: "what happens when I rename a
# project folder?" had two answers in two files, and the two had already drifted.
#
# It was called project-folder.feature until the design/project split, which is
# when the last of those verbs moved out. The name followed the contents: what is
# left is creation, so it sits beside create-design.feature where the two opt-in
# models can be read together.
#
# A PERSONAL project is created the same way, by the same tag, in the user's own
# home — personal-projects.feature owns only the who and the where.
#
# ## THE ASYMMETRY (saga §C6.18)
#
#     every Penpot project      →  a folder in Nextcloud     (automatic)
#     SOME Nextcloud folders    →  a project in Penpot       (opt-in only)
#
# A folder created inside a mapped folder is an ORDINARY FOLDER. Nothing is
# sent, nothing is inferred, and it can hold anything the user likes — notes,
# exports, a subfolder of references. Mapped folders are real folders, and they
# must behave like ordinary ones. Inferring intent from a folder's existence is
# the kind of automatic behaviour this app has refused everywhere else (§6.33 on
# creation, move-design.feature on drag-in).
#
# The opt-in is the `penpot` TAG. Assigning it says "make this a project", which
# is a deliberate act with a name, exactly as "+ New → Penpot design" is for
# files. The tag is ALSO how the app marks the folders it mirrors, so the two
# directions share one visible marker: if it carries the tag, it is a Penpot
# project, whoever made it one.
#
# WHY A TAG AND NOT A BUTTON: Nextcloud already has tag assignment as a
# first-class gesture with an event (`TagAssignedEvent`), the sibling apps use it
# for exactly this kind of opt-in, and it survives a rename or a move in a way a
# name convention could not. It needs Nextcloud 32 — see appinfo/info.xml.
#
# ## A NOTE ON THE BACKGROUND
#
# It used to provision a Team Folder and mirror a project called "My Stuff" into
# it, and none of those steps had ever existed — harmless while the whole file
# was @todo, and an instant `--strict` failure the moment one scenario went live.
# It is now the same Background the other live behaviour files use: a PLAIN
# mapped folder, because Team Folder provisioning is not covered by this suite
# (features/README.md), plus the mirror every scenario here needs.

Feature: A folder as a Penpot project — the opt-in, and the tag that marks it
  As a Nextcloud user
  I want to choose which of my folders are Penpot projects
  So that a mapped folder stays usable for ordinary things

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"
    And the team has been mirrored into Nextcloud

    # ── the permissive half, and it has to come first ───────────────────────────

  @in-nextcloud @gesture
  Scenario: A new folder inside a mapped folder is just a folder
    When I create a folder at "Penpot/Just My Notes"
    Then the folder "Penpot/Just My Notes" carries no Penpot project id
    And the folder "Penpot/Just My Notes" does not carry the "penpot" tag
    And Penpot holds no project named "Just My Notes"
    # A mapped folder that silently turned every subfolder into a Penpot project
    # would be unusable for anything else.

    # ── opting in ───────────────────────────────────────────────────────────────

  @in-nextcloud @occ
  Scenario: Tagging a folder "penpot" creates the project in Penpot
    Given I create a folder at "Penpot/Client Work"
    When I assign the "penpot" tag to "Penpot/Client Work"
    Then Penpot holds a project named "Client Work"
    And the folder "Penpot/Client Work" carries a Penpot project id

  @in-nextcloud @occ
  Scenario: A folder opted in late brings the designs already inside it
    Given I create a folder at "Penpot/Late Opt In"
    And I create a new design file at "Penpot/Late Opt In/Moodboard.penpot"
    When I assign the "penpot" tag to "Penpot/Late Opt In"
    Then Penpot project "Late Opt In" holds a design named "Moodboard"
    # THE REASON TO ALLOW OPTING IN LATE. A folder someone has been filling with
    # designs becomes a project WITH its contents, rather than forcing the
    # decision up front. Before the tag those designs were in the team's Drafts
    # (§6.35) — a folder inside a mapping is still inside the mapping — and one
    # `move-files` re-files the lot without exporting or re-id'ing anything.

  @in-nextcloud @occ
  Scenario: Tagging a folder that is already a project changes nothing
    Given a mirrored project "Already Mine"
    When I assign the "penpot" tag to "Penpot/Already Mine"
    Then the folder "Penpot/Already Mine" carries a Penpot project id
    And Penpot holds a project named "Already Mine"
    # The common path, because the pull tags every folder it mirrors. A second
    # create here would leave two folders claiming one project — the exact
    # ambiguity copy-design.feature refuses a folder copy to avoid.

  @in-nextcloud @occ @todo
  Scenario: A folder tagged as a project must have a usable name first
    Given a plain folder inside the mapped folder whose name is unusable as a project name
    When I assign the "penpot" tag to it
    Then the app refuses and explains what is wrong with the name
    And the tag is not left applied
    And no Penpot project is created
    # BUILT (§C6.18) — ProjectFolderService checks the name locally and takes the
    # tag back off, so the user can rename and re-tag: a two-step they control,
    # rather than a half-created state they have to discover (§6.39). Still @todo
    # only for want of a step that makes a folder whose name Nextcloud accepts
    # and Penpot would not — NC allows 255 characters and Penpot 250, so the
    # window exists but has to be constructed deliberately.

  @in-nextcloud @occ @todo
  Scenario: Tagging a folder outside every mapping does nothing at all
    Given a plain folder "Holiday Photos" outside every mapped folder
    When I assign the "penpot" tag to it
    Then Penpot is never contacted
    And the tag is left where the user put it
    # Tags are instance-wide, so this is not an error to report — no team could
    # be resolved for that folder even in principle. Stripping a user's own tag
    # off a folder this app has no business touching would be a worse surprise
    # than an inert label.

    # ── opting out does not destroy anything ────────────────────────────────────

  @in-nextcloud @occ
  Scenario: Removing the "penpot" tag does not delete the project
    Given I create a folder at "Penpot/Keep Me"
    And the folder "Penpot/Keep Me" has been tagged "penpot"
    When I remove the "penpot" tag from "Penpot/Keep Me"
    Then Penpot holds a project named "Keep Me"
    And the folder "Penpot/Keep Me" carries a Penpot project id
    # Untagging is unmapping, not deleting — the same rule as moving a design out
    # of a mapping (§6.23), and the same rule as deleting a project folder
    # (delete-project.feature). Destroying a project because someone removed a label
    # would be the worst kind of surprise.
    #
    # The app does not subscribe to `TagUnassignedEvent` at all, so "Penpot is
    # never contacted" is true by construction rather than by a branch someone
    # could later add an `else` to.

    # ── the tag as the shared marker ────────────────────────────────────────────

  @in-penpot
  Scenario: A project created in Penpot arrives as a tagged folder
    Given a Penpot project named "Bubbles" exists in that team
    When the team is mirrored again
    Then the folder "Penpot/Bubbles" carries a Penpot project id
    And the folder "Penpot/Bubbles" carries the "penpot" tag
    # A user cannot tell — and should not have to — whether a project folder
    # started life in Penpot or was opted in from Nextcloud. Both carry the tag;
    # both are projects.

  @in-penpot
  Scenario: A project folder that lost its tag gets it back on the next pull
    Given a mirrored project "Retagged"
    And I remove the "penpot" tag from "Penpot/Retagged"
    When the team is mirrored again
    Then the folder "Penpot/Retagged" carries the "penpot" tag
    # The tag decorates; `penpot_project_id` decides. Because the id never went
    # anywhere, the pull re-stamps the badge on every run — which is also why the
    # tag being missing is never a state the app has to repair specially.
