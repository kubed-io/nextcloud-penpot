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

    # TWO RULES, AND THEY LOOKED LIKE ONE SCENARIO TWICE. Written apart, both read
    # "a mapping exists, the admin maps one, it is refused" — the difference lived
    # in whether a second team had been named, which is invisible on the page.
    # Side by side the columns ARE the difference: row 1 reuses the team and takes
    # a free folder, row 2 brings a fresh team to the taken folder.
    #
    # Naming a team re-points "it", so row 1 names the mapped team back again and
    # row 2 names one that is not mapped at all.
    #
    # Why each is refused: a team is what a mapping IS, so mapping it twice would
    # make two mappings mean the same thing. Two teams mirroring into one folder
    # would interleave their project subfolders, and every sync would fight over
    # the same names.
    # notes: AGENTS.md#a-mapping-may-not-reuse-a-team-or-a-folder

  Scenario: Removing a mapping deletes nothing
    Given a mapping with the following values:
      | team   | Northwind    |
      | folder | Design Files |
    When the admin removes that mapping
    Then there are exactly 0 configured team mappings
    And removing it reported that nothing was deleted
    # notes: AGENTS.md#removing-a-mapping-deletes-nothing

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
