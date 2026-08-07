# Notes, decisions and history for this feature: AGENTS.md#view-design

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

  # THIS FILE REPLACED "A mirrored Penpot file is a first-class file type", which
  # described a CONSTRUCT — a mimetype, an icon, a property set — rather than
  # anything a person does. Each of those is the end state of something else:
  #
  #   the mimetype being registered   is what ENABLING THE APP leaves behind
  #                                   -> lifecycle.feature
  #   the metadata on a file          is what THE PULL leaves behind
  #                                   -> asserted by sync-now.feature, and shown here
  #   the menu glyph                  belongs to the action that draws it
  #                                   -> open-with.feature
  #
  # What is left is the only part anyone actually performs: looking at the thing.
  # notes: AGENTS.md#view-design

  @in-penpot @occ
  Scenario: A mapped folder shows its designs as designs
    Given a mirrored design "Typed" in the project "Types"
    Then the DAV content type of "Penpot/Types/Typed.penpot" is "application/vnd.penpot"
    # ONE SCENARIO, DELIBERATELY. Behat cannot read rendered pixels, so the icon is
    # proven the only way it can be: the file carries the app's own mimetype rather
    # than the application/zip a ".penpot" archive would otherwise be sniffed as,
    # and Nextcloud maps that mimetype to the app's glyph. Elaborating past that
    # would be testing Nextcloud's icon renderer.
    # notes: AGENTS.md#a-mapped-folder-shows-its-designs-as-designs

  @in-penpot @occ
  Scenario: Viewing the DAV properties on a file shows Penpot specific details
    Given a mirrored design "Advertised" in the project "Props"
    Then the DAV property "nc:metadata-penpot_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_team_id" of "Penpot/Props/Advertised.penpot" is set
    And the DAV property "nc:metadata-penpot_mode" of "Penpot/Props/Advertised.penpot" is "reference"
    And the file "Penpot/Props/Advertised.penpot" holds no content at all
    # THE THREE KEYS A MIRROR ARRIVES WITH, and the body that goes with them. A
    # pull mints every mirror in the mapping's default mode, which is "link", so
    # what a fresh mirror publishes is exactly this: an id, its team, the mode, and
    # nothing in the file. The sync-mode axis — what promotion changes about that
    # body — is sync-mode.feature's and set-mode.feature's.
    #
    # "link" stores as "reference". The literal string "link" is is_callable(),
    # which crashes core's PROPFIND — the only place in this app where a wire value
    # differs from the name of the thing it carries.
    # notes: AGENTS.md#viewing-the-dav-properties-on-a-file-shows-penpot-specific-details

  @in-penpot @occ
  Scenario: A file carries the team its design belongs to, but never a project
    Given a mirrored design "Team Stamped" in the project "Stamps"
    Then the DAV property "nc:metadata-penpot_team_id" of "Penpot/Stamps/Team Stamped.penpot" is set
    And the file "Penpot/Stamps/Team Stamped.penpot" stores no copy of its project
    # AN ABSENCE THAT IS LOAD-BEARING, which is why it is a scenario of its own
    # rather than a missing row above. A file's project is the nearest ancestor
    # folder carrying a project id (mapping-membership.feature), so a copy stamped
    # on the file would be a second answer free to drift from the first — and the
    # first is the one every gesture resolves against. The team is different: it is
    # what the deep link needs and no ancestor walk can supply it once a file
    # leaves its folder.
    # notes: AGENTS.md#a-file-carries-the-team-its-design-belongs-to-but-never-a-project

  @unbuilt
  Scenario: What the app manages, only the app changes
    Given a mirrored ".penpot" file
    When a client tries to change "nc:metadata-penpot_id" via PROPPATCH
    Then the change is rejected — the sync engine owns this property
    And the property still names the design it named before
    # A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can
    # attempt this. The identity of a mirror is the app's to write; a client that
    # could edit it could silently re-point a file at a different design.
    # notes: AGENTS.md#what-the-app-manages-only-the-app-changes

  # notes: AGENTS.md#the-row-icon-is-the-app-s-colour-mark
  @blocked
  Scenario: The row icon is the app's colour mark
    Given a mirrored ".penpot" file
    Then the Files-row icon comes from the app's colour mark, with a fixed fill
