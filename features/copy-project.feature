# Notes, decisions and history for this feature: AGENTS.md#copy-project

Feature: Copying a Penpot project folder
  As a Nextcloud user
  I want copying a project folder to be refused rather than half-done
  So that a drag never invents a bulk operation Penpot cannot perform
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @todo
  Scenario: Copying a project folder is refused, unlike copying a file
    Given the Penpot project "My Stuff" is mirrored as a folder
    When I try to copy that folder
    Then the copy is refused with an explanation
    And no new Penpot project is created
    And no duplicate project folder is left behind
    And copying an individual ".penpot" file remains unaffected
    # notes: AGENTS.md#copying-a-project-folder-is-refused-unlike-copying-a-file

  @in-nextcloud @gesture @todo
  Scenario: Copying an ordinary folder inside a mapped folder is unaffected
    Given a plain folder "Clients" inside the mapped folder with no Penpot metadata
    When I copy it
    Then the copy succeeds normally
    And Penpot is never contacted
    # notes: AGENTS.md#copying-an-ordinary-folder-inside-a-mapped-folder-is-unaffected
