# Notes, decisions and history for this feature: ../AGENTS.md#restore-project

Feature: Restoring a Penpot project folder
  As a Nextcloud user
  I want restoring a project folder to bring back the project and its designs
  So that undoing a folder delete undoes all of it, or tells me it cannot
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture @unbuilt
  Scenario: Restoring a project folder brings back the project and every design in it
    Given a mirrored project "Doomed" holding 3 designs
    And I deleted the "Doomed" project folder
    When I restore the folder from the Nextcloud trash
    Then "restore-deleted-team-files" is called once with all 3 design ids
    And Penpot lists the project "Doomed" again
    And all 3 designs are back in it
    # notes: ../AGENTS.md#restoring-a-project-folder-brings-back-the-project-and-every-design-in-it

  @in-nextcloud @gesture @todo
  Scenario: Restoring one design of a deleted project does not silently restore the rest
    Given a Penpot project that was deleted with 2 designs in it
    When only the first design is restored
    Then the project exists again in Penpot
    And the second design is still in Penpot's trash
    # notes: ../AGENTS.md#restoring-one-design-of-a-deleted-project-does-not-silently-restore-the-rest

  @blocked
  Scenario: A project deleted while empty cannot be restored, and the app says so
    Given a project folder whose project was deleted with no designs in it
    When I restore the folder from the Nextcloud trash
    Then the folder comes back as an ordinary folder
    And the app explains that an empty Penpot project cannot be restored
    And it names the grace window after which the project is gone for good
    # notes: ../AGENTS.md#a-project-deleted-while-empty-cannot-be-restored-and-the-app-says-so

    # ── the layers restore does NOT use, and why it says so ───────────────────
