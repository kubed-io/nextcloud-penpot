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
# ## TRASH, NEVER DESTROY — AND IT IS ASSERTED NOW, NOT PROMISED
#
# Nothing below hard-deletes. A pruned mirror is moved to the Nextcloud trash and
# stays recoverable for as long as the instance's retention allows.
#
# That used to be stated here and checked nowhere: the scenarios asserted "no
# node at that path", which a hard delete satisfies just as well. Every scenario
# that prunes now also asserts the file is IN the trash, including the one where
# Penpot has purged the design outright — the case where the mirror is the last
# copy in existence and the temptation to mirror Penpot's purge is strongest.
#
# ## AND THE RECONCILER ONLY EVER SEES VISIBLE FILES
#
# The other half of the same idea, and the one that keeps it simple: the pull
# walks the mapped folder's listing, which does not contain trashed files. So a
# mirror in the Nextcloud trash is not spared by a check — it is never looked at.
# Once a file is in the trash the reconciler is done with it, for good, whatever
# happens in Penpot afterwards. There is no cross-trash comparison anywhere in
# this app, and that is a design decision, not an omission.
#
# ## WHOSE TRASH, AND WHY IT MAY NOT BE YOURS (saga §C6.16)
#
# The pull runs as the account that owns the mapped folder — the service account
# on a shared Team Folder — so a pruned mirror lands in THAT account's trash.
# Nextcloud does this for every shared file, not just ours: the owner's delete
# fills the owner's trash. A member of the share looking in their own Files app
# sees the file vanish and finds nothing in their trash, which is what the
# promise above looks like from the other side of a share. It is documented in
# the README rather than worked around, because working around it means
# second-guessing Nextcloud's own sharing model.

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
    And the file "Penpot/Doomed/Farewell.penpot" is in the Nextcloud trash
    # THE CLAIM THIS SUITE EXISTS FOR. The mirror was a `link` — a pointer with no
    # bytes — and the design it pointed at is gone. Penpot's grace window is what
    # turns an unrecoverable deletion into a recoverable one, so the pointer
    # becomes a real archive on its way to the trash.
    #
    # THE LAST LINE IS NOT DECORATION. "No node at that path" is equally true of a
    # hard delete — the one outcome this must never produce — so for three courses
    # "trash, never destroy" was a promise in this file's header and an assertion
    # in none of its scenarios. It reached a user as *"the file left my folder and
    # I cannot find it in the trash"* before it reached a test.

  # ── the rule that has no exception ────────────────────────────────────────
  #
  # NEXTCLOUD NEVER PURGES A FILE BECAUSE PENPOT NO LONGER HAS IT. Emptying the
  # Nextcloud trash is the user's gesture and the user's alone — the pull has no
  # business reaching into it, whatever Penpot did.
  #
  # The tempting symmetry is to mirror Penpot's trash exactly: soft-deleted there
  # → trashed here, purged there → purged here. It is wrong, and the reason is
  # that the two trashes expire on their own schedules. Penpot's is ~7 days and
  # not configurable; a Nextcloud instance may keep its trash for thirty. Mirror
  # the purge and every design that ages out of Penpot's trash takes the user's
  # last copy with it — silently, on a schedule nobody chose, exactly when the
  # mirror has become the only copy that exists.

  Scenario: A design purged in Penpot still only reaches the Nextcloud trash
    Given a Penpot project named "Erased" exists in that team
    And a Penpot file named "No Way Back" exists in the project "Erased"
    When the admin runs a pull
    And the design "No Way Back" is permanently deleted in Penpot
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned 1 mirror
    And there is no node at "Penpot/Erased/No Way Back.penpot"
    And the file "Penpot/Erased/No Way Back.penpot" is in the Nextcloud trash
    # The design is gone from every Penpot listing AND from its trash, so nothing
    # about it can ever come back — and the mirror is still only trashed. This is
    # the case where the local file is genuinely the last copy of that design,
    # which is precisely why it must land somewhere recoverable.
    #
    # NOTHING IS ASSERTED ABOUT THE FINAL ARCHIVE HERE, and the reason is a live
    # finding (saga §C6.16): `permanently-delete-team-files` returns before the
    # data is actually gone. Penpot marks the rows and a worker removes them
    # later — so `export-binfile` can still succeed for seconds afterwards, and
    # this scenario really did save an archive for a design that had been
    # permanently deleted. Whether the snapshot lands is Penpot's timing, not our
    # behaviour; the assertion this file cares about is where the mirror ends up.
    # The truly-past-the-window case is the "cannot be recovered" scenario in
    # reconcile.feature, which does not depend on winning a race.

  # ── the reconciler's field of view: VISIBLE FILES, and nothing else ───────
  #
  # THE RULE THAT MAKES THE ONE ABOVE SIMPLE. The reconciler walks the mapped
  # folder's directory listing, so a mirror already in the Nextcloud trash is not
  # merely spared — it is **not seen at all**. Once a file reaches the trash the
  # pull is finished with it, permanently, whatever Penpot does next.
  #
  # State this as a rule and a whole class of question stops existing. "Both
  # trashes hold it and then Penpot purges — now what?" has no answer to design,
  # because the reconciler was never looking. There is no third state to
  # reconcile, no cross-trash comparison, and no schedule on which the app can
  # take a user's last copy away.
  #
  # THE PRICE, NAMED: a design that comes back in Penpot while its old mirror
  # sits in the Nextcloud trash gets a NEW mirror, beside the trashed one — the
  # pull cannot re-adopt what it cannot see. reconcile.feature specifies that
  # adoption and it is not built; this rule is why it is a deliberate open
  # question rather than an oversight.

  Scenario: A mirror already in the Nextcloud trash is invisible to the pull
    Given a Penpot project named "Left Alone" exists in that team
    And a Penpot file named "Twice Dead" exists in the project "Left Alone"
    When the admin runs a pull
    And I delete "Penpot/Left Alone/Twice Dead.penpot"
    And the design "Twice Dead" is purged from Penpot's trash
    And the admin runs a pull
    Then the pull succeeds
    And the pull pruned nothing
    And the file "Penpot/Left Alone/Twice Dead.penpot" is in the Nextcloud trash
    # THE SEQUENCE THE RULE EXISTS FOR, end to end: the user deletes the mirror
    # (which puts the design in Penpot's trash), then the design is destroyed in
    # Penpot for good. Both sides are now gone in their own way — and the pull
    # does nothing at all, because a trashed mirror was never in its field of
    # view. "Pruned nothing" is the whole assertion: not "pruned it gently".

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
