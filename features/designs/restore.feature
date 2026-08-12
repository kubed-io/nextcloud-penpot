# Notes, decisions and history for this feature: ../AGENTS.md#designsrestore

Feature: Restoring a design from the trash
  As a Nextcloud user
  I want a restore to undo exactly what the trashing did
  So that changing my mind costs nothing on either side

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |

    # ── RULE: a restore is the trashing, undone ──────────────────────────────
    # notes: ../AGENTS.md#restoring-a-design-brings-back-the-file-and-its-design-together

  @in-nextcloud @gesture
  Scenario: Restore a design whose design is in Penpot's trash
    Given a mirrored design "Round Trip" in the project "Stay Put"
    And "Penpot/Stay Put/Round Trip.penpot" is in the trash
    When I restore "Penpot/Stay Put/Round Trip.penpot" from the Nextcloud trash
    Then Penpot project "Stay Put" holds a design named "Round Trip"
    And the design "Round Trip" is not in Penpot's trash
    And "Penpot/Stay Put/Round Trip.penpot" holds:
      | penpot_id | the id it had before it was trashed |
      | content   | an archive                          |

    # Penpot's own trash is what makes this lossless: the design comes back with the
    # id, revision, history and links it always had, and nothing is imported.

    # ── RULE: what the far side still has decides what a restore has to do ───
    # notes: ../AGENTS.md#the-three-layers-a-restore-can-land-in

  @in-nextcloud @gesture @todo
  Scenario: Restore a design that is already back in Penpot
    Given a mirrored design "Rescued" in the project "Stay Put"
    And "Penpot/Stay Put/Rescued.penpot" is in the trash
    And the design "Rescued" has been restored in Penpot
    When I restore "Penpot/Stay Put/Rescued.penpot" from the Nextcloud trash
    Then Penpot project "Stay Put" holds 1 design
    And "Penpot/Stay Put/Rescued.penpot" holds:
      | penpot_id | the id it had before it was trashed |

    # Nothing was lost remotely, so nothing is sent. A second restore would be a
    # second design.

  @in-nextcloud @gesture @unbuilt
  Scenario: Restore a design that is gone from Penpot for good
    Given a mirrored design "Lost" in the project "Stay Put"
    And "Penpot/Stay Put/Lost.penpot" is in the trash
    And the design "Lost" has been permanently deleted in Penpot
    When I restore "Penpot/Stay Put/Lost.penpot" from the Nextcloud trash
    Then Penpot project "Stay Put" holds no design named "Lost"
    And the app reports that the design is gone and the file is now the only copy
    And "Penpot/Stay Put/Lost.penpot" holds:
      | content | an archive |

    # Past the grace window there is nothing to put back — importing the archive
    # would be a new design, which is a different gesture. See the notes above.

    # ── RULE: a file the app never mirrored is Nextcloud's alone ─────────────
    # notes: ../AGENTS.md#restoring-a-file-that-was-never-in-penpot-leaves-penpot-alone

  @in-nextcloud @gesture
  Scenario: Restore a file the app never mirrored
    Given an untracked ".penpot" archive at "Loose Design.penpot"
    And "Loose Design.penpot" is in the trash
    When I restore "Loose Design.penpot" from the Nextcloud trash
    Then the file "Loose Design.penpot" is not in the Nextcloud trash
    And the file "Loose Design.penpot" carries no Penpot id

    # ── RULE: a design coming back in Penpot brings its file with it ─────────

  @in-penpot @gesture @unbuilt
  Scenario: Restore a design in Penpot while its file is in the trash
    Given a mirrored design "Both Sides" in the project "Stay Put"
    And "Penpot/Stay Put/Both Sides.penpot" is in the trash
    When someone restores the design "Both Sides" in Penpot
    Then the file "Penpot/Stay Put/Both Sides.penpot" is not in the Nextcloud trash
    And Penpot project "Stay Put" holds 1 design

    # The trashed file is brought back rather than a second one written, because the
    # id names the file that already exists.

    # ── RULE: a restore is attributed to whoever made it ─────────────────────
    # notes: ../AGENTS.md#a-restore-is-attributed-to-the-acting-user

  @in-nextcloud @gesture @blocked
  Scenario: Restore a design as a user with a personal token
    Given the user has a personal Penpot token
    And a mirrored design "Mine" in the project "Stay Put"
    And "Penpot/Stay Put/Mine.penpot" is in the trash
    When I restore "Penpot/Stay Put/Mine.penpot" from the Nextcloud trash
    Then Penpot project "Stay Put" holds a design named "Mine"
    And Penpot attributes the restore to that user

  @in-nextcloud @gesture @blocked
  Scenario: Restore a design as a user with no personal token
    Given the user has no personal Penpot token
    And a mirrored design "Ours" in the project "Stay Put"
    And "Penpot/Stay Put/Ours.penpot" is in the trash
    When I restore "Penpot/Stay Put/Ours.penpot" from the Nextcloud trash
    Then Penpot project "Stay Put" holds a design named "Ours"
    And Penpot attributes the restore to the service account
    And the user is told the restore was made as the service account

    # The missing token never blocks the restore — it only changes whose name is
    # on it, and the user is told rather than left to discover it.
