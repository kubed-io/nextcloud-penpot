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

### A copy's clocks are its own

`copy.feature` pins `Created` in the post-state because a copy is a BIRTH: a design
exists in Penpot that did not before. Inheriting the original's date would date the
copy before it existed, and this app already makes a point of a mirror wearing the
DESIGN's clock rather than the sync's — so the copy has to wear its own.

`Modified` is deliberately not asserted, and the asymmetry is the platform's rather
than ours: Nextcloud puts the modification time back before the copy request ends,
so the file wears its design's modified date only from the next pull onward. The
Grafana sibling measured that inside a failing CI run and left the same row out.

Worth restating here because penpot's clocks arrive as EPOCH MILLISECONDS, not the
ISO-8601 both siblings parse ({@see MirrorTimes}) — an assertion that passes on a
sibling can pass here while reading a number nothing parsed.

### A copy made in Nextcloud is named by Nextcloud

Nextcloud has already named the copy by the time the app hears about it, and that
name is the user's stated intent — so it is what the design is called in Penpot,
extension stripped. Copying inside one folder gives "Original (1)", copying into a
different one keeps "Original", and both are the platform's choice, not ours.

Two places agree rather than the siblings' three. Their bodies are JSON carrying a
`title`, so a name can disagree with itself inside one file; a `.penpot` body is a
binary archive with no name to check.

### The mappings in the Background

Three mappings, declared as ONE table — team, folder, mode, storage and groups
spelled out per line — plus one folder that is mapped to nothing. Every scenario
then names the folder it acts in, and the Background is what says what that folder
IS. Nothing restates "sync", "team folder" or "unmapped" in a step.

The four lines are the whole input space a copy has: a `sync` mapping in an admin
folder, a `sync` mapping in a Team Folder, a `link` mapping, and outside every
mapping. Mode and storage are not scenarios; they are properties of the place the
file landed in, which is why they belong here and not in a Given.

Ported from the Grafana sibling, which arrived at it after three separate
`a mapping with the following values:` blocks per Background stopped reading as one
neighbourhood and started reading as three unrelated facts. The Background is the
neighbourhood, not the subject — the subject is whatever the scenario arranges for
itself.

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

COPYING A PROJECT — a real copy, producing a real second project.

REVERSES saga §6.40, which refused the gesture. The old reasoning was that Penpot
has no duplicate-project call, so the app would have to synthesise one. It does, and
that is fine: a project copy is a `create-project` followed by one `duplicate-file`
per design, which is exactly what a copied FOLDER already means in both siblings.
Nothing about it is atomic in Grafana either.

The three old objections answered:
 (1) two folders claiming one project — they do not: the copy gets a NEW project id
     and every design under it a new file id, which is the whole point of the rule;
 (2) Nextcloud auto-naming the copy — that is the same rule designs/copy already
     lives by. Nextcloud names it, Penpot takes that name, §6.36 stays true;
 (3) three apps on one folder — each app answers for its own mappings, as it does
     for every other gesture on a shared folder.

### A copied project is a new project

The copy carries a new project id and every design under it a new file id. Two
folders claiming one project is the failure this rule exists to prevent, and it is
the same claim designs/copy makes one level down.

Storage is a row rather than a scenario: an admin folder and a Team Folder differ in
where Nextcloud puts the bytes and in nothing this app decides.

### A project copied into another team belongs to that team

The destination decides, exactly as it does for a design. The copy is created in the
destination team and never re-homed afterwards, so there is no window in which it
belongs to the team it came from.

### Penpot projects do not nest

THE NUANCE THAT DOES NOT PORT FROM THE SIBLINGS. Grafana folders nest; Penpot
projects are flat under a team. So a project folder copied UNDER another project — or
under a plain folder — cannot be a project, and arrives as an ordinary folder with no
project id.

Its designs are not lost: they resolve by the same nearest-project-ancestor rule
create.feature states, so they join the project above, or the team's Drafts when
there is none.

### A copy never changes a project's mode

A mode belongs to the TEAM, and a copy may not change one. Copying a project from a
sync team into a link team is refused, and so is the reverse — the same rule
designs/copy carries for a single design, read one level up.

ONE OUTLINE, TWO EXAMPLES BLOCKS, because it is two independent halves of one rule —
the same shape designs/copy uses. The first is the SOURCE rule and it is total: a link
project has nowhere to go, its own team included. The second is the DESTINATION rule,
which only needs a source the first has not already refused.

Grafana states the same rule twice, split across two scenarios, in its FOLDER copy but
combined in its dashboard copy. The combined form is the one worth having: split, the
link-to-link row falls between the two and neither scenario claims it.

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

### Why a create cannot write the bytes itself, and why the spec does not mention it

**A code note, not a spec one.** The scenario says the file holds an archive after
the gesture, and it is right to: that is the state the user ends up in. How long
the app takes to get there is an implementation detail, and a scheduled sync the
person never sees is not something Gherkin describes.

Worth writing down because the obvious fix is a wall. Exporting during the create
looks like it would remove a self-inflicted transient — a file stamped `sync`
holding zero bytes is what `occ penpot_sync:status` prints as `sync` / `pointer`
and calls *"precisely the drift"*. It cannot be done from there, and the reason is
Nextcloud's locking rather than anything about Penpot. `CreateListener` runs on
`NodeWrittenEvent`, which `OCA\DAV\Connector\Sabre\File::finalizeUpload()` emits
*after* downgrading the file to a **shared** lock — its own comment says so:
*"Downgrade to shared lock before post hooks so legacy hook consumers can still
access the file."* `Node::putContent()` goes through `View::file_put_contents()`,
which takes a shared lock and then `changeLock(…LOCK_EXCLUSIVE)`, and that upgrade
cannot succeed while the DAV request still holds its own shared lock.

nextcloud-n8n's `CreateService` carries the same finding, from the round that paid
for it: *"this runs INSIDE the handler for the very write that created the file, so
`putContent()` on the same node hits Nextcloud's lock and the whole create fails.
Tried; it took out every arrange in the suite that lands a file in a mapped
folder."*

So the body arrives on the next sync, down `ArchiveService`'s self-healing path.
**The harness collapses that wait rather than the spec describing it** — the
`Then a matching design is created in Penpot` step runs the sync itself, the same
way the arrange spine already does after seeding a design. A scenario must never
grow a `When the admin syncs every mapping` to reach a later state: it is a `When`
after a `Then`, and it puts an admin's button into a story about a user making a
file.

### A link carries a revision too, because it is the pull's stamp

**Two live feature files disagreed about this, and the one that was green won.**
`mapping/sync-now.feature` asserts `penpot_revision | set` on a `link` file and has
been passing for courses; `designs/create.feature` said `absent`, reasoning *"a
revision records what a push last sent, and a link never pushes"*.

That reasoning describes a `penpot_revision` this app does not have. The stamp is
`revn` + `modified-at` joined (§5.5) and it is the PULL's drift signal — what the
mirror last saw upstream, not what anything sent. `PullService` writes it for every
mode because every mode is pulled; a link's body is empty, but the question "has
this design changed since I looked?" is the same question for both.

Worth noticing HOW the two got out of step, because it is the same shape as the
`Penpot/Inbox` row and the home-root row before it: nothing is wrong with either
sentence read alone. The contradiction is only visible with both files open, and
one of them had never run.

── creating in a personal team ─────────────────────────────────────────────
Same behaviour, different destination: the user's own Drafts rather than the
team's. `connection/personal.feature` owns why that destination differs.

### A design created in the user's own home lands in their personal Drafts

THE WHOLE POINT OF THE IMPLICIT MAPPING. Without a team ancestor this file
resolves to nothing and stays inert (create-design.feature's rule). With
one it is the ordinary team-root case (§6.35) — same rule, new root.

**AND ONLY THE ROOT.** A folder in the home is promoted to a personal project by
the first design landing in it, exactly as a folder under any other mapping is —
see [a folder is a project when a design is in it](#a-folder-is-a-project-when-a-design-is-in-it).

This scenario said the opposite until it was read again: a plain `Sketchbook` in
the home landed in Drafts too, under the caption *"the home root and a plain
folder in it are both outside every project"*. That is the retired
"depth is not part of it" reading of §6.35, and the personal-mapping TWIN of the
`Penpot/Inbox` row the same round removed from this very file — the contradiction
was settled in one mapping and left standing in the other.

Worth noticing how it survived: nothing was wrong with the sentence on its own
terms. It only reads as a contradiction next to a rule that lives in a different
file, which is the failure mode of splitting a spec by noun and the reason
`README`'s claim that personal projects are *"the ordinary rules with a different
mapping"* has to be load-bearing rather than decorative. If the home root is a
mapping root, promotion works there the way it works everywhere, and an exception
for personal would be the special case needing an argument.

### The personal mapping is held until the siblings have one

**Every `@todo` in this app is a promise except these, and they are a DECISION —
one taken on where this app sits next to its siblings rather than on what the rule
should be.** `nextcloud-grafana` and `nextcloud-n8n` have no per-user connection at
all: one instance token, one set of admin mappings, and nothing that belongs to a
single person. The personal mapping would put penpot ahead of both on an axis
neither has started, and parity is the goal until it is not.

So the rule stated above stands as the spec, and the scenarios resting on it wait:
`designs/create.feature`'s `Create a design in the user's own home`,
`designs/move.feature`'s `Move a design into another team`, and all three of
`connection/personal.feature`. They are `@todo` rather than `@unbuilt` because
nothing about them is owed by the code *yet* — the queue is where they belong, and
this note is why they will still be there after a round that clears the rest.

**What exists today, and what it is NOT.** `PersonalTokenService` is real and
shipped, and it is attribution only — its own docblock says so. A user's token is
passed as an actor token to RPC calls so Penpot records the change as theirs, and
it is read nowhere else. It maps nothing.

**What it would take**, recorded so the next round does not have to re-derive it.
One extra rung on the `MembershipResolver` walk: the acting user's home folder,
counted as marked when they have a personal team. Everything downstream is
untouched — Drafts at the root, promotion by path below it, the same move, rename
and delete code — because a home root then behaves exactly like a mapping root that
happens to carry no marker. The team itself comes from `get-teams` asked with the
USER's own token, where Penpot computes `is-default` as
`(t.id = profile.default_team_id)`; that is read out of the backend's `teams.clj`,
not inferred, and it makes the token the single source of truth.

One consequence to carry into that round, because it reaches past the feature:
**a personal token changes what other scenarios mean.** `Create a design outside
every mapping` puts its file in `Scratch`, and for a token holder that is not
outside every mapping at all — it is a folder in their own team, and the app would
rightly allow the create the scenario expects it to refuse. Any harness that
arranges a token has to clear it before every scenario, in the arrange rather than
after, where a failing scenario could skip it.

### A design crossing between a home and a shared team is a move, not a create

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

A PROJECT IS A FOLDER THAT HOLDS A DESIGN, and its name is the path from the
mapping's folder down to it. Both halves were decided together (§C6.38) and neither
works without the other.

REPLACES the tag mechanism. A `penpot` tag used to be what made a folder a project,
and the app carried a whole vocabulary for tagging, un-tagging, tagging something
already tagged, and tagging outside every mapping. None of it was behaviour anyone
performed on purpose — it was a mechanism wearing a feature's clothes. The tag
survives only as the visible pill `PullService::tagProject()` already describes it
as: decoration over an authoritative id, never at the cost of the pull.

### A folder is a project when a design is in it

**A NESTED PROJECT IS STILL EXPLICIT, and that is this rule's own edge.** A design
in `Penpot/foo/bar/baz` where `foo/bar` is already a project belongs to `foo/bar`
— nearest ancestor, §6.29 — so `baz` does NOT become a project by holding it. Only
a folder with no project above it is promoted.

That reshaped two arranges rather than any behaviour. `Move a folder that other
projects are named through` and its delete-side twin both need TWO projects, and
used to get them because the harness tagged every folder it wrote a design into.
With promotion by content it has to say so, which is what the `kind` column is for.
The move scenario failed loudly when it stopped being true; **the delete one passed
vacuously** — *"Penpot holds no project named `foo/bar/baz`"* is trivially true of a
project that never existed. Worth remembering when a negative assertion goes green
after a rule changes underneath it.

**BUILT, and the boundary is where the EVENT is.** Promotion happens as a design
arrives — created, moved in, copied in — because those are the three gestures that
fire a per-file event the app can act on. `Create a design in a folder Penpot has
never seen` passes on all four rows, plain folder and Team Folder, one level deep
and three.

`Move a folder of untracked designs into a team` does not, and it fails on two
walls at once rather than on this rule:

`Move a design into a folder Penpot has never seen` is `@unbuilt` for the same
family of reasons, and only one of its four rows works today (`Penpot/Existing` →
`Penpot/Team/Deep`, both inside one mapping and one storage). The other three want
capabilities this rule does not supply: a source in `Scratch` is an UNTRACKED file,
and importing one is the §6.33 carve-out; the two rows that cross between `Penpot`
and `Shared` cross a STORAGE boundary, which fires no rename event at all.

1. **A folder move fires one event, for the folder.** Core emits nothing per
   child, so no design inside ever arrives anywhere as far as the app can tell —
   the same wall `projects/copy` and the cross-team move sit behind.
2. **The design in it is an uploaded ARCHIVE**, and importing one is the §6.33
   carve-out this app has always refused: a `.penpot` someone drops in is content,
   not a create, and turning it into a design is a human-directed act.

Either alone would be enough. Worth stating because "a folder is a project when a
design is in it" reads as though dragging a folder of designs in should work, and
the reason it does not has nothing to do with folders becoming projects.

The same rule both siblings state about folders, and the reason it works here is
that Penpot never has to be told about a folder — only about a design, which it
needs a project id for anyway. So the project is created as a CONSEQUENCE of the
first design landing in it, and an empty folder stays Nextcloud's own business.

Promotion by content rather than by tag because a move is a gesture people already
make, and a tag is one they have to be taught.

### The project name is the path below the mapping

`/Penpot/Team/Deep` in a mapping rooted at `Penpot` is a project named `Team/Deep`.
Penpot has no parent field — projects are flat under a team — so the only place the
tree can live is the name.

MEASURED, not assumed: `create-project` takes `[:string {:max 250, :min 1}]` and
accepts `/` anywhere in it, including leading, trailing and doubled. Two projects in
one team may share a name. So the name is a free string, and reading a path out of it
is our interpretation rather than a contract Penpot enforces.

Which is why the path is a RENDERING and never the key. Reconcile stays by project
id, exactly as it does for a design — so two projects genuinely named `foo/bar`
render as `/foo/bar` and `/foo/bar (2)`, and the suffix is Nextcloud's alone, the
same rule both siblings already carry for two things sharing a title.

### A folder holding no designs is just a folder

The other half of the promotion rule, and the reason `Create a folder in a mapping`
earns a scenario: a folder with no project id is one this app has never had anything
to do with. Making an empty folder must not reach Penpot at all.

### A project name with slashes is a path

The case that decided the whole design. Someone types `foo/bar` into Penpot's own
project dialog — nothing stops them, measured — and under leaf-naming there was no
answer but to skip it and log. A project the user made, silently absent from
Nextcloud.

Reading the slash makes that project arrive at `/foo/bar` instead. The guard does not
disappear, it shrinks: from *any name containing a slash* down to *a name that spells
no path at all*.

### The folders a project name spells are not projects

`foo/bar` makes `/foo` because `bar` needs somewhere to sit. `/foo` holds no design
and carries no project id, so it is indistinguishable from a folder the user made —
and that is correct, because there is nothing to distinguish. If a project named
`foo` appears later, that same folder gains an id.

This answers the first of open question #47's three blockers, which asked how an
inferred folder is told apart from a user folder. It is not, and need not be.

### A project name that spells no path is skipped

`/`, `foo/../bar`, `foo/?/bar` — all legal in Penpot, none renderable as a Nextcloud
path. Normalise first (a leading, trailing or doubled slash is dropped), then skip
what is left over.

REPORTED AS A NEXTCLOUD NOTIFICATION, which is the only channel a pull has. An
earlier cut said "the sync reports the project it could not place", which reads as a
post-state and is really a second gesture — an excuse to run a sync inside a `Then`.
The bell is where an async failure belongs, and it is the same channel both siblings
already use ({@see SyncNotifier}); this app has yet to grow one.

One project is the whole cost, which is why the scenario keeps a second project in
the team and asserts it arrived. The rest of the team still pulls, and that is the
difference between a report and a failure.

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

Deleting a project folder deletes the project. Penpot has its own trash, so the
designs go there with it and nothing is destroyed by the gesture on either side —
which is what makes a folder delete safe to offer at all.

REWRITTEN when §C6.38 made the name a path. Five of the seven old scenarios asserted
CALLS rather than outcomes — `"delete-project" is called with that project's id`,
`"delete-file" is never called`, `exactly one "delete-project" call is made`,
`Penpot is never contacted`. That is the implementation's call pattern, not a
behaviour anyone can observe, and it would keep passing while the project sat there
undeleted. What a person can see is that the project is gone and its designs are in
Penpot's trash, so that is what the scenarios say now.

Two more went for other reasons: `A project Penpot still lists after deletion is not
mirrored` described the `get-projects` upstream bug (§6.42), which is a workaround
and belongs in the saga; `Restoring a design also restores its project` is
`projects/restore`'s.

### Deleting a project folder deletes the project in Penpot

The designs go to Penpot's trash with the project — Penpot deletes a project softly,
so the whole gesture is reversible on both sides for the length of its grace window.
The Nextcloud folder is recoverable from the Nextcloud trash, independently.

**Confirmed against the server, not assumed.** `delete-project` sets `deleted-at` to
a point in the future using the team's own deletion delay — the identical mechanism
`delete-file` uses, and the one §C6.11 already established the app can trust. That
matters because the softness is the entire argument for letting ONE gesture reach
many designs without asking first. It also refuses to delete a team's DEFAULT
project (`:non-deletable-project`), which this app cannot reach: Drafts is a state,
not a folder (§6.35), so no folder carries the default project's id.

**This scenario was tagged `@todo` and the code did not exist.** `@todo` means *the
code exists; only the test is missing*, and nothing deleted a project at all —
`DeleteListener` returned on anything that was not a `File`, `DeletionService` had
only `onTrashed(File)` and `onPurged(File)`, and `PenpotClient` had no
`delete-project`. Trashing a project folder was a plain local delete.

Nobody had checked, and nothing would have made them: a `@todo` is a promise about
code that only running the scenario collects on. It is the same failure the four-tag
vocabulary was introduced to prevent, arriving from the one direction the vocabulary
does not defend — a tag that is too OPTIMISTIC reads as work queued rather than work
missing. Worth remembering when reading the remaining queue: 76 of these are
promises.

### Trashing a folder takes every project its name spelled

THE CONSEQUENCE OF THE PATH MODEL, and the one thing about deleting that does not
port from either sibling. `/Penpot/foo` may hold no designs and be no project, while
`foo/bar` and `foo/bar/baz` are named THROUGH it. Deleting it ends all of them.

That is not a surprise to hide — it is what deleting a folder has always meant in a
file manager, and the Nextcloud trash entry is the undo. But it is why the trash
entry has to be asserted rather than assumed: this is the gesture that can reach the
most projects at once.

### Trashing a project folder in a link team is refused

The same refusal a single link file gets, for the same reason: under a link the tree
is Penpot's and Nextcloud is a read-only mirror of it. Removing the folder locally
would only make the mapping disagree with the team it mirrors until the next pull
wrote it back.

### A project deleted in Penpot leaves no folder claiming its id

Two endings, decided by what the folder holds, and the pair only works if both are
stated. Holding only designs, the folder GOES — the mirror has nothing left to be.
Holding anything else, the folder STAYS, keeps the other files, and loses only its
project id.

The second ending is the one worth reading twice, so it names the surviving file
rather than describing an absence: deleting a user's spreadsheets because a Penpot
project went away is not this app's call, and "still exists, holding Budget.xlsx" is
the only phrasing that proves it didn't.

The pair is Grafana's, verbatim in shape, and the reasoning transfers exactly.

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
| the metadata cannot be edited | core, which registers every key EDIT_FORBIDDEN | a note, not a scenario — see RETIRED below |

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
| The row icon and the menu glyph are separate files | two files with opposite contracts, so one scenario could not be the arrange for both. The menu glyph went to `open-with.feature`; the row icon half was retired later, in the alignment pass — see RETIRED below |

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
  metadata is written; the human-visible pill is still Course 6 work. No
  scenario here asserts it: this file looks at a design FILE, and the tag is a
  folder's — `connection/sync-now.feature` claims it, in the tags column of the
  tree a pull leaves behind.

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

### Finding designs by their mode

`@blocked`, and the missing capability is named: there is no proven DAV REPORT
search over `nc:metadata-*` in this harness to drive it against. The index
itself is real — `penpot_mode` is registered as an indexed metadata key
precisely so "find every link in the instance" is a query rather than a folder
walk — but nothing here can issue that query. Confirm the search surface exists
and this becomes an ordinary `@todo`.

### RETIRED — three more, when this file was aligned with its siblings

Grafana's `dashboards/view.feature` is three scenarios; n8n's is the same three
plus two CLI listings this app has no command for (`occ` here maps teams, it
does not list designs). This file was five, and each of the three that went was
a fact already owned somewhere else:

| scenario | why it went |
|---|---|
| The row icon is the app's colour mark | pixels, and unreachable from HTTP — it had been `@blocked` since it was written. Its only observable half is that a mirror carries the app's own mimetype instead of `application/zip`, which the mimetype scenario asserts. The renderer fact it existed to record is kept below, where a note can hold it without pretending to be a test |
| A file carries the team its design belongs to, but never a project | `penpot_team_id` is a row of the DAV outline, so the positive half was already said. The rest was a NEGATIVE — a scenario proving a key the app deliberately does not write. Why it is not written is documented above; nobody performs it |
| What the app manages, only the app changes | the refusal is core's and not this app's: every key is registered EDIT_FORBIDDEN, so a PROPPATCH is turned away before any of our code runs. Grafana keeps the note and has no scenario for it, and this file now matches. n8n does keep one, in `workflows/edit.feature` — filed as an edit, which is what a PROPPATCH is |

THE RENDERER FACT, kept because it will otherwise be rediscovered the hard way:
Nextcloud serves mimetype icons out of `core/img/filetypes/` WITHOUT recolouring
them, so that file must carry its own fill or it renders invisible. That is the
opposite contract from the context-menu glyph, which core DOES recolour — which
is why the menu half lives in `open-with.feature`, beside the action that draws
it (saga §C6.1/§C6.7).

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

**NARROWED, AND IT USED TO SAY THE OPPOSITE OF `projects/create.feature`.** The
sentence here was *"ONE RULE, AND DEPTH IS NOT PART OF IT. The team root and a
plain folder three levels down are the same case: under a team, under no project,
therefore Drafts."* That is flatly incompatible with
[a folder is a project when a design is in it](#a-folder-is-a-project-when-a-design-is-in-it),
which says the first design landing in a plain folder is exactly what MAKES it a
project. Both notes were in this file, and the two feature files each followed
one of them: `designs/create.feature` filed a design in `Penpot/Inbox` into
Drafts, while `projects/create.feature` expected a project called `Inbox`.

Settled in favour of `projects/create.feature`, on the organising rule this suite
already runs on: **`projects/` owns a folder's identity as a project**
([README](README.md)), and `designs/create.feature`'s Drafts rows were a
secondary claim in a file about something else. The adoption note also reasons
about the choice — *"a move is a gesture people already make, and a tag is one
they have to be taught"* — where this one only asserted uniformity.

So the rule now has depth in it, in exactly one place: **the mapping ROOT is
Drafts, and nothing else is.** That is not an exception bolted on, it is
{@see MembershipResolver::pathBelowMapping()} returning null — a root has no path
below a mapping to be named by, so there is no project it could become. Every
other folder under the team does.

The `Penpot/Inbox` row is gone from `designs/create.feature`, and the case it was
testing lives in `projects/create.feature` where it belongs.

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

**`@todo` FOR A CONTRADICTION INSIDE THE SCENARIO, not for missing code.** The
`move-files` half has been built since Course 4 and §C6.38's round proved the
folder half beside it. What stops this one running is its own `Then`: it asserts
`content | an archive` for BOTH Examples blocks, and the second block deliberately
carries a `Pointers` row — a LINK, which holds zero bytes by design and which
`designs/view.feature` pins as `empty`, live and green.

So the table says `penpot_mode | the mapping's mode` (which varies per row) and
`content | an archive` (which does not), and the two cannot both be right for the
same row. Left alone rather than trimmed: dropping the `content` row would make it
pass while quietly giving up a real claim — that a move does not cost a sync design
its archive. Settling it properly means either splitting the link row into its own
scenario or growing the vocabulary a `the mapping's …` cell would need, and that is
a spec decision rather than a test fix.

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

**WHY `Move a design out of every mapping` IS `@unbuilt`, AND WHY THE TAG SAID
`@todo` UNTIL THE DESIGN-MOVE ROUND.** The app does the opposite, and says so in
as many words. `MotionService::onMove()` logs *"move landed outside any Penpot
project; leaving Penpot untouched"* and returns, so the file keeps both its team
and its mode — both rows the scenario asserts are wrong today. Nothing outside
`CopyService` has ever written `PenpotMetadata::MODE_UNMAPPED`; grep it.

The comment at that return calls the unmapping *"Course 5's decision to make
explicitly, not one to infer from a drag"*. The spec has since decided the other
way twice over: this scenario, and the folder twin that IS built (§C6.38 — a
project dragged out of every mapping becomes a plain Nextcloud folder). A design
is the smaller half of a rule whose bigger half already ships, which is what makes
this a gap rather than a disagreement.

**AND WHY LEAVING NOW TRASHES THE DESIGN, WHICH IT DID NOT BEFORE.** The scenario
used to assert `the design still exists in Penpot` and explained itself with *"Penpot
has no recycle bin and needs none"*. That reads as a decision and was really an
absence: the app stopped mirroring the design and left it sitting in a project whose
folder no longer maps anywhere, visible to everyone in the team, indistinguishable
from work still being mirrored. Both siblings park it instead — n8n ARCHIVES the
workflow (`workflows/move.feature`, live), Grafana moves the dashboard into its
`nextcloud-trash` folder (`dashboards/move.feature`, live) — and the reason is the
same on both: a design nobody is mirroring should stop being underfoot without being
destroyed.

Penpot needs neither of the siblings' inventions, because it HAS the thing they are
each simulating. `designs/delete.feature` already says so in as many words: *"Penpot
needs no recycle-bin setting: it HAS a trash… The siblings bolt one on because their
services have none."* So leaving a mapping trashes the design — the same soft delete
`designs/delete.feature` pins for the trash gesture, keeping the id, the revision and
the history — and the `penpot_id` STAYS ON THE FILE, which is the whole trick. An
unmapped file is not a file that forgot; it is a file holding a claim on something
parked.

### A cross-team move always crosses a storage boundary

**NAMED FROM A MEASURED RUN, not reasoned about.** Two scenarios wanted a design to
change TEAM and keep its identity, and both were promoted to live on the reasoning
that the personal-token precondition was the only thing stopping them. CI disagreed,
and the mechanism is worth writing down because it is not the one the app's own
docblocks predicted.

This suite can map exactly two teams to two Nextcloud folders, and the Background
pins their storage: `Penpot` is an admin folder (the user's home) and `Shared` is a
Team Folder (a groupfolders mount). **There is no pair of mapped teams on the same
storage**, so every cross-team drag is also a cross-storage one.

What that costs is not what `MotionService`'s docblock says it costs. That warns
that a cross-storage move fires `NodeDeletedEvent` + a create rather than
`NodeRenamedEvent`, so the service never sees it. The log says otherwise — the event
DOES arrive. What does not arrive is the file's METADATA: properties do not travel
across a storage boundary, so the node that lands is a `.penpot` carrying no
`penpot_id` at all. `onMove()` reads it as untracked and takes the §6.33 import
branch, which is visible in the run as *"a design arrived, so the folder is a
project"* followed by *"adopted an archive as a Penpot design"* — a NEW design with a
new id, which is exactly what the scenario asserts must not happen.

So the wall is real, it is Nextcloud's, and it has nothing to do with personal
tokens:

- `Move a design into another team` is `@blocked`. Both rows cross the boundary, and
  there is no third mapping to write a same-storage pair with.
- `Move a design out of every mapping` LOST its `Shared/Let Go` row and is a plain
  Scenario now. The claim was *"from either storage kind, because leaving is
  leaving"*, and leaving a Team Folder for unmapped space strips the stamp the
  scenario exists to assert. One honest row beats two where one cannot pass.

The behaviour itself is very probably right — nothing suggests the app mishandles a
cross-team move it can actually see. It is unprovable here, which is a different
thing, and the tag now says which.

### Coming back revives whatever Penpot still has

The `penpot_id` on the file names a design that EXISTS — parked in Penpot's trash, or
never gone at all. Both rows behave identically: the app untrashes it if it needs to,
files it into the destination project, and re-stamps the file. **The id, the revision
and the history all survive**, which is the entire reason leaving a mapping was a
trashing rather than a delete.

WHY THE TWO ROWS ARE ONE SCENARIO. The outcome is identical and so is the code path —
"make sure the design exists and is in this project" absorbs both, and the difference
between them is only whether one of its steps had anything to do. Splitting them would
state one claim twice and imply a distinction that is not there.

### A design name is a scenario's own, because Penpot's trash is forever

`Move a design file into a project when Penpot still has its design` used
`Going Loose.penpot` — the same name the LEAVE scenario above it uses — and its
closing assertion is `the design "Going Loose" is not in Penpot's trash`. That is
a lookup BY NAME against a trash the whole feature file shares, and the leave
scenario parks a design of that name and leaves it there. So the return scenario
was reading the previous scenario's parked design and reporting that its own
untrash had failed, while the log said in as many words that it had worked.

Two things make this unfixable in the harness, which is why it is a naming rule
rather than a cleanup step:

- **Penpot's purge does not remove the row.** `permanently-delete-team-files`
  stamps `deleted_at` and leaves the record (§C6.11), so a purged design is STILL
  LISTED by `get-team-deleted-files`. Destroying the debris does not un-name it.
- **Deleting the leftover file trashes its design.** The file is still tracked, so
  the delete listener does exactly what it should — and manufactures a fresh ghost
  wearing the same name.

Every other scenario in the file already avoids this without anyone writing it
down: `Travelling`, `Relocated`, `Crossing`, `Departing`, `Uploaded`, `Turnbuckle`,
`Pointer`. One name per scenario, and the reused one was the anomaly. The return
scenario is now `Coming Back.penpot`.

THE GENERAL RULE, since this cost two CI rounds: **a design name is a scenario's
own.** Penpot's trash accumulates across a whole feature file and nothing empties
it, so any assertion phrased by name is really an assertion about the entire run.

**AND RENAMING WAS NOT ENOUGH, WHICH TOOK TWO MORE ROUNDS TO SEE.** Giving the
return scenario its own name fixed the collision BETWEEN scenarios and left the one
between the two ROWS of its own Outline — which no name can fix, because both rows
run the same `Given`. The mechanism is the TEARDOWN: it deletes the mapped project
folders after every scenario, and deleting a project trashes its designs. So by row
two, row one's design is in Penpot's trash wearing the identical name.

Every one of those rounds was read as the untrash failing, while the app's own log
said in as many words that it had worked. Both were true, about different designs.
The timestamps settled it — the return succeeded at `:09`, the teardown trashed the
project at `:13`, and the row that failed was the NEXT one.

So `the design "X" is not in Penpot's trash` now resolves the CURSOR's id whenever
the scenario has that design on stage, falling back to the name only for callers
naming a design they never staged. Purging the debris is not an alternative:
`permanently-delete-team-files` leaves the record (§C6.11), so a destroyed design is
still listed. There is no way to make a name unique in that listing — only to stop
asking by name.

### An arrival Penpot cannot match becomes a new design

The other half of the same question, and the two rows that were nearly three scenarios.
Penpot has no design for this file. That is TWO stored states — the file carries an id
nothing answers to, or it carries no id at all — and exactly one outcome: the archive
is imported (§6.33), a fresh id is minted, and the stale one (if there was one) is
overwritten.

**Nothing is lost, and that is the point.** A `sync` file IS the design — a complete,
valid `.penpot` archive that has been sitting in Nextcloud the whole time. What the
user does not get back is the id and the version history, which is why this cannot be
another row on the scenario above: `penpot_id | a new one, never the one it arrived
with` is the opposite claim to `the original id`, and no Examples table can hold both.

This is n8n's `Restoring when the n8n workflow was hard-deleted falls back to create`,
in Penpot's terms — except that n8n needs a second scenario for the never-tracked case
and this does not, because an import does not care whether the id it is replacing was
stale or absent.

### An arrival is told apart by what the file carries, never by its history

TWO ARRIVALS, AND IT TOOK THREE GOES TO SEE THAT. They started as *"Move an unmapped
design back into a project"* and *"Move an untracked design file into a project"* —
two spellings of "it was outside, now it is inside", giving a reader nothing to tell
them apart by.

**Two failed repairs first, both of the same kind, because the kind is easy to miss.**
Retitling one *"Move a design that **left a mapping** back into a project"*, and giving
another the step `And its design has been permanently deleted in Penpot`. Each of those
names how the file GOT into its state — and a scenario cannot assert its own backstory.
The `Given` places a file with an id at a path; nothing about it establishes that the
file was ever mapped, that it came back rather than arriving for the first time, or
that anybody deleted anything. The same state arrives by ordinary routes that make the
story false: **copy an unmapped file and move the copy in**, and you have a file whose
id names a design somebody else's file is still using, or no id at all.

They are also not reliable as implications. **Unmapped does not imply an id** — a file
can sit outside every mapping carrying no `penpot_id` whatsoever. And *"permanently
deleted"* is only one of several ways an id ends up naming nothing.

So the discriminator is not the history, not the folder, and not the gesture. It is
**what the file carries when it lands**, and there are only two answers that matter:

| the file carries | what the arrival does | scenario |
| --- | --- | --- |
| an id a design still answers to — trashed or live | REATTACHES: untrash if needed, re-file, re-stamp, id kept | `…when Penpot still has its design` |
| an id nothing answers to, or no id at all | IMPORTS (§6.33): a new id is minted and any stale one overwritten | `…when Penpot has no design for it` |

Two scenarios, two rows each, and the split between them is forced rather than
stylistic: one asserts `penpot_id | the original id` and the other `a new one, never
the one it arrived with`, so no Examples table could hold all four rows.

The words `unmapped` and `untracked` stay in the `Given`s where they are precise and
already load-bearing — `designs/delete.feature` has a `Trash an untracked design file`
scenario and the rule *"a file the app never mirrored is Nextcloud's alone"*. What
changed is that the first outline's `Given` says `carrying its Penpot id` out loud
rather than leaning on `unmapped` to imply it, the same way the duplicate scenarios
further down have always spelled out `carrying "<its id>"`.

### The two Penpot-side departures are not the Nextcloud drag, or each other

THREE SCENARIOS READ AS ONE IF YOU ONLY READ THE TITLES, and they were called `Move a
design out of a sync mapping in Penpot`, `Move a design out of a link mapping in
Penpot` and `Move a design out of every mapping`. The trailing *"in Penpot"* was doing
all the disambiguating, and it is the easiest three words in a title to skip.

They are separated by the `@in-penpot` / `@in-nextcloud` tag, which is the real answer
and is not visible while you are reading a title:

- **the drag** (`@in-nextcloud`) — a person moves the FILE in Nextcloud, out to an
  unmapped folder. The file goes where they put it; the design gets trashed.
- **the sync departure** (`@in-penpot`) — someone moves the DESIGN in Penpot's own UI,
  into a team this app does not map. The design is fine; it is the FILE that has lost
  its subject, so the mirror goes to the Nextcloud trash where the user decides.
- **the link departure** (`@in-penpot`) — the same gesture on a `link` mapping, and it
  leaves NO trash entry, because a restored pointer would point at nothing this mapping
  mirrors.

Retitled to lead with the actor — `Someone moves a design into an unmapped team in
Penpot` — because `someone` is already this spec's word for the far-side actor in
every `When` it appears in. Grafana carries the identical pair under the older titles
(`dashboards/move.feature`); this is a divergence from the sibling that improves on it
rather than drifting from it.

AND THE LINK DEPARTURE IS NOT THE REFUSAL EITHER. `Moving a link, or into a link
mapping, is refused` is a person being stopped by the guard in Nextcloud. Nothing
happens in Nextcloud here, so no guard is ever consulted — `MoveRules` has no say over
what someone does in Penpot's UI.

### A duplicate arriving in a project keeps the id already there

THE PERSON ANSWERS WHAT THE CONTENT SHOULD BE; the identity is never theirs to
pick. Nextcloud's conflict dialog offers keep-existing, keep-new or keep-both, and
all three questions are about BYTES. Whichever body wins, the file that stays at
that path goes on being the design it already was — because a project holds exactly
one file per design id, and the arrival's id is an accident of where it came from.

The Examples cross the three answers with the three identities an arrival can carry
(the same id, a different one, none at all) because the whole claim is that the
third column does not read the second. Ported from the Grafana sibling, which found
the bug this table exists to catch: an arrival carrying a stale id re-bound the
destination to a design nobody was looking at.

### Keeping both versions of a duplicate makes the arrival its own design

Keep-both is the one answer that leaves two files, so it is the one answer that
needs a second design. Nextcloud names the arrival "Turnbuckle (1)"; that file has
to become a design of its own rather than a second claim on the first, which is the
same rule copy.feature states from the other end.

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

### A refusal has to reach the person, and the listener cannot carry it

BOTH refusals above were invisible. `MoveGuardListener` throws
`AbortedEventException` with a message written for exactly this moment — named
rule, reason, way forward, translated through `IL10N` — and the person dragging
the folder saw a 403 with an empty body. The messages have been right the whole
time; nothing was delivering them.

Read out of a running Nextcloud rather than reasoned about, three frames deep:

- `OC\Files\Node\HookConnector::rename()` catches `AbortedEventException` by
  name, logs it, and sets `run = false`. The message goes to the log, and stops.
- `View::rename()` returns `false`, so `Sabre\...\Directory::moveInto()` answers
  `throw new \Sabre\DAV\Exception\Forbidden('')` — an empty string, by literal.
- `OC_Hook::emit()` wraps every slot in `catch (Throwable)` and CARRIES ON. Only
  `HintException` and `ServerNotAvailableException` are re-thrown.

That third frame is the trap, and it is why the obvious repair is wrong.
`AbortedEventException` is not one option among several — it is the ONLY thing a
listener on this route can throw that refuses anything at all. The Grafana
sibling measured the alternative in CI: swapping in `OCP\Files\ForbiddenException`
to rescue the message turned nine refusals into HTTP 201, allowed.

**So the rules are stated once and asked twice.** `MoveRules` answers with a
message or null. `MoveGuardListener` asks and aborts — that is the half reaching
`occ`, another app, a script, none of which go near Sabre. `LinkWriteGuardPlugin`
asks on `method:MOVE` and throws `Forbidden` with the reason — the only place a
readable 403 reaches a client. Same split `method:PUT` and `method:COPY` already
use in both siblings, arrived at the same way.

The scenarios did not change, and neither did the rules. What changed is that
`Then the move is refused` is now a claim about something the person can read.

### A design moved to another project in Penpot relocates its mirror

THE PRUNE MUST NOT FIRE. The design is still named by Penpot, just from a
different project — a reconciler that keyed on "not in this folder" instead
of "not in this team's listing" would trash the mirror and re-create it,
losing a `sync` file's archive on the way past.

---

## projects/move

`features/projects/move.feature`

REWRITTEN under §C6.38, and the old file's central claim is now the opposite of the
truth. It said `And Penpot is never contacted` — moving a project folder was purely
local, because the project's name was its leaf folder and position meant nothing.
With the name being the PATH, a move IS a rename, and Penpot is contacted every time.

Three of its scenarios also refused a move that should be allowed. `A project folder
cannot be moved out of its team folder`, `The project-folder refusal explains why`
and `A project folder cannot be moved into a different team's folder` were one rule
stated three times, and the rule was wrong: `move-project` crosses a team in one
call, exactly as it is for a design and exactly as Grafana allows a folder to cross
mappings.

**The parameters recorded here were wrong, and the correction is worth keeping.**
This note said `move-project` takes `{id, team-id}`. It takes
`{project-id, team-id}` — read off `schema:move-project` in
`app/rpc/commands/management.clj` in a running backend. The trap is that
`rename-project` and `delete-project` both take a bare `id` for the same object,
so the plausible guess is a 400 from a command defined a few lines away. This is
the third time a wire key has been recorded from memory and been wrong
(`rename-file`'s `id`, `duplicate-file`'s `file-id`), which is why
`PenpotClient::PARAMS` carries the confirmed shape and the reason it was
confirmed, one row at a time.

The same read settled `projects/copy`: **`duplicate-project` exists**
(`{project-id, name?}`, "Duplicate an entire project with all the files", since
1.16). The claim that it does not was in README's two-noun table and in
`projects/copy.feature`'s own closing comment.

### Moving a project folder renames the project

The path below the mapping is the name, so dragging `Traveller` into `Clients` makes
it `Clients/Traveller`. The id never changes — a move renames the project, it never
replaces it with a new one wearing the new path.

### A move high in the tree renames every project below it

THE COST OF THE PATH MODEL, stated where someone will meet it. Penpot has no parent
field, so there is no atomic re-parent: moving `/Penpot/foo` renames `foo/bar` and
`foo/bar/baz` and everything else named through it, one `rename-project` each.

Every one keeps its id, which is what makes a partial failure survivable — the ids
still name the same projects, and the next pull reconciles the names. It is also why
the reconciler must never read *the folder is not where the name says* as "move the
folder back": under this model Nextcloud's position is the newer fact.

### A project carries its team as well as its name

One move changing team and name together, keeping the id, the designs and their
history. `move-project` carries the team, so nothing is re-created to cross a
boundary — the same shape `move-files` gives a single design.

**`@unbuilt` FOR A REASON THAT IS NEITHER THE RULE NOR THE API.** §C6.38 built
`move-project` and `MotionService` calls it, and the three other scenarios in this
file went green on it. This one did not, and the wall is underneath both:

`Shared` is a **Team Folder**, so dragging `Penpot/Crossing` into it crosses a
storage boundary. Core does not fire `NodeRenamedEvent` for those — it is a
copy+delete underneath — and neither half routes a folder: `CopyListener` and
`DeleteListener` both take a `File`, and core fires no per-child event for the
designs inside. So nothing destructive happens. Nothing happens at all.

Measured in CI rather than reasoned about: the drag returns success and the project
is still in `Design Team` afterwards.

**The Background is not the problem, and must not be "fixed".** Pointing this
scenario at two admin-folder mappings would make it pass while testing something
nobody asked about — the rule under test is that a project carries its team, and
`the storage a mapping uses makes no difference to what a move is` is a claim this
file makes out loud one scenario earlier.

**The code owed is shared with `Move a folder of untracked designs into a team`**,
and with all three of `projects/copy`'s: every one of them is *a folder arriving
inside a mapping*, which this app currently has no way to notice. One capability,
five scenarios.

### A project folder that leaves every mapping stops being a mirror

Nothing is deleted in Penpot. The project stands, its designs stand, and the folder
simply stops being the thing that mirrors it — the same rule a single design leaving
its mapping already follows.

**The folder loses its marker; the design keeps its id.** The scenario's two rows
looked inconsistent and are not, and the difference is the resolver. Membership is
derived by walking UP (§6.29), so a `penpot_project_id` left on a folder now sitting
in unmapped space is not inert — it still answers for anything dropped beside it,
reporting a project with no team above it. A design's `penpot_id` answers for
nothing: it is a record of which design these bytes are, and it is what makes
dragging the file back a RETURN rather than an import, which is exactly what
`Move a design out of every mapping` asks for.

That scenario's row said `absent` for the design until §C6.38 was implemented, which
contradicted `designs/move.feature` and contradicted the sentence directly above it
in this note. The note won.

**What is not done yet, stated so nobody reads silence as coverage.** The designs
under an unmapped folder keep their `penpot_team_id` and their `sync` mode, so they
are half-unmapped. Finishing that is `designs/move.feature`'s `Move a design out of
every mapping`, which needs `PenpotMetadata` to be able to remove ONE key — it can
write keys and drop a whole record, and neither is what unmapping a design wants.
Doing it here only for designs that happen to sit under a moved project, while a
design dragged out on its own still keeps everything, would make the two paths
disagree in a new direction rather than fix the old one.

Worth knowing rather than asserting: the project still exists, so a later pull will
build a folder for it again at its own path. That is a consequence of two rules
agreeing, not a third rule, and it does not earn a scenario.

### A move never changes a project's mode

A mode belongs to the TEAM, and a move may not change one. One Outline and two
Examples blocks, the same shape `projects/copy` uses: the first block is the SOURCE
rule and is total — a link project has nowhere to go, its own team included — and the
second is the DESTINATION rule, which only needs a source the first has not refused.

### An emptied parent is reaped only when it holds nothing else

The clean-up half of a re-path, and the half that can destroy something if it is
wrong. After a project moves out, the folder it sat in may be left with nothing —
and it may have only ever existed because the project's name spelled it.

Reaped when it holds nothing AND carries no project id of its own. Kept the moment it
holds anything else, user files included. That is the same line Grafana draws for a
folder that lost its last dashboard, one gesture over: deleting a user's notes
because a Penpot project moved out from under them is not this app's call.

### A project renamed in Penpot moves its folder

The mirror of the rule above, and the reason both belong in one file. Penpot can
rename `Upstream` to `Clients/Upstream` in a single call; in Nextcloud that is a MOVE,
possibly several folders deep, possibly creating folders on the way.

THE FOLDER IS MOVED, NOT REPLACED, and the id is what makes that possible. The new
name says where the project belongs; the id says which folder already IS it. So the
reconciler has a before and an after: ensure the destination path exists, move that
folder into it, then clean up behind. Everything the folder held travels with it,
the user's own files included — an earlier cut said the old path was simply "gone
from Nextcloud", which reads as a delete and would have destroyed anything else
sitting in there.

CHANGING TEAM IS A ROW OF THE SAME OUTLINE, not a scenario of its own. Penpot can
rename and re-team in one gesture where a Files drag can only do one at a time, so
the destination team is an INPUT and the outcome — the folder is gone from where it
was and stands, with its id, where the new name and team put it — is identical.

Grafana states it the same way (`Move a folder in Grafana`, whose Examples caption is
*"Grafana can move and rename in one call where a Files gesture cannot"*), and it has
no separate cross-mapping scenario on the remote side either.

---

## designs/open-with

**`@blocked` throughout — no browser, and that is the whole file.** Every scenario
here asserts a context-menu entry, what a plain click does, or that an entry is
*hidden*. All three are DOM behaviour, and the occ+DAV harness has no DOM.

**The logic behind the action is covered, just not here.**
`tests/js/files-helpers.test.js` pins the helpers the file action is built from —
`readMetadata`, `getPenpotId`, `getPenpotMode` and `buildUrl` — including the two
things most likely to break it: the stored wire value `reference` being translated
back to `link` before any caller sees it, and `buildUrl` never emitting a
`file-id` without a `team-id`, which is the shape that actually errored once. So
what is missing is the wiring between a real menu and those helpers, not the
decisions they make.

**Marked at the `Feature:` level rather than scenario by scenario**, which Behat
applies to every scenario below it — the same way nextcloud-n8n marks the whole of
its `uninstall.feature`. One line moves when a browser harness arrives.


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


### Opening needs a team id, so an unmapped file opens nothing

The deep link requires BOTH ids. Penpot will not open a workspace without a team —
its own legacy-route redirect makes an RPC round trip purely to look one up, which is
the proof — so `?file-id=` alone lands on an internal error.

A file that has left every mapping keeps its `penpot_id` and loses its
`penpot_team_id` (`designs/move.feature`), so there is nothing to build a link from.
The design may well still exist over there; this app just no longer knows which team
to open it in.

The code reaches the same place from the other direction: `enabled()` is gated on the
file TYPE rather than the mode or the id, because on the first folder after a page
load the listing can arrive before the app's DAV property is registered, and an action
that flickers once a session is worse than one that occasionally no-ops. So the menu
entry appears and the CLICK is where it finds out.

### An untracked design file opens nothing rather than failing

Reaching a click without both ids means the file is a `.penpot` this app does not
track — a state, not a failure. So the click is a silent no-op rather than an error
toast: nothing the user did was wrong, and there is nothing for them to fix.

This is the other half of not gating the action on the id. The action is offered on
the file TYPE, which is cheap and never flickers; the click is where the app finds
out whether it can actually go anywhere.

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

### Renaming a link never renames the design

A `link` file's name comes from Penpot, so a rename made in Nextcloud would survive
exactly until the next pull re-derived the filename and quietly undid it. A rename
undone later is worse than one refused now: the user is neither told no nor allowed
to keep it. Refused, in the same voice the move guard uses.

Both siblings state this rule; penpot had the mirroring half (`Rename a design in
Penpot` covers a link) and not the refusing half, so a link could be renamed locally
and silently reverted.

### Nextcloud's collision suffix starts at (2)

**Read out of the running server, not remembered.** `OC\Files\Node\Folder::getNonExistingName()`
sets `$counter = 2` for the first collision and counts up, so a second
`Original.penpot` in one folder is `Original (2).penpot` — there is no `(1)`.
`PullService::freeName()` has always matched that; three scenarios did not.

`designs/copy.feature` asked for `Original (1)` and for `Original`/`(1)`/`(2)`;
`designs/rename.feature` asked for `Alpha (1)`. All three are corrected to what
Nextcloud actually produces, which is what they meant all along: their own note
says **the suffix is Nextcloud's alone**, and deferring to Nextcloud means
deferring to its numbering too.

Worth recording how it hid for three CI cycles. The copy row PASSED, because the
harness placed the copy itself — WebDAV COPY overwrites rather than suffixing, so
`CopySteps::freeCopyName()` had to choose a name, and it chose `(1)` to match the
table. A scenario asserting a name the harness picked proves nothing about the
app; it was two halves of the same wrong assumption agreeing with each other. The
helper now starts where core starts, so a future disagreement fails instead.

### The suffix is Nextcloud's alone

Two designs may share a name in Penpot; two files in one folder may not. When a
design is renamed over there onto a name a sibling already holds, the file that HELD
the name keeps it and the arriving one takes "(1)".

The direction matters and is the whole scenario: suffixing the incumbent instead
would rename a file the user never touched, and the next pull would swap them back.
Penpot is perfectly happy with two "Alpha"s and never sees the suffix at all.

### An empty file name is refused before it is sent

**WITHDRAWN as a scenario — the note stands, the test does not.**
`Rename a design to a name Penpot cannot hold` was removed from
`designs/rename.feature` because it is unreachable through the gesture it
describes: **Nextcloud's filename rules are strictly tighter than Penpot's**, so a
rename the Files app or WebDAV will accept is one Penpot would accept too. There
is no name a user can type that gets far enough to be refused for Penpot's sake.

The paragraph below is why, and it is the same fact read from the other end — it
was already written here before the scenario was questioned. The guard is still
worth having (see the last line: a better message and a saved round trip), and the
pull direction still needs one, which is the "/" section that follows. What is gone
is a *rename* scenario asserting a refusal no rename can trigger.

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

### A link mapping authors nothing

A link folder is a read-only projection of Penpot. A file appearing in one is a
local file and can never become the design it looks like — so the app refuses the
creation rather than leaving a `.penpot` sitting there looking managed. Creating the
design in Penpot is how a link folder gains a file.

Refused at every depth, which is why it is an Outline: a project folder nested under
a link mapping is no more writable than the mapping's own root.

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

### A created design is attributed to the acting user when possible — WITHDRAWN

**The two scenarios this note describes were removed, and the rule was not.** They
were judged low quality and pulled to be redone properly rather than left standing
as a spec nobody would want to build to. Nothing points at this anchor now; it is
kept because the RULE is still wanted and the reasoning below is still the
argument for it.

What has to come back is a statement of authorship at creation — with a personal
token the design is the user's, without one it is the service account's, and in
the second case the user is TOLD. The shape below is the part worth keeping; the
scenarios that carried it are not.

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

**AND IT ABOLISHED A STATE TWO OTHER FILES WERE DESCRIBING.** `designs/delete.feature`
and `designs/rename.feature` each ran an outline over *"inside a mapping and outside
every mapping alike"*, asserting that an untracked `.penpot` stays untracked in both.
The second half still holds. The first cannot: an archive inside a mapping is adopted
the moment it lands, so there is no untracked design file there to trash or rename.

Measured rather than argued — CI failed exactly those two rows and passed their
`Scratch` twins, on the commit that turned the import on. They are removed, for the
same reason `Rename a design to a name Penpot cannot hold` was: a scenario whose
premise the rules make unreachable is not a test, and the rule it was guarding is
stated where it is true.

What survives is the narrower and still-real case the anchor above ends on: a file
PENPOT will not take stays untracked wherever it is. That is the only way a `.penpot`
inside a mapping is Nextcloud's alone now.

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

### There is nowhere for a failure to be reported to

**BUILT — and the wall moved rather than disappeared.** This section used to open
*"this app has no notifier, and every 'the failure is reported to the user' in the
spec is waiting on that one missing class."* That is now `lib/Notification/Notifier.php`
plus `lib/Service/SyncNotifier.php`, the same pair both siblings ship, registered
in `Application::register()` and raised from two places: `ImportService` when Penpot
refuses an archive, and `NodeRenamedListener` when a move cannot be pushed.

So the scenarios moved `@unbuilt` → `@todo`, not `@unbuilt` → live, and the reason
is worth being exact about because it is a different wall:

- **The behaviour exists.** The fault is arrangeable from `occ` (point the instance
  URL at a dead host, hand Penpot an archive it rejects), the report is raised, and
  the user gets a bell entry naming what Penpot said.
- **The suite cannot read a bell entry.** Notifications are stored by the
  `notifications` APP, which is not part of the `nextcloud/server` checkout CI
  installs from — and reading them means the OCS API rather than `occ`, which this
  suite has no transport for. Installing an extra app to observe a courtesy channel
  is a bigger change to the harness than the assertion is worth.
- **AND NEITHER SIBLING TESTS THIS EITHER.** Checked before deciding: n8n and
  grafana both ship the notifier pair and have **zero** Gherkin scenarios and zero
  integration steps that observe a notification. `@todo` here is exactly at parity
  — the code is real and the assertion is owed.

`projects/create.feature`'s *"the user is notified that the project could not be
placed"* is now unblocked in the same sense: the channel exists for it to use.

### Penpot's destroy leaves the row behind

**§C6.11 recorded that `permanently-delete-team-files` puts a design "not in
Penpot's trash". True, and only until something touches it again.** Read off the
running backend rather than inferred: the command's own docstring says *"Mark the
specified files to be deleted **immediatelly**"*, and what it does is
`db/update! :file {:deleted-at request-at}`. It sets the clock to NOW instead of a
week out. **The row survives**; a collector removes it later.

So `delete-file` on that same id still succeeds — `mark-file-deleted` re-stamps
`deleted_at` a week into the future — and the design reappears in
`get-team-deleted-files`. Which is exactly what `Trash a design that is already
gone from Penpot` does: destroy the design, then trash the file, which makes the
app call `delete-file` on an id it has every reason to think is live.

That leaves the scenario `@blocked` rather than `@unbuilt`, and the distinction is
the point. The app is not wrong. Avoiding this would mean a pre-flight existence
check before every delete — a wasted round trip on a hot path, to guard against a
state a user reaches only by destroying a design in Penpot and then trashing its
mirror before the next sync. The harness cannot hold the state still either: there
is no id Penpot will report as *gone* rather than *deleted*.

Measured across three CI cycles: the destroy was confirmed by re-reading (the
design did leave the listing), and it came back after the app's own delete.

### Deleting a mirror moves the design into Penpot's trash

Both sides go soft together: the design lands in Penpot's own trash keeping its id,
revision and history, and the file lands in the Nextcloud trash keeping its
metadata. Nothing here is irreversible, which is what makes it safe to do without
asking — the irreversible half is the purge.

### A trash Penpot cannot take is not aborted today

**The one place this app's failure rule and the spec disagree, and it is worth
being precise about why.** §6.18 rule 3 — *a remote failure never rewrites local
state* — is why a failed rename still stands locally and a failed copy stays as an
untracked file. `DeletionService::onTrashed()` follows it: `delete-file` throws, the
warning is logged, and the local delete stands. `DeleteListener::attempt()` says so
in as many words: *"the local delete stands"*.

`Trash a design while Penpot is unreachable` asks for the opposite — the trash
aborted, the file left where it was, its metadata intact — and it is right to,
because **a delete is the one gesture where the rule inverts**. Everywhere else the
local state is the thing the user just made and the remote is a mirror catching up.
Here the local state is the only *pointer* to a design that still exists: remove it
while Penpot keeps the design, and the design is stranded with nothing naming it
until the next pull mirrors it back as a surprise.

**What it would take.** The Penpot delete has to move ahead of the local one — try
`delete-file` first, and abort the Nextcloud delete if it fails, which is the shape
`MoveGuardListener` already uses for refusals and which `LinkWriteGuardPlugin::onDelete()`
can already carry a message for. Not a large change; it is left out of the round
that promoted the rest of `designs/delete.feature` because it reorders the delete
path for every caller — folders, restore and purge included — and that deserves its
own PR rather than riding along with thirteen test promotions.

### A Team Folder's trash reaches Penpot after all

`purge.feature` carried two scenarios for one gesture: emptying the trash of a
plain folder destroyed the design in Penpot, while emptying a Team Folder's trash
did not — groupfolders was believed to raise no event this app could hear, so the
design was left in Penpot's trash to age out.

**Installing Team Folders on every leg disproved it.** With the storage stated in
the mapping table rather than decided by the CI leg, the two cases ran in the same
suite for the first time, and the Team Folder purge reached Penpot exactly like the
plain one. The step guarding the gap said what to do if that ever happened, and it
happened the first time the two were run together.

So there is one scenario now. This is the nuance the storage change was made to
catch, and it caught it immediately: a limitation the specs had recorded as fact,
provable only by running both halves in one place.

### A Team Folder's trash emits no purge signal

The purge is one behaviour and one scenario, with the storage kind as a ROW — but the
two rows do not reach the app the same way. A Team Folder's trash raises no purge
hook at all; both siblings ride the filecache entry removal instead
(`TeamFolderPurgeListener`), and this app has no such listener yet.

Recorded here rather than as a second scenario, because the mechanism is a HOW. If
the Team Folder row fails and the admin-folder row passes, this is why.

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

**AND THE BUTTON OUTLIVED THE SPEC BY TWO COURSES.** Retiring the scenarios left
`templates/sync_settings.php` still rendering a *disabled* "Purge Nextcloud files"
between the two working buttons, with a tooltip promising it was *"available once
the purge machine lands"*, two settings-hint paragraphs describing what it would
spare, and matching notes in `SyncSettings.php` and `js/sync-settings.js`. Nothing
was ever wired to it — no route, no controller action, no `occ` command — so this
was pure dead surface, and the only thing it did was tell every admin who read the
panel that a feature was coming which had already been cancelled.

The general rule, since this is the second time a *present-but-disabled* control has
gone stale here: the argument for shipping one is that the finished shape of the
section is visible early and enabling it later is deleting an attribute. That holds
exactly as long as somebody still intends to enable it. The sync button earned it and
went live; this one's feature was cancelled underneath it, and at that moment the
button stopped being a preview and became a lie. **When a feature is retired, the
retirement includes its UI.**

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

WHERE THE LINE SITS BETWEEN THIS FILE AND `projects/move.feature`, now that §C6.38
has made a project's name its path: **rename is the folder keeping its parent, move
is the parent changing.** Split by the NEXTCLOUD outcome, because in Penpot both are
one `rename-project` call and there is nothing to tell them apart over there.

That is Grafana's line too, and it is why its `Move a folder in Grafana` Outline is
captioned about doing both in one call — the remote side has one gesture where the
Files app has two.

REWRITTEN, and eight of the eleven old scenarios went. Four described the `/` guard —
`The app never sends a slash to Penpot`, `A project whose name contains a slash is
skipped`, `Renaming the project in Penpot fixes it on the next pull`, `The app never
invents a substitute name`. All are obsolete: a slash is now a path, the app sends
them deliberately, and what remains of the guard lives in `projects/create`.

The other four were the familiar shapes. `Renaming a project folder does not touch
the designs inside it` asserted that bystanders were unharmed. `A project rename is
attributed to the acting user` asserted `"rename-project" is called using that user's
own token` — a call, not a behaviour, and the per-user axis besides. `One unmappable
project does not block the rest of the team` was a bystander assertion on an obsolete
rule. `An empty folder name is refused` tested a rule Nextcloud already enforces
before the app ever hears about it.

### Renaming a project folder renames the project in Penpot

The last segment of the path changed, so the last segment of the name changes. The id
never moves, which is the whole anti-break claim, and the designs inside are untouched
because nothing about them changed — that is a consequence, not a second scenario.

### A project renamed in Penpot keeps its folder where it is

Renamed IN PLACE. Nextcloud is authoritative for layout, so the pull renames the
folder where the user left it and never drags it to a canonical path.

If the new name changes the PATH rather than just the last segment, that is a move in
Nextcloud and `projects/move.feature` owns it. One Penpot call, two Nextcloud
outcomes, and the outcome is what decides which file states it.

### A failed project rename leaves the local rename standing

Nextcloud has already renamed the folder, and reverting would fight the user over a
gesture that succeeded locally. The divergence is reported and the next pull settles
it — the same rule every other failed propagation in this app follows.

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

A file this app never mirrored is Nextcloud's alone, coming or going. Restoring it
puts it back and Penpot never hears about it.

**THE IN-MAPPING ROW IS GONE, AND §6.33 IS WHY.** The Examples used to read *"inside
a mapping and outside every mapping alike"*, crossing `Penpot/Stay Put/Loose.penpot`
with `Scratch/Loose.penpot`. That was true when an untracked `.penpot` sitting in a
mapped folder was simply ignored. It is not true now: an archive arriving inside a
mapping is IMPORTED and becomes a real design, so the first row cannot get as far as
the restore — the file is tracked before the scenario's `When` ever runs, and
`the file holds no Penpot metadata at all` is false by the time it is asked.

`designs/delete.feature` had already made exactly this correction, and says so in
its own Examples heading: *"outside every mapping, which is the only place one can
still be."* Restore now matches. The rule is unchanged; the input space shrank.

**AND SO DOES PURGE, which was missed on the first pass and cost a round.** The
same stale row sat in `designs/purge.feature`, and its failure was more alarming
than restore's: the file was imported on arrival, so purging it DESTROYED A REAL
DESIGN and `no design is deleted in Penpot` was simply true-and-failing. Reading
the log made it obvious — `adopted an archive as a Penpot design`, then
`permanently deleted a design in Penpot` — but the assertion's wording sent the
first look at the wrong scenario entirely.

THE RULE, since three files carried this row and two of them were fixed one at a
time: **§6.33 removed "untracked, inside a mapping" from the input space
everywhere at once.** Any Examples column crossing in-mapping with out-of-mapping
for an untracked `.penpot` is stale by construction, in every feature file.

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

## projects/purge

`features/projects/purge.feature`

Emptying the Nextcloud trash of a project folder finishes what the trashing started:
the designs it held leave Penpot's trash for good. Penpot's trash is what made the
folder delete reversible, and this is the gesture that ends that.

### A purge reaches every project the folder held

Recursive is recursive, and under §C6.38 that reaches further than it looks. A trashed
`/Penpot/Team` may have spelled `Team/Sub` as well as `Team`, so one purge can be the
last word on several projects at once. The Examples say so with a nested row rather
than with a second scenario, because the outcome is identical however many it reached.

A LINK TEAM HAS NO SCENARIO HERE, deliberately. Its project folders cannot be trashed
(`projects/delete`), so they can never be in the trash to purge. Grafana states the
same absence in the same place, for the same reason.

Two scenarios that Grafana carries are also deliberately absent. `while other
dashboards are parked` asserts that a purge did not reach things it was never given —
a bystander claim, and the same one already retired from `designs/purge`. Purging a
plain folder that was never a project asserts the pre-state: with no designs there was
never anything to finish.

### Why the not-in-the-trash fork has one row here and two in Grafana

Grafana's twin is an Outline — *"two ways to get here, and the purge cannot tell
them apart"* — crossing `back in the folder` with `gone from Grafana entirely`.
Penpot carries only the first, and the reason is Penpot's, not an omission.

`permanently-delete-team-files` STAMPS `deleted_at` AND LEAVES THE ROW (§C6.11).
So a design that was erased is still returned by `get-team-deleted-files`, exactly
like one that is merely trashed. "Gone from Penpot entirely" is therefore not a
state this app can observe, let alone one a scenario can arrange and then assert
`its design is not in Penpot's trash` about — the sentence would be false for the
very case it was meant to describe.

Which leaves one reachable way to be out of Penpot's trash: somebody restored the
design over there. That is the row the scenario keeps.

The same fact is why {@see thatFilesDesignIsPermanentlyDeletedFromPenpot} asks the
PROJECT listing rather than the trash: absent from its project is what "erased"
means here, and it is the same listing the pull reads.

### Emptying Penpot's trash reaches back into the Nextcloud trash

Those designs were the only route the project had back — there is no `restore-project`
call, measured — so once they are gone the trashed folder has nothing left to be
restored to, and it goes too.

**@unbuilt, AND THE WALL IS THIS APP'S REACH, NOT PENPOT'S.** Emptying Penpot's
trash is arrangeable and the pull sees the result perfectly well. What no code here
can do is remove an ENTRY FROM THE NEXTCLOUD TRASH. Nothing in `lib/` reads that
trash at all: `TrashControl` only pauses it, and the listeners react to gestures
someone else made. `PullService`'s own docblock has carried the deferral since it
was written — *"adopting a mirror out of the Nextcloud trash (§6.37) … needs
`files_trashbin` and is its own slice"* — and this scenario is the other half of
exactly that slice.

Worth separating from the walls it sits beside. It is not a harness limit: the
suite can empty Penpot's trash and can read Nextcloud's. It is not a reporting
gap either. The behaviour is simply absent, which is what `@unbuilt` means, and
building it means giving this app a reason to open the trash for the first time.

### A Penpot purge may not destroy what was never Penpot's

The restraint half, and the reason the rule above needs a second scenario rather than
a row: the outcome differs in KIND. A trashed folder holding a spreadsheet STAYS in
the Nextcloud trash, holding it. A file with no far side cannot be destroyed by
something that happened on the far side.

The same line `projects/delete` draws for a project deleted in Penpot, one gesture
later.

---

## projects/restore

`features/projects/restore.feature`

TWO SCENARIOS, and the consolidation is the point. Restoring a project folder always
ends the same way — Penpot holds the project again — and the only thing that varies
is whether it wears the id it left with. That is a VALUE, so it is a column.

MEASURED against a live Penpot, and the measurements are what collapsed the file:

- `delete-project` is soft. The project leaves `get-all-projects` at once and its
  FILES appear in `get-team-deleted-files`, stamped `willBeDeletedAt` about seven days
  out. The project itself appears in no trash listing anywhere — only its files do.
- **There is no `restore-project` RPC.** It answers 404. A project's only route back
  is `restore-deleted-team-files` on a file, which revives the project as a side
  effect — confirmed by restoring one file and watching its project reappear.
- `get-all-projects` filters deleted projects correctly. The §6.42 note about
  `get-projects` never filtering `deleted_at` does not apply to the call this app
  already uses.

### Restoring a project folder brings the project back

Four rows, one outcome. Storage is a row for the usual reason. The other three are the
three things Penpot can still be holding when the folder comes out of the trash:

- its designs, still in Penpot's trash → the original id comes home;
- its designs, already purged → nothing to come back through, so a new one is made;
- nothing, because the project never held a design → same, for the same reason.

The last is not an exotic case. A project created in Penpot arrives as a Nextcloud
folder whether or not it holds designs, so trashing that folder deletes a project no
file can revive. It reaches the same end state by a different road, which is exactly
what an Examples row is for.

### Restoring one design brings its project with it

Penpot clears a project's deletion when any file inside it comes back, so restoring
ONE design revives the project and lifts its folder out of the Nextcloud trash.
Restoring two, or a hundred, says nothing further — which is why there is no separate
scenario for restoring "the project's designs". You cannot restore a project; you can
only restore files, one set at a time, and the first one already did the interesting
part.

RETIRED — `Restore a trashed project's designs in Penpot, where the folder held other
files`. The claim was that a folder comes out of the Nextcloud trash whole, spreadsheet
included. True, but it is Nextcloud's doing rather than this app's, and it is the same
restore as any other. If it earns a scenario anywhere it is `designs/restore`, where a
single design coming back is the subject.

---
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

THREE SCENARIOS, DOWN FROM ELEVEN, and the shape came from the siblings: grafana and
n8n both carry two, because the whole tree is the assertion and everything else is a
row of the Background.

### Sync-now scope

One button, one scope: every mapping. The admin's button and the scheduled run are the
same behaviour started two ways, which is why they are two rows of one Outline rather
than two scenarios — the actor is an input and the tree that comes out is identical.

There is no per-mapping sync-now. A mapping that needs its own run needs it because
something is wrong with it, and that is a question for `connection/admin.feature`.

### A background is a picture, not a story

Both sides are declared as state — what Penpot holds, what Nextcloud holds, what is
mapped — and the Background never says how they came to disagree. `/Penpot/Cogs` and
`/Penpot/notes.txt` are simply there before the sync runs; nothing narrates a user
having made them.

### The tree is the assertion

One `Nextcloud holds exactly these resources:` table replaces five scenarios, because
every one of them was a claim about the same tree:

- **adoption** — `/Penpot/Cogs` was in the Background and is in the result, tagged.
  No `Cogs (2)` appears, which is the whole of the old `A folder already named like a
  Penpot project is adopted, not duplicated`;
- **untouched content** — `notes.txt` and `plan.txt` go in and come out, which is the
  whole of `A sync leaves content it does not manage alone`;
- **Drafts** — `Loose Idea.penpot` surfaces at the mapping root and no `Drafts` folder
  is created, because Drafts is a state rather than a place;
- **the path model** — the project named `Region/Deep` arrives as two folders, and only
  the deeper one is tagged. `/Penpot/Region` holds no design and is nobody's project;
- **every storage kind and mode at once** — an admin folder, a Team Folder and a link
  team, in one table.

`exactly` is what makes it work. A table that only listed what should appear could not
have caught a stray `Cogs (2)` sitting beside the real one.

### The first sync to Penpot makes designs of the files already there

THE OTHER DIRECTION EXISTS, and an earlier cut of this section said it could not.
That was a misreading of §6.1: what is forbidden is pushing SHAPE DATA into a design
Penpot already has. Creating a project, renaming one, importing a whole archive as a
new design — the app does all of these, and the gesture features are full of them.

So sync-now has two buttons, as both siblings do. The push takes a `.penpot` sitting
in a mapped folder that Penpot has never seen and makes a design of it, in the project
the folder spells. It never touches a design Penpot already holds.

A link team is deliberately absent from the push's fixtures. Its contents come from
Penpot and from nowhere else, so there is nothing for a push to do there — and putting
an untracked file under one would have been asking a question `designs/create` already
refuses.

### RETIRED — six scenarios, and what happened to each

| scenario | why it went |
|---|---|
| A folder already named like a Penpot project is adopted | now a Background row and a result row |
| A sync leaves content it does not manage alone | same — `notes.txt` goes in and comes out |
| A sync that cannot finish says so, and says why | `<what is wrong>` was a whole clause in a placeholder, and a connection failure belongs to `connection/admin.feature` |
| One failure never costs the rest of the sync | `<one thing fails>` likewise, and neither sibling states it |
| A sync that dies halfway leaves every file whole | @blocked with no fault injection and no sibling equivalent |
| A second sync started while one is running does not queue another | a negative about a thing that must not happen, @blocked, and absent from both siblings |
| A user syncs their own personal team | parked with the rest of the per-user work, to be done across all three apps at once |
| Two Penpot projects in one team sharing a name | the collision rule now lives with the naming rule in `projects/create` |

The old Outline also varied the mapped FOLDER by actor — `All Mappings` for the admin,
`On Schedule` for the schedule — so the two rows never touched the same tree. That is a
fixture working around a collision, not an input the behaviour depends on.

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
