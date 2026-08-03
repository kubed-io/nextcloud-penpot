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

  Scenario Outline: Creating a mapping saves the form
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    And an unset field on the mapping form defaults to:
      | folder      | Northwind           |
      | mode        | link                |
      | groups      |                     |
      | folder mode | nested              |
      | storage     | plain shared folder |
    When the admin maps "Northwind" with:
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

  @todo
  Scenario: Renaming the team in Penpot does not rename the admin's folder
    Given a Penpot team named "Northwind" is mapped to the folder "Design Files"
    When the team is renamed in Penpot
    And the pull runs
    Then the mapping's Nextcloud folder is still "Design Files"
    # notes: AGENTS.md#renaming-the-team-in-penpot-does-not-rename-the-admins-folder

  @todo
  Scenario: Two mappings cannot target the same Nextcloud folder
    Given a Penpot team named "Northwind" is mapped to the folder "Designs"
    When the admin maps another team into the folder "Designs"
    Then the mapping is rejected
    And the refusal explains "already used"
    # Two teams mirroring into one folder would interleave their project
    # subfolders, and the pull would fight over the same names on every run.

  Scenario Outline: A value the app cannot honour is refused, and says why
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    When the admin maps "Northwind" with:
      | folder      | <folder>      |
      | folder mode | <folder mode> |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured team mappings

    Examples: the same form, and the two values it will not take
      | folder       | folder mode | reason             |
      | teams/design |             | single folder name |
      |              | keyed       | not implemented    |

    # notes: AGENTS.md#a-value-the-app-cannot-honour-is-refused-and-says-why

  Scenario: A team id that resolves to nothing cannot be mapped
    Given no Penpot teams are mapped
    When the admin tries to map the team id "11111111-2222-3333-4444-555555555555"
    Then the mapping is rejected
    And the refusal explains "not visible to the service account"
    And there are exactly 0 configured team mappings
    # notes: AGENTS.md#a-team-id-that-resolves-to-nothing-cannot-be-mapped

  Scenario: A Penpot team may only be mapped once
    Given a Penpot team named "Northwind" is mapped to the folder "Design Files"
    When the admin maps the team "Northwind" into the folder "Design Files"
    Then the mapping is rejected
    And there is exactly 1 configured team mapping
    # notes: AGENTS.md#a-penpot-team-may-only-be-mapped-once

  Scenario: Removing a mapping deletes nothing
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    When the admin maps the Penpot team "Northwind"
    And the admin removes that mapping
    Then there are exactly 0 configured team mappings
    And removing it reported that nothing was deleted
    # notes: AGENTS.md#removing-a-mapping-deletes-nothing

    # notes: AGENTS.md#there-is-no-project-level-mapping-to-configure

  @decision
  Scenario: There is no project-level mapping to configure
    Given the Penpot team "Northwind" is mapped
    Then the mapping list shows exactly 1 mapping, for the team
    And no per-project mapping can be added, configured, or removed
    And project subfolders exist only because the pull created them

    # ── permissions and fallback ─────────────────────────────────────────────────

  @todo
  Scenario: Project folder names always match their Penpot projects
    Given the Penpot team "Northwind" is mapped and pulled
    Then every project folder is named exactly as Penpot names that project
    And the app never lets a project folder's name diverge from its project's
    # notes: AGENTS.md#project-folder-names-always-match-their-penpot-projects

  @todo
  Scenario: Two Penpot projects in one team sharing a name is handled, not crashed
    Given the "Northwind" team has two projects both named "Brand"
    When the pull runs
    Then both are mirrored without a folder-name collision
    And the app reports the ambiguity so an admin can rename one in Penpot
    # notes: AGENTS.md#two-penpot-projects-in-one-team-sharing-a-name-is-handled-not-crashed

  @todo
  Scenario: A team renamed in Penpot does not rename the mapped folder
    Given the Penpot team "Northwind" is mapped
    When the team is renamed to "Northwind Design" in Penpot
    And the pull runs
    Then the mapped folder keeps the name the admin gave it
    And the mapping records the team's new name
    And the mapping still resolves, because it is keyed on the team id, not the name
    # notes: AGENTS.md#a-team-renamed-in-penpot-does-not-rename-the-mapped-folder

    # notes: AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed

  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given a Penpot team named "Northwind" is mapped to a <folder type>, shared with "design,admin"
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"

    Examples: on a Team Folder
      | folder type | groups             |
      | Team Folder | design,admin,sales |
      | Team Folder | design             |
      | Team Folder | sales              |
      | Team Folder |                    |

    Examples: and on a plain shared folder
      | folder type  | groups             |
      | plain folder | design,admin,sales |
      | plain folder | design             |
      | plain folder | sales              |
      | plain folder |                    |
