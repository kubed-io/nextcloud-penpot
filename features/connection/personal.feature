# Notes, decisions and history for this feature: ../AGENTS.md#connectionpersonal

Feature: The personal connection
  As a Nextcloud user
  I want to use my own token to connect to Penpot
  So that I can sync my personal team, and Penpot says the changes were mine

  Background:
    Given the app is enabled
    And a working admin connection to Penpot

  @unbuilt
  Scenario: A user enters a valid token
    When the user sets their personal token to a valid one
    And the user tests their personal connection
    Then the health check reports success
    And their home root is mapped to their personal Penpot team
    # The mapping is the token's shadow — nothing to name, nothing to decide.
    # notes: ../AGENTS.md#a-user-enters-a-valid-token

  @unbuilt
  Scenario: A user clears their token
    Given the user has personal project folders in their home
    When the user clears their personal token
    Then their home root is mapped to no Penpot team
    And a new ".penpot" file made there is inert, as it was before the token
    And the folders and their files are left exactly where they are
    # notes: ../AGENTS.md#a-user-clears-their-token

  @unbuilt
  Scenario: A user enters a bad token
    When the user sets their personal token to a bad one
    And the user tests their personal connection
    Then the health check reports an error
    And the message names "token" as the field causing it
