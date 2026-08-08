# Notes, decisions and history for this feature: ../AGENTS.md#designscreate

Feature: Creating a new Penpot design from Nextcloud
  As a Nextcloud user
  I want to start a new design from the Files app
  So that creating work is as easy as it is for workflows and dashboards

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

    # notes: ../AGENTS.md#create-design-background

  @in-nextcloud @gesture
  Scenario: A new design file in a project folder becomes a design in that project
    Given a mirrored project "Make Here"
    When I create a new design file at "Penpot/Make Here/Fresh Idea.penpot"
    Then the file "Penpot/Make Here/Fresh Idea.penpot" carries a Penpot id
    And Penpot project "Make Here" holds a design named "Fresh Idea"
    # The Penpot name never carries the extension (§6.4).

  @in-nextcloud @gesture
  Scenario Outline: A design created under the team but not under a project is a draft
    Given a mirrored project "Anchor"
    And I create a folder at "Penpot/Inbox"
    When I create a new design file at "<path>"
    Then the file "<path>" carries a Penpot id
    And Penpot project "Anchor" holds no design named "<design>"

    Examples: the team root, and a plain folder at any depth beneath it
      | path                              | design        |
      | Penpot/Loose Idea.penpot          | Loose Idea    |
      | Penpot/Inbox/Filed By Hand.penpot | Filed By Hand |

    # Drafts is a state, not a folder (§6.35) — the file stays where it was made.
    # notes: ../AGENTS.md#a-design-created-under-the-team-but-not-under-a-project-is-a-draft

    # notes: ../AGENTS.md#uploading-a-penpot-archive-does-not-create-an-empty-design
  @in-nextcloud @gesture
  Scenario: Uploading a ".penpot" archive does not create an empty design
    Given a mirrored project "No Invent"
    When I upload a ".penpot" archive at "Penpot/No Invent/Dragged In.penpot"
    Then the file "Penpot/No Invent/Dragged In.penpot" carries no Penpot id
    And Penpot project "No Invent" holds no design named "Dragged In"

  @in-nextcloud @gesture
  Scenario: A ".penpot" file created outside every mapping is an inert file
    Given I create a folder at "No Mapping Here"
    When I create a new design file at "No Mapping Here/Wishful.penpot"
    Then the file "No Mapping Here/Wishful.penpot" carries no Penpot id
    And "No Mapping Here/Wishful.penpot" resolves to no Penpot mapping at all
    # Nothing is created, nothing is reported, and the file stays exactly as the
    # user left it. This is the same state an untracked upload lands in.

    # ── where the action appears ─────────────────────────────────────────────────

    # notes: ../AGENTS.md#filing-a-newly-created-draft-is-just-a-drag

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
    # The service account cannot see a personal team at all.

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

  # notes: ../AGENTS.md#a-newly-created-design-follows-its-mappings-default-mode

  @unbuilt
  Scenario: A design created in the user's own home lands in their personal Drafts
    Given the user has set a valid personal Penpot token
    When the user creates a new design file at the root of their home
    Then the design is created in their personal team's "Drafts" project
    And the file carries the new design's Penpot id
    # notes: ../AGENTS.md#a-design-created-in-the-users-own-home-lands-in-their-personal-drafts

  @unbuilt
  Scenario: A design created in a plain folder in the user's home also lands in personal Drafts
    Given the user has set a valid personal Penpot token
    And a plain folder "Sketchbook" in the user's home with no Penpot metadata
    When the user creates a new design file inside "Sketchbook"
    Then the design is created in their personal team's "Drafts" project
    # Nearest-ancestor, unchanged: no project id on the way up, a team id at the
    # root. Exactly what a plain folder under a mapped Team Folder does.

    # notes: ../AGENTS.md#a-design-created-in-a-plain-folder-in-the-users-home-also-lands-in-personal-drafts

    # ── modes and behaviour are identical to team projects ──────────────────────
