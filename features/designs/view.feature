# Notes, decisions and history for this feature: ../AGENTS.md#designsview

Feature: Looking at a mirrored design
  As someone with designs mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as designs rather than as anonymous archives

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

  # notes: ../AGENTS.md#designsview

  @in-penpot @occ
  Scenario: A mapped folder shows its designs as designs
    Given a mirrored design "Typed" in the project "Types"
    Then the DAV content type of "Penpot/Types/Typed.penpot" is "application/vnd.penpot"
    # notes: ../AGENTS.md#a-mapped-folder-shows-its-designs-as-designs

  @in-penpot @occ
  Scenario: Viewing the DAV properties on a file shows Penpot specific details
    Given a mirrored design "Advertised" in the project "Props"
    Then the DAV property "nc:metadata-penpot_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_team_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_mode" of "Penpot/Props/Advertised.penpot" is "reference"
    And the file "Penpot/Props/Advertised.penpot" holds no content at all
    # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-penpot-specific-details

  @in-penpot @occ
  Scenario: A file carries the team its design belongs to, but never a project
    Given a mirrored design "Team Stamped" in the project "Stamps"
    Then the DAV property "nc:metadata-penpot_team_id" of "Penpot/Stamps/Team Stamped.penpot" is set
    And the file "Penpot/Stamps/Team Stamped.penpot" stores no copy of its project
    # notes: ../AGENTS.md#a-file-carries-the-team-its-design-belongs-to-but-never-a-project

  @unbuilt
  Scenario: What the app manages, only the app changes
    Given a mirrored ".penpot" file
    When a client tries to change "nc:metadata-penpot_id" via PROPPATCH
    Then the change is rejected — the sync engine owns this property
    And the property still names the design it named before
    # notes: ../AGENTS.md#what-the-app-manages-only-the-app-changes

  # @blocked — no browser. An icon is pixels, and the harness is occ + DAV.
  # notes: ../AGENTS.md#the-row-icon-is-the-apps-colour-mark
  @blocked
  Scenario: The row icon is the app's colour mark
    Given a mirrored ".penpot" file
    Then the Files-row icon comes from the app's colour mark, with a fixed fill
