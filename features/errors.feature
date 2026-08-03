# Notes, decisions and history for this feature: AGENTS.md#errors

Feature: Failures never cost the user data
  As a Nextcloud admin
  I want every failure mode to fail safe and loud
  So that a network blip or an expired token never destroys a mirror

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Northwind"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

    # ── the transport lies: parse the stream, not the status ─────────────────────

  @unbuilt
  Scenario: An error inside a 200 response is treated as a failure
    Given a mirrored ".penpot" file in "sync" mode
    When an export returns HTTP 200 but the stream carries an "error" event
    Then the app treats the export as failed
    And the existing mirrored file is left exactly as it was
    And the failure is logged with the Penpot error code
    # Confirmed live (saga §6.20): importing into a deleted file id returned
    # HTTP 200 with `{"~:type":"~:not-found","~:code":"~:object-not-found"}`.

  @todo
  Scenario: A stream that ends without an end event is a failure, not a success
    Given a mirrored ".penpot" file in "sync" mode
    When an export stream closes with no "end" event
    Then the app treats the export as failed
    And the existing mirrored file is left exactly as it was

  @todo
  Scenario: Penpot error codes are decoded from Transit, not string-matched
    When Penpot returns an error payload in Transit encoding
    Then the app decodes the type and code fields
    And reports the real Penpot error code to the admin
    # `~:` keyword, `~u` uuid, `~#` tagged map — the dialect from Course 3, now
    # confirmed on real payloads in both directions.

  @todo
  Scenario: The known-bad export flag combination is never sent
    When the app exports any file
    Then it never sets "includeLibraries" and "embedAssets" both true in one call
    # penpot#7649 — an opaque 500. Normalize on our side regardless of whether
    # upstream ships the cleaner validation error first.

    # ── downloads ────────────────────────────────────────────────────────────────

  @todo
  Scenario: A failed asset download never truncates the existing mirror
    Given a mirrored ".penpot" file in "sync" mode holding a valid archive
    When the export succeeds but the asset download fails
    Then the existing archive is left intact and valid
    And the file is not replaced with a partial or empty archive
    And the failure is logged

  @todo
  Scenario: A transient download failure is retried before giving up
    When an asset download fails with a transient error
    Then the app retries with backoff
    And on final failure it keeps the existing mirror and reports the error
    # The asset id is stable and re-fetchable for ~24h (saga §6.20), so retrying
    # is genuinely worthwhile rather than hopeful.

  @todo
  Scenario: An unauthenticated asset fetch is treated as a credential failure
    When an asset download returns 401
    Then the app reports a credential problem, not a missing file
    And nothing is pruned
    # The asset URL requires the bearer token — 401 without it, confirmed live.

  @blocked
  Scenario: The inner signed storage URL is never persisted
    When an export completes and yields an asset URL
    Then the app does not store the redirect target for later use
    # The inner GCS URL carries a ~24h signature (X-Amz-Expires=87300). The asset
    # id is the durable handle; the signed URL is regenerated per request.

    # ── partial writes ───────────────────────────────────────────────────────────

  @todo
  Scenario: A pull interrupted halfway leaves every written file valid
    Given several mirrored ".penpot" files in "sync" mode
    When the pull is interrupted partway through
    Then every file is either its old version or its new version
    And no file is left as a half-written archive
    # Write to a temp location, move into place atomically.

  @todo
  Scenario: A file that fails to export does not stop the rest of the pull
    Given three mirrored ".penpot" files in "sync" mode
    When exporting the second file fails
    Then the first and third files are still reconciled
    And the failure is reported for the second file only

    # ── pruning: never on incomplete information ─────────────────────────────────

  @todo
  Scenario: A failed project listing prunes nothing
    Given mirrored ".penpot" files in the "My Stuff" subfolder
    When "get-project-files" fails for that project
    Then nothing is pruned
    And the mirrored files are left exactly as they are
    And the failure is reported

  @todo
  Scenario: A failed team listing prunes nothing anywhere under it
    Given a mapped team with several mirrored projects
    When "get-projects" fails for that team
    Then no project subfolder is removed
    And no mirrored file is pruned

  @blocked
  Scenario: An expired service token prunes nothing
    Given mirrored ".penpot" files in the "My Stuff" subfolder
    When the service-account token has expired
    Then the pull halts for that mapping
    And nothing is pruned
    And the admin is notified that the token needs renewing
    # An auth failure is not evidence that anything was deleted. This is the
    # single most important line in this file.

  @todo
  Scenario: Losing access to a team halts only that mapping
    Given two mapped Penpot teams
    When the service account loses access to the first team
    Then the first mapping halts and reports the problem
    And nothing under the first mapping is pruned
    And the second mapping continues to pull normally

  @todo
  Scenario: A pruned file goes to the trash, never straight to deletion
    Given a mirrored ".penpot" file whose Penpot original was genuinely deleted
    When the pull runs with a clean, successful listing
    Then the mirrored file is moved to the Nextcloud trash
    And it is recoverable from the trash

    # ── a real upstream bug the pull must not trust (saga §6.42) ────────────────

  @todo
  Scenario: The pull does not trust "get-projects" alone about which projects exist
    Given a Penpot project that has been deleted
    When the pull lists the team's projects
    Then it confirms each project's existence before acting on it
    And a project that "get-projects" lists but that no longer exists is not mirrored
    # notes: AGENTS.md#the-pull-does-not-trust-get-projects-alone-about-which-projects-exist

  @blocked
  Scenario: A design deleted in Penpot can still be rescued inside the grace window
    Given a mirrored ".penpot" file in "link" mode, holding no archive
    When its design is deleted in Penpot
    And the pull notices within Penpot's ~7-day grace window
    Then the app can still export the design's archive
    And the user is offered a real ".penpot" file to keep
    # notes: AGENTS.md#a-design-deleted-in-penpot-can-still-be-rescued-inside-the-grace-window

    # ── write failures ───────────────────────────────────────────────────────────

  @todo
  Scenario: A failed rename leaves the local rename standing
    Given a mirrored ".penpot" file
    When I rename it in Nextcloud and the Penpot rename call fails
    Then the Nextcloud file keeps its new name
    And Penpot is unchanged
    And the divergence is reported to the user
    And the next pull reconciles the name by Penpot's authority
    # Never revert the user's local action to "fix" a remote failure, and never
    # silently drop the write (saga §6.18 rule 3).

  @unbuilt
  Scenario: A restore whose follow-up rename fails reports partial success
    Given a restore that creates a new Penpot file
    When the import succeeds but the follow-up rename fails
    Then the app reports that the design was restored but not renamed
    And the import is not rolled back
    And the newly created Penpot file id is recorded against the local file
    # Rolling back would delete a design we just successfully restored — exactly
    # the data loss this whole file exists to prevent.

    # ── credential failures ──────────────────────────────────────────────────────

  @todo
  Scenario: A missing service token blocks mapping with a clear reason
    Given no service-account token is configured
    When the admin tries to map a Penpot team
    Then the mapping is refused
    And the refusal explains that a service-account token is required

  @blocked
  Scenario: An invalid personal token falls back rather than blocking
    Given the user's personal Penpot token is invalid
    When the user performs an action that writes to Penpot
    Then the action is performed using the service-account token
    And the user is told their personal token was rejected and why it matters
    # Attribution degrades; the action does not fail (saga §6.18).
