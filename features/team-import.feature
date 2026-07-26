# SPECULATIVE — this file documents a PROPOSAL (saga §6.15), not a locked
# design, and it directly touches an open architectural fork. Do not read any
# scenario below as decided behaviour; every one of them is written to make the
# open questions visible, the same "design, not wired" convention both sibling
# apps use for their own undecided slices (compare Grafana's tag-sync.feature
# header, or n8n's speculative-fork write-ups).
#
# THE PROPOSAL: from wherever a user's Penpot token is configured
# (personal-settings.feature), query that account's visible Penpot teams, show
# which already correspond to an existing Nextcloud Team Folder and which
# don't, and let the user opt to "import" one — provisioning a Team Folder that
# becomes a team mapping (admin-mapping.feature). This reuses §6.13's
# ownership-pill/tag mechanism for a SECOND purpose: pulling FROM Penpot, a
# project becomes/matches a same-named subfolder by ordinary name-matching
# (fine, because §6.13 already locked mapped-folder naming as
# Penpot-authoritative on the pull direction — see admin-mapping.feature). Going
# the OTHER way — a user makes a plain folder inside a mapped Team Folder and
# wants it to BECOME a new Penpot project — name-matching alone can't
# disambiguate "this is meant to be a project" from "this is just reference
# material sitting in the folder" (§6.13's tolerated-content rule), so the
# proposal is a dedicated app-owned tag as the creation signal: tag present ⇒
# create the project in Penpot (via `create-project`, confirmed real in §6.5) on
# the next pull cycle; tag absent ⇒ ordinary tolerated content, untouched.
#
# WHY THIS IS STILL OPEN, NOT LOCKED (do not resolve these here):
#
#   1. THIS REOPENS §6.1, NOT JUST EXTENDS IT (saga §6.7/§6.15). §6.1 locked
#      Nextcloud as read-only — no writeback, no Nextcloud-originated content.
#      "New Penpot project from a tagged Nextcloud folder" is Nextcloud
#      ORIGINATING a Penpot object. That's not disqualifying, but it means this
#      proposal is really asking for a narrower carve-out than blanket
#      read-only: existing files/projects stay strictly read-only; CREATION
#      would be a distinct, separately-decided path. Nothing in this app should
#      treat that carve-out as granted until a saga chapter says so explicitly.
#
#   2. TEAM FOLDER CREATION PERMISSIONS (saga §6.15, the one genuinely NEW open
#      point raised by this section specifically): Team Folders are
#      admin-configured by default; a non-admin, non-delegated user checking an
#      "import as Team Folder" box has nothing behind that checkbox to act on,
#      on this cluster today. Whether the UI greys the box out, routes to an
#      admin-approval step, or something else is explicitly undecided.
#
#   3. `import-binfile` IS NOW CONFIRMED WORKING (saga §6.20 — open question #6
#      closed). Both the create-new and in-place variants were exercised live.
#      So this fork is no longer blocked on "does the mechanism exist"; it's
#      blocked purely on the §6.1 policy question in point 1. Three practical
#      facts came out of that testing and apply here: the call is SSE, its params
#      are kebab-case (`project-id`, not `projectId`), and its `name` parameter
#      is IGNORED — an imported file takes the name from its archive manifest, so
#      any create path needs a follow-up `rename-file`.
#
#   4. THE SERVICE ACCOUNT MUST ALREADY BE ON THE TEAM (saga §6.18, new since
#      this file was written): a team can't be mapped at all unless the service
#      account holds a `viewer` invite. That changes this feature's framing —
#      "import a team I can see" is really "import a team BOTH I and the service
#      account can see." A user's personal token showing them a team is not
#      sufficient for it to be importable, and the UI must say which of the two
#      is missing rather than just failing.
#
# CI SKIPS THIS ENTIRE FILE. Nothing here should be implemented against until a
# future saga chapter either ratifies §6.7/§6.15's creation carve-out or
# explicitly rejects it in favour of the plainer "map only what already exists
# in Penpot" shape (admin-mapping.feature).

@todo
Feature: Importing an existing Penpot team as a Team Folder, and the open question of Nextcloud-originated projects
  As a Nextcloud user with a configured Penpot token
  I want to see which of my Penpot teams are already mapped and import the ones that aren't
  So that connecting a new team doesn't require the admin to hand-configure every mapping
  # NOTE: only the IMPORT-AN-EXISTING-TEAM half of this feature is proposed as
  # buildable-once-ratified; the tag-triggers-project-CREATION half is
  # additionally gated on the still-open §6.1 read-only-scope question above.

  Background:
    Given the app is connected to Penpot
    And the user has a personal Penpot token configured

  # ── the "already imported, shows up automatically" half — confirmed workable ──
  # Confirmed against the groupfolders README + live behaviour (saga §6.15): a
  # Team Folder "shows up in the home folder for each user in the configured
  # groups" automatically once granted — there's no separate pending state to
  # build. So detecting "is this already imported" is a read-only match, not a
  # grant action.
  Scenario: A Penpot team already mapped to a Team Folder is detected, not re-imported
    Given the Penpot team "Ferronescotia" is already mapped to a Team Folder
    And the user's Nextcloud group has access to that Team Folder
    When the user views their Penpot teams in personal settings
    Then "Ferronescotia" is shown as already imported
    And no new folder or mapping is created

  # ── importing a NOT-yet-mapped team — the permission gate is the open point ──
  Scenario: Importing an unmapped team as a Team Folder requires Team Folder rights
    Given the Penpot team "New Team" is visible to the user's token but not yet mapped
    And the acting user does not hold Team Folder admin or delegated rights
    When the user tries to import "New Team" as a Team Folder
    Then the import is refused or routed to an admin approval step
    And the UI explains that Team Folder creation is admin-configured by default
    # Which of "refused with an explanation" vs "routed to an admin step" is
    # correct is explicitly undecided (saga §6.15) — this scenario only asserts
    # that the checkbox is NOT allowed to silently no-op or silently succeed
    # for a user who lacks the underlying permission.

  # ── the OTHER gate, new since saga §6.18: service-account visibility ────────
  # A user seeing a team through their personal token is NOT sufficient. The
  # service account does all mirroring, so it must be able to see the team too,
  # or the resulting mapping would pull nothing forever.
  Scenario: A team the service account cannot see is shown as not importable
    Given the Penpot team "Solo Team" is visible to the user's personal token
    But the service account has not been invited to "Solo Team"
    When the user views their Penpot teams in personal settings
    Then "Solo Team" is listed but marked as not importable
    And the UI explains the service account must be invited as "viewer" first
    And it names which of the two prerequisites is missing
    # Two distinct gates now exist — Team Folder rights and service-account
    # visibility. Failing to say WHICH one blocked the import turns a fixable
    # setup step into a mystery.

  # ── the speculative, explicitly-not-decided creation-via-tag mechanism ──────
  @todo
  Scenario: A tagged plain folder inside a mapped Team Folder is proposed to become a new Penpot project
    Given a Team Folder mapped to the Penpot team "Ferronescotia"
    And a plain, untagged subfolder created directly inside it
    Then that subfolder is ordinary tolerated content — nothing happens to it
    # This is the confirmed, locked behaviour (§6.13's tolerated-content rule).
    When the app's project-creation tag (name TBD) is applied to that subfolder
    Then this is PROPOSED to make the next pull call "create-project" in Penpot
    But whether this app may ever originate a Penpot project this way is an open fork against §6.1
    And this scenario intentionally does not assert that Penpot is contacted
