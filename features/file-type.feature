# Notes, decisions and history for this feature: AGENTS.md#file-type

Feature: A mirrored Penpot file is a first-class file type
  As a Nextcloud user
  I want .penpot files to be a real, purpose-built file type
  So that they have the right mimetype + icon and expose their sync state

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

  @in-penpot @occ
  Scenario: Mirrored files get the custom Penpot mimetype, not a generic one
    Given a mirrored design "Typed" in the project "Types"
    Then the DAV content type of "Penpot/Types/Typed.penpot" is "application/vnd.penpot"
    # notes: AGENTS.md#mirrored-files-get-the-custom-penpot-mimetype-not-a-generic-one

    # notes: AGENTS.md#the-row-icon-and-the-menu-glyph-are-separate-files
  @blocked
  Scenario: The row icon and the menu glyph are separate files
    Given a mirrored ".penpot" file
    Then the Files-row icon comes from the app's colour mark, with a fixed fill
    And the "Open in Penpot" menu glyph is themed to the menu's own colour
    And the menu glyph is drawn as filled shapes, never as strokes

  @in-penpot @occ
  Scenario: WebDAV PROPFIND exposes the Penpot metadata in the XML
    Given a mirrored design "Advertised" in the project "Props"
    Then the DAV property "nc:metadata-penpot_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_mode" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_team_id" of "Penpot/Props/Advertised.penpot" is set
    # notes: AGENTS.md#webdav-propfind-exposes-the-penpot-metadata-in-the-xml

  @in-penpot @occ
  Scenario: A file carries the team its design belongs to, but never a project
    Given a mirrored design "Team Stamped" in the project "Stamps"
    Then the DAV property "nc:metadata-penpot_team_id" of "Penpot/Stamps/Team Stamped.penpot" is set
    And the file "Penpot/Stamps/Team Stamped.penpot" stores no copy of its project
    # notes: AGENTS.md#a-file-carries-the-team-its-design-belongs-to-but-never-a-project

  @in-penpot @occ
  Scenario: A mirrored file's mode is visible over DAV
    Given a mirrored design "Moded" in the project "Modes"
    Then the DAV property "nc:metadata-penpot_mode" of "Penpot/Modes/Moded.penpot" is "reference"
    And the file "Penpot/Modes/Moded.penpot" holds no content at all
    # notes: AGENTS.md#a-mirrored-files-mode-is-visible-over-dav

  @in-penpot @occ
  Scenario: A project folder is identifiable by both metadata and a visible tag
    Given a mirrored project "Both Markers"
    Then the folder "Penpot/Both Markers" carries a Penpot project id
    And the folder "Penpot/Both Markers" carries the "penpot" tag
    # Folder metadata works exactly as file metadata does — same Node type, same
    # fileid space (§6.21). The tag is the human half of the same fact (§C6.18).

  @unbuilt
  Scenario: A file moved out of its mapped folder is unmapped, not untracked
    Given a mirrored ".penpot" file that has been moved out of its mapped folder
    Then its "nc:metadata-penpot_id" property is still present
    And the file resolves to no mapping, because no enclosing folder carries Penpot metadata
    And this combination is what marks the file "unmapped" rather than "untracked"

  @todo
  Scenario: The mode is visible and reflects whether content is stored
    Given a mirrored ".penpot" file in "link" mode
    Then its "nc:metadata-penpot_mode" property is "link"
    And the file holds no archive content
    Given a mirrored ".penpot" file in "sync" mode
    Then its "nc:metadata-penpot_mode" property is "sync"
    And the file holds the real ".penpot" archive

  @unbuilt
  Scenario: The metadata is read-only over DAV
    Given a mirrored ".penpot" file
    When a client tries to change "nc:metadata-penpot_id" via PROPPATCH
    Then the change is rejected — the sync engine owns this property

    # notes: AGENTS.md#the-metadata-is-read-only-over-dav
