<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Feature notes

Why each scenario in `features/**/*.feature` is the shape it is — one section per
feature file, in the present tense.

## This is the middle of a cascade

Three documents describe this app's behaviour, and each holds one level of detail.
Every level links to the next, so you can stop at the depth your question needs.

| | Document | Holds |
|---|---|---|
| 1 | [`features/**/*.feature`](.) | **The specification.** What the app does, in plain language. Every scenario ends with a `# notes:` pointer to its section here. |
| 2 | **this file** | **The reasoning, as of today.** What a scenario encodes, what it deliberately leaves out, where its edges are. Every section opens with a `saga:` pointer to the decision behind it. |
| 3 | [`saga/`](../saga/) | **The history.** What was decided, what it replaced, and the live-instance evidence that settled it. |

**Level 2 is present tense, and that is the rule that keeps it useful.** A note
that opens *"this used to…"*, describes a scenario that no longer exists, or argues
against a design nobody is proposing belongs one level down. Put it in the saga and
leave a pointer. A retired decision sitting in a working document reads exactly like
a live one — which is how the same withdrawn mechanism kept getting proposed for
three rounds ([Chapter 3, Round 10](../saga/Chapter_3_Building_To_Plan.md#round-10--the-rules-own-edge-and-a-leftover-that-argued-for-itself)).

Seven retired feature files used to be documented here. They are now in
[Chapter 3, Round 11](../saga/Chapter_3_Building_To_Plan.md#round-11--the-docs-stop-carrying-history-and-gain-a-direction),
whole — including where every one of their scenarios went.

## The budget is two lines, and CI enforces it

A comment block in a `.feature` may carry at most two lines of prose; anything
longer belongs here, behind a `# notes: AGENTS.md#anchor` breadcrumb.
`tests/integration/bin/check-notes-anchors.sh` checks both halves — that every
breadcrumb resolves to a real heading, and that no block is over budget — because
both rot silently. Rename a scenario and its anchor stops matching with nothing to
notice; let prose creep back and the spec stops being readable, which is how this
file came to be as long as the suite it explains.

The checker proves a pointer **lands**, not that it lands somewhere true. Two
scenarios spent Chapter 3 pointing into sections about retired feature files, green
the whole way. When you move a section, read what points at it.

For how the suite is organised — the nouns, the tags, which scenarios CI runs and
why — see [README.md](README.md).

> If you change a behaviour, change the note that explains it in the same commit.
> A note describing the old behaviour is worse than no note.

## connection/admin

`features/connection/admin.feature`

saga: [§6.11 the Instance card](../saga/Chapter_1_First_Contact.md#611--decision-mostly-locked-a-dedicated-instance-settings-card-url-only-split-from-the-credential-question) · [§6.18 the access model](../saga/Chapter_1_First_Contact.md#618--decision-locked-the-access-model--a-required-service-account-reads-an-optional-personal-token-writes-as-you)

CONFIGURING THE APP IS ONE ACT, and this file is two scenarios: it works, or it
does not and says which field is wrong.

### connection/admin

**This replaced a 31-scenario `connection/admin.feature`** — itself
`connection/admin.feature` with `connection/personal.feature` folded in. It broke
almost every rule this suite has:

| | |
|---|---|
| five scenarios with **no `When` at all** | "The URL card carries no credential field", "Users do not author their own team mappings", "The app assumes one Nextcloud user maps to one Penpot account" — form structure, a capability that will never exist, and an assertion that a page's *prose documents an assumption* |
| two scenarios with **two `When`s** | both "distinguishes unset from rejected", each rebuilding its pre-state by performing another action |
| three duplicate pairs | the fold-in created them, and nothing deduped |
| thirteen `@blocked` with no capability named | the one thing `README.md` requires of the tag |

THE CONNECTION IS ONE FACT, SO IT IS ONE TABLE. The URL, the credential and the
schedule are all inputs to "the app is connected", not three behaviours. The
schedule especially: an interval is a setting, not something a person performs, so
it gets a column rather than a scenario.

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

saga: [§6.18 the access model](../saga/Chapter_1_First_Contact.md#618--decision-locked-the-access-model--a-required-service-account-reads-an-optional-personal-token-writes-as-you) · [§6.12 what a personal token is for](../saga/Chapter_1_First_Contact.md#612--refinement-of-69-user-tokens-do-the-real-work-the-admin-token-is-optional-and-read-only--but-its-reach-is-capped-by-team-membership-not-by-us)

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

That end state is why personal projects get no feature file of their own: once the
home root carries the team, every other personal behaviour is the ordinary one, and
`designs/` already describes it.

### A user clears their token

THE MAPPING CANNOT OUTLIVE THE TOKEN, and nothing is deleted when it goes. The
folders and their archives stay exactly where they are — losing a credential is
never evidence that content is gone, the same rule the service account follows.

The third `And` is what makes it a real end state rather than a tidy-up: a new
`.penpot` file made at the home root is inert again, exactly as it was before the
token existed. The mapping is gone, not merely idle.

## mapping/create

`features/mapping/create.feature`

saga: [§6.24 a mapping is a team](../saga/Chapter_1_First_Contact.md#624--decision-locked-the-mapping-is-a-team-projects-are-mirrored-not-mapped) · [§6.13 Team Folder or shared folder](../saga/Chapter_1_First_Contact.md#613--decision-locked-610-ratified--team-folder-or-shared-folder-fallback-mounts-a-team-one-level-of-real-subfolders-are-projects-tolerating-non-penpot-content--and-mapping-is-admin-tightened-not-per-user-open) · [§C6.31 a form is not a set of behaviours](../saga/Chapter_2_The_Colony.md#c631--a-form-is-not-a-set-of-behaviours-and-a-default-has-to-work)

"Admin makes a mapping" — the mapping list in admin settings, driven over
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
    (saga §6.29) — there is no depth cap.
**Projects cannot be mapped individually**, and not by preference: the next pull
would immediately recreate any subfolder you removed. One mapping object, one
lifecycle.

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

**The folder name does NOT track Penpot's team name.** This is a two-name object:
the admin names the folder, Penpot names the team, and the scenarios below pin them
apart.

MODE IS THE MAPPING'S, AND ONLY THE MAPPING'S (saga §6.22, amended): a mapping
carries the mode its files get ("link" unless set otherwise), and it is immutable
once created. A file's mode follows entirely from the mapping it was mirrored
under; changing it means removing the mapping and mapping the team again. **There
is no per-file override.**

WHAT'S DELIBERATELY NOT HERE: creating a NEW Penpot team or project FROM Nextcloud
is a separate, still-open fork.

<!-- A per-file mode override existed and was removed; the fork above was
     `team-import.feature`'s. Both are in saga Chapter 3, Round 11
     (../saga/Chapter_3_Building_To_Plan.md#round-11--the-docs-stop-carrying-history-and-gain-a-direction). -->

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
submits this mapping:" and a table. Saving the form, being refused because the folder
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
their names and the designs in them all arrive with the
FIRST SYNC, which is `sync-now.feature`'s. "Project folder names always match their
Penpot projects" and "Two Penpot projects in one team sharing a name" live there for
that reason: read here, they would suggest that mapping a team produces a tree.

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

AND EVERY ROW ASSERTS ALL FOUR FIELDS, so a row that sets the mode is also proving
it did not disturb the folder. Rows 1 and 2 together are the whole of "the folder name is the
admin's, and defaults to the team's" — the two names are independent, and it
costs a row rather than a scenario. The last row is a Team Folder named
exactly as its team, which is legal and worth pinning: nothing about the
storage backend constrains the name.

THERE IS NO "folder mode" COLUMN, because there is no such field (§C6.36): one
unimplemented value beside one implemented one is not a choice, and a form field
nobody can meaningfully fill in does not earn a column. The design question it stood
for is open in the saga ([§6.53](../saga/Chapter_1_First_Contact.md#653--decision-locked-folder-mode-is-a-per-mapping-immutable-choice--and-it-dissolves-the--problem)).

### A team may only be mapped once

`getByTeamId()` refuses a team that already has a mapping. A mapping IS its team
(saga §6.24), so a second one would be two answers to the same question — which
folder does this team mirror into.

THE TEAM BEING SUBMITTED IS ALWAYS STATED, and that is the rule the two reuse
scenarios follow rather than a difference between them. Here `Northwind` is both
the incumbent and the team being submitted, so its existence is load-bearing and
said out loud; in the folder scenario the submitted team is `Bundt Cake`, which is
named there, and `Northwind` is only the incumbent — carried by the mapping
statement, which creates it.

THE REFUSAL NAMES WHICH SIDE THE CLASH IS ON. "The team is already mapped" alone
leaves an admin looking at the wrong half of the form; "to another folder" says
where to go and look.

### A folder may only be mapped once

`assertFolderUnique()` refuses a folder some other mapping already uses, for the
mirror-image reason: a folder can only mirror one team, and two mappings pointed at
one folder would each prune what the other wrote.

### A team that cannot be reached cannot be mapped

Better an honest refusal than a mapping that silently pulls nothing.

ONE SCENARIO FOR TWO CAUSES, because there is only one behaviour. `get-teams` is
membership-scoped (§6.12), so a team that does not exist and a team the service
account was never invited to arrive identically: the lookup returns nothing.

**Do not split them.** A second scenario for the invited case tests Penpot's
permission model rather than this app's, and cannot be arranged honestly anyway —
the harness has one Penpot account, and `a penpot team named "…" exists` is
find-or-create THROUGH it, so anything it names is visible by construction.

**And the refusal must not name one cause.** "is not visible to the service account"
sends an admin looking for an invite to a team that was never there. It says the
team was not found using the given credentials, and offers both explanations.

IT NEVER SPEAKS IN IDS. Nobody types a team id to make a mapping — the UI is a drop
down and the id is what the app derives from the name it was handed — so the id
lives in the step. `the admin submits this mapping:` resolves a `team` cell by
LOOKUP, never find-or-create: a `When` that builds fixtures would have conjured
`Outsiders` and then mapped it successfully.

### Why these three are scenarios and not one Outline

They look like one action against three pre-states, which is the shape of an
Outline. **The pre-state IS the difference here**, and that is what makes them
three: each enforces a rule of its own. As Examples rows the captions end up
carrying the rules the scenario titles should, and a reader has to hold three blocks
in their head to see what any single one claims.

NEITHER MODE NOR STORAGE MATTERS TO ANY OF THEM, so `storage` is gone from all
three. All three checks run before anything is provisioned, so the backend never
enters into them, and a column that never changes the outcome is a column a reader
has to rule out. `mode` stays because it varies the submission without pretending
to be load-bearing.

They sit together at the bottom of the file, after the two scenarios that succeed,
so the refusals read as a group.


### A link mapping may not be made over designs that already exist

@unbuilt — nothing purges on create yet.

THE STATE THIS PREVENTS IS ONE THE APP HAS NO ANSWER FOR. A `link` mirror is a
zero-byte pointer; a `.penpot` holding an archive inside a link mapping is a
contradiction, and every rule that reads one has to guess which it is. The live
instance produced exactly that: a folder mapped `sync`, unmapped (leaving three
real archives behind), then re-mapped `link` over them. Removing the mapping then
took the three pointers and kept the three archives — which is
`MappingTeardownService`'s stated rule working correctly and
`mapping/delete.feature`'s promise ("takes its designs with it") failing, at the
same time. CI could not have caught it: every scenario there builds a clean tree.

So the contradiction is designed out at the only moment it can be created.

THE ACKNOWLEDGEMENT IS A SECOND BEAT, NOT A FORM FIELD. It began life as a
`| purge designs | yes |` row in the submitted table, which was wrong twice over:
it is not a setting the mapping stores, and it put the consent BEFORE the app had
said what it would cost. As an `And` after the `When` it reads the way the
interaction actually goes — the admin submits, the app answers with a count, the
admin accepts — and it keeps the form table to fields a mapping really has.

PURGED, NOT TRASHED, and that is the load-bearing word. A trashed design offers a
restore, and restoring INTO a link mapping is already ruled out — there is nowhere
for the bytes to go, because Penpot has no write path for design content. Rather
than invent an answer for a restore that cannot work, the files never reach the
trash. Which is why the confirmation has to say HOW MANY and that they are not
recoverable: this is the one gesture in the app that destroys something outright.

CANCELLING NEEDS NO HANDLING, and that is a design property rather than an
omission. The admin goes and does whatever they were going to do with those files
— move them, delete them, keep them somewhere else — and when they come back the
tree holds no designs, so the mapping is created with no warning at all. Nothing
destructive, nothing to confirm.

ONLY *UNMAPPED* DESIGNS, ON PURPOSE. A tree already belonging to a mapping cannot
reach this rule: `A mapping may not reuse a team or a folder` refuses first, and a
mapping may not be made under or over an existing one. So "no `.penpot` anywhere in
the tree" holds implicitly for every mapped tree without being checked, and the
only case left to handle is the one this scenario names.

SYNC IS UNTOUCHED. Designs already in the tree are adopted and imported when a
`sync` mapping arrives (§6.33), so nothing is destroyed and nothing is confirmed.

### Removing a mapping deletes nothing

Nothing is removed from Penpot and nothing local is removed either. What
SHOULD happen to already-mirrored files is Course 5's decision
(mapping/delete.feature) — until then the safe behaviour is to leave them
and say so.

### Two Penpot projects in one team sharing a name is handled, not crashed

Penpot permits duplicate project names; Nextcloud does not permit duplicate
sibling folder names. Free nesting means the second folder can live
elsewhere, but the exact rule is undecided — saga open question #31.

### A team renamed in Penpot does not rename the mapped folder

THE PULL DOES NOT RENAME THE TEAM FOLDER to follow the team. The folder name is
the admin's (see above), and silently moving someone's folder because a team was
renamed upstream is a surprise, not a sync. The recorded team name does update, so
the admin page shows the truth.

Note this is the opposite of the PROJECT folder rule below, and
deliberately so: a team folder is a mount point the admin chose to create,
a project folder is a mirror of a Penpot object.

### The groups a mapped folder is shared with can be changed

── what can be changed, which is one thing ─────────────────────────────────

THERE IS NO EDIT, SO THERE IS NOTHING TO REFUSE — and that is why there are no
"the admin tries to change the folder and is told no" scenarios here. No such
refusal is reachable: there is no occ command that edits a mapping, and the one
HTTP endpoint takes `ncGroups` and nothing else. The service signature is
`updateGroups(id, groups)` (§C6.33), so a change to any other field cannot be
EXPRESSED, let alone refused.

A scenario for a refusal no caller can provoke is a scenario about an error
message. Immutability is a fact about the API's shape, and the place to state it is
where the shape is — `MappingService::updateGroups()`'s docblock carries the reason
for each locked field, and `MappingServiceTest` pins that a group change moves
nothing else.

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

Keep that hazard in the Examples table rather than in the step's PHP. A step that
picks a folder name from the kind hides the reason at exactly the point where
someone would otherwise "tidy" the two names into one.

THE FOLDER is what changes here — see above. Not the mapping: the mapping stores no
groups at all (§C6.35), so there is nothing on it for a re-share to update.

---

### Without a service-account token, nothing can be mapped

Refusing a mapping belongs with mapping. The service account is what reads, so
without one there is nothing a mapping could do; refusing at creation says so at
the moment the admin can act on it, rather than at the first sync.

## designs/copy

`features/designs/copy.feature`

saga: [§6.28 duplicate-file is real](../saga/Chapter_1_First_Contact.md#628--decision-locked-duplicate-file-is-real--copies-are-a-first-class-penpot-operation) · [§C6.17 who performs a copy](../saga/Chapter_2_The_Colony.md#c617--who-performs-a-copy-and-why-the-answer-was-never-really-ours)

THE LIVE HALF is driven over WebDAV against a real Penpot: copy in place, copy
up to the team root, and the copy-then-rename chain.

Copying a mirrored ".penpot" file. A copy in Nextcloud becomes a REAL new
design in Penpot — full parity with both siblings, which register a copy as a
new n8n workflow / Grafana dashboard for the same reason: a copy is a new
thing, and leaving it inert makes the file a lie about what it is.

Copying a PROJECT folder is projects/copy.feature, and the answer there is the
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
it keeps its stamp), which is designs/move.feature's "unmapped" state.

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

This is what the new id buys: two candidates for "update in place" is the state to
avoid, and giving the copy its own real id solves it without leaving a dead file
behind.

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

saga: [§6.40 copying a project folder is refused](../saga/Chapter_1_First_Contact.md#640--decision-locked-copying-a-project-folder-is-disabled-for-a-reason-that-generalises)

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

### A project duplicated in Penpot arrives as its own folder

The mirror of `Copy a project within its team`, and the cheaper half: the pull
already mirrors any project the team holds, so a duplicate is simply a project
the team did not have before. No new mechanism, and no per-child anything —
this side never had the wall the Nextcloud side did.

**THE SUFFIX BELONGS TO WHICHEVER SIDE MADE THE COPY**, and the two do not
agree:

| made in | named by | first copy of `My Stuff` |
|---|---|---|
| Nextcloud | core's `Folder::getNonExistingName()` | `My Stuff (2)` |
| Penpot | the dashboard's `dashboard.copy-suffix` | `My Stuff (copy)` |

Neither is this app's choice and neither should be normalised into the other.
The folder takes the project's name because a project's name IS its path
(§C6.38), so `(copy)` reaching Nextcloud is the rule working, not a leak.

**`duplicate-project` DOES NOT NAME THE COPY.** Called bare it returns a project
with the same name as the original — Penpot allows two projects to share one
(§31) — and the `(copy)` a user sees is appended by the frontend before the
call. Measured in the bundle and against a live instance, which is why the
harness passes the name rather than expecting Penpot to invent it.

### A project copied into another team belongs to that team

The destination decides, exactly as it does for a design. The copy is created in the
destination team and never re-homed afterwards, so there is no window in which it
belongs to the team it came from.

### Penpot projects do not nest — but folders do, and the NAME carries it

THE NUANCE THAT DOES NOT PORT FROM THE SIBLINGS. Grafana folders nest; Penpot
projects are flat under a team. Nextcloud folders nest and people expect them
to, so the two are reconciled in the NAME rather than in the structure: a folder
at `Penpot/foo/bar` is the project *named* `foo/bar`, which is one flat project
in Penpot and a nested tree here.

**THERE IS NO "CANNOT HOLD A PROJECT" PLACE inside a mapping.** A folder below a
project is promoted like any other — see *A folder is a project when a design is
in it*, which spells out that there is no "only a folder with no project above
it" carve-out, and why: two folders a user cannot tell apart must not behave
differently on a marker nobody can see.

<!-- That carve-out existed, shipped, and was reported by a live instance;
     §C6.38 reversed it. `Copy a project under something that cannot hold one`
     outlived the reversal asserting the removed behaviour, and was deleted
     rather than rewritten — its Drafts row could only be true under the rule
     that had gone. -->

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

saga: [§6.33 where a create is unambiguous](../saga/Chapter_1_First_Contact.md#633--decision-locked-create-in-nextcloud-is-scoped-to-where-its-unambiguous-and-drafts-is-where-it-lands-otherwise) · [§6.35 Drafts is a state](../saga/Chapter_1_First_Contact.md#635--decision-locked-drafts-is-a-state-not-a-folder--and-its-where-nextcloud-gets-flexibility-penpot-lacks)

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
folder (designs/move.feature).

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

THE THREE PLACEMENT CASES ARE LIVE ABOVE, driven over WebDAV — which is exactly
what the "+ New" menu does: write an empty file and stop. **Do not restate them
here in menu vocabulary** ("I choose New → Penpot design inside the My Stuff
folder"); that is the same three outcomes said twice, and the second copy drifts.
Only the MENU SURFACE is this section's own.

### A newly created design is born in its mapping's mode

A NEW DESIGN TAKES ITS MAPPING'S MODE, and nothing else decides it. Stamping
`MODE_LINK` unconditionally would leave a design created under a **sync** mapping a
pointer nothing could ever turn into an archive, sitting in a folder whose every
other design holds one — there is no per-file promotion to rescue it with.

It is an outline over both modes because the mapping's mode is the only variable.

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
resolves to nothing and stays inert (designs/create.feature's rule). With
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
is designs/move.feature's, whatever the two ends happen to be. The scenarios live
there, next to every other move, rather than here where a reader comparing
"what happens when I drag a design" would have to find them.

This file owns only the fact that makes them possible: the home root has a
team ancestor because a token was set.

---

### A design created under the team but not under a project is a draft

**DEPTH IS PART OF THIS RULE, in exactly one place.** It is tempting to say the
team root and a plain folder three levels down are the same case — under a team,
under no project, therefore Drafts. They are not, and saying so contradicts
[a folder is a project when a design is in it](#a-folder-is-a-project-when-a-design-is-in-it):
a design landing in a plain folder is precisely what MAKES it a project.

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


## projects/create

`features/projects/create.feature`

saga: [§C6.18 a folder becomes a project](../saga/Chapter_2_The_Colony.md#c618--a-folder-becomes-a-project-and-the-one-marker-that-means-both) · [§6.29 nesting is free](../saga/Chapter_1_First_Contact.md#629--decision-locked-nesting-is-flexible-in-nextcloud-because-membership-is-a-nearest-ancestor-lookup)

A PROJECT IS A FOLDER THAT HOLDS A DESIGN, and its name is the path from the
mapping's folder down to it. Both halves were decided together (§C6.38) and neither
works without the other.

**`penpot_project_id` is the only thing that makes a folder a project.** There is
no second marker. Two things write it: the pull, mirroring a project that exists in
Penpot, and `adoptForContent()`, when a design lands in a plain folder below a sync
mapping. Nothing else.

So an EMPTY project folder can only come from Penpot, and that is how the harness
arranges every `kind: project` row: create the project in Penpot, pull, and the
folder arrives stamped.

<!-- There was once a `penpot` system tag that WAS the opt-in, and afterwards a
     period where the pull stamped it as decoration. It is gone in full — service,
     listener, event subscription and all: saga §D4.14
     (../saga/Chapter_4_Open_For_Business.md#d414--decision-locked-a-folder-is-a-project-because-of-its-metadata-and-nothing-else). -->

### A folder is a project when a design is in it

**EVERY FOLDER, AT EVERY DEPTH.** A design landing in `Penpot/foo/bar/baz` promotes
`baz`, whether or not `foo/bar` is already a project. There is no "only a folder with
no project above it" carve-out: two folders a user cannot tell apart must not behave
differently on a marker nobody can see, decided by which of them happened to receive
a design first.

<!-- That carve-out existed and shipped, and a live instance reported it: saga
     Chapter 3 Round 10
     (../saga/Chapter_3_Building_To_Plan.md#round-10--the-rules-own-edge-and-a-leftover-that-argued-for-itself). -->

**READING AND ARRIVING ARE DIFFERENT QUESTIONS, and they have to stay apart.**
§6.29 still resolves a node to the nearest project ABOVE it, so a design already
sitting in a plain subfolder belongs to the project above until something ARRIVES
in that subfolder. Arriving promotes; sitting there does not. That asymmetry is
what lets `fileExistingDesigns()` sweep a plain subfolder into the project being
promoted and still be right.

**THE RULE IS SPELT OUT IN FOUR VERB FILES.** One rule, said once per gesture,
which is how this suite is organised — and also how a change to it gets shipped
half-done. The rows that carry it are the ones whose destination is a subfolder of a
project:

| file | row |
|---|---|
| `designs/create.feature` | `Penpot/Make Here/wip` |
| `designs/copy.feature` | `Penpot/Copy Here/wip` |
| `designs/move.feature` | `Penpot/Move From/wip`, and the `link` row beside it |
| `projects/create.feature` | `Penpot/Existing/Below` |

Change the rule and all four have to move together. `grep -rn "wip\b"
features/*/*.feature` is the whole audit.

A LINK MAPPING IS THE ONE PLACE THIS DOES NOT REACH, and not by exception to this
rule so much as to promotion itself: under a link the tree is filled FROM Penpot,
so nothing may be created and an arrival belongs to the project it lands under.
That is why `DestinationResolver` falls back to the ancestor and not to Drafts —
Drafts would move somebody's design out of the project Penpot has it in, because
they made a folder.

`Move a folder that other projects are named through` and its delete-side twin
both need TWO projects, and the arrange has to SAY so — that is what the `kind`
column is for, rather than relying on a side effect of writing a design somewhere.
The delete-side one is the cautionary half: *"Penpot holds no project named
`foo/bar/baz`"* is trivially true of a project that never existed, so it passes
vacuously if the arrange stops building what it claims. Worth remembering whenever a
negative assertion goes green after a rule changes underneath it.

**THE BOUNDARY IS WHERE THE EVENT IS.** Promotion happens as a design
arrives — created, moved in, copied in — because those are the three gestures that
fire a per-file event the app can act on. `Create a design in a folder Penpot has
never seen` passes on all four rows, plain folder and Team Folder, one level deep
and three.

`Move a design into a folder Penpot has never seen` passes on all four of its rows
too, and the four are chosen so that the arrival is a different mechanism each time:
a source in `Scratch` is an UNTRACKED file and is IMPORTED (§6.33), and the two rows
between `Penpot` and `Shared` cross a STORAGE boundary, where core deletes the
metadata and the file id is what survives. Four routes in, one rule out — the folder
the design lands in becomes the project, and none of the four is a special case of it.

`Move a folder of untracked designs into a team` does not, and it fails on two
walls at once rather than on this rule:

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

`Create a project in Penpot` is the scenario that reads this back, and it asserts the
leaf by ID rather than by name — which is what forces every name in its Examples to
be one no earlier scenario leaves standing in that team. Penpot state accumulates
across a leg and two projects may share a name, so a second `Team` would be mirrored
to `Team (2)` beside the first (`PullService::ensureProjectFolder()` adopts only a
BARE folder), and the row would then read the older project's id off the older folder.
Deterministically wrong, not flaky, and it reads as an app bug.

### A project name that spells no path is skipped

`/`, `foo/../bar`, `foo/?/bar` — all legal in Penpot, none renderable as a Nextcloud
path. Normalise first (a leading, trailing or doubled slash is dropped), then skip
what is left over.

REPORTED AS A NEXTCLOUD NOTIFICATION, which is the only channel a pull has —
{@see SyncNotifier}. Do not phrase it as *"the sync reports the project it could not
place"*: that reads as a post-state and is really a second gesture, an excuse to run
a sync inside a `Then`. The bell is where an async failure belongs.

One project is the whole cost, which is why the scenario keeps a second project in
the team and asserts it arrived. The rest of the team still pulls, and that is the
difference between a report and a failure.

---

## designs/delete

`features/designs/delete.feature`

saga: [§6.52 deletion rebuilt on Penpot’s trash](../saga/Chapter_1_First_Contact.md#652--decision-locked-deletion-and-restore-rebuilt-on-penpots-own-trash-replaces-634) · [§C6.16 the prune’s field of view](../saga/Chapter_2_The_Colony.md#c616--the-prunes-promise-was-never-asserted-and-the-trash-it-fills-is-not-yours)

DELETING A DESIGN — both bins, both directions, and the one irreversible path.
Deleting a PROJECT (the folder) is projects/delete.feature: one call, not one
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
safety half of it lives in connection/sync-now.feature, where the run itself is spec'd.

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

The design is gone from every Penpot listing AND from its trash, so nothing about it
can ever come back — and the mirror is still only trashed. This is the case where the
local file is genuinely the last copy of that design, which is precisely why it must
land somewhere recoverable.

NOTHING IS ASSERTED ABOUT THE FINAL ARCHIVE HERE (§C6.16):
`permanently-delete-team-files` returns before the data is actually gone — Penpot
marks the rows and a worker removes them later — so `export-binfile` can still
succeed for seconds afterwards. Whether the snapshot lands is Penpot's timing, not
our behaviour.

**What happens to the trash ENTRY afterwards is
[`designs/purge.feature`'s](#a-design-destroyed-in-penpot-purges-its-trashed-mirror)**, not this file's: a
mirror whose design has been destroyed is reaped, on three agreeing answers. This
section stops at the moment the file lands in the trash.

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

Penpot has a trash of its own and it is reachable by API, so a delete is already
reversible without this app building anywhere to put things. It also preserves
strictly more than a bespoke bin could, and needs no configuration. The design
that proposed one, and the false premise it rested on, are saga §6.34 → [§6.52](../saga/Chapter_1_First_Contact.md#652--decision-locked-deletion-and-restore-rebuilt-on-penpots-own-trash-replaces-634).

### Once the grace window passes, only a best-effort import remains

Measured, not assumed (saga §6.41): a real export→import round trip
preserved name, revn 5, pages and assets — and produced 0 file_change rows
against the original's 5.

---

## projects/delete

`features/projects/delete.feature`

saga: [§C6.19 what Penpot does when you delete a project](../saga/Chapter_2_The_Colony.md#c619--what-penpot-does-when-you-delete-a-project-and-two-things-nobody-had-measured)

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

BOTH SIDES OF THE PREMISE ARE STATED, rather than left to the Background: the folder
in Nextcloud, and the project in Penpot that it is a folder FOR. The refusal is not "this is a link team", it is "this folder IS a project
Penpot still holds" — and the rule below is the same gesture on the same kind of
folder with no project behind it, which goes. Without the `Given`, the two scenarios
look like they disagree about link mode instead of agreeing about the project, and
the one thing that separates them is invisible.

The `Given` and the `Then` say the same sentence deliberately: one is what makes the
refusal correct, the other is that the refusal did not destroy it on the way past.

### A folder Penpot never named is an ordinary folder

The boundary of the two rules above, and the axis is *is this folder a project* —
NOT what mode its team is in. A folder somebody made themselves holds no designs and
maps to no project, so no pull will ever write it back and no gesture in Penpot can
reach it. It is a Nextcloud folder that happens to sit under a mapped team, and it
goes when they say it goes.

TWO STATEMENTS, NOT ONE, and the split is what makes the scenario legible. "a folder
that is not a project" bundles a Nextcloud fact and a Penpot fact into one sentence,
and only the Penpot half is load-bearing — so the arrange states the folder, and
`Penpot holds no project named` states Penpot's side in Penpot's own words.

THE CONTENTS ARE NOT THE VECTOR, which is why no row puts a file in the folder. A
design in a folder under a team implies a matching Penpot project, so "no matching
project" already means "no designs" — there is nothing an `.txt` row would add that
the missing project does not already say. (`LinkWriteGuardPluginTest` covers a folder
of ordinary files at the unit level, where the subtree walk actually lives.)

The `Pointers` row is the one that earns the scenario; the other two are what make
the claim "the mapping makes no difference" rather than "link is special". It was
also the only row that was ever broken. `MoveRules::refusalForDeleting()` first read
the link rule as being about the MAPPING and refused every delete anywhere under a
`link` team, whatever the node was — so an empty folder made in a link-mapped folder
could not be removed by any route, ever, and the 403 explained itself with a sync
that was never going to happen. Found by hand on the live instance, not in CI.

Nothing stops the folder being made, either: `refusalForCreating` guards `.penpot`
names and says nothing about folders or spreadsheets. An app that lets you make a
thing and never lets you remove it is worse than one that refused the creation.

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

IT IS THE PRUNE'S OTHER HALF, and the half that is easy to miss.
`PullService::collectMirrors()` gathers FILES, so a project deleted in Penpot
already does the right thing to its designs — they go to the Nextcloud trash, each
with a last-chance snapshot. Without this, it does nothing at all to the FOLDER,
which stays behind carrying a `penpot_project_id` naming a project that no longer
exists, and no pull ever looks at it again.

A DEAD MARKER IS NOT MERELY UNTIDY. Nothing that reads one can tell it from a live
one: `MembershipResolver` resolves designs into a project that is gone, and
`MoveRules::refusalForDeleting()` refuses to let the folder be deleted under a `link`
mapping — permanently, because the reason it gives ("it would come back on the next
sync") is not true and never becomes true. Found by hand on a live instance, where a
folder in exactly that state could not be removed by any route.

`PullService::reapOrphanProjects()` is the repair, and it runs behind the same
`$complete` gate the prune does — for a sharper reason. A project skipped for an
illegal name is absent from the run's named set while being perfectly alive in
Penpot, so without the gate one slash in one project name would send every other
project's folder to the trash on the next pull.

---

## designs/edit

`features/designs/edit.feature`

saga: [§6.1 the read-only rule](../saga/Chapter_1_First_Contact.md#61--decision-locked-nextcloud-is-a-read-only-mirror-of-penpot-not-a-peer) · [§6.22 sync vs link](../saga/Chapter_1_First_Contact.md#622--decision-locked-reconciliation--sync-vs-link-comes-back-meaning-something-new)

A DESIGN'S CONTENT CHANGING — and the only file in this app with one direction
where both siblings have two.


**Editing happens in Penpot, and only in Penpot.** A `.penpot` archive is opaque
nested design data; there is nothing coherent to hand-edit and no way to
re-import it if there were, which is why `open-with.feature` offers no text
editor in any mode. So there is no Nextcloud-side twin to write, and every
scenario here is `@in-penpot`.

**THIS FILE OWNS THE MOST IMPORTANT THING THIS APP DOES FOR A `sync` FILE**, and
it has to be stated positively. A negative — *"not re-exported by the next pull"* —
files the behaviour under the mechanism that carries it, and leaves "a design was
edited and the mirror caught up" asserted nowhere at all.

Do not fold in the truncation case, which is a different claim about an older
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

## designs/view

`features/designs/view.feature`

saga: [§6.4 the mimetype](../saga/Chapter_1_First_Contact.md#64--mimetype-a-real-extension-but-not-a-free-custom-mimetype-from-penpot-itself) · [§C6.6 a link stops carrying a body](../saga/Chapter_2_The_Colony.md#c66--a-link-stops-carrying-a-body-because-the-metadata-already-was-one) · [§C6.24 the clock a mirror wears](../saga/Chapter_2_The_Colony.md#c624--the-clock-the-mirror-was-never-wearing)

LOOKING AT A MIRRORED DESIGN — the only part of "it is a real file type" that
anyone actually performs.

**This replaced `designs/view.feature`, which described a CONSTRUCT.** "A mirrored
Penpot file is a first-class file type" was about a mimetype, an icon and a
property set — none of which anyone does. Each turned out to be the end state of
something else:

| it described | whose end state it is | where it went |
|---|---|---|
| the mimetype is registered | **enabling the app** | `lifecycle.feature` |
| a file carries this metadata | **the pull** | asserted by `sync-now.feature`, shown here |
| the mode property's wire value | what the metadata says | the DAV view scenario |
| the context-menu glyph | the action that draws it | `open-with.feature` |
| the metadata cannot be edited | core, which registers every key EDIT_FORBIDDEN | a note, not a scenario |

Nobody registers a mimetype; they install an app. Nobody sets metadata; they map
a team and the pull stamps it. Once each end state sits with the behaviour that
produces it, what remains is looking — and that is a real thing to do.

FOUR SCENARIOS WENT AWAY ENTIRELY, because the reshape exposed them as
duplicates or as end states already owned elsewhere:

| scenario | why it went |
|---|---|
| A project folder is identifiable by both metadata and a visible tag | a duplicate — same arrange, same two asserts, stated elsewhere |
| A file moved out of its mapped folder is unmapped, not untracked | the *rule* is nearest-ancestor membership; the *gesture* is `designs/move.feature`'s move-out. Neither needed a third statement of it |
| The mode is visible and reflects whether content is stored | two `Given`/`Then` pairs in one scenario — two scenarios wearing one name. The DAV half merged into the view scenario |
| The row icon and the menu glyph are separate files | two files with opposite contracts, so one scenario could not be the arrange for both. The menu glyph went to `open-with.feature`; the row icon half went in the alignment pass |

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
  penpot_mode     — "sync" or "link" (saga §6.22). The axis means something
                    different here from both siblings: not "which way do edits
                    flow" (they never flow out) but "do we store the bytes at
                    all."
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

THERE IS NO "penpot_mapping" KEY, and there must not be one. Folder-level
metadata works (saga §6.21, tested live on a real Team Folder), so the folder
already knows which project and team it is and membership is DERIVED by walking up.
A copy on every file would have to be rewritten on every move, which is a second
source of truth and drifts on the first one that fails.

SO A FILE'S STATE IS DERIVED FROM penpot_id + WHERE IT LIVES:
  mirrored  — has penpot_id, has a project-id ancestor folder (saga §6.29)
  unmapped  — has penpot_id, no project-id ancestor
  untracked — has no penpot_id

"Has a project-id ancestor" is a NEAREST-ANCESTOR walk at any depth, not a
fixed-level check (saga §6.29).

FOLDERS CARRY METADATA TOO (saga §6.21, §6.32):
  penpot_project_id — on a project folder. The authoritative machine record.
  penpot_team_id    — on a Team Folder.
`penpot_project_id` is the whole record. There is no tag, no badge and no second
marker — a folder is a project because of its metadata and nothing else
(saga §D4.14).

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
in the file. The key set and the mode's wire value are one scenario, not two: same
arrange, same PROPFIND.

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

## lifecycle

`features/lifecycle.feature`

saga: [§6.4 the mimetype](../saga/Chapter_1_First_Contact.md#64--mimetype-a-real-extension-but-not-a-free-custom-mimetype-from-penpot-itself) · [§C6.3 a mimetype that claims no structure](../saga/Chapter_2_The_Colony.md#c63--a-mimetype-that-refuses-to-claim-a-structure)

Stage 0: the app installs and uninstalls cleanly on a real Nextcloud.
A clean uninstall is also an app-store rule. No Penpot contact.

Identical shape to both sibling apps (nextcloud-n8n, nextcloud-grafana) — app
enable/disable has nothing to do with the read-only-vs-bidirectional split that
makes Penpot Sync architecturally different elsewhere (saga §6.1). This is a
clean, mechanical port.

LIVE — this is one of the first two features to come off @todo. It runs against
a real Nextcloud in CI (.github/workflows/integration.yml).

### Enabling the app

**THE MIMETYPE IS WHAT ENABLING LEAVES BEHIND**, which is why it is asserted here
rather than in a scenario of its own. Nobody registers a mimetype; they install an
app, and the registration is the consequence.

Proven by uploading a plain file rather than by reading the app's own metadata:
a file this app has never touched, with nothing but the extension going for it,
comes back typed as the app's own mimetype. That is what registration means and
the only part of it a client can observe. Without the repair step a `.penpot` is
sniffed as a generic archive (§C6.1), which is a zip icon and no opener.

THIS CLOSES A NAMED GAP. The old `designs/view.feature` note said the mimetype
registration was UNASSERTED IN CI — "a repair step that silently failed to merge
the config would look exactly like one that worked". It is asserted now, and on
the one scenario that was already running.

Its visible consequence (a mapped folder that looks like designs) belongs to
`designs/view.feature`; its removal is the "Removing the app" scenario below.

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


## designs/move

`features/designs/move.feature`

saga: [§6.29 nesting is free](../saga/Chapter_1_First_Contact.md#629--decision-locked-nesting-is-flexible-in-nextcloud-because-membership-is-a-nearest-ancestor-lookup) · [§6.43 a link is confined to its project](../saga/Chapter_1_First_Contact.md#643--decision-locked-link-files-are-strictly-confined-to-their-project)

MOVING A DESIGN — every way a design can change project, team, or Drafts state,
from either side. Moving a PROJECT (the folder) is projects/move.feature: a
different object with a different rule, because a project folder's position is
constrained where a design's is not.

A move is a move whoever performed it, so both directions are here and the sections
below are ordered by where it happened. Splitting the Nextcloud half from the Penpot
half puts one behaviour in two files that then drift.

### THE GUIDING PRINCIPLE: DON'T LOSE DATA

A move never destroys bytes, never contacts Penpot destructively, and never
leaves a file in a state the user cannot get back out of. The closing invariant
below is stated for files AND folders, and projects/move.feature relies on it
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
confined. **The refusal names the rule and stops.** It must not end "promote to
sync first": there is no per-file promotion, and re-mapping a whole team is not
advice to give someone mid-drag.

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

**AND WHY LEAVING TRASHES THE DESIGN.** Asserting `the design still exists in
Penpot` reads as a decision and is really an absence: the app stops mirroring the
design and leaves it sitting in a project whose folder maps nowhere, visible to
everyone in the team, indistinguishable from work still being mirrored. Both
siblings park it instead — n8n ARCHIVES the
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

### A subfolder of a project is a project of its own

**`Penpot/Move From/wip` AND `Penpot/Clients/Traveller` ARE THE SAME SHAPE AND
BEHAVE THE SAME WAY.** A design dragged into either promotes the folder it lands in.
Anything that makes them differ makes the outcome depend on markers a user cannot
see, which is the trap this rule exists to close.

It was written as a subtlety worth teaching. It was a trap. A user cannot see
markers, so the two folders were the same folder as far as anyone could tell, and
which behaviour they got depended on which folder had happened to receive a design
first. Reported live: a folder made inside a project, a design dragged in, and
nothing at all in Penpot.

**Both shapes now mean the same thing.** The folder a design lands in becomes a
project named by its path below the mapping, so `wip` is `Move From/wip` and
`Traveller` is `Clients/Traveller`. The two Examples blocks that sat next to each
other to teach the distinction now teach one rule twice — the first that a
subfolder is promoted at all, the second that the NAME is the whole path.

THE LINK ROW SURVIVES UNCHANGED, and it is the only asymmetry left: promotion is
refused under a link mapping, so a mirror filed into a subfolder there still
belongs to the project above it. That is a rule about who may CREATE, not about
what a folder is.

**THE PROJECT NAME HAS TO BE UNIQUE ACROSS THE WHOLE SUITE, and a nested one makes
that easy to forget.** The legs get a fresh Nextcloud each and share ONE Penpot, so
a project name is effectively global: `projects/move.feature` already produces
`Clients/Traveller`, and reusing it here had the destination resolve to that
project — a real one, in the right team, holding another feature's files. The row
failed on a path, which reads like a bug in the nesting and is a fixture collision.
`Nesting/…` belongs to this file alone.

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
DOES arrive. What does not arrive is the file's METADATA: the node that lands is a
`.penpot` carrying no `penpot_id` at all. `onMove()` reads it as untracked and takes
the §6.33 import branch, which is visible in the run as *"a design arrived, so the
folder is a project"* followed by *"adopted an archive as a Penpot design"* — a NEW
design with a new id, which is exactly what the scenario asserts must not happen.

#### What a cross-storage move actually does, measured

*"Properties do not travel across a storage boundary"* sounds like a complete
explanation and is not one. Measured on a live Nextcloud — a plain `.txt` stamped
with `penpot_id`, moved from a home folder into a groupfolder mount, **with a
same-storage rename as the control in the same script and the same run**:

| | file id | `penpot_id` afterwards |
|---|---|---|
| same-storage rename | preserved | **survives** |
| cross-storage move | **preserved** | **gone** |

**The file id is preserved**, and that is the counter-intuitive half. A
cross-storage move looks like a copy-and-delete, so the natural assumption is a new
file with a new id and orphaned metadata. It is the other way round: the id survives
and the METADATA is deliberately destroyed — removing the source cache entries raises
`CacheEntriesRemovedEvent`, and core's own `MetadataDelete` listener (bound in
`FilesMetadataManager`) drops every `files_metadata` row for those ids.

So there was nothing to look up and nothing to repair after the fact. The last moment
the record exists is `BeforeNodeRenamedEvent`.

#### The fix, and why it is a memory rather than a lookup

`MoveMemoryListener` reads the identity on the before-event and parks it in
`MoveMemory`, an in-process map with the same lifetime argument as `SyncGuard`: both
halves of one gesture run in one request. `MotionService::onMove()` consults it only
when the arriving file has no metadata at all, re-stamps what it finds, and from that
line on nothing downstream can tell the difference between a design that crossed a
storage and one that did not — the project comparison, the `move-files`, and the team
re-stamp at the end all work unchanged.

Penpot never needed anything: `move-files` has carried the destination team in one
call since saga §6.27/§6.34. Nextcloud was the half that could not express it.

- `Move a design into another team` is LIVE. Both rows cross the boundary, and both
  are the gesture the memory exists for.
- `Move a design out of every mapping` still LOST its `Shared/Let Go` row, and that
  row is now reachable — leaving a Team Folder for unmapped space is the same
  crossing, and `park()` runs on the recovered identity. Restoring it is a gherkin
  change, so it is proposed rather than taken.

**PROMOTING THE SCENARIO IS HOW THE WALL GOT FOUND, and running the probe is how it
got named.** Four CI rounds established *that* metadata was lost; two minutes against
a live instance established *what* deleted it and that the id survived — which is the
difference between a tag saying "cannot" and a listener saying "here".

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

### An arrival becomes its own design, whatever it arrived carrying

THREE SCENARIOS COLLAPSED INTO ONE, because the question they branched on stopped
existing. They were: the design is live, the design is parked, and Penpot has no
design for this file at all — five Examples rows across two outlines, all asking
what the `penpot_id` on an arriving file names.

It names nothing that matters. A file moving into a mapping is IMPORTED, so the
bytes that arrive are the bytes that end up in Penpot, and the import mints an id
whatever the file was carrying.

### WHY THE REATTACH HAD TO GO

It read the id off the file, untrashed the design if it was parked, and filed THAT
design into the project. So the id was authoritative for identity while Nextcloud
stayed authoritative for content — and those two collide silently inside one sync
interval:

> park a design → unarchive it in Penpot → edit it → trash it again → drag the file
> back in, all before a scheduled pull

The reattach hands back bytes the user never saw and could not have asked for.
Nothing local ever knew the design had moved on, and no amount of care at the
Nextcloud end can find out in time.

**The bytes in Nextcloud are what the person is holding, so they are what must
exist afterwards.** That is the whole rule, and an import is the only thing that
guarantees it. Penpot has no way to put new bytes inside an existing design —
`import-binfile` always creates one — so the new id is not a compromise, it is the
shape of the only operation that can be honest.

What that costs is the version history of a design somebody had already unmapped,
which is the right thing to spend: the alternative spends the user's actual work.

### WHAT THE ID IS STILL FOR

Kept on the file, and {@see park()} still says so — but for one question only, and
it is not identity. Two files inside one mapping may not claim one design, and the
id is how the "keep both versions" answer is recognised. See
`#keeping-both-versions-of-a-duplicate-makes-the-arrival-its-own-design`.

The parked design is left where it is. Penpot's trash empties itself, and one that
somebody unarchives by hand is a new design the reconciler picks up like any other
— which is exactly what it now is.

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
| anything at all — a live id, a parked one, a stranger's, none | IMPORTS (§6.33): a new id is minted and any stale one overwritten | `Move an unmapped design file into a project` |

**That table is one row, not two.** Splitting it on whether the id still names a
design — one branch reattaching and asserting `penpot_id | the original id`, the
other importing and asserting a new one — describes a fork the app does not have.

It was forced by a rule that has since gone. Reattaching made the id authoritative
for identity while Nextcloud stayed authoritative for content, and those collide
silently inside one sync interval — see *"An arrival becomes its own design"* above
for the sequence. With the reattach removed the discriminator disappears with it:
one row, one scenario, and what the file carries when it lands decides nothing.

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

### A duplicate arriving in a project is answered by content

THE PERSON ANSWERS WHAT THE CONTENT SHOULD BE; the identity follows from it.
Nextcloud's conflict dialog offers keep-existing, keep-new or keep-both, and all
three questions are about BYTES. What the person is choosing is which design they
end up with; the id is bookkeeping they never see.

**The id already there does NOT always win**, and the reason is that the conflict
answer decides everything. Crossing the three answers with the three identities an
arrival can carry — the same id, a different one, none at all — describes a matrix
the app does not have, because:

  - *keep the existing version* sends no request at all, so the destination keeps
    its id because nothing happened to it;
  - *keep the new version* is `Overwrite: T`, and Sabre DELETES the destination
    before moving. The design it mirrored is gone, and the arriving bytes have to
    become a design of their own — `import-binfile` is the only way to put bytes
    in Penpot and it always mints an id.

So the surviving file keeps the destination's id in one case and gets a new one in
the other, and both are the same rule: **the answer picks the content, and the
identity is whatever that content requires.** There is no `its id` column, because
what a file ARRIVED carrying is not an input to anything — two rows, not six.

The Grafana sibling's bug this table was ported to catch is still caught, by the
guard rather than the column: an arrival must never re-bind an id another file in
the mapping is still using. See below.

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

saga: [§C6.38 the master design](../saga/Chapter_2_The_Colony.md#c638--the-finale-the-spec-outran-the-harness-on-purpose)

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

### A project moving into a folder named after itself

THE ONE SHAPE THE OUTLINE ABOVE CANNOT REACH, and the one a user hits first.
`Bubbles` becoming `Bubbles/foo` asks a folder to be moved INSIDE ITSELF; going
back asks it to take the name its own parent is using. Every row of
`Move/Rename a project in Penpot` changes the path to something disjoint, so none
of them exercise either, and they cannot be rows of it in any case: its `Then`
says `there is no folder at "<from>"`, and here `Penpot/Bubbles` survives — as the
folder the project now sits in.

Both are one Penpot rename away from any nested project, and both fail **silently**
if the pull refuses to read a name with a `/` in it: the gesture produces nothing and
switches the prune and the orphan reap off for the whole mapping. These scenarios are
what stop that going quiet.

WHY THE SECOND SCENARIO IS NOT REDUNDANT. It looks like the first read backwards
and it is not: the folder it must land on is occupied by the folder it is leaving,
so the name has to be FREED before it lands. Get that wrong and everything still
"works" — the project keeps its id, the designs keep theirs, the assertions on
identity all pass — and the folder is called `Wrapper (2)` forever, one suffix
higher every pull. That is why the scenario names the path rather than only the
id.

Neither says anything about the parking the implementation does to get there. A
folder stepping aside under a temporary name for one step is mechanism; the
scenario asks what is true afterwards.

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
the user's own files included. **Do not phrase the old path as "gone from
Nextcloud"** — that reads as a delete, and a delete would destroy anything else
sitting in there.

CHANGING TEAM IS A ROW OF THE SAME OUTLINE, not a scenario of its own. Penpot can
rename and re-team in one gesture where a Files drag can only do one at a time, so
the destination team is an INPUT and the outcome — the folder is gone from where it
was and stands, with its id, where the new name and team put it — is identical.

Grafana states it the same way (`Move a folder in Grafana`, whose Examples caption is
*"Grafana can move and rename in one call where a Files gesture cannot"*), and it has
no separate cross-mapping scenario on the remote side either.

**THE TWO HALVES HAVE TO AGREE, and nothing catches it if they do not.**
`PushService` renames a project to its path below the mapping when its folder moves;
if `PullService` treats a `/` in a project name as an illegal node name and SKIPS the
project, the app writes names it then refuses to read back — and the everyday gesture
this section describes produces no folder at all, with every test still green.

The second half of that cost was worse than the missing folder. A skipped project
clears the pull's completeness flag, which switches off the prune AND the orphaned-
project reap for the WHOLE mapping — so one nested project silently disabled every
reconciliation that mapping had, permanently, with no error anywhere. Found by hand
on a live instance: a project renamed to `Bubbles/foo` in Penpot moved nothing,
created nothing, and stopped its mapping pruning.

Fixed by making the pull read a name the way the push writes one: a `/` is a level,
the path above the leaf is provisioned as ordinary unmarked folders, the folder is
found by its id wherever it sits and MOVED, and an emptied bare parent is cleared
behind it. The one case none of the scenarios here reach is a project moving INSIDE
ITSELF (`Bubbles` → `Bubbles/foo`), which asks a folder to be moved into a folder
that does not exist yet and whose name it is currently using — the folder parks
under the mapping root for one step, which is also what lets the reverse
(`Bubbles/foo` → `Bubbles`) land on the real name instead of `Bubbles (2)`.
### A project sent to another team takes its folder with it

The mirror of `Move a project folder into another team`. One project has one
folder, whichever side moved it: when a project leaves for another team, the
folder that already IS that project arrives in the receiving mapping — with
everything in it, designs and ordinary files alike. `Budget.xlsx` is the
assertion, because a design would be re-mirrored either way and would not
notice the difference.

**TWO HALVES, AND THE SECOND ONE IS THE ORDERING.** The receiving mapping has to
find a folder standing under a root that is not its own, and the donor mapping
must not destroy that folder's identity first.

- **Finding it.** `$folderIndex` is built per mapping root, so a project
  arriving from another team always MISSED it and a miss means "make a new
  folder". `foreignProjectFolder()` answers the miss by indexing the other
  mapped roots — at most once per pull, and only when something actually misses,
  since a miss is otherwise just a genuinely new project. The relocation itself
  needed nothing new: `ensureProjectFolder()` already hands whatever it finds to
  `tryMoveProject()`, which moves the folder whole.

- **Not reaping it.** `reapOrphanProjects()` reads "not named by this team" as
  "deleted in Penpot", which a migrating project also looks like. Whether the
  folder survived therefore depended on which mapping happened to pull first —
  donor first and the id was stripped before anyone could relocate it,
  irreversibly, since nothing can find a bare folder again.
  `movedToAnotherMappedTeam()` asks the other mapped teams before reaping, and
  answers **true on failure**: an unreachable Penpot defers the reap to a later
  pull rather than destroying an identity on a guess.

<!-- The Nextcloud-side twin and this one landed together; the Penpot side was
     split out mid-PR when it turned out to be a different mechanism, then built
     rather than left @todo. -->

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
its `lifecycle.feature`. One line moves when a browser harness arrives.


`features/designs/open-with.feature`

saga: [§C6.2 one action, and the absence is the feature](../saga/Chapter_2_The_Colony.md#c62--one-action-and-the-absence-is-the-feature)

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
sync-vs-link mode (saga §6.22), but the mode governs whether the ARCHIVE is stored locally, never whether the
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

BUILT, AND STILL @todo — because of the harness, not the app. `src/files.js`
registers exactly one action, "Open in Penpot", as the default click. What is
missing is a way to RUN these scenarios: every one is a click or a context menu, and
the integration harness is occ-only with no browser driver. **@todo here means "not
executable from this file", not "unimplemented".**

WHAT IS ASSERTED INSTEAD, and where: tests/js/files-helpers.test.js covers the
logic these scenarios would exercise — that both modes offer the opener
identically, that `unmapped` hides it, and the exact deep-link shape. The parts
no unit test can reach are the registration itself and the default-click
promotion.

THE DEEP LINK IS <base>/#/workspace?file-id=<penpot_id> (saga §C6.1), read off a
live Penpot's own route table rather than guessed. It keys on the file id ALONE,
which is why the
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
must carry its own fill or it is invisible. That half is `designs/view.feature`'s,
because it is a property of the file type rather than of this action.

**The menu glyph and the row icon are two files with opposite contracts**, so one
scenario cannot honestly be the arrange for both. The glyph belongs next to the menu
entry that draws it, which is here.

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

## designs/purge

`features/designs/purge.feature`

saga: [§C6.11 the trash commands, called first](../saga/Chapter_2_The_Colony.md#c611--the-trash-commands-called-before-designing-around-them)

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

## mapping/delete

`features/mapping/delete.feature`

saga: [§C6.30 what a mapping feature is not about](../saga/Chapter_2_The_Colony.md#c630--what-a-mapping-feature-is-not-about)

Removing a team mapping — the admin deletes a mapping from the list (or
`occ penpot_sync:remove-mapping`). This is NOT the "Purge Nextcloud files"
button (that keeps the mapping and never touches Penpot — see purge.feature).
Removing a MAPPING tears down the connection: what happens to the files that
were mirrored through it?

A MAPPING IS A TEAM, AND THAT'S THE ONLY THING THERE IS TO REMOVE (saga §6.24).
There is no "remove the My Stuff project mapping" operation and there coherently
cannot be one: project subfolders are MIRRORED by the pull, not mapped by a human, so
"removing" one just means the next pull recreates it. One mapping object, one
lifecycle.

GRAFANA HAS THIS FILE, N8N DOESN'T — Grafana's exists because its recycle-bin
setting gives removing a mapping a two-path story. This app has no such
setting: Penpot provides its own trash (saga §6.49/§6.52), and it only ever
engages on an explicit "Delete in Penpot" action. Removing a mapping never
deletes anything in Penpot at all, so teardown collapses to ONE rule — but the
file is still needed, because the app DOES provision real folders that a
removed mapping leaves behind.

THE CONTRACT: the mapping goes, and each mirrored design is left in whatever
state its MODE made it worth leaving in. Penpot is never contacted, at any point
— there is no remote state to reconcile, because nothing about a mapping exists
on Penpot's side.

{@see \OCA\PenpotSync\Service\MappingTeardownService} is where it lives, and BOTH
routes call it — `occ penpot_sync:remove-mapping` and the admin panel's delete —
because a teardown on one route only would leave the CLI and the UI producing two
different instances. It runs AFTER the mapping is removed — the mapping OBJECT is
already loaded and that is all `StorageService::findRoot()` needs, and `remove()` is
the half that can throw. Torn down first, a throw would leave the mapping configured
over a tree already dismantled.

IT RUNS UNDER {@see \OCA\PenpotSync\Service\SyncGuard}, and that is not a detail.
Each removal is a `Node::delete()`, which fires the same event a person's delete
does — and `DeleteListener` answers that by putting the design in PENPOT's trash.
Without the guard, removing a link mapping would delete every design it mirrored,
in Penpot, from the one action whose whole promise is that it touches nothing there.

### Removing a mapping keeps what the mode made worth keeping

MODE DECIDES, AND IT DECIDES BY WHAT THE FILE ACTUALLY HOLDS (saga §6.22). The
two scenarios are one rule asked of the two modes, and the code asks it one level
down — of each FILE, "does it hold an archive?" — which is the same answer for
every tree the pull builds (mode is what decides whether a mirror gets bytes) and
the right answer for a tree that is mixed, which reading the mapping's mode would
get wrong. The difference is the archive:

- **`link`** — the designs GO. A link is a zero-byte pointer whose only meaning
  was the mapping, so once the mapping is gone there is nothing left for it to
  be. It is the same reasoning {@see PullService}'s prune already applies to a
  link whose design left the mapping: keeping one offers a restore that
  reconnects to nothing.
- **`sync`** — the designs STAY, and become unmapped. The file holds the design
  itself, and may be the last copy of it in existence. Removing a mapping is an
  administrative act about a connection; destroying somebody's archives is not
  something it gets to do on the way past.

WHATEVER ELSE THE FOLDER HELD IS NOT PART OF THE QUESTION, which is why neither
scenario puts an ordinary file in the tree. Files this app never mirrored were
never the mapping's to touch in either mode, so a row proving it would be
proving something about Nextcloud rather than about the teardown.

**The two modes are not the same case**, which is why there is no single "every
mirrored file goes to the trash and becomes unmapped" rule, and no teardown warning
counting archives at stake. The rule above splits on the one thing that differs, and
the consequence is that a warning would have nothing to warn about: nothing
recoverable is ever removed.

---

## designs/rename

`features/designs/rename.feature`

saga: [§6.54 rename-file works](../saga/Chapter_1_First_Contact.md#654--test-cook-rename-file-works-takes-plain-id-and-accepts-a---which-closes-the-62-fork-and-open-question-48) · [§6.2 the fork it closed](../saga/Chapter_1_First_Contact.md#62--rename-confirmed-both-directions-are-simple-one-is-currently-unimplemented)

Renaming a DESIGN — the mirror file and the Penpot file it points at.
Renaming a PROJECT (the folder) is projects/rename.feature: same gesture, but a
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

**THE NOTE STANDS; THERE IS NO SCENARIO.** A rename Penpot would refuse is
unreachable through the gesture: **Nextcloud's filename rules are strictly tighter
than Penpot's**, so a name the Files app or WebDAV accepts is one Penpot accepts
too. There is no name a user can type that gets far enough to be refused for
Penpot's sake.

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

**The rule is unconditional.** Do not qualify it "in nested mode": there is no
folder-mode field (§C6.36) and no `keyed` alternative for it to contrast with.

Checked live against Nextcloud's IFilenameValidator: the ONLY forbidden
characters are "\" and "/" (plus ".."/"." as segments, ".htaccess", and the
.part/.filepart extensions). Everything else — "a:b", "a*b", "CON",
".hidden" — is a perfectly legal folder name. So this is a two-character
problem, not a general sanitisation problem.

THE APP REJECTS IT AT THE SOURCE where it can: it owns project creation
(projects/create.feature's tag opt-in) and project renames (§6.36), so a "/"
never enters Penpot through this app. What is left is the only case it
cannot reach — a name typed directly in Penpot's own UI.

The invariant under every rename path: the name changes, the identity does
not. A rename that re-created the design would break every mirror, archive
and deep link that points at it.

### Renaming a design that was just copied propagates to Penpot

WALKED BY HAND, AND IT FAILED — but not here. The copy had silently failed
to record its "penpot_id", so this rename correctly ignored an untracked
file and looked like the bug (saga §C6.9). Kept in designs/rename.feature as well
as designs/copy.feature on purpose: the symptom appeared at THIS gesture, so this
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

**BUILT, and the wall moved rather than disappeared.** The notifier is
`lib/Notification/Notifier.php` plus `lib/Service/SyncNotifier.php`, registered in
`Application::register()` and raised from two places: `ImportService` when Penpot
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

### A subfolder was Nextcloud's layout, and now it is a project

This section read: *"Penpot has no concept of a subfolder, so filing a design into
`wip/` changes nothing on its side — and a pull must not undo it, because Nextcloud
owns layout."* The second half is still true and the first half is not the point
any more: Penpot has no subfolders, so a subfolder holding a design becomes a
PROJECT, named by its path. `wip/` is `Move From/wip`.

What survives intact is the confinement rule read from the strict end:
**confinement is to the PROJECT, not to a folder**, which is why a LINK may still
be filed away in a subfolder — promotion is refused under a link, so the mirror
stays in the project it was already in. That is an Examples row rather than its own
scenario, and it is now the only row in that block that does not promote.

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

The Background declares TWO mappings, and no scenario restates a mode. One folder
re-declared per scenario — `sync` in one, `link` in another — is the same folder in
two modes depending which scenario you read.

### The three layers a restore can land in

This is the one place penpot differs from n8n and grafana in kind, not just in
vocabulary: **Penpot has its own trash, with a grace window.** So the far side can
be in three states when a mirror comes back, and each needs something different:

1. **In Penpot's trash** — restore it there. Lossless: the same id, revision,
   history and links. Nothing is imported.
2. **Already back** — someone rescued it in Penpot first. Nothing was lost
   remotely, so nothing is sent; a second restore would be a second design.
3. **Gone for good** — past the grace window, or destroyed. There is nothing to
   put back at the old id, so the archive is IMPORTED and the design starts again.
   See the section below.

Different end states, so three scenarios rather than three Examples rows.

### A restore into a mapping imports what Penpot no longer has

**A RESTORE THAT STOPS AT THE FILE IS THE DECEPTIVE ANSWER.** Putting the file back,
telling the user their design is gone and stopping — asserting `the "Stay Put" Penpot
project holds no design named "Lost"` — sounds like caution. It hands back a folder
that looks restored and a Penpot that is missing the design, which is the state the
gesture was meant to end.

Two things are wrong with that. First, **the file lands inside a mapping**, and
`move.feature` already settled what an archive arriving inside a mapping is: an
import (§6.33), whichever gesture carried it. A restore that refuses is the only
arrival in the app that leaves a mapped folder holding a design Penpot has never
heard of — the exact desynced state the mapping exists to prevent. Second, the
user asked for their design back and the app is holding the only copy of it. A
bell entry saying "it is gone, this file is all you have" is a report about a
thing the app could have simply done.

So layer 3 finishes the restore: import the archive into the project the file came
back into, and stamp the id that comes back. The **new id** is honest and stays
stated in the spec (§6.20 — a Penpot design cannot be resurrected at the id it
had), which is why the metadata row reads `a new one, never the one it arrived
with` rather than `the original id`. Nothing is lost that was not already lost
when the design was destroyed.

**What this does NOT change.** Layers 1 and 2 still come first and are still
preferred, in that order — an import is the last resort, never the shortcut. A
design merely in Penpot's trash is restored losslessly, and a design that never
left is not touched at all. Reaching the import means the app has already proven
Penpot has nothing to restore.

### A design destroyed in Penpot purges its trashed mirror

A mirror belongs in the Nextcloud trash only while there is a design in Penpot for
it to be a mirror OF. Destroy the design and the trash entry stops meaning
anything: the restore beside it can no longer bring that design back, only import
a new one. So the purge is mirrored like every other gesture in this lifecycle,
and the trash is finally symmetrical in both directions.

**AND IT IS NOT A SCHEDULED DELETE OF SOMEONE'S LAST COPY**, which is the objection
worth answering out loud. Destroying a design in Penpot is not something anyone does
by accident — it is the second, deliberate half of a two-step delete, by someone who
already trashed it once. It is the same gesture Nextcloud spells "empty the trash",
which this app has always answered by destroying the design; not answering it in the
other direction is asymmetry rather than caution.

**The constraint that comes with it: never guess.** The reap purges only on three
answers agreeing — absent from the projects the pull just listed, absent from the
team's Penpot trash, and a definite not-found from `get-file-summary`. None is
sufficient alone, and any uncertainty (an unreachable Penpot, a 500, a listing that
could not be read) spares the entry for the next pull to ask about again.

**Read off the running backend rather than inferred** (`app/rpc/commands/files.clj`
and `app/db.clj` in the backend jar):

- `permanently-delete-team-files` does `db/update! :file {:deleted-at request-at}`
  and submits a `delete-object` worker task. So the row survives the call and is
  collected later — both states have to read as "gone".
- `get-team-deleted-files` filters `f.deleted_at > now`, so a destroyed design
  drops out of the trash listing **immediately**, while an ordinarily trashed one
  (`deleted_at` a week out) stays. **That is the difference the reap turns on**,
  and it is the only place in the API where the two are distinguishable.
- Not from a single-file probe, which was the first idea: `get-file-info` looked
  perfect — `::rpc/auth false`, returns `{:id, :deleted-at}` — until `db/get`
  turned out to default `::remove-deleted` to true, and `is-row-deleted?` is
  `(some? deleted-at)`. Any `deleted-at` at all, past or future, and the row is
  dropped and the command 404s. So it answers exactly what `get-file-summary`
  already answers, and neither can tell trashed from destroyed.

**AND ALL THREE CAN BE WRONG AT ONCE.** §6.49/§C6.15: Penpot's restore returns
before its transaction settles, and inside that window a design being restored is
missing from the project listing, missing from the trash listing, and NOT-FOUND to
the probe. Every check agrees and every one is wrong — and the pass runs inside a
pull, which is exactly what follows a restore. So a mirror judged gone is asked
about a second time after the window, and only agreement reaps it. Paid once per
candidate, which in the steady state is never.

**THE HALF THAT IS NOT BUILT, named so nobody reads silence as coverage.** A design
restored in Penpot while its old mirror sits in the trash gets a NEW mirror beside
the trashed one — the pull reaps but does not re-adopt. n8n's `TrashReconcileService`
does both. `Restore a design in Penpot while its file is in the trash` passes either
way, because the trashed twin is not in the folder listing it asserts on, so nothing
in the spec demands the other half yet. **A fork, not an oversight.**

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

Do not end the scenario `And the next pull reconciles the name`. That is the
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

saga: [§6.36 a project folder’s name is its project’s](../saga/Chapter_1_First_Contact.md#636--decision-locked-a-project-folders-name-always-equals-its-penpot-project-name) · [§6.39 renaming a project is its own flow](../saga/Chapter_1_First_Contact.md#639--decision-locked-renaming-a-project-folder-is-its-own-flow-not-a-variant-of-file-rename)

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

**THE OUTLINE HAS NO SELF-NESTING ROW, AND IT USED TO.** `Penpot/Wrapper` renamed to
`Wrapper/inner` was a fourth row here for as long as the scenario was `@todo`, and it
failed the moment it ran — on `there is no folder at "<from>"`, which the outline's
other three rows need and this one makes false. When a project moves INTO a folder
named after itself the source folder does not go anywhere: it stops being the project
and becomes the folder the project now sits in, which is exactly what its new name
says.

So the row was not asking for a different assertion, it was asking for the opposite
of one the outline already shares. `projects/move` carries that case as a scenario of
its own — #a-project-moving-into-a-folder-named-after-itself — where the `absent`
marker on the old folder is the point rather than a contradiction. Two scenarios, not
one row, because the end states genuinely differ.

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

saga: [§6.52 deletion rebuilt on Penpot’s trash](../saga/Chapter_1_First_Contact.md#652--decision-locked-deletion-and-restore-rebuilt-on-penpots-own-trash-replaces-634) · [§C6.15 the command that lies twice](../saga/Chapter_2_The_Colony.md#c615--the-delete-grew-an-undo-and-the-command-it-needs-lies-twice)

RESTORING A DESIGN — out of the Nextcloud trash, out of Penpot's trash, or out
of an archive when both are gone. Restoring a PROJECT is projects/restore.feature.

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

ONE BEHAVIOUR, NOT THREE.

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

**THERE IS NO IN-MAPPING ROW, AND §6.33 IS WHY.** *"Inside a mapping and outside
every mapping alike"* — crossing `Penpot/Stay Put/Loose.penpot` with
`Scratch/Loose.penpot` — is unreachable: an archive arriving inside a mapping is
IMPORTED and becomes a real design, so the file is tracked before the scenario's
`When` ever runs and `the file holds no Penpot metadata at all` is false by the time
it is asked.

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
this file must obey; its live scenarios are in designs/delete.feature.

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
user decision with real consequences, specified in designs/restore.feature. The one
thing that must not happen is quietly doing nothing.

---

### A restore whose follow-up rename fails reports partial success

A restore that cannot come back at its original id is an import plus a rename, and
the two can part company.

ROLLING BACK WOULD BE THE DATA LOSS. The import succeeded — a design the user
asked for is now in Penpot. Deleting it to "clean up" a failed rename destroys
the thing that just worked, so the app keeps it, records the new id against the
local file, and says plainly that the design came back wearing the wrong name.

## projects/purge

`features/projects/purge.feature`

saga: [§C6.19 what Penpot does when you delete a project](../saga/Chapter_2_The_Colony.md#c619--what-penpot-does-when-you-delete-a-project-and-two-things-nobody-had-measured)

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

**AND THERE IS NO TEAM FOLDER ROW EITHER, WHICH IS NOT A CHOICE.** It was written, and
measured on a live instance rather than in CI: two identical project folders were
trashed and purged, one under an admin-folder mapping and one under a Team Folder. The
admin folder's purge destroyed its design and logged doing so; the Team Folder's
produced NO LOG LINE AT ALL. The hook never fired.

That is #a-team-folders-trash-emits-no-purge-signal happening, and that note called
this exact shot — *"if the Team Folder row fails and the admin-folder row passes, this
is why."* groupfolders registers its own `ITrashBackend` whose `removeItem()` unlinks
and emits nothing: no typed event, no legacy hook, no entry point for any app. There
is no code that can be written here, so the row is not `@unbuilt` and not `@blocked` —
it is absent, with the mechanism recorded.

Worth separating from a claim it looks like it contradicts. `designs/purge` DOES run a
Team Folder row green, and that is the OTHER DIRECTION: emptying Penpot's trash and
watching the reap reach back. The reap runs inside the pull and needs no hook at all,
which is exactly why that half works on a backend where this half cannot.

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

IT RUNS NOW, and the last thing it wanted was one verb: DESTROYING a trashed folder.
`TrashedFolder` shipped with a `restore` closure and no `purge` one, on the argument
that a purge reachable from the revive path is a purge called by accident. It carries
both now, and the guard moved to the caller, where the rule was always going to live.

THE REAP DECIDES, and it decides about the FOLDER because the trash offers no smaller
unit — a trashed folder's designs are nested inside that one item, not beside it, so
{@see TrashControl::listTrashed()} never sees them and the file pass cannot reach
them. `listTrashFolder()` on the item's own backend is the only door that opens.

EVERY DESIGN, NOT ANY. One still recoverable in Penpot is one reason the folder is
still worth something — restoring it would bring the project back, which is what
`projects/restore` asserts. So the folder goes only when `isGone()`, which answers
false whenever it cannot tell, is true of all of them.

### A design's own deletion is what makes it destroyable

**THE ORDER OF TWO DELETES DECIDES WHETHER A THIRD ONE WORKS**, measured on a live
Penpot across four runs:

| the design got its own `deleted_at` | `permanently-delete-team-files` | restorable after |
|---|---|---|
| never — only its project was deleted | stamps it, but the restore revives both | **yes** |
| **before** — while the project was still live | destroys it | **no** |

So a file is reliably destroyable only if it was deleted in its own right first. That
is why {@see DeletionService::onFolderTrashed()} calls `delete-file` on every design
below the folder BEFORE `delete-project`.

### The trash listing does answer, and the answer is a field

**`get-team-deleted-files` returns RECORDS, not a set of ids, and one of the fields
is the whole question.** This was recorded here for two PRs as an unanswerable
question — a design destroyed while its project is deleted goes on being named in
that listing, so "is it still there?" cannot separate destroyed from recoverable, and
`fileExists()` cannot either (`get-file-summary` answers NOT-FOUND for any row with a
`deleted_at`, past or future). All true. All beside the point, because nothing had
looked at the record.

`will-be-deleted-at` is three-valued, and measured live:

| the record says | what it means | recoverable |
|---|---|---|
| no such key | the file itself was never deleted; it is listed because its PROJECT is | **yes** — and restoring it revives the project |
| a stamp a week out | in the trash proper | **yes** |
| a stamp that has PASSED | destroyed: the destroy sets the clock to now and a collector takes the row later | **no** |

The third row is not a guess. A destroyed design was handed to
`restore-deleted-team-files`, which reported success, named the id in its `end`
payload, and left it exactly where it was — still listed, still stamped in the past,
never live again.

So {@see PenpotClient::recoverableFileIds()} is that reading, and it is what every
caller asking "will Penpot give this back?" now uses. {@see TrashReconcileService}
counted ids instead, which is why it spared a folder whose designs were every one of
them gone, for ever, since nothing about that state changes on its own.

ONE CALLER DELIBERATELY KEEPS THE RAW LISTING: {@see PullService::penpotTrashIds()},
which drives the prune. A wider set there means more mirrors KEPT, which is the
direction that method already fails in, and narrowing it would have the prune start
deleting mirrors on a rule `designs/purge` owns.

A CLOCK THAT DISAGREES SPARES THE DESIGN. The comparison is against this host's
clock and Penpot's may differ; a destroyed design carries Penpot's own "now", so a
host running behind reads it as future and spares a mirror rather than destroying
one. No grace period corrects a skew that already fails safe.

<!-- Both Penpot-side scenarios in `projects/purge.feature` were `@blocked` on the
     claim that this could not be decided. Four rounds of measurement, every one of
     them asking whether an id was present, and the answer was one field away. -->

### A purge Penpot cannot be told about still empties the bin

Emptying the Nextcloud trash is not this app's to refuse — that half has already
happened by the time anything here runs, and the legacy `preDelete` hook cannot
cleanly abort it anyway. So an unreachable Penpot costs the far half only: nothing is
destroyed over there, and the designs stay in Penpot's own trash until it expires
them on its own schedule.

Which is the safe direction, and the same one every other "Penpot is unreachable" row
takes. A purge that guessed would be guessing about the one irreversible thing this
app can cause.

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

saga: [§C6.19 what Penpot does when you delete a project](../saga/Chapter_2_The_Colony.md#c619--what-penpot-does-when-you-delete-a-project-and-two-things-nobody-had-measured)

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

**BOTH SCENARIOS HERE WERE TAGGED `@todo`, AND NEITHER HAD ANY CODE BEHIND IT.**
`@todo` means "the behaviour exists, only the test is missing", and this file was
five lines of `lib/` short of that in both directions: `RestoreFromTrashListener`
returned early on anything that was not a `File`, `RestoreService` had no folder
entry point, and `TrashControl` could list and purge a trashed file but had no way
to take one back out. They were `@unbuilt` and nobody had re-read them since. This
is the third round in a row where a status tag turned out to be wrong in one
direction or the other — see the saga.

HOW IT WORKS, in the order it has to happen. Core announces ONE node for a folder
restore and nothing at all for the designs inside it, which is the same wall
`DeletionService::onFolderTrashed()` meets going the other way, so the walk is the
app's own. It settles every project folder FIRST — asking Penpot's trash once per
tree whether any design of that project is still recoverable, and making the project
again only when none is — and only then hands each design to the ordinary
`onRestored()`. That order is load-bearing: a purged design comes back by IMPORT
into the project its folder names, and until the folder has been re-stamped that is
a project Penpot has deleted.

An unreadable Penpot trash makes NOTHING again. "No design can revive this project"
is exactly what a failed listing looks like, and acting on it would stand a second
project beside one that was about to come back on its own, with the user's history
stranded in the old one. The mapping root is carved out for the same reason
`onFolderTrashed()` carves it out: a walk that started there would reach every
project in the team at once.

EVERY EXAMPLES ROW NAMES ITS OWN PROJECT — `Parked` and `Empty` — and that is not
decoration. Penpot state accumulates across a leg, so a row that left a project
standing would hand the next row a folder that is not in the state its `held` clause
claims.

**THERE IS NO `Penpot has purged` ROW, AND THE REASON IS §C6.11's.** It was written,
it ran, and it failed — the folder came back wearing its ORIGINAL id, because the
design the arrange had destroyed was recoverable again by the time the restore
happened. Destroying a design only stamps `deleted_at` to now and leaves the row
(#penpots-destroy-leaves-the-row-behind), so the `delete-project` that trashing the
folder triggers re-stamps it a week out and puts it straight back in
`get-team-deleted-files`. The app then restored it, which revived the project, which
is the correct answer to the state Penpot was actually in.

So the row asked for something the gesture under test destroys on its way past: the
purge has to precede the trash, and the trash undoes the purge. It is the same wall
`Trash a design that is already gone from Penpot` sits behind as `@blocked` — *there
is no id Penpot will report as gone rather than deleted* — and the rule loses
nothing, because `no designs at all` reaches the same end state by a road that holds
still.

### Restoring one design brings its project with it

Penpot clears a project's deletion when any file inside it comes back, so restoring
ONE design revives the project and lifts its folder out of the Nextcloud trash.
Restoring two, or a hundred, says nothing further — which is why there is no separate
scenario for restoring "the project's designs". You cannot restore a project; you can
only restore files, one set at a time, and the first one already did the interesting
part.

NOT A SCENARIO — *a trashed project's designs come back with the folder, spreadsheet
included*. True, but it is Nextcloud's doing rather than this app's, and it is the same
restore as any other. If it earns a scenario anywhere it is `designs/restore`, where a
single design coming back is the subject.

**THE HARNESS OWES THIS ONE §6.49's DISCIPLINE, AND FIRST IT DIDN'T.** Restoring a
design in Penpot revives its project — but the restore's SSE returns before the
transaction settles, so the design leaves `get-team-deleted-files` while the project
is still deleted, and a second call is what settles it. The step confirmed against
the TRASH, returned after one call, and pulled into that window; the pull saw no such
project, never looked for its folder, and the failure read as the revive not working.
It confirms against the PROJECT listing now, which is the oracle `RestoreService`
already uses and `RestoreServiceTest` already pins for the app.

**THIS SCENARIO CLOSES HALF OF §6.37**, the fork `PullService` has carried since the
Nextcloud trash became readable. `TrashReconcileService` REAPS — it destroys a trashed
mirror whose design Penpot has destroyed — and the symmetrical case, a trashed mirror
whose far side came BACK, had no scenario asking for it and so was left open. It has
one now, and the pull answers it: before provisioning a folder for a project it has
no folder for, it looks in the trash for the folder that project already had.

AT THE FOLDER LEVEL, DELIBERATELY. Trashing `Penpot/Revived` puts ONE thing in the
trash — the folder — and the designs under it are nested inside that item rather than
beside it, which is why `TrashControl` refuses to descend and why there is no trashed
`Alpha.penpot` for a design-level revive to find. The folder comes back whole, which
is the only way Nextcloud offers and the only way that cannot hand somebody a partial
restore. A design whose SIBLINGS are still deleted needs nothing special: their
mirrors return with the folder and the same pull's prune trashes them again, which is
the truthful answer.

The REMAINING half of §6.37 is a single design whose mirror was trashed on its own
and whose design then came back. That mirror is still re-created beside the trashed
one rather than matched to it by `penpot_id`. Untidy, not lossy, and no scenario asks
for it yet.

**THE PROJECT IS CALLED `Revived` AND IT MAY NEVER BE CALLED `Doomed` AGAIN.** This is
the only scenario in the suite that asserts a path is NOT in the Nextcloud trash, and
nothing empties that trash between scenarios — so the claim is about the trash of the
whole LEG, not of this scenario. `projects/delete.feature` runs first in `project-trash`
and finishes with `Penpot/Doomed` sitting in the trash on purpose (*"is recoverable
from the Nextcloud trash"*), which the poll here would have found forever. The name is
the entire fix, and the rule it belongs to is one file wider than the one the Examples
table taught: **a fixture name is shared across every file in a leg, not just across
the rows of one table.**

---
## connection/sync-now

`features/connection/sync-now.feature`

saga: [§C6.28 the pull is not a feature](../saga/Chapter_2_The_Colony.md#c628--reconcilefeature-was-never-a-feature)

TWO SCENARIOS — one per direction — and the shape came from the siblings: grafana
and n8n both carry two, because the whole tree is the assertion and everything else
is a row of the Background.

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

- **adoption** — `/Penpot/Cogs` was in the Background and is in the result. No
  `Cogs (2)` appears, which is the whole of the old `A folder already named like a
  Penpot project is adopted, not duplicated`. Adoption takes a BARE folder only: one
  already carrying markers belongs to another project, and re-stamping it would hand
  one project's designs to another;
- **untouched content** — `notes.txt` and `plan.txt` go in and come out, which is the
  whole of `A sync leaves content it does not manage alone`;
- **Drafts** — `Loose Idea.penpot` surfaces at the mapping root and no `Drafts` folder
  is created, because Drafts is a state rather than a place;
- **the path model** — the project named `Region/Deep` arrives as two folders, and only
  the deeper one carries the project id. `/Penpot/Region` is scaffolding: it holds no
  design and is nobody's project;
- **every storage kind and mode at once** — an admin folder, a Team Folder and a link
  team, in one table.

`exactly` is what makes it work. A table that only listed what should appear could not
have caught a stray `Cogs (2)` sitting beside the real one.

### The first sync to Penpot makes designs of the files already there

THE OTHER DIRECTION EXISTS. §6.1 forbids pushing SHAPE DATA into a design Penpot
already has — nothing more. Creating a project, renaming one, importing a whole
archive as a new design: the app does all of these, and the gesture features are full
of them.

<!-- Read more broadly, §6.1 was taken to rule the push out entirely, in three
     files. Saga Chapter 3, Round 8 (../saga/Chapter_3_Building_To_Plan.md#round-8--the-push-and-eleven-runs-spent-on-one-test). -->

So sync-now has two buttons, as both siblings do. The push takes a `.penpot` sitting
in a mapped folder that Penpot has never seen and makes a design of it, in the project
the folder spells. It never touches a design Penpot already holds.

BUILT, AND IT GOES THROUGH THE IMPORT DOOR. `BulkPushService` walks each `sync`
mapping and hands every archive that is not already a mirror to the same
`ImportService::adopt()` a dragged-in file uses, with the destination resolved by the
same `DestinationResolver::projectForContentIn()` — so the project a folder spells,
Drafts at the mapping root (§6.35), and the path model (§C6.38) all come for free
rather than being answered a second time.

AN `unmapped` FILE IS PUSHED, and this is where the siblings stop being the bar.
Grafana skips unmapped files in its push because one KEEPS ITS UID and reattaches to
the same dashboard, so a re-arrival needs no decision. Penpot cannot reattach at all
(§6.20, and `import-binfile` always mints a new id) — which is why *"an arrival
becomes its own design, whatever it arrived carrying"* exists in `designs/move.feature`.
Skipping them would strand real bytes in a mapped folder that nothing in Penpot
answers to, which is the state this button clears.

A link team is deliberately absent from the push's fixtures. Its contents come from
Penpot and from nowhere else, so there is nothing for a push to do there — and putting
an untracked file under one would have been asking a question `designs/create` already
refuses.

### The Background is only what both scenarios share

Which is the mappings, and nothing else. Carrying both sides of the picture —
everything Penpot held, everything Nextcloud held — makes each scenario responsible
for the other's fixtures: the pull drags along the archive the push needs, and the
push drags along six designs it never looks at.

Worse, it made the Background the thing under test. `connection/sync-now.feature` is
the only file in the suite whose Background IS its fixture, and every failure this
feature had traced back to that: an arrange that clears a mapped folder between
scenarios is harmless when each scenario seeds its own design, and destroys the
subject when the Background holds it.

So each direction now states its own side. The pull says what Penpot has and what
little Nextcloud has; the push says the one archive it is about. Both are shorter,
neither can be broken by the other, and the Background says only the thing they
genuinely agree on.

### One actor, not an Outline

The pull scenario had two Examples rows, `the admin` and `the schedule`. It is gone,
and the reason is this file's Background: it IS the fixture. A second row re-runs
that Background against a folder the first row has already mirrored into, and the
arrange clears a mapped folder before re-mapping it — which, in penpot, deletes the
designs in Penpot ({@see DeletionService} fires on any file carrying a `penpot_id`,
including one the unmap left `unmapped`).

Five attempts to make the shared `emptyMappedFolder()` safe for that all failed, and
three of them broke other legs. The function is fine; ten features depend on it and
they all seed per scenario. This file was asking it for something it was never
built to give.

WHAT THE SECOND ROW PROVED is that a timer starts the same run as a button — which
is a claim about the TRIGGER, and triggers are `connection/admin.feature`'s subject.
What a sync DOES is this file's, and one actor says it.

### Every noun in this file is unique to it

`Design Team`, `Cogs`, `Gizmo`, `Doohickey` — this feature shared all four with
`mapping/sync-now.feature`, which sits in the SAME LEG and maps that team to its own
folder. One Penpot, one Nextcloud, two features legitimately doing different things
to the same objects.

Necessary but not sufficient — the Outline and the shared Background were the rest
of it. Still, no harness fix could ever have made this file pass while the names
collided: the other feature was entitled to reshape the team, and this one asserts
`exactly`.

So the nouns are now this file's alone — `Everything Team` / `Everything Shared` /
`Everything Linked`, folders `All Sync` / `All Team` / `All Link`, projects
`Widgets` and `Deep/Nested`, designs `Sprocket A`, `Sprocket B`, `Buried`,
`Stray Sketch`, `Ebb`, `Riveted`, `Local Only`. Before adding a fixture here, grep
the suite for its name; a leg shares one instance, and `exactly` cannot survive a
neighbour.

THE GENERAL RULE: reused names are safe for a feature that asserts only its own
rows, and unsafe for one that asserts a whole tree. This is the only file in the
suite doing the latter.

### Two bugs, one symptom — and why that took eleven runs

The leg failed at 30/32 with `Gizmo` and `Doohickey` missing. TWO independent
faults produced that same line, which is why fixing either one alone left the
number unchanged and made each fix look wrong.

**1. The mapping step emptied the folder while a mapping was live.**
`theFollowingMappingsWereMade()` empties each mapped folder before mapping it. The
unmap beside it is latched by `$mappingsDeclared`; the emptying was not — so on the
second row it ran again with the first row's mappings still LIVE, and a delete
inside a live mapping is a gesture `DeleteListener` carries into Penpot. A probe
caught it between two adjacent steps:

    [nextcloudHolds START]  Cogs[Hand Made|Doohickey|Gizmo] Drafts[Loose Idea]
    [sync step START]       Drafts[]

THE CURE WAS NOT IN THE HARNESS, and five attempts to put it there are the reason
this took an evening. Latching per scenario does nothing (Behat fires
`@BeforeScenario` once per Examples ROW, so it clears before the row that needs
it). Latching per RUN fixed this leg and broke four others — a folder emptied once
and never again accumulates every later scenario's leftovers, which is the
`Pinned (1) (2) (3)` problem the function exists to prevent. Asking whether the
folder is mapped is always false, because the unmap runs first. Disabling the app
around the delete works and is a sledgehammer aimed at the wrong wall.

All of it was reverted. `emptyMappedFolder()` is untouched, and the fix is three
lines of Gherkin: the Scenario Outline is gone (see *One actor, not an Outline*)
and each scenario states its own pre-state (see *The Background is only what both
scenarios share*), so nothing re-runs the Background against a folder a previous
row has already mirrored into.

Ten feature files survive the hazard because their scenarios seed per row; only
this one, whose BACKGROUND held what the assertion checks, could notice. **Grafana and n8n
pass the same Gherkin because their mapping step does not empty at all** — the
emptying is a penpot addition for its reused folder names, and outlines are where
it bites.

**2. `get-projects` does not filter soft-deleted projects (§6.42).**
Which meant the Background's find-or-create read the dead `Cogs` left by fault 1,
saw the designs it still listed, and skipped re-seeding — hiding the damage. Use
`get-all-projects`, and expect camelCase keys because `penpotRpcRead()` sends
`Accept: application/json`. `ArrangeSteps` documents both; this trait had not
learned them.

MEASURE, DO NOT REASON. Five diagnoses were argued from the symptom and every one
was wrong-or-partial; one probe printing Penpot's actual contents at each step
boundary settled it in a single run. When a fix does not move the number, suspect a
second fault before concluding the first was wrong.

## mapping/manage-groups

`features/mapping/manage-groups.feature`

saga: [§C6.34 the folder owns its groups](../saga/Chapter_2_The_Colony.md#c634--the-folder-owns-its-groups-the-mapping-should-not) · [§C6.35 groups are a pass-through](../saga/Chapter_2_The_Colony.md#c635--do-not-store-what-you-can-read-groups-become-a-pass-through)

THE ONE FIELD A MAPPING LETS YOU EDIT. Everything else — the team, the folder,
the storage backend, the default mode — is fixed at creation, because changing it
would force a live migration of already-mirrored content. Split out of
`mapping/create.feature` so the editable field is not buried among the immutable
ones.

The groups are the FOLDER'S, not the mapping's: the app applies them when it
provisions, then reads back whatever the folder says. Re-share it from Files or
with `occ` and this app reports the change; a sync never puts back a group you
removed. Both storage backends get their own Examples block because the
provisioning differs and the behaviour must not.

## mapping/view

`features/mapping/view.feature`

saga: [§C6.29 two names for a team](../saga/Chapter_2_The_Colony.md#c629--two-names-for-a-team-one-name-for-a-project)

Looking at what is mapped. Small today, and the interesting case is the one that
is here: a team renamed in Penpot must not rename the folder an admin chose. The
mapping is keyed on the team id, so it keeps resolving; the folder name was never
Penpot's to set.

## mapping/sync-now

`features/mapping/sync-now.feature`

saga: [§C6.28 the pull is not a feature](../saga/Chapter_2_The_Colony.md#c628--reconcilefeature-was-never-a-feature)

THE CARD'S OWN BUTTON — one mapping, on demand.

### Syncing one mapping brings its projects and designs into Nextcloud

SEPARATE FROM THE INSTANCE-WIDE ONE, and not as a third Examples row beside "every
mapping" and "the schedule". The end state is the same, but the scope IS the
difference, and a mapping-scoped action belongs with the mapping.
`connection/sync-now.feature` keeps the two that walk everything.

The folder differs from the instance-wide scenarios' on purpose: they clear the
mapping store between runs, so distinct folders stop one file's leftovers reading
as another's result.
