# Notes, decisions and history for this feature: ../AGENTS.md#team-mapping-create

Feature: Mapping a Penpot team to a Nextcloud folder
  As a Nextcloud admin
  I want to point a Penpot team at a folder
  So that its designs mirror into Nextcloud, scriptably (e.g. from a k8s job)

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # ── one fact, one table — the same shape as pre-state or as the action ─────
    # notes: ../AGENTS.md#the-preconditions

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

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

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
    # notes: ../AGENTS.md#a-team-id-that-resolves-to-nothing-cannot-be-mapped

  Scenario Outline: A mapping may not reuse a team or a folder
    Given a mapping with the following values:
      | team   | Northwind |
      | folder | Designs   |
    And a Penpot team named "<team>" exists
    When the admin maps it with:
      | folder | <folder> |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there is exactly 1 configured team mapping

    Examples: a team may be mapped once, and a folder may be used once
      | team       | folder    | reason         |
      | Northwind  | Elsewhere | already mapped |
      | Bundt Cake | Designs   | already used   |

    # notes: ../AGENTS.md#a-mapping-may-not-reuse-a-team-or-a-folder
