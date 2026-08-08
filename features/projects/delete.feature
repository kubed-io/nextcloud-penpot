# Notes, decisions and history for this feature: ../AGENTS.md#projectsdelete

Feature: Deleting a Penpot project folder
  As a Nextcloud user
  I want deleting a project folder to delete the project in Penpot
  So that removing a folder means the same thing on both sides
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a project folder deletes the project in Penpot
    Given a mirrored design "Inside" in the project "Doomed"
    When I delete the "Doomed" project folder
    Then "delete-project" is called with that project's id
    And Penpot no longer lists a project named "Doomed"
    And the design "Inside" is in Penpot's trash
    And the folder is recoverable from the Nextcloud trash
    # notes: ../AGENTS.md#deleting-a-project-folder-deletes-the-project-in-penpot

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a project folder does not need a per-design call
    Given a mirrored project "Doomed" holding 3 designs
    When I delete the "Doomed" project folder
    Then "delete-file" is never called
    And exactly one "delete-project" call is made
    # notes: ../AGENTS.md#deleting-a-project-folder-does-not-need-a-per-design-call

  @in-nextcloud @gesture @todo
  Scenario: A plain folder inside a mapped folder deletes without touching Penpot
    Given a plain folder "Just My Notes" inside the mapped folder
    When I delete it
    Then Penpot is never contacted
    # Only a folder carrying `penpot_project_id` is a project. This is the same
    # rule the tag opt-in rests on (create-project.feature), stated for delete.

  @in-nextcloud @gesture @unbuilt
  Scenario: The team root is never deletable as a project
    When I try to delete the mapped folder itself
    Then "delete-project" is never called for the team's Drafts project
    # notes: ../AGENTS.md#the-team-root-is-never-deletable-as-a-project

  @in-penpot @todo
  Scenario: A project deleted in Penpot leaves no folder claiming its id
    Given a mirrored design "Orphan" in the project "Deleted Upstream"
    When the project is deleted in Penpot
    And the team is mirrored again
    Then the mirror of "Orphan" is in the Nextcloud trash
    And the folder does not still carry the dead project's id
    # notes: ../AGENTS.md#a-project-deleted-in-penpot-leaves-no-folder-claiming-its-id

    # ── the hard step: emptying the trash purges Penpot ───────────────────────

  # Penpot keeps listing a deleted project (saga §6.42), so the listing alone is
  # not proof it exists. notes: ../AGENTS.md#a-project-penpot-still-lists-after-deletion-is-not-mirrored
  @in-penpot @todo
  Scenario: A project Penpot still lists after deletion is not mirrored
    Given a Penpot project that has been deleted
    When the team is mirrored again
    Then no folder is created for that project
    And no design is mirrored into one

  @todo
  Scenario: Restoring a design also restores its project if that was deleted too
    Given a Penpot project that was deleted, containing a design
    When the design is restored from Penpot's trash
    Then its containing project is restored as well
    And the project folder reappears on the next pull
    # Penpot's restore clears deleted_at on the project as well as the file.

  # notes: ../AGENTS.md#deleting-a-personal-project-folder-deletes-that-project-in-penpot

  @in-nextcloud @gesture @unbuilt
  Scenario: Deleting a personal project folder deletes that project in Penpot
    Given a personal project folder in the user's home
    When the user deletes the folder
    Then the project is deleted in Penpot under the user's own token
    And its designs go to Penpot's trash with it
    And the folder is recoverable from the Nextcloud trash
