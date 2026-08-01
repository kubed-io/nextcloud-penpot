# Personal Penpot projects, mounted at the root of a user's Nextcloud home.
#
# THE ONE PLACE THE PERSONAL TOKEN DOES REAL WORK (saga §6.31). Everywhere else
# in this app the personal token only attributes writes (§6.18) — the service
# account does all mirroring. Personal projects are the exception, and they have
# to be, for a structural reason: the service account is not a member of anyone's
# personal team and CANNOT BE. §6.12 confirmed no Penpot credential ever gets an
# instance-wide view; a personal team is precisely the space nobody else is in.
#
# THE SHAPE:
#   - Every Penpot account has a "Default" personal team, isDefault: true,
#     auto-provisioned 12 MILLISECONDS after the account itself (saga §6.9 —
#     measured, not assumed). It is not something a user made or can opt out of.
#   - That team gets NO TEAM FOLDER. Wrapping a personal space in a groupfolders
#     Team Folder — a SHARING primitive — would be actively wrong.
#   - Its projects mount as folders AT THE ROOT OF THE USER'S HOME, each carrying
#     its Penpot project id as metadata exactly like any other project folder.
#   - From there everything is normal: files resolve by nearest ancestor (§6.29),
#     and the user can move those folders anywhere in their home.
#
# THE EXCEPTION THIS CREATES, WRITTEN DOWN RATHER THAN DISCOVERED: a personal
# project folder has NO TEAM-ID ANCESTOR. The naive implementation of §6.29
# ("walk up for a team id; none found = broken") would treat every personal
# project as an error. So resolution needs an explicit rule: no team ancestor +
# a project id belonging to the acting user's personal team = a personal project,
# and that is VALID.
#
# A KNOWN, ACCEPTED GAP: this is a SECOND PULL PATHWAY, which §6.18 deliberately
# avoided for team content. It's safe here — a user's home folder has exactly one
# writer by construction, so the shared-Team-Folder race §6.18 was protecting
# against cannot occur. But it is extra machinery, and it should be built AFTER
# the primary team pull works. Not day-one scope.
#
# @todo — no lib/ exists yet; and this is explicitly follow-on work behind the
# team pull (saga open question #28).

Feature: Personal Penpot projects in the user's home folder
  As an individual Nextcloud user
  I want my own Penpot projects to appear in my home folder
  So that my personal designs are backed up without needing a shared Team Folder

  Background:
    Given the app is installed and enabled
    And the admin has set the instance-wide Penpot base URL

    # ── what appears, and what doesn't ───────────────────────────────────────────

  @blocked
  Scenario: A user's personal projects mount at their home root
    Given the user has set a valid personal Penpot token
    And the user's personal Penpot team contains the projects "Sketches" and "Logos"
    When the personal pull runs for that user
    Then a folder "Sketches" appears at the root of the user's home
    And a folder "Logos" appears at the root of the user's home
    And each folder carries its Penpot project id as metadata
    And each folder carries the app's project tag

  @blocked
  Scenario: The personal team itself gets no folder
    Given the user has set a valid personal Penpot token
    When the personal pull runs
    Then no folder is created for the personal team itself
    And no Team Folder is provisioned for it
    And the personal projects sit directly at the home root
    # A personal team is not a sharing boundary — there is nobody to share with.

  @unbuilt
  Scenario: A personal project folder resolves without a team ancestor
    Given a personal project folder at the root of the user's home
    Then the folder carries a Penpot project id
    And it has no ancestor folder carrying a Penpot team id
    And it resolves as a personal project, not as a broken mapping
    And a mirrored ".penpot" file inside it belongs to that project
    # The explicit exception to saga §6.29's team lookup.

  @unbuilt
  Scenario: A user can move their personal project folders anywhere in their home
    Given a personal project folder "Sketches" at the user's home root
    And a plain folder "Design" in the user's home
    When the user moves "Sketches" into "Design"
    Then the move succeeds
    And files inside "Sketches" still belong to the "Sketches" project
    And a pull does not move the folder back
    # Same free-nesting rule as team projects (saga §6.29). There is no team
    # folder to stay inside, so the §6.30 restriction has nothing to bite on.

    # ── the credential boundary ──────────────────────────────────────────────────

  @blocked
  Scenario: Personal projects are pulled with the user's own token, never the service account
    Given the user has set a valid personal Penpot token
    When the personal pull runs
    Then the pull uses that user's personal token
    And the service-account token is not used for any personal project
    # The service account cannot see a personal team and never will (saga §6.12).

  @blocked
  Scenario: Without a personal token, no personal projects appear at all
    Given the user has not set a personal Penpot token
    When any pull runs
    Then no personal project folders are created in that user's home
    And the user's mapped Team Folders are unaffected and keep pulling normally
    # Team content is the service account's job and does not depend on this.

  @blocked
  Scenario: One user's personal projects never appear in another user's home
    Given user "dana" has a personal Penpot token and personal projects
    And user "alex" has their own personal Penpot token
    When the personal pull runs for both users
    Then "alex" sees only their own personal projects
    And no folder from "dana"'s personal team appears anywhere in "alex"'s home

  @blocked
  Scenario: Clearing a personal token stops personal pulls without deleting anything
    Given the user has personal project folders in their home
    When the user clears their personal Penpot token
    Then the personal project folders and their files are left in place
    And they are no longer refreshed
    And nothing is pruned or trashed
    # Don't-lose-data: losing a credential is never evidence that content is gone
    # (the same rule errors.feature applies to the service account).

    # ── modes and behaviour are identical to team projects ──────────────────────

  @unbuilt
  Scenario: Personal projects support the same link and sync modes
    Given a personal project folder with mirrored files
    Then each file is in "link" or "sync" mode exactly as a team file would be
    And promoting or demoting a personal file behaves identically
    # Nothing about personal projects changes the storage model (sync-mode.feature).

  @unbuilt
  Scenario: Deleting a personal project folder never touches Penpot
    Given a personal project folder in the user's home
    When the user deletes the folder
    Then Penpot is never contacted
    And the Penpot project and its designs are completely unaffected
    And the folder is recoverable from the Nextcloud trash
