# Notes, decisions and history for this feature: ../AGENTS.md#team-mapping-manage-groups

Feature: Changing who a mapped folder is shared with
  As a Nextcloud admin
  I want to change a mapped folder's groups after the fact
  So that access can follow the team without rebuilding the mapping

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

    # The one field of a mapping that is editable; everything else is fixed at
    # creation, because changing it would migrate already-mirrored content.
    # notes: ../AGENTS.md#team-mappingmanage-groups

  # notes: ../AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed
  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | team    | Northwind    |
      | folder  | <folder>     |
      | groups  | design,admin |
      | storage | <storage>    |
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"


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
