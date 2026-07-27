# The pull — Penpot → Nextcloud — as it actually runs in this build (saga Ch2
# Course 3, "The Survey Stakes"). Unlike the richer, still-@todo designs in
# team-import.feature and project-folder.feature (which assume groupfolders Team
# Folders, a Files-app surface, and writeback listeners), THIS file is the slice
# CI can prove end-to-end today:
#
#   - the plain admin-owned folder backend (StorageService), because the CI
#     Nextcloud has no groupfolders app;
#   - `occ penpot_sync:sync pull` walking get-all-projects → get-project-files;
#   - the metadata stamps (team id on the root, project id on a project folder);
#   - and — the point — the nearest-ancestor resolver (§6.29) run over a tree
#     the pull actually built, read back through `occ penpot_sync:status`.
#
# The fixtures are seeded straight into Penpot over its RPC bus (create-project,
# confirmed live §6.38) with the same minted token the app uses, then the app is
# made to mirror them. Not @todo: every step below is wired in PullSteps.
#
# DELIBERATELY NOT ASSERTED HERE (built in later courses, see the PullService
# docblock): the project-folder visible tag, `sync`-mode archive download, the
# `.penpot` browser deep-link, and prune of stale mirror files.

Feature: Pulling a Penpot team into a plain Nextcloud folder
  As an operator who has mapped a Penpot team
  I want `occ penpot_sync:sync pull` to mirror that team's projects and files
  So that the team appears in Nextcloud with the metadata the resolver relies on

  Background:
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped

  Scenario: A pull mirrors a mapped team's root folder and stamps its team id
    Given the first visible team is mapped as a plain folder "Penpot"
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Penpot" carries the team's Penpot id
    And resolving "Penpot" reports the team

  Scenario: A pull mirrors a project as a folder carrying its project id
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Acme Website" exists in that team
    When the admin runs a pull
    Then the pull succeeds
    And the folder "Penpot/Acme Website" carries a Penpot project id
    And resolving "Penpot/Acme Website" reports it is inside a Penpot project

  Scenario: A second pull reconciles in place and does not duplicate the folder
    Given the first visible team is mapped as a plain folder "Penpot"
    And a Penpot project named "Reconcile Me" exists in that team
    When the admin runs a pull
    And the admin runs a pull
    Then the folder "Penpot/Reconcile Me" carries a Penpot project id
    And there is no node at "Penpot/Reconcile Me (2)"
