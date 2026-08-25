<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# How the feature files are organised

These are the app's specification, written before the code and kept true after
it. This file is the map: what belongs where, and what the tags mean.

## Two documents, and which one you want

| | |
|---|---|
| **README.md** (this file) | How the suite is ORGANISED — which file owns which behaviour, what each tag means, which scenarios CI runs, how the backends are covered. Read it to find your way around. |
| **[AGENTS.md](AGENTS.md)** | WHY each scenario is the way it is — the decision it encodes, what it replaced, what was deliberately left out. One section per feature file, and every `.feature` links to its section on line 1. |

The split exists because Gherkin is meant to be read as specification. A scenario
should be legible at a glance and a comment should add scope or a caveat, not
carry an essay — so the essays live in `AGENTS.md` and the feature files point at
them. If you change a behaviour, change its note in the same commit: a note
describing the old behaviour is worse than no note.

## The organising rule: a feature is a BEHAVIOUR, not a mechanism

A feature file answers *"what happens when someone moves a design?"* — every way
a design can be moved, in either system, with the consequence on the other side.
It does not answer *"what does the pull do?"*, because a user does not think in
pulls.

That distinction is the whole layout. Before it, finding "how does move work"
meant reading `move-design.feature` (now `designs/move.feature`) for the Nextcloud half, `reconcile.feature` for the
Penpot half, and `gestures.feature` for the two that CI could actually prove.
Three files, one behaviour, and no single place that told you the shape of it.

**The folder is the noun; the file is the verb.** Four folders, and everything in
one of them acts on the same kind of thing:

| Folder | The noun | The verbs |
|---|---|---|
| [`designs/`](designs/) | a `.penpot` file | create, view, edit, copy, move, rename, delete, restore, purge, open-with |
| [`projects/`](projects/) | a project folder | create, view, copy, move, rename, delete, restore |
| [`mapping/`](mapping/) | a mapping | create, view, manage-groups, delete, sync-now |
| [`connection/`](connection/) | the instance | admin, personal, sync-now |

So `designs/move.feature` answers *"what happens when someone moves a design?"* —
every way it can be moved, in either system, with the consequence on the other
side. There is no file that answers *"what does the pull do?"*, because nobody
thinks in pulls.

**A mapping is configuration, not content**, which is why it gets the same verb
treatment as a noun: creating one, looking at one, changing the one field that is
editable, tearing one down, and the two things you can ask one to do. It used to
be `admin-mapping.feature` — one file for five verbs, exactly the shape the
design/project split had already been fixed out of.

**`connection/` is the instance-wide half**, and it holds the personal token too:
an admin connects the instance (a URL, a credential and a schedule, as one form),
a user connects only themselves (a token against the URL the admin gave).
Different pre-state, different end state, so separate scenarios — the same act,
so the same folder.

**A connection is one fact, so it is one table.** The URL, the token and the
schedule are inputs to "the app is connected", not three behaviours; an interval
is a setting, not something anyone performs.

What stays at the top level is `lifecycle.feature` alone — the app enabling,
disabling and being removed belongs to no noun in the app.

**Personal projects have no folder of their own**, deliberately. They are the
ordinary rules with a different mapping: a design in a personal project is
created, moved, renamed and deleted by exactly the scenarios in `designs/`. The
only thing that differs is that setting a personal token maps the user's home
root to their personal team — which is `connection/personal.feature`'s.

**The nearest-ancestor rule has no file**, and that is deliberate. It is the
app's most load-bearing decision, and it is a RULE — only ever observed through a
gesture: you move something and it still belongs, you create something and it
lands in Drafts. Every scenario that tried to test it directly turned out to be a
move or a create that already existed elsewhere. The rule is written down in
[`AGENTS.md`](AGENTS.md#mapping-membership--retired).

## The two nouns: a DESIGN and a PROJECT

Six verbs, two nouns, twelve files. A design is a `.penpot` file; a project is a
**folder**, because a folder is the only thing a Penpot project can be in
Nextcloud.

Every verb file names its noun, so "what happens when I rename X?" has one place
to look, and the two answers sit side by side. They are genuinely different
behaviours rather than one with a branch:

| verb | design | project |
|---|---|---|
| copy | duplicates it in Penpot | a new project holding new designs |
| move | anywhere its project reaches | anywhere at all — the path is the name |
| delete | one design | one call that takes the whole project with it |
| restore | the same design, id intact | the project *and* everything in it, or not at all |

**Two rows of that table were wrong until §C6.38, and both were wrong about the
API rather than about the design.** `move` said *pinned inside its team folder*
and `copy` said *refused — Penpot has no duplicate-project call*. Penpot has
`move-project` (`{project-id, team-id}`) and `duplicate-project`
(`{project-id, name?}`), both since 1.16; the schemas are in
`app/rpc/commands/management.clj`. A limit that was never checked became a rule,
the rule was written into a table, and the table was then cited as the reason to
keep the limit. `projects/move.feature` and `projects/copy.feature` had already
been rewritten to say what the app should do — this table was the last place
still saying otherwise.

A **personal** project is still a project: the verbs behave identically, so the
only special things are the who (the user's own token) and the where (their home
root) — and both are settled the moment the token is saved, which is why they
live in `connection/personal.feature` and not in a file of their own.

Splitting them is also what makes the CI suites possible — the filename is the
matrix axis (`tests/integration/behat.dist.yml`), because a **path partition
cannot leak the way a tag partition does**: measured over the live scenarios, 28
carry no channel tag and 32 no origin tag, so any tag split would silently stop
running them.

**The behaviour is the axis, not the kind of thing acted on.** `create-project.feature`
used to own every verb a project folder could be on the receiving end of —
renaming one, copying one, moving one, deleting one. That is the same mistake
`gestures.feature` made in the other direction, and it cost the same thing:
"what happens when I rename a project folder?" had two answers in two files, and
the two had already drifted. `rename-design.feature` had *live* coverage of the
project-folder rename while `create-project.feature` still called it unbuilt;
`move-design.feature` had all four project-folder move scenarios; `copy-design.feature` had the
refusal plus a comment pointing back for reasons it could simply have stated.

A project folder is not a separate universe. Renaming one is a RENAME, and it
belongs beside the other rename where a reader comparing the two sees the whole
table at once. What is left in `create-project.feature` is the one thing no
behaviour file can own: a folder's **identity** as a project — how it acquires
one, and the marker that says so.

**The sync itself** — whose actors are an admin and the clock:

| File | Owns |
|---|---|
| `connection/sync-now.feature` | An instance-wide sync — the section's button, and the schedule doing it unasked |
| `mapping/sync-now.feature` | The card's own button: one mapping, on demand |

A scenario belongs in either only when someone ASKED for a sync. "A design
renamed in Penpot reaches Nextcloud" is a rename — it lives in
`designs/rename.feature`, where the sync is how the news travels, not the point.

These were one file, and before that `reconcile.feature`, named after the code
that runs it. That was a mechanism wearing a feature file, and it collected 34
scenarios that mostly belonged to the behaviours they travelled through. The
split into two is by SCOPE, which is the only thing that differs between them —
see AGENTS.md.

## Tags

Two orthogonal axes, because the same verb means two different things depending
on where it happened — and the interesting part is always the *other* side.

### Origin — where the action happened

| Tag | Meaning |
|---|---|
| `@in-nextcloud` | Someone acted in Nextcloud. The scenario's payoff is what reached Penpot. |
| `@in-penpot` | The design changed in Penpot (by a human, another client, or Penpot itself). The scenario's payoff is what reached Nextcloud, and a sync run is implied. |

A scenario with neither is about something that never crosses the boundary —
configuration, a refusal, a local-only surface.

### Channel — how it was triggered

| Tag | Meaning |
|---|---|
| `@gesture` | A Files-app gesture: drag, rename, delete, restore, "+ New", upload. Driven over WebDAV, which is what a browser sends. |
| `@occ` | A CLI command. |
| `@admin` | The admin settings panel. |
| `@scheduled` | The timed job, with no human present. |

`@gesture` is what `gestures.feature` used to be. It was a file, which meant
every gesture scenario lived away from the behaviour it demonstrated; as a tag,
`behat --tags @gesture` gives the same collection back without splitting any
feature. Nothing is lost and the scenarios sit next to their own kind.

### Storage backend — a CELL IN THE MAPPING TABLE, and no longer an axis

Every behaviour is valid on both a plain (admin-owned) folder and a Team Folder,
and almost none of them has a different outcome. So the backend has to be covered
without being *written down twice*. Three ways were tried, in this order:

| | |
|---|---|
| duplicate each scenario, tagged `@team-folder` / `@plain-folder` | two identical blocks that prove nothing, ×97 |
| a matrix axis + a dynamic Background — `OccTrait::backendFlags()` reading `PENPOT_TEST_BACKEND`, the spec silent | worked, and cost every scenario the ability to SAY which folder it wanted |
| **a `storage` cell in the mapping table** | ✓ |

**What the axis bought, and what it cost.** It was genuinely right for a while,
and it found things: the structural scenarios in `sync-now.feature` had mapped a
Team Folder and passed only because of where they sat in the run — moved, the
folder resolved to nothing, and Team Folder provisioning turned out never to have
been covered at all. No new scenario would have caught that; pointing an existing
one at the other backend did. The same dimension disproved §C6.27 (below).

What it cost is that the backend was something the RUN decided and the spec could
not mention. A scenario that genuinely wanted a Team Folder had no way to ask, so
it needed a `@plain-folder` / `@team-folder` tag to opt out of the leg that could
not host it — a tag that exists to skip a leg is the duplication coming back in
through the tag system.

**So groupfolders is installed on every leg** (the same thing the n8n and Grafana
suites do), and the storage kind is stated where it belongs — in the mapping:

```gherkin
    And a mapping with the following values:
      | team    | Northwind    |
      | folder  | <folder>     |
      | storage | <storage>    |
```

`mapping/manage-groups.feature` is the worked example: one outline, two `Examples`
blocks, one per backend, and the two are compared in a single run instead of
across two legs that never meet. `OccTrait::backendFlags()` survives as the
default for the steps that do not name a storage — with no `PENPOT_TEST_BACKEND`
set it resolves to a plain shared folder, which is what a mapping gets when the
scenario does not care.

The objection this replaced was **"a table can only vary the mapping, and the
difference is whether groupfolders is installed on the server"** — true while the
app was conditional, and no longer true now that it is always there. The residual
cost is honest and small: the plain rows exercise a plain folder on a server that
*has* groupfolders installed, which is not every deployment. That is the price of
being able to ask, and it is cheaper than a tag per scenario.

Where a backend genuinely changes an OUTCOME it still earns its own scenario,
because then the two rows would *not* be identical. There are two confirmed cases:

- **§C6.16** — on a shared mapping a pruned mirror goes to the **owner's** trash,
  not the acting user's.
- ~~**§C6.27** — emptying a Team Folder's trash cannot reach Penpot at all.~~
  DISPROVED: with Team Folders installed on every leg and the storage stated in
  the mapping table, the Team Folder purge reached Penpot exactly like the plain
  one. See features/AGENTS.md#a-team-folders-trash-reaches-penpot-after-all.
  groupfolders registers its own `ITrashBackend` whose `removeItem()` emits
  nothing — no typed event, no legacy hook — so no app can observe it. It
  self-corrects, because Penpot's own trash expires after 7 days, so the
  divergence is a window rather than a permanent state.

Both were found by RUNNING the suite across both backends rather than reasoning
about them — neither needed a new scenario to be discovered, only an existing one
pointed at the other backend. That is the argument the axis won on, and it is why
the `storage` cell has to keep both kinds reachable rather than quietly settling
on whichever one the harness defaults to.

### `sync` vs `link` — a restriction in one direction, not an axis

The tempting move is to write every behaviour twice, once per mode. Don't: the
modes only diverge in one direction.

* **`@in-penpot` scenarios are mode-agnostic.** A design renamed, moved or
  deleted in Penpot reaches Nextcloud the same way whichever mode the mirror is
  in — a `link` simply has no bytes to update. These scenarios should not
  mention mode at all, and writing them twice duplicates a rule that never
  forks.
* **`@in-nextcloud` scenarios branch.** A `link` is a read-only projection, so
  it is confined to its project: moves out are refused, and there is nothing to
  copy. That is a business rule, and it is stated as one.

The test: can you write the restriction as a sentence beginning *"A link…"*? If
yes it is a rule worth its own section. If mode makes no difference to the
outcome, leave it out.

### Status — four tags, and only one of them is a backlog

`@todo` used to mean four different things at once: the test is unwritten, the
*feature* is unwritten, the harness cannot reach it, and there is nothing to
execute in the first place. Those need four different people to do four
different things, and lumping them together meant that "what is actually built
but untested?" — the most useful question you can ask of a spec — took a
hand-analysis every time it was asked.

| Tag | Meaning | What to do about it |
|---|---|---|
| *(none)* | Runs in CI. | Keep it green. |
| `@todo` | **The code exists; only the test is missing.** | Write the test. |
| `@unbuilt` | A spec awaiting code. | Build the feature. |
| `@blocked` | Real behaviour this harness cannot reach. | Build the harness capability — or accept it. |
| `@decision` | Records a deliberate absence. There is no operation. | Nothing, ever. |

All four are excluded from the run. The distinction is not about whether a
scenario executes today; it is about **who picks it up**.

**`behat --tags @todo` is the work queue.** That is the whole point of narrowing
it: the list is now things a person can sit down and do, with no triage step in
front of it.

#### Why the queue is one flat list, and how a scenario earns its status back

The reorganisation this file describes — the folder became the noun, the file
became the verb, `reconcile.feature` stopped being a feature — rewrote the spec
faster than the harness could follow. Nine scenarios were still running when it
landed, and the step vocabulary underneath them had moved. The choice was to
re-triage 116 scenarios inside the PR that did the rewriting, or to collapse them
to one flat queue and let each PR that implements a behaviour restore that
scenario's real status. **The second, because triage done from the spec alone is a
guess, and the honest answer for each one is found by trying to make it pass.**

**Chapter 3 Round 1 drew the first nine** — the ones green in CI the moment before
the collapse: the two admin-connection scenarios, enabling and disabling the app,
the three mapping-creation ones, changing a mapped folder's groups, and syncing
one mapping. They prove the HARNESS rather than any behaviour, which is what made
them the only sane place to break ground. Where the suite stands now:

| status | scenarios | |
|---|---|---|
| *(none)* — runs in CI | 26 | 67 executed: `admin` 25, `design` 19, `project` 21, `core` 2 |
| `@todo` | 66 | the queue |
| `@blocked` | 11 | no browser, no app removal, no way to author a design |
| `@unbuilt` | 11 | the app disagrees with the spec; see below |
| `@decision` | 0 | |

**The `project` leg nearly doubled without a single test being written for it.**
§C6.38 reversed a rule — a project folder was pinned inside its team folder — and
scenarios moved straight from `@unbuilt` to green because the code caught up with
what they already said. That is the payoff of tagging honestly: the work queue told
the truth about what was owed, so the PR that paid it needed no re-triage.

It also cuts the other way in the same file. A fourth scenario was promoted, failed
in CI, and went back to `@unbuilt` naming a wall nobody had measured — a move into a
Team Folder crosses a storage boundary and fires no rename event at all. **That
round trip is the point**: promoting it was how the wall got found, and the tag now
records a fact rather than an assumption.

**The `design` leg then nearly doubled too, and the tests were the small half.**
`designs/move.feature` held thirteen scenarios and ran none; the round promoted two
and retagged six, and only ONE of the two was a test anyone could simply have
written. `Move a design between projects` was held off the run by a defect in the
**spec** — its shared `Then` demanded `content | an archive` of both Examples
blocks, and the second block moves a `link`, which holds zero bytes on purpose. The
row now reads `the mapping's body`, so both modes can answer the same claim.

So the queue shrank by eight and gained two passing scenarios. **That gap is the
finding, not an accounting error**: `@todo` means *the code exists and only the
test is missing*, and six of these were something else — four places the app
contradicts the spec, and two the harness cannot reach without a browser. A queue
that cannot be read at face value costs more than a short one.

**All four legs report tests now**, so the empty-suite exemption in the workflow
no longer carries any of them. It stays, because it is self-healing in both
directions and the next spec-first feature file will empty a leg again.

**Round 1 also found two things the rewrite had dropped**, which is the argument
for running a scenario rather than reading it:

* `connection/admin.feature` lost `And nothing is configured yet` from its
  Background. Commit `466f92d` had added that line on purpose — *"a bad URL names
  the url field, and the Background starts blank"* — because the bad-URL row
  relies on it: `set-url` refuses a URL it cannot build requests from, so nothing
  is stored, and the health check must fail on a MISSING url rather than on the
  good one the row above left behind. Restored.
* `lifecycle.feature`'s "Removing the app" was flattened from `@blocked` to
  `@todo` along with everything else, even though the comment above it names its
  wall. Restored to `@blocked`; it is the tag's only member.

Seven were `@blocked` and one was `@decision`, and **that record is only half in
the files.** Where the reason was already written as a comment it survives the
collapse untouched, which is why those comments still open with the old tag name:

```gherkin
  # @blocked — no app removal. The harness can enable and disable, which is what
  # `occ` offers; removing an app and reinstalling it is a store operation this
  # suite has no way to perform.
  @blocked
  Scenario: Removing the app
```

**A comment naming a status the tag no longer carries is the record, not a
contradiction** — the tag was the temporary flat state, the comment is the truth,
and the one above has now been reconciled back. Two more read that way (both of
`designs/edit.feature`), and the two `# @unbuilt — THIS IS THE SPEC, AND THE APP
DOES THE OPPOSITE TODAY` notes in `designs/delete.feature` and
`designs/move.feature` are the same thing and matter more, because they mark
places where promoting the scenario means **fixing the app, not writing a test**.

The other four `@blocked` and the one `@decision` had no such comment, so their
status lived only in the tag and is now only in the saga's Chapter 2 close, which
names all eight. Do not re-derive them from the spec.

**The rule that goes with it:** a scenario stops being `@todo` only on a PR that
runs it. Promote it to live when it passes, or move it to its true status with the
reason written down — never by re-reading the spec and deciding what it probably
is. The build order is in the saga's Chapter 3.

#### What makes something `@blocked` rather than `@todo`

Name the missing capability, in the scenario or the section above it. The seven
that exist today:

* **no browser** — anything about a button, card, panel, icon, or menu entry;
* **no tty** — anything that answers an interactive `occ` prompt, which today is
  the confirmation a demotion asks for before it deletes a stored archive;
* **no app removal** — `occ` enables and disables; removing an app and
  reinstalling it is a store operation this suite cannot perform;
* **no fault injection** — anything that needs a real Penpot to fail in a
  specific way (a broken export stream, a refused asset download, a listing that
  errors) or a sync to be killed mid-write;
* **no way to edit a design's content** — Penpot's `update-file` is the only RPC
  that changes what is inside a design, and its `changes` payload is unproven
  (saga §1); the harness creates, renames, moves and deletes, but cannot author;
* **no logged-in session** — every personal-token attribution scenario, because
  the occ+DAV harness has no acting user to attribute to;
* **no time travel** — anything gated on Penpot's deletion delay, which is
  7 days by default and set per team, not per request (§C6.19).

**"no groupfolders in the CI image" was retired**, and is listed here only so it
is not reached for again: the app is installed on every leg now, so Team Folder
provisioning is reachable and the storage kind is a cell in a mapping table
rather than a dimension the run decides.

A `@blocked` scenario with no stated reason is really a `@todo` nobody checked.

#### What makes something `@decision`

There is no operation to perform. *"There is no 'Sync to Penpot' button, ever"*
records a design choice; it will never become live, and that is not a gap.

Do not confuse it with a scenario that asserts **nothing happened** — *"Penpot is
never contacted"*, *"no folder named Drafts is created"*. Those are ordinary
behaviour, entirely testable by absence, and several of them run in CI today.

The test is on the `Then`, not the `When`: does it name **the outcome of an
operation**, or **the permanent absence of a capability**? *"Penpot is never
contacted"* is an outcome — something happened, and this is what it did not
cause. *"No button offers to push designs to Penpot"* is an absence: there is no
operation to have an outcome, and there never will be.

Looking at the `When` instead would get this wrong, because a `@decision`
scenario may still open a page to have somewhere to look.

#### A `@todo` that fails is a finding, not a status

A scenario may be `@todo` because it FAILS, not because it is unwritten: the spec
is right, the code is wrong, and the scenario is the evidence. Promoting it is a
bug fix, not a test-writing task.

**Say so in a comment when you do this.** A silent failing `@todo` is
indistinguishable from an unwritten one, and the difference is the whole value of
the tag — which is now the *only* thing that distinguishes them, since the tag
itself no longer sorts anything (above). Two say so today, both marking the app
disagreeing with the spec rather than lagging it:

```gherkin
    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it trashes
    # the file and calls it "hidden". A link is Penpot's copy to remove.
```

in `designs/delete.feature` ("Trash a link") and `designs/move.feature` ("Move an
untracked design file into a project"). The example this section used to give — the
restore/pull listing disagreement (§6.49) — was resolved and its comment is gone.

**A status tag sits on its own scenario, never above a comment block.** Gherkin
binds a floating tag to whatever scenario comes next, across any number of
intervening comment lines — which is exactly how one scenario was silently
excluded while four others ran undefined (§C6.14). CI runs `--strict` so
undefined steps fail the job; nothing protects against a tag landing on the
wrong scenario except keeping them adjacent.

## `Rule:` is NOT available — verified, not assumed

Gherkin 6 added `Rule:`, which groups scenarios under one business rule and can
carry its own `Background`. It maps almost exactly onto how this suite is
organised, and it does not work here: **Behat's parser rejects the keyword.**

    In Parser.php line 339:
      Expected Step, but got text: "  Rule: A move within one project is local"

That is Behat 3.32, and **there is no newer version to upgrade to.**
[Behat#1451](https://github.com/Behat/Behat/issues/1451) is still open; it
depends on [Behat/Gherkin#140](https://github.com/Behat/Gherkin/issues/140),
open since 2019. A maintainer in February 2026: *"I'd still like to look at
this but will come back to it once we have Behat 4.0 out (or at least the
alpha)."* So this is post-4.0 work with no shipped implementation anywhere.

The version number was inferred from and believed rather than tested; the
parser disagreed on the first run, which is the cheapest possible way to be
wrong and still the wrong way to find out.

Business rules are therefore **comment banners** (`# ── RULE: … ──`). They cost
nothing, read the same, and become real `Rule:` blocks the day Behat ships it —
the groupings and the Backgrounds they would carry are already in place.

## Never end a line with the bare word `json`

Not a Gherkin rule — an **editor** one, and worth writing down because the symptom
looks like a broken file rather than a broken highlighter.

The VS Code `cucumberautocomplete` extension ships `syntaxes/json-embed.json`
containing:

```json
"begin": "(json|JSON)\\s*$",
```

That was meant to anchor to a `"""json` doc-string delimiter, but it is not tied to
the `"""` at all. So **any** line ending in the bare word opens an embedded-JSON
region that nothing ever closes, and every line after it in the file renders as
JSON — grey, unreadable, and with no error to explain why.

Only end-of-line occurrences trigger it, and any of these clears it:

| | |
|---|---|
| finish the sentence | `…and can always edit the JSON.` |
| quote it as a value | `…defaulting to "json"` |
| say what kind of thing | `…from the file's JSON body` |

Prefer whichever the sentence wanted anyway. **A step line is a function signature**,
so changing one means changing its definition too — reword those rather than adding
punctuation, and move the definition with it.


### …and never start a token with an apostrophe

Same class of bug, same file's grammar. `feature.tmLanguage.json` treats `'` as a
string delimiter:

```
begin: (?<![a-zA-Z0-9"])'      end: '(?![a-zA-Z0-9"])
```

Two rules interact, and BOTH bite around a quoted step parameter:

```
"  begin: (?<![a-zA-Z0-9'])"      end: "(?![a-zA-Z0-9'])
'  begin: (?<![a-zA-Z0-9"])'      end: '(?![a-zA-Z0-9"])
```

A `"` **cannot close** in front of an apostrophe, and a lone `'` **opens** a
region of its own. So both of these leave a region open and grey out the rest of
the file:

| | |
|---|---|
| `alex's token` | fine — no quote involved |
| `the token of "alex"` | fine — the possessive is rephrased away from the quote |
| `"alex"'s token` | **breaks** — the closing `"` refuses to close before `'` |
| `"alex" 's token` | **breaks** — the lone `'` opens its own region |

The safe habit is simple: **never put an apostrophe next to a quoted parameter.**
Rephrase the possessive instead.

## Comments: two lines, then a breadcrumb

A feature file is the specification, so a scenario has to be legible at a glance.
A comment may add scope to a step; it may not carry the reasoning. **Two lines of
prose per block is the budget**, and anything longer goes to
[`AGENTS.md`](AGENTS.md) behind a one-line pointer:

```gherkin
    # notes: AGENTS.md#a-mapped-folder-shows-its-designs-as-designs
```

Dividers, a `@blocked` reason and the breadcrumbs themselves are not prose and do
not count against it.

`tests/integration/bin/check-notes-anchors.sh` enforces both halves in the
quality job: every breadcrumb must resolve to a real heading, and no block may
exceed the budget. Both failures are silent otherwise — a renamed scenario
orphans its anchor with nothing to notice, and prose creeps back a line at a time
until the spec is unreadable again.

## Metadata is a POST-STATE, never a subject

A mirror's metadata is not something anyone does. It is what is true after
something was done — so it is never a scenario of its own, and never a lone
`Then` picking off one key. It appears as a **table, at the end of the action
that changed it**:

```gherkin
    Given a mirrored design "Cover" in the project "Brand"
    And "Penpot/Brand/Cover.penpot" is a "sync" design
    When the design "Cover" is edited in Penpot
    Then "Penpot/Brand/Cover.penpot" holds the design as it is now
    And "Penpot/Brand/Cover.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_mode     | "sync"          |
      | content         | an archive      |
      | modified        | the design's    |
```

**An end state is absolute, not a diff.** An earlier cut of this offered
`unchanged` / `changed`, backed by a `Given I note the state of "…"` — which is
an *action* wearing a `Given`, harness bookkeeping in the one position that is
supposed to say only how the world already is. A row names the thing its value
came **from** instead: `the design's id` is resolved out of Penpot by name, at
assertion time, against the design the `Given` already named. That says what "the
id survived a rename" was reaching for, and says it better — the id is not merely
different-from-before, it is *that design's*.

**The rows an action did not touch are half the reason the table exists.** "A
move re-files a design without re-fetching it" and "a rename cannot break the
Penpot link" are promises this app makes; as prose they are comments nobody
executes, and as rows they are assertions.

The vocabulary is deliberately small — a table that can say anything stops being
readable: `the design's id`, `set`, `absent`, `an archive`, `empty`, `the
design's` (for a clock), or a quoted literal.

Three more name no value on purpose, because the point of the row is that the
*mapping* decided it — an outline sends one gesture into a `sync` mapping, a Team
Folder and a `link` one, and spelling `sync` would split one claim into three
scenarios: `the mapping's team`, `the mapping's mode`, `the mapping's body`.

That trio is spelt as a trio deliberately. The team half was written both ways —
`the team's id` in four files and `the mapping's team` in nine, with
`connection/sync-now.feature` using **both**, twenty-six lines apart — and only
the first spelling was ever implemented. So every scenario reaching for the
second one failed on "not a value this vocabulary knows": a fixture failure
wearing the shape of an app failure. One claim, one spelling.

`penpot_mode` reads `"link"` in a table even though the stored value is
`reference` (the literal string `link` is `is_callable()` and crashes core's
PROPFIND). The wire quirk is spelt out once, in `view-design.feature`, where the
DAV surface genuinely is the subject; everywhere else a table speaks the
vocabulary the admin chose.

**One canonical table, then deltas.** `view-design.feature` spells out the full
property set once, because there LOOKING is the behaviour. Every other file shows
only the rows its action touches or promises not to touch.

## Data tables: an input, or a different rule?

`Scenario Outline` + `Examples` is right when the rows are **one rule applied to
different inputs** and the outcome is identical for every row. The four link
refusals differed only in destination, so they are one outline over three rows.

It is wrong when the rows are **different rules that happen to share a shape**.
Filing a draft and un-filing look like one outline over a direction column;
they are the same rule read from opposite ends, and three separate bugs
(§C6.8/§C6.9/§C6.10) lived in that asymmetry. Collapsing them would bury the
thing that made them worth writing.

The test: can you write the rows as a list of *values*, or only as a list of
*sentences*? Values → `Examples`. Sentences → separate scenarios.

## Wording is an API

Every step line is a function signature. Two phrasings of one idea are two
functions to maintain and two ways for the same assertion to drift, so the step
vocabulary is deliberately small and parameterised:

```gherkin
Then the file "X" is in the Nextcloud trash
Then the file "X" is not in the Nextcloud trash        # one step, optional negation
Then Penpot project "P" holds a design named "D"
Then Penpot project "P" holds no design named "D"      # same step
```

Prefer extending an existing phrasing with a parameter over inventing a new one.
`tests/integration/bootstrap/Steps/` is the vocabulary; read it before adding to
it.
