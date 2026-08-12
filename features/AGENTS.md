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

A URL row works differently from a token row, and that IS the behaviour. A URL
the app cannot build requests from is refused at *set* time, so nothing is
stored — and the health check then fails on a MISSING URL rather than on a
malformed one. Same visible outcome for the admin: the message names the url.

That is also why the Background starts from `nothing is configured yet`. An unset
field has to mean unset rather than "whatever the row above stored", and the bad
URL row is the case that forces it: it writes nothing at all.

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

**`@unbuilt`, not `@todo`, and the gap is real: there is no personal health check
at all.** `PersonalTokenService` stores, reads and clears a token; nothing tests
one. No `occ` command, no route. So a user can paste a token that Penpot would
reject and find out only when a rename is silently attributed to the service
account — which is precisely the case `connection/admin.feature` refuses to allow
for the admin. Build the check and both scenarios go live.

**"A user's token is theirs alone" was cut.** Per-user storage is how
`IAppConfig`'s user scope works; the scenario asserted that a framework does what
it does, and asserted it as an absence — dana's token not appearing for alex.
Nothing acts on alex's token, so there is nothing to observe.

### A user enters a valid token

THE MAPPING IS THE TOKEN'S SHADOW. Setting a token is the whole of "connect me" —
there is no folder to name, no team to pick and no mapping to configure, because
there is exactly one personal team and exactly one place it can go. A visible
mapping would be a choice with one possible answer.

That end state is why `personal-projects.feature` is retired: once the home root
carries the team, every other personal behaviour is the ordinary one.

### A user clears their token

THE MAPPING CANNOT OUTLIVE THE TOKEN, and nothing is deleted when it goes. The
folders and their archives stay exactly where they are — losing a credential is
never evidence that content is gone, the same rule the service account follows.

The third `And` is what makes it a real end state rather than a tidy-up: a new
`.penpot` file made at the home root is inert again, exactly as it was before the
token existed. The mapping is gone, not merely idle.

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

MODE IS THE MAPPING'S, AND ONLY THE MAPPING'S (saga §6.22, amended): a mapping
carries the mode its files get ("link" unless set otherwise), and it is immutable
once created. A file's mode follows entirely from the mapping it was mirrored
under; changing it means removing the mapping and mapping the team again. There
was once a per-file override — see "team-mapping/set-mode — RETIRED".

WHAT'S DELIBERATELY NOT HERE: creating a NEW Penpot team or project FROM
Nextcloud is a separate, still-open fork — see `## team-import — RETIRED`.

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

### Without a service-account token, nothing can be mapped

FROM THE RETIRED `errors.feature`, and it was the last copy. The scenario had
lived in `admin-connection.feature` too, and was dropped when that file was split
into `connection/admin` and `connection/personal` — leaving `errors.feature`
quietly holding the only statement of it.

Refusing a mapping belongs with mapping. The service account is what reads, so
without one there is nothing a mapping could do; refusing at creation says so at
the moment the admin can act on it, rather than at the first sync.

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

FOUR DOORS, AND THEY ALL HOLD THE SAME LINE NOW: the section's button, the
scheduled job, the card's button and `occ penpot_sync:sync`. Two pulls over one
folder tree race on the same files, and the scope of each does not make it safe —
a card sync and an instance-wide one collide exactly as two instance-wide ones do.

THE CLI GETS AN ESCAPE HATCH THE BUTTONS DO NOT NEED. `isBusy()` reads a STORED
flag, so a run killed outright — SIGKILL, an evicted pod — leaves it at `running`
forever. A button can wait for the admin to try again later; the CLI is the
headless door someone reaches for when the UI is the thing misbehaving, so
refusing it without a way through would wedge the one tool that could unwedge
things. `--force` runs anyway.

`@blocked` on the scenario itself — no fault injection, and no way to hold a run
open while a second is issued. The CLI half is the one that could be driven (set
the status, run the command, assert the refusal); it is not written yet.

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
when they set a personal token (`connection/personal.feature`), and none of
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
                                  (`connection/personal.feature` — needs a
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

### A newly created design is born in its mapping's mode

THIS ONE WAS WRONG IN THE CODE, and removing `set-mode` is what exposed it.
`CreationService` stamped `MODE_LINK` unconditionally, with the comment *"born a
`link` … a promotion is one command away"*. Once the promotion command was gone,
a design created under a **sync** mapping would have been a pointer nothing could
ever turn into an archive, sitting in a folder whose every other design holds one.

It is an outline over both modes because the mode is now the only variable —
there is no per-file override left for a second scenario to describe.

NO ARCHIVE IS STORED **YET**, in either mode, and the "yet" is doing real work.
The design is created empty, so there is nothing worth exporting at that instant;
no revision is stamped either, which is what makes the next pull's drift check run
and fill a `sync` file's body in on the same self-healing path it uses for an
archive that went missing.

── creating in a personal team ─────────────────────────────────────────────
Same behaviour, different destination: the user's own Drafts rather than the
team's. `connection/personal.feature` owns why that destination differs.

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
home — `connection/personal.feature` owns the token that puts it there.

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
see their personal team at all (`connection/personal.feature`).

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

## errors — RETIRED

`features/errors.feature` is **gone**. "Failures never cost the user data" is an
INVARIANT, not a behaviour: nobody performs an error. An error is what happens
when something a person *did* goes wrong, so each one belongs with the behaviour
that can fail — the same reasoning that retired `file-type` (a construct),
`reconcile` (a mechanism) and `admin-section` (a panel).

The `When` lines gave it away. Almost none had a human actor:

    When an export stream closes with no "end" event      the transport
    When the app exports any file                         the app itself
    When "get-project-files" fails for that project       an RPC command, by name
    When the pull is interrupted partway through          the reconciler again

Twenty-one scenarios in, eight out.

### Where each one went

| scenario | disposition |
|---|---|
| An error inside a 200 response is treated as a failure | → `team-mapping/set-mode.feature`, row 1 of one outline |
| A stream that ends without an end event | → same outline, row 2 |
| A failed asset download never truncates the existing mirror | → same outline, row 3 |
| An unauthenticated asset fetch is a credential failure | → same outline, row 4 |
| A pull interrupted halfway leaves every written file valid | → `connection/sync-now.feature` |
| A file that fails to export does not stop the rest of the pull | → `connection/sync-now.feature`, one outline with the row below |
| Losing access to a team halts only that mapping | → same outline: one failure at mapping scale rather than file scale |
| A failed project listing prunes nothing | → `designs/delete.feature`, row of one outline |
| A failed team listing prunes nothing anywhere under it | → same outline |
| An expired service token prunes nothing | → same outline |
| The pull does not trust "get-projects" alone | → `projects/delete.feature` — the behaviour is a project deleted in Penpot |
| A restore whose follow-up rename fails | → `designs/restore.feature` |
| A missing service token blocks mapping | → `team-mapping/create.feature` |

### And what was dropped, with the reason

| scenario | why |
|---|---|
| Penpot error codes are decoded from Transit, not string-matched | "not string-matched" describes how the parser works. `tests/unit/TransitTest.php` |
| The known-bad export flag combination is never sent | asserts a REQUEST PAYLOAD, which Behat cannot see. `tests/unit/PenpotClientTest.php` |
| The inner signed storage URL is never persisted | an internal storage decision with no observable outcome at all |
| A transient download failure is retried before giving up | backoff is a mechanism, and its end state is identical to the outline's |
| A pruned file goes to the trash, never straight to deletion | duplicate — `designs/delete.feature` asserts it LIVE |
| A design deleted in Penpot can still be rescued inside the grace window | duplicate — the snapshot and the window closing are both already there |
| A failed rename leaves the local rename standing | duplicate — `designs/rename.feature` "A failed propagation never reverts the user's local rename" |
| An invalid personal token falls back rather than blocking | belongs with the WRITE GESTURE, which is where its twin went when `connection.feature` was rewritten |

### THREE THINGS THIS FILE HID

**Its Background was fiction.** All three steps — `the app is connected to
Penpot`, `a Team Folder mapped to the Penpot team …`, `the Penpot project … is
mirrored as a folder inside it` — had never been written. The identical trio that
had rotted in `remove-mapping.feature`, invisible for the same reason: every
scenario in the file was tagged.

**A missing token blocking a mapping existed ONLY here.** "Without a
service-account token, nothing can be mapped" was dropped when `connection.feature`
was split into `admin`/`personal`, and this file was quietly the last copy. It is
now `team-mapping/create.feature`'s, where refusing a mapping belongs.

**Four `@blocked` named no capability**, which is the one thing the tag exists to
do.

### A promotion that fails leaves the file as it was

FOUR SCENARIOS, ONE RULE. Each described a different way the export can break on
the wire and then asserted the same end state: the file is untouched. That is an
`Examples` table, not four scenarios — the `reason` column carries the only thing
that genuinely differs, which is what the admin is told.

`@blocked` — **no fault injection.** Every row needs a real Penpot to fail in a
specific way, and the harness can only ask it to succeed.

FILED UNDER PROMOTION because promotion was what triggered the first export.
⚠️ RETIRED WITH ITS FILE: promotion no longer exists, so the first and riskiest
export is now the first pull under a `sync` mapping. The four export-failure rows
below went with `set-mode.feature` and are asserted nowhere — they were `@blocked`
on fault injection the harness cannot do, and they remain a real gap rather than
a solved one.

### An incomplete listing prunes nothing

THE MOST IMPORTANT RULE IN THE APP, and it was four scenarios saying it four
ways. Not knowing what Penpot holds is not evidence that anything was deleted —
an expired token, a failed team listing and a failed project listing all mean the
same thing, and all must mean "prune nothing".

These are NOT the empty negatives this suite rejects elsewhere. Something did
act: a sync ran, and a dangerous branch was available to it. The claim is that
the branch did not fire, which is an outcome.

`@todo` rather than `@blocked` because one row IS drivable today — a rejected
token needs no fault injection, only a bad token — and it happens to be the row
that matters most.

### One failure never costs the rest of the sync

TWO SCALES, ONE RULE: one design failing must not cost the other designs, and one
team failing must not cost the other teams. They were two scenarios that shared
every line but the noun.

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

The mode axis — whether that body is an archive or nothing at all — belongs to
the MAPPING, not to this file and not to any per-file action.

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

## mapping-membership — RETIRED

`features/mapping-membership.feature` is **gone**. The nearest-ancestor rule is
this app's most load-bearing decision and it is still true; it was never a
behaviour. A rule is only ever OBSERVED through a gesture — you move something
and it still belongs, you create something and it lands in Drafts — so every
honest scenario in the file was already a move or a create.

Which is exactly why six of them had been rewritten elsewhere, word for word,
without anyone noticing.

### THE RULE, which now lives here instead of in a file

A file's project is **the nearest ancestor folder carrying a Penpot project id**,
found by walking up; its team is the nearest ancestor carrying a team id, however
far up that is. Nothing is cached on the file — a copy would go stale on the
first move, which is the whole point (§C6.7). Penpot is flat; Nextcloud need not
be (§6.29).

Two consequences worth stating once: a folder Penpot has no concept of is simply
walked past, and a file under a team but under no project is in that team's
Drafts — which is a state, not a folder (§6.35).

### Six duplicates of scenarios that were already live

| it said | already asserted by |
|---|---|
| A file nested deeper inside a project folder still belongs to that project | `designs/move.feature` — same gesture, same `wip` subfolder, same assertion |
| Project folders can be grouped under ordinary Nextcloud folders | `projects/move.feature` — which even asserts *"the folder still resolves to the same team, found further up"* |
| A file with no project-id ancestor belongs to no mapping | `designs/create.feature` "A `.penpot` file created outside every mapping is an inert file" |
| A file at the mapped folder's root is in that team's Drafts | `designs/create.feature` |
| No folder is ever created to represent Drafts | `connection/sync-now.feature` — `there is no node at "<folder>/Drafts"`, in the tree table |
| A folder opted in by tag resolves exactly like a mirrored one | `projects/create.feature` "A folder opted in late brings the designs already inside it" |

### Four with no `When` at all

`A file's project is the nearest ancestor folder carrying a project id` was the
file's own thesis restated as a test — and its third `Then` is already
`designs/view.feature`'s. `A project folder's team is the nearest ancestor
carrying a team id` and `A personal project folder has no team ancestor` are the
same shape.

`Two folders carrying the same project id is a reported conflict` had a second
problem: **nothing can produce that state.** `projects/copy.feature` refuses a
project-folder copy precisely to prevent it, so the scenario specified recovery
from a situation the app is built to make unreachable — there is no `Given` a
test could arrange. It also carried the file's only `But`, which is a real
Gherkin keyword and a pure synonym for `And`: keywords are ignored in step
matching, so it reads as contrast and asserts nothing. Contrast is what you write
when you are describing a situation rather than an outcome.

### Three survived, two of them as Examples rows

| it said | where it went |
|---|---|
| The nearest project id wins when project folders are nested | a row on `projects/move.feature`'s "moved anywhere inside its team folder" — the destination is the only thing that differs |
| A file in any plain folder under a team is also in Drafts | a row on `designs/create.feature`'s Drafts scenario — the same rule at a different depth |
| Non-Penpot content inside a project folder is left alone | `connection/sync-now.feature`, as "A sync leaves content it does not manage alone" |

### A project folder can be moved anywhere inside its team folder

ONE RULE, TWO DESTINATIONS. A project folder may be filed under a plain folder —
which Penpot has no concept of — or under another project folder, where the
nearest id wins and the outer project does not swallow the inner one. Same
gesture, same end state, so it is an `Examples` column rather than two scenarios.

### A design created under the team but not under a project is a draft

ONE RULE, AND DEPTH IS NOT PART OF IT. The team root and a plain folder three
levels down are the same case: under a team, under no project, therefore Drafts.
The second row came from `mapping-membership.feature`, where it read as a
separate fact about nesting.

### A sync leaves content it does not manage alone

FROM THE RETIRED `mapping-membership.feature`. A `notes.txt` sitting in a project
folder is not the app's business, and a sync must not touch it — pruning keys on
metadata, never on a file extension or on where a file happens to sit.

Filed with the sync rather than with membership because the actor is a sync: the
question is what a run does to things it did not create.

IT ARRIVED BROKEN, in the way this suite documents and I still walked into. It
came from `mapping-membership.feature`, whose Background maps a shared `Penpot`
folder — and `sync-now.feature` deliberately maps NOTHING, so every scenario here
names its own folder and cannot inherit another's leftovers. Pasted across
unchanged, the `PUT` for `notes.txt` 404'd on a parent that did not exist. It now
maps `Untouched` itself, like everything else in this file.

`.github/instructions/gherkin.instructions.md` names this trap and names these
two files as the pair it happens between. Check the destination's Background
before moving a scenario into it.

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

Mapped in `sync` mode because a `link` is confined to its project (§6.43) and the
guard refuses this drag before it happens — that refusal is its own scenario
below, and needs a different assertion. The scenario re-states the mapping rather
than promoting one file, which is the only way to get a real archive now.

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
DIFFERENT Penpot teams (`connection/personal.feature` — setting a personal token
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
confined. The refusal used to end "promote to sync first"; it no longer does,
because there is no per-file promotion and re-mapping a whole team is not advice
to give someone mid-drag. It names the rule and stops, as both siblings' do.

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
`## personal-projects — RETIRED` for what actually is special about them.

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

## personal-projects — RETIRED

`features/personal-projects.feature` is **gone**. Personal projects are not a
feature: they are **the ordinary rules with a different mapping**. A design in a
personal project is created, viewed, moved, renamed, deleted and restored by
exactly the scenarios in `designs/`; a personal project folder behaves exactly as
`projects/` says. The file existed because "personal" felt like a category — the
same error that produced `errors`, `mapping-membership` and `file-type`.

Only two things are genuinely different, and both are end states of setting a
token, so both went to `connection/personal.feature`:

| it said | where it went |
|---|---|
| Setting a personal token maps the personal team to the home root | a `Then` on "A user enters a valid token" |
| Clearing the token removes the implicit mapping | a new scenario, "A user clears their token" |

### The rest, and why none of it survived

| it said | why |
|---|---|
| One user's personal projects never appear in another user's home | a negative on the impossible. Nextcloud homes and per-user tokens make it so; nothing acts on the other user |
| Clearing a personal token stops personal pulls without deleting anything | the "nothing deleted" half is now one `And` on the clear scenario, where it is a post-state rather than a scenario of its own |
| The personal team itself gets no folder | see the correction below — it is the same fact as the mapping, stated backwards |
| A user's personal projects mount at their home root | the first sync of the personal mapping, which `connection/sync-now.feature`'s "A user syncs their own personal team" already owns |
| Personal projects are pulled with the user's own token, never the service account | implied by the above: the service account cannot see a personal team, so the projects appearing at all IS the proof |
| Without a personal token, no personal projects appear at all | the inverse of the mapping end state, and asserted by the clear scenario's "inert, as it was before the token" |
| A personal project folder resolves without a team ancestor | **not true** — see below |

### TWO CORRECTIONS THIS FILE WAS CARRYING

**"The personal team itself gets no folder" was only half true.** The mapping's
folder is the user's home root. `/` is a folder — it is simply the one every user
sees as theirs — so the honest statement is that the personal team maps to `/`,
with the team's name and the folder's name never needing to agree because nobody
names either. "No folder is created" and "mapped to the home root" are the same
fact, and only the second one is useful.

**"Resolves without a team ancestor" claimed an exception that does not exist.**
The scenario called itself *"the explicit exception to saga §6.29's team
lookup"* — but the team ancestor of a personal project IS the personal team, sat
on the home root. `MembershipResolver` walks ancestors looking for markers and
has no special case for any of this, because it needs none: put the team id on
the home root and the ordinary rule resolves it. A spec that invents an exception
the code does not have is worse than a silent one, because the next person builds
the exception.

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

### A duplicate made in Penpot is not a copy we can see

Penpot's duplicate leaves no trace this app can read. From Nextcloud's side a new
design simply exists in a project — identical in every observable way to one that
was created from scratch — so there is nothing a copy scenario could assert that
`connection/sync-now.feature` does not already prove when it mirrors a design the
app has never seen.

The scenario is gone rather than converted. A spec that cannot distinguish its
subject from something already covered is not testing the subject.

### A duplicate made in Penpot is two designs, not one

**The gesture is invisible to us.** Penpot's duplicate leaves no trace this app can
read: a new design simply exists in a project, indistinguishable from one created
from scratch. Written as "a new file appears with its own id", the scenario tests
the create path a second time and earns nothing.

It earns its keep on a different claim. A duplicate arrives with NEAR-IDENTICAL
CONTENT AND A DERIVED NAME — `Original` and `Original (copy)`, the same bytes — and
those are exactly the conditions under which an app matching mirrors by name or by
content would fold the two into one file, or overwrite the original's mirror. A
create with a distinct name never exercises that.

So the end state is "two designs are still two files, with different ids", not "a
new file appeared". The subject is the id-based matching, which is the only thing
about a duplicate that is ours to get wrong.

### A design has to have somewhere to go

Penpot's `create-file` needs a project id, and there is no rootless design. So a
`.penpot` file created where no team can be resolved is refused outright, with a
message — not accepted and left inert.

This replaced two scenarios that circled the same thing without saying it. One
asserted the file "is an inert file" and "resolves to no Penpot mapping at all",
which describes a state the app should never have allowed. The other asserted that
the New-menu action "is not offered" — a negative about a menu, checking that
something was never built, which no assertion can fail on.

Not offering the action is still right; it is just not a behaviour with an end
state. What IS a behaviour is what happens when a file arrives there anyway, by
WebDAV or by a desktop client, and that is the scenario now.

### A created design is attributed to the acting user when possible

Authorship is a durable property of a design rather than a line in its history,
which is why this matters more at creation than for any other write. With a
personal token the design is the user's; without one it is the service account's.

TWO SCENARIOS, NOT TWO EXAMPLES ROWS — the same call as `designs/restore.feature`,
for the same reason: the end states are not the same shape. Without a token the app
also TELLS the user who the design will be authored by, and a row cannot carry a
post-condition the other row does not have. Squeezing them into Examples meant
dropping that sentence, which is the half a user would actually notice.

The old file had three scenarios on this theme; the third was the same rule stated
for a personal project folder.

### Creating: what this feature stopped claiming

- **"Filing a newly created draft is just a drag"** was a MOVE, in create.feature,
  with an action in its post-state (`the design is moved from Drafts into …`).
  `designs/move.feature` owns that gesture.
- **"Uploading a `.penpot` archive does not create an empty design"** is not a
  create at all. A file arriving with content already in it is a move-in or a
  restore, and whatever should happen to it belongs to those features.
- **"A failed creation leaves no orphaned local file"** asserted the absence of a
  file nothing writes.
- **"A created design appears exactly once after the next pull"** ran a pull to
  prove the local file already carries the real id — which is what the create
  scenario's own table says.
- **"A newly created design is born in its mapping's mode"** as an outline over
  sync and link: the mode is in the create outline's table, and the only part of
  `link` worth its own scenario is that it refuses the write.

### A restore is attributed to the acting user

Two scenarios rather than two Examples rows, because the end states are not the
same shape: with a personal token the restore is simply the user's, while without
one it is the service account's AND the user is told so. That extra sentence is a
post-condition the first case does not have.

A missing token never blocks the restore. Attribution is the personal token's only
job, and the app says whose name went on the change rather than leaving the user to
find out from Penpot's history.

### A design file arriving in a project becomes a design

**A mapping that ignores a design sitting inside it is not a mapping.** A `.penpot`
archive dragged into a mapped project is imported and becomes a real design, and the
file is stamped with the id it comes back with.

The old scenario claimed the opposite — the file "carries no Penpot id" and the
project "holds no design named Uploaded" — on the reasoning that creating a design
should be deliberate rather than a side effect of a drag. That reasoning does not
survive contact with what a mapping is for: the whole point of the folder is that
what is in it is in Penpot.

The one file that stays untracked is one PENPOT will not take. That is not a rule
the app invents; it is an error it catches from the import and reports, naming what
came back. Best-effort in, honest about failure.

This also settles the question `designs/create.feature` raised and pushed away: a
file arriving with content already in it is not a create, it is an import — and
this is where it belongs.

### Deleting a mirror moves the design into Penpot's trash

Both sides go soft together: the design lands in Penpot's own trash keeping its id,
revision and history, and the file lands in the Nextcloud trash keeping its
metadata. Nothing here is irreversible, which is what makes it safe to do without
asking — the irreversible half is the purge.

### A purge only destroys what is still in Penpot's trash

**Penpot's permanent delete does not check that a design is in the trash.** Hand it
a live design's id and the design is destroyed — proven live (saga §C6.11). The
trashed mirror still carries that id, so a purge that simply forwards it is one
rescue away from silently destroying live work.

So the purge reads Penpot's trash listing and destroys only ids it finds there. The
case that matters is a design someone rescued in Penpot while its mirror sat in the
Nextcloud trash: the local file goes, and the design is left alone.

That is stated as behaviour — the design survives — rather than as the mechanism the
old scenario asserted ("the app reads Penpot's trash listing first", "it passes only
ids found in that listing", "an id absent from that listing is never passed").

### RETIRED — the admin purge

`purge.feature` described an admin button that removed every `.penpot` file the app
had mirrored, across every mapping, on the promise that a later sync would bring
them back. Six scenarios, four of them about which files it spared and how to undo
it.

Removed for the reason it was removed from n8n and from grafana: it deleted a great
deal on a promise that only held for files that were faithful mirrors, and the ones
that were not are exactly the ones you would miss. It was never built here — every
scenario was @unbuilt or @blocked — so retiring it is a matter of deleting the spec.

Purge now means the same thing in all three apps: emptying the Nextcloud trash,
which finishes the delete the trash gesture started.

### A link is never deleted from Nextcloud

A link is a pointer at a design Penpot owns. Deleting the pointer removes nothing,
and the next sync writes it straight back — so the gesture is refused rather than
half-honoured. The same rule the sibling n8n and grafana apps settled on.

**This retires the "hidden link" model**, which was six scenarios: deleting a link
"hid" it, the pull had to recognise a dismissed link by the `penpot_id` on its
trashed file and leave it hidden, restoring un-hid it, and emptying the trash
un-hid it too — the last one recorded as an open question because it is incoherent
from the user's side. All of it existed to make a delete that should not happen
behave tolerably.

### Trashing: what this feature stopped claiming

`delete.feature` held 33 scenarios and was three features under one name.

**Purging belongs to `designs/purge.feature`** — "Emptying the Nextcloud trash
destroys the design", "A purge only ever passes ids that are in Penpot's trash
listing", "Purging a mirror whose design was restored in Penpot destroys nothing",
"Permanent deletion is a separate, explicit action".

**Restoring belongs to `designs/restore.feature`** — "Restoring from Penpot's trash
returns the design completely intact", "A restore is confirmed by re-reading",
"The app always offers Penpot's trash before an archive import", "Once the grace
window passes, only a best-effort import remains".

**Three pairs said the same thing twice**, once live and once as @todo: trashing a
mirror, deleting an untracked file, and emptying the trash.

**The internals went.** `"delete-file" is called`, `"permanently-delete-team-files"
is called` / `is never called`, `the app reads Penpot's trash listing first`, `it
passes only ids found in that listing`, `the pull exported 0 archives`. Which calls
the app makes is how it keeps the promise, not the promise.

**Both confirmation scenarios went**, each carrying two `When`s — "I choose Delete
in Penpot and confirm", "the app warns this cannot be undone / When I confirm".

**"Both modes delete identically"** contradicted the link rule above and is gone
with it. **"There is no app-managed trash-bin setting"** is a decision, and belongs
here rather than as a scenario asserting a setting does not exist.

### A subfolder is Nextcloud's layout, not Penpot's

Penpot has no concept of a subfolder, so filing a design into `wip/` changes
nothing on its side — and a pull must not undo it, because Nextcloud owns layout.
Those were two scenarios saying one thing; the second only added a pull to prove
the first still held.

Confinement is to the PROJECT, not to a folder, which is why a LINK may be filed
away in a subfolder too. That is the same rule read from the strict end, so it is
an Examples row rather than its own scenario.

### A design moved to another team in Penpot leaves this mapping

From this mapping's point of view the design is simply gone, and a vanished
design's mirror is trashed like any other. If the other team is mapped as well, its
own sync mirrors the design there — nothing about this scenario needs to know that.

### Moving: what this feature stopped claiming

- **"Filing a draft" and "Un-filing"** were the same move as project-to-project,
  with the team root at one end. The team root IS Drafts, so all three are Examples
  rows of one outline rather than three scenarios and two vocabularies.
- **"Moving an unmapped tracked file back under a project offers a restore"** was
  the confirmation model `designs/restore.feature` shed for the same reason: no
  such thing exists in n8n or grafana, and a move is not a restore.
- **"No move, of any file or folder, ever deletes anything in Penpot"** had
  `When I move either of them anywhere at all` — not a gesture, and an assertion
  about every possible gesture cannot fail.
- **"A move between projects is attributed to the acting user"** asserted
  `"move-files" is called using that user's own token`. Attribution is real, but it
  is a property of the change in Penpot, not of which call carried it.
- **`Penpot is never contacted`** appeared twice with no step definition behind it.
- **`the next pull reconciles it`** ended the failure scenario: the mechanism, not
  the behaviour.

The Background also mapped ONE folder and then re-declared it per scenario, twice
as `sync` and once as `link` — the same folder in two modes, depending which
scenario you read. There are two mappings now, and no scenario restates a mode.

### The three layers a restore can land in

This is the one place penpot differs from n8n and grafana in kind, not just in
vocabulary: **Penpot has its own trash, with a grace window.** So the far side can
be in three states when a mirror comes back, and each needs something different:

1. **In Penpot's trash** — restore it there. Lossless: the same id, revision,
   history and links. Nothing is imported.
2. **Already back** — someone rescued it in Penpot first. Nothing was lost
   remotely, so nothing is sent; a second restore would be a second design.
3. **Gone for good** — past the grace window there is nothing to put back. The
   mirror still holds the archive, so the content is not lost, but importing it
   would make a NEW design with a new id and no history. That is a different
   gesture, and not one a restore performs on the user's behalf.

Different end states, so three scenarios rather than three Examples rows.

### Restoring: what this feature stopped claiming

`restore.feature` held 23 scenarios and was two features under one name.

**Half of it was a MOVE.** Seven scenarios opened `Given an unmapped ".penpot"
file … When I move the file into the "My Stuff" folder`, and described a
confirmation dialog offering to "restore" the file into Penpot. Moving a file into
a mapping is `designs/move.feature`'s gesture, and neither n8n nor grafana has any
such confirmation model — a restore is the trash gesture undone, nothing more.

**The confirm-and-explain scenarios went with it.** "Restoring is always confirmed,
never silent", "Restoring a deleted design states clearly what does and does not
return", "The app never silently resurrects a deleted design", "Confirming a restore
of a deleted design…". Several carried two `When`s, which is two scenarios wearing
one title.

**A link cannot be in the trash at all.** "A file with no archive cannot be
restored" arranged a link file being moved in; links are never trashed here, in
grafana, or in n8n.

**The internals went.** Which RPC restores the design, whether the restore stream
answers with an empty set of ids, that the app re-checks the project listing and
retries, that an import ignores the `name` param so a follow-up rename is needed —
all of it is how the app keeps its promise, not the promise.

**Attribution stayed**, as two scenarios — see the section above for why they are
not Examples rows.

### The copy belongs to where it lands

A copy is never the original's design. What decides which PROJECT it becomes is the
folder it landed in — the same project, another project, or the team root, which is
Drafts. So the destination is an Examples column and the rule is one scenario.

### Copying: the @todo block was the live block, rewritten as RPC calls

`copy.feature` carried 23 scenarios, and roughly half of them said what the other
half already said. "Copying up to the team root" appeared THREE times — once live,
twice as @todo. "A copy is tracked the moment it exists" was "A copy can be renamed
immediately" under another name. "Copying outside every mapping creates nothing" and
"Copying an untracked file changes nothing" were both the live never-contacts-Penpot
scenario.

The duplicates differed in one way: they asserted the RPC rather than the behaviour —
`"duplicate-file" is called with the original's "penpot_id"`, `"move-files" is never
called, because the project did not change`. Which calls the app makes to get there
is the app's business; a reader needs to know a new design exists in the right
project with its own id, which the live scenarios already said.

Both `"move-files" is never called` claims also had no step definition at all. They
passed the guard because the scenarios were skipped, and would have had nothing to
run if they were not.

### A failed propagation never reverts the user's local rename

Nextcloud has already renamed the file by the time the app tries to tell Penpot.
Undoing that would fight the user over a gesture that succeeded locally, so the
local name stands, the failure is reported, and the file keeps its `penpot_id` —
which is what lets a later sync finish the job rather than read the file as new.

The scenario used to end `And the next pull reconciles the name`. That is the
mechanism, not the behaviour, and it belongs to whatever the next sync does.

### Renaming: what this feature stopped claiming

Six scenarios were removed rather than converted.

**Three were the same behaviour twice.** "Renaming a mirrored file in Nextcloud
renames the Penpot file" said what the live scenario above it already said;
"Renaming never breaks the Penpot link" asserted post-state that now rides the
rename's own table; and "Renaming a design that was just copied propagates to
Penpot" is a rename of a file that happens to have arrived by copy — the copy is
decoration, exactly like renaming a file that happens to live in a subfolder.

**Three asserted the API call rather than the behaviour.** `"rename-file" is
called with the id under the key "id"` and `not under "file-id"` is a spec for a
JSON key; `the ".penpot" extension is stripped before sending and re-added
locally` is how the call is built; `"export-binfile" was never called` is both an
internal and a negative. What a reader needs to know is that the design ends up
named "New Name" and keeps its id, which the remaining scenarios say.

The slash-in-a-name scenario also left: it is about the PULL creating a mirror for
a design whose name cannot be a filename, which is `designs/create.feature`'s
subject, not renaming. It was also malformed — an `And` with no `Given` before it.

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
design for a file that never had one is still an open fork, and
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
fork — restore only ever puts BACK something that
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

### A restore whose follow-up rename fails reports partial success

FROM THE RETIRED `errors.feature`. A restore that cannot come back at its
original id is an import plus a rename, and the two can part company.

ROLLING BACK WOULD BE THE DATA LOSS. The import succeeded — a design the user
asked for is now in Penpot. Deleting it to "clean up" a failed rename destroys
the thing that just worked, so the app keeps it, records the new id against the
local file, and says plainly that the design came back wearing the wrong name.

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

## projects/view

`features/projects/view.feature`

TELLING A PROJECT FOLDER FROM AN ORDINARY ONE. Created in the noun/verb
restructure from scenarios that had been sitting in `mapping-membership.feature`
— looking at a project is looking, and `designs/` already had a `view`.

### A project folder carries a visible tag as well as its metadata

Two markers, two jobs (§6.32): the metadata is what every lookup reads, the tag
is what a user can see and search for. Under free nesting that matters more than
it would under a depth cap — position no longer tells you which folders are
projects.

### A tagged folder's name always equals its Penpot project's name

A project folder is otherwise indistinguishable from an ordinary folder someone
named the same thing. Tag plus matching name means a tagged folder called "Acme"
IS the Penpot project "Acme". The rename half of the invariant is
`projects/rename.feature`'s, where it is live.

### A plain folder inside a mapped folder is tolerated, not adopted

The whole point of the tag: ordinary folders live among project folders without
becoming projects. The opt-in that DOES make one a project is
`projects/create.feature`'s, and it is live there.

## team-mapping/set-mode — RETIRED (and `sync-mode` with it)

`features/team-mapping/set-mode.feature` is **gone**, and so is the
`occ penpot_sync:set-mode` command it specified. The whole per-file mode axis has
been removed from the app.

**THE SECTION THIS REPLACES CALLED ITS OWN SHOT.** It was headed *"WHOSE DECISION
IS THIS, AND WAS IT EVER ASKED FOR?"*, recorded that per-file mutable mode
*"diverged from the design without a decision"*, that *"nobody asked for per-file
switching — it arrived because the move guard needed an escape hatch to offer"*,
and named the exact price of undoing it: *"the lever goes, the move guard loses
the escape it offers, and every 'promote to sync first' refusal in move.feature
needs a different answer."* That is precisely what was paid.

THE RULE NOW, AND IT IS THE SIBLINGS' RULE: **the mapping alone decides the
mode.** It is an immutable field of the mapping, exactly like the folder name and
the Team Folder flag. A design's mode follows from the mapping it was mirrored
under, and changing it means removing the mapping and mapping the team again —
which re-mirrors the same designs, by the same ids, into the same folder.

Neither `nextcloud-grafana` nor `nextcloud-n8n` ever had a per-file lever. This
app growing one made "the mapping says link" quietly untrue, and gave a third
place for the same question to be answered differently.

WHAT WENT WHERE:

| it said | where it went |
|---|---|
| Promoting a mirrored design fetches a real ZIP from Penpot | the export is still proven live — a `sync` **mapping** pulls, and `move.feature` / `rename.feature` / `edit.feature` assert real ZIP bytes on disk |
| A promoted file is not re-exported by the next pull | the revision check it rested on is `edit.feature`'s subject, where an edit in Penpot is the action |
| Demoting throws the archive away and never contacts Penpot | deleted — the action does not exist |
| Demoting asks first, because it deletes the only local copy | deleted with the prompt, the `--force` flag and `SetModeTest` |
| A link refusal offers to promote the file to "sync" mode first | deleted — the refusal now names the rule and stops, like both siblings' |
| Promoting a link first makes the move work normally | deleted — there is no promoting |

`features/sync-mode.feature` had already been retired *into* `set-mode.feature`,
so its note is folded in here rather than left pointing at a file that no longer
exists. Its own diagnosis still stands and now reads as the earlier half of this
one: it was sixteen `@todo` scenarios restating live ones, its
*"files inherit their mapping's default mode"* scenario described a bulk mode flip
that has never existed, and two of its scenarios named the pull as the actor —
the same defect that retired `reconcile.feature` in the Grafana sibling.

HOW A SCENARIO GETS A REAL ARCHIVE NOW: it asks for a sync mapping —
`Given a Penpot team named "…" is mapped to the folder "…" in "sync" mode` — and
lets the pull export. The step resets the mappings first, so a scenario stating
it is doing exactly what a person would do: mapping the team the other way.


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

### Users do not author their own team mappings

DELIBERATELY NOT BUILT, not merely unwritten. Letting users author mappings
breeds edge cases faster than anything else on the table: two users mapping
one team, a user mapping a team the service account cannot see, folders
orphaned when the admin removes the mapping underneath them. The personal
team mapping stays automatic and singular.

---

### A sync that dies halfway leaves every file whole

FROM THE RETIRED `errors.feature`, where it read "a pull interrupted halfway" —
the reconciler as the actor again. What matters is not that a pull was
interrupted but that a sync can die at any moment, and no file may be left as a
half-written archive.

Every write is to a temp location and moved into place, so a file is its old
version or its new one. `@blocked` — **no fault injection**: the run has to be
killed mid-write.

### A user syncs their own personal team

THE PER-MAPPING SYNC, FOR THE ONE MAPPING A USER OWNS. It is the same shape as
`team-mapping/sync-now.feature`'s card button — one mapping, on demand, filling
immediately — and it is here rather than there because the personal mapping is
not a team mapping. It has no card, no admin, and no row in the mapping list: it
exists because a token does (`connection/personal.feature`).

THE TREE IS THE PROOF, exactly as it is for a team. Two differences, and both
fall out of where the mapping points rather than from anything special about
personal work:

  - the mapped folder is the user's HOME ROOT, so a project folder sits at the
    top of their files and a Drafts design sits beside it;
  - the sync runs on the USER'S OWN TOKEN, because the service account cannot see
    a personal team at all (saga §6.12) — which is why the designs appearing is
    itself the proof of whose credential ran.

Everything else — the `penpot` tag on project folders, links holding no bytes,
dates coming from Penpot — is the ordinary behaviour, asserted with the ordinary
table.

## team-import — RETIRED

`features/team-import.feature` is **gone**. "Importing a team" was mapping a team
and syncing it, stated differently — `team-mapping/create.feature` and
`connection/sync-now.feature` own both halves. What the file added beyond them
was a listing UI and three refusals, and none of the four survived contact:

| it said | why it went |
|---|---|
| A team already mapped is detected, not re-imported | a status label on a listing. The BEHAVIOUR — a team may be mapped once — is `team-mapping/create.feature`'s "A mapping may not reuse a team or a folder", live |
| Importing an unmapped team requires Team Folder rights | a permission gate on an operation users do not have. Mapping is admin-only, which is the premise of `team-mapping/` — there is no user-facing import to gate |
| A team the service account cannot see is not importable | a negative on the impossible: a team the service account cannot see cannot be mapped at all, which is `team-mapping/create.feature`'s precondition |
| The import surface explains that tagging a folder creates a project | no `When`. Its first half is `projects/view.feature`'s live "A plain folder inside a mapped folder is tolerated, not adopted"; its second asserts UI copy |

### THE FORK THIS FILE GUARDED IS CLOSED, AND WAS CLOSED BY SHIPPING

The section used to say, at length, that "a new Penpot project from a tagged
Nextcloud folder" reopens §6.1's read-only lock, that the carve-out was **not
granted**, and that *"nothing here should be implemented against until a future
saga chapter ratifies it"*.

`projects/create.feature`'s "Tagging a folder `penpot` creates the project in
Penpot" is **live and green in CI**. The carve-out was taken. The prose warning
against it survived the decision by some months, which is its own lesson: a note
that describes the old world is worse than no note, because it will be believed.

### WHAT IS STILL OPEN, AND STILL WORTH KNOWING

**Creating a Penpot design for a local file that never had one** — the
import-as-restore path — remains undecided, and `designs/restore.feature` rows 3
and 4 are where it bites. That is a narrower question than the one above:
creating an EMPTY project is cheap and reversible; importing an archive mints a
design with a new id, no history, and no way back to the original.

Three facts from the live `import-binfile` testing (saga §6.20) apply whenever it
is built, and are the reason `designs/restore.feature` needs a follow-up rename:

  - the call is SSE, not a plain request;
  - its params are kebab-case (`project-id`, never `projectId`);
  - its `name` parameter is IGNORED — an imported file takes the name from its
    archive manifest.

**The service account must already be on the team.** A user's personal token
showing them a team is not sufficient for it to be mappable (saga §6.18); the
service account needs its own `viewer` invite. That is `team-mapping/create.feature`'s
precondition now, not a property of an import screen.

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
