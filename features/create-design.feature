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
# folder (move.feature).
#
# NOT YET EXERCISED LIVE. Unlike import-binfile (saga §6.20, now confirmed
# working), `create-file` has never actually been called against a real instance.
# Its param casing, and whether a created design needs any content pushed to be
# valid, are unverified — saga open question #27. Everything below is design.
#
# @todo — no lib/ exists, and the RPC is unexercised.

@todo
Feature: Creating a new Penpot design from Nextcloud
  As a Nextcloud user
  I want to start a new design from the Files app
  So that creating work is as easy as it is for workflows and dashboards

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── where the action appears ─────────────────────────────────────────────────

  Scenario: Creating inside a project folder creates the design in that project
    When I choose "New → Penpot design" inside the "My Stuff" folder
    And I give it a name
    Then a new design is created in the Penpot project "My Stuff"
    And a mirrored ".penpot" file appears in that folder
    And the file carries the new design's "penpot_id"
    And "Open in Penpot" opens the new design

  Scenario: Creating at a team folder's root puts the design in that team's Drafts
    When I choose "New → Penpot design" at the root of the "Northwind" Team Folder
    Then the design is created in the "Northwind" team's "Drafts" project
    And the mirrored file appears where I created it, at the team folder root
    And the app explains the design lives in Penpot's Drafts

  Scenario: Creating in a plain folder under a team also lands in Drafts
    Given a plain folder "scratch" inside the Team Folder, with no Penpot metadata
    When I choose "New → Penpot design" inside "scratch"
    Then the design is created in the "Northwind" team's "Drafts" project
    And the mirrored file appears in "scratch", where I created it
    And no folder named "Drafts" is created anywhere
    # No project-id ancestor, but a team-id ancestor exists — so the team is
    # known and Drafts is unambiguous.

  Scenario: Filing a newly created draft is just a drag
    Given I created a design at the Team Folder's root, so it lives in Drafts
    When I move the file into the "My Stuff" folder
    Then the design is moved from Drafts into the "My Stuff" project in Penpot
    And it keeps the id it was created with
    # The create/file split costs the user nothing: make it anywhere sensible,
    # file it later with an ordinary drag (move.feature, saga §6.35).

  Scenario: The action is not offered where no team can be determined
    Given a folder with no Penpot team or project ancestor
    When I open the New menu there
    Then "New → Penpot design" is not offered
    # Penpot's create-file requires a projectId; there is no rootless design. An
    # action that could only fail is better not shown.

  Scenario: Creating inside a personal project folder uses the user's own token
    Given the user has a personal Penpot token and a personal project folder
    When I choose "New → Penpot design" inside that folder
    Then the design is created in that personal project
    And the creation uses the user's personal token
    # The service account cannot see a personal team at all (personal-projects.feature).

  # ── attribution ──────────────────────────────────────────────────────────────

  Scenario: A created design is attributed to the acting user when possible
    Given the user has a valid personal Penpot token
    When the user creates a new design
    Then the design is created using that user's own token
    And Penpot records that user as its author
    # This matters more for creation than for any other write: authorship is a
    # durable property of a design, not just a history line.

  Scenario: Creation falls back to the service account, and says so
    Given the user has no personal Penpot token configured
    When the user creates a new design in a team project
    Then the design is created using the service-account token
    And the app tells the user the design will be authored by the service account
    And it suggests configuring a personal token for correct authorship

  # ── failure behaviour ────────────────────────────────────────────────────────

  Scenario: A failed creation leaves no orphaned local file
    When I create a new design and the Penpot call fails
    Then no mirrored ".penpot" file is left behind in the folder
    And the failure is reported with the reason
    # The inverse of the rename rule: here there is no local state worth keeping,
    # so a half-created file would only be confusing.

  Scenario: A created design appears exactly once after the next pull
    When I create a new design in the "My Stuff" folder
    And a pull runs
    Then the design appears exactly once in that folder
    And no duplicate is created alongside it
    # The local file is stamped with the real penpot_id at creation, so the pull
    # adopts it rather than treating it as a new remote file.

  # ── mode ─────────────────────────────────────────────────────────────────────

  Scenario: A newly created design follows its mapping's default mode
    Given the "Northwind" mapping has default mode "link"
    When I create a new design in the "My Stuff" folder
    Then the mirrored file is in "link" mode
    And no archive is stored for it until it is promoted to "sync"
