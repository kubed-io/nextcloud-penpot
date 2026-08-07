# Notes, decisions and history for this feature: ../AGENTS.md#admin-connection

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

  # notes: ../AGENTS.md#the-stored-url-is-normalised-so-later-callers-can-concatenate-paths
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

    # notes: ../AGENTS.md#the-url-card-carries-no-credential-field

  Scenario: A configured connection reports the teams the token can see
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the connection is checked
    Then the connection succeeds
    And at least one Penpot team is listed
    # notes: ../AGENTS.md#a-configured-connection-reports-the-teams-the-token-can-see

  Scenario: The connection check also lists projects, proving multi-record decoding
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the connection is checked including files
    Then the connection succeeds
    And at least one Penpot project is listed
    # notes: ../AGENTS.md#the-connection-check-also-lists-projects-proving-multi-record-decoding

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
    # notes: ../AGENTS.md#the-service-account-sees-only-the-teams-it-was-invited-into

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
    # notes: ../AGENTS.md#the-connection-test-tells-an-unset-token-apart-from-a-rejected-one

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
    # notes: ../AGENTS.md#a-successful-test-reports-the-teams-the-account-can-actually-see

  @blocked
  Scenario: A connection test surfaces the required Penpot instance flag
    Given the admin has set the Penpot base URL and a service-account token
    But the Penpot instance has "enable-access-tokens" disabled
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test names the missing instance flag
    # notes: ../AGENTS.md#a-connection-test-surfaces-the-required-penpot-instance-flag

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

    # notes: ../AGENTS.md#a-write-rejected-because-of-the-personal-token-is-retried-as-the-service-account

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
    # notes: ../AGENTS.md#only-an-authorisation-failure-falls-back-never-a-real-error

    # ── the user's own connection: a token, and nothing else ────────────────────
    # notes: ../AGENTS.md#the-personal-connection

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
    # notes: ../AGENTS.md#clearing-a-personal-token-degrades-attribution-but-breaks-nothing

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

  @decision
  Scenario: Users do not author their own team mappings
    Given a user who is not an admin
    Then they cannot map a Penpot team to a folder
    # notes: ../AGENTS.md#users-do-not-author-their-own-team-mappings

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
    # notes: ../AGENTS.md#the-app-assumes-one-nextcloud-user-maps-to-one-penpot-account
