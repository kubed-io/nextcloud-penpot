# "New → Penpot design" in the Files app — the same New-menu affordance both
# sibling apps offer for workflows and dashboards.
#
# THIS IS A DELIBERATE CARVE-OUT OF §6.1, RATIFIED IN PRINCIPLE (saga §6.33).
# §6.1 locked Nextcloud as read-only for design CONTENT; §6.23 already carved out
# restore. Creation is the second carve-out and Command asked for it explicitly.
# Neither carve-out weakens the core promise: this app never modifies or deletes
# an existing Penpot design as a side effect of a file-manager gesture. Creating
# is a deliberate, explicit user action from a menu.
#
# THE SCOPING RULE, AND WHY IT EXISTS (saga §6.33): the action is only offered
# where the target project is UNAMBIGUOUS. Command's framing — "it does not seem
# to make sense to do this outside of a project folder or team folder" — is
# exactly right, because Penpot requires a projectId on create-file; there is no
# team-level or rootless design. So:
#
#   inside a project folder      → created in THAT project
#   inside a team folder         → created in that team's DRAFTS project
#   in a plain folder under a team → same: that team's DRAFTS
#   nowhere with a team ancestor → THE ACTION IS NOT OFFERED
#
# WHY DRAFTS RATHER THAN AN ERROR: it's Penpot's own answer to the same question.
# Every team auto-provisions a "Drafts" project with isDefault: true (saga §6.6,
# confirmed on every team live), and it's exactly where Penpot's own UI puts a
# design created outside any project. We match their convention rather than
# inventing one.
#
# DRAFTS IS A STATE, NOT A FOLDER (saga §6.35). No "Drafts" folder is ever
# created. A design created at a team folder's root — or in any plain folder
# under it — STAYS VISUALLY WHERE THE USER MADE IT in Nextcloud, while living in
# that team's Drafts project in Penpot. This is where Nextcloud is more expressive
# than Penpot: one flat Drafts bucket on their side can be any arrangement of
# ordinary folders on ours. Filing the design later is just a drag into a project
# folder (move-design.feature).
#
# NOW EXERCISED LIVE (saga §C6.11). `create-file` was called against a running
# instance and its schema read back:
#
#   {name: string≤250 (required), project-id: uuid (required),
#    id?: uuid, is-shared?: bool, features?}
#
# KEBAB `project-id`, and `name` is REQUIRED — a design cannot be created
# nameless. There is also an optional `id`: a caller may supply the design's uuid
# itself. This app deliberately does not, because letting Nextcloud mint Penpot
# identities would make the id something two systems can disagree about; Penpot
# assigns it and we record what it says. Open question #27 is closed.
#
# NOTHING IS OPENED AFTER CREATING (researched, not assumed). Nextcloud's own
# New-menu API does nothing on its own — its maintainer's words: "Any Entry is
# responsible for nothing but themselves... you need to call the creation
# yourself." The sanctioned pattern is prompt → put the file → emit
# `files:node:created`, and both sibling apps do exactly that. Text and Office
# auto-open because they ARE the editor; we are not, and `window.open` after an
# await chain is unreliable anyway — popup blockers reject it inconsistently. So
# the file appears, and the user clicks it.
#
# @todo — no lib/ exists yet.

Feature: Creating a new Penpot design from Nextcloud
  As a Nextcloud user
  I want to start a new design from the Files app
  So that creating work is as easy as it is for workflows and dashboards

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped to the folder "Penpot"

    # ══ CREATED IN NEXTCLOUD ═══════════════════════════════════════════════════
    #
    # "+ New → Penpot design" writes an EMPTY file and stops; the server notices it
    # and creates the design. Asserted in Penpot, because a file appearing in
    # Nextcloud is exactly what a broken create looks like.

  @in-nextcloud @gesture
  Scenario: A new design file in a project folder becomes a design in that project
    Given a mirrored project "Make Here"
    When I create a new design file at "Penpot/Make Here/Fresh Idea.penpot"
    Then the file "Penpot/Make Here/Fresh Idea.penpot" carries a Penpot id
    And Penpot project "Make Here" holds a design named "Fresh Idea"
    # The Penpot name never carries the extension (§6.4).

  @in-nextcloud @gesture
  Scenario: A new design file at the team root is created in Drafts
    Given a mirrored project "Anchor"
    When I create a new design file at "Penpot/Loose Idea.penpot"
    Then the file "Penpot/Loose Idea.penpot" carries a Penpot id
    And Penpot project "Anchor" holds no design named "Loose Idea"
    # Drafts is a state, not a folder (§6.35) — the file stays where it was made.

    # THE GUARD NEITHER SIBLING NEEDS. An uploaded .penpot already holds a whole
    # design; creating an empty one for it would set the file and Penpot against
    # each other, and the next sync pull would overwrite the real archive with the
    # empty export.
  @in-nextcloud @gesture
  Scenario: Uploading a ".penpot" archive does not create an empty design
    Given a mirrored project "No Invent"
    When I upload a ".penpot" archive at "Penpot/No Invent/Dragged In.penpot"
    Then the file "Penpot/No Invent/Dragged In.penpot" carries no Penpot id
    And Penpot project "No Invent" holds no design named "Dragged In"

    # ══ THE RULE: NEXTCLOUD CANNOT MAKE A DESIGN, IT CAN ONLY ASK FOR ONE ══════
    #
    # A `.penpot` is a Penpot artefact. Nextcloud has no way to produce one — it
    # can write an empty file with that extension, and that is all. So "+ New →
    # Penpot design" is not a local create at all: it is a REQUEST, and the
    # request needs somewhere to go.
    #
    # Penpot has no rootless design (§C6.11: `create-file` requires a project),
    # so "somewhere" means a resolvable Penpot home:
    #
    #     inside a project folder    →  that project
    #     under a mapped team        →  that team's Drafts        (§6.35)
    #     at the user's own root     →  their PERSONAL team's Drafts
    #                                   (personal-projects.feature — needs a
    #                                    personal token, and is not built)
    #     anywhere else              →  NOTHING HAPPENS
    #
    # The last line is the rule, and it is a refusal to guess rather than an
    # error. A `.penpot` outside every mapping is an ordinary, inert file: the
    # user made a file, it is theirs, and it is simply not a design. Inventing a
    # team to file it into would be worse than doing nothing, and erroring would
    # make a mapped folder unusable for the ordinary things folders are for.

  @in-nextcloud @gesture
  Scenario: A ".penpot" file created outside every mapping is an inert file
    Given I create a folder at "No Mapping Here"
    When I create a new design file at "No Mapping Here/Wishful.penpot"
    Then the file "No Mapping Here/Wishful.penpot" carries no Penpot id
    And "No Mapping Here/Wishful.penpot" resolves to no Penpot mapping at all
    # Nothing is created, nothing is reported, and the file stays exactly as the
    # user left it. This is the same state an untracked upload lands in.

    # ── where the action appears ─────────────────────────────────────────────────

    # THE THREE PLACEMENT CASES ARE LIVE ABOVE, driven over WebDAV — which is
    # what the "+ New" menu actually does: write an empty file and stop. They
    # used to be repeated here in menu vocabulary ("I choose New → Penpot design
    # inside the My Stuff folder"), which described the same three outcomes a
    # second time and had already drifted from them. Only the MENU SURFACE is
    # this section's own, and that is what is left.

  @todo
  Scenario: Filing a newly created draft is just a drag
    Given I created a design at the Team Folder's root, so it lives in Drafts
    When I move the file into the "My Stuff" folder
    Then the design is moved from Drafts into the "My Stuff" project in Penpot
    And it keeps the id it was created with
    # The create/file split costs the user nothing: make it anywhere sensible,
    # file it later with an ordinary drag (move-design.feature, saga §6.35).

  @blocked
  Scenario: The action is not offered where no team can be determined
    Given a folder with no Penpot team or project ancestor
    When I open the New menu there
    Then "New → Penpot design" is not offered
    # Penpot's create-file requires a projectId; there is no rootless design. An
    # action that could only fail is better not shown.

  @blocked
  Scenario: Creating inside a personal project folder uses the user's own token
    Given the user has a personal Penpot token and a personal project folder
    When I choose "New → Penpot design" inside that folder
    Then the design is created in that personal project
    And the creation uses the user's personal token
    # The service account cannot see a personal team at all (personal-projects.feature).

    # ── attribution ──────────────────────────────────────────────────────────────

  @blocked
  Scenario: A created design is attributed to the acting user when possible
    Given the user has a valid personal Penpot token
    When the user creates a new design
    Then the design is created using that user's own token
    And Penpot records that user as its author
    # This matters more for creation than for any other write: authorship is a
    # durable property of a design, not just a history line.

  @blocked
  Scenario: Creation falls back to the service account, and says so
    Given the user has no personal Penpot token configured
    When the user creates a new design in a team project
    Then the design is created using the service-account token
    And the app tells the user the design will be authored by the service account
    And it suggests configuring a personal token for correct authorship

    # ── failure behaviour ────────────────────────────────────────────────────────

  @todo
  Scenario: A failed creation leaves no orphaned local file
    When I create a new design and the Penpot call fails
    Then no mirrored ".penpot" file is left behind in the folder
    And the failure is reported with the reason
    # The inverse of the rename rule: here there is no local state worth keeping,
    # so a half-created file would only be confusing.

  @todo
  Scenario: A created design appears exactly once after the next pull
    When I create a new design in the "My Stuff" folder
    And a pull runs
    Then the design appears exactly once in that folder
    And no duplicate is created alongside it
    # The local file is stamped with the real penpot_id at creation, so the pull
    # adopts it rather than treating it as a new remote file.

    # ── mode ─────────────────────────────────────────────────────────────────────

  @todo
  Scenario: A newly created design follows its mapping's default mode
    Given the "Northwind" mapping has default mode "link"
    When I create a new design in the "My Stuff" folder
    Then the mirrored file is in "link" mode
    And no archive is stored for it until it is promoted to "sync"

  # ── creating in a personal team ─────────────────────────────────────────────
  # Same behaviour, different destination: the user's own Drafts rather than the
  # team's. personal-projects.feature owns why that destination differs.

  @unbuilt
  Scenario: A design created in the user's own home lands in their personal Drafts
    Given the user has set a valid personal Penpot token
    When the user creates a new design file at the root of their home
    Then the design is created in their personal team's "Drafts" project
    And the file carries the new design's Penpot id
    # THE WHOLE POINT OF THE IMPLICIT MAPPING. Without a team ancestor this file
    # resolves to nothing and stays inert (create-design.feature's rule). With
    # one it is the ordinary team-root case (§6.35) — same rule, new root.

  @unbuilt
  Scenario: A design created in a plain folder in the user's home also lands in personal Drafts
    Given the user has set a valid personal Penpot token
    And a plain folder "Sketchbook" in the user's home with no Penpot metadata
    When the user creates a new design file inside "Sketchbook"
    Then the design is created in their personal team's "Drafts" project
    # Nearest-ancestor, unchanged: no project id on the way up, a team id at the
    # root. Exactly what a plain folder under a mapped Team Folder does.

    # ── crossing the boundary: personal ⇄ a shared team ─────────────────────────
    # A user's home and a mapped Team Folder are two mappings to two different
    # Penpot teams, so a drag between them is a REAL cross-team move — and a move
    # is move-design.feature's, whatever the two ends happen to be. The scenarios live
    # there, next to every other move, rather than here where a reader comparing
    # "what happens when I drag a design" would have to find them.
    #
    # This file owns only the fact that makes them possible: the home root has a
    # team ancestor because a token was set.

    # ── modes and behaviour are identical to team projects ──────────────────────
