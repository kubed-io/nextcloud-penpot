# Notes, decisions and history for this feature: ../AGENTS.md#designsdelete

Feature: Trashing a design
  As a Nextcloud user
  I want the trash to mean the same thing on both sides
  So that removing a file never loses a design and never silently desyncs

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a delete is soft on both sides ──────────────────────────────────
    # notes: ../AGENTS.md#deleting-a-mirror-moves-the-design-into-penpots-trash

  @in-nextcloud @gesture
  Scenario Outline: Trash a design
    Given a design file named "Doomed.penpot" in "<source>"
    When I move it to the trash
    Then the design "Doomed" is in Penpot's trash
    And the "<project>" Penpot project holds no design named "Doomed"
    And the file is recoverable from the Nextcloud trash

    Examples: from either storage kind, because trashing is trashing
      | source        | project |
      | Penpot/Bin Me | Bin Me  |
      | Shared/Bin Me | Bin Me  |

    # Both sides are soft, and the design keeps its id, revision and history — which
    # is what makes this safe to do without asking.

    # Penpot needs no recycle-bin setting: it HAS a trash, so a delete is already
    # reversible there. The siblings bolt one on because their services have none.

  @in-nextcloud @gesture
  Scenario: Trash a design that is already gone from Penpot
    Given a design file named "Twice Dead.penpot" in "Penpot/Left Alone"
    And its design is permanently deleted in Penpot
    When I move it to the trash
    Then the design "Twice Dead" is not in Penpot's trash
    And the file is recoverable from the Nextcloud trash

    # Being asked to delete something already deleted is not a problem — it is the
    # outcome the user wanted, so it is not reported as an error.

    # ── RULE: a link is read-only, so it is not deleted from this side ────────
    # notes: ../AGENTS.md#a-link-is-never-deleted-from-nextcloud

  @in-nextcloud @gesture
  Scenario: Trash a link
    Given a design file named "Pointer.penpot" in "Pointers/Confined"
    When I try to move it to the trash
    Then the trash is refused with a message
    And the file stays in "Pointers/Confined"
    And the design still exists in Penpot

    # A link is Penpot's copy to remove, so the guard refuses the delete outright
    # rather than hiding the file — #38 built that, and this proves it.

    # ── RULE: a file the app never mirrored is Nextcloud's alone ──────────────
    # notes: ../AGENTS.md#deleting-an-untracked-penpot-file-leaves-penpot-alone

  @in-nextcloud @gesture
  Scenario Outline: Trash an untracked design file
    Given an untracked design file at "<path>"
    When I move it to the trash
    Then no design is deleted in Penpot
    And the file is recoverable from the Nextcloud trash
    And it still holds no Penpot metadata

    Examples: outside every mapping, which is the only place one can still be
      | path                    |
      | Scratch/Not Ours.penpot |

    # ── RULE: a design deleted in Penpot takes its mirror to the trash ────────
    # notes: ../AGENTS.md#a-design-deleted-in-penpot-is-snapshotted-then-moved-to-the-trash

  @in-penpot @gesture
  Scenario: Delete a design in Penpot
    Given a design file named "Farewell.penpot" in "Penpot/Doomed"
    When someone deletes the design in Penpot
    Then the design "Farewell" is in Penpot's trash
    And the file is gone from "Penpot/Doomed"
    And the file is recoverable from the Nextcloud trash

    # However the design went over there — trashed or erased — its mirror only ever
    # reaches OUR trash, where the user decides whether it stays.

    # ── RULE: a trash that cannot finish leaves the file where it was ─────────

  # notes: ../AGENTS.md#a-trash-penpot-cannot-take-is-not-aborted-today
  # @unbuilt — the local delete stands and the failure is logged, never aborted.
  @in-nextcloud @gesture @unbuilt
  Scenario: Trash a design while Penpot is unreachable
    Given a design file named "Doomed.penpot" in "Penpot/Offline"
    And Penpot is unreachable
    When I try to move it to the trash
    Then the trash is aborted and the file stays in "Penpot/Offline"
    And the file keeps its Penpot metadata
