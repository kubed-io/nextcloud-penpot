<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Feature notes

The reasoning behind `features/*.feature` — why a scenario exists, what it
replaced, which decision it encodes, and what was deliberately left out.

It lives here rather than in the feature files because Gherkin is meant to be
read as specification: a scenario should be legible at a glance, and a comment
should add scope or a tidbit, not carry an essay. The essays are here, one
section per feature file, and each feature file links to its section on line 1.

**The budget is two lines, and CI enforces it.** A comment block in a `.feature`
may carry at most two lines of prose; anything longer belongs here, behind a
`# notes: AGENTS.md#anchor` breadcrumb. `tests/integration/bin/check-notes-anchors.sh`
checks both halves — that every breadcrumb resolves to a real heading, and that no
block is over budget — because both rot silently. Rename a scenario and its anchor
stops matching with nothing to notice; let prose creep back and the spec quietly
stops being readable, which is how this file came to be as long as the suite it
explains.

For how the suite is organised — tags, backends, which scenarios CI runs and
why — see [README.md](README.md).

> Written for whoever picks this up next, human or agent. If you change a
> behaviour, change the note that explains it in the same commit; a note that
> describes the old behaviour is worse than no note.

## connection/admin

`features/connection/admin.feature`

CONFIGURING THE APP IS ONE ACT, and this file is two scenarios: it works, or it
does not and says which field is wrong.

### connection/admin

**This replaced a 31-scenario `connection.feature`** — itself
`admin-connection.feature` with `personal-settings.feature` folded in. It broke
almost every rule this suite has:

| | |
|---|---|
| five scenarios with **no `When` at all** | "The URL card carries no credential field", "Users do not author their own team mappings", "The app assumes one Nextcloud user maps to one Penpot account" — form structure, a capability that will never exist, and an assertion that a page's *prose documents an assumption* |
| two scenarios with **two `When`s** | both "distinguishes unset from rejected", each rebuilding its pre-state by performing another action |
| three duplicate pairs | the fold-in created them, and nothing deduped |
| thirteen `@blocked` with no capability named | the one thing `README.md` requires of the tag |

THE CONNECTION IS ONE FACT, SO IT IS ONE TABLE. The URL, the credential and the
schedule are all inputs to "the app is connected". They used to be three cards
and a scenario each, which made configuring the app look like three behaviours.
The schedule especially: an interval is a setting, not something a person
performs — which is also why `admin-section.feature`'s two scheduled-pull
scenarios went with it.

A cell names what KIND of value it is (`the test instance`, `a valid token`)
rather than the value, because the real URL and token come from the environment
the mint step built. A scenario cannot know them, and pinning them would tie the
spec to one CI fixture.

### An admin enters bad connection details

THE MESSAGE HAS TO NAME THE FIELD. "It did not work" is the failure this guards
against: an admin looking at a URL, a token and a schedule needs to be told which
one to fix, and the two token failures have different fixes — an absent token
means finish configuring, a rejected one means mint a new one (or turn on
`enable-access-tokens`, which is off by default upstream and whose absence looks
exactly like a typo).

Both rows are token failures today because the URL is validated at *set* time,
not at test time — `connection/admin.feature`'s own outline covers a malformed
URL being refused before it is ever stored.

### WHAT WENT, AND WHERE

The attribution and fallback scenarios — a write retried as the service account,
a degraded attribution reported once, only an authorisation failure falling back
— are **not connection scenarios**. Each one's `When` is a gesture ("the user
renames a mirrored design"), so the behaviour is the gesture; attribution is its
end state, and `designs/rename.feature` already owns it. They are recorded here
rather than kept as a file of their own.

## connection/personal

`features/connection/personal.feature`

THE SAME ACT FROM THE OTHER END. An admin connects the instance — a URL, a
credential and a schedule. A user connects only themselves — a token, against the
URL the admin already gave. Different pre-state, different end state, so separate
scenarios; the same act, so the same folder.

A personal token buys ATTRIBUTION and personal projects, nothing else. It never
widens what the app mirrors and is never used for the scheduled pull (saga
§6.18): one puller, always, or the shared-Team-Folder race returns. That is a
property of the design rather than a behaviour anyone performs, so it is written
here and not as four near-identical `@blocked` scenarios asserting a token was
not used.

## team-mapping/create

`features/team-mapping/create.feature`

"Admin makes a mapping" — the team-mapping list in admin settings, driven over
the CLI (the same operations the Settings panel performs).

A MAPPING IS A TEAM. THAT IS THE WHOLE OBJECT (saga §6.24, refining §6.13).
Penpot's hierarchy is a confirmed, hard, structural three levels — team
`contains` project `contains` file (§6.5, verified against /api/doc's param
schemas: a project record cannot even be represented without its team). But
only the TOP level is a mapping:
  - A Penpot TEAM maps to a Nextcloud Team Folder (or a plain-folder +
    group-sharing fallback when groupfolders isn't installed/delegated —
    mirroring both siblings' TeamFolderService "optional dependency" precedent).
  - Penpot PROJECTS are NOT mapped. They are MIRRORED — every project in the
    team appears as a folder created and named by the pull, initially one level
    inside the Team Folder. There is no project mapping to add, configure, or
    remove. Users may reorganise those folders freely within the Team Folder
    (saga §6.29); an earlier "exactly one level, hard cap" rule is withdrawn.
An earlier draft had users mapping projects individually. That could never work
coherently: the next pull would immediately recreate any subfolder you removed.
One mapping object, one lifecycle.

THE SERVICE ACCOUNT IS A PRECONDITION, NOT A CONVENIENCE (saga §6.18, locked):
a team cannot be mapped unless the service account can actually see it — which
means someone with authority over that Penpot team has invited it as `viewer`.
This is not us being strict; it's Penpot's model. §6.12 confirmed NO credential
gets an instance-wide view (get-teams is membership-scoped, always). Requiring
the invite up front makes that visible, instead of silently creating a mapping
that pulls nothing forever.

A MAPPING HOLDS TWO NAMES, AND NEITHER IS THE OTHER (saga §C6.29). The Penpot
TEAM NAME is server-authoritative — read back from Penpot on every pull, never
supplied by the admin (§6.13 point 3). The NEXTCLOUD FOLDER NAME is the admin's,
and defaults to the team's name only because that is the useful default. They
may differ, and a rename on the Penpot side does not move the admin's folder.

An earlier draft of this header said the folder name "tracks Penpot's team name
via the pull". That was one name for a two-name object, and the scenarios below
now contradict it outright.

MODE IS A MAPPING DEFAULT, NOT A MAPPING PROPERTY (saga §6.22): a mapping
carries the default mode its files get ("link" unless set otherwise), but any
individual file can be promoted or demoted afterwards — see set-mode.feature.

WHAT'S DELIBERATELY NOT HERE: creating a NEW Penpot team or project FROM
Nextcloud is a separate, still-open fork — see team-import.feature.

PARTIALLY LIVE. The MAPPING LIFECYCLE — add, refuse, list, remove, and the
defaults a new mapping gets — runs for real in CI against a real Penpot, and
those scenarios are untagged below. Everything that depends on the PULL (Team
Folder provisioning, project subfolders, rename propagation) is still @todo:
MappingService and the admin surface exist, the sync engine does not.

### The preconditions

ONE SENTENCE PER FACT, AND A MAPPING IS ONE FACT.

    Given a mapping with the following values:
      | team    | Northwind    |
      | folder  | Design Files |
      | groups  | design,admin |
      | storage | team folder  |

The table takes the SAME fields as the creation form, and both run them through
one `flagFor()`, so "storage" or "groups" means the same thing whether a value
is being set up or submitted. An omitted or blank row is the app's own default,
exactly as a blank cell is in the form.

WHAT IT REPLACED was a family of sentences that each said a different SUBSET of
the same fact — "…is mapped to the folder X", "…is mapped to a Team Folder",
"…shared with Y". A scenario needing two of them said the mapping twice; a
scenario needing a combination nobody had written yet had to grow a new step. A
table has no subsets, so the family collapses to one sentence and the new
combination is a new row.

An intermediate draft went the other way and split the compound Given into
three short sentences — the team exists, then it is mapped, then it is shared.
That reads as a RECIPE for reaching the state rather than the state itself,
which is not what a `Given` is for. One sentence that ties a team to its folder
is the pre-state; three steps in sequence are how you would perform it.

"IT" IS WHATEVER TEAM WAS NAMED LAST. Every sentence that names a team sets it,
including this one, so the rest of a scenario never repeats the name. Naming
another team afterwards re-points "it" — which is how "Two mappings cannot
target the same Nextcloud folder" reaches a second team without a second
mapping sentence, and why that scenario names it LAST.

THE ONE-LINE FORM STILL EXISTS for the thirteen other feature files whose
Background just needs a mapping to be there: "a Penpot team named X is mapped
to the folder Y". It names the team the same way, so "it" works after either.
It keeps `backendFlags()` — those files inherit the matrix leg's backend rather
than choosing one, which is exactly the difference that makes it a separate
sentence rather than an alias.

AND ONE ACTION TO MATCH. Creating a mapping is a single `When` — "the admin
maps it with:" and a table. Saving the form, being refused because the folder
is taken, and being refused because the team is already mapped are the SAME
action against three different pre-states, so they share the sentence and
differ in their `Given` and their `Then`. A draft had a second sentence, "the
admin maps it into the folder X", which was this one with a single field and no
table — a subset again, the same mistake the pre-state sentences had made.

That symmetry is what makes the file readable end to end: one way to describe a
mapping, one way to create one, and every scenario is a pre-state and an
outcome.

WHAT THIS FILE IS NOT ABOUT: what is INSIDE a mapped folder. A mapping
guarantees exactly one thing — the Nextcloud folder it names. Project folders,
their names, their `penpot` tags and the designs in them all arrive with the
FIRST SYNC, and two scenarios that used to sit here ("Project folder names
always match their Penpot projects", "Two Penpot projects in one team sharing a
name") moved to sync-now.feature for that reason. They had been reading as
though mapping a team produced a tree, which is exactly the confusion this split
removes.

GROUPS HAVE TO EXIST FIRST. `the Nextcloud groups "design,sales" exist` is a
precondition and not a detail: only `admin` exists on a fresh instance, and a
group that does not exist cannot be shared with. See
"The groups a mapped folder is shared with can be changed" for how long that
went unnoticed.

### Creating a mapping saves the form

ONE BEHAVIOUR, AND EVERY ROW CHECKS ALL OF IT.

Creating a mapping is a form submission: whatever the admin typed comes
back, and whatever they left alone comes back as its default. That is the
whole of it, so it is ONE scenario — the rows are inputs, not behaviours.
Choosing "sync" is not a different behaviour from choosing "link"; none of
these values can even be OBSERVED until something later acts on one (the
mode decides whether a file's bytes are held, the storage decides what kind
of folder gets provisioned, and the groups decide who can see it).

THE DEFAULTS ARE DECLARED AS DATA, not asserted one scenario at a time.
There were five near-identical scenarios here, each mapping a team and
reading back one field, and the storage default among them was WRONG for as
long as it took to notice — a Team Folder needs the optional groupfolders
app, so the no-choice mapping asked for a backend a stock Nextcloud does not
have (§C6.31). Five titles hid that. One column does not.

AND EVERY ROW ASSERTS ALL FOUR FIELDS, which is what the earlier drafts got
wrong: a row that sets the mode is also proving it did not disturb the
folder. Rows 1 and 2 together are the whole of "the folder name is the
admin's, and defaults to the team's" — the two names are independent, and it
costs a row rather than a scenario. The last row is a Team Folder named
exactly as its team, which is legal and worth pinning: nothing about the
storage backend constrains the name.

A FIFTH COLUMN USED TO BE HERE. "folder mode" sat in the defaults table with
no row setting it, because its only other value was refused — `keyed` was
designed and never built. The field is gone entirely now (§C6.36): one
unimplemented value beside one implemented value is not a choice, and a form
field nobody can meaningfully fill in does not earn a column. The design
question survives where an unbuilt design belongs, in the saga (§6.53,
question #47).

### A team id that resolves to nothing cannot be mapped

Better an honest refusal than a mapping that silently pulls nothing.

AND THIS IS THE WHOLE OF IT. There used to be a second scenario here for a
team that EXISTS but has not invited the service account. From this side of
the wire the two are one case: `get-teams` is membership-scoped (§6.12), so
a team we were never invited to is a team that is not there. Testing the
difference would be testing Penpot's own permission model, which is not
ours to prove — the token works or nothing in this suite runs at all.

### A mapping may not reuse a team or a folder

TWO RULES THAT READ AS ONE SCENARIO WRITTEN TWICE. `getByTeamId()` refuses a
team that is already mapped; `assertFolderUnique()` refuses a folder that is
already used. Apart, both scenarios said "a mapping exists, the admin maps one,
it is refused" — the whole difference lived in whether a second team had been
named first, which is invisible on the page and was flagged as a duplicate the
moment someone read them together.

Side by side the columns ARE the difference: row 1 reuses the team and takes a
free folder, row 2 brings a fresh team to the taken folder. The `reason` column
is what makes them two rules rather than one, and asserting it is what would
catch the app refusing for the right-sounding wrong reason.


The pre-state is a mapping that already exists, so the scenario opens where
the interesting part starts. It used to map twice inside the `When` block,
which put half the setup in the behaviour and needed a "the same team
again" step to refer back to a team it had never named.

### Removing a mapping deletes nothing

Nothing is removed from Penpot and nothing local is removed either. What
SHOULD happen to already-mirrored files is Course 5's decision
(remove-mapping.feature) — until then the safe behaviour is to leave them
and say so.

### Two Penpot projects in one team sharing a name is handled, not crashed

Penpot permits duplicate project names; Nextcloud does not permit duplicate
sibling folder names. Free nesting means the second folder can live
elsewhere, but the exact rule is undecided — saga open question #31.

### A team renamed in Penpot does not rename the mapped folder

SUPERSEDES an earlier draft in which the pull renamed the Team Folder to
follow. That predates admin-chosen folder names: silently moving someone's
folder because a team was renamed upstream is a surprise, not a sync. The
recorded team name still updates, so the admin page shows the truth.

Note this is the opposite of the PROJECT folder rule below, and
deliberately so: a team folder is a mount point the admin chose to create,
a project folder is a mirror of a Penpot object.

### The groups a mapped folder is shared with can be changed

── what can be changed, which is one thing ─────────────────────────────────

THERE IS NO EDIT, SO THERE IS NOTHING TO REFUSE. Four scenarios used to sit
here — the folder mode, the Nextcloud folder, the Team Folder flag and the
default mode, each saying "the admin tries to change it and is told no".
(Folder mode has since gone entirely, §C6.36, which is a stronger version of
the same argument: the field nobody could change is now a field nobody has.)
None of them was reachable. There is no occ command that edits a mapping,
and the one HTTP endpoint takes `ncGroups` and nothing else; the service
signature is `updateGroups(id, groups)` (§C6.33), so a change to any other
field cannot be EXPRESSED, let alone refused.

A scenario for a refusal that no caller can provoke is a scenario about an
error message. Immutability is a fact about the API's shape, and the place
to state it is where the shape is — MappingService::updateGroups()'s
docblock carries the reason for each locked field, and MappingServiceTest
pins that a group change moves nothing else.

WHY THESE FIELDS ARE LOCKED, in one line: changing any of them would force a
LIVE MIGRATION of already-mirrored content — moving the whole tree,
re-stamping every file, migrating a provisioned folder and its shares, or
deleting every downloaded archive at once. Removing the mapping and adding it again makes that cost visible
instead of hiding it behind a dropdown, which is the same line
nextcloud-grafana draws.

THE ONE EDIT, and it is the one that moves no content — re-sharing a folder
is not a migration. Everything else about a mapping is settled when it is
created (§C6.33).

AND IT IS AN EDIT TO THE FOLDER, NOT TO THE MAPPING (§C6.35). Nothing here is
persisted: the step re-shares the provisioned folder, and the assertion reads
`list-mappings --json`, whose `nc_groups` is now sourced from the folder rather
than from appconfig. So each row proves the whole round trip — apply, prune,
read back — on a real backend, which is the only place the two backends'
mechanisms (a groupfolders assignment vs. a group share) could differ.

The empty-groups rows earn their place because of that: they are the only ones
that prove PRUNING. The old code applied groups additively and never removed
one, so "set the groups to nothing" silently did nothing at all.

AND THE FIRST RUN OF IT CAUGHT THE SUITE LYING. Four of these scenarios had
been green while proving nothing: `design` and `sales` do not exist on a fresh
Nextcloud, nothing in the suite created them, and no share or assignment was
ever made. It passed because it read the groups back out of the app's own
stored copy — which faithfully recorded what we INTENDED. Reading through to the
folder turned them red immediately, and `the Nextcloud groups "…" exist` is the
fixture that was always missing. It is hard to think of a better argument for
not storing what you can read: the cache did not just risk going stale, it was
answering the question the test meant to ask.

BOTH BACKENDS, NAMED OUT LOUD. This is the second place in the suite where
the storage backend is the SUBJECT rather than a matrix dimension (§C6.30 is
the other): a group change has to reach a groupfolders mount and an
admin-owned share, and those are different code paths in StorageService. The
admin leg runs with groupfolders installed precisely so one run can ask both.

The four group sets are the four shapes a change comes in — add one, drop
one, replace the set entirely, and clear it. The last is the one worth
having: an empty set is easy to treat as "nothing was sent, keep what is
there", and on a Team Folder it is also the set that makes the folder
invisible to everyone.

THE PRE-STATE IS ONE SENTENCE AND A TABLE — see "The preconditions" above.
The storage kind and the folder name are both Examples columns here, because
the folder name has to differ per kind: removing a mapping deletes nothing, so
a folder outlives the mapping that made it, and a later row reusing the name
would inherit a folder of the wrong kind (the mistake §C6.32 records). Rows of
the SAME kind DO reuse one folder, which is safe — ensureRoot() is idempotent.

That hazard used to be hidden in the step's PHP, which picked a folder name
from the kind. Putting it in the Examples table makes the reason visible at
the point where someone would otherwise "tidy" the two names into one.

THE FOLDER is what changes here — see above. That sentence used to read "the
MAPPING is what changes here … re-sharing the provisioned folder is
ensureRoot()'s, re-asserted on every sync", which was true of the design
§C6.35 replaced and is now exactly backwards.

---

## admin-section — RETIRED

`features/admin-section.feature` is **gone**. It described the settings panel:
which cards exist, what order they appear in, which fields each holds, where the
buttons live. Twelve scenarios, none of them a thing anyone does.

| it said | why it went |
|---|---|
| The section presents four panels in the family's order | panel ORDER is an implementation detail of the UI |
| The Instance card holds both the URL and the service-account token | the structure of a form — those fields are the INPUT to connecting, not a behaviour |
| The token field never echoes a stored token back | already asserted verbatim in `connection/connection.feature`, as the end state of saving a token: *the token is stored as a sensitive value* |
| Every button in the section lives in Sync Actions | a UI nuance, and `@blocked` because nobody is testing whether the layout looks good |
| Test connection works today and reports what the account can see | `connection/connection.feature`'s, and already there |
| "Sync from Penpot" queues a background job and says so | `connection/sync-now.feature`'s — and whether a run is queued or synchronous is a mechanism this suite asserts nowhere, deliberately |
| The panel reports the outcome of the last run | an END STATE of syncing → moved onto the sync outline as `And the run is recorded with when it ran and what it did` |
| A second click while a sync is running does not start another | a real edge case → moved to `connection/sync-now.feature` |
| The scheduled pull uses the interval from Sync Settings | implementation detail. The interval and the enable toggle are connection settings — inputs, which Gherkin need not describe. The schedule already appears as an actor row in the sync outline |
| Turning the schedule off stops the runs | a negative with nothing to observe: the only honest test is that every `@in-penpot` behaviour stops arriving, which is a test that waits forever |
| There is no "Sync to Penpot" button, ever | a negative check on a feature that will never exist |
| Purge is offered but disabled until the delete machine exists | pins the presence and disabled-ness of a button; if it is anything it is `designs/purge.feature`'s |

THE PATTERN WORTH REMEMBERING: a settings panel is where a behaviour is
*configured*, not a behaviour. Its fields are inputs to the thing they configure,
and its layout is not specification at all. Everything real in this file already
had a home in `connection/` — which is why the folder split is what made the
duplication visible.

### A second sync started while one is running does not queue another

`@blocked` — **no browser**, and no way to hold a run open while a second is
issued. Two concurrent pulls over one folder tree would race on the same files,
which is the only reason this is worth stating.

## designs/copy

`features/designs/copy.feature`

THE LIVE HALF is driven over WebDAV against a real Penpot: copy in place, copy
up to the team root, and the copy-then-rename chain.

Copying a mirrored ".penpot" file. A copy in Nextcloud becomes a REAL new
design in Penpot — full parity with both siblings, which register a copy as a
new n8n workflow / Grafana dashboard for the same reason: a copy is a new
thing, and leaving it inert makes the file a lie about what it is.

Copying a PROJECT folder is copy-project.feature, and the answer there is the
opposite one — it is refused. That asymmetry is exactly why the two are
separate files rather than one with a branch in the middle.

### Copying a ".penpot" file outside every mapping never contacts Penpot

OUTSIDE EVERY MAPPING, NOTHING HAPPENS — the boundary that makes the rest of
this file safe. A `.penpot` file the app never mirrored is ordinary content,
and copying ordinary content is Nextcloud's business alone.

No penpot_id on the source means there is nothing to duplicate, and no
mapped ancestor means there is nowhere to put it. Both checks matter: a
file can carry an id and still be outside every mapping (drag one out and
it keeps its stamp), which is move-design.feature's "unmapped" state.

THE ONE THAT FAILED BY HAND. The team root has no project FOLDER above it, so
membership resolves to "no project" — which reads exactly like "outside every
mapping" and is nothing of the kind (§6.35). The copy appeared in Nextcloud
and nothing whatsoever happened in Penpot, with nothing logged.

### A copy can be renamed immediately, because it was tracked

The last line is the point: renaming the COPY must not touch the original.
A copy that failed to record its id presents as a broken rename, one
gesture later — which is how §C6.9 reached a human before a test.

### A copy is tracked the moment it exists, so the next action works

── walked by hand, and each one caught a real bug ────────────────────────

These three came from a manual walkthrough rather than from design, and every
one of them failed the first time. They are kept in the order they were done,
because the ORDER is what exposed the bugs: each step was only reachable once
the previous one worked.

THE FIRST WALKTHROUGH FAILED HERE, and blamed the wrong feature. The copy
silently failed to record its id, so the rename that followed had nothing
to push and did nothing — which presents as "rename is broken" (saga
§C6.9). A copy that does not track is not a copy problem the user can see;
it is a rename problem, a move problem, and a delete problem, later.

### Copying to the team root creates the design in Drafts

THE SECOND WALKTHROUGH FAILED HERE. The team root has no project FOLDER
above it, so membership resolves to "no project" — which reads exactly like
"outside every mapping" and is nothing of the kind (§6.35). The copy was
created in Nextcloud and nothing at all happened in Penpot, silently
(§C6.10).

### A copy that cannot be tracked says so rather than looking finished

The two failures above were both invisible from the Files app: a file
appeared, and nothing said otherwise. Whatever else a failed copy does, it
must not look like a completed one.

### A link file copies exactly like a sync file

duplicate-file copies the design server-side from its id, so a pointer
with zero bytes duplicates as completely as a stored archive. The siblings
cannot do this — they copy by pushing the file's own content.

### A sync copy keeps its archive and is a valid file on its own

Stripping identity never strips bytes. The copy's archive is the ORIGINAL
design's bytes until the next pull re-exports it for the new id, which is
correct: at the instant of copying the two designs are identical.

### Copying outside every mapping creates nothing in Penpot

There is no project to create in, and inventing one would be the surprise
write §6.1 refuses. The id is inert here and genuinely useful: it records
which design these bytes came from, which is what makes restore possible.

### A failed duplicate leaves the Nextcloud copy standing

§6.18 rule 3: a remote failure never rewrites local state. Carrying the
original's id would be the worst outcome — two files claiming one design,
which is the ambiguity that made the old inert-copy rule necessary.

### Exactly one file per design id under a project, always

This is what the new id buys. The old rule stripped the id to avoid two
candidates for "update in place"; giving the copy its own real id solves
the same problem without leaving a dead file behind.

### Copying a design across two mappings makes a new design in the destination team

The cross-team copy is the ordinary copy path — `duplicate-file` then
`move-files` (§C6.8) — with a destination that happens to belong to another
team. `move-files` carries the team, so nothing extra is needed.

@unbuilt for the personal half only: a user's home root becomes a mapping
when they set a personal token (personal-projects.feature), and none of
that exists in `lib/` yet. Team-to-team copying is built.

### A design duplicated in Penpot is mirrored like any other new design

══ COPIED IN PENPOT ═══════════════════════════════════════════════════════

THE ASYMMETRY IS THE FINDING, and it is why both directions belong in one
file even though only one of them is really "copying".

A duplicate made in Penpot's own UI is, from Nextcloud's side, INDISTIN-
GUISHABLE FROM ANY OTHER NEW DESIGN. Penpot does not tell us a file was
duplicated — `get-project-files` returns a design with a fresh id and a name
like "Original (copy)", and nothing marks it as derived. So there is no
copy behaviour to implement on this side at all: the reconciler mirrors it
the way it mirrors anything new.

That asymmetry is worth stating rather than discovering:

  copied in Nextcloud  →  we CALL duplicate-file (+ move-files if it landed
                          in another project), because the gesture has to be
                          translated into something Penpot understands
  copied in Penpot     →  we call NOTHING. A new design appears and is
                          mirrored. The "copy" is invisible to us.

Which means the two directions cannot be one scenario with a direction
column: one exercises a write path, the other exercises the reconciler doing
its ordinary job. Same word, two different rules — the exact case
features/README.md says must stay separate.

No `duplicate-file` call of ours is involved. Needs a seed step that calls
duplicate-file directly on the Penpot side — the one thing missing to make
this live.

### A duplicate made in Penpot inherits the mapping's mode, not the original's

THE DIFFERENCE THAT MATTERS, and the reason this pair earns its place: a
Nextcloud-side copy inherits nothing because the app creates the mirror
knowing where it came from, while a Penpot-side duplicate arrives as a
stranger and takes the mapping's default like any other new design. Two
designs that look identical in Penpot can therefore mirror in different
modes, purely because of where the duplicate was made.

---

## projects/copy

`features/projects/copy.feature`

COPYING A PROJECT — and the short answer is that you cannot.

Copying a DESIGN duplicates it in Penpot (copy-design.feature). Copying a
project folder does NOT, and the asymmetry is deliberate rather than an
omission: Penpot has no duplicate-project operation, so the app would have to
synthesise one by creating a project and duplicating every design into it. That
is a bulk write invented by a drag, with no single call to make it atomic and
no obvious answer for what a half-finished one leaves behind.

So it is refused, visibly. An ordinary folder that merely happens to sit inside
a mapped folder is unaffected — it is not a project, and copying it is just
copying a folder.

### Copying a project folder is refused, unlike copying a file

DISABLED DELIBERATELY (saga §6.40), not merely unbuilt. Three reasons:
 (1) the copy would carry the same project id, so two folders claim one
     project — and every file in the copied tree would too;
 (2) Nextcloud auto-increments a copy to "My Stuff (2)", which instantly
     violates §6.36's names-always-match rule — and "fixing" it by rename
     would rename the ORIGINAL Penpot project;
 (3) on this cluster a single folder can also carry n8n and Grafana
     mappings, so a folder copy asks three independent apps to agree on
     what a duplicate means, with no coordination between them.

### Copying an ordinary folder inside a mapped folder is unaffected

Only folders carrying a project id are refused. A mapped folder has to stay
usable as an ordinary folder, which is the same rule the tag opt-in rests
on (create-project.feature).

---

## designs/create

`features/designs/create.feature`

"New → Penpot design" in the Files app — the same New-menu affordance both
sibling apps offer for workflows and dashboards.

THIS IS A DELIBERATE CARVE-OUT OF §6.1, RATIFIED IN PRINCIPLE (saga §6.33).
§6.1 locked Nextcloud as read-only for design CONTENT; §6.23 already carved out
restore. Creation is the second carve-out and Command asked for it explicitly.
Neither carve-out weakens the core promise: this app never modifies or deletes
an existing Penpot design as a side effect of a file-manager gesture. Creating
is a deliberate, explicit user action from a menu.

THE SCOPING RULE, AND WHY IT EXISTS (saga §6.33): the action is only offered
where the target project is UNAMBIGUOUS. Command's framing — "it does not seem
to make sense to do this outside of a project folder or team folder" — is
exactly right, because Penpot requires a projectId on create-file; there is no
team-level or rootless design. So:

  inside a project folder      → created in THAT project
  inside a team folder         → created in that team's DRAFTS project
  in a plain folder under a team → same: that team's DRAFTS
  nowhere with a team ancestor → THE ACTION IS NOT OFFERED

WHY DRAFTS RATHER THAN AN ERROR: it's Penpot's own answer to the same question.
Every team auto-provisions a "Drafts" project with isDefault: true (saga §6.6,
confirmed on every team live), and it's exactly where Penpot's own UI puts a
design created outside any project. We match their convention rather than
inventing one.

DRAFTS IS A STATE, NOT A FOLDER (saga §6.35). No "Drafts" folder is ever
created. A design created at a team folder's root — or in any plain folder
under it — STAYS VISUALLY WHERE THE USER MADE IT in Nextcloud, while living in
that team's Drafts project in Penpot. This is where Nextcloud is more expressive
than Penpot: one flat Drafts bucket on their side can be any arrangement of
ordinary folders on ours. Filing the design later is just a drag into a project
folder (move-design.feature).

NOW EXERCISED LIVE (saga §C6.11). `create-file` was called against a running
instance and its schema read back:

  {name: string≤250 (required), project-id: uuid (required),
   id?: uuid, is-shared?: bool, features?}

KEBAB `project-id`, and `name` is REQUIRED — a design cannot be created
nameless. There is also an optional `id`: a caller may supply the design's uuid
itself. This app deliberately does not, because letting Nextcloud mint Penpot
identities would make the id something two systems can disagree about; Penpot
assigns it and we record what it says. Open question #27 is closed.

NOTHING IS OPENED AFTER CREATING (researched, not assumed). Nextcloud's own
New-menu API does nothing on its own — its maintainer's words: "Any Entry is
responsible for nothing but themselves... you need to call the creation
yourself." The sanctioned pattern is prompt → put the file → emit
`files:node:created`, and both sibling apps do exactly that. Text and Office
auto-open because they ARE the editor; we are not, and `window.open` after an
await chain is unreliable anyway — popup blockers reject it inconsistently. So
the file appears, and the user clicks it.

@todo — no lib/ exists yet.

### create-design: Background

══ CREATED IN NEXTCLOUD ═══════════════════════════════════════════════════

"+ New → Penpot design" writes an EMPTY file and stops; the server notices it
and creates the design. Asserted in Penpot, because a file appearing in
Nextcloud is exactly what a broken create looks like.

### Uploading a ".penpot" archive does not create an empty design

THE GUARD NEITHER SIBLING NEEDS. An uploaded .penpot already holds a whole
design; creating an empty one for it would set the file and Penpot against
each other, and the next sync pull would overwrite the real archive with the
empty export.

══ THE RULE: NEXTCLOUD CANNOT MAKE A DESIGN, IT CAN ONLY ASK FOR ONE ══════

A `.penpot` is a Penpot artefact. Nextcloud has no way to produce one — it
can write an empty file with that extension, and that is all. So "+ New →
Penpot design" is not a local create at all: it is a REQUEST, and the
request needs somewhere to go.

Penpot has no rootless design (§C6.11: `create-file` requires a project),
so "somewhere" means a resolvable Penpot home:

    inside a project folder    →  that project
    under a mapped team        →  that team's Drafts        (§6.35)
    at the user's own root     →  their PERSONAL team's Drafts
                                  (personal-projects.feature — needs a
                                   personal token, and is not built)
    anywhere else              →  NOTHING HAPPENS

The last line is the rule, and it is a refusal to guess rather than an
error. A `.penpot` outside every mapping is an ordinary, inert file: the
user made a file, it is theirs, and it is simply not a design. Inventing a
team to file it into would be worse than doing nothing, and erroring would
make a mapped folder unusable for the ordinary things folders are for.

### Filing a newly created draft is just a drag

THE THREE PLACEMENT CASES ARE LIVE ABOVE, driven over WebDAV — which is
what the "+ New" menu actually does: write an empty file and stop. They
used to be repeated here in menu vocabulary ("I choose New → Penpot design
inside the My Stuff folder"), which described the same three outcomes a
second time and had already drifted from them. Only the MENU SURFACE is
this section's own, and that is what is left.

### A newly created design follows its mapping's default mode

── creating in a personal team ─────────────────────────────────────────────
Same behaviour, different destination: the user's own Drafts rather than the
team's. personal-projects.feature owns why that destination differs.

### A design created in the user's own home lands in their personal Drafts

THE WHOLE POINT OF THE IMPLICIT MAPPING. Without a team ancestor this file
resolves to nothing and stays inert (create-design.feature's rule). With
one it is the ordinary team-root case (§6.35) — same rule, new root.

### A design created in a plain folder in the user's home also lands in personal Drafts

── crossing the boundary: personal ⇄ a shared team ─────────────────────────
A user's home and a mapped Team Folder are two mappings to two different
Penpot teams, so a drag between them is a REAL cross-team move — and a move
is move-design.feature's, whatever the two ends happen to be. The scenarios live
there, next to every other move, rather than here where a reader comparing
"what happens when I drag a design" would have to find them.

This file owns only the fact that makes them possible: the home root has a
team ancestor because a token was set.

---

## projects/create

`features/projects/create.feature`

HOW A FOLDER BECOMES A PENPOT PROJECT — the creating half, from either side.

### WHAT THIS FILE OWNS

A project's IDENTITY: how a folder acquires one, and the marker that says it
has. Every VERB a project can be on the receiving end of lives with the other
instances of that verb, so "what happens when I rename a project?" and "what
happens when I rename a design?" sit side by side rather than in two files:

  renaming a project   → rename-project.feature
  copying one          → copy-project.feature   (refused — and why)
  moving one           → move-project.feature
  deleting one         → delete-project.feature
  restoring one        → restore-project.feature

This file used to own all of those, which was the same mistake gestures.feature
made in the other direction — organising by the KIND OF THING acted on instead
of by the BEHAVIOUR — and it cost the same thing: "what happens when I rename a
project folder?" had two answers in two files, and the two had already drifted.

It was called project-folder.feature until the design/project split, which is
when the last of those verbs moved out. The name followed the contents: what is
left is creation, so it sits beside create-design.feature where the two opt-in
models can be read together.

A PERSONAL project is created the same way, by the same tag, in the user's own
home — personal-projects.feature owns only the who and the where.

### THE ASYMMETRY (saga §C6.18)

    every Penpot project      →  a folder in Nextcloud     (automatic)
    SOME Nextcloud folders    →  a project in Penpot       (opt-in only)

A folder created inside a mapped folder is an ORDINARY FOLDER. Nothing is
sent, nothing is inferred, and it can hold anything the user likes — notes,
exports, a subfolder of references. Mapped folders are real folders, and they
must behave like ordinary ones. Inferring intent from a folder's existence is
the kind of automatic behaviour this app has refused everywhere else (§6.33 on
creation, move-design.feature on drag-in).

The opt-in is the `penpot` TAG. Assigning it says "make this a project", which
is a deliberate act with a name, exactly as "+ New → Penpot design" is for
files. The tag is ALSO how the app marks the folders it mirrors, so the two
directions share one visible marker: if it carries the tag, it is a Penpot
project, whoever made it one.

WHY A TAG AND NOT A BUTTON: Nextcloud already has tag assignment as a
first-class gesture with an event (`TagAssignedEvent`), the sibling apps use it
for exactly this kind of opt-in, and it survives a rename or a move in a way a
name convention could not. It needs Nextcloud 32 — see appinfo/info.xml.

### A NOTE ON THE BACKGROUND

It used to provision a Team Folder and mirror a project called "My Stuff" into
it, and none of those steps had ever existed — harmless while the whole file
was @todo, and an instant `--strict` failure the moment one scenario went live.
It is now the same Background the other live behaviour files use: a PLAIN
mapped folder, because Team Folder provisioning is not covered by this suite
(features/README.md), plus the mirror every scenario here needs.

### A folder opted in late brings the designs already inside it

THE REASON TO ALLOW OPTING IN LATE. A folder someone has been filling with
designs becomes a project WITH its contents, rather than forcing the
decision up front. Before the tag those designs were in the team's Drafts
(§6.35) — a folder inside a mapping is still inside the mapping — and one
`move-files` re-files the lot without exporting or re-id'ing anything.

### Tagging a folder that is already a project changes nothing

The common path, because the pull tags every folder it mirrors. A second
create here would leave two folders claiming one project — the exact
ambiguity copy-design.feature refuses a folder copy to avoid.

### A folder tagged as a project must have a usable name first

BUILT (§C6.18) — ProjectFolderService checks the name locally and takes the
tag back off, so the user can rename and re-tag: a two-step they control,
rather than a half-created state they have to discover (§6.39). Still @todo
only for want of a step that makes a folder whose name Nextcloud accepts
and Penpot would not — NC allows 255 characters and Penpot 250, so the
window exists but has to be constructed deliberately.

### Tagging a folder outside every mapping does nothing at all

Tags are instance-wide, so this is not an error to report — no team could
be resolved for that folder even in principle. Stripping a user's own tag
off a folder this app has no business touching would be a worse surprise
than an inert label.

### Removing the "penpot" tag does not delete the project

Untagging is unmapping, not deleting — the same rule as moving a design out
of a mapping (§6.23), and the same rule as deleting a project folder
(delete-project.feature). Destroying a project because someone removed a label
would be the worst kind of surprise.

The app does not subscribe to `TagUnassignedEvent` at all, so "Penpot is
never contacted" is true by construction rather than by a branch someone
could later add an `else` to.

### A project created in Penpot arrives as a tagged folder

A user cannot tell — and should not have to — whether a project folder
started life in Penpot or was opted in from Nextcloud. Both carry the tag;
both are projects.

### A project folder that lost its tag gets it back on the next pull

The tag decorates; `penpot_project_id` decides. Because the id never went
anywhere, the pull re-stamps the badge on every run — which is also why the
tag being missing is never a state the app has to repair specially.

---

## designs/delete

`features/designs/delete.feature`

DELETING A DESIGN — both bins, both directions, and the one irreversible path.
Deleting a PROJECT (the folder) is delete-project.feature: one call, not one
per design, and a different set of guards.

### TWO BINS, AND THEY ARE NOT SYMMETRIC (saga §C6.11)

Nextcloud's trash and Penpot's trash are separate systems with separate
retentions. An ordinary delete is SOFT on both sides — recoverable, and
therefore safe to do without asking. Only emptying the Nextcloud trash reaches
`permanently-delete-team-files`, the single irreversible thing this app can
cause, and it is reached only by the single irreversible gesture Nextcloud
offers.

THE GUARD ON THAT CALL IS THE ONLY SAFETY THERE IS: Penpot does not check its
own trash before permanently deleting, so the app reads the trash listing first
and passes only ids that are in it. A design someone restored in Penpot between
the two is therefore left alone.

### A LINK HAS NOTHING TO DELETE

A `link` is zero bytes pointing at a design that lives elsewhere, so deleting
one is a DISMISSAL, not a deletion — it hides the pointer and leaves the design
untouched. That branch is here in full, because "delete" reads the same in the
Files app whichever mode the file is in.

### Emptying the Nextcloud trash destroys the design in Penpot

── ONE BEHAVIOUR THAT REALLY DOES DIFFER BY BACKEND ────────────────────────
Everything else in this suite is backend-agnostic, which is why the backend is
a dimension the run varies rather than something the specs mention. This is
the exception, and it earns two scenarios because the OUTCOMES differ — the
same rule that gave §C6.16 its own scenario.

Found by the backend matrix on its first run (saga §C6.27), not by review.

The one irreversible thing this app can cause, reached only by the one
irreversible gesture Nextcloud offers. permanently-delete-team-files does
NOT check the trash itself (§C6.11) — the app reads the listing first, and
that guard is the only safety there is.

### Emptying a Team Folder's trash cannot reach Penpot, and says nothing

NOT A DECISION — A GAP WE CANNOT CLOSE FROM HERE, recorded so it is tracked
rather than rediscovered. groupfolders does not use files_trashbin: it
registers its own ITrashBackend, and its removeItem() calls
`$node->getStorage()->unlink()` and emits NOTHING — no typed event, no
legacy hook. There is no entry point for any app to observe it, so the
purge simply never reaches us.

(Its restoreItem() DOES emit the legacy `post_restore` hook, which is why
the restore half of this pair was fixable and this half is not.)

IT SELF-CORRECTS, WHICH IS WHY THIS IS AN EDGE CASE RATHER THAN DATA LOSS.
The design is already in Penpot's own trash from the ordinary delete, and
that trash expires on its own — `deleted_at` is set to now + 7 days
(§C6.11). So the divergence is a WINDOW, not a permanent state: the design
outlives the Nextcloud file by up to a week and is then gone anyway. What is
lost is the immediacy, not the outcome.

SOLVING IT SPECIALLY, when we do: the candidates are an upstream hook in
groupfolders, or a pull-side reconcile that notices a mirror is gone from
both the folder AND the trash. The second is delicate — "absent" must not be
confused with "never existed", the same trap §C6.11 hit with a deleted
project's folder.

### Deleting an untracked ".penpot" file leaves Penpot alone

══ DELETED IN PENPOT ══════════════════════════════════════════════════════

The mirror image, and it arrives via a sync run rather than an event: the
design stops being named by Penpot's listing, so the pull moves its mirror to
the Nextcloud trash. This is the PRUNE, and it is the most dangerous thing
this app does — every way of failing to ask (a 502, a project skipped for an
illegal name, a half-read listing) is indistinguishable from a deletion. The
safety half of it lives in reconcile.feature, where the run itself is spec'd.

THE RULE WITH NO EXCEPTION: Nextcloud never purges a file because Penpot no
longer has it. The two trashes expire on schedules neither side controls —
Penpot's is ~7 days and not configurable, a Nextcloud instance may keep 30 —
so mirroring the purge would let every design that ages out of Penpot's trash
take the user's last copy with it, on a schedule nobody chose.

### A design deleted in Penpot is snapshotted, then moved to the trash

THE CLAIM THE LIVE SUITE EXISTS FOR. The mirror was a `link` — a pointer
with no bytes — and the design it pointed at is gone. Penpot's grace window
turns an unrecoverable deletion into a recoverable one, so the pointer
becomes a real archive on its way to the trash.

THE LAST LINE IS NOT DECORATION. "No node at that path" is equally true of
a hard delete — the one outcome this must never produce — so for three
courses "trash, never destroy" was a promise in a header and an assertion
in no scenario. It reached a user as *"the file left my folder and I cannot
find it in the trash"* before it reached a test (§C6.16).

### A design that already had its archive needs no second export

A `sync` file is already its own snapshot. Re-exporting it would download a
whole archive to replace an identical one — and would fail for exactly the
files most worth keeping, once the grace window closes.

### A design purged in Penpot still only reaches the Nextcloud trash

The design is gone from every Penpot listing AND from its trash, so nothing
about it can ever come back — and the mirror is still only trashed. This is
the case where the local file is genuinely the last copy of that design,
which is precisely why it must land somewhere recoverable.

NOTHING IS ASSERTED ABOUT THE FINAL ARCHIVE HERE (§C6.16):
`permanently-delete-team-files` returns before the data is actually gone —
Penpot marks the rows and a worker removes them later — so `export-binfile`
can still succeed for seconds afterwards. Whether the snapshot lands is
Penpot's timing, not our behaviour.

### A mirror already in the Nextcloud trash is invisible to the pull

── the reconciler's field of view: VISIBLE FILES, and nothing else ───────

THE RULE THAT MAKES THE ONE ABOVE SIMPLE. The reconciler walks the mapped
folder's directory listing, so a mirror already in the Nextcloud trash is not
merely spared — it is **not seen at all**. Once a file reaches the trash the
pull is finished with it, permanently, whatever Penpot does next.

State this as a rule and a whole class of question stops existing. "Both
trashes hold it and then Penpot purges — now what?" has no answer to design,
because the reconciler was never looking. There is no cross-trash comparison,
and no schedule on which the app can take a user's last copy away.

THE PRICE, NAMED: a design that comes back in Penpot while its old mirror
sits in the Nextcloud trash gets a NEW mirror, beside the trashed one — the
pull cannot re-adopt what it cannot see. reconcile.feature carries that as an
explicit open fork.

THE SEQUENCE THE RULE EXISTS FOR, end to end: the user deletes the mirror
(which puts the design in Penpot's trash), then the design is destroyed in
Penpot for good. Both sides are now gone in their own way — and the pull
does nothing at all, because a trashed mirror was never in its field of
view: it is still in the trash afterwards, and no mirror reappeared for it.

Asserted on this file rather than on the pull's prune COUNTER, which is a
claim about every mirror any scenario ever left in the shared folder.

### Purging a mirror from the Nextcloud trash destroys the design

══ DELETING A PROJECT FOLDER ══════════════════════════════════════════════

WHAT HAPPENS TODAY, AND IT IS NOT WHAT A USER EXPECTS (§C6.19). Deleting a
project folder reaches Penpot **not at all** — verified live. Two reasons
stack, and neither is deliberate:

  1. `DeleteListener` returns unless the node is a `File`.
  2. Nextcloud fires `BeforeNodeDeletedEvent` for the FOLDER ONLY. There is
     no per-child event, so even removing (1) would not reach the designs
     inside — a recursive walk is something this app would have to do
     itself, before the node is gone.

The folder then reappears on the next pull (the project never went
anywhere), which reads as the app undoing the user's deletion.

PENPOT SUPPORTS EXACTLY WHAT IS WANTED — checked in its source and proven
live against a project holding two designs:

  delete-project {id}      → HTTP 204. SOFT: project.deleted_at = now + 7d
                             (per-team `deletion-delay`, default 7 days).
                             A worker then cascades the SAME future
                             timestamp onto every file in the project and
                             its changes, data, media and thumbnails.
  → the project vanishes from `get-all-projects` IMMEDIATELY;
  → its designs appear in `get-team-deleted-files` IMMEDIATELY, before the
    worker runs, because that query matches on `p.deleted_at > now` OR
    `f.deleted_at > now` — the project's own mark is enough.

So there is no "must be empty first" rule to mirror, and no reason to
refuse. A project deletes with its contents, reversibly, on a grace window
that lines up with the Nextcloud trash almost exactly.

ONE PROJECT CANNOT BE DELETED: the team's default (Drafts) answers
`:non-deletable-project`. It has no folder of its own — it IS the team root
(§6.35) — so this app cannot reach it by this gesture anyway; the guard is
stated so a future change to the folder layout cannot back into it.

### A trash-bypassed delete is treated as the permanent one

There is no soft step to be had — the file never reaches a trash. Treating
it as the soft step would mean turning the trash off quietly stops deletes
reaching Penpot at all.

### Deleting a link file hides it instead of removing the design

── deleting a link file HIDES it (saga §6.43) ──────────────────────────────
There is nothing to delete: the design lives in Penpot and the local file is
a pointer with no content. So a local delete of a link is a VISIBILITY
operation, not a destructive one.

NOT BUILT YET, AND THE REASON IS THE SCENARIO AFTER NEXT. Today a `link`
deletes exactly like a `sync` — the design goes to Penpot's trash and the
restore brings it back. Making the delete local requires the pull to read the
Nextcloud trash first ("A pull does not recreate a link the user dismissed"),
because otherwise a dismissed link reappears on the very next run and the user
is in an argument with the reconciler. The two are one slice, and it is not
this one. Stated here rather than left as a scenario that quietly does not
match the code.

### A hidden link is distinguishable from one that was never pulled

THE TRASH IS THE HIDDEN MARKER (saga §6.45) — no separate flag exists.
A trashed Nextcloud file keeps its fileid, its "penpot_id" and its
"penpot_mode" (saga §6.44, tested live), so the reconciler just looks.

### A link is never restored into Penpot, in any circumstance

"A link just says it's there in Penpot and shows it in Nextcloud, but the
file contents are never touched for any reason" — trashing and restoring a
link are purely local visibility operations (saga §6.45).

### There is no app-managed trash-bin setting

WITHDRAWN DESIGN (saga §6.34 → §6.52). An earlier draft built exactly this,
on the false premise that Penpot's own trash was unreachable. It isn't —
and Penpot's trash preserves more, with no configuration and no bespoke
machinery. Moving a user's design into a robot's private team would also
have made it vanish for their whole team.

### Once the grace window passes, only a best-effort import remains

Measured, not assumed (saga §6.41): a real export→import round trip
preserved name, revn 5, pages and assets — and produced 0 file_change rows
against the original's 5.

---

## projects/delete

`features/projects/delete.feature`

DELETING A PROJECT — the folder, which is ONE Penpot call rather than one per
design. Deleting a design is delete-design.feature.

WHY IT IS NOT A LOOP. `delete-project` removes the project and everything in it
server-side, so the app never walks the designs. That matters beyond
efficiency: a per-design loop could fail halfway and leave a project that is
half-deleted on one side and whole on the other, which is precisely the state
nothing in this app is allowed to produce.

WHAT IS NOT A PROJECT. A plain folder that merely sits inside a mapped folder
carries no `penpot_project_id`, so deleting it touches nothing in Penpot — and
the TEAM ROOT is never deletable as a project, because it is the mapping, not a
project in it.

The design-side rules about the two bins, the permanent-delete guard, and link
dismissal all live in delete-design.feature and are not restated here.

### A CORRECTION THIS FILE EXISTS TO CARRY (saga §C6.25)

This spec used to say "deleting a personal project folder never touches
Penpot". That was not a rule — it was the CURRENT DEFECT written up as one.
Deleting a project folder reaches Penpot **not at all** today, for two stacked
reasons (DeleteListener bails on anything that is not a File, and Nextcloud
fires BeforeNodeDeletedEvent for the folder ONLY, with no per-child event), and
the folder then comes back on the next pull — which reads as the app undoing
the user's deletion. Somewhere between "we will deal with this later" and the
spec, later became never.

The mirror's whole premise is parity: a folder the user tagged into existence
is one they can delete the same way, in a personal team exactly as in a mapped
one. Only the credential differs.

### WHAT PENPOT ACTUALLY DOES, measured (saga §C6.11)

  delete-project {id}   → HTTP 204, and it is ENTIRELY SOFT. Sets
                          project.deleted_at to now + deletion-delay (7 days by
                          default) and a worker cascades the SAME future
                          timestamp to every file in it.
  restore               → there is NO restore-project RPC. A project returns
                          only as a SIDE EFFECT of restoring one of its files.
  an EMPTY project      → has no file to carry it back, so it cannot be
                          restored through the API at all. It expires.

DELETE CASCADES; RESTORE DOES NOT — measured by deleting a project holding two
designs and restoring only one: the project came back, that design came back,
the other stayed in the trash. So "restore the project folder" has to mean
"restore every design that was in it, in ONE call" — not for tidiness, but
because a per-file loop that failed halfway would leave a project holding some
of its designs and no signal that anything was wrong.

THE GRACE WINDOW LINES UP WITH THE NEXTCLOUD TRASH almost exactly, which is
what makes the mirror honest: soft on both sides, recoverable on both sides,
for roughly the same week. restore-project.feature owns the other half.

### Deleting a project folder deletes the project in Penpot

The two trashes line up: Nextcloud's is reversible and so is Penpot's, on
a comparable window. This is the same shape as deleting a single mirror
(above) one level up the tree.

### Deleting a project folder does not need a per-design call

Penpot cascades server-side, so mirroring its behaviour is ONE call, not
N+1 — and doing it per-file would be worse than redundant: it would leave
the project itself alive and empty if the last call failed.

### The team root is never deletable as a project

Penpot answers `:non-deletable-project` for a team's default project. The
team root carries `penpot_team_id`, not `penpot_project_id`, so it does not
resolve as a project folder and never reaches the call.

### A project deleted in Penpot leaves no folder claiming its id

THE GAP THE LIVE PROBE FOUND (§C6.19). The designs prune correctly, with
rescue archives — but the FOLDER survives, still stamped with a project id
that no longer resolves, still wearing the `penpot` tag. Anything dropped
into it afterwards resolves to a project Penpot will refuse.

`get-all-projects` filters deleted projects out, so the pull cannot tell
"deleted" from "never existed" — which is exactly why the pull must not
DELETE the folder either. Un-stamping it (and un-tagging it) turns it back
into an ordinary folder, which is the truthful end state.

### Deleting a personal project folder deletes that project in Penpot

── the same gesture in a personal team, and it means the SAME thing ────────
A personal project is a project. The who and the where differ; the rule does
not. This is stated explicitly because the spec previously claimed the
opposite — see the correction note at the top of this file.

SAME RULE AS A TEAM PROJECT, different credential. A personal project is
not a read-only view of Penpot — the whole point of the mirror is parity,
so a folder the user tagged into existence is one they can delete the same
way. The user's own token performs it, because the service account cannot
see their personal team at all (personal-projects.feature).

---

## designs/edit

`features/designs/edit.feature`

A DESIGN'S CONTENT CHANGING — and the only file in this app with one direction
where both siblings have two.

### edit-design

**Editing happens in Penpot, and only in Penpot.** A `.penpot` archive is opaque
nested design data; there is nothing coherent to hand-edit and no way to
re-import it if there were, which is why `open-with.feature` offers no text
editor in any mode. So there is no Nextcloud-side twin to write, and every
scenario here is `@in-penpot`.

**THIS FILE FILLS A REAL HOLE.** Until it existed, "a design was edited and the
mirror caught up" was asserted **nowhere** — the two closest scenarios were both
negatives (`set-mode.feature`'s "not re-exported by the next pull" and
`ignore.feature`'s "the file is not re-exported"). The single most important
thing this app does for a `sync` file had no scenario at all, because the
behaviour had been filed under the mechanism that carries it.

It replaced a scenario in the retired `sync-mode.feature` called "A leftover body
from an older version is truncated by the next pull". That one was about an older
version of **this app** — early builds wrote a small JSON pointer body into link
files, and §C6.6 changed a link to zero bytes, so the pull truncates whatever the
old build left. Neither Grafana nor Penpot has ever been released, so there are
no old builds in the field and nothing to migrate from. It was deleted rather
than rewritten.

### An edit in Penpot reaches the stored archive

`@blocked` — **no way to edit a design's content.** Penpot's `update-file` is the
only RPC that changes what is inside a design, and its `changes` payload is
unproven and reported fragile (saga §1, penpot/penpot#4180). The harness can
create, rename, move and delete designs; it cannot author in one. Confirm a
usable `update-file` shape and all three scenarios become ordinary `@in-penpot`
work.

THE TABLE IS BOTH HALVES OF THE BEHAVIOUR. The revision moved and the archive was
re-fetched — that is what happened. The id, the team and the mode did not — that
is the promise, and it is what makes the mirror the same file rather than a new
one standing where the old one was.

`modified` is the design's clock rather than the sync's, so a mapped folder
sorted by date sorts by when designs were worked on (§C6.24). It is also the half
of the drift signal a rename moves without moving `revn`, which is why the signal
is the two joined (§5.5).

The third scenario — an edit to one design leaves its neighbours alone — is the
one that costs real money if it regresses. A sync that re-exported everything
whenever anything changed would satisfy the first two and be a bandwidth bill.
A THIRD SCENARIO WAS WRITTEN AND CUT: "an edit to one design leaves its
neighbours alone". It is not a behaviour — the behaviour is still editing, with
the same pre-state and the same end state, plus a statement that a second file
was not touched. Nothing edits a file nobody edited; saying so is not a scenario.

Cutting it left `I note the mtime and etag of` with no caller at all, so those
step definitions went too. The regression they once guarded (a pull rewriting
every mirror on every run) is recorded in the CHANGELOG.

### An edit in Penpot costs a link nothing but its dates

A LINK TRACKS THE EDIT WITHOUT PAYING FOR IT. The revision and the dates come
from a listing the sync already had; the bytes do not, because nobody asked for
them. That is the economic argument for link being the default, stated as an end
state rather than as a claim about call counts.

## errors

`features/errors.feature`

Failure behaviour. Neither sibling app needed a file like this; this one does,
because Penpot's transport has more ways to fail than a plain JSON REST call.

THE GOVERNING RULE, FROM COMMAND (saga §6.25): DON'T LOSE DATA. A remote
failure must never destroy local state. Every scenario below is an application
of that one rule.

WHY THERE ARE MORE FAILURE SURFACES HERE (all confirmed live, saga §5.1/§6.20):
  1. export-binfile and import-binfile are BOTH SSE — a stream of progress
     events, then `end` or `error`.
  2. HTTP 200 DOES NOT MEAN SUCCESS. An `event: error` arrives inside a 200
     response — witnessed directly when importing into a deleted file id. The
     status code lies; the stream is the truth.
  3. The payload is TRANSIT-JSON even when Accept: application/json is sent
     (`~:type`, `~u<uuid>`, `~#uri`), so errors must be decoded, not string-matched.
  4. Fetching the actual bytes is a SECOND authenticated request to an asset URL
     that 401s without the token.
  5. A restore is TWO calls (import, then rename) because import ignores the
     `name` param — so it has a genuine partial-success state.

THE MOST DANGEROUS OPERATION IN THE APP IS PRUNING. A failed listing looks
exactly like "every file was deleted." Getting this wrong deletes a user's
backups because a token expired. It has its own scenarios below, and the answer
is always the same: no clean listing, no pruning.

@todo — no lib/Service/ exists yet.

### The pull does not trust "get-projects" alone about which projects exist

Confirmed live on Penpot 2.17.0: "get-projects" does NOT filter deleted_at,
while "get-all-projects" does, "get-project" 404s, and "get-project-files"
returns []. Files filter correctly everywhere — the bug is specific to the
projects listing. Trusting it would resurrect deleted project folders on
every pull.

### A design deleted in Penpot can still be rescued inside the grace window

Confirmed live (saga §6.42): "export-binfile" still exports a soft-deleted
file — 6496 real bytes from a file deleted moments earlier — even though it
404s on get-file and is invisible to every listing. This turns the one
genuinely unrecoverable case (a link file whose design is deleted) into a
best-effort-restorable one. Whether this runs automatically or is offered
is undecided — saga open question #38.

---

## designs/view

`features/designs/view.feature`

LOOKING AT A MIRRORED DESIGN — the only part of "it is a real file type" that
anyone actually performs.

**This replaced `file-type.feature`, which described a CONSTRUCT.** "A mirrored
Penpot file is a first-class file type" was about a mimetype, an icon and a
property set — none of which anyone does. Each turned out to be the end state of
something else:

| it described | whose end state it is | where it went |
|---|---|---|
| the mimetype is registered | **enabling the app** | `lifecycle.feature` |
| a file carries this metadata | **the pull** | asserted by `sync-now.feature`, shown here |
| the mode property's wire value | what the metadata says | the DAV view scenario |
| the context-menu glyph | the action that draws it | `open-with.feature` |
| the metadata cannot be edited | a refusal anyone can provoke | stayed, as a scenario |

Nobody registers a mimetype; they install an app. Nobody sets metadata; they map
a team and the pull stamps it. Once each end state sits with the behaviour that
produces it, what remains is looking — and that is a real thing to do.

FOUR SCENARIOS WENT AWAY ENTIRELY, because the reshape exposed them as
duplicates or as end states already owned elsewhere:

| scenario | why it went |
|---|---|
| A project folder is identifiable by both metadata and a visible tag | word-for-word the same scenario as `mapping-membership.feature`'s "A project folder carries a visible tag as well as its metadata" — same arrange, same two asserts |
| A file moved out of its mapped folder is unmapped, not untracked | the *rule* is `mapping-membership.feature`'s "A file with no project-id ancestor belongs to no mapping"; the *gesture* is `move-design.feature`'s move-out. Neither needed a third statement of it |
| The mode is visible and reflects whether content is stored | two `Given`/`Then` pairs in one scenario — two scenarios wearing one name. The DAV half merged into the view scenario; the body half is `set-mode.feature`'s demote scenario |
| The row icon and the menu glyph are separate files | two files with opposite contracts, so one scenario could not be the arrange for both. The row icon stayed; the menu glyph went to `open-with.feature` |

Ported from `kubed-io/nextcloud-n8n`, where the split landed first — itself
downstream of the mapping-table work that started here.

STILL NEEDED, EVEN THOUGH THE EXTENSION IS REAL (saga §6.4): Penpot's own
server serves an export as generic `Content-Type: application/zip` (confirmed
live, re-verified §6.20). So this app registers its own mimetype via the same
`occ maintenance:mimetype:update-db`/`update-js` mechanism both siblings use.
The one real win over both: `.penpot` is a SINGLE-TOKEN extension, not a
compound (`.n8n.json` / `.grafana.json`) — none of the "don't simplify the
compound extension" fragility n8n's AGENTS.md warns about.

METADATA KEYS (revised — saga §6.21/§6.22 changed this set):
  penpot_id       — the Penpot file id (the master's n8n_id / grafana_uid).
                    Stable thread; survives renames and moves because it's keyed
                    on Penpot's own id, not a name.
  penpot_revision — Penpot's "revn" + modifiedAt pair (saga §5.5), the drift
                    signal a pull diffs against so it can skip unchanged files.
                    NOT a push-loop guard (there is no content push) — a
                    read-side "is my copy stale" check only, unlike the
                    siblings' syncedHash keys which guard a writeback loop.
  penpot_mode     — "sync" or "link" (saga §6.22). NEW since an earlier draft,
                    which asserted no mode key existed. The axis came back
                    meaning something different from both siblings: not "which
                    way do edits flow" (they never flow out) but "do we store
                    the bytes at all."
  penpot_team_id  — the Penpot TEAM the design belongs to (saga §C6.7). Added
                    when the Files-app deep link needed it: Penpot's workspace
                    route refuses to open without a team, and a browser holding
                    one directory PROPFIND cannot walk up a freely-nested tree
                    to find the Team Folder's marker. See below for why this is
                    not a relapse into the removed "penpot_mapping" key.

WHY penpot_team_id IS NOT THE RETURN OF "penpot_mapping". The removed key
cached the file's POSITION — project AND team, as resolved from the folder tree
— and position is exactly what a move changes, so every move had to rewrite it
or it lied. A team id is not position: it is a property of the DESIGN in
Penpot, in the same category as penpot_id and penpot_revision. The PROJECT id
is still deliberately NOT stored on a file, because that one is position and
does change locally.

The folder walk stays the authority and VERIFIES the stamp: a move between two
mapped team folders really does change the owning team, so the move path
re-stamps from the resolver, and "occ penpot_sync:status" reports a
stamp-vs-folders disagreement rather than letting a stale link open the wrong
team's workspace.

DELIBERATELY REMOVED: "penpot_mapping". An earlier draft stored the file's
mapping on the file. That's redundant now that folder-level metadata is
confirmed working (saga §6.21, tested live on a real Team Folder) — the folder
already knows which project and team it is, so membership is DERIVED by walking
up two levels. Storing a copy on every file means rewriting it on every move,
which is exactly the drift the old move.feature tangled itself in.

SO A FILE'S STATE IS DERIVED FROM penpot_id + WHERE IT LIVES:
  mirrored  — has penpot_id, has a project-id ancestor folder (saga §6.29)
  unmapped  — has penpot_id, no project-id ancestor
  untracked — has no penpot_id
  ignored   — carries the ignore tag (a visible tag, not metadata — ignore.feature)

"Has a project-id ancestor" is a NEAREST-ANCESTOR walk at any depth, not a
fixed-level check — see mapping-membership.feature.

FOLDERS CARRY METADATA TOO (saga §6.21, §6.32):
  penpot_project_id — on a project folder. The authoritative machine record.
  penpot_team_id    — on a Team Folder.
Plus a visible system TAG on project folders, so a user can see and search for
them among ordinary folders — which matters under free nesting, where position
alone no longer tells you what a folder is.

BUILD STATE, corrected at C6.1 (the old note read "no lib/Service/ exists yet",
which has been false since Course 3):

  BUILT — the metadata keys, all five, written by the pull and advertised over
  DAV at `{nc:}metadata-<key>` with the indexed ones queryable (Course 3). The
  mapping-is-derived-from-folders rule, via MembershipResolver. The read-only
  guarantee: every key is registered EDIT_FORBIDDEN, so PROPPATCH is refused by
  core, not by us.

  BUILT AT C6.1 — the custom mimetype and icon. `application/vnd.penpot`, with
  no structured suffix: `+json` would be a lie for a `sync` mirror (a real ZIP)
  and `+zip` for a `link` one (a JSON pointer), and `+zip` is the worse lie
  because it invites a client to unpack a pointer. Registered by
  lib/Migration/RegisterMimetype.php on every install/upgrade, reverted on
  uninstall (lifecycle.feature).

  NOT BUILT — the project folder's visible system TAG (§6.32). The folder
  metadata is written; the human-visible pill is still Course 6 work. The
  fourth scenario below asserts both halves and only the metadata half holds.

@todo — the scenarios are all DAV/mimetype assertions and the integration
harness is occ-only. The mimetype registration in particular is UNASSERTED IN
CI right now: a repair step that silently failed to merge the config would look
exactly like one that worked. Named here so it is not assumed to be covered.

### A mapped folder shows its designs as designs

ONE SCENARIO, DELIBERATELY. Behat cannot read rendered pixels, so the icon is
proven the only way it can be: the file carries the app's own mimetype rather
than the `application/zip` a `.penpot` archive would otherwise be sniffed as —
a zip icon, no "Open in Penpot" action, and no hint as to why. Elaborating past
that would be testing Nextcloud's icon renderer.

ASSERTED OVER DAV, because that is where the Files app reads it. The mapping
file being right on disk proves nothing about what a client is told.

### The row icon is the app's colour mark

`@blocked`, and the reason is named: it is a rendering fact and not reachable
from HTTP.

WHY IT SURVIVED AS ITS OWN SCENARIO rather than folding into the mimetype one:
Nextcloud renders mimetype icons out of `core/img/filetypes/` WITHOUT
recolouring them, so that file must carry its own fill or it is invisible. That
is the opposite contract from the context-menu glyph, which NC *does* recolour —
which is why the menu half now lives in `open-with.feature`, next to the action
that draws it (saga §C6.1/§C6.7). One scenario could not honestly be the arrange
for both.

### Viewing the DAV properties on a file shows Penpot specific details

The keys are registered in Application::boot() precisely so they ride the
directory PROPFIND, and nothing had ever checked that they do. The app's own
`status` command cannot answer this — it reads the metadata store directly.

THE THREE KEYS A MIRROR ARRIVES WITH, plus the body that goes with them. A pull
mints every mirror in the mapping's default mode, which is `link`, so what a
fresh mirror publishes is exactly this: an id, its team, the mode, and nothing
in the file. Merged from what used to be two scenarios (the key set, and the
mode's wire value) because both had the same arrange and asserted on the same
PROPFIND.

`penpot_revision` is deliberately not asserted: a `link` file that has never
drifted carries an empty one, so requiring it here would make this scenario
about export state rather than about DAV advertising the key set.

`link` is stored as `reference` ON THE WIRE — the literal string `link` is
`is_callable()`, which crashes core's PROPFIND. The only place in this app where
a wire value differs from the name of the thing it carries, written down here
because a client author reading only the README would look for "link".

The mode axis — what promotion changes about that body — is `set-mode.feature`'s,
not this file's.

### A file carries the team its design belongs to, but never a project

THE ONE THING CACHED ON THE FILE (§C6.7), and the one thing that is not.
The team is stamped because the browser builds the workspace deep link from
it and cannot afford to walk a freely-nested tree on every render. The
project is NOT, because it is derived from the folders and a copy would go
stale on the first move — see mapping-membership.feature.

### What the app manages, only the app changes

A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can
attempt a PROPPATCH. The identity of a mirror is the app's to write — a client
that could edit `penpot_id` could silently re-point a file at a different
design. Every key is registered EDIT_FORBIDDEN, so the refusal comes from core
rather than from us.

The load-bearing assertion is that the VALUE did not change, not that a
particular status came back.

══ NEXTCLOUD'S TIMESTAMPS ARE PENPOT'S NOW ═══════════════════════════════

A mirror carries two sets of dates and they used to mean different things:

  Nextcloud's `mtime` / `creation_time`   when the app last wrote the node
  Penpot's `created-at` / `modified-at`   when the DESIGN was last changed

The first is now stamped FROM the second, so sorting a mapped folder by date
sorts by the designs rather than by sync activity (saga §C6.24).

THERE ARE NO SCENARIOS FOR IT HERE, DELIBERATELY. A modification time is not
a behaviour anyone performs — it is the shared RESULT of editing, moving,
copying and renaming, each of which is already owned by its own feature file.
A scenario asserting "the mtime moved" would be specifying Nextcloud, in the
wrong file, with an invented actor. So the assertions ride the behaviours that
cause them: a design changed in Penpot, and a mirror coming into existence —
both `sync-now.feature`.

This file keeps only what is genuinely about LOOKING at a mirror: which DAV
properties exist and who may write them.

THE CONSTRAINT THAT MADE IT SUBTLE (§C6.19) still holds and is now enforced
in `sync-now.feature`: a pull that changes nothing must move neither mtime
nor etag. `touch()` leaves a file's own etag alone but propagates a fresh one
to its PARENT FOLDER — which is what sync clients poll — so an unconditional
stamp would churn the folder on every tick. Every write is conditional.

A PROJECT FOLDER TAKES ITS CREATION TIME ONLY. Core propagates a folder's
mtime from its children, so stamping that would be a fight lost on every pull
that writes any design — and a propagated mtime is better information anyway
("something in this project changed"), since Penpot's project `modified-at`
only moves on a rename.

---

## lifecycle

`features/lifecycle.feature`

Stage 0: the app installs and uninstalls cleanly on a real Nextcloud.
A clean uninstall is also an app-store rule. No Penpot contact.

Identical shape to both sibling apps (nextcloud-n8n, nextcloud-grafana) — app
enable/disable has nothing to do with the read-only-vs-bidirectional split that
makes Penpot Sync architecturally different elsewhere (saga §6.1). This is a
clean, mechanical port.

LIVE — this is one of the first two features to come off @todo. It runs against
a real Nextcloud in CI (.github/workflows/integration.yml).

### Enabling the app

**THE MIMETYPE IS WHAT ENABLING LEFT BEHIND.** It used to head a file called "A
mirrored Penpot file is a first-class file type", which described the
registration as though someone had gone and done it. Nobody registers a
mimetype; they install an app, and the registration is the consequence — so it
is asserted here, on the install.

Proven by uploading a plain file rather than by reading the app's own metadata:
a file this app has never touched, with nothing but the extension going for it,
comes back typed as the app's own mimetype. That is what registration means and
the only part of it a client can observe. Without the repair step a `.penpot` is
sniffed as a generic archive (§C6.1), which is a zip icon and no opener.

THIS CLOSES A NAMED GAP. The old `file-type.feature` note said the mimetype
registration was UNASSERTED IN CI — "a repair step that silently failed to merge
the config would look exactly like one that worked". It is asserted now, and on
the one scenario that was already running.

Its visible consequence (a mapped folder that looks like designs) belongs to
`view-design.feature`; its removal is the "Removing the app" scenario below.

## mapping-membership

`features/mapping-membership.feature`

Where a file "belongs" — resolved by walking UP the folder tree, reading folder
metadata. This is the single most load-bearing rule in the app; almost every
other feature file defers to it.

THE RULE (saga §6.29, locked):

  A .penpot file belongs to the Penpot project recorded on THE NEAREST ANCESTOR
  FOLDER carrying a project id. A project folder belongs to the team recorded on
  THE NEAREST ANCESTOR FOLDER carrying a team id. No such ancestor ⇒ no mapping.

THIS REPLACES THE OLD "EXACTLY ONE LEVEL, HARD CAP" RULE. Earlier drafts capped
project folders at exactly one level below the team folder and treated anything
deeper as an error-ish "tolerated content" state. That cap was a legibility
guess made before we understood how cleanly folder metadata resolves — and it
imposed Penpot's flatness on Nextcloud, which doesn't share it. Withdrawn.

WHY FREE NESTING IS THE RIGHT CALL: Penpot is flat and rigid (team → project →
file, no sub-projects). Nextcloud is a file manager people organise however they
like. Identity lives in METADATA, not in path — so a project folder works
exactly the same at any depth, and a user can group five project folders under
a "Clients/" folder that has no Penpot counterpart at all. That's real value
Penpot itself can't offer, and it costs us nothing: "walk up until you find the
key" is the same lookup as "check one level up," minus the early exit.

THIS IS THE ONLY LAYOUT THERE IS. A mapping used to carry a folder mode, with
a second `keyed` layout where a project's NAME would be its path and free
nesting would not apply. It was designed, never built, and the field is gone
(§C6.36) — so nothing here is conditional any more. The alternative survives
as an open saga question (§6.53, #47), not as a value anyone can set.

THE MECHANISM IS CONFIRMED LIVE (saga §6.21): Files-Metadata attaches to
folders exactly as to files — same Node type, same fileid space. Tested
write/persist/read-back against a REAL production Team Folder (groupfolder 5),
with an ordinary folder as control. Identical results.

TWO MARKERS, TWO JOBS (saga §6.32):
  - folder METADATA (penpot_project_id / penpot_team_id) is the authoritative
    machine record — what every lookup below reads.
  - a system TAG is the human-visible pill in the Files app, so a user can SEE
    and SEARCH which folders are real Penpot projects. Under free nesting this
    matters more than it would have under the cap: position no longer tells you.

MEMBERSHIP IS DERIVED, NEVER STORED ON THE FILE. No "penpot_mapping" key — the
folders already know, and a stored copy would have to be rewritten on every
move, which is exactly the drift an earlier move-design.feature tangled itself in.

BUILT AND NOW LIVE. `MembershipResolver` has existed since Course 3 and every
other feature defers to it, but nothing in CI had ever asked it a question —
the resolver was the most load-bearing rule in the app and the least tested.
The scenarios below drive it through `occ penpot_sync:status`, which prints the
resolved membership alongside the raw markers, so a failure says which of the
two disagreed.

THE BACKGROUND WAS FICTION. It provisioned a Team Folder and mirrored a project
called "My Stuff", and none of those steps had ever existed — harmless while
the file was entirely @todo, an instant `--strict` failure the moment one
scenario went live. Same trap as create-project.feature (§C6.18). It is now the
standard Background: a PLAIN mapped folder, because Team Folder provisioning is
not covered by this suite (features/README.md).

### A file nested deeper inside a project folder still belongs to that project

The old cap called this "too deep" and orphaned the file. It is just a
subfolder — Penpot has no opinion about it, so neither do we, and the
design must not have moved project as a side effect of the drag.

### The nearest project id wins when project folders are nested

NEAREST ancestor, not outermost — this is what makes free nesting
unambiguous, and it is only reachable now that a project folder can be
moved inside another one. Nothing about the nesting reaches Penpot, where
both projects stay flat.

### A file at the mapped folder's root is in that team's Drafts

── the Drafts state: a team ancestor but no project ancestor ───────────────
Drafts is NEVER a folder (saga §6.35). It is the name Penpot gives to
"belongs to a team, sits in no project" — exactly what the nearest-ancestor
rule produces when it finds a team id but no project id on the way up.

This boundary is where §C6.8, §C6.9 and §C6.10 all lived, every one of them
the same mistake: reading "no project ancestor" as "outside every mapping"
when it means Drafts — a real project with a real id.

### A file in any plain folder under a team is also in Drafts

This is where Nextcloud is MORE expressive than Penpot: any arrangement of
ordinary folders under a team maps to the one Drafts bucket. Penpot has a
single Drafts because a flat system has nowhere else to put an unfiled
design; we can express the same state as a whole folder tree, for free.

### A project folder carries a visible tag as well as its metadata

Two markers, two jobs (§6.32): the metadata is what every lookup reads, the
tag is what a user can see and search for. Under free nesting that matters
more than it would have under the old depth cap — position no longer tells
you which folders are projects.

### A tagged folder's name always equals its Penpot project's name

Under free nesting a project folder is otherwise indistinguishable from an
ordinary folder someone named the same thing. Tag + matching name means a
tagged folder called "Acme" IS the Penpot project "Acme" — no ambiguity.
The rename half of the invariant is rename-design.feature's, where it is live.

### A plain folder inside a mapped folder is tolerated, not adopted

This is the whole point of the tag: ordinary folders can live among project
folders without becoming projects. The opt-in that DOES make one a project
is create-project.feature's, and it is live there.

### A folder opted in by tag resolves exactly like a mirrored one

The opt-in itself — what the tag DOES — is create-project.feature's, and it
is live there. This is only the half this file owns: once stamped, nothing
downstream can tell which direction the folder came from.

### A personal project folder has no team ancestor, and that is valid

The ONE exception to "walk up for a team" (saga §6.31) — a personal team
gets no folder of its own, so its projects sit at the home root. Without
this rule the natural implementation would treat every personal project as
an error. See personal-projects.feature.

### Two folders carrying the same project id is a reported conflict

Free nesting makes this reachable (copy a project folder and you have two).
The lookup stays well-defined; the WRITE target needs a tie-break. Which
rule to use is not yet decided — saga open question #30.

---

## designs/move

`features/designs/move.feature`

MOVING A DESIGN — every way a design can change project, team, or Drafts state,
from either side. Moving a PROJECT (the folder) is move-project.feature: a
different object with a different rule, because a project folder's position is
constrained where a design's is not.

This file used to be the Nextcloud half only: the Penpot half lived in
reconcile.feature and the two scenarios CI could prove lived in a third file
called gestures.feature. Three places, one behaviour. Now a move is a move,
whoever performed it, and the sections below are ordered by where it happened.

### THE GUIDING PRINCIPLE: DON'T LOSE DATA

A move never destroys bytes, never contacts Penpot destructively, and never
leaves a file in a state the user cannot get back out of. The closing invariant
below is stated for files AND folders, and move-project.feature relies on it
rather than restating it.

### NEXTCLOUD OWNS LAYOUT, PENPOT OWNS MEMBERSHIP (saga §6.29)

A design may sit anywhere inside a folder that maps to its real project — the
pull only ensures membership, never a particular path. That is what makes a
plain subfolder a legitimate place to file work.

### Dragging a sync design into another project re-files it in Penpot

Promoted to sync first because a `link` is confined to its project (§6.43)
and the guard refuses this drag before it happens — that refusal is its own
scenario below, and needs a different assertion.

### Filing a draft — dragging from the team root into a project

── across the Drafts boundary, both directions (§6.35) ───────────────────
Both walked by hand on a live instance before being written. They are the
same rule in mirror image: the team root has no project ancestor, so it IS
that team's Drafts — a real project, not an absence of one.

### Un-filing — dragging from a project out to the team root

The design is in Drafts now. The file simply sits at the team root, because
Drafts is a state and never a folder — Nextcloud stays more expressive than
Penpot here, and that is the point of the rule.

### Moving a design from a personal project into a mapped team project

── across two mappings: personal ⇄ a shared team ──────────────────────────
A user's home root and a mapped Team Folder are two mappings to two
DIFFERENT Penpot teams (personal-projects.feature — setting a personal token
maps the personal team to the home root, implicitly). So a drag between them
is a real cross-team move, and Penpot supports it directly: `move-files`
carries the destination's team with it, proven live in §6.27/§6.34.

ALLOWED IN BOTH DIRECTIONS, deliberately and for now. It is the simple
behaviour — the user moved a design, so the design moved. An admin option to
FORBID moving designs out of a team folder is a reasonable thing to want and
is deliberately NOT specified: see saga §C6.21 for why it is a bigger
decision than it looks.

@unbuilt rather than @todo: the personal side of the mapping does not exist
in `lib/` at all. The cross-TEAM machinery underneath these does — it is the
same `move-files` the scenarios above use.

One call does both: the destination project's team follows automatically.
The re-stamp matters because the workspace deep link is built from it
(§C6.7) — a stale one opens the wrong team's workspace.

### Moving a design out of both mappings unmaps it, from either side

── RULE: a link may not leave the project it points into (§6.43) ────────

A "sync" file is a real archive, so moving it anywhere leaves the user
holding something valuable. A "link" is a POINTER — move it out and they
hold an empty husk that looks like a design and isn't. So links are
confined, and every refusal offers the same escape: promote to "sync" first.
That is not a fob-off; it is exactly the action that makes the move safe.

ONE RULE, THREE DESTINATIONS — which is why these are Examples rather than
three scenarios. The destination is an INPUT; the outcome is identical for
every row. Contrast the Drafts pair further down, which look equally
symmetrical and are two different rules read from opposite ends.

(Written as a comment, not a Gherkin `Rule:` block — Behat's parser rejects
that keyword outright. See features/README.md.)

### A link cannot be moved out of the project it points into

DRIVEN LIVE. The guard is the only thing in this app that says no, and until
now nothing proved it ever does — a guard that silently stopped refusing
would hand someone an empty husk that looks like a design, and every test
would still have been green.

Row 1 is another project, row 2 is the team root (which MEANS Drafts, a
real project change, §6.35), and row 3 leaves every mapping. One rule,
three destinations, one outcome — which is what makes it a table.

The last two assertions are the point of the refusal: the file is still
where it was, still tracked, and Penpot never heard about any of it.

### A design moved to another project in Penpot relocates its mirror

THE PRUNE MUST NOT FIRE. The design is still named by Penpot, just from a
different project — a reconciler that keyed on "not in this folder" instead
of "not in this team's listing" would trash the mirror and re-create it,
losing a `sync` file's archive on the way past.

---

## projects/move

`features/projects/move.feature`

MOVING A PROJECT — the folder, and the one rule that makes it different from
moving a design: a project folder's POSITION is constrained where a design's is
not. Moving a design is move-design.feature.

WHY A PROJECT FOLDER IS PINNED TO ITS TEAM FOLDER. A project belongs to exactly
one Penpot team, and the team folder IS that team in Nextcloud. Dragging the
folder out of it would assert a membership Penpot has no way to represent — so
it is refused, visibly, with the alternative spelled out. Inside its own team
folder the user may put it wherever they like: Nextcloud owns layout, Penpot
owns membership (saga §6.29/§6.30).

THE INVARIANT THAT COVERS BOTH FILES lives in move-design.feature — "no move,
of any file or folder, ever deletes anything in Penpot" — and is not restated
here, because one copy of a rule is the point of splitting these.

### The project-folder refusal explains why, and what to do instead

Split from the scenario above, which proves the refusal HAPPENS; this one
is about what it SAYS, and needs the exception body surfaced through DAV.
Saga §6.30. Reparenting a project in Penpot (`move-project`) is real and
confirmed, but it is a destructive cross-team mutation that changes who can
see the work — far outside §6.1. Refuse loudly; never silently undo.

### A project folder cannot be moved into a different team's folder

══ MOVED IN PENPOT ════════════════════════════════════════════════════════

The same behaviour from the other end, and it arrives via a sync run rather
than an event. Penpot is authoritative for project membership, so a design
re-filed upstream relocates its mirror — it is not a conflict to resolve, it
is the source of truth changing.

### A user can move their personal project folders anywhere in their home

── the same rule in a personal team ────────────────────────────────────────
A personal project is a project. The WHO (the user's own token) and the WHERE
(their home root, no team-folder ancestor) differ; the rule does not — see
personal-projects.feature for what actually is special about them.

---

## designs/open-with

`features/designs/open-with.feature`

"Open with" — the opener(s) offered for a mirrored ".penpot" file.

RADICALLY SIMPLER THAN BOTH SIBLINGS, and for a specific, locked reason (saga
§6.1): there is no "Edit as text" action AT ALL. Both n8n and Grafana offer a
raw-JSON text editor as a second opener because their files hold editable
content that can meaningfully round-trip through a hand-edit + save + push. A
`.penpot` file is an opaque ZIP of nested design-shape JSON (saga Course 2) —
there is no sane way to hand-edit it and re-import it coherently, so this app
never offers a text-editor opener, not even for unmapped/untracked files (both
siblings default UNMAPPED files to the text editor as their opener; Penpot
Sync has no equivalent because there's nothing editable to fall back to).

ONE OPENER, AND THE MODE AXIS DOESN'T CHANGE IT. This app does have a
sync-vs-link mode (saga §6.22 — an earlier draft of this header said it didn't),
but the mode governs whether the ARCHIVE is stored locally, never whether the
design can be opened. Both siblings' default-click table has a row per mode
because their modes change what a click means; here every mirrored file that
carries a "penpot_id" gets exactly one action regardless of mode: "Open in
Penpot", a deep link to the live design.

WHERE MODE DOES SHOW UP: downloading. A "sync" file hands you a real .penpot
archive; a "link" file has no bytes to hand over, so the app says so rather
than serving an empty placeholder that looks like a design export.

A file with no "penpot_id" (never tracked) has no Penpot-specific opener at
all; it falls through to whatever Nextcloud does with a generic archive. A file
whose design was DELETED in Penpot is a third case — it has an id, but that id
is permanently dead (saga §6.20), so the opener reports that instead of
following a link it knows is broken.

AND WHEN THE OPENER HIDES, NEXTCLOUD TAKES OVER — DELIBERATELY. With no action
registered for it, a click falls through to core's default for the mimetype: a
download. That is the right ending, not a consolation prize. Nothing can open
the design any more, so the bytes on disk are the whole remaining value of the
file — and for a "sync" mirror those bytes are the real archive, which is
exactly the case the local backup exists for. So "hide the action" is a
decision about what a click SHOULD do, not merely what it must not do.

BUILT AS OF C6.1 — and still @todo here, for a reason that changed. It used to
be "no src/files.js exists yet"; src/files.js now exists and registers exactly
one action, "Open in Penpot", as the default click. What is missing is a way to
RUN these scenarios: every one of them is a click or a context menu, and the
integration harness is occ-only with no browser driver (the same wall
rename-design.feature and admin-section.feature describe). @todo here means "not
executable from this file", not "unimplemented".

WHAT IS ASSERTED INSTEAD, and where: tests/js/files-helpers.test.js covers the
logic these scenarios would exercise — that both modes offer the opener
identically, that `unmapped` hides it, and the exact deep-link shape. The parts
no unit test can reach are the registration itself and the default-click
promotion.

THE DEEP LINK IS <base>/#/workspace?file-id=<penpot_id> (saga §C6.1), read off
a live Penpot's own route table rather than guessed — §C3.4 refused to write it
until it could be confirmed. It keys on the file id ALONE, which is why the
"moved out of its mapped folder" case still links: no ancestor folder is
consulted.

STILL GENUINELY UNBUILT, not merely unrunnable, and named so C6.1 is not
credited with it:
  - the download-refusal for a `link` file — needs a WebDAV-layer guard (the
    siblings' LinkWriteGuardPlugin shape); today a `link` downloads as a
    zero-byte file without comment. That is at least honest — the old JSON body
    handed you something that looked like a design export and was not — but
    "here is an empty file" is still not the sentence the scenario asks for.
  - the deleted-design case is built only HALFWAY. C6.1 hides "Open in Penpot"
    for an `unmapped` file rather than following a dead id (§6.20), which is
    the "instead of dead-linking" half. It does not yet REPORT why, and does
    not offer the restore. Hiding is the safe subset; the sentence the scenario
    asks for is a later slice.

### The "Open in Penpot" glyph is drawn for a menu

TWO FILES, ONE MARK (saga §C6.1/§C6.7). The context-menu glyph and the Files-row
icon are the same drawing with opposite colour treatments, and collapsing them
fails in both directions — this is not a style preference.

Nextcloud applies its own fill to a menu glyph, which overrides `fill="none"`
and floods a stroked outline into a solid tile. A filled shape cannot fail that
way — recolouring it just recolours it. Mimetype icons are the opposite: NC
renders those out of `core/img/filetypes/` WITHOUT recolouring, so that file
must carry its own fill or it is invisible. That half is `view-design.feature`'s,
because it is a property of the file type rather than of this action.

This scenario used to sit in `file-type.feature` alongside the row icon, as
though the two were one fact. They are two files with opposite contracts, so one
scenario could not honestly be the arrange for both — and a menu glyph belongs
next to the menu entry that draws it.

`@blocked` for the same reason every scenario in this file is: no browser
driver.

---

## personal-projects

`features/personal-projects.feature`

PERSONAL PROJECTS — the WHO and the WHERE, and nothing else.

### WHAT THIS FILE OWNS, AFTER THE DESIGN/PROJECT SPLIT

A personal project is a project. Creating, renaming, moving, copying and
deleting one behaves exactly as it does in a mapped team — so those scenarios
live with their verb (create-project.feature, rename-project.feature, and so
on), where a reader comparing the personal case against the team case sees both
answers in one table instead of hunting two files. That is the same rule the
whole `-design` / `-project` split rests on: organise by BEHAVIOUR, not by the
kind of thing acted on.

What genuinely differs is not the behaviour but its two coordinates:

  WHO    the user's OWN personal token, never the service account. No token,
         no personal projects at all — and clearing one stops the pull without
         deleting anything.
  WHERE  the user's home root, with NO team-folder ancestor. The personal team
         itself gets no folder, which is why the membership resolver has to
         cope with a project folder that has no team above it (saga §6.29).

Those two are what this file is for, plus the isolation that follows from them:
one user's personal projects never appear in another user's home.

THE ONE PLACE THE PERSONAL CASE REALLY DIVERGES — deleting a personal project
folder does not touch Penpot — is stated in delete-project.feature, beside the
team answer it contradicts. A divergence hidden in a file nobody opens while
holding the question is a divergence nobody finds.

### Setting a personal token maps the personal team to the home root

── the implicit mapping, and what it makes possible ────────────────────────
These are the scenarios the "home root IS the mapping" reading adds. They
are not a second pull pathway; they are what having a team ancestor at the
root means for every OTHER feature, which is the point of framing it as a
mapping rather than as a sync job.

---

## designs/purge

`features/designs/purge.feature`

Purge — an admin-only button beside "Sync from Penpot" and "Test connection"
(also `occ penpot_sync:purge`) that removes the mirrored ".penpot" files THIS
APP created and nothing else. It deletes every mirrored file across all
mappings, and:
  - never contacts Penpot at all (there is nothing to guard against pushing —
    unlike both siblings, there is no SyncGuard-style "don't mirror this
    delete back out" concern, because there is no writeback direction to
    guard, saga §6.1);
  - leaves the mappings configured;
  - leaves the custom mimetype registration alone (that is uninstall's job).

THE IGNORE MARKER IS PRESERVED, SAME AS BOTH SIBLINGS. An earlier draft of this
header claimed Penpot Sync had no ignore mechanism, reasoning from saga §6.3
(Penpot's API has zero tag support). That conflated the two sides: the ignore
marker is a NEXTCLOUD system tag, and Nextcloud has tags regardless of what
Penpot offers. §6.23 established it, and purge must respect it — a purge that
deleted ignored files would destroy the one thing the tag exists to protect.

WHAT PURGE MUST REASON ABOUT: mirrored (delete), unmapped (keep), untracked
(keep), ignored (keep). And within "mirrored", the MODE matters for what the
user actually loses — a "sync" file holds a real archive, a "link" file holds
nothing (saga §6.22), so the confirmation says which.

Driven headlessly through `occ penpot_sync:purge`.
Two intended flows: purge → "Sync from Penpot" (everything reappears, since
Penpot's copy was never touched), and purge → uninstall (Nextcloud looks like
the app was never there).

@todo — no lib/Command/Purge exists yet.

---

## team-mapping/delete

`features/team-mapping/delete.feature`

Removing a team mapping — the admin deletes a mapping from the list (or
`occ penpot_sync:remove-mapping`). This is NOT the "Purge Nextcloud files"
button (that keeps the mapping and never touches Penpot — see purge.feature).
Removing a MAPPING tears down the connection: what happens to the files that
were mirrored through it?

A MAPPING IS A TEAM, AND THAT'S THE ONLY THING THERE IS TO REMOVE (saga §6.24).
An earlier draft had a "remove the My Stuff project mapping" scenario. That
operation doesn't exist and never coherently could: project subfolders are
MIRRORED by the pull, not mapped by a human, so "removing" one would just mean
the next pull recreates it. One mapping object, one lifecycle.

GRAFANA HAS THIS FILE, N8N DOESN'T — Grafana's exists because its recycle-bin
setting gives removing a mapping a two-path story. This app has no such
setting: Penpot provides its own trash (saga §6.49/§6.52), and it only ever
engages on an explicit "Delete in Penpot" action. Removing a mapping never
deletes anything in Penpot at all, so teardown collapses to ONE rule — but the
file is still needed, because the app DOES provision real folders that a
removed mapping leaves behind.

THE CONTRACT: every mirrored file connected to the removed mapping goes to the
Nextcloud trash and becomes unmapped — purely local, since there is no remote
state to reconcile. Files that were never part of the mapping are left strictly
alone. Penpot is never contacted, at any point.

MODE MATTERS FOR WHAT THE USER ACTUALLY LOSES (saga §6.22): a trashed "sync"
file still holds its real archive, so it's recoverable content. A trashed
"link" file was only ever a pointer — there's nothing in it to recover. The
teardown warns about this, because "removing a mapping deleted my backups" and
"removing a mapping deleted some pointers" are very different events.

@todo — no lib/Service/MappingTeardownService exists yet.

---

## designs/rename

`features/designs/rename.feature`

Renaming a DESIGN — the mirror file and the Penpot file it points at.
Renaming a PROJECT (the folder) is rename-project.feature: same gesture, but a
different Penpot object, a different RPC, and a different set of name rules.

Rename is the ONE place saga §6.1's read-only stance is genuinely narrower than
it sounds. BOTH DIRECTIONS ARE SETTLED (saga §6.54 closed the §6.2 fork).

PENPOT → NEXTCLOUD: covered by the same pull as any other change — the pull
compares Penpot's current name against what's on disk and renames the Nextcloud
file to match, keyed on "penpot_id". Free, because the name comes
back in the ordinary listing (saga §5.5) — no export needed.

NEXTCLOUD → PENPOT: RATIFIED (saga §6.54). `rename-file` works — HTTP 200,
returning {id, name, created-at, modified-at}. Read-only was always about
CONTENT (shape data we cannot round-trip), not about a one-field name.

WHY IT WAS RATIFIED, briefly:
  - §6.36 already locked that renaming a PROJECT propagates. Leaving files as a
    silent no-op made one gesture behave two ways in one Files app.
  - §6.22 makes Penpot authoritative for a mirrored file's name — so NOT
    propagating means a user's rename silently REVERTS on the next pull, the
    exact failure mode this app exists to avoid.

THE PARAM TRAP (saga §6.54): `rename-file` takes the id under plain **`id`**,
NOT `file-id`. Confirmed live: `file-id` returns HTTP 400 :params-validation.
Fourth distinct param convention in this API — PenpotClient needs an explicit
per-command table, not a rule.

BUILD STATE: the NEXTCLOUD → PENPOT path is built and unit-tested, verified live
on the pod. These stay @todo only because the harness is occ-only — no
Files-app / WebDAV channel to fire a real NodeRenamedEvent, and no logged-in
session for the personal-token branch. They flip when that channel lands.

### A rename is picked up in both modes, without an export

── Nextcloud → Penpot: RATIFIED (saga §6.54) ───────────────────────────────
The fork is closed: renaming a mirrored file in the Files app DOES propagate,
via "rename-file". §6.1's read-only stance was always about CONTENT — shape
data we cannot meaningfully round-trip — not about a one-field name.

### Renaming a mirrored file in Nextcloud renames the Penpot file

The extension is a Nextcloud-side affordance (saga §6.4) — Penpot's own
name never carries it. This is the one thing file rename does that project
rename does not (project folder names are bare).

### The rename call sends the file id under the plain "id" parameter

Confirmed live (saga §6.54): {"file-id": ...} returns HTTP 400
:params-validation with missing-key [:id]. There is no inferable casing
rule across this API — only a per-command table. See saga open question #21.

### A propagated rename is attributed to the acting user

This is the whole reason personal tokens exist (saga §6.18) — rename is one
of the app's few write paths (saga §6.19), all of which attribute the same
way.

### An empty file name is refused before it is sent

── the name guard: the same shape at both levels ───────────────────────────
THE GUARD RUNS BACKWARDS FROM EXPECTATION (saga §6.38). Penpot accepts
essentially any non-empty string up to 250 characters — confirmed live:
upper case, lower case, emoji, dots, leading spaces, and even "Has/Slash"
all create fine. NEXTCLOUD is the stricter side. So going out, the only
real check is non-empty and 250; coming in, a name Nextcloud cannot spell
as a folder is the problem, which is the "/" section below.

Penpot enforces this too — [:string {:min 1, :max 250}], confirmed live to
return HTTP 400 on "" (saga §6.54). Our guard is a better message and a
saved round trip, not the only defence.

### A Penpot file whose name contains a slash is skipped with a clear reason

Penpot accepts "/" in a FILE name exactly as it does in a project name —
confirmed live, HTTP 200 (saga §6.54). So the §6.51 guard applies at both
levels, with the same refuse-and-report rule and a narrower blast radius:
one file skipped, not a whole subtree.

### Renaming never breaks the Penpot link

── "/" IN A PROJECT NAME: INVALID (saga §6.51) ─────────────────────────────
The project-level twin of the scenario above, and the wider blast radius —
a project that cannot be spelled as a folder takes its whole file list with
it. Nextcloud nests freely (§6.29), so a "/" in a project name would mean
nothing here.

This used to be qualified with "in nested mode", against a `keyed` mode where
a "/" would have BEEN the path. That mode was designed and never built, and
the field is gone (§C6.36) — so the rule is now unconditional, which is how it
always behaved.

Checked live against Nextcloud's IFilenameValidator: the ONLY forbidden
characters are "\" and "/" (plus ".."/"." as segments, ".htaccess", and the
.part/.filepart extensions). Everything else — "a:b", "a*b", "CON",
".hidden" — is a perfectly legal folder name. So this is a two-character
problem, not a general sanitisation problem.

THE APP REJECTS IT AT THE SOURCE where it can: it owns project creation
(create-project.feature's tag opt-in) and project renames (§6.36), so a "/"
never enters Penpot through this app. What is left is the only case it
cannot reach — a name typed directly in Penpot's own UI.

The invariant under every rename path: the name changes, the identity does
not. A rename that re-created the design would break every mirror, archive
and deep link that points at it.

### Renaming a design that was just copied propagates to Penpot

WALKED BY HAND, AND IT FAILED — but not here. The copy had silently failed
to record its "penpot_id", so this rename correctly ignored an untracked
file and looked like the bug (saga §C6.9). Kept in rename-design.feature as well
as copy-design.feature on purpose: the symptom appeared at THIS gesture, so this
is where someone will come looking.

### Renaming an untracked ".penpot" file is not a failure

This is correct behaviour and must stay — a file we do not track is not
ours to rename anywhere. It is also indistinguishable, from the user's
side, from the bug above. That is exactly why the tracking failure has to
be loud where it happens.

---

### Renaming a mirrored file renames its design in Penpot

Penpot's name never carries the ".penpot" extension (§6.4) — the assertion is on
"New Name", not "New Name.penpot", and that is the whole rule.

THE NAME IS NOT A TABLE ROW BECAUSE IT IS NOT STORED. A mirror is bound to its
design by `penpot_id`, never by what it is called, which is why a rename cannot
break the link. `the design's id` resolves through the NEW name, so one row
asserts both halves: the design is called that now, and this file is still that
design.

## projects/rename

`features/projects/rename.feature`

Renaming a PROJECT — the folder in Nextcloud and the Penpot project it maps to.
Renaming a DESIGN is rename-design.feature: same gesture in the Files app, but
a different Penpot object (`rename-project` vs `rename-file`) and a different
set of name rules, because a project name has to survive becoming a folder name.

A PROJECT IS A FOLDER. That is why this file exists separately: in Nextcloud a
Penpot project has no representation other than a folder, so every constraint
Nextcloud puts on folder names lands here and nowhere else.

PENPOT → NEXTCLOUD: the pull compares Penpot's project name against the folder
on disk and renames the folder, keyed on `penpot_project_id` — never on the
name, which is exactly what a rename would defeat.

NEXTCLOUD → PENPOT: locked since saga §6.36. This direction was settled BEFORE
the file rename was (§6.54), and the asymmetry it created is what forced that
decision.

THE NAME RULES ARE THE SUBSTANCE HERE. A project name that cannot become a
folder name is REFUSED rather than sanitised (saga §6.51): "foo/bar" and
"foo-bar" would both collapse to "foo-bar", silently merging two distinct
projects into one folder with no way to tell which is which. Refusing visibly
beats breaking the names-always-match rule invisibly. Inferring a parent folder
from the "/" is a whole different layout — an unbuilt saga design (§6.53), never
a fallback triggered by one awkward name.

### rename-project: Background

A PROJECT FOLDER IS ITS OWN FLOW, not a variant of the file rename (§6.36 /
§6.39): a different event, a different id, a different RPC, and a 204 with no
body instead of a record. It had no live coverage at all, which meant the two
rename paths were one green test and one assumption.

The assertion works because `penpot_sync:probe` lists PENPOT's own project
names — so finding a design under the new name proves Penpot renamed the
project, not merely that Nextcloud renamed a folder.

### Renaming a project folder does not touch the designs inside it

`rename-project` takes the PROJECT id; nothing about the files changes, and
a regression that sent file ids here would rename a design instead — which
this catches, because the design would no longer be found by its own name.

### A failed project rename leaves the local rename standing

Saga §6.18 rule 3 — a remote failure never destroys local state. Same rule
as the file twin below, and it has to be stated for both because they are
different listeners reading different ids.

### The app never sends a slash to Penpot

A Nextcloud folder name cannot contain "/" anyway, so this is automatic for
renames — but it must also hold for the CREATE path (create-project.feature's
tag opt-in), which is where a name could be composed rather than typed.

### The app never invents a substitute name

Sanitising is REJECTED (saga §6.51): "foo/bar" and "foo-bar" would both
become "foo-bar", silently collapsing two distinct projects into one folder
with no way to tell which is which. That breaks the names-always-match rule
invisibly, which is worse than refusing visibly. Inferring a parent folder
from the "/" is a whole different layout — an unbuilt saga design (§6.53), not
something to fall back into because one name happened to contain a slash.

---

## designs/restore

`features/designs/restore.feature`

RESTORING A DESIGN — out of the Nextcloud trash, out of Penpot's trash, or out
of an archive when both are gone. Restoring a PROJECT is restore-project.feature.

### THE ORDER IS THE FEATURE (saga §6.49/§C6.11)

The app always offers Penpot's own trash BEFORE an archive import, and the
difference is not cosmetic: a trash restore returns the SAME design — same id,
same revision, same history — while an import creates a new one that merely
looks like it. Only once Penpot's grace window has closed is an import the best
that remains, and the app says so rather than quietly producing a lookalike.

### A RESTORE IS CONFIRMED, NEVER ASSUMED

Penpot's success event is not proof: the restore is re-read from the same
listing the pull uses before it is reported as done. A restore that did not
actually happen must never be announced as one.

### A LINK HAS NOTHING TO RESTORE INTO PENPOT

Restoring a dismissed `link` un-hides a pointer. It never pushes anything back
into Penpot, in any circumstance.

### Restoring a design brings back the file and its design together

ONE BEHAVIOUR, AND IT USED TO BE THREE.

There were three scenarios here: this gesture, "a pull after a restore
neither prunes the mirror nor duplicates it", and "a pull after a restore
does not trash the mirror a second time". The last two were not behaviours.
They asked whether the SCHEDULED SYNC treats a restored item like any other
mapped item — which is the reconciler, and the reconciler is how this app
works, not something a user does. Writing it as a scenario also put the
gesture in the Given block ("And I delete …") and the machine in the When
block ("And the team has been mirrored into Nextcloud"), so the file read as
though a user deletes things during setup and runs syncs by hand.

What is left is what the user actually does and what they actually get: the
file comes back, the design comes back with it, and the id is the same one —
so nothing downstream can mistake it for a new design. Once that holds, the
sync has an id and a listing like any other mirror and needs no scenario of
its own. Not duplicating and not re-trashing are guarantees the CODE owes,
and RestoreServiceTest is where they are pinned.

The pre-state is state, not a gesture: "is in the trash" says what is true
before the behaviour, and how it got that way is the step definition's
business.

### Restoring a file that was never in Penpot leaves Penpot alone

A restore only ever puts BACK something this app mirrored out. Inventing a
design for a file that never had one is team-import.feature's open fork, and
it must not happen by accident on the way out of the trash.

THE OLD VERSION ASSUMED SOMETHING THE APP DOES NOT ALLOW. It staged a
"mirrored design in the project Bystander", uploaded a second, untracked
`.penpot` NEXT TO IT inside that same mapped project folder, and then
asserted the bystander was still there — an uninvolved file being uninvolved,
which is not a claim worth a scenario unless the two could collide, and they
cannot.

Worse, it implied a `.penpot` file can sit inside a mapped project folder
WITHOUT being in Penpot. Nothing in this app produces that state: anything
under a mapping is mirrored. An opt-out marker — a `penpot:ignore` tag, say —
would create it, and that idea has never been designed, only implied by this
scenario. It is written down in the saga as an open question rather than
smuggled in as a fact the spec depends on.

So the file lives OUTSIDE every mapping, which is the only way a `.penpot`
file is genuinely untracked today, and the assertions are about it alone.

### An untracked file is never restored, because it was never in Penpot

Creating brand-new Penpot files from Nextcloud is a separate, still-open
fork (team-import.feature) — restore only ever puts BACK something that
this app previously mirrored out.

### Restoring a deleted design states clearly what does and does not return

Stated as "here is what you get and what you don't", not as a warning about
failure — because the design itself really does come back (saga §6.41).
NOTE: this scenario only applies once Penpot's own ~7-day trash window has
closed. Inside it, the app restores losslessly instead — see below.
That last line matters: if the delete was recent, recovering it IN PENPOT
keeps the id, the links and the history — strictly better than what we can
offer. Pointing the user at the better option, even though it isn't ours,
is the honest thing to do (saga §6.26).

### A design still in Penpot's trash is restored losslessly, not imported

Layer 2 always beats layer 3 (saga §6.49/§6.52), and it is BUILT: the trash
listing is read before anything else is considered. Kept here as the rule
this file must obey; its live scenarios are in delete-design.feature.

### A restore that Penpot did not actually perform is never reported as success

§C6.11: handed an id it does not restore, Penpot answers 200 with an `end`
event carrying an EMPTY SET. The ids in that set — not the status, not the
existence of the event — are the answer.

### A restore is confirmed against the listing the pull reads

NOT "is it out of the trash?", which sounds equivalent and is not. Penpot's
restore returns BEFORE its transaction settles (§6.49), and in that window
the trash listing can stop naming a design while the project listing still
omits it. The pull decides what to prune from the project listing, so that
is the only answer worth having — asking the other one failed this file's
own scenario about half the time (§C6.15).

### A pull after a restore leaves exactly one mirror, in any mode

THE GAP THIS SLICE CLOSED. Before it, the design stayed in Penpot's trash,
so the pull saw a design Penpot no longer named and pruned the mirror a
second time (with a final snapshot, C5.1). Nothing was lost; the file
appeared to delete itself twice, which is its own kind of bad. Restoring
the design upstream is what makes the pull leave the file alone.

### A design that never left Penpot is restored locally and nothing is sent

── restoring a whole PROJECT, and the asymmetry that makes it tricky ──────

PENPOT HAS NO `restore-project` (checked in its source: projects.clj offers
create / rename / delete / pin and nothing else). A project comes back only
as a SIDE EFFECT of restoring one of its files — `restore-deleted-team-files`
collects the `project-id` of every file it restores and clears `deleted_at`
on those projects too.

That makes restore asymmetric with delete, and the asymmetry is measured,
not inferred (§C6.19). Deleting a project with two designs trashes both.
Restoring ONE of them:

    the project        → back, listed again by get-all-projects
    the file restored  → back in the project
    the OTHER file     → still in the trash

So "restore the folder" must mean "restore every design that was in it",
in one call, or the user gets a project back with a hole in it. The one
call is also the only way to reach the project at all.

AND A PROJECT DELETED WHILE EMPTY CANNOT BE RESTORED THROUGH THE API AT
ALL — there is no file to carry it back. It simply expires.

Layer 1. The mirror was trashed while Penpot was unreachable, or someone
restored the design in Penpot's own UI first. Nothing was ever lost
remotely, so taking the file out of the trash IS the whole restore.

### A design that is gone for good is not silently recreated

Layer 3, and it is NOT BUILT: importing the archive would mint a NEW id
(§6.20 — a purged id cannot be resurrected, tested directly), so it is a
user decision with real consequences, specified in restore-design.feature. The one
thing that must not happen is quietly doing nothing.

---

## projects/restore

`features/projects/restore.feature`

RESTORING A PROJECT — the folder, and everything that was in it. Restoring a
design is restore-design.feature.

A PROJECT COMES BACK WHOLE, OR IT DOES NOT COME BACK. Restoring the folder
restores the project and every design that went down with it — restoring one
design of a deleted project does NOT silently resurrect the project around it,
because that would be inventing a container the user never asked for.

THE ONE THING THAT CANNOT BE UNDONE: a project deleted while EMPTY has no
design to restore it through. Penpot exposes no restore-project RPC — a project
returns only as a side effect of restoring one of its files (saga §C6.11,
confirmed live) — so an empty one is genuinely gone, and the app says so
plainly rather than failing in a way that reads like a bug.

### Restoring a project folder brings back the project and every design in it

ONE call with the whole set, not three calls. Penpot restores the project
from any file in it, so three calls would restore the project on the first
and then merely add files — but a partial failure would leave a project
holding some of its designs, which is worse than either extreme.

### Restoring one design of a deleted project does not silently restore the rest

Confirmed live (§C6.19). Stated because it is genuinely surprising, and
because a naive "restore the folder" that fired one call per file it
happened to find in the Nextcloud trash would produce exactly this
half-restored state without ever looking wrong.

### A project deleted while empty cannot be restored, and the app says so

Penpot offers no `restore-project`, and there is no file to carry it back.
Saying so is the whole behaviour — the alternative is a folder that looks
restored and points at nothing.

---

## team-mapping/set-mode

`features/team-mapping/set-mode.feature`

Promoting and demoting a single file — the whole of the mode axis, now that
`sync-mode.feature` is retired into it.

THE AXIS MEANS SOMETHING DIFFERENT HERE THAN IN EITHER SIBLING (saga §6.22).
In n8n and Grafana, `sync` vs `link` decides which way edits flow. This app never
writes design content back at all (§6.1), so the axis decides only WHETHER WE
STORE THE BYTES:

    link  — a pointer. No archive stored. Never calls export-binfile.
    sync  — a real backup. The .penpot archive is downloaded and stored.

A `sync` file is still a read-only mirror. LINK IS THE DEFAULT, which is a safety
property as much as a performance one: the expensive path is opt-in, so a newly
mapped team of 500 files costs a listing rather than 500 exports. A `.penpot`
export is a full ZIP with embedded binaries, not a JSON diff — most designs need
to be findable and clickable, not duplicated.

### Promoting a mirrored design fetches a real ZIP from Penpot

PROMOTION IS PURELY ADDITIVE, which is what the table shows at a glance: the mode
is "sync" and there are real bytes, while the file still names the same design in
the same team. An export never writes to Penpot and never re-stamps the id, which
is what makes it safe to retry.

### A promoted file is not re-exported by the next pull

Mode is stored PER FILE, and an unchanged revision means an unchanged archive, so
staying in sync mode is free until the design actually moves.

The one scenario here where a pull IS the subject, because "a second run does not
undo the first" is a claim about running it twice.

### Demoting throws the archive away and never contacts Penpot

The bytes are gone and the mode says so, while the file still names the same
design — and that design is still in Penpot, which the last line says out loud
because "never contacts Penpot" is a claim about the far side.

`penpot_revision | set` is deliberate: a demotion throws away the bytes but keeps
the file's record of which revision it held, so a later promotion knows whether
what it fetches is new.

### WHY THIS SUITE IS WORTH A LIVE PENPOT

Promotion is the app's only code path that moves real bytes out of Penpot, and
it is four unmockable steps in a row (saga §5.1–§5.4, §C4.8):

  1. POST `export-binfile` — the response is an **SSE stream**, not JSON;
  2. the stream's `end` event carries a Transit **tagged map**, `{"~#uri": …}`,
     a form the decoder originally mistook for plain JSON;
  3. that URI needs a **second authenticated GET** to a different path entirely;
  4. and only then are there ZIP bytes.

Every one of those was discovered by watching a real instance rather than by
reading its source, and every one would happily pass a mocked test while
failing on the wire — a proxy that buffers the stream, an event that gets
renamed, or an asset URL unreachable from inside the cluster (§5.3: an nginx
resolver bug made exactly this fetch 502 while the export itself "succeeded").

So the assertion below is deliberately crude and physical: after a promotion
the mirrored file **begins with a ZIP's magic bytes**. Not "the client was
called" — a ZIP arrived.

### THE CHEAP PATH IS ASSERTED TOO, BECAUSE IT IS THE WHOLE POINT

`link` mode's entire claim is that mirroring a team costs a listing and
nothing else. A regression that quietly exported every file would still pass
every other scenario in this suite, and would first be noticed as a bandwidth
bill — so the zero is asserted, not assumed.

### Demoting asks first, because it deletes the only local copy

`@blocked` — **no tty**. Behat has no terminal to answer a confirmation prompt,
which is why every other scenario here passes `--force`, and why the prompt
itself went unasserted until now. The consequence IS asserted, in the demote
scenario: the archive is gone, the file is empty again, and Penpot was never
contacted. What was missing is that the app asks at all.

It came from `sync-mode.feature`, which specified it and could not run it either.
The prompt is unit-tested where the answer can be scripted (SetModeTest); this is
the end-to-end statement of the same promise, parked behind a named wall rather
than behind a file nobody was going to run.
### WHOSE DECISION IS THIS, AND WAS IT EVER ASKED FOR?

STATED PLAINLY BECAUSE IT DIVERGED FROM THE DESIGN WITHOUT A DECISION: mode is
PER FILE and MUTABLE. `occ penpot_sync:set-mode` takes a PATH, not a mapping,
and a file can be flipped `sync` ⇄ `link` any number of times. The mapping's
mode is only the default a NEW mirror inherits.

The expectation was the opposite — mode set on the team-folder mapping and
IMMUTABLE there, the way the folder name and the Team Folder flag are. Nobody
asked for per-file switching;
it arrived because the move guard needed an escape hatch to offer. A `link` is
confined to its project (§6.43), so "promote it to sync first" is the only
advice a refusal can give that leads anywhere — and that advice needs a lever.

So it exists, it is load-bearing, and it is specified here rather than left as
an undocumented capability. Two consequences the scenarios below hold to:

  1. IT IS AN ADMIN ACTION. Changing a file's mode decides whether Nextcloud
     stores a real archive or a pointer — a storage-and-recovery decision about
     someone else's team folder. There is no per-user surface for it.
  2. DEMOTION DESTROYS A LOCAL BACKUP. `sync` → `link` deletes the archive
     Penpot is not keeping for you. It is the one direction that loses
     something, and it confirms before it does.

IF IMMUTABILITY IS WANTED INSTEAD, this is the file that changes: the lever
goes, the move guard loses the escape it offers, and every "promote to sync
first" refusal in move-design.feature needs a different answer. That is a design
decision, not a spec tidy-up.

---

## sync-mode — RETIRED

`features/sync-mode.feature` is **gone**. Its subject was real; the file was not.

The per-file "do we store the bytes?" choice belongs to **`set-mode.feature`**,
which owns the action a person performs and runs live against a real Penpot.
`sync-mode.feature` was written first, before the engine existed, and then never
shrank when `set-mode.feature` came along and proved half of it for real — so the
repo carried sixteen `@todo` scenarios restating five live ones.

FOURTEEN OF THE SIXTEEN WERE ALREADY COVERED, ELSEWHERE, VERBATIM:

| it said | already asserted by |
|---|---|
| A team of link files costs no exports at all | `set-mode.feature`, same sentence, **live** |
| Promoting a link file to sync fetches the archive | `set-mode.feature` "Promoting a mirrored design fetches a real ZIP", **live** |
| Promotion survives future pulls | `set-mode.feature` "A promoted file is not re-exported by the next pull", **live** |
| Confirming a demotion deletes the archive and keeps the pointer | `set-mode.feature` "Demoting throws the archive away and never contacts Penpot", **live** |
| A sync file holds the real archive | the promote scenario's own `Then`, **live** |
| A link file is a pointer with no stored content | `view-design.feature` + the demote scenario, **live** |
| A link file holds nothing at all | same |
| A link is never a small placeholder archive | a restatement of "holds no content at all" with no action of its own |
| A link file is confined to its own project | `move-design.feature` — and the scenario said so itself: *"Detail lives in move-design.feature and ignore.feature; this is the summary"* |
| Promotion lifts every link restriction at once | `move-design.feature` "Promoting a link first makes the move work normally" |
| Personal projects support the same link and sync modes | no action, no actor — a claim that a thing behaves normally |
| Files inherit their mapping's default mode | see below; the operation it describes does not exist |
| A leftover body from an older version is truncated by the next pull | `When the pull runs` — the reconciler as the subject |
| An already-empty link is left strictly alone | an mtime and an etag, about the reconciler |

**A summary of other files is not a scenario.** Two of them said outright that
the real coverage lived elsewhere. A reader who wants to know what a link cannot
do is better served by the file where each refusal is actually exercised, and a
summary that drifts from those files is worse than nothing because it will be
believed.

**"Files inherit their mapping's default mode" described an operation that does
not exist.** Its second half is *"the admin changes the mapping's default mode to
sync"* — but a mapping's mode is fixed at creation (`admin-mapping.feature`:
everything except the groups is immutable, deliberately, because changing it
would force a live migration of already-mirrored content). Here that is sharper
than elsewhere: flipping a mapping's mode in bulk would either delete every
downloaded archive under it or export every file at once. There is no such
control, so there was nothing to test.

It was also a **two-`When` scenario**, which is the tell: a second `When` is a
scenario trying to rebuild a pre-state by performing another behaviour, instead
of stating how the world is in a `Given`.

**"A leftover body from an older version" and "An already-empty link is left
strictly alone" both named the pull as the actor.** That is the same defect that
retired `reconcile.feature` in the Grafana sibling: nobody runs a reconciler, and
an mtime is a result rather than a behaviour. The end state each was reaching for
— a link holds nothing — is asserted where a link is made.

TWO SCENARIOS SURVIVED, and both moved to the file that owns their subject:

| it said | where it went | why it survived |
|---|---|---|
| Demoting a sync file to link warns before deleting the archive | `set-mode.feature` | the confirmation is really implemented (`--force` exists to skip it) and nothing asserted it — every live scenario passes `--force` |
| Demoting an ignored file is refused | `ignore.feature` | the mirror of "Ignoring a link file is refused"; both are about what ignore protects |

## connection/sync-now

`features/connection/sync-now.feature`

SYNC NOW — bringing what is already in Penpot into Nextcloud.

### A sync brings the team's projects and designs into Nextcloud

ONE BEHAVIOUR, FOUR WAYS TO START IT — admin/one mapping, admin/every mapping,
the schedule, and a user's own personal team. Same pre-state, same post-state, so
the actor and the scope are COLUMNS rather than four scenarios. Whether a run is
synchronous or queued is a mechanism and is asserted nowhere.

PROJECTS COME IN BY NAME AND WEAR THE TAG; designs come in beneath them. Every
path in the tree table is named exactly as Penpot names the thing it mirrors,
which is the whole of "project folder names match their projects". Drafts is the
team's default project and gets no folder of its own — it IS the mapped folder
(§6.35), so a loose design sits at the root.

NOTHING ASSERTS "THE SYNC SUCCEEDED". The tree is the proof, and the only one
every trigger can offer: a command's exit code says nothing about a job that ran
inside Nextcloud.

### A folder already named like a Penpot project is adopted, not duplicated

THE NAME IS ALL THERE IS TO MATCH ON the first time — a hand-made folder carries
no project id yet. Adopting it is what stops a first sync over an existing tree
leaving a second folder beside the one someone made. From then on the id
identifies the project, which is why a rename upstream moves this folder rather
than making another.

### THE RECONCILER IS NOT A FEATURE (saga §C6.28)

This file used to be `reconcile.feature`, and it had thirty-four scenarios. The
reconciler is what carries every "from Penpot" change into Nextcloud — it is the
mechanism BEHIND the behaviours, not one of them, and a file named after it
collects scenarios for no better reason than that they travel through the same
code. Three of the thirty-four were behaviours. Ten were rules with no actor and
no gesture. Thirteen restated a verb another file already owns — a rename is a
rename, and that it arrived via the reconciler is HOW, not WHAT.

Half the file could not be built, and that was the tell rather than a coincidence:
an unbuildable scenario is usually a scenario about the wrong thing.

### TWO ACTORS, AND THAT IS THE WHOLE FILE

    admin   syncs one mapping now, and waits for it
    admin   syncs everything now, which is a background job
    time    the schedule comes round and does it with nobody asking

Everything below is the OUTCOME of one of those. Mirroring a root, a project, a
file, its dates; leaving an unchanged instance alone; pruning what Penpot no
longer has — none of them is a separate behaviour, they are what a sync DOES.

### THE FIRST SYNC IS ITS OWN SITUATION

Whatever put these designs in Penpot happened before this app existed, so it is
out of scope by definition. That makes "existing designs arrive for the first
time" a real and independent thing to describe — and it needs one or two designs
to describe it, not a catalogue of every state a design can be in.

### A USER'S SYNC NOW IS THE SAME BEHAVIOUR

Scoped by what their token can see in Penpot and what they can see in Nextcloud,
but the end state is identical, and a scenario that differs only in scope is the
same scenario. The one genuine difference is that the personal team mapping is
AUTOMATIC, so a user's button is scoped to exactly one folder and needs no
mapping card at all.

### sync-now scope

A MAPPING GUARANTEES ONE FOLDER. Everything else — the project folders, their
tags, the designs — arrives on the first sync, so it is described here and not
in admin-mapping.feature.

AND THE FIRST SYNC IS ALL THIS FILE COVERS. A later run only has work to do
because something changed in Penpot, and every one of those is a scenario about
THE CHANGE rather than about syncing: a design deleted upstream is
delete-design.feature's, a project renamed upstream is rename-project.feature's.
Once those are theirs, there is no "second sync" behaviour left to describe.

Three scenarios about pruning used to sit here — a mirror pruned when its design
is gone, a `link` getting a last archive on the way out, a `sync` needing none.
All three are already asserted, step for step, by delete-design.feature's "A
design deleted in Penpot is snapshotted, then moved to the trash" and "A design
that already had its archive needs no second export". They were duplicates, and
the duplicate was in the wrong file: a prune is an effect of deleting in Penpot,
which is a Penpot-origin behaviour.

Three more said a second run changes nothing — no duplicate folder, nothing
pruned, no mtime or etag moved. Idempotence is real and it is how the reconciler
works, but "a sync that reacts to nothing" is not a behaviour a user can ask
for. The step definitions for the mtime/etag pair are deliberately KEPT in
PullSteps: their docblock records a live bug (a pull was moving mtime and etag on
every file on every run, which makes every sync client re-download the world),
and re-adding the scenario is one line if that guard is wanted back.

THE TRIGGER IS DATA. Four ways to start a sync —

    actor    | scope
    ---------+---------------------
    admin    | one mapping          the card's "Sync now"
    admin    | every mapping        the section's "Sync from Penpot"
    schedule | every mapping        time as the actor
    user     | their personal team  the personal "Sync now"

— with the same pre-state and the same post-state. They were four scenarios,
each asserting that post-state in its own words, and they had used four
different phrasings for it. As columns the sameness is the point of the table.
THE SCHEDULE IS A ROW, NOT A PROMISE. The obvious way to test it — set the
interval to a few seconds and sleep — does not work and would be the wrong test
anyway: ScheduleConfig clamps to 300s and the job clamps again to 60s, both
deliberately, because a job re-entering faster than a pull can finish is a bug.
A test that had to defeat two safety floors would be testing the floors.
`occ background-job:execute --force-execute` runs the real ScheduledPullJob now,
ignoring its interval, which is "the schedule came round" with the waiting taken
out. Enabling the schedule is part of the trigger rather than a fixture: a
schedule nobody turned on has no actor, and the job returns immediately by
design when it is off.

Only the personal sync is still out of reach, so that one row sits in a tagged
scenario of its own rather than being silently skipped inside a green outline.

NOTHING ASSERTS "THE SYNC SUCCEEDED" any more. The tree is the proof, and it is
the only proof every trigger can offer — a command's exit code says nothing
about a job that ran inside Nextcloud. That is what let the schedule join the
outline instead of needing a scenario with its own weaker assertions.

WHETHER A RUN IS QUEUED OR SYNCHRONOUS IS ASSERTED NOWHERE. A scenario used to
end `Then the sync is queued as a background job`, with a comment justifying why
"everything" cannot be synchronous. That is a mechanism and a design note, not a
behaviour — the admin's question is whether their designs arrived and whether
they were told.

A TREE IS ONE FACT, so the post-state is one table:

    And the mapped folder holds:
      | path                       | tagged |
      | One Mapping/Cogs           | penpot |
      | One Mapping/Cogs/Gizmo.penpot | -   |

It replaced a column of one-node-per-line assertions that never showed the
SHAPE the sync was meant to build. The tag is a column rather than a scenario
because it is a property of a node, the same as the node existing at all — and
that also ends the tag being asserted in three files at once.

PROJECT NAMES ARE GLOBAL TO THE SUITE, and this section learned it the hard
way. Every core-suite scenario seeds into the SAME Penpot team, nothing tears a
project down afterwards, and several assertions locate a project BY NAME — so a
reused name silently points a later scenario at an earlier one's project. The
first draft called a project "Widgets", which an existing scenario further down
also creates; that scenario then read the wrong project's `created-at` and
failed on a date it had asserted correctly for months. Two projects already
shared the name "Widgets" before this section existed, which is why the second
of them is now "Reconciled".

Pick a name nothing else uses. `grep -rn '"<name>"' features/` is the check.

THE PRE-STATE IS WHAT PENPOT HOLDS, as one table:

    And the Penpot team already contains:
      | project | design    |
      | Widgets | Gizmo     |
      | Widgets | Doohickey |
      | Gadgets | Sprocket  |

A first sync is only interesting when the team already has something in it, and
"something" is a SHAPE — projects, each with designs. Repeating "a project named
X exists" and "a file named Y exists in the project X" describes how it was
built instead, which is not what a `Given` is for. The step find-or-creates each
project, so a name may repeat down the column to give one project several
designs.

WHICH IS ALSO HOW DRAFTS GETS WRITTEN IN THE SAME TABLE. `Drafts` resolves to
the team's real default project rather than making a second project that happens
to share its name — so "a loose design in Drafts" needs no special sentence. It
mirrors to the mapped folder's ROOT, because the default project has no folder
of its own (§6.35), and the scenario asserts both halves: the design is at the
root and there is no `Drafts` folder beside it.

FOUND BY NAME, KEPT BY ID. This is the rule the section exists to pin. A project
folder is named exactly as Penpot names the project; from then on the stamped
`penpot_project_id` is what identifies it, which is why a rename upstream MOVES
the folder rather than making a second one. The first time round there is no id
to match on — a folder somebody made by hand carries nothing — so the name is
all there is, and `PullService::ensureProjectFolder()` adopts a same-named
folder rather than creating `Widgets (2)` beside it. That adoption is a real
behaviour with a real scenario now; it used to be a comment in the code.

AND THE TAG IS PART OF THE MIRROR, not decoration applied later. Every project
folder wears `penpot` — the same tag a user applies by hand to opt a folder in
(create-project.feature), so one badge means "this is a project" whichever
direction it came from. `tagProject()` runs on both the adopt and the create
path, which is why the adoption scenario asserts the tag too: adopting a folder
that never got badged would leave it invisible to the same searches.

### A sync that cannot finish says so, and says why

@unbuilt, AND THE GAP IS REAL — found while writing it. `occ penpot_sync:sync`
catches exactly one exception, `OutOfBoundsException`, which is an unknown
mapping id. A `PenpotApiException` — an unreachable Penpot, a rejected token —
escapes uncaught, so the honest answer to "what happens when a sync cannot reach
Penpot?" is currently "a stack trace".

It is one scenario rather than a CLI one and a UI one because both front doors
need the same answer, which is the same rule MappingService follows for
validation: the rules live in the service so the `occ` twin cannot drift from
the panel.

The two rows are the two ways the connection can be wrong, and they are the two
`admin-connection.feature` already distinguishes for the connection TEST —
missing token versus unreachable host. A sync should not collapse them, for the
same reason the test does not: they send an operator to different fixes.

### A user syncs their own personal team folder

SAME BEHAVIOUR, DIFFERENT SCOPE (§C6.28). No mapping card exists for this —
the personal team mapping is automatic, so there is exactly one folder to
sync and nothing to choose.

### Users do not author their own team mappings

DELIBERATELY NOT BUILT, not merely unwritten. Letting users author mappings
breeds edge cases faster than anything else on the table: two users mapping
one team, a user mapping a team the service account cannot see, folders
orphaned when the admin removes the mapping underneath them. The personal
team mapping stays automatic and singular.

---

## team-import

`features/team-import.feature`

SPECULATIVE — this file documents a PROPOSAL (saga §6.15), not a locked
design, and it directly touches an open architectural fork. Do not read any
scenario below as decided behaviour; every one of them is written to make the
open questions visible, the same "design, not wired" convention both sibling
apps use for their own undecided slices (compare Grafana's tag-sync.feature
header, or n8n's speculative-fork write-ups).

THE PROPOSAL: from wherever a user's Penpot token is configured
(personal-settings.feature), query that account's visible Penpot teams, show
which already correspond to an existing Nextcloud Team Folder and which
don't, and let the user opt to "import" one — provisioning a Team Folder that
becomes a team mapping (admin-mapping.feature). This reuses §6.13's
ownership-pill/tag mechanism for a SECOND purpose: pulling FROM Penpot, a
project becomes/matches a same-named subfolder by ordinary name-matching
(fine, because §6.13 already locked mapped-folder naming as
Penpot-authoritative on the pull direction — see admin-mapping.feature). Going
the OTHER way — a user makes a plain folder inside a mapped Team Folder and
wants it to BECOME a new Penpot project — name-matching alone can't
disambiguate "this is meant to be a project" from "this is just reference
material sitting in the folder" (§6.13's tolerated-content rule), so the
proposal is a dedicated app-owned tag as the creation signal: tag present ⇒
create the project in Penpot (via `create-project`, confirmed real in §6.5) on
the next pull cycle; tag absent ⇒ ordinary tolerated content, untouched.

WHY THIS IS STILL OPEN, NOT LOCKED (do not resolve these here):

  1. THIS REOPENS §6.1, NOT JUST EXTENDS IT (saga §6.7/§6.15). §6.1 locked
     Nextcloud as read-only — no writeback, no Nextcloud-originated content.
     "New Penpot project from a tagged Nextcloud folder" is Nextcloud
     ORIGINATING a Penpot object. That's not disqualifying, but it means this
     proposal is really asking for a narrower carve-out than blanket
     read-only: existing files/projects stay strictly read-only; CREATION
     would be a distinct, separately-decided path. Nothing in this app should
     treat that carve-out as granted until a saga chapter says so explicitly.

  2. TEAM FOLDER CREATION PERMISSIONS (saga §6.15, the one genuinely NEW open
     point raised by this section specifically): Team Folders are
     admin-configured by default; a non-admin, non-delegated user checking an
     "import as Team Folder" box has nothing behind that checkbox to act on,
     on this cluster today. Whether the UI greys the box out, routes to an
     admin-approval step, or something else is explicitly undecided.

  3. `import-binfile` IS NOW CONFIRMED WORKING (saga §6.20 — open question #6
     closed). Both the create-new and in-place variants were exercised live.
     So this fork is no longer blocked on "does the mechanism exist"; it's
     blocked purely on the §6.1 policy question in point 1. Three practical
     facts came out of that testing and apply here: the call is SSE, its params
     are kebab-case (`project-id`, not `projectId`), and its `name` parameter
     is IGNORED — an imported file takes the name from its archive manifest, so
     any create path needs a follow-up `rename-file`.

  4. THE SERVICE ACCOUNT MUST ALREADY BE ON THE TEAM (saga §6.18, new since
     this file was written): a team can't be mapped at all unless the service
     account holds a `viewer` invite. That changes this feature's framing —
     "import a team I can see" is really "import a team BOTH I and the service
     account can see." A user's personal token showing them a team is not
     sufficient for it to be importable, and the UI must say which of the two
     is missing rather than just failing.

CI SKIPS THIS ENTIRE FILE. Nothing here should be implemented against until a
future saga chapter either ratifies §6.7/§6.15's creation carve-out or
explicitly rejects it in favour of the plainer "map only what already exists
in Penpot" shape (admin-mapping.feature).

### team-import: Background

NOTE: only the IMPORT-AN-EXISTING-TEAM half of this feature is proposed as
buildable-once-ratified; the tag-triggers-project-CREATION half is
additionally gated on the still-open §6.1 read-only-scope question above.

── the "already imported, shows up automatically" half — confirmed workable ──
Confirmed against the groupfolders README + live behaviour (saga §6.15): a
Team Folder "shows up in the home folder for each user in the configured
groups" automatically once granted — there's no separate pending state to
build. So detecting "is this already imported" is a read-only match, not a
grant action.

### Importing an unmapped team as a Team Folder requires Team Folder rights

Which of "refused with an explanation" vs "routed to an admin step" is
correct is explicitly undecided (saga §6.15) — this scenario only asserts
that the checkbox is NOT allowed to silently no-op or silently succeed
for a user who lacks the underlying permission.

### A team the service account cannot see is shown as not importable

── the OTHER gate, new since saga §6.18: service-account visibility ────────
A user seeing a team through their personal token is NOT sufficient. The
service account does all mirroring, so it must be able to see the team too,
or the resulting mapping would pull nothing forever.

Two distinct gates now exist — Team Folder rights and service-account
visibility. Failing to say WHICH one blocked the import turns a fixable
setup step into a mystery.

── creation-via-tag: DECIDED AND SHIPPED (§C6.18) ──────────────────────────
This section used to be headed "the speculative, explicitly-not-decided
creation-via-tag mechanism", with a tag "name TBD", an "open fork against
§6.1", and a scenario that deliberately asserted nothing. All three are
settled: the tag is `penpot`, the fork closed the same way §6.33 closed for
files (creating a CONTAINER is not pushing CONTENT), and the behaviour is
live in create-project.feature.

It happens on the TAG EVENT, not on the next pull — the pull never
originates anything in Penpot, and making it the actor would have meant a
user's gesture taking up to five minutes to have an effect.

What is left for this file is the import surface's view of it: an admin
looking at a team they have not mapped should be told what tagging would do,
not left to discover it.

### The import surface explains that tagging a folder creates a project

The behaviour itself is asserted in create-project.feature, where it is
live. Duplicating the assertion here would be the two-files-one-behaviour
mistake features/README.md exists to prevent.

---

## uninstall — RETIRED, folded into `lifecycle.feature`

`features/uninstall.feature` is **gone**. Enabling, disabling and removing an app
are three points on one lifecycle, and they were split across two files because
the removal grew an essay rather than because a reader needed two places to look.

THREE SCENARIOS IN, ONE OUT:

| it said | verdict |
|---|---|
| Removing the app reverts the custom mimetype registration | **kept**, as `lifecycle.feature`'s "Removing the app" — real work of ours, and the exact mirror of what "Enabling the app" now asserts |
| Disabling the app leaves the mirrored design files in place | **deleted** — the app does nothing on disable. There is no code to write and none to break; it asserted Nextcloud's behaviour, not this app's |
| Re-enabling and pulling reconciles the existing files without duplicates | **deleted** — `sync-now.feature` "A folder already named like a Penpot project is adopted, not duplicated" already asserts id-matched reconciliation. Disabling and re-enabling changes nothing about how a pull matches |

**The data-orphan promise is still true and still worth knowing** — it is just
not a scenario. The app never deletes a `.penpot` file, never clears its
Files-Metadata, never touches a Team Folder and never contacts Penpot on removal.
Every `sync` file is a real archive, so deleting one would be genuine data loss;
a `link` holds no bytes but its `penpot_id` is what makes a later reconnect free.
To wipe the Nextcloud side deliberately, an admin uses Purge (`purge.feature`).
That is a promise kept by writing no code, which is exactly why it reads as a
paragraph rather than as a `When`.

### Removing the app

`@blocked` — **no app removal**. The harness enables and disables, which is what
`occ` offers; removing an app and reinstalling it is a store operation this suite
cannot perform. That is a different wall from `@todo`, and naming it is the rule
(see `README.md`).

WHAT IT ASSERTS IS OUR WORK, not the framework's. `UnregisterMimetype` is wired
to the `<uninstall>` repair step in `appinfo/info.xml`, and it reverts what the
install wrote into the Nextcloud core tree — `config/mimetype*.json`,
`core/img/filetypes/Penpot.svg`, `core/js/mimetypelist.js` — and re-stamps the
`.penpot` filecache rows back to a generic archive mimetype. Penpot's own server
serves an export as `application/zip` (§6.4), so there is no Penpot-branded type
to fall back to: this app owns the registration end to end, same as both siblings.

The second `Then` — the files are left where they are — is the data-orphan
promise stated once, at the only moment anyone would doubt it.

ONE THING SIMPLER THAN BOTH SIBLINGS: reconnection here is PULL-ONLY. n8n and
Grafana's reinstall story has to worry about a stray push racing the first pull
after re-enable; this app never writes back (§6.1), so "reinstall reconciles in
place" is strictly a read-side guarantee with no writeback half to reason about.

## team-mapping/manage-groups

`features/team-mapping/manage-groups.feature`

THE ONE FIELD A MAPPING LETS YOU EDIT. Everything else — the team, the folder,
the storage backend, the default mode — is fixed at creation, because changing it
would force a live migration of already-mirrored content. Split out of
`admin-mapping.feature` so the editable field is not buried among the immutable
ones.

The groups are the FOLDER'S, not the mapping's: the app applies them when it
provisions, then reads back whatever the folder says. Re-share it from Files or
with `occ` and this app reports the change; a sync never puts back a group you
removed. Both storage backends get their own Examples block because the
provisioning differs and the behaviour must not.

## team-mapping/view

`features/team-mapping/view.feature`

Looking at what is mapped. Small today, and the interesting case is the one that
is here: a team renamed in Penpot must not rename the folder an admin chose. The
mapping is keyed on the team id, so it keeps resolving; the folder name was never
Penpot's to set.

## team-mapping/sync-now

`features/team-mapping/sync-now.feature`

THE CARD'S OWN BUTTON — one mapping, on demand.

### Syncing one mapping brings its projects and designs into Nextcloud

SPLIT OUT OF THE INSTANCE-WIDE OUTLINE, which used to carry it as a third
Examples row beside "every mapping" and "the schedule". Same end state, and the
row was honest — but the scope IS the difference, and a mapping-scoped action
belongs with the mapping. `connection/sync-now.feature` keeps the two that walk
everything.

The folder differs from the instance-wide scenarios' on purpose: they clear the
mapping store between runs, so distinct folders stop one file's leftovers reading
as another's result.
