# THE LIVE HALF IS gestures.feature — copy in place, copy up to the team root,
# and the copy-then-rename chain, driven over WebDAV against a real Penpot.
#
# Copying a mirrored ".penpot" file. A copy in Nextcloud becomes a REAL new
# design in Penpot — full parity with both siblings, which register a copy as a
# new n8n workflow / Grafana dashboard for the same reason: a copy is a new
# thing, and leaving it inert makes the file a lie about what it is.
#
# ── THIS FILE REVERSED A DECISION. READ WHY BEFORE REVERSING IT BACK ─────────
#
# An earlier version said, in its loudest scenario, "No copy, anywhere, ever
# writes to Penpot", and filed the duplicate as "PROPOSED, NOT ADOPTED". The
# argument was §6.1: a Ctrl+C is someone organising files, not authoring work,
# and a Penpot design is a heavyweight object a team would see appear from
# nowhere.
#
# That argument was overturned deliberately (saga §C6.8), on two grounds:
#   1. §6.1 is about CONTENT never flowing back — the shape data of a design is
#      never pushed, and still never is. Creating a *container* on an explicit
#      user gesture is a different act, and it is the one both siblings make.
#   2. The alternative was worse, not safer. An inert copy is a ".penpot" file
#      that opens nothing and is indistinguishable from a real mirror — exactly
#      the "quiet lie" prune.feature and restore.feature exist to prevent.
#
# The mechanism was then PROVEN live rather than assumed (§C6.8): duplicate-file
# takes kebab `file-id` + optional `name` (≤250), honours the name, and returns
# a full record. It has NO project parameter, so the duplicate always lands in
# the SOURCE file's project.
#
# ── WHICH IS WHY ONE GESTURE IS TWO MECHANISMS ──────────────────────────────
#
# Where the copy LANDS decides how many calls it takes. Both are the same
# feature; they differ only in what the nearest-ancestor walk (§6.29) returns:
#
#   lands in the SAME project   → duplicate-file                  (one call)
#   lands in ANOTHER project    → duplicate-file + move-files     (two calls)
#   lands outside every mapping → nothing is created at all
#
# move-files takes `project-id` + `ids` (a set) and answers 204 — including on a
# just-created duplicate, confirmed live. Note this is a THIRD spelling beside
# duplicate's `file-id` and delete's plain `id`: the client encodes per command.
#
# ── MODE DOES NOT MATTER HERE, AND THAT IS A PENPOT-ONLY PROPERTY ───────────
#
# The siblings copy by pushing the file's own JSON, so a copy of a pointer would
# have nothing to push. `duplicate-file` copies the DESIGN, server-side, from an
# id — no bytes travel. So a `link` file (zero bytes, §C6.6) duplicates exactly
# as well as a `sync` file. Neither mode is a special case, and no export is
# needed to copy.
#
# ── THE NAME COMES FROM THE COPY, NOT THE ORIGINAL ──────────────────────────
#
# Nextcloud names a copy itself ("My firsty (copy).penpot"). That name is the
# user's stated intent, so it is what the new design is called — extension
# stripped (§6.4), truncated to Penpot's 250-char limit. This is the one place a
# name flows Nextcloud → Penpot at creation time rather than being followed.
#
# @todo — no lib/Listener/CopyListener exists yet.

Feature: Copying a mirrored Penpot file creates a real copy in Penpot
  As a Nextcloud user
  I want copying a design file to give me a real, separate design
  So that a duplicate is something I can open and work on, not a dead file

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

    # ══ COPIED IN NEXTCLOUD ════════════════════════════════════════════════════
    #
    # Driven as real WebDAV COPYs against a real Penpot. Three bugs came out of the
    # gap where these had no live test (§C6.8/§C6.9/§C6.10) — a param bug believed
    # for an hour, a copy that silently failed to record its id and presented as a
    # broken RENAME one gesture later, and a copy to the team root that did nothing
    # at all in Penpot while its unit test passed against a mock.

  @in-nextcloud @gesture
  Scenario: Copying in place creates a second design in the same project
    Given a mirrored design "Original" in the project "Copy Here"
    When I copy "Penpot/Copy Here/Original.penpot" to "Penpot/Copy Here/Original copy.penpot"
    Then the file "Penpot/Copy Here/Original copy.penpot" carries a Penpot id
    And the files "Penpot/Copy Here/Original.penpot" and "Penpot/Copy Here/Original copy.penpot" carry different Penpot ids
    And Penpot project "Copy Here" holds a design named "Original copy"
    # Different ids is the load-bearing one: two files claiming a single design
    # is the ambiguity that made the old inert-copy rule necessary at all.

    # OUTSIDE EVERY MAPPING, NOTHING HAPPENS — the boundary that makes the rest of
  # this file safe. A `.penpot` file the app never mirrored is ordinary content,
  # and copying ordinary content is Nextcloud's business alone.
  @in-nextcloud @gesture
  Scenario: Copying a ".penpot" file outside every mapping never contacts Penpot
    Given a mirrored project "Bystanders"
    And I upload a ".penpot" archive at "Loose Design.penpot"
    When I copy "Loose Design.penpot" to "Loose Design copy.penpot"
    Then the file "Loose Design copy.penpot" carries no Penpot id
    And Penpot project "Bystanders" holds no design named "Loose Design copy"
    # No penpot_id on the source means there is nothing to duplicate, and no
    # mapped ancestor means there is nowhere to put it. Both checks matter: a
    # file can carry an id and still be outside every mapping (drag one out and
    # it keeps its stamp), which is move.feature's "unmapped" state.

  # THE ONE THAT FAILED BY HAND. The team root has no project FOLDER above it, so
    # membership resolves to "no project" — which reads exactly like "outside every
    # mapping" and is nothing of the kind (§6.35). The copy appeared in Nextcloud
    # and nothing whatsoever happened in Penpot, with nothing logged.
  @in-nextcloud @gesture
  Scenario: Copying up to the team root creates the design in Drafts
    Given a mirrored design "Promote Me" in the project "Copy Up"
    When I copy "Penpot/Copy Up/Promote Me.penpot" to "Penpot/Promote Me copy.penpot"
    Then the file "Penpot/Promote Me copy.penpot" carries a Penpot id
    And Penpot project "Copy Up" holds no design named "Promote Me copy"

  @in-nextcloud @gesture
  Scenario: A copy can be renamed immediately, because it was tracked
    Given a mirrored design "Before" in the project "Chain"
    And I copy "Penpot/Chain/Before.penpot" to "Penpot/Chain/Before copy.penpot"
    When I rename "Penpot/Chain/Before copy.penpot" to "After.penpot"
    Then Penpot project "Chain" holds a design named "After"
    And Penpot project "Chain" holds no design named "Before copy"
    And Penpot project "Chain" holds a design named "Before"
    # The last line is the point: renaming the COPY must not touch the original.
    # A copy that failed to record its id presents as a broken rename, one
    # gesture later — which is how §C6.9 reached a human before a test.

    # ── the two gestures, which are the same feature ──────────────────────────

  @todo
  Scenario: Copying inside the same folder duplicates the design in place
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file within that folder
    Then "duplicate-file" is called with the original's "penpot_id"
    And the new design is created in the "My Stuff" project
    And "move-files" is never called, because the project did not change
    And the copy carries the NEW design's "penpot_id", never the original's
    And the original keeps its own id and is completely unaffected

  @todo
  Scenario: Copying up to the team root duplicates it, then moves it to Drafts
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file to the team folder root
    Then "duplicate-file" is called with the original's "penpot_id"
    And "move-files" then moves the new design into that team's Drafts project
    And the copy carries the NEW design's "penpot_id"
    # Drafts is a state, not a folder (§6.35) — the team root resolves to the
    # team's default project, which is a real project id like any other.

  @todo
  Scenario: Copying into another project folder lands it in that project
    Given a second mirrored project folder "Client Work"
    And a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file into "Client Work"
    Then the new design is moved into the "Client Work" project
    And the copy carries the NEW design's "penpot_id"

  @todo
  Scenario: Copying into a plain subfolder is still the same project
    Given a plain subfolder "wip" inside the "My Stuff" folder
    When I copy a mirrored ".penpot" file into "wip"
    Then the new design is created in the "My Stuff" project
    And "move-files" is never called
    # Nearest-ancestor at any depth (§6.29): a plain subfolder carries no project
    # id, so the walk keeps going up and finds "My Stuff".

    # ── walked by hand, and each one caught a real bug ────────────────────────
    #
    # These three came from a manual walkthrough rather than from design, and every
    # one of them failed the first time. They are kept in the order they were done,
    # because the ORDER is what exposed the bugs: each step was only reachable once
    # the previous one worked.

  @todo
  Scenario: A copy is tracked the moment it exists, so the next action works
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file within that folder
    Then the copy carries a "penpot_id" immediately
    And renaming the copy straight away renames its design in Penpot
    # THE FIRST WALKTHROUGH FAILED HERE, and blamed the wrong feature. The copy
    # silently failed to record its id, so the rename that followed had nothing
    # to push and did nothing — which presents as "rename is broken" (saga
    # §C6.9). A copy that does not track is not a copy problem the user can see;
    # it is a rename problem, a move problem, and a delete problem, later.

  @todo
  Scenario: Copying to the team root creates the design in Drafts
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file up one level, to the mapped team folder itself
    Then a new design appears in that team's Drafts in Penpot
    And the copy in Nextcloud carries that new design's id
    # THE SECOND WALKTHROUGH FAILED HERE. The team root has no project FOLDER
    # above it, so membership resolves to "no project" — which reads exactly like
    # "outside every mapping" and is nothing of the kind (§6.35). The copy was
    # created in Nextcloud and nothing at all happened in Penpot, silently
    # (§C6.10).

  @todo
  Scenario: A copy that cannot be tracked says so rather than looking finished
    Given a mirrored ".penpot" file
    When I copy it and Penpot cannot be reached
    Then the failure is logged with the file and the design it came from
    And the copy carries no "penpot_id"
    # The two failures above were both invisible from the Files app: a file
    # appeared, and nothing said otherwise. Whatever else a failed copy does, it
    # must not look like a completed one.

    # ── the name ──────────────────────────────────────────────────────────────

  @todo
  Scenario: The new design is named after the copy, not the original
    Given a mirrored ".penpot" file named "Login screen.penpot"
    When I copy it and Nextcloud names the copy "Login screen (copy).penpot"
    Then the new design in Penpot is named "Login screen (copy)"
    And the ".penpot" extension is never part of the Penpot name

  @todo
  Scenario: An over-long name is truncated rather than refused
    Given a mirrored ".penpot" file whose copy would exceed 250 characters
    When I copy it
    Then the name sent to Penpot is truncated to 250 characters
    And the copy is created rather than skipped
    # Penpot's limit is a schema max (§C6.8). Losing the tail of a name is a
    # smaller harm than refusing to copy the file at all.

    # ── mode is not a special case ────────────────────────────────────────────

  @todo
  Scenario: A link file copies exactly like a sync file
    Given a mirrored ".penpot" file in "link" mode, holding no bytes
    When I copy the file within its folder
    Then "duplicate-file" is called and a real new design is created
    And no export is ever performed
    # duplicate-file copies the design server-side from its id, so a pointer
    # with zero bytes duplicates as completely as a stored archive. The siblings
    # cannot do this — they copy by pushing the file's own content.

  @todo
  Scenario: A sync copy keeps its archive and is a valid file on its own
    Given a mirrored ".penpot" file in "sync" mode
    When I copy the file within its folder
    Then the copy holds the full ".penpot" archive content
    And the copy is a valid ZIP that opens outside Nextcloud
    # Stripping identity never strips bytes. The copy's archive is the ORIGINAL
    # design's bytes until the next pull re-exports it for the new id, which is
    # correct: at the instant of copying the two designs are identical.

    # ── where nothing is created ──────────────────────────────────────────────

  @todo
  Scenario: Copying outside every mapping creates nothing in Penpot
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file to a folder with no Penpot ancestor
    Then "duplicate-file" is never called
    And the copy keeps the original's "penpot_id" as a historical record
    And the copy is "unmapped" — no pull will ever refresh it
    # There is no project to create in, and inventing one would be the surprise
    # write §6.1 refuses. The id is inert here and genuinely useful: it records
    # which design these bytes came from, which is what makes restore possible.

  @todo
  Scenario: Copying an untracked ".penpot" file changes nothing
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I copy the file anywhere
    Then the copy also has no "penpot_id"
    And Penpot is never contacted

  @todo
  Scenario: The pull's own writes never look like a copy
    Given a pull is running
    When it writes a mirror file
    Then no duplicate is created in Penpot
    # The SyncGuard fences the pull out of the write-back listeners, exactly as
    # it does for rename and move.

    # ── failure ───────────────────────────────────────────────────────────────

  @todo
  Scenario: A failed duplicate leaves the Nextcloud copy standing
    Given a mirrored ".penpot" file
    When I copy it and "duplicate-file" fails
    Then the Nextcloud copy still exists with its content intact
    And the copy carries no "penpot_id" rather than the original's
    And the failure is reported
    # §6.18 rule 3: a remote failure never rewrites local state. Carrying the
    # original's id would be the worst outcome — two files claiming one design,
    # which is the ambiguity that made the old inert-copy rule necessary.

  @todo
  Scenario: Exactly one file per design id under a project, always
    Given a mirrored ".penpot" file and a copy of it in the same project
    When the pull runs
    Then each file is refreshed against its own design
    And neither file is renamed, moved, or pruned because of the other
    # This is what the new id buys. The old rule stripped the id to avoid two
    # candidates for "update in place"; giving the copy its own real id solves
    # the same problem without leaving a dead file behind.

    # ── folders are still refused ─────────────────────────────────────────────

  @todo
  Scenario: Copying a project folder is refused, unlike copying a file
    Given the Penpot project "My Stuff" is mirrored as a folder
    When I try to copy that folder
    Then the copy is refused with an explanation
    And no new Penpot project is created
    And no duplicate project folder is left behind
    And copying an individual ".penpot" file remains unaffected
    # DISABLED DELIBERATELY (saga §6.40), not merely unbuilt. Three reasons:
    #  (1) the copy would carry the same project id, so two folders claim one
    #      project — and every file in the copied tree would too;
    #  (2) Nextcloud auto-increments a copy to "My Stuff (2)", which instantly
    #      violates §6.36's names-always-match rule — and "fixing" it by rename
    #      would rename the ORIGINAL Penpot project;
    #  (3) on this cluster a single folder can also carry n8n and Grafana
    #      mappings, so a folder copy asks three independent apps to agree on
    #      what a duplicate means, with no coordination between them.

  @in-nextcloud @gesture @todo
  Scenario: Copying an ordinary folder inside a mapped folder is unaffected
    Given a plain folder "Clients" inside the mapped folder with no Penpot metadata
    When I copy it
    Then the copy succeeds normally
    And Penpot is never contacted
    # Only folders carrying a project id are refused. A mapped folder has to stay
    # usable as an ordinary folder, which is the same rule the tag opt-in rests
    # on (project-folder.feature).

  # ══ COPIED IN PENPOT ═══════════════════════════════════════════════════════
  #
  # THE ASYMMETRY IS THE FINDING, and it is why both directions belong in one
  # file even though only one of them is really "copying".
  #
  # A duplicate made in Penpot's own UI is, from Nextcloud's side, INDISTIN-
  # GUISHABLE FROM ANY OTHER NEW DESIGN. Penpot does not tell us a file was
  # duplicated — `get-project-files` returns a design with a fresh id and a name
  # like "Original (copy)", and nothing marks it as derived. So there is no
  # copy behaviour to implement on this side at all: the reconciler mirrors it
  # the way it mirrors anything new.
  #
  # That asymmetry is worth stating rather than discovering:
  #
  #   copied in Nextcloud  →  we CALL duplicate-file (+ move-files if it landed
  #                           in another project), because the gesture has to be
  #                           translated into something Penpot understands
  #   copied in Penpot     →  we call NOTHING. A new design appears and is
  #                           mirrored. The "copy" is invisible to us.
  #
  # Which means the two directions cannot be one scenario with a direction
  # column: one exercises a write path, the other exercises the reconciler doing
  # its ordinary job. Same word, two different rules — the exact case
  # features/README.md says must stay separate.

  @in-penpot @todo
  Scenario: A design duplicated in Penpot is mirrored like any other new design
    Given a mirrored design "Original" in the project "Shared Work"
    And the design "Original" is duplicated in Penpot
    When the team is mirrored again
    Then the file "Penpot/Shared Work/Original (copy).penpot" carries a Penpot id
    And the files "Penpot/Shared Work/Original.penpot" and "Penpot/Shared Work/Original (copy).penpot" carry different Penpot ids
    # No `duplicate-file` call of ours is involved. Needs a seed step that calls
    # duplicate-file directly on the Penpot side — the one thing missing to make
    # this live.

  @in-penpot @todo
  Scenario: A duplicate made in Penpot inherits the mapping's mode, not the original's
    Given a mirrored design "Original" in the project "Shared Work"
    And "Penpot/Shared Work/Original.penpot" is a "sync" design
    And the design "Original" is duplicated in Penpot
    When the team is mirrored again
    Then the file "Penpot/Shared Work/Original (copy).penpot" is in "link" mode
    # THE DIFFERENCE THAT MATTERS, and the reason this pair earns its place: a
    # Nextcloud-side copy inherits nothing because the app creates the mirror
    # knowing where it came from, while a Penpot-side duplicate arrives as a
    # stranger and takes the mapping's default like any other new design. Two
    # designs that look identical in Penpot can therefore mirror in different
    # modes, purely because of where the duplicate was made.
