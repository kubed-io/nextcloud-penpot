# Notes, decisions and history for this feature: ../AGENTS.md#team-mappingcreate

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

  Scenario Outline: Using invalid values for a mapping
    Given a mapping with the following values:
      | team   | Northwind |
      | folder | Designs   |
    And a Penpot team named "<team>" exists
    And the penpot team "Outsiders" does not exist
    When the admin submits this mapping:
      | team    | <team>    |
      | folder  | <folder>  |
      | mode    | <mode>    |
      | storage | <storage> |
    Then the mapping is rejected, explaining "<reason>"

    Examples: Creating a mapping when the team is already mapped
      | team      | folder    | mode | storage     | reason         |
      | Northwind | Elsewhere | sync | team folder | The team is already mapped to another folder |

    Examples: Creating a mapping when the folder is already mapped
      | team       | folder  | mode | storage      | reason       |
      | Bundt Cake | Designs | link | admin folder | The folder is already mapped to another team |

    Examples: Creating a mapping when the team does not exist
      | team      | folder  | mode | storage     | reason                  |
      | Outsiders | Designs | link | team folder | The team was not found using the given credentials |

    # notes: ../AGENTS.md#using-invalid-values-for-a-mapping

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

  # notes: ../AGENTS.md#without-a-service-account-token-nothing-can-be-mapped
  @occ
  Scenario: Without a service-account token, nothing can be mapped
    Given a penpot team named "Northwind" exists
    And no service-account token is configured
    When the admin submits this mapping:
      | team   | Northwind |
      | folder | Designs   |
    Then the mapping is rejected, explaining "A service-account token is not configured yet."
