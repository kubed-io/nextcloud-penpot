# Notes, decisions and history for this feature: ../AGENTS.md#create-project

Feature: A folder as a Penpot project — the opt-in, and the tag that marks it
  As a Nextcloud user
  I want to choose which of my folders are Penpot projects
  So that a mapped folder stays usable for ordinary things

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
    And the team has been mirrored into Nextcloud

    # ── the permissive half, and it has to come first ───────────────────────────

  @in-nextcloud @gesture
  Scenario: A new folder inside a mapped folder is just a folder
    When I create a folder at "Penpot/Just My Notes"
    Then the folder "Penpot/Just My Notes" carries no Penpot project id
    And the folder "Penpot/Just My Notes" does not carry the "penpot" tag
    And Penpot holds no project named "Just My Notes"
    # A mapped folder that silently turned every subfolder into a Penpot project
    # would be unusable for anything else.

    # ── opting in ───────────────────────────────────────────────────────────────

  @in-nextcloud @occ
  Scenario: Tagging a folder "penpot" creates the project in Penpot
    Given I create a folder at "Penpot/Client Work"
    When I assign the "penpot" tag to "Penpot/Client Work"
    Then Penpot holds a project named "Client Work"
    And the folder "Penpot/Client Work" carries a Penpot project id

  @in-nextcloud @occ
  Scenario: A folder opted in late brings the designs already inside it
    Given I create a folder at "Penpot/Late Opt In"
    And I create a new design file at "Penpot/Late Opt In/Moodboard.penpot"
    When I assign the "penpot" tag to "Penpot/Late Opt In"
    Then Penpot project "Late Opt In" holds a design named "Moodboard"
    # notes: ../AGENTS.md#a-folder-opted-in-late-brings-the-designs-already-inside-it

  @in-nextcloud @occ
  Scenario: Tagging a folder that is already a project changes nothing
    Given a mirrored project "Already Mine"
    When I assign the "penpot" tag to "Penpot/Already Mine"
    Then the folder "Penpot/Already Mine" carries a Penpot project id
    And Penpot holds a project named "Already Mine"
    # notes: ../AGENTS.md#tagging-a-folder-that-is-already-a-project-changes-nothing

  @in-nextcloud @occ @todo
  Scenario: A folder tagged as a project must have a usable name first
    Given a plain folder inside the mapped folder whose name is unusable as a project name
    When I assign the "penpot" tag to it
    Then the app refuses and explains what is wrong with the name
    And the tag is not left applied
    And no Penpot project is created
    # notes: ../AGENTS.md#a-folder-tagged-as-a-project-must-have-a-usable-name-first

  @in-nextcloud @occ @todo
  Scenario: Tagging a folder outside every mapping does nothing at all
    Given a plain folder "Holiday Photos" outside every mapped folder
    When I assign the "penpot" tag to it
    Then Penpot is never contacted
    And the tag is left where the user put it
    # notes: ../AGENTS.md#tagging-a-folder-outside-every-mapping-does-nothing-at-all

    # ── opting out does not destroy anything ────────────────────────────────────

  @in-nextcloud @occ
  Scenario: Removing the "penpot" tag does not delete the project
    Given I create a folder at "Penpot/Keep Me"
    And the folder "Penpot/Keep Me" has been tagged "penpot"
    When I remove the "penpot" tag from "Penpot/Keep Me"
    Then Penpot holds a project named "Keep Me"
    And the folder "Penpot/Keep Me" carries a Penpot project id
    # notes: ../AGENTS.md#removing-the-penpot-tag-does-not-delete-the-project

    # ── the tag as the shared marker ────────────────────────────────────────────

  @in-penpot
  Scenario: A project created in Penpot arrives as a tagged folder
    Given a Penpot project named "Bubbles" exists in that team
    When the team is mirrored again
    Then the folder "Penpot/Bubbles" carries a Penpot project id
    And the folder "Penpot/Bubbles" carries the "penpot" tag
    # notes: ../AGENTS.md#a-project-created-in-penpot-arrives-as-a-tagged-folder

  @in-penpot
  Scenario: A project folder that lost its tag gets it back on the next pull
    Given a mirrored project "Retagged"
    And I remove the "penpot" tag from "Penpot/Retagged"
    When the team is mirrored again
    Then the folder "Penpot/Retagged" carries the "penpot" tag
    # notes: ../AGENTS.md#a-project-folder-that-lost-its-tag-gets-it-back-on-the-next-pull
