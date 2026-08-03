# Notes, decisions and history for this feature: AGENTS.md#admin-section

Feature: The admin section's shape and actions
  As a Nextcloud admin
  I want this app's settings laid out like its sibling integrations
  So that configuring the third one teaches me nothing new

  Background:
    Given the app is enabled

    # ── the layout ───────────────────────────────────────────────────────────────

  @blocked
  Scenario: The section presents four panels in the family's order
    When the admin opens the Penpot settings section
    Then the panels appear in this order:
      | Instance      |
      | Sync Settings |
      | Team mappings |
      | Sync Actions  |
    # Identical to nextcloud-n8n and nextcloud-grafana, deliberately.

  @blocked
  Scenario: The Instance card holds both the URL and the service-account token
    When the admin opens the Penpot settings section
    Then the "Instance" card has a Penpot base URL field
    And the "Instance" card has a service-account token field
    And no other admin card asks for a credential
    # notes: AGENTS.md#the-instance-card-holds-both-the-url-and-the-service-account-token

  @blocked
  Scenario: The token field never echoes a stored token back
    Given the admin has configured the service-account token
    When the admin opens the Penpot settings section
    Then the token field renders blank
    But the card states that a token is currently stored
    # A sensitive field is blank even when populated, so the COPY is the only
    # signal of whether one is set. Both siblings learned this the same way.

  @blocked
  Scenario: Every button in the section lives in Sync Actions
    When the admin opens the Penpot settings section
    Then all of the section's action buttons are in the "Sync Actions" panel
    And the "Sync Actions" panel is the last panel in the section

    # ── the actions themselves ───────────────────────────────────────────────────

  @blocked
  Scenario: Test connection works today and reports what the account can see
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the admin tests the connection
    Then the connection test reports success
    And the connection test lists at least one Penpot team
    # notes: AGENTS.md#test-connection-works-today-and-reports-what-the-account-can-see

    # notes: AGENTS.md#sync-from-penpot-queues-a-background-job-and-says-so

  @blocked
  Scenario: "Sync from Penpot" queues a background job and says so
    When the admin opens the Penpot settings section
    And the admin clicks "Sync from Penpot"
    Then the button reports that a sync has started
    And the request returns immediately, without waiting for the pull
    And the panel shows the run as queued, then running, then finished
    # The admin can navigate away and come back to a finished run.

  @blocked
  Scenario: The panel reports the outcome of the last run
    Given a bulk sync has finished
    When the admin opens the Penpot settings section
    Then the panel shows when it last ran and what it did
    And a failed run says so, with the reason
    # notes: AGENTS.md#the-panel-reports-the-outcome-of-the-last-run

  @blocked
  Scenario: A second click while a sync is running does not start another
    Given a bulk sync is already running
    When the admin clicks "Sync from Penpot" again
    Then no second job is queued
    # Two concurrent pulls over one folder tree would race on the same files.

  @todo
  Scenario: The scheduled pull uses the interval from Sync Settings
    Given the scheduled pull is enabled with an interval
    When the interval elapses and Nextcloud's cron runs
    Then a pull runs without anyone asking for it
    And a design renamed in Penpot is renamed in Nextcloud
    And the run is recorded like any other
    # This is the scenario the whole slice exists for: a rename in Penpot should
    # reach Nextcloud eventually, with nobody watching.

  @todo
  Scenario: Turning the schedule off stops the runs
    Given the scheduled pull is disabled
    When Nextcloud's cron runs
    Then no pull happens
    # The job still ticks — the interval gates how often it re-reads the
    # setting — but it does nothing while off.

  @decision
  Scenario: There is no "Sync to Penpot" button, ever
    When the admin opens the Penpot settings section
    Then no button offers to push designs to Penpot
    # notes: AGENTS.md#there-is-no-sync-to-penpot-button-ever

  @blocked
  Scenario: Purge is offered but disabled until the delete machine exists
    When the admin opens the Penpot settings section
    Then the "Purge Nextcloud files" button is present
    And the "Purge Nextcloud files" button is disabled
    # Its behaviour is specified in purge.feature; this only pins its presence
    # and its honesty in the meantime.
