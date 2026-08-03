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
# A MAPPING HOLDS TWO NAMES, AND NEITHER IS THE OTHER (saga §C6.29). The Penpot
# TEAM NAME is server-authoritative — read back from Penpot on every pull, never
# supplied by the admin (§6.13 point 3). The NEXTCLOUD FOLDER NAME is the admin's,
# and defaults to the team's name only because that is the useful default. They
# may differ, and a rename on the Penpot side does not move the admin's folder.
#
# An earlier draft of this header said the folder name "tracks Penpot's team name
# via the pull". That was one name for a two-name object, and the scenarios below
# now contradict it outright.
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

  Scenario Outline: Creating a mapping saves the form
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    And an unset field on the mapping form defaults to:
      | folder      | Northwind           |
      | mode        | link                |
      | groups      |                     |
      | folder mode | nested              |
      | storage     | plain shared folder |
    When the admin maps "Northwind" with:
      | folder  | <folder>  |
      | mode    | <mode>    |
      | groups  | <groups>  |
      | storage | <storage> |
    Then the mapping matches the form, unset fields at their defaults

    Examples: one field at a time, and nothing at all
      | folder       | mode | groups       | storage     |
      |              |      |              |             |
      | Design Files |      |              |             |
      |              | link |              |             |
      |              | sync |              |             |
      |              |      | admin        |             |
      |              |      | admin,design |             |
      |              |      |              | team folder |

    Examples: and in combination
      | folder       | mode | groups       | storage     |
      | Design Files | sync | admin,design | team folder |
      | Northwind    | link | admin        | team folder |

    # ONE BEHAVIOUR, AND EVERY ROW CHECKS ALL OF IT.
    #
    # Creating a mapping is a form submission: whatever the admin typed comes
    # back, and whatever they left alone comes back as its default. That is the
    # whole of it, so it is ONE scenario — the rows are inputs, not behaviours.
    # Choosing "sync" is not a different behaviour from choosing "link"; none of
    # these values can even be OBSERVED until something later acts on one (the
    # mode decides whether a file's bytes are held, the groups and storage decide
    # what the pull provisions, the folder mode decides how project names become
    # paths).
    #
    # THE DEFAULTS ARE DECLARED AS DATA, not asserted one scenario at a time.
    # There were five near-identical scenarios here, each mapping a team and
    # reading back one field, and the storage default among them was WRONG for as
    # long as it took to notice — a Team Folder needs the optional groupfolders
    # app, so the no-choice mapping asked for a backend a stock Nextcloud does not
    # have (§C6.31). Five titles hid that. One column does not.
    #
    # AND EVERY ROW ASSERTS ALL FIVE FIELDS, which is what the earlier drafts got
    # wrong: a row that sets the mode is also proving it did not disturb the
    # folder. Rows 1 and 2 together are the whole of "the folder name is the
    # admin's, and defaults to the team's" — the two names are independent, and it
    # costs a row rather than a scenario. The last row is a Team Folder named
    # exactly as its team, which is legal and worth pinning: nothing about the
    # storage backend constrains the name.
    #
    # "folder mode" is in the defaults table but no row sets it — its only other
    # value is REFUSED (keyed is designed and not built, §6.53), so every row
    # asserts the default and the refusal lives with the other refusals below.

  @todo
  Scenario: Renaming the team in Penpot does not rename the admin's folder
    Given a Penpot team named "Northwind" is mapped to the folder "Design Files"
    When the team is renamed in Penpot
    And the pull runs
    Then the mapping's Nextcloud folder is still "Design Files"
    # The folder name was the admin's choice; the team name was only ever its
    # default. Silently moving their folder because someone renamed a team
    # upstream would be a surprise, not a sync.

  @todo
  Scenario: Two mappings cannot target the same Nextcloud folder
    Given a Penpot team named "Northwind" is mapped to the folder "Designs"
    When the admin maps another team into the folder "Designs"
    Then the mapping is rejected
    And the refusal explains "already used"
    # Two teams mirroring into one folder would interleave their project
    # subfolders, and the pull would fight over the same names on every run.

  Scenario Outline: A value the app cannot honour is refused, and says why
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    When the admin maps "Northwind" with:
      | folder      | <folder>      |
      | folder mode | <folder mode> |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured team mappings

    Examples: the same form, and the two values it will not take
      | folder       | folder mode | reason             |
      | teams/design |             | single folder name |
      |              | keyed       | not implemented    |

    # A PATH IS NOT A FOLDER NAME. Everything below the team folder is created by
    # the pull from Penpot's own project names, so an inner "/" would invent an
    # intermediate folder no Penpot object corresponds to, and nothing would ever
    # clean it up.
    #
    # KEYED IS DESIGNED, NOT BUILT. Accepting it and behaving as "nested" would be
    # a silent lie the admin could only detect from the resulting folder layout
    # (saga §6.53, question #47).
    #
    # Both are refusals of an OPTION, which is why they share a table with each
    # other and not with the refusals below — those are about the TEAM (it is not
    # there, it is already mapped), and no option would have changed them.

    # ── sharing: groups + Team Folder, exactly as the siblings do it ────────────
    # Same two controls, same meanings, same defaults as nextcloud-n8n and
    # nextcloud-grafana, so an admin configuring all three does the same thing each
    # time. They PERSIST today and are honoured when the pull provisions the folder
    # (Course 3) — the same "saved now, applied later" state Grafana ships them in.

    # ── what a mapped folder LETS YOU DO (saga §C6.8) ─────────────────────────
    #
    # A mapped folder is an ORDINARY Nextcloud folder that happens to be mirrored.
    # Its groups get read, write, create and delete — the same surface any other
    # folder grants, and the same surface both siblings grant.
    #
    # AN EARLIER BUILD WITHHELD CREATE AND DELETE to express "the mirror is
    # read-only" (§6.1). That was the wrong tool, and the damage was invisible from
    # the code: Nextcloud hides the "+ New" button entirely on a folder with no
    # CREATE, so mapped folders silently behaved unlike every other folder in the
    # instance — no new file, no new subfolder, no paste. It also made three BUILT
    # features unreachable:
    #
    #   - free nesting (§6.29), the app's most load-bearing rule, whose entire
    #     point is that a user may group mirrors into plain subfolders of their own;
    #   - the move write-back, since a cross-folder move needs DELETE on the source;
    #   - "a mapped folder stays usable as an ordinary folder", which the prune
    #     relies on when it leaves unstamped files alone.
    #
    # §6.1 still holds absolutely — it is enforced by there being no content push,
    # not by a permission bit. Withholding CREATE stopped no write to Penpot; it
    # only stopped the user from using their own files.

  @todo
  Scenario: A pull re-asserts the folder's group rights
    Given a folder mapped to the Penpot team "Northwind"
    And its group rights have been changed by hand
    When the pull runs
    Then the mapping's groups hold read, update, create and delete again
    # This is what lets a permissions correction reach folders that already
    # exist: neither backend needs a migration, because both re-assert on every
    # pass. It also means hand-editing the share is not a supported way to
    # restrict a mapped folder — remove the group from the mapping instead.

  Scenario: A team id that resolves to nothing cannot be mapped
    Given no Penpot teams are mapped
    When the admin tries to map the team id "11111111-2222-3333-4444-555555555555"
    Then the mapping is rejected
    And the refusal explains "not visible to the service account"
    And there are exactly 0 configured team mappings
    # Better an honest refusal than a mapping that silently pulls nothing.
    #
    # AND THIS IS THE WHOLE OF IT. There used to be a second scenario here for a
    # team that EXISTS but has not invited the service account. From this side of
    # the wire the two are one case: `get-teams` is membership-scoped (§6.12), so
    # a team we were never invited to is a team that is not there. Testing the
    # difference would be testing Penpot's own permission model, which is not
    # ours to prove — the token works or nothing in this suite runs at all.

  Scenario: A Penpot team may only be mapped once
    Given a Penpot team named "Northwind" is mapped to the folder "Design Files"
    When the admin maps the team "Northwind" into the folder "Design Files"
    Then the mapping is rejected
    And there is exactly 1 configured team mapping
    # The pre-state is a mapping that already exists, so the scenario opens where
    # the interesting part starts. It used to map twice inside the `When` block,
    # which put half the setup in the behaviour and needed a "the same team
    # again" step to refer back to a team it had never named.

  Scenario: Removing a mapping deletes nothing
    Given no Penpot teams are mapped
    And a Penpot team named "Northwind" exists
    When the admin maps the Penpot team "Northwind"
    And the admin removes that mapping
    Then there are exactly 0 configured team mappings
    And removing it reported that nothing was deleted
    # Nothing is removed from Penpot and nothing local is removed either. What
    # SHOULD happen to already-mirrored files is Course 5's decision
    # (remove-mapping.feature) — until then the safe behaviour is to leave them
    # and say so.

    # ── the folder itself ───────────────────────────────────────────────────────
    #
    # THERE IS NO SCENARIO HERE, AND THAT IS THE POINT. "A mapped folder exists"
    # is not something an admin does — it is what a mapping IS. Saving one builds
    # the folder (§C6.32), so a scenario asserting the folder appeared would be
    # asserting that `add-mapping` did its own job, one step removed.
    #
    # It used to be `Mapping a Penpot team provisions a Team Folder and mirrors
    # its projects`, which was two claims and both belonged elsewhere: the mirror
    # is sync-now.feature's, and the folder is now a postcondition of creating the
    # mapping at all. A briefly-live version asserted it after a pull, which only
    # documented the OLD timing — that the folder did not appear until a sync ran.
    #
    # ensureRoot() is idempotent and PullService still calls it every pass, so a
    # folder deleted by hand comes back. That is repair, not a feature: nobody
    # asked for it and nobody watches it happen.

  @decision
  Scenario: There is no project-level mapping to configure
    Given the Penpot team "Northwind" is mapped
    Then the mapping list shows exactly 1 mapping, for the team
    And no per-project mapping can be added, configured, or removed
    And project subfolders exist only because the pull created them

    # ── permissions and fallback ─────────────────────────────────────────────────

  @todo
  Scenario: Project folder names always match their Penpot projects
    Given the Penpot team "Northwind" is mapped and pulled
    Then every project folder is named exactly as Penpot names that project
    And the app never lets a project folder's name diverge from its project's
    # Locked in saga §6.36, in both directions: Penpot renames propagate down on
    # the pull, and renaming a project folder in Nextcloud calls "rename-project".
    # Position is free (§6.29); only the NAME is pinned. This is what earns the
    # project tag its keep — a tagged folder named "Acme" IS the project "Acme".

  @todo
  Scenario: Two Penpot projects in one team sharing a name is handled, not crashed
    Given the "Northwind" team has two projects both named "Brand"
    When the pull runs
    Then both are mirrored without a folder-name collision
    And the app reports the ambiguity so an admin can rename one in Penpot
    # Penpot permits duplicate project names; Nextcloud does not permit duplicate
    # sibling folder names. Free nesting means the second folder can live
    # elsewhere, but the exact rule is undecided — saga open question #31.

  @todo
  Scenario: A team renamed in Penpot does not rename the mapped folder
    Given the Penpot team "Northwind" is mapped
    When the team is renamed to "Northwind Design" in Penpot
    And the pull runs
    Then the mapped folder keeps the name the admin gave it
    And the mapping records the team's new name
    And the mapping still resolves, because it is keyed on the team id, not the name
    # SUPERSEDES an earlier draft in which the pull renamed the Team Folder to
    # follow. That predates admin-chosen folder names: silently moving someone's
    # folder because a team was renamed upstream is a surprise, not a sync. The
    # recorded team name still updates, so the admin page shows the truth.
    #
    # Note this is the opposite of the PROJECT folder rule below, and
    # deliberately so: a team folder is a mount point the admin chose to create,
    # a project folder is a mirror of a Penpot object.

    # ── what can be changed, which is one thing ─────────────────────────────────
    #
    # THERE IS NO EDIT, SO THERE IS NOTHING TO REFUSE. Four scenarios used to sit
    # here — the folder mode, the Nextcloud folder, the Team Folder flag and the
    # default mode, each saying "the admin tries to change it and is told no".
    # None of them was reachable. There is no occ command that edits a mapping,
    # and the one HTTP endpoint takes `ncGroups` and nothing else; the service
    # signature is `updateGroups(id, groups)` (§C6.33), so a change to any other
    # field cannot be EXPRESSED, let alone refused.
    #
    # A scenario for a refusal that no caller can provoke is a scenario about an
    # error message. Immutability is a fact about the API's shape, and the place
    # to state it is where the shape is — MappingService::updateGroups()'s
    # docblock carries the reason for each locked field, and MappingServiceTest
    # pins that a group change moves nothing else.
    #
    # WHY THESE FIELDS ARE LOCKED, in one line: changing any of them would force a
    # LIVE MIGRATION of already-mirrored content — moving the whole tree,
    # re-stamping every file, migrating a provisioned folder and its shares,
    # rewriting every project name in Penpot, or deleting every downloaded archive
    # at once. Removing the mapping and adding it again makes that cost visible
    # instead of hiding it behind a dropdown, which is the same line
    # nextcloud-grafana draws.

  Scenario: The groups a mapped folder is shared with can be changed
    Given a Penpot team named "Northwind" is mapped to the folder "Northwind Shared"
    When the admin changes that mapping's groups to "design,admin"
    Then the mapping's groups are "design,admin"
    # THE ONE EDIT, and it is the one that moves no content — re-sharing a folder
    # is not a migration. Everything else about a mapping is settled when it is
    # created.
    #
    # The MAPPING is what changes here. Re-sharing the provisioned folder is
    # ensureRoot()'s, re-asserted on every sync, which is why this scenario stops
    # at what the admin actually gets an answer about.

  @unbuilt
  Scenario: A team can be mapped in keyed mode
    When the admin maps the Penpot team "Design Co" with folder mode "keyed"
    Then the mapping's folder mode is "keyed"
    And project names are treated as paths relative to the Team Folder
    # DESIGNED, NOT BUILT (saga §6.53). Only the fork is locked — keyed mode has
    # no feature file of its own and several open questions (inferred-folder
    # ownership, key collisions, what a move out of the team means). Do not
    # implement against this scenario.
