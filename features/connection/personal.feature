# Notes, decisions and history for this feature: ../AGENTS.md#connectionpersonal

Feature: The personal connection
  As a Nextcloud user
  I want to use my own token to connect to Penpot
  So that I can sync my personal team, and Penpot says the changes were mine

  Background:
    Given the app is enabled
    And a working admin connection to Penpot

  @todo
  Scenario: A user sets their own token
    When the user sets their personal token
    And the user tests their personal connection
    Then the health check reports success

  @todo
  Scenario: A user's token is theirs alone
    Given another user has set their own personal token
    When the user sets their personal token
    Then each user's token is stored against that user only
