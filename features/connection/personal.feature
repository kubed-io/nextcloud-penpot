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

  @unbuilt
  Scenario: A user enters a bad token
    When the user sets their personal token to a bad one
    And the user tests their personal connection
    Then the health check reports an error
    And the message names "token" as the field causing it
