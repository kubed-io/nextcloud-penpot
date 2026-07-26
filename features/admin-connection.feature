# The "connect to Penpot" use case. The SHAPE is genuinely different from both
# sibling apps, and the reason took the whole survey to work out.
#
# THE ACCESS MODEL (saga §6.18, locked). The question "per-user tokens or one
# admin token?" was unanswerable for three sections of the saga because it was
# TWO questions being asked as one:
#
#     Who READS?  → the service account. Always. Required.
#     Who WRITES? → the acting user, if they've set a token. Optional.
#
# Once split, both answers are obvious and neither fights the other.
#
# WHY THE SERVICE ACCOUNT IS REQUIRED (saga §6.16 found the reason, §6.18 acted
# on it): if the scheduled pull ran per-user, two Nextcloud users who are both
# members of the same Penpot team would resolve to the SAME Team Folder, and two
# uncoordinated jobs would write the same mirror file. That's a data race, not an
# inefficiency. One puller, one credential, one pass — the race cannot happen.
#
# WHY PER-USER TOKENS SURVIVE ANYWAY: attribution. Penpot attributes every
# mutation to whoever's token made it. If Nextcloud renames using the service
# account, Penpot's history says "nextcloud renamed this" for every rename by
# every user, forever. With a personal token it says the truth. That's the entire
# case for per-user tokens here, and it's a good one — but it only touches the
# small set of write paths (saga §6.19), so the personal token stays
# OPTIONAL. Requiring one before a user can rename a file would be a terrible
# first-run experience for zero functional gain.
#
# THE URL CARD IS URL-ONLY (saga §6.11, locked): modeled on n8n's
# InstanceSettings.php, not Grafana's bundled URL+token card. Grafana bundles
# because it has exactly one credential; here there are two, with different
# owners and different jobs, so the URL belongs to neither card.
#
# PARTIALLY LIVE. The URL scenarios below run for real in CI — the base URL is
# the whole of the first implemented slice (saga §6.11: it's the one setting every
# version of this app needs regardless of how the credential model resolves, so
# it was built first). Every CREDENTIAL scenario is still @todo: no token storage
# exists yet.

Feature: Admin and per-user Penpot connection setup
  As a Nextcloud admin and as an individual Nextcloud user
  I want the app to read as a service account and write as me
  So that mirroring is reliable and Penpot's history says who really did what

  Background:
    Given the app is enabled

  # ── the URL card: admin, locked, credential-free — IMPLEMENTED ───────────────

  Scenario: Admin sets the instance-wide Penpot base URL
    When the admin sets the Penpot base URL
    Then the Penpot base URL is stored

  Scenario: The stored URL is normalised so later callers can concatenate paths
    When the admin sets the Penpot base URL to "https://penpot.example.com/"
    Then the stored URL has no trailing slash
    And the Penpot base URL is "https://penpot.example.com"

  Scenario: A URL with no scheme is rejected
    When the admin sets the Penpot base URL to "penpot.example.com"
    Then setting the URL is rejected

  Scenario: A non-http scheme is rejected
    When the admin sets the Penpot base URL to "ftp://penpot.example.com"
    Then setting the URL is rejected

  @todo
  Scenario: The URL card carries no credential field
    Then no credential field exists on this card — tokens are configured elsewhere

  # ── the service-account token: required, admin-configured, does all reading ──

  @todo
  Scenario: The admin configures the service-account token
    Given the admin has set the Penpot base URL
    When the admin saves a service-account Penpot access token
    Then the token is stored as a sensitive value
    And the field renders blank on reload, never echoing the stored token back

  @todo
  Scenario: Without a service-account token, nothing can be mapped
    Given the admin has set the Penpot base URL
    And no service-account token is configured
    When the admin opens the mapping list
    Then the app explains that a service-account token is required to map a team
    And no team can be mapped

  @todo
  Scenario: The service account sees only the teams it was invited into
    Given the admin has configured a service-account Penpot token
    And that service account has been invited as "viewer" on the Penpot team "observe"
    When the admin lists teams visible to the service account
    Then only "observe" and any other team it was explicitly invited into is visible
    And no other team on the Penpot instance is visible through this token
    # Not a limitation we imposed — Penpot offers NO credential an instance-wide
    # view (get-teams is membership-scoped, confirmed §6.12). Viewer is the right
    # role: enough to list and export, no write access wanted.

  @todo
  Scenario: The connection test tells an unset token apart from a rejected one
    Given the admin has set the Penpot base URL
    And no service-account token is configured
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token is not set
    When the admin saves an invalid service-account token
    And the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token was rejected

  @todo
  Scenario: A connection test surfaces the required Penpot instance flag
    Given the admin has set the Penpot base URL and a service-account token
    But the Penpot instance has "enable-access-tokens" disabled
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test names the missing instance flag
    # "enable-webhooks" is NOT tested here: webhook delivery has never been
    # observed working (saga §6.17, open question #19), so nothing in the current
    # design depends on it. Adding it back is a saga decision, not a settings tweak.

  # ── the personal token: optional, per-user, attribution only ─────────────────

  @todo
  Scenario: The app works fully with no personal tokens configured anywhere
    Given the admin has configured the service-account token
    And no Nextcloud user has set a personal Penpot token
    When a team is mapped and the pull runs
    Then mirroring works completely
    And write actions are performed as the service account
    # The personal layer is additive. Nothing is blocked by its absence.

  @todo
  Scenario: A user's personal token is used to attribute their writes
    Given the admin has configured the service-account token
    And the user has set a valid personal Penpot token
    When the user performs an action that writes to Penpot
    Then the write uses that user's own token
    And Penpot attributes the change to that user, not to the service account

  @todo
  Scenario: A personal token is never used for the scheduled pull
    Given two Nextcloud users have valid personal Penpot tokens
    When the scheduled pull runs
    Then the pull uses only the service-account token
    And neither personal token is used for any read
    # Saga §6.18 — one puller, always, or the shared-Team-Folder race returns.

  @todo
  Scenario: A personal token never widens what the app mirrors
    Given the user's personal token can see the Penpot team "Private Team"
    But the service account has not been invited to "Private Team"
    Then "Private Team" cannot be mapped
    And no content from "Private Team" is mirrored
    # Deliberately closed (saga §6.18): letting personal tokens widen the mirror
    # would reintroduce exactly the dual-pull-path complexity §6.16 rejected.
