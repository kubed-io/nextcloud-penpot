# "Admin makes a mapping" — the team-mapping list in admin settings, driven over
# the CLI (the same operations the Settings panel performs).
#
# A MAPPING IS A TEAM. THAT IS THE WHOLE OBJECT (saga §6.24, refining §6.13).
# Penpot's hierarchy is a confirmed, hard, structural three levels — team
# `contains` project `contains` file (§6.5, verified against /api/doc's param
# schemas: a project record cannot even be represented without its team). But
# only the TOP level is a mapping:
#   - A Penpot TEAM maps to a Nextcloud Team Folder (or a plain-folder +
#     group-sharing fallback when groupfolders isn't installed/delegated —
#     mirroring both siblings' TeamFolderService "optional dependency" precedent).
#   - Penpot PROJECTS are NOT mapped. They are MIRRORED — every project in the
#     team appears as a folder created and named by the pull, initially one level
#     inside the Team Folder. There is no project mapping to add, configure, or
#     remove. Users may reorganise those folders freely within the Team Folder
#     (saga §6.29); an earlier "exactly one level, hard cap" rule is withdrawn.
# An earlier draft had users mapping projects individually. That could never work
# coherently: the next pull would immediately recreate any subfolder you removed.
# One mapping object, one lifecycle.
#
# THE SERVICE ACCOUNT IS A PRECONDITION, NOT A CONVENIENCE (saga §6.18, locked):
# a team cannot be mapped unless the service account can actually see it — which
# means someone with authority over that Penpot team has invited it as `viewer`.
# This is not us being strict; it's Penpot's model. §6.12 confirmed NO credential
# gets an instance-wide view (get-teams is membership-scoped, always). Requiring
# the invite up front makes that visible, instead of silently creating a mapping
# that pulls nothing forever.
#
# MAPPED-FOLDER NAMING IS SERVER-AUTHORITATIVE (saga §6.13 point 3): the folder
# name tracks Penpot's team name via the pull. This keeps two Nextcloud setups
# mapping the same Penpot team recognizably in sync by name, not just by hidden
# id.
#
# MODE IS A MAPPING DEFAULT, NOT A MAPPING PROPERTY (saga §6.22): a mapping
# carries the default mode its files get ("link" unless set otherwise), but any
# individual file can be promoted or demoted afterwards — see sync-mode.feature.
#
# WHAT'S DELIBERATELY NOT HERE: creating a NEW Penpot team or project FROM
# Nextcloud is a separate, still-open fork — see team-import.feature.
#
# @todo — no lib/Settings/ or lib/Service/MappingService exists yet.

@todo
Feature: Admin configures team mappings
  As a Nextcloud admin
  I want to map existing Penpot teams to Team Folders
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    And the admin has set the instance-wide Penpot base URL
    And the admin has configured the service-account Penpot token

  # ── the core mapping action ──────────────────────────────────────────────────

  Scenario: Mapping a Penpot team provisions a Team Folder and mirrors its projects
    Given the service account has been invited as "viewer" on the Penpot team "Ferronescotia"
    When the admin maps the Penpot team "Ferronescotia"
    Then a Team Folder is provisioned for "Ferronescotia"
    And the Team Folder carries the Penpot team id as folder metadata
    When the pull runs
    Then each of "Ferronescotia"'s Penpot projects appears as a direct subfolder
    And each project folder carries its Penpot project id as folder metadata
    And each project folder carries the app's project tag
    And the pull creates them one level inside the Team Folder
    # Where the pull PUTS them initially. Users may then move them anywhere
    # within the Team Folder (saga §6.29) — but not out of it (saga §6.30).

  # The precondition that makes the single-puller model work (saga §6.18).
  Scenario: A team the service account cannot see cannot be mapped
    Given the Penpot team "Private Team" is visible to a user's personal token
    But the service account has not been invited to "Private Team"
    When the admin tries to map "Private Team"
    Then the mapping is refused
    And the refusal explains the service account must be invited as "viewer" first
    And no Team Folder is provisioned
    # Better an honest refusal now than a mapping that silently pulls nothing.

  Scenario: There is no project-level mapping to configure
    Given the Penpot team "Ferronescotia" is mapped
    Then the mapping list shows exactly 1 mapping, for the team
    And no per-project mapping can be added, configured, or removed
    And project subfolders exist only because the pull created them

  # ── permissions and fallback ─────────────────────────────────────────────────

  # Team Folders are admin-configured by default (groupfolders' own documentation
  # and this cluster's live config, checked directly — no delegation configured).
  Scenario: Mapping a team into a Team Folder requires Team Folder creation rights
    Given the acting Nextcloud user does not hold Team Folder admin or delegated rights
    When that user tries to map a Penpot team to a new Team Folder
    Then the action is refused or requires an admin-side step
    And the refusal explains that Team Folder creation is admin-configured by default

  # The fallback tier — same "optional dependency" precedent both sibling apps'
  # TeamFolderService.php already establish, mirrored here for the team level.
  Scenario: Mapping a team without groupfolders installed falls back to a plain shared folder
    Given the "groupfolders" app is not installed
    When the admin maps the Penpot team "Ferronescotia"
    Then a plain Nextcloud folder is provisioned and shared to the mapped group
    And the folder carries the Penpot team id as folder metadata, exactly as a Team Folder would
    And the mapping behaves the same for pull purposes as a Team Folder mapping
    # Folder metadata works identically on both (saga §6.21, confirmed live on a
    # real production Team Folder) — the fallback is a sharing difference, not a
    # mapping-mechanism difference.

  # ── naming, mode, and duplicate prevention ───────────────────────────────────

  Scenario: A mapped folder's name is not independently chosen at mapping time
    When the admin maps the Penpot team "Ferronescotia"
    Then the provisioned Team Folder is named "Ferronescotia", matching Penpot exactly
    And the mapping UI does not offer a separate "call it something else" field

  Scenario: Project folder names always match their Penpot projects
    Given the Penpot team "Ferronescotia" is mapped and pulled
    Then every project folder is named exactly as Penpot names that project
    And the app never lets a project folder's name diverge from its project's
    # Locked in saga §6.36, in both directions: Penpot renames propagate down on
    # the pull, and renaming a project folder in Nextcloud calls "rename-project".
    # Position is free (§6.29); only the NAME is pinned. This is what earns the
    # project tag its keep — a tagged folder named "Acme" IS the project "Acme".

  @todo
  Scenario: Two Penpot projects in one team sharing a name is handled, not crashed
    Given the "Ferronescotia" team has two projects both named "Brand"
    When the pull runs
    Then both are mirrored without a folder-name collision
    And the app reports the ambiguity so an admin can rename one in Penpot
    # Penpot permits duplicate project names; Nextcloud does not permit duplicate
    # sibling folder names. Free nesting means the second folder can live
    # elsewhere, but the exact rule is undecided — saga open question #31.

  Scenario: A team renamed in Penpot renames its Team Folder on the next pull
    Given the Penpot team "Ferronescotia" is mapped
    When the team is renamed to "Ferronescotia Design" in Penpot
    And the pull runs
    Then the Team Folder is renamed to "Ferronescotia Design"
    And the mapping still resolves, because it is keyed on the team id, not the name

  Scenario: A mapping records the default mode its files get, defaulting to link
    When the admin maps the Penpot team "Ferronescotia" without choosing a mode
    Then the mapping's default mode is "link"
    When the admin maps the Penpot team "Design Co" with default mode "sync"
    Then the mapping's default mode is "sync"
    # Per-file promotion/demotion is sync-mode.feature's concern, not this one.

  Scenario: A Penpot team may only be mapped once
    Given the Penpot team "Ferronescotia" is already mapped
    When the admin tries to map the Penpot team "Ferronescotia" again
    Then the mapping is rejected
    And there is still exactly 1 configured team mapping
