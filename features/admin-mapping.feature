# Notes, decisions and history for this feature: AGENTS.md#admin-mapping

Feature: Admin configures team mappings
  As a Nextcloud admin
  I want to map existing Penpot teams to Team Folders
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # ── the mapping lifecycle: IMPLEMENTED, runs against a real Penpot ──────────
    # These drive the same MappingService the settings panel calls, over occ.
    #
    # A mapping is ONE fact, so it is one sentence: "a mapping with the following values:" plus a
    # table of what is in it. The fields are the same ones the creation form
    # takes, so the pre-state and the action are described in one vocabulary, and
    # a blank or absent row means the app's own default. It also names the team,
    # so the rest of the scenario can say "it".
    # notes: AGENTS.md#the-preconditions

  Scenario Outline: Creating a mapping saves the form
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    And the Nextcloud groups "design" exist
    And an unset field on the mapping form defaults to:
      | folder  | Northwind           |
      | mode    | link                |
      | groups  |                     |
      | storage | plain shared folder |
    When the admin maps it with:
      | folder  | <folder>  |
      | mode    | <mode>    |
      | groups  | <groups>  |
      | storage | <storage> |
    Then the mapping matches the form, unset fields at their defaults

    Examples: one field at a time, and nothing at all
      | folder       | mode | groups       | storage     |
      |              |      |              |             |
      | Design Files |      |              |             |
      |              | link |              |             |
      |              | sync |              |             |
      |              |      | admin        |             |
      |              |      | admin,design |             |
      |              |      |              | team folder |

    Examples: and in combination
      | folder       | mode | groups       | storage     |
      | Design Files | sync | admin,design | team folder |
      | Northwind    | link | admin        | team folder |

    # notes: AGENTS.md#creating-a-mapping-saves-the-form

  # notes: AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed
  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | team    | Northwind    |
      | folder  | <folder>     |
      | groups  | design,admin |
      | storage | <storage>    |
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"

    # The folder name differs per storage kind ON PURPOSE. Removing a mapping
    # deletes nothing, so a folder outlives the mapping that made it — and a
    # later row reusing the name would inherit a folder of the wrong kind.

    Examples: on a Team Folder
      | folder                  | storage     | groups             |
      | Groups On A Team Folder | team folder | design,admin,sales |
      | Groups On A Team Folder | team folder | design             |
      | Groups On A Team Folder | team folder | sales              |
      | Groups On A Team Folder | team folder |                    |

    Examples: and on a plain shared folder
      | folder                   | storage             | groups             |
      | Groups On A Plain Folder | plain shared folder | design,admin,sales |
      | Groups On A Plain Folder | plain shared folder | design             |
      | Groups On A Plain Folder | plain shared folder | sales              |
      | Groups On A Plain Folder | plain shared folder |                    |

  Scenario: Two mappings cannot target the same Nextcloud folder
    Given a mapping with the following values:
      | team   | Northwind |
      | folder | Designs   |
    And a Penpot team named "Bundt Cake" exists
    When the admin maps it with:
      | folder | Designs |
    Then the mapping is rejected
    And the refusal explains "already used"
    # Two teams mirroring into one folder would interleave their project
    # subfolders, and the pull would fight over the same names on every run.
    #
    # The second team is named LAST because naming a team re-points "it", and the
    # When needs "it" to be the team that is not yet mapped. A DIFFERENT team into
    # a taken folder is what makes this the folder rule rather than the team rule.

  # api only because the ui is a drop down
  @api @occ
  Scenario: A team id that resolves to nothing cannot be mapped
    Given no Penpot teams are mapped
    When the admin tries to map the team id "11111111-2222-3333-4444-555555555555"
    Then the mapping is rejected
    And the refusal explains "not visible to the service account"
    And there are exactly 0 configured team mappings
    # The only scenario here that names no team: this step exists to hand
    # add-mapping something no lookup could have produced.
    # notes: AGENTS.md#a-team-id-that-resolves-to-nothing-cannot-be-mapped

  Scenario: A Penpot team may only be mapped once
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    When the admin maps it with:
      | folder | Design Files |
    Then the mapping is rejected
    And there is exactly 1 configured team mapping
    # The SAME team, mapped again — no second team is named, so "it" is still
    # Northwind. That is what separates this from the folder rule above.
    # notes: AGENTS.md#a-penpot-team-may-only-be-mapped-once

  Scenario: Removing a mapping deletes nothing
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    When the admin removes that mapping
    Then there are exactly 0 configured team mappings
    And removing it reported that nothing was deleted
    # notes: AGENTS.md#removing-a-mapping-deletes-nothing

    # notes: AGENTS.md#there-is-no-project-level-mapping-to-configure

  @decision
  Scenario: There is no project-level mapping to configure
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    Then the mapping list shows exactly 1 mapping, for the team
    And no per-project mapping can be added, configured, or removed
    And project subfolders exist only because the pull created them

    # ── permissions and fallback ─────────────────────────────────────────────────

  @todo
  Scenario: Project folder names always match their Penpot projects
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    And the pull runs
    Then every project folder is named exactly as Penpot names that project
    And the app never lets a project folder's name diverge from its project's
    # notes: AGENTS.md#project-folder-names-always-match-their-penpot-projects

  @todo
  Scenario: Two Penpot projects in one team sharing a name is handled, not crashed
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    And it has two projects both named "Brand"
    When the pull runs
    Then both are mirrored without a folder-name collision
    And the app reports the ambiguity so an admin can rename one in Penpot
    # notes: AGENTS.md#two-penpot-projects-in-one-team-sharing-a-name-is-handled-not-crashed

  @todo
  Scenario: A team renamed in Penpot does not rename the mapped folder
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    When it is renamed to "Northwind Design" in Penpot
    And the pull runs
    Then the mapping's Nextcloud folder is still "Design Files"
    And the mapping records the team's new name
    And the mapping still resolves, because it is keyed on the team id, not the name
    # notes: AGENTS.md#a-team-renamed-in-penpot-does-not-rename-the-mapped-folder
