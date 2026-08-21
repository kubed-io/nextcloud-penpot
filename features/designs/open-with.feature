# Notes, decisions and history for this feature: ../AGENTS.md#designsopen-with

# @blocked throughout — no browser. Every scenario here asserts a context-menu
# entry or what a click does, which this harness cannot reach.
# notes: ../AGENTS.md#designsopen-with
@blocked
Feature: Opening a design file
  As a Nextcloud user
  I want a design file to take me to the live design in Penpot
  So that the file in Nextcloud and the work in Penpot are one click apart

  Background:
    Given the app is connected to Penpot

    # ── RULE: a design file names a live design, whatever its mode ────────────
    # notes: ../AGENTS.md#opening-needs-a-team-id-so-an-unmapped-file-opens-nothing

  @in-nextcloud @gesture @ui
  Scenario Outline: Open in Penpot is offered for a mirrored design file
    Given a design file in "<mode>" mode
    When I look at its context menu
    Then "Open in Penpot" is offered

    Examples: both modes point at the same live design, and neither is a special case
      | mode |
      | sync |
      | link |

  @in-nextcloud @gesture @ui
  Scenario Outline: A plain click opens the design in Penpot
    Given a design file in "<mode>" mode
    When I click the file in the Files app
    Then the design opens in Penpot

    Examples: the default click for the design file type, in either mode
      | mode |
      | sync |
      | link |

    # ── RULE: the opener needs an instance and a single file ──────────────────

  @in-nextcloud @gesture @ui
  Scenario: Open in Penpot is hidden until an instance is configured
    Given the admin has not set the Penpot base URL
    And a design file in "sync" mode
    When I look at its context menu
    Then "Open in Penpot" is hidden

  @in-nextcloud @gesture @ui
  Scenario: Open in Penpot is hidden for a selection of several files
    Given two design files in "sync" mode
    When I look at their context menu
    Then "Open in Penpot" is hidden

    # One click opens one design, so a selection has no answer to give.

    # ── RULE: a file the app does not track opens nothing, quietly ────────────
    # notes: ../AGENTS.md#an-untracked-design-file-opens-nothing-rather-than-failing

  @in-nextcloud @gesture @ui
  Scenario: Click a design file the app does not track
    Given an untracked design file at "Scratch/Loose.penpot"
    When I click the file in the Files app
    Then nothing opens
    And no error is shown

    # The action is offered on the file TYPE, not on the id — a listing can arrive
    # before the DAV property does, and an action that flickers is worse than a no-op.

