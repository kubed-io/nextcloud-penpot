# The per-Nextcloud-user personal Penpot token page — genuinely NEW territory for
# this app family. Neither n8n nor Grafana has a per-user settings precedent:
# both store one admin-wide credential (IAppConfig, `sensitive`) and only read
# IUserSession inside listeners to know who's acting.
#
# WHAT THIS PAGE IS FOR, PRECISELY (saga §6.18): attribution. Nothing else.
#
# It is NOT how the app reads Penpot — the service account does all mirroring,
# always (admin-connection.feature). It is NOT required for anything to work. It
# exists so that when a human renames or restores a design from Nextcloud, Penpot
# records THAT HUMAN as having done it, rather than recording every action by
# every user as "nextcloud" forever. Penpot's file history is append-only from
# our side: a change attributed to a robot can never be re-attributed later.
#
# THIS IS A SMALL SURFACE BY DESIGN. Command's own observation — "renaming
# deleting and moving is really the only thing we could do from nextcloud as an
# action" — is what makes the whole model affordable. The complete list of writes
# is short and every entry is either non-destructive or explicitly confirmed by
# a human (saga §6.19): moving a file between projects, creating a design,
# restoring one, renaming a project folder, and deleting in Penpot. Exactly ONE
# RPC in the whole app destroys anything (`delete-file`), and it is only ever
# reached through a confirmed user action. A token used at that few call sites
# doesn't need elaborate machinery.
#
# WHAT'S STILL OPEN (saga open question #9): only the storage mechanism. The
# presumed answer is IConfig::setUserValue with the sensitive flag — the
# user-scoped analogue of both siblings' AppConfig pattern — but it isn't built.
# The scenarios below describe OBSERVABLE behaviour and don't assume a class.
#
# THE 1:1 ASSUMPTION (saga §6.9): one Nextcloud user, one Penpot account, one
# token. Stated as the assumed default, not enforced.
#
# @todo — no lib/Settings/PersonalSettings.php exists yet; this is a brand-new
# settings surface, not a port from either sibling.

Feature: Personal Penpot access token settings
  As an individual Nextcloud user
  I want to store my own Penpot personal access token
  So that changes I make from Nextcloud are attributed to me in Penpot

  Background:
    Given the app is installed and enabled
    And the admin has set the instance-wide Penpot base URL
    And the admin has configured the service-account token

    # ── the page, and what it honestly claims to do ──────────────────────────────

  @todo
  Scenario: A user finds a personal Penpot settings section
    When the user opens their personal settings
    Then a "Penpot" personal settings section is present
    And it explains that the token is theirs alone, not shared instance-wide
    And it explains the token is used to attribute their changes in Penpot
    And it explains the app works without it

  @todo
  Scenario: A user sets their own personal access token
    When the user enters their Penpot personal access token and saves
    Then the token is stored for that user only
    And the field renders blank on reload, the same "never echoed back" pattern both sibling apps use

  @blocked
  Scenario: A user's token never leaks to another Nextcloud user
    Given user "dana" has set a personal Penpot token
    When user "alex" opens their own personal Penpot settings
    Then "alex" sees no token configured
    And "alex" setting their own token never overwrites the token of "dana"

  @blocked
  Scenario: Testing the personal connection distinguishes unset from rejected
    Given the user has not set a personal Penpot token
    When the user tests their personal connection
    Then the test reports a failure and says the token is not set
    When the user sets an invalid personal Penpot token and tests again
    Then the test reports a failure and says the token was rejected

    # ── what happens without one: degraded attribution, never blocked work ───────

  @blocked
  Scenario: Clearing a personal token degrades attribution but breaks nothing
    Given the user has a personal Penpot token stored
    When the user clears the token field and saves
    Then no personal token remains stored for that user
    And their mapped folders keep pulling normally, as the service account
    And their future write actions are attributed to the service account
    # Contrast with an earlier draft, which said mapped folders "stop pulling
    # until a token is set again." That was written under the superseded
    # per-user-pull model (saga §6.9) — the pull never used personal tokens in
    # the final design, so clearing one cannot affect it.

  @blocked
  Scenario: An expired personal token falls back rather than failing the action
    Given the user's personal Penpot token has expired
    When the user renames a mirrored file
    Then the rename is still performed, using the service-account token
    And the user is told their personal token expired and the change was attributed to the service account
    # Penpot tokens expire (never / 30 / 60 / 90 / 180 days) with no auto-rotation
    # — so expiry is a routine event to handle gracefully, not an error state.

    # ── the boundary: this token reads nothing on the app's behalf ───────────────

  @blocked
  Scenario: A personal token is never used to mirror content
    Given the user has a valid personal Penpot token
    When the scheduled pull runs
    Then the user's personal token is not used
    And all mirroring is performed with the service-account token

  @blocked
  Scenario: A personal token does not grant the user new teams to map
    Given the user's personal token can see a Penpot team the service account cannot
    Then that team is not offered for mapping
    And the app explains the service account must be invited to it first
    # Saga §6.18 — the personal token's reach never widens the mirror.

    # ── the documented assumption ────────────────────────────────────────────────

  @blocked
  Scenario: The app assumes one Nextcloud user maps to one Penpot account
    Given the personal-settings page description
    Then it documents the 1:1 (one Nextcloud user, one Penpot account) assumption
    And it does not attempt to prevent two Nextcloud users from pasting the same token
    # Sharing one Penpot login across Nextcloud users isn't precluded by anything
    # the app enforces — it just defeats the page's only purpose, since both
    # users' changes would then be attributed identically.
