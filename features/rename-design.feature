# Renaming a DESIGN — the mirror file and the Penpot file it points at.
# Renaming a PROJECT (the folder) is rename-project.feature: same gesture, but a
# different Penpot object, a different RPC, and a different set of name rules.
#
# Rename is the ONE place saga §6.1's read-only stance is genuinely narrower than
# it sounds. BOTH DIRECTIONS ARE SETTLED (saga §6.54 closed the §6.2 fork).
#
# PENPOT → NEXTCLOUD: covered by the same pull as any other change — the pull
# compares Penpot's current name against what's on disk and renames the Nextcloud
# file to match, keyed on "penpot_id". Free in both modes, because the name comes
# back in the ordinary listing (saga §5.5) — no export needed.
#
# NEXTCLOUD → PENPOT: RATIFIED (saga §6.54). `rename-file` works — HTTP 200,
# returning {id, name, created-at, modified-at}. Read-only was always about
# CONTENT (shape data we cannot round-trip), not about a one-field name.
#
# WHY IT WAS RATIFIED, briefly:
#   - §6.36 already locked that renaming a PROJECT propagates. Leaving files as a
#     silent no-op made one gesture behave two ways in one Files app.
#   - §6.22 makes Penpot authoritative for a mirrored file's name — so NOT
#     propagating means a user's rename silently REVERTS on the next pull, the
#     exact failure mode this app exists to avoid.
#
# THE PARAM TRAP (saga §6.54): `rename-file` takes the id under plain **`id`**,
# NOT `file-id`. Confirmed live: `file-id` returns HTTP 400 :params-validation.
# Fourth distinct param convention in this API — PenpotClient needs an explicit
# per-command table, not a rule.
#
# BUILD STATE: the NEXTCLOUD → PENPOT path is built and unit-tested, verified live
# on the pod. These stay @todo only because the harness is occ-only — no
# Files-app / WebDAV channel to fire a real NodeRenamedEvent, and no logged-in
# session for the personal-token branch. They flip when that channel lands.

Feature: Renaming a mirrored Penpot design
  As a Nextcloud user
  I want renaming a design file to rename the design in Penpot, and vice versa
  So that one name means one thing on both sides
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped to the folder "Penpot"

  @in-nextcloud @gesture
  Scenario: Renaming a mirrored file renames its design in Penpot
    Given a mirrored design "Old Name" in the project "Rename Live"
    When I rename "Penpot/Rename Live/Old Name.penpot" to "New Name.penpot"
    Then Penpot project "Rename Live" holds a design named "New Name"
    And Penpot project "Rename Live" holds no design named "Old Name"
    # Penpot's name never carries the ".penpot" extension (§6.4) — the assertion
    # is on "New Name", not "New Name.penpot", and that is the whole rule.

  @todo
  Scenario: Renaming a file in Penpot renames the mirrored file on the next pull
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When the file is renamed to "New Name" in Penpot
    And a pull runs
    Then the mirrored file is renamed to "New Name.penpot"
    And its "penpot_id" metadata is unchanged

  @todo
  Scenario: A rename is picked up in both modes, without an export
    Given a mirrored ".penpot" file in "link" mode
    When the file is renamed in Penpot and a pull runs
    Then the mirrored file is renamed
    And "export-binfile" was never called to detect or apply the rename

    # ── Nextcloud → Penpot: RATIFIED (saga §6.54) ───────────────────────────────
    # The fork is closed: renaming a mirrored file in the Files app DOES propagate,
    # via "rename-file". §6.1's read-only stance was always about CONTENT — shape
    # data we cannot meaningfully round-trip — not about a one-field name.

  @todo
  Scenario: Renaming a mirrored file in Nextcloud renames the Penpot file
    Given a mirrored ".penpot" file for a Penpot file named "Old Name"
    When I rename the file to "New Name.penpot" in the Files app
    Then "rename-file" is called with the file's Penpot id and "New Name"
    And the Penpot file is named "New Name"
    And the ".penpot" extension is stripped before sending and re-added locally
    And the file's "penpot_id" is unchanged
    # The extension is a Nextcloud-side affordance (saga §6.4) — Penpot's own
    # name never carries it. This is the one thing file rename does that project
    # rename does not (project folder names are bare).

  @todo
  Scenario: The rename call sends the file id under the plain "id" parameter
    When a mirrored file is renamed and the rename propagates
    Then "rename-file" is called with the id under the key "id"
    And not under "file-id"
    # Confirmed live (saga §6.54): {"file-id": ...} returns HTTP 400
    # :params-validation with missing-key [:id]. There is no inferable casing
    # rule across this API — only a per-command table. See saga open question #21.

  @blocked
  Scenario: A propagated rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using that user's own token
    And Penpot attributes the rename to that user, not to the service account
    # This is the whole reason personal tokens exist (saga §6.18) — rename is one
    # of the app's few write paths (saga §6.19), all of which attribute the same
    # way.

  @blocked
  Scenario: A propagated rename with no personal token uses the service account
    Given the user has no personal Penpot token configured
    When the user renames a mirrored file in the Files app
    Then "rename-file" is called using the service-account token
    And the user is told the change was attributed to the service account

  @todo
  Scenario: A failed propagation never reverts the user's local rename
    When the user renames a mirrored file and the Penpot call fails
    Then the Nextcloud file keeps its new name
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # Saga §6.18 rule 3 — a remote failure must never destroy local state.

    # ── the name guard: the same shape at both levels ───────────────────────────
    # THE GUARD RUNS BACKWARDS FROM EXPECTATION (saga §6.38). Penpot accepts
    # essentially any non-empty string up to 250 characters — confirmed live:
    # upper case, lower case, emoji, dots, leading spaces, and even "Has/Slash"
    # all create fine. NEXTCLOUD is the stricter side. So going out, the only
    # real check is non-empty and 250; coming in, a name Nextcloud cannot spell
    # as a folder is the problem, which is the "/" section below.

  @todo
  Scenario: An empty file name is refused before it is sent
    When I try to rename a mirrored file to a name that is empty once the extension is stripped
    Then the rename is refused with an explanation
    And Penpot is never contacted
    # Penpot enforces this too — [:string {:min 1, :max 250}], confirmed live to
    # return HTTP 400 on "" (saga §6.54). Our guard is a better message and a
    # saved round trip, not the only defence.

  @todo
  Scenario: A file name longer than Penpot allows is refused before it is sent
    When I try to rename a mirrored file to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  @todo
  Scenario: In nested mode, a Penpot file whose name contains a slash is skipped with a clear reason
    Given the mapping's folder mode is "nested"
    And a Penpot file named "Has/Slash"
    When the pull runs
    Then no file is created for it
    And the admin is told the file cannot be mirrored because "/" is not allowed in a file name
    And the message names the file so it can be renamed in Penpot
    And every other file in the same project is mirrored normally
    # Penpot accepts "/" in a FILE name exactly as it does in a project name —
    # confirmed live, HTTP 200 (saga §6.54). So the §6.51 guard applies at both
    # levels, with the same refuse-and-report rule and a narrower blast radius:
    # one file skipped, not a whole subtree.

    # ── "/" IN A PROJECT NAME: INVALID IN NESTED MODE (saga §6.53) ──────────────
    # The project-level twin of the scenario above, and the wider blast radius —
    # a project that cannot be spelled as a folder takes its whole file list with
    # it. Everything here is scoped to `nested` mode, the default, where Nextcloud
    # nests freely and a "/" in a project name would mean nothing. In `keyed` mode
    # a "/" is not an error at all: it IS the path, which is the whole point of
    # making folder mode a per-mapping choice (admin-mapping.feature).
    #
    # Checked live against Nextcloud's IFilenameValidator: the ONLY forbidden
    # characters are "\" and "/" (plus ".."/"." as segments, ".htaccess", and the
    # .part/.filepart extensions). Everything else — "a:b", "a*b", "CON",
    # ".hidden" — is a perfectly legal folder name. So this is a two-character
    # problem, not a general sanitisation problem.
    #
    # THE APP REJECTS IT AT THE SOURCE where it can: it owns project creation
    # (create-project.feature's tag opt-in) and project renames (§6.36), so a "/"
    # never enters Penpot through this app in nested mode. What is left is the
    # only case it cannot reach — a name typed directly in Penpot's own UI.

  @in-nextcloud @gesture
  Scenario: Renaming never breaks the Penpot link
    Given a mirrored design "Before" in the project "Keeps Its Id"
    When I rename "Penpot/Keeps Its Id/Before.penpot" to "After.penpot"
    Then the file "Penpot/Keeps Its Id/After.penpot" still carries its Penpot id
    And Penpot project "Keeps Its Id" holds a design named "After"
    # The invariant under every rename path: the name changes, the identity does
    # not. A rename that re-created the design would break every mirror, archive
    # and deep link that points at it.

    # ── renaming something that was just created by another gesture ───────────

  @in-nextcloud @gesture
  Scenario: Renaming a design that was just copied propagates to Penpot
    Given a mirrored design "Original" in the project "Copy Then Rename"
    And I copy "Penpot/Copy Then Rename/Original.penpot" to "Penpot/Copy Then Rename/Duplicate.penpot"
    When I rename "Penpot/Copy Then Rename/Duplicate.penpot" to "Renamed Copy.penpot"
    Then Penpot project "Copy Then Rename" holds a design named "Renamed Copy"
    And Penpot project "Copy Then Rename" holds a design named "Original"
    And the files "Penpot/Copy Then Rename/Original.penpot" and "Penpot/Copy Then Rename/Renamed Copy.penpot" carry different Penpot ids
    # WALKED BY HAND, AND IT FAILED — but not here. The copy had silently failed
    # to record its "penpot_id", so this rename correctly ignored an untracked
    # file and looked like the bug (saga §C6.9). Kept in rename-design.feature as well
    # as copy-design.feature on purpose: the symptom appeared at THIS gesture, so this
    # is where someone will come looking.

  @in-nextcloud @gesture
  Scenario: Renaming an untracked ".penpot" file is not a failure
    Given a mirrored project "Untracked Rename"
    And I upload a ".penpot" archive at "Penpot/Untracked Rename/Dragged In.penpot"
    When I rename "Penpot/Untracked Rename/Dragged In.penpot" to "Renamed Anyway.penpot"
    Then the file "Penpot/Untracked Rename/Renamed Anyway.penpot" carries no Penpot id
    And Penpot project "Untracked Rename" holds no design named "Renamed Anyway"
    # This is correct behaviour and must stay — a file we do not track is not
    # ours to rename anywhere. It is also indistinguishable, from the user's
    # side, from the bug above. That is exactly why the tracking failure has to
    # be loud where it happens.
