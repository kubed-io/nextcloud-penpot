# Notes, decisions and history for this feature: ../AGENTS.md#connectionadmin

Feature: The main admin connection
  As a Nextcloud admin
  I want to configure my connection details for Penpot
  So that the app works

  Background:
    Given the app is enabled

  @todo
  Scenario: An admin enters valid connection details
    When the admin fills in the connection details:
      | url         | the test instance |
      | token       | a valid token     |
      | enable sync | true              |
      | schedule    | 1h                |
    And the admin tests the connection
    Then the health check reports success
    And the health check lists at least one Penpot team

  @todo
  Scenario Outline: An admin enters bad connection details
    When the admin fills in the connection details:
      | url         | <url>   |
      | token       | <token> |
      | enable sync | true    |
      | schedule    | 1h      |
    And the admin tests the connection
    Then the health check reports an error
    And the message names "<field>" as the field causing it

    Examples: each field that can be wrong on its own
      | url               | token        | field |
      | the test instance |              | token |
      | the test instance | a bad token  | token |
      | example.com       | a good token | url   |

    # notes: ../AGENTS.md#an-admin-enters-bad-connection-details
