# Notes, decisions and history for this feature: AGENTS.md#set-mode

Feature: Storing and discarding a mirrored design's archive
  As an operator who has mapped a Penpot team
  I want `occ penpot_sync:set-mode` to decide which designs are really backed up
  So that important work is preserved without paying to store everything

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @admin @occ
  Scenario: A whole team of link files costs no exports at all
    When the team is mirrored again
    Then the pull succeeds
    And the pull exported 0 archives

  @admin @occ
  Scenario: Promoting a mirrored design fetches a real ZIP from Penpot
    Given a Penpot project named "Archive Me" exists in that team
    And a Penpot file named "Cover" exists in the project "Archive Me"
    When the team is mirrored again
    And "Penpot/Archive Me/Cover.penpot" is a "sync" design
    Then the mode change succeeds
    And the file "Penpot/Archive Me/Cover.penpot" is in "sync" mode
    And the file "Penpot/Archive Me/Cover.penpot" holds a real ".penpot" archive
    And the file "Penpot/Archive Me/Cover.penpot" still carries its Penpot id
    # An export never writes to Penpot and never re-stamps the id — promotion is
    # purely additive, which is what makes it safe to retry.

  @admin @occ
  Scenario: A promoted file is not re-exported by the next pull
    Given a mirrored design "Logo" in the project "Stable"
    And "Penpot/Stable/Logo.penpot" is a "sync" design
    When the team is mirrored again
    Then the pull succeeds
    And the pull exported 0 archives
    And the file "Penpot/Stable/Logo.penpot" holds a real ".penpot" archive
    # Mode is stored PER FILE, and an unchanged revision means an unchanged
    # archive — so staying in sync mode is free until the design actually moves.

  @admin @occ
  Scenario: Demoting throws the archive away and never contacts Penpot
    Given a Penpot project named "Demote Me" exists in that team
    And a Penpot file named "Sketch" exists in the project "Demote Me"
    When the team is mirrored again
    And "Penpot/Demote Me/Sketch.penpot" is a "sync" design
    And "Penpot/Demote Me/Sketch.penpot" is a "link" design
    Then the mode change succeeds
    And the file "Penpot/Demote Me/Sketch.penpot" is in "link" mode
    And the file "Penpot/Demote Me/Sketch.penpot" holds no content at all
    And the file "Penpot/Demote Me/Sketch.penpot" still carries its Penpot id
    # The design in Penpot is completely unaffected: demotion deletes a LOCAL
    # backup and nothing else.

  @admin @occ
  Scenario: A folder has no mode to set
    When the team is mirrored again
    And the admin sets the mode of "Penpot" to "sync"
    Then the mode change is refused
    And the refusal mentions "Modes are per-file"
