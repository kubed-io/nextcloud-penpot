# PERSONAL PROJECTS — the WHO and the WHERE, and nothing else.
#
# ## WHAT THIS FILE OWNS, AFTER THE DESIGN/PROJECT SPLIT
#
# A personal project is a project. Creating, renaming, moving, copying and
# deleting one behaves exactly as it does in a mapped team — so those scenarios
# live with their verb (create-project.feature, rename-project.feature, and so
# on), where a reader comparing the personal case against the team case sees both
# answers in one table instead of hunting two files. That is the same rule the
# whole `-design` / `-project` split rests on: organise by BEHAVIOUR, not by the
# kind of thing acted on.
#
# What genuinely differs is not the behaviour but its two coordinates:
#
#   WHO    the user's OWN personal token, never the service account. No token,
#          no personal projects at all — and clearing one stops the pull without
#          deleting anything.
#   WHERE  the user's home root, with NO team-folder ancestor. The personal team
#          itself gets no folder, which is why the membership resolver has to
#          cope with a project folder that has no team above it (saga §6.29).
#
# Those two are what this file is for, plus the isolation that follows from them:
# one user's personal projects never appear in another user's home.
#
# THE ONE PLACE THE PERSONAL CASE REALLY DIVERGES — deleting a personal project
# folder does not touch Penpot — is stated in delete-project.feature, beside the
# team answer it contradicts. A divergence hidden in a file nobody opens while
# holding the question is a divergence nobody finds.

Feature: Personal Penpot projects in a user's own home
  As a Nextcloud user with my own Penpot token
  I want my personal projects mirrored into my home, under my own credentials
  So that my own work follows the same rules without being visible to anyone else

  Background:
    Given the app is installed and enabled
    And the admin has set the instance-wide Penpot base URL

  @unbuilt
  Scenario: A user's personal projects mount at their home root
    Given the user has set a valid personal Penpot token
    And the user's personal Penpot team contains the projects "Sketches" and "Logos"
    When the personal pull runs for that user
    Then a folder "Sketches" appears at the root of the user's home
    And a folder "Logos" appears at the root of the user's home
    And each folder carries its Penpot project id as metadata
    And each folder carries the app's project tag

  @unbuilt
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
  Scenario: Personal projects are pulled with the user's own token, never the service account
    Given the user has set a valid personal Penpot token
    When the personal pull runs
    Then the pull uses that user's personal token
    And the service-account token is not used for any personal project
    # The service account cannot see a personal team and never will (saga §6.12).

  @unbuilt
  Scenario: Without a personal token, no personal projects appear at all
    Given the user has not set a personal Penpot token
    When any pull runs
    Then no personal project folders are created in that user's home
    And the user's mapped Team Folders are unaffected and keep pulling normally
    # Team content is the service account's job and does not depend on this.

  @unbuilt
  Scenario: One user's personal projects never appear in another user's home
    Given user "dana" has a personal Penpot token and personal projects
    And user "alex" has their own personal Penpot token
    When the personal pull runs for both users
    Then "alex" sees only their own personal projects
    And no folder from "dana"'s personal team appears anywhere in "alex"'s home

  @unbuilt
  Scenario: Clearing a personal token stops personal pulls without deleting anything
    Given the user has personal project folders in their home
    When the user clears their personal Penpot token
    Then the personal project folders and their files are left in place
    And they are no longer refreshed
    And nothing is pruned or trashed
    # Don't-lose-data: losing a credential is never evidence that content is gone
    # (the same rule errors.feature applies to the service account).

    # ── the implicit mapping, and what it makes possible ────────────────────────
    # These are the scenarios the "home root IS the mapping" reading adds. They
    # are not a second pull pathway; they are what having a team ancestor at the
    # root means for every OTHER feature, which is the point of framing it as a
    # mapping rather than as a sync job.

  @unbuilt
  Scenario: Setting a personal token maps the personal team to the home root
    Given the user has set a valid personal Penpot token
    Then the user's home root resolves to their personal Penpot team
    And no mapping for it appears in the admin panel
    And the user is never asked to configure or name it
    # Nothing to decide: the mapping exists exactly because the token does. A
    # visible mapping would be a choice with one possible answer.

  @unbuilt
  Scenario: Clearing the token removes the implicit mapping
    Given the user has personal project folders in their home
    When the user clears their personal Penpot token
    Then the user's home root resolves to no Penpot team
    And a new ".penpot" file made there is inert, as it was before the token
    # The mapping is the token's shadow. It cannot outlive it, and nothing is
    # deleted when it goes (see the scenario above).
