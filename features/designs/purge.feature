# Notes, decisions and history for this feature: ../AGENTS.md#designspurge

Feature: Emptying the trash
  As a Nextcloud user
  I want emptying the trash to finish the delete on both sides
  So that a purged file leaves nothing behind, and takes nothing else with it

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the purge finishes what the trashing started ────────────────────
    # notes: ../AGENTS.md#purging-a-mirror-from-the-nextcloud-trash-destroys-the-design

  @in-nextcloud @gesture
  Scenario Outline: Empty the trash
    Given a design file named "Gone For Good.penpot" in "<source>"
    And the file is in the Nextcloud trash
    And its design is in Penpot's trash
    When I purge it from the trash
    Then that file's design is permanently deleted from Penpot
    And the file is gone from the Nextcloud trash

    Examples: from either storage kind, because a purge is a purge
      | source          |
      | Penpot/Purge Me |
      | Shared/Purge Me |

    # The one irreversible thing this app can cause, reached only by the one
    # irreversible gesture Nextcloud offers.

    # Penpot needs no recycle-bin setting: it HAS a trash, so the trashing was
    # already reversible and this is the gesture that ends that.

    # ── RULE: the purge destroys only what it can still see in Penpot's trash ─
    # notes: ../AGENTS.md#a-purge-only-destroys-what-is-still-in-penpots-trash

  @in-nextcloud @gesture
  Scenario: Empty the trash when the design is not in Penpot's trash
    Given a design file named "Spared.penpot" in "Penpot/Purge Me"
    And the file is in the Nextcloud trash
    And its design is not in Penpot's trash
    When I purge it from the trash
    Then the file is gone from the Nextcloud trash

    # Restored over there, or erased over there — the purge cannot tell and does not
    # need to. Penpot's permanent delete would destroy a LIVE design if handed one.

    # ── RULE: destroying a design in Penpot purges its trashed mirror ─────────
    # notes: ../AGENTS.md#a-design-destroyed-in-penpot-purges-its-trashed-mirror

  @in-penpot @gesture
  Scenario Outline: Permanently delete a design in Penpot
    Given a design file named "Erased Upstream.penpot" in "<source>"
    And the file is in the Nextcloud trash
    And its design is in Penpot's trash
    When someone permanently deletes the design in Penpot
    Then the file is gone from the Nextcloud trash

    Examples: both storage kinds, because a Team Folder's trash is a different one
      | source          |
      | Penpot/Purge Me |
      | Shared/Purge Me |

    # The purge came from the other side, and it is the same purge: the design is
    # gone for good, so a trash entry offering to restore it offers nothing.

    # ── RULE: a file the app never mirrored is Nextcloud's alone ──────────────

  @in-nextcloud @gesture
  Scenario Outline: Purge an untracked design file
    Given an untracked design file at "<path>"
    And the file is in the Nextcloud trash
    When I purge it from the trash
    Then no design is deleted in Penpot
    And the file is gone from the Nextcloud trash

    Examples: outside every mapping, which is the only place one can still be
      | path                 |
      | Scratch/Loose.penpot |

    # ── RULE: a purge that cannot reach Penpot destroys nothing ───────────────

  @in-nextcloud @gesture
  Scenario: Empty the trash while Penpot is unreachable
    Given a design file named "Out Of Reach.penpot" in "Penpot/Purge Me"
    And the file is in the Nextcloud trash
    And its design is in Penpot's trash
    And Penpot is unreachable
    When I purge it from the trash
    Then no design is deleted in Penpot
    And the file is gone from the Nextcloud trash

    # Cannot prove the design is still in the trash, so do not destroy it. The
    # Nextcloud trash is emptied either way — that half is not ours to refuse.
