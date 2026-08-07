# Notes, decisions and history for this feature: ../AGENTS.md#projectsview

Feature: Looking at a project folder
  As someone with a Penpot team mirrored into Nextcloud
  I want to tell a project folder from an ordinary one
  So that I know which folders Penpot knows about before I move anything into them

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

  @in-penpot @occ
  Scenario: A project folder carries a visible tag as well as its metadata
    Given a mirrored project "Marked"
    Then the folder "Penpot/Marked" carries a Penpot project id
    And the folder "Penpot/Marked" carries the "penpot" tag
    # notes: ../AGENTS.md#a-project-folder-carries-a-visible-tag-as-well-as-its-metadata

  @in-penpot @occ
  Scenario: A tagged folder's name always equals its Penpot project's name
    Given a mirrored project "Exactly This Name"
    Then the folder "Penpot/Exactly This Name" carries a Penpot project id
    And Penpot holds a project named "Exactly This Name"
    # notes: ../AGENTS.md#a-tagged-folders-name-always-equals-its-penpot-projects-name

  @in-nextcloud @gesture
  Scenario: A plain folder inside a mapped folder is tolerated, not adopted
    Given I create a folder at "Penpot/Just Sitting Here"
    When the team is mirrored again
    Then the folder "Penpot/Just Sitting Here" carries no Penpot project id
    And Penpot holds no project named "Just Sitting Here"
    # notes: ../AGENTS.md#a-plain-folder-inside-a-mapped-folder-is-tolerated-not-adopted
