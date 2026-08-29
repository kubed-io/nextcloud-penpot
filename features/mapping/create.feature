# Notes, decisions and history for this feature: ../AGENTS.md#mappingcreate

Feature: Mapping a Penpot team to a Nextcloud folder
  As a Nextcloud admin
  I want to point a Penpot team at a folder
  So that its designs mirror into Nextcloud, scriptably (e.g. from a k8s job)

  rules: 
  - creating a mapping does not trigger a sync
  - creating a mapping creates its nextcloud folder if it doesn't exist at the moment of creation
  - if the folder is a team folder, the folder is created with the team folder api
  - if link mapping is created over unmapped files and they are opted to be purged, the creation of the mapping is what sets the stage for the sync whenever it happens 

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # ── one fact, one table — the same shape as pre-state or as the action ─────
    # notes: ../AGENTS.md#the-preconditions

  Scenario Outline: Creating a new mapping to a penpot team 
    Given a penpot team named "Northwind" exists
    And the Nextcloud groups "design" exists
    And an unset field on the mapping form defaults to:
      | folder  | Northwind           |
      | mode    | link                |
      | groups  |                     |
      | storage | plain shared folder |
    When the admin submits this mapping:
      | team    | Northwind |
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

  # notes: ../AGENTS.md#a-link-mapping-may-not-be-made-over-designs-that-already-exist
  @occ
  Scenario: Mapping in link mode over a folder that already holds designs
    Given a penpot team named "Northwind" exists
    And a folder "Designs" already exists
    And an unmapped design file at "Designs/Sketches/Keeper.penpot"
    When the admin submits this mapping:
      | team   | Northwind |
      | folder | Designs   |
      | mode   | link      |
    And allows the existing unmapped designs to be purged
    Then the mapping matches the form, unset fields at their defaults
    And no ".penpot" designs exist under "/Designs" in Nextcloud
    And "Designs/Sketches/Keeper.penpot" left no trash entry

  # notes: ../AGENTS.md#a-team-may-only-be-mapped-once
  @occ
  Scenario: A team may only be mapped once
    Given a penpot team named "Northwind" exists
    And a mapping with the following values:
      | team   | Northwind |
      | folder | Designs   |
    When the admin submits this mapping:
      | team   | Northwind |
      | folder | Elsewhere |
      | mode   | sync      |
    Then the mapping is rejected, explaining "The team is already mapped to another folder"

  # notes: ../AGENTS.md#a-folder-may-only-be-mapped-once
  @occ
  Scenario: A folder may only be mapped once
    Given a mapping with the following values:
      | team   | Northwind |
      | folder | Designs   |
    And a penpot team named "Bundt Cake" exists
    When the admin submits this mapping:
      | team   | Bundt Cake |
      | folder | Designs    |
      | mode   | link       |
    Then the mapping is rejected, explaining "The folder is already mapped to another team"

  # api only because the ui is a drop down of the teams it can reach
  # notes: ../AGENTS.md#a-team-that-cannot-be-reached-cannot-be-mapped
  @api @occ
  Scenario: A team that cannot be reached cannot be mapped
    Given the penpot team "Outsiders" does not exist
    When the admin submits this mapping:
      | team   | Outsiders |
      | folder | Designs   |
    Then the mapping is rejected, explaining "The team was not found using the given credentials"

  # notes: ../AGENTS.md#without-a-service-account-token-nothing-can-be-mapped
  @occ
  Scenario: Without a service-account token, nothing can be mapped
    Given a penpot team named "Northwind" exists
    And no service-account token is configured
    When the admin submits this mapping:
      | team   | Northwind |
      | folder | Designs   |
    Then the mapping is rejected, explaining "A service-account token is not configured yet."
