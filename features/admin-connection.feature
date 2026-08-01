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

  # ONE RULE, SEVERAL BAD INPUTS — so the rows are Examples, not scenarios. Every
  # row asserts the identical outcome; only the input varies. That is the test
  # for a table: if the rows are a list of VALUES it is an outline, and if they
  # can only be written as a list of SENTENCES they are separate scenarios.
  Scenario Outline: A URL the app cannot build requests from is rejected
    When the admin sets the Penpot base URL to "<url>"
    Then setting the URL is rejected

    Examples: inputs that cannot be a base for an RPC path
      | url                     |
      | penpot.example.com      |
      | ftp://penpot.example.com |

  @blocked
  Scenario: The URL card carries no credential field
    Then no credential field exists on this card — tokens are configured elsewhere

    # ── the live connection: the client, against a real Penpot — IMPLEMENTED ────
    # These run against a real Penpot container in CI, with a token minted per run
    # (saga §6.47). They are the ONLY place the wire format is asserted: the unit
    # suite deliberately does not mock the transport, because a mock of a protocol
    # we have repeatedly misread would only encode the misreading.

  Scenario: A configured connection reports the teams the token can see
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the connection is checked
    Then the connection succeeds
    And at least one Penpot team is listed
    # Reporting TEAMS rather than "OK" is the point (saga §6.12/§6.18): Penpot
    # visibility is always membership-scoped, so which teams a token can see is
    # the fact that decides what can be mapped.

  Scenario: The connection check also lists projects, proving multi-record decoding
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the connection is checked including files
    Then the connection succeeds
    And at least one Penpot project is listed
    # A listing with several records is what exercises Transit's key cache —
    # the second and later records are almost entirely back-references, and two
    # real decoder bugs were invisible until exactly this shape was decoded.

  Scenario: Without a token, the connection check fails and says why
    Given the Penpot base URL points at the test instance
    And no service-account token is configured
    When the connection is checked
    Then the connection fails
    And the failure explains that no token is configured

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

  Scenario: The connection test tells an unset token apart from a rejected one
    Given the Penpot base URL points at the test instance
    And no service-account token is configured
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token is not set
    When the admin saves an invalid service-account token
    And the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token was rejected
    # These two need COMPLETELY different fixes — finish configuring, versus
    # mint a new token — so collapsing them into "connection failed" sends
    # people to the wrong one.

  Scenario: A rejected token names the instance flag that is off by default
    Given the Penpot base URL points at the test instance
    When the admin saves an invalid service-account token
    And the admin tests the connection
    Then the connection test reports a failure
    And the connection test names the "enable-access-tokens" instance flag
    # `enable-access-tokens` is off by default upstream, and its absence produces
    # a plain 401 — indistinguishable from a typo'd token unless we say so.

  Scenario: A successful test reports the teams the account can actually see
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the admin tests the connection
    Then the connection test reports success
    And the connection test lists at least one Penpot team
    # Reporting TEAMS rather than "OK" is the point (saga §6.12/§6.18): Penpot
    # visibility is always membership-scoped, so which teams the token can see is
    # exactly the fact that decides what can be mapped.

  @blocked
  Scenario: A connection test surfaces the required Penpot instance flag
    Given the admin has set the Penpot base URL and a service-account token
    But the Penpot instance has "enable-access-tokens" disabled
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test names the missing instance flag
    # "enable-webhooks" is NOT tested here: webhook delivery has never been
    # observed working (saga §6.17, open question #19), so nothing in the current
    # design depends on it. Adding it back is a saga decision, not a settings tweak.

    # ══ THE ATTRIBUTION RULE, IN ONE LINE ══════════════════════════════════════
    #
    #     READS are always the service account.
    #     WRITES attribute to the acting user when there is one.
    #
    # Those are two different kinds of statement, and conflating them is what
    # makes token handling feel complicated when it is not.
    #
    # READS — THE SERVICE ACCOUNT IS A REQUIREMENT, NOT A DEFAULT. Penpot has no
    # admin scope; every token sees exactly the teams its account belongs to
    # (§6.12). So the puller must be an account that is a member of every mapped
    # team, and that is the whole reason a service account exists. Using a
    # personal token to read would not merely attribute differently — it would
    # change WHAT IS MIRRORED, per user, which is the dual-pull-path complexity
    # §6.16 rejected and the shared-Team-Folder race with it.
    #
    # WRITES — ATTRIBUTION ONLY, AND IT CANNOT WIDEN ANYTHING. A write always
    # targets something the service account already mirrored, so using the user's
    # token changes the name in Penpot's history and nothing else. That is why it
    # is safe to make it best-effort.
    #
    # THE ACTING USER IS WHOEVER THE SESSION SAYS (saga §C6.22). Every gesture —
    # rename, move, copy, create, delete, restore, tag — runs inside the user's
    # own HTTP request, so `IUserSession` has them and attribution just works. The
    # scheduled pull has no session because NOBODY PERFORMED IT: it reconciles
    # what Penpot already says. Attributing it to a user would be a fiction.
    #
    # A background job CAN act as a user when one is genuinely responsible —
    # Nextcloud's own `IUserSession::setVolatileActiveUser()` (NC 29+) is exactly
    # that, and core uses it so "event listeners can correctly work". This app has
    # no such job today; §C6.22 records when it would want one.

    # ── the personal token: optional, per-user, attribution only ─────────────────

  @blocked
  Scenario: The app works fully with no personal tokens configured anywhere
    Given the admin has configured the service-account token
    And no Nextcloud user has set a personal Penpot token
    When a team is mapped and the pull runs
    Then mirroring works completely
    And write actions are performed as the service account
    # The personal layer is additive. Nothing is blocked by its absence.

  @blocked
  Scenario: A user's personal token is used to attribute their writes
    Given the admin has configured the service-account token
    And the user has set a valid personal Penpot token
    When the user performs an action that writes to Penpot
    Then the write uses that user's own token
    And Penpot attributes the change to that user, not to the service account

  @blocked
  Scenario: A personal token is never used for the scheduled pull
    Given two Nextcloud users have valid personal Penpot tokens
    When the scheduled pull runs
    Then the pull uses only the service-account token
    And neither personal token is used for any read
    # Saga §6.18 — one puller, always, or the shared-Team-Folder race returns.

  @blocked
  Scenario: A personal token never widens what the app mirrors
    Given the user's personal token can see the Penpot team "Private Team"
    But the service account has not been invited to "Private Team"
    Then "Private Team" cannot be mapped
    And no content from "Private Team" is mirrored
    # Deliberately closed (saga §6.18): letting personal tokens widen the mirror
    # would reintroduce exactly the dual-pull-path complexity §6.16 rejected.

    # ── when attribution FAILS, the action must not ─────────────────────────────
    # A personal token is not merely "sometimes expired". A Nextcloud user need
    # not have a Penpot account at all, and if they do, they need not be a member
    # of the Penpot team behind a shared Team Folder — the mapping only ever
    # required the SERVICE ACCOUNT to be a member (§6.18). So "the acting user's
    # token cannot write here" is an ORDINARY state, not an edge case.
    #
    # NOT BUILT, AND THE PROMISE IS ALREADY WRITTEN DOWN. `PersonalTokenService`
    # says every caller "is expected to fall back to the service account and carry
    # on", and the fall back that exists is the pre-flight one: no token → service
    # account. There is no post-failure retry. A user whose token Penpot rejects
    # loses the write entirely (§C6.22).

  @unbuilt
  Scenario: A write rejected because of the personal token is retried as the service account
    Given the user has a personal Penpot token that cannot write to this team
    When the user renames a mirrored design
    Then the first attempt uses the user's token and is rejected
    And the write is retried with the service-account token and succeeds
    And the rename reaches Penpot
    # THE ACTION IS THE POINT, ATTRIBUTION IS THE GARNISH. Losing a user's rename
    # to protect the accuracy of a history entry is the wrong trade in every case.

  @unbuilt
  Scenario: A degraded attribution is reported once, not on every gesture
    Given a user whose personal token Penpot keeps rejecting
    When the user performs several write actions
    Then each action succeeds as the service account
    And the user is told once that their token is not being used
    And they are not warned again for every subsequent action
    # A per-gesture warning for a routine state trains people to ignore warnings.

  @unbuilt
  Scenario: Only an authorisation failure falls back, never a real error
    Given the user has a valid personal Penpot token
    When a write fails because Penpot is unreachable
    Then the write is NOT retried with the service-account token
    And the failure is reported as itself
    # The fallback answers "this token may not", not "this call did not work".
    # Retrying a timeout as the service account would double every outage and
    # could apply a write twice — see errors.feature.
