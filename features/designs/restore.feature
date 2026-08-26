# Notes, decisions and history for this feature: ../AGENTS.md#designsrestore

Feature: Restoring a design from the trash
  As a Nextcloud user
  I want a restore to undo exactly what the trashing did
  So that changing my mind costs nothing on either side

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a restore is the trashing, undone ──────────────────────────────
    # notes: ../AGENTS.md#restoring-a-design-brings-back-the-file-and-its-design-together

  @in-nextcloud @gesture
  Scenario Outline: Restore a design whose design is in Penpot's trash
    Given a design file named "Round Trip.penpot" in "<source>"
    And the file is in the Nextcloud trash
    And its design is in Penpot's trash
    When I restore it from the trash
    Then the file is back in "<source>"
    And the design is in the "<project>" Penpot project
    And the file holds:
      | penpot_id   | the original id    |
      | penpot_mode | the mapping's mode |
      | content     | an archive         |

    Examples: from either storage kind, because the trash is the trash
      | source          | project  |
      | Penpot/Stay Put | Stay Put |
      | Shared/Stay Put | Stay Put |

    # Penpot's own trash is what makes this lossless: the design comes back with the
    # id, revision, history and links it always had, and nothing is imported.

    # ── RULE: what the far side still has decides what a restore has to do ───
    # notes: ../AGENTS.md#the-three-layers-a-restore-can-land-in

  @in-nextcloud @gesture
  Scenario: Restore a design that is already back in Penpot
    Given a design file named "Rescued.penpot" in "Penpot/Stay Put"
    And the file is in the Nextcloud trash
    And its design has been restored in Penpot
    When I restore it from the trash
    Then the design is in the "Stay Put" Penpot project
    And there is exactly one file for that design

    # Nothing was lost remotely, so nothing is sent. A second restore would be a
    # second design.

  # notes: ../AGENTS.md#there-is-nowhere-for-a-failure-to-be-reported-to
  # @todo — the report travels now; nothing in this suite can read a bell entry.
  @in-nextcloud @gesture @todo
  Scenario: Restore a design that is gone from Penpot for good
    Given a design file named "Lost.penpot" in "Penpot/Stay Put"
    And the file is in the Nextcloud trash
    And its design has been permanently deleted in Penpot
    When I restore it from the trash
    Then the "Stay Put" Penpot project holds no design named "Lost"
    And the app reports that the design is gone and the file is now the only copy
    And the file holds:
      | content | an archive |

    # Past the grace window there is nothing to put back — importing the archive
    # would be a new design, which is a different gesture. See the notes above.

    # ── RULE: a file the app never mirrored is Nextcloud's alone ─────────────
    # notes: ../AGENTS.md#restoring-a-file-that-was-never-in-penpot-leaves-penpot-alone

  @in-nextcloud @gesture
  Scenario Outline: Restore an untracked design file
    Given an untracked design file at "<path>"
    And the file is in the Nextcloud trash
    When I restore it from the trash
    Then the file is back at "<path>"
    And no design is restored in Penpot
    And the file holds no Penpot metadata at all

    Examples: outside every mapping, which is the only place one can still be
      | path                 |
      | Scratch/Loose.penpot |

    # ── RULE: a design coming back in Penpot brings its file with it ─────────

  @in-penpot @gesture
  Scenario: Restore a design in Penpot while its file is in the trash
    Given a design file named "Both Sides.penpot" in "Penpot/Stay Put"
    And the file is in the Nextcloud trash
    And its design is in Penpot's trash
    When someone restores the design in Penpot
    Then the file is back in "Penpot/Stay Put"
    And there is exactly one file for that design
    And the file holds:
      | penpot_id | the original id |

    # The trashed file is brought back rather than a second one written, because the
    # id names the file that already exists.

