# Copying a mirrored ".penpot" file. Both sibling apps treat a copy as "always a
# brand-new remote resource" — the copy is registered as a new n8n workflow /
# Grafana dashboard with its own id, because their files hold live, pushable
# content and a copy is the safest point to strip identity before that content
# could hijack the original.
#
# PENPOT HAS A REAL COPY ENDPOINT, AND WE STILL DON'T CALL IT ON Ctrl+C.
# `duplicate-file` is real and confirmed working live (saga §6.28): one call,
# `{fileId, name?}`, returns a full new file record. Notably it DOES honour the
# `name` param, unlike `import-binfile` (§6.20) — so a Penpot-side copy is one
# call, not an import-then-rename pair. It also takes camelCase `fileId`, unlike
# import's kebab-case: THREE commands now confirmed to disagree on casing, so
# the client must encode this per command rather than assume a convention.
#
# BUT A FILE-MANAGER COPY IS NOT A REQUEST TO CREATE A DESIGN. Someone dragging
# a file with Ctrl held is organising their files, not authoring work in Penpot.
# Silently creating a design there would be exactly the kind of surprise write
# this app refuses to make (saga §6.1) — and it would be worse than the siblings'
# equivalent, because a Penpot design is a heavyweight object a team will see
# appear out of nowhere. Deliberate creation has its own action
# (create-design.feature) and its own menu entry.
#
# THE AMBIGUITY AN EARLIER DRAFT LEFT OPEN, NOW CLOSED: it said a copy inside a
# mapped folder keeps the SAME "penpot_id", then admitted in a comment that "only
# one of them keeps being refreshed — the other becomes a stale, unmanaged
# duplicate," without saying which. That's ambiguous exactly where it matters:
# reconcile updates files in place matched by penpot_id, so two files with one id
# under the same project give the pull an ambiguous target.
#
# THE RULE: A COPY UNDER A MAPPED PROJECT IS STRIPPED OF ITS "penpot_id" and
# becomes ordinary untracked local content. Exactly one file per penpot_id under
# a given project. This costs nothing — the copy is still a perfectly good
# .penpot archive — and removes the ambiguity entirely.
#
# Copying OUTSIDE any mapping is different: there's no pull to confuse, so the id
# is kept as a historical record of where the archive came from. It's inert, and
# it's what makes a later restore possible (restore.feature).
#
# @todo — no lib/Listener/CopyListener exists yet.

@todo
Feature: Copying a mirrored Penpot file never creates anything in Penpot
  As a Nextcloud user
  I want a copy to be an inert local duplicate
  So that duplicating a file is safe and never confuses the mirror or surprises my team

  Background:
    Given the app is connected to Penpot
    And a Team Folder mapped to the Penpot team "Ferronescotia"
    And the Penpot project "My Stuff" is mirrored as a folder inside it

  Scenario: A copy made under a mapped project becomes untracked local content
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file within that folder
    Then the copy has no "penpot_id"
    And the original keeps its "penpot_id" and stays the managed mirror
    And no new design is created in Penpot
    And there is still exactly one real design in Penpot
    # Exactly one file per penpot_id under a project — otherwise the pull has two
    # candidates for "update in place" and no way to choose.

  Scenario: The copy keeps its content and is a valid archive on its own
    Given a mirrored ".penpot" file in "sync" mode in the "My Stuff" folder
    When I copy the file within that folder
    Then the copy holds the full ".penpot" archive content
    And the copy is a valid ZIP that opens outside Nextcloud
    # Stripping identity never strips bytes — don't-lose-data.

  Scenario: A copy nested deeper under the same project is still stripped
    Given a plain subfolder "wip" inside the "My Stuff" folder
    When I copy a mirrored ".penpot" file into "wip"
    Then the copy has no "penpot_id"
    # "Under a mapped project" is the nearest-ancestor question (saga §6.29), not
    # a same-folder question — a subfolder resolves to the same project.

  Scenario: A pull leaves the untracked copy alone
    Given a mirrored ".penpot" file and an untracked copy of it in the same folder
    When the pull runs
    Then the original is refreshed normally
    And the copy is never refreshed, renamed, moved, or pruned
    # Tolerated content — the pull only touches files it recognizes by metadata.

  Scenario: Copying a mirrored file outside every mapping keeps its id as a record
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When I copy the file to a folder with no Penpot ancestor
    Then the copy keeps its "penpot_id" metadata as a historical record
    And the copy is "unmapped" — it is not refreshed by any future pull
    And no new design is ever created in Penpot for the copy
    # Safe here because no mapping's pull can be confused by it, and the id is
    # genuinely useful: it records which design this archive came from.

  Scenario: Copying an untracked ".penpot" file changes nothing about Penpot
    Given a ".penpot" file with no "penpot_id" (never tracked)
    When I copy the file anywhere
    Then the copy also has no "penpot_id"
    And Penpot is never contacted

  Scenario: No copy, anywhere, ever writes to Penpot
    Given a mirrored ".penpot" file
    When I copy it anywhere at all
    Then "duplicate-file" is never called
    And no create, rename, or destructive call is ever made against Penpot
    And the design in Penpot is completely unaffected

  # Copying a project FOLDER is a different question, and the answer is "no" —
  # see project-folder.feature. Briefly: the copy would claim the same project id
  # for a whole tree, Nextcloud's "(2)" suffix instantly breaks the
  # names-always-match rule, and on this cluster the same folder may also carry
  # n8n and Grafana mappings (saga §6.40).
  Scenario: Copying a project folder is refused, unlike copying a file
    Given the Penpot project "My Stuff" is mirrored as a folder
    When I try to copy that folder
    Then the copy is refused with an explanation
    And copying an individual ".penpot" file remains unaffected

  # ── the deliberate alternative, available but not adopted ───────────────────

  @todo
  Scenario: A deliberate "Duplicate in Penpot" action would be one cheap call
    Given a mirrored ".penpot" file in the "My Stuff" folder
    When a user explicitly asks to duplicate the design in Penpot
    Then "duplicate-file" creates a real copy in the same Penpot project
    And the new design gets its own id and the requested name
    And the next pull mirrors it as a new file
    # PROPOSED, NOT ADOPTED (saga §6.28). The mechanism is confirmed working and
    # cheap — no archive round-trip, no id collision, name honoured. Recorded so
    # a future chapter can adopt it deliberately rather than re-deriving it. It
    # is NOT wired to the ordinary file-manager copy gesture, and must not be.
