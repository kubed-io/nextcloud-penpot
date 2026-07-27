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
# PARTIALLY LIVE. The MAPPING LIFECYCLE — add, refuse, list, remove, and the
# defaults a new mapping gets — runs for real in CI against a real Penpot, and
# those scenarios are untagged below. Everything that depends on the PULL (Team
# Folder provisioning, project subfolders, rename propagation) is still @todo:
# MappingService and the admin surface exist, the sync engine does not.

Feature: Admin configures team mappings
  As a Nextcloud admin
  I want to map existing Penpot teams to Team Folders
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token

  # ── the mapping lifecycle: IMPLEMENTED, runs against a real Penpot ──────────
  # These drive the same MappingService the settings panel calls, over occ.

  Scenario: A team the service account can see can be mapped
    Given no Penpot teams are mapped
    And the service account can see at least one Penpot team
    When the admin maps the first team the service account can see
    Then there is exactly 1 configured team mapping
    And the mapping records the team name from Penpot

  Scenario: A new mapping defaults to link mode and nested folders
    Given no Penpot teams are mapped
    When the admin maps the first team the service account can see
    Then the mapping's default mode is "link"
    And the mapping's folder mode is "nested"

  # ── naming: the FOLDER is the admin's, the PROJECTS are Penpot's ────────────
  # The one place the two levels deliberately differ, and the same shape both
  # sibling apps use: a mapping names its DESTINATION, and the source object's
  # name is merely the default.
  #
  #   nextcloud-n8n:     tag        → Team Folder (named by the admin)
  #   nextcloud-grafana: folder     → nc_folder, blank ⇒ the Grafana folder title
  #   here:              Penpot team → nc_folder, blank ⇒ the Penpot team name
  #
  # Project folders INSIDE the mapped folder are the exception: they always match
  # their Penpot project's name exactly, in both directions (saga §6.36). A team
  # folder is a mount point the admin chose to create, so naming it is theirs; a
  # project folder is a mirror of a Penpot object, and letting its name drift
  # would break the identity the pull uses to match folders to projects.

  Scenario: A mapped folder defaults to the Penpot team's name
    Given no Penpot teams are mapped
    When the admin maps the first team the service account can see
    Then the mapping's Nextcloud folder is named after the Penpot team

  Scenario: The admin may name the Nextcloud folder whatever they like
    Given no Penpot teams are mapped
    When the admin maps the first visible team into the folder "Design Files"
    Then the mapping's Nextcloud folder is "Design Files"
    And the mapping still records the Penpot team's own name separately
    # Both are kept: the folder name is what gets created, the team name is what
    # the admin page shows so the pairing is legible at a glance.

  @todo
  Scenario: Renaming the team in Penpot does not rename the admin's folder
    Given the first visible team is mapped into the folder "Design Files"
    When the team is renamed in Penpot
    And the pull runs
    Then the mapping's Nextcloud folder is still "Design Files"
    # The folder name was the admin's choice; the team name was only ever its
    # default. Silently moving their folder because someone renamed a team
    # upstream would be a surprise, not a sync.

  @todo
  Scenario: Two mappings cannot target the same Nextcloud folder
    Given the first visible team is mapped into the folder "Designs"
    When the admin maps another team into the folder "Designs"
    Then the mapping is rejected
    And the refusal explains "already used"
    # Two teams mirroring into one folder would interleave their project
    # subfolders, and the pull would fight over the same names on every run.

  Scenario: A Nextcloud folder name is a single folder, not a path
    Given no Penpot teams are mapped
    When the admin maps the first visible team into the folder "teams/design"
    Then the mapping is rejected
    And the refusal explains "single folder name"
    # Everything below the team folder is created by the pull from Penpot's own
    # project names, so an inner "/" would invent an intermediate folder that no
    # Penpot object corresponds to — and that nothing would ever clean up.

  # ── sharing: groups + Team Folder, exactly as the siblings do it ────────────
  # Same two controls, same meanings, same defaults as nextcloud-n8n and
  # nextcloud-grafana, so an admin configuring all three does the same thing each
  # time. They PERSIST today and are honoured when the pull provisions the folder
  # (Course 3) — the same "saved now, applied later" state Grafana ships them in.

  Scenario: A mapping records the Nextcloud groups its folder is shared with
    Given no Penpot teams are mapped
    When the admin maps the first visible team shared with the group "admin"
    Then the mapping's groups are "admin"

  Scenario: A mapping defaults to a Team Folder with no groups
    Given no Penpot teams are mapped
    When the admin maps the first team the service account can see
    Then the mapping uses a Team Folder
    And the mapping has no groups
    # groupfolders is the preferred backend in all three apps, so an omitted flag
    # means "use a Team Folder". Groups start empty and are opt-in.

  @todo
  Scenario: A mapping can use a plain shared folder instead of a Team Folder
    When the admin maps a team with Team Folder turned off
    Then the mapping records that it uses a plain shared folder
    And the folder is shared to the mapping's groups when the pull provisions it
    # The value persists today; the provisioning that acts on it is Course 3.

  Scenario: A team the service account cannot see cannot be mapped
    Given no Penpot teams are mapped
    When the admin tries to map the Penpot team "11111111-2222-3333-4444-555555555555"
    Then the mapping is rejected
    And the refusal explains "not visible to the service account"
    And there are exactly 0 configured team mappings
    # Better an honest refusal now than a mapping that silently pulls nothing.
    # Penpot offers NO instance-wide view (§6.12), so this is Penpot's model
    # surfacing, not a rule this app invented.

  Scenario: A Penpot team may only be mapped once
    Given no Penpot teams are mapped
    When the admin maps the first team the service account can see
    And the admin maps the same team again
    Then the mapping is rejected
    And there is exactly 1 configured team mapping

  Scenario: Folder mode "keyed" is refused, because it is designed but not built
    Given no Penpot teams are mapped
    When the admin tries to map the first visible team with folder mode "keyed"
    Then the mapping is rejected
    And the refusal explains "not implemented"
    # Accepting it and behaving as "nested" would be a silent lie the admin could
    # only detect from the resulting folder layout (saga §6.53, question #47).

  Scenario: Removing a mapping deletes nothing
    Given no Penpot teams are mapped
    When the admin maps the first team the service account can see
    And the admin removes that mapping
    Then there are exactly 0 configured team mappings
    And removing it reported that nothing was deleted
    # Nothing is removed from Penpot and nothing local is removed either. What
    # SHOULD happen to already-mirrored files is Course 5's decision
    # (remove-mapping.feature) — until then the safe behaviour is to leave them
    # and say so.

  # ── the core mapping action ──────────────────────────────────────────────────

  @todo
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
  @todo
  Scenario: A team the service account cannot see cannot be mapped
    Given the Penpot team "Private Team" is visible to a user's personal token
    But the service account has not been invited to "Private Team"
    When the admin tries to map "Private Team"
    Then the mapping is refused
    And the refusal explains the service account must be invited as "viewer" first
    And no Team Folder is provisioned
    # Better an honest refusal now than a mapping that silently pulls nothing.

  @todo
  Scenario: There is no project-level mapping to configure
    Given the Penpot team "Ferronescotia" is mapped
    Then the mapping list shows exactly 1 mapping, for the team
    And no per-project mapping can be added, configured, or removed
    And project subfolders exist only because the pull created them

  # ── permissions and fallback ─────────────────────────────────────────────────

  # Team Folders are admin-configured by default (groupfolders' own documentation
  # and this cluster's live config, checked directly — no delegation configured).
  @todo
  Scenario: Mapping a team into a Team Folder requires Team Folder creation rights
    Given the acting Nextcloud user does not hold Team Folder admin or delegated rights
    When that user tries to map a Penpot team to a new Team Folder
    Then the action is refused or requires an admin-side step
    And the refusal explains that Team Folder creation is admin-configured by default

  # The fallback tier — same "optional dependency" precedent both sibling apps'
  # TeamFolderService.php already establish, mirrored here for the team level.
  @todo
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

  @todo
  Scenario: A mapped folder's name is not independently chosen at mapping time
    When the admin maps the Penpot team "Ferronescotia"
    Then the provisioned Team Folder is named "Ferronescotia", matching Penpot exactly
    And the mapping UI does not offer a separate "call it something else" field

  @todo
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

  @todo
  Scenario: A team renamed in Penpot renames its Team Folder on the next pull
    Given the Penpot team "Ferronescotia" is mapped
    When the team is renamed to "Ferronescotia Design" in Penpot
    And the pull runs
    Then the Team Folder is renamed to "Ferronescotia Design"
    And the mapping still resolves, because it is keyed on the team id, not the name

  @todo
  Scenario: A mapping records the default mode its files get, defaulting to link
    When the admin maps the Penpot team "Ferronescotia" without choosing a mode
    Then the mapping's default mode is "link"
    When the admin maps the Penpot team "Design Co" with default mode "sync"
    Then the mapping's default mode is "sync"
    # Per-file promotion/demotion is sync-mode.feature's concern, not this one.

  # ── folder mode: how this team's projects map to folders (saga §6.53) ───────
  # A per-mapping, IMMUTABLE choice between two mutually-exclusive models:
  #
  #   nested (default) — Penpot projects are flat names; Nextcloud nests freely
  #                      under the team folder (§6.29). A "/" in a project name
  #                      is INVALID, because it would mean nothing.
  #   keyed            — a project's name IS its path relative to the team folder
  #                      ("foo/bar" → Team/foo/bar/). Moving a project folder is
  #                      renaming it. Free nesting does not apply, because
  #                      position IS the name.
  #
  # The two cannot coexist: either "/" carries structure or it doesn't. Making
  # the choice explicit per team is what removes the awkward middle case.

  @todo
  Scenario: A mapping records its folder mode, defaulting to nested
    When the admin maps the Penpot team "Ferronescotia" without choosing a folder mode
    Then the mapping's folder mode is "nested"

  @todo
  Scenario: The folder mode cannot be changed after the mapping is created
    Given the Penpot team "Ferronescotia" is mapped with folder mode "nested"
    When the admin tries to change that mapping's folder mode to "keyed"
    Then the change is rejected as immutable
    And the rejection explains the mapping must be removed and re-added
    # Flipping it live would restructure every folder under the mapping AND
    # rewrite every project name in Penpot — a bulk, two-sided, destructive
    # migration behind a dropdown. Same immutability precedent both sibling apps
    # already set for a mapping's structural fields.

  @todo
  Scenario: A team can be mapped in keyed mode
    When the admin maps the Penpot team "Design Co" with folder mode "keyed"
    Then the mapping's folder mode is "keyed"
    And project names are treated as paths relative to the Team Folder
    # DESIGNED, NOT BUILT (saga §6.53). Only the fork is locked — keyed mode has
    # no feature file of its own and several open questions (inferred-folder
    # ownership, key collisions, what a move out of the team means). Do not
    # implement against this scenario.

  @todo
  Scenario: A Penpot team may only be mapped once
    Given the Penpot team "Ferronescotia" is already mapped
    When the admin tries to map the Penpot team "Ferronescotia" again
    Then the mapping is rejected
    And there is still exactly 1 configured team mapping
