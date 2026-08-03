# Notes, decisions and history for this feature: AGENTS.md#rename-project

Feature: Renaming a Penpot project
  As a Nextcloud user
  I want renaming a project folder to rename the project in Penpot, and vice versa
  So that the folder tree and the Penpot team always read the same
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  # notes: AGENTS.md#rename-project-background
  @in-nextcloud @gesture
  Scenario: Renaming a project folder renames the project in Penpot
    Given a mirrored design "Inside" in the project "Old Project Name"
    When I rename "Penpot/Old Project Name" to "New Project Name"
    Then Penpot project "New Project Name" holds a design named "Inside"
    And the folder "Penpot/New Project Name" carries a Penpot project id

  @in-nextcloud @gesture
  Scenario: Renaming a project folder does not touch the designs inside it
    Given a mirrored design "Untouched Design" in the project "Renamed Around It"
    When I rename "Penpot/Renamed Around It" to "Renamed Around It v2"
    Then Penpot project "Renamed Around It v2" holds a design named "Untouched Design"
    And the file "Penpot/Renamed Around It v2/Untouched Design.penpot" carries a Penpot id
    # notes: AGENTS.md#renaming-a-project-folder-does-not-touch-the-designs-inside-it

  @in-nextcloud @gesture @todo
  Scenario: A failed project rename leaves the local rename standing
    Given a mirrored project "My Stuff"
    When I rename the project folder and the Penpot call fails
    Then the folder keeps its new name locally
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # notes: AGENTS.md#a-failed-project-rename-leaves-the-local-rename-standing

  @in-nextcloud @gesture @blocked
  Scenario: A project rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a project folder
    Then "rename-project" is called using that user's own token
    # Needs a logged-in session the occ+DAV harness does not have.

    # ── Penpot → Nextcloud: confirmed, this is how renames normally happen ───────

  @in-penpot @todo
  Scenario: Renaming a project in Penpot renames the folder on the next pull
    Given a mirrored project "My Stuff"
    When the project is renamed to "Acme" in Penpot
    And the team is mirrored again
    Then the folder is renamed to "Acme"
    And the folder stays exactly where the user had put it
    # Nextcloud is authoritative for LAYOUT (§6.29), so the pull renames in place
    # and never drags the folder back to a canonical path.

  @todo
  Scenario: An empty or whitespace-only folder name is refused
    When I try to rename a project folder to a name that is empty once trimmed
    Then the rename is refused with an explanation
    And Penpot is never contacted
    # The one rule Penpot actually enforces: [:string {:max 250, :min 1}].

  @todo
  Scenario: A folder name longer than Penpot allows is refused before it is sent
    When I try to rename a project folder to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  @todo
  Scenario: In nested mode the app never sends a slash to Penpot
    Given the mapping's folder mode is "nested"
    When a project is created or renamed through this app
    Then the resulting Penpot project name never contains "/"
    # notes: AGENTS.md#in-nested-mode-the-app-never-sends-a-slash-to-penpot

  @in-penpot @todo
  Scenario: In nested mode, a project whose name contains a slash is skipped with a clear reason
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder is created for that project
    And no files from that project are mirrored
    And the admin is told the project cannot be mirrored because "/" is not allowed in a folder name
    And the message names the project so it can be renamed in Penpot

  @in-penpot @todo
  Scenario: One unmappable project does not block the rest of the team
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    And other projects with ordinary names in the same team
    When the pull runs
    Then every other project is mirrored normally
    And only the unmappable project is skipped

  @in-penpot @todo
  Scenario: Renaming the project in Penpot fixes it on the next pull
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash" that was skipped
    When it is renamed to "Has Slash" in Penpot
    And the pull runs
    Then a folder named "Has Slash" is created
    And its files are mirrored normally

  @in-penpot @todo
  Scenario: The app never invents a substitute name
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder named "Has-Slash" or "Has Slash" is created for it
    # notes: AGENTS.md#the-app-never-invents-a-substitute-name

    # ── the invariant, true under either branch ─────────────────────────────────
