# Pruning a mirror whose design is gone — the live half of reconcile.feature's
# most dangerous section.
#
# THE DESIGN LIVES IN reconcile.feature. That file is the full, still-@todo
# specification of the reconciler: trash adoption, Penpot-side restores, the
# ignore marker. THIS file is the subset CI can prove today, driven entirely
# through `occ` against a real Nextcloud and a real Penpot.
#
# ## WHY THIS SUITE IS WORTH A LIVE PENPOT — AND IT IS NOT THE WIRE
#
# Every other live suite here exists because the wire format is unmockable. This
# one exists because of a claim about PENPOT'S OWN BEHAVIOUR (saga §6.42):
#
#   `export-binfile` still exports a design that has already been deleted, for
#   as long as Penpot's own trash holds it.
#
# The entire rescue path is built on that sentence. A mocked export would hand
# back bytes for a design that never existed and prove exactly nothing — so the
# sentence is asserted against a real Penpot, on a design this suite really
# deleted.
#
# ## THE NEGATIVE HALF IS THE MORE IMPORTANT ONE
#
# Pruning is driven by "Penpot did not name this file", and every way of failing
# to ask — a 502, a project skipped for an illegal name, a half-read listing —
# is indistinguishable from a deletion. A regression there does not throw; it
# quietly moves a team's mirrors to the trash on the next scheduled run. So "a
# pull that changed nothing pruned nothing" is asserted as a step rather than
# assumed from the happy path.
#
# ## TRASH, NEVER DESTROY
#
# Nothing below hard-deletes. A pruned mirror is moved to the Nextcloud trash and
# stays recoverable for as long as the instance's retention allows — which is why
# the assertion is "no node at that path", not "the file no longer exists".

@prune
Feature: Pruning mirrors of designs Penpot no longer has
  As an operator who has mapped a Penpot team
  I want a design deleted in Penpot to stop haunting my Files app
  So that the mirror stays honest without ever losing something I might want back

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

  Scenario: A pull that changed nothing prunes nothing
    Given a Penpot project named "Untouched" exists in that team
    And a Penpot file named "Poster" exists in the project "Untouched"
    When the admin runs a pull
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned nothing
    # The safety property, asserted first because it is the one a regression
    # breaks silently. Pruning on a listing that simply did not mention a file is
    # the single most destructive thing this app could do.

  Scenario: A design deleted in Penpot is snapshotted, then moved to the trash
    Given a Penpot project named "Doomed" exists in that team
    And a Penpot file named "Farewell" exists in the project "Doomed"
    When the admin runs a pull
    And the design "Farewell" is deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 1 final archive
    And there is no node at "Penpot/Doomed/Farewell.penpot"
    # THE CLAIM THIS SUITE EXISTS FOR. The mirror was a `link` — a pointer with no
    # bytes — and the design it pointed at is gone. Penpot's grace window is what
    # turns an unrecoverable deletion into a recoverable one, so the pointer
    # becomes a real archive on its way to the trash.

  Scenario: A design that already had its archive needs no second export
    Given a Penpot project named "Kept" exists in that team
    And a Penpot file named "Backup" exists in the project "Kept"
    When the admin runs a pull
    And the admin promotes "Penpot/Kept/Backup.penpot" to "sync" mode
    And the design "Backup" is deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And the pull saved 0 final archives
    And the pull exported 0 archives
    # A `sync` file is already its own snapshot. Re-exporting it would download a
    # whole archive to replace an identical one — and would fail for exactly the
    # files most worth keeping, once the grace window closes.
