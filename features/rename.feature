# Rename — the ONE place saga §6.1's read-only stance is genuinely narrower than
# it sounds, and the two directions are in different states of confirmation
# (saga §6.2). The fork is STILL OPEN; do not resolve it here.
#
# PENPOT → NEXTCLOUD (confirmed, uncontroversial): covered by the same pull as
# any other change — the pull compares Penpot's current name against what's on
# disk and renames the Nextcloud file to match, keyed on "penpot_id". Free, in
# both modes, because the name comes back in the ordinary listing (saga §5.5) —
# no export needed to detect or apply it.
#
# NEXTCLOUD → PENPOT (open fork, saga §6.2 — NOT decided): `rename-file` is real,
# one field, and confirmed tagged WEBHOOK in the live /api/doc. Whether read-only
# extends to the FILENAME or only to CONTENT is unresolved. Renaming is a much
# smaller, safer surface than editing shape data, so it's plausible either way.
#
# WHAT DID GET DECIDED (saga §6.18): IF the fork is ratified, the attribution and
# failure behaviour are already settled — the rename uses the acting user's
# personal token when they have one (so Penpot's history names the human), falls
# back to the service account otherwise, and on failure the LOCAL rename stands
# while Penpot is left untouched. So the open question is narrowly "do we call it
# at all," not "how would it work." Both branches are written below; the second
# is tagged so CI skips it until a chapter ratifies the fork.
#
# WHOEVER RATIFIES SHOULD WEIGH THIS: saga §6.22 makes Penpot authoritative for a
# mirrored file's name. If NC→Penpot rename is NOT ratified, then renaming a
# mirrored file in the Files app is a no-op that silently reverts on the next
# pull — which is coherent, but needs to be TOLD to the user (see the scenario
# below), exactly like the in-mapping move case in move.feature.
#
# @todo — no lib/ exists yet for either direction.

@todo
Feature: Renaming a mirrored Penpot file
  As a Nextcloud user
  I want file names to reflect Penpot, and I want honesty about which direction is settled
  So that I never assume a rename propagates a direction this app hasn't committed to

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  # ── Penpot → Nextcloud: confirmed, this is how renames normally happen ───────

  Scenario: Renaming a file in Penpot renames the mirrored file on the next pull
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When the file is renamed to "New Name" in Penpot
    And a pull runs
    Then the mirrored file is renamed to "New Name.penpot"
    And its "penpot_id" metadata is unchanged

  Scenario: A rename is picked up in both modes, without an export
    Given a mirrored ".penpot" file in "link" mode
    When the file is renamed in Penpot and a pull runs
    Then the mirrored file is renamed
    And "export-binfile" was never called to detect or apply the rename

  # ── the settled behaviour if the user renames locally and the fork stays CLOSED ──

  Scenario: A local rename that does not propagate is reverted by the pull, visibly
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When I rename the file to "My Name.penpot" in the Files app
    Then the app explains that Penpot decides a mirrored file's name
    And it explains the name will revert on the next pull
    When a pull runs
    Then the file is named "Old Name.penpot" again
    And its "penpot_id" and content are unchanged
    # Not data loss — a name correction, same shape as the in-mapping move case
    # (move.feature). But correct behaviour still has to be VISIBLE behaviour.

  # ── Nextcloud → Penpot: the OPEN FORK. Do not assume either answer. ──────────
  # A future chapter must either ratify propagation via `rename-file` or reject
  # it in favour of keeping renames strictly one-way. These scenarios describe
  # what ratification WOULD mean; they are not committed behaviour.

  @todo
  Scenario: Whether renaming in Nextcloud propagates to Penpot is undecided
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When I rename the file to "New Name.penpot" in the Files app
    Then the rename is a real, simple RPC call away from propagating ("rename-file")
    But whether this app actually calls it is an open architectural fork (saga §6.2)
    And this scenario intentionally does not assert either outcome

  @todo
  Scenario: If ratified, a propagated rename is attributed to the acting user
    Given the fork in saga §6.2 has been ratified
    And the user has a valid personal Penpot token
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using that user's own token
    And Penpot attributes the rename to that user, not to the service account
    # This is the whole reason personal tokens exist (saga §6.18) — rename is one
    # one of the app's few write paths (saga §6.19), all of which attribute the
    # same way.

  @todo
  Scenario: If ratified, a propagated rename with no personal token uses the service account
    Given the fork in saga §6.2 has been ratified
    And the user has no personal Penpot token configured
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using the service-account token
    And the user is told the change was attributed to the service account

  @todo
  Scenario: If ratified, a failed propagation never reverts the user's local rename
    Given the fork in saga §6.2 has been ratified
    When the user renames a mirrored file and the Penpot call fails
    Then the Nextcloud file keeps its new name
    And Penpot is unchanged
    And the divergence is reported
    # Saga §6.18 rule 3 — a remote failure must never destroy local state.

  # ── the invariant, true under either branch ─────────────────────────────────

  Scenario: Renaming never breaks the Penpot link, regardless of direction
    Given a mirrored ".penpot" file with a known "penpot_id"
    When the file is renamed by any means
    Then the "penpot_id" metadata is unchanged
