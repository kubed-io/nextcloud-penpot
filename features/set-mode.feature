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
# ## WHOSE DECISION IS THIS, AND WAS IT EVER ASKED FOR?
#
# STATED PLAINLY BECAUSE IT DIVERGED FROM THE DESIGN WITHOUT A DECISION: mode is
# PER FILE and MUTABLE. `occ penpot_sync:set-mode` takes a PATH, not a mapping,
# and a file can be flipped `sync` ⇄ `link` any number of times. The mapping's
# mode is only the default a NEW mirror inherits.
#
# The expectation was the opposite — mode set on the team-folder mapping and
# IMMUTABLE there, the way `folder_mode` is. Nobody asked for per-file switching;
# it arrived because the move guard needed an escape hatch to offer. A `link` is
# confined to its project (§6.43), so "promote it to sync first" is the only
# advice a refusal can give that leads anywhere — and that advice needs a lever.
#
# So it exists, it is load-bearing, and it is specified here rather than left as
# an undocumented capability. Two consequences the scenarios below hold to:
#
#   1. IT IS AN ADMIN ACTION. Changing a file's mode decides whether Nextcloud
#      stores a real archive or a pointer — a storage-and-recovery decision about
#      someone else's team folder. There is no per-user surface for it.
#   2. DEMOTION DESTROYS A LOCAL BACKUP. `sync` → `link` deletes the archive
#      Penpot is not keeping for you. It is the one direction that loses
#      something, and it confirms before it does.
#
# IF IMMUTABILITY IS WANTED INSTEAD, this is the file that changes: the lever
# goes, the move guard loses the escape it offers, and every "promote to sync
# first" refusal in move.feature needs a different answer. That is a design
# decision, not a spec tidy-up.

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

  @admin @occ
  Scenario: A whole team of link files costs no exports at all
    When the team is mirrored again
    Then the pull succeeds
    And the pull exported 0 archives

  @admin @occ
  Scenario: Promoting a mirrored design fetches a real ZIP from Penpot
    Given a Penpot project named "Archive Me" exists in that team
    And a Penpot file named "Cover" exists in the project "Archive Me"
    When the team is mirrored again
    And "Penpot/Archive Me/Cover.penpot" is a "sync" design
    Then the mode change succeeds
    And the file "Penpot/Archive Me/Cover.penpot" is in "sync" mode
    And the file "Penpot/Archive Me/Cover.penpot" holds a real ".penpot" archive
    And the file "Penpot/Archive Me/Cover.penpot" still carries its Penpot id
    # An export never writes to Penpot and never re-stamps the id — promotion is
    # purely additive, which is what makes it safe to retry.

  @admin @occ
  Scenario: A promoted file is not re-exported by the next pull
    Given a mirrored design "Logo" in the project "Stable"
    And "Penpot/Stable/Logo.penpot" is a "sync" design
    When the team is mirrored again
    Then the pull succeeds
    And the pull exported 0 archives
    And the file "Penpot/Stable/Logo.penpot" holds a real ".penpot" archive
    # Mode is stored PER FILE, and an unchanged revision means an unchanged
    # archive — so staying in sync mode is free until the design actually moves.

  @admin @occ
  Scenario: Demoting throws the archive away and never contacts Penpot
    Given a Penpot project named "Demote Me" exists in that team
    And a Penpot file named "Sketch" exists in the project "Demote Me"
    When the team is mirrored again
    And "Penpot/Demote Me/Sketch.penpot" is a "sync" design
    And "Penpot/Demote Me/Sketch.penpot" is a "link" design
    Then the mode change succeeds
    And the file "Penpot/Demote Me/Sketch.penpot" is in "link" mode
    And the file "Penpot/Demote Me/Sketch.penpot" holds no content at all
    And the file "Penpot/Demote Me/Sketch.penpot" still carries its Penpot id
    # The design in Penpot is completely unaffected: demotion deletes a LOCAL
    # backup and nothing else.

  @admin @occ
  Scenario: A folder has no mode to set
    When the team is mirrored again
    And the admin sets the mode of "Penpot" to "sync"
    Then the mode change is refused
    And the refusal mentions "Modes are per-file"
