# Promoting and demoting a single file — the live half of sync-mode.feature.
#
# THE DESIGN LIVES IN sync-mode.feature. That file is the full, still-@todo
# specification of the mode: what each mode is, what a link may not do, what the
# Files-app surface will offer. THIS file is the subset CI can prove today,
# driven entirely through `occ` against a real Nextcloud and a real Penpot.
#
# ## WHY THIS SUITE IS WORTH A LIVE PENPOT
#
# Promotion is the app's only code path that moves real bytes out of Penpot, and
# it is four unmockable steps in a row (saga §5.1–§5.4, §C4.8):
#
#   1. POST `export-binfile` — the response is an **SSE stream**, not JSON;
#   2. the stream's `end` event carries a Transit **tagged map**, `{"~#uri": …}`,
#      a form the decoder originally mistook for plain JSON;
#   3. that URI needs a **second authenticated GET** to a different path entirely;
#   4. and only then are there ZIP bytes.
#
# Every one of those was discovered by watching a real instance rather than by
# reading its source, and every one would happily pass a mocked test while
# failing on the wire — a proxy that buffers the stream, an event that gets
# renamed, or an asset URL unreachable from inside the cluster (§5.3: an nginx
# resolver bug made exactly this fetch 502 while the export itself "succeeded").
#
# So the assertion below is deliberately crude and physical: after a promotion
# the mirrored file **begins with a ZIP's magic bytes**. Not "the client was
# called" — a ZIP arrived.
#
# ## THE CHEAP PATH IS ASSERTED TOO, BECAUSE IT IS THE WHOLE POINT
#
# `link` mode's entire claim is that mirroring a team costs a listing and
# nothing else. A regression that quietly exported every file would still pass
# every other scenario in this suite, and would first be noticed as a bandwidth
# bill — so the zero is asserted, not assumed.
#
# ## NOT ASSERTED HERE, ON PURPOSE
#
# The interactive confirmation on a demotion: Behat has no tty to answer it. The
# prompt is unit-tested where the answer can be scripted (SetModeTest); the steps
# here pass `--force` and assert the CONSEQUENCE instead — the archive is gone,
# the file is empty again, and Penpot was never contacted.

  @sync-mode
Feature: Storing and discarding a mirrored design's archive
  As an operator who has mapped a Penpot team
  I want `occ penpot_sync:set-mode` to decide which designs are really backed up
  So that important work is preserved without paying to store everything

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

  Scenario: A whole team of link files costs no exports at all
    When the admin runs a pull
    Then the pull succeeds
    And the pull exported 0 archives

  Scenario: Promoting a mirrored design fetches a real ZIP from Penpot
    Given a Penpot project named "Archive Me" exists in that team
    And a Penpot file named "Cover" exists in the project "Archive Me"
    When the admin runs a pull
    And "Penpot/Archive Me/Cover.penpot" is a "sync" design
    Then the mode change succeeds
    And the file "Penpot/Archive Me/Cover.penpot" is in "sync" mode
    And the file "Penpot/Archive Me/Cover.penpot" holds a real ".penpot" archive
    And the file "Penpot/Archive Me/Cover.penpot" still carries its Penpot id
    # An export never writes to Penpot and never re-stamps the id — promotion is
    # purely additive, which is what makes it safe to retry.

  Scenario: A promoted file is not re-exported by the next pull
    Given a Penpot project named "Stable" exists in that team
    And a Penpot file named "Logo" exists in the project "Stable"
    When the admin runs a pull
    And "Penpot/Stable/Logo.penpot" is a "sync" design
    And the team has been mirrored into Nextcloud
    Then the pull succeeds
    And the pull exported 0 archives
    And the file "Penpot/Stable/Logo.penpot" holds a real ".penpot" archive
    # Mode is stored PER FILE, and an unchanged revision means an unchanged
    # archive — so staying in sync mode is free until the design actually moves.

  Scenario: Demoting throws the archive away and never contacts Penpot
    Given a Penpot project named "Demote Me" exists in that team
    And a Penpot file named "Sketch" exists in the project "Demote Me"
    When the admin runs a pull
    And "Penpot/Demote Me/Sketch.penpot" is a "sync" design
    And "Penpot/Demote Me/Sketch.penpot" is a "link" design
    Then the mode change succeeds
    And the file "Penpot/Demote Me/Sketch.penpot" is in "link" mode
    And the file "Penpot/Demote Me/Sketch.penpot" holds no content at all
    And the file "Penpot/Demote Me/Sketch.penpot" still carries its Penpot id
    # The design in Penpot is completely unaffected: demotion deletes a LOCAL
    # backup and nothing else.

  Scenario: A folder has no mode to set
    When the admin runs a pull
    And the admin sets the mode of "Penpot" to "sync"
    Then the mode change is refused
    And the refusal mentions "Modes are per-file"
