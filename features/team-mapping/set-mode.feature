# Notes, decisions and history for this feature: ../AGENTS.md#team-mappingset-mode

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
    Given a mirrored design "Cover" in the project "Archive Me"
    When the admin promotes "Penpot/Archive Me/Cover.penpot" to "sync" mode
    Then the mode change succeeds
    And "Penpot/Archive Me/Cover.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_revision | set             |
      | penpot_mode     | "sync"          |
      | content         | an archive      |

    # notes: ../AGENTS.md#promoting-a-mirrored-design-fetches-a-real-zip-from-penpot

  @admin @occ
  Scenario: A promoted file is not re-exported by the next pull
    Given a mirrored design "Logo" in the project "Stable"
    And "Penpot/Stable/Logo.penpot" is a "sync" design
    When the team is mirrored again
    Then the pull succeeds
    And the pull exported 0 archives
    And "Penpot/Stable/Logo.penpot" holds:
      | penpot_id   | the design's id |
      | penpot_mode | "sync"          |
      | content     | an archive      |
    # notes: ../AGENTS.md#a-promoted-file-is-not-re-exported-by-the-next-pull

  @admin @occ
  Scenario: Demoting throws the archive away and never contacts Penpot
    Given a mirrored design "Sketch" in the project "Demote Me"
    And "Penpot/Demote Me/Sketch.penpot" is a "sync" design
    When the admin demotes "Penpot/Demote Me/Sketch.penpot" to "link" mode
    Then the mode change succeeds
    And "Penpot/Demote Me/Sketch.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_revision | set             |
      | penpot_mode     | "link"          |
      | content         | empty           |
    And Penpot project "Demote Me" holds a design named "Sketch"

    # notes: ../AGENTS.md#demoting-throws-the-archive-away-and-never-contacts-penpot

  # @blocked — no tty. Demotion asks for confirmation on stdin, and Behat has no
  # terminal to answer with; every other scenario here passes --force for exactly
  # that reason, which is what leaves the prompt itself unasserted.
  # notes: ../AGENTS.md#demoting-asks-first-because-it-deletes-the-only-local-copy
  @blocked
  Scenario: Demoting asks first, because it deletes the only local copy
    Given a mirrored design "Precious" in the project "Stable"
    And "Penpot/Stable/Precious.penpot" is a "sync" design
    When the admin demotes "Penpot/Stable/Precious.penpot" to "link" mode without confirming
    Then the demotion asks for confirmation before anything is deleted
    And the file "Penpot/Stable/Precious.penpot" holds a real ".penpot" archive

  # @blocked — no fault injection. Every row needs a real Penpot to fail in a
  # specific way, and the harness can only ask it to succeed.
  # notes: ../AGENTS.md#a-promotion-that-fails-leaves-the-file-as-it-was
  @blocked
  Scenario Outline: A promotion that fails leaves the file as it was
    Given a mirrored design "Fragile" in the project "Stable"
    When the admin promotes "Penpot/Stable/Fragile.penpot" to "sync" mode, and <what fails>
    Then the mode change fails
    And the file "Penpot/Stable/Fragile.penpot" is left exactly as it was
    And the failure is reported as "<reason>"

    Examples: every way an export can fail on the wire
      | what fails                                     | reason              |
      | the export answers 200 with an error event     | the Penpot error    |
      | the export stream ends with no "end" event     | an incomplete export |
      | the asset download fails                       | a failed download   |
      | the asset download is refused as unauthorised  | a credential problem |

  @admin @occ
  Scenario: A folder has no mode to set
    When the team is mirrored again
    And the admin sets the mode of "Penpot" to "sync"
    Then the mode change is refused
    And the refusal mentions "Modes are per-file"
