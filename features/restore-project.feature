# RESTORING A PROJECT — the folder, and everything that was in it. Restoring a
# design is restore-design.feature.
#
# A PROJECT COMES BACK WHOLE, OR IT DOES NOT COME BACK. Restoring the folder
# restores the project and every design that went down with it — restoring one
# design of a deleted project does NOT silently resurrect the project around it,
# because that would be inventing a container the user never asked for.
#
# THE ONE THING THAT CANNOT BE UNDONE: a project deleted while EMPTY has no
# design to restore it through. Penpot exposes no restore-project RPC — a project
# returns only as a side effect of restoring one of its files (saga §C6.11,
# confirmed live) — so an empty one is genuinely gone, and the app says so
# plainly rather than failing in a way that reads like a bug.

Feature: Restoring a Penpot project folder
  As a Nextcloud user
  I want restoring a project folder to bring back the project and its designs
  So that undoing a folder delete undoes all of it, or tells me it cannot
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team is mapped to the folder "Penpot"

  @in-nextcloud @gesture @unbuilt
  Scenario: Restoring a project folder brings back the project and every design in it
    Given a mirrored project "Doomed" holding 3 designs
    And I deleted the "Doomed" project folder
    When I restore the folder from the Nextcloud trash
    Then "restore-deleted-team-files" is called once with all 3 design ids
    And Penpot lists the project "Doomed" again
    And all 3 designs are back in it
    # ONE call with the whole set, not three calls. Penpot restores the project
    # from any file in it, so three calls would restore the project on the first
    # and then merely add files — but a partial failure would leave a project
    # holding some of its designs, which is worse than either extreme.

  @in-nextcloud @gesture @todo
  Scenario: Restoring one design of a deleted project does not silently restore the rest
    Given a Penpot project that was deleted with 2 designs in it
    When only the first design is restored
    Then the project exists again in Penpot
    And the second design is still in Penpot's trash
    # Confirmed live (§C6.19). Stated because it is genuinely surprising, and
    # because a naive "restore the folder" that fired one call per file it
    # happened to find in the Nextcloud trash would produce exactly this
    # half-restored state without ever looking wrong.

  @blocked
  Scenario: A project deleted while empty cannot be restored, and the app says so
    Given a project folder whose project was deleted with no designs in it
    When I restore the folder from the Nextcloud trash
    Then the folder comes back as an ordinary folder
    And the app explains that an empty Penpot project cannot be restored
    And it names the grace window after which the project is gone for good
    # Penpot offers no `restore-project`, and there is no file to carry it back.
    # Saying so is the whole behaviour — the alternative is a folder that looks
    # restored and points at nothing.

    # ── the layers restore does NOT use, and why it says so ───────────────────
