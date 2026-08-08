---
description: 'Gherkin and Behat conventions for review — how a feature file must read, and how to tell whether it is actually tested'
applyTo: "{features/**/*.feature,features/README.md,tests/integration/**}"
---
<!--
  SPDX-FileCopyrightText: 2026 kubed-io
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Gherkin conventions — the spec, and whether it is real

`features/*.feature` is this app's **specification**, written before the code and
kept true after it. It is not a test-naming convention. Review it as
documentation that happens to execute.

**Read `features/README.md` first** — it is the authority on layout and tags, and
this file is the review checklist that follows from it. Where the two disagree,
`features/README.md` wins and this file is stale.

**The fastest review pass**: read each `Scenario:` line with its `When`, and ask
*who performed this, and what changed?* Almost every defect this repo has shipped
in a feature file fails that question — a mechanism as the actor, a construct as
the subject, a property as the title, or an assertion that nothing happened to
something nobody touched. Those shapes are catalogued in
[A scenario that is not one](#a-scenario-that-is-not-one--the-shapes-to-reject),
with the offending lines.

## Where the binding lives — check this before saying anything about coverage

A scenario is only real if a step definition matches every one of its lines.

| What | Where |
|---|---|
| The scenarios | `features/<noun>/<verb>.feature` — `designs/`, `projects/`, `team-mapping/`, `connection/` |
| The step definitions | `tests/integration/bootstrap/Steps/*.php` |
| The context that composes them | `tests/integration/bootstrap/FeatureContext.php` |
| Transports (occ, WebDAV) | `tests/integration/bootstrap/Support/` |
| What CI actually runs | `tests/integration/behat.dist.yml` |

`behat.dist.yml` filters `~@todo&&~@unbuilt&&~@blocked&&~@decision`, and CI runs
`--strict` — so an **undefined step in an untagged scenario fails the build.**
That is the safety net: a scenario with no status tag is claimed to be live, and
CI enforces the claim.

A new step definition belongs in the trait that owns its concern, and every trait
must be `use`d in `FeatureContext`. **A new `*Steps.php` that nobody composed in
is silently dead** — check for it.

## The four status tags

The single most useful question about a spec is *"what is built but untested?"*.
These tags exist so it is a filter rather than an investigation.

| Tag | Means | Who picks it up |
|---|---|---|
| *(none)* | Runs in CI. | — |
| `@todo` | **The code exists; only the test is missing.** | Someone writing a test |
| `@unbuilt` | A spec awaiting code. | Someone building the feature |
| `@blocked` | Real behaviour the harness cannot reach. | Someone extending the harness |
| `@decision` | Records a deliberate absence. No operation, ever. | Nobody |

**`behat --tags @todo` is the work queue.** Flag anything that pollutes it:

- A scenario tagged `@todo` whose feature has no code → it is `@unbuilt`.
- A `@blocked` that does not **name the missing capability** → it is a `@todo`
  nobody checked. The four that exist are: no browser, no logged-in session, no
  groupfolders in the CI image, and no way to age a deletion past Penpot's
  per-team delay.
- A `@decision` on something with a real gesture. `@decision` is for the
  *permanent absence of a capability* (*"There is no 'Sync to Penpot' button,
  ever"*), **not** for *"nothing happened"* (*"Penpot is never contacted"*) —
  that second kind is ordinary behaviour, testable by absence, and several run in
  CI today. Read the `Then`: the outcome of an operation, or an absence of one?

A `@todo` that fails because of a **defect** is legitimate — but it must say so
in a comment, or it is indistinguishable from an unwritten one.

## Community standards this project follows

From [Cucumber's own guidance](https://cucumber.io/docs/bdd/better-gherkin/) —
cite these when a scenario drifts:

- **Describe behaviour, not implementation.** The test: *will this wording need
  to change if the implementation does?* If yes, rewrite it.
- **`Given` is a precondition, `When` is the action, `Then` is an observable
  outcome.** Avoid user interaction in a `Given`.
- **Assert on something observable**, not on internal state. Cucumber says
  "resist the temptation to look in the database"; here that means **assert
  through Penpot's own listing or over DAV, never only through this app**. A
  scenario that asks the app whether the app did something proves only that the
  app agrees with itself — that exact shape let §C6.9 and §C6.10 ship.
- **Keywords are ignored when matching step definitions.** Two steps with the
  same text are the same function whatever their keyword. This is why one
  function may carry several phrasings, and why near-duplicate wordings are a
  defect rather than a style choice.
- **Backgrounds stay short and describe state, not who did what.**

### Where this project deliberately differs, and why

Do not "correct" these:

- **The reasoning is kept, but not in the feature file.** Every scenario ends
  with a `# notes: AGENTS.md#anchor` pointer, and the essay lives in
  `features/AGENTS.md`. A block may carry **at most two lines of prose**;
  `tests/integration/bin/check-notes-anchors.sh` fails the build on a longer one
  and on a pointer that no longer resolves. This reversed an earlier rule that
  said comments should carry the reasoning inline — that produced 3488 lines of
  notes for 3552 lines of spec, and scenarios nobody could read at a glance.
- **Backgrounds run to six lines, over the recommended four.** Every line is a
  real configuration step against a live Nextcloud and a live Penpot; there is no
  `Given I am logged in` shortcut to hide them behind.
- **`Rule:` is not used, and this is verified, not preference.** Behat's parser
  rejects the keyword (`Expected Step, but got text: "  Rule: …"`), and there is
  no newer Behat to upgrade to. Business rules are comment banners
  (`# ── RULE: … ──`). **Never suggest converting them to `Rule:` blocks.**

## Layout — one behaviour, one file

Feature files are organised by **behaviour**, never by the kind of thing acted
fullon. Renaming a project folder is a *rename* and lives in `rename-project.feature`, next
to renaming a file, so a reader comparing the two sees one table.

This is the most valuable thing to catch in review, because the failure is
silent. `create-project.feature` once owned renaming, copying, moving *and*
deleting a project folder — and had already drifted: the old `rename.feature` carried
**live** coverage of the project-folder rename while `create-project.feature`
still called it unbuilt. Two files, one behaviour, and nobody reads two files to
answer one question.

**Flag a scenario that describes a behaviour another file owns.** The owners are
listed in `features/README.md`.

## A scenario that is not one — the shapes to reject

Every one of these shipped in this repo and was removed. They share a root: the
scenario names something that is not a thing anyone **does**. Read the `Scenario:`
line and the `When` together and ask *"who performed this, and what changed?"* —
if either answer is missing, it is one of the shapes below.

### The `When` is a mechanism, not an action

```gherkin
    When the pull runs                       # ✘ nobody runs a pull
    When the team is mirrored again          # ✘ nicer words, same defect
    When the reconciler notices the change   # ✘
```

A reconciler is how news travels, not news. **When the change happened in Penpot,
say what someone did there and let the step hide the sync:**

```gherkin
    Given a mirrored design "Farewell" in the project "Doomed"
    When the design "Farewell" is deleted in Penpot        # ✔ pre-state, action,
    Then the file "…/Farewell.penpot" is in the Nextcloud trash   #   end state
```

This retired a whole file. `reconcile.feature` was six scenarios that turned out
to be four behaviours — the first sync, an edit, an upstream delete, a local edit
pushed back — each of which belonged with the thing a person did.

**The exception is narrow:** a pull may be the subject when running it *twice* is
the claim ("a promoted file is not re-exported by the next pull"). That is about
repetition, not about reconciling.

### The subject is a construct

```gherkin
Feature: A mirrored Penpot file is a first-class file type   # ✘ nobody does this
  Scenario: WebDAV PROPFIND exposes the metadata             # ✘ nor this
  Scenario: A mirrored file's mode is visible over DAV       # ✘ a property, not an act
  Scenario: A design file has an updated-at                  # ✘ a field, not an act
```

A mimetype, a property set, an index and a timestamp are all **end states of
something else**. Nobody registers a mimetype; they install an app. Nobody sets
an `updated-at`; they edit a design and the app stamps it. Put each end state
with the action that produces it, as a table, and what remains is the one thing
someone genuinely performs — *looking* (`view-design.feature`).

### More than one `When`

```gherkin
    When the pull runs
    Then every newly mirrored file is in "link" mode
    When the admin changes the mapping's default mode to "sync"   # ✘ second When
    Then the new file is in "sync" mode
```

A second `When` is a scenario rebuilding a pre-state by performing another
behaviour. State it in the `Given` instead. **One `When`, followed by `And`s that
are only continuations of the same act, then one `Then` and its `And`s.**

Two `Given`/`Then` pairs in one scenario is the same defect wearing a different
hat — that is two scenarios sharing a name.

### An action wearing a `Given`

```gherkin
    Given I note the state of "Penpot/Brand/Cover.penpot"   # ✘ that is an act
```

A `Given` says how the world **is**. Harness bookkeeping is not pre-state. This
one forced a whole diff vocabulary (`unchanged` / `changed`) that vanished the
moment the table stated absolute values instead — and the absolute form is the
stronger claim: not *"the id differs from before"* but *"the id is that design's"*.

### The `Then` asserts that nothing happened to something nobody touched

```gherkin
  Scenario: An edit to one design leaves its neighbours alone   # ✘
  Scenario: An already-empty link is left strictly alone        # ✘
```

Nothing edits a file nobody edited. These are the same action, the same
pre-state and the same end state as the scenario above them, plus a sentence
saying an untouched thing was untouched.

**This is not the same as a negative outcome**, which is real and often the
point: *"Penpot is never contacted"*, *"no folder named Drafts is created"* —
something happened, and this is what it did not cause. The test is whether an
action was aimed at the thing you are asserting about.

### The scenario summarises other files

```gherkin
  Scenario: A link file is confined to its own project
    Then it cannot be moved into another project folder
    And it cannot be moved to the team root
    # Detail lives in move-design.feature and ignore.feature; this is the summary
```

A comment admitting the coverage is elsewhere is the tell. A summary is a second
copy free to drift, and the reader who wants the rule is better served by the
file that exercises it.

### The scenario is another file's scenario

Two files carried *"A project folder carries a visible tag as well as its
metadata"* with the same arrange and the same two asserts, in two suites. Before
adding a scenario, grep the `Then` lines for one that already says it.

### The operation does not exist

```gherkin
    When the admin changes the mapping's default mode to "sync"   # ✘ no such thing
```

A mapping's mode is fixed at creation. There was no control, no `occ` flag and no
code path — the scenario described a wish. **Check that the `When` names
something the app can actually be asked to do**; if it cannot, the scenario is
`@unbuilt` at best and usually nonsense.

Its sibling: asserting a value the app never writes. A PROPFIND table once listed
`grafana_folderUid` and `grafana_apiVersion`, both registered and both written by
nothing.

### The scenario is about an older version of this app

```gherkin
  Scenario: A leftover body from an older version is truncated by the next pull  # ✘
```

Neither this app nor its Grafana sibling has ever been released. There is nothing
in the field to migrate from, so a compatibility scenario describes a population
of zero. Delete it rather than rewriting it.

## State is a table — both ends of it

Two conventions follow from "a scenario is pre-state, one action, end state", and
both are worth checking on any PR that touches a mirror.

**A mapping is one fact, so it is one sentence and a table** — the same shape
whether it is the pre-state or the action, in the vocabulary the creation form
uses. A blank or absent row means the app's own default, declared once in the
Background:

```gherkin
    Given a mapping with the following values:
      | team    | Northwind    |
      | folder  | Design Files |
      | groups  | design,admin |
```

Naming a field is a claim that it matters to the outcome. Leave out the rows that
do not — a scenario about DAV properties says nothing about `storage`, because
what a mirror publishes is identical on both backends.

**Metadata is the end state of the action that changed it**, never a subject of
its own and never a lone `Then` picking off one key:

```gherkin
    When the admin promotes "Penpot/Archive Me/Cover.penpot" to "sync" mode
    Then "Penpot/Archive Me/Cover.penpot" holds:
      | penpot_id       | the design's id |
      | penpot_team_id  | the team's id   |
      | penpot_mode     | "sync"          |
      | content         | an archive      |
```

The rows an action did **not** change are half the reason the table earns its
place: *"a move re-files without re-fetching"* and *"a rename cannot break the
Penpot link"* are promises, and as rows they are assertions rather than comments.

The vocabulary is closed — `the design's id`, `the team's id`, `set`, `absent`,
`an archive`, `empty`, `the design's` (a clock), or a quoted literal. **Flag a new
value word**: a table that can say anything stops being readable, and a diff word
like `unchanged` drags a "note the state first" action back into the `Given`.

## The traps this repo has actually fallen into

Each of these has cost a debugging session here. They are worth checking on every
PR that touches a feature file.

**A tag floating above a comment block.** Gherkin binds a tag to the next
*scenario*, across any number of comment lines. A `@todo` placed above a banner
silently excluded one scenario while four others ran undefined. **A status tag
must be directly adjacent to its `Scenario:` line.**

**A Background whose steps do not exist.** Harmless while every scenario in the
file is tagged, and an instant `--strict` failure the moment one goes live. Two
files carried fictional Backgrounds for months (`a Team Folder mapped to the
Penpot team "Northwind"` — a step that had never been written). **If a PR
promotes a scenario to live, verify the Background is real.**

**A scenario borrowing another file's setup habits.** `connection/sync-now.feature`
deliberately maps nothing in its Background — every scenario names its own folder
so one scenario's leftovers cannot become another's prune. `designs/move.feature` maps a
shared `Penpot` folder in its Background. Copying a scenario between them
silently breaks it. **Check the file's own Background before assuming a path
resolves.**

**An assertion about the whole mapped folder inside a one-file scenario.** `the
pull pruned nothing` is a claim about every design every other scenario ever left
behind; it passed or failed on alphabetical neighbours and flapped for a session.
**A scenario about one file asserts on that file.**

**An absence assertion that passes for the wrong reason.** `occ penpot_sync:status`
exits non-zero both for "no such node" and for "cannot resolve the sync actor".
Asserting only the exit code makes the test go green precisely when its fixture
breaks. **Absence assertions must match the specific failure.**

**A `Then` that only asks this app.** See the observable-outcome rule above.

## Two characters that break the editor, and neither is a Gherkin rule

Both are bugs in the VS Code `alexkrechik.cucumberautocomplete` grammar, not in
Gherkin — but the symptom is a file that renders as unreadable grey from some
point onward, with no error to explain it. Reviewers should flag these, because
the author often cannot see what they did.

### Never end a line with the bare word `json`

`syntaxes/json-embed.json` contains:

```json
"begin": "(json|JSON)\\s*$",
```

That was meant to anchor to a `"""json` doc-string delimiter and is not tied to
the `"""` at all, so **any** line ending in the bare word opens an embedded-JSON
region that nothing closes. Only end-of-line occurrences trigger it. Fixes, in
order of preference — pick whichever the sentence wanted anyway:

| | |
|---|---|
| finish the sentence | `…and can always edit the JSON.` |
| say what kind of thing | `…from the file's JSON body` |
| quote it as a value | `…defaulting to "json"` |

A **step line is a function signature**, so reword those rather than adding
punctuation, and move the step definition with it.

### Never start a token with an apostrophe

`syntaxes/feature.tmLanguage.json` treats `'` as a string delimiter:

```
begin: (?<![a-zA-Z0-9"])'      end: '(?![a-zA-Z0-9"])
```

So an apostrophe opens a string **unless it directly follows an alphanumeric or a
double quote**. That makes the ordinary cases safe and one case fatal:

| | |
|---|---|
| `alex's token` | fine — `'` follows a letter, and no quote is involved |
| `the token of "alex"` | fine — the possessive is rephrased away from the quote |
| `"alex"'s token` | **breaks** — the closing `"` cannot close, because `"(?![a-zA-Z0-9'])` refuses to close in front of an apostrophe |
| `"alex" 's token` | **breaks** — the lone `'` opens a region of its own |

The last form turns up when a possessive gets separated from its noun, usually
while editing around a quoted parameter. If a file suddenly goes grey partway
down, look for a lone `'` before you look for anything else.

## Scenario Outline: an input, or a different rule?

`Examples` is right when the rows are **one rule over different inputs** and the
outcome is identical for every row.

It is wrong when the rows are **different rules sharing a shape**. Filing a draft
and un-filing look like one outline over a direction column; they are the same
rule read from opposite ends, and three separate bugs lived in that asymmetry.

The test: can you write the rows as a list of *values*, or only as a list of
*sentences*? Values → `Examples`. Sentences → separate scenarios.

## Wording is an API

Every step line is a function signature, so the vocabulary is deliberately small
and parameterised:

```gherkin
Then the file "X" is in the Nextcloud trash
Then the file "X" is not in the Nextcloud trash        # one step, optional negation
Then Penpot project "P" holds a design named "D"
Then Penpot project "P" holds no design named "D"      # same step
```

**Flag a new step phrasing that duplicates an existing one.** Two wordings for
one idea are two functions to maintain and two ways for the same assertion to
drift. Read `tests/integration/bootstrap/Steps/` before accepting a new one.

The reverse is fine and intentional: one function may answer to several
phrasings, because keywords are ignored in matching. `theAdminRunsAPull()` is
`the admin runs a pull` where the run is the behaviour under test, `the team has
been mirrored into Nextcloud` where a mirror merely has to exist first, and `the
team is mirrored again` where an upstream change needs a sync to notice it. Same
operation, three honest readings.

**Setup says what IS TRUE, not who did what to make it true.** `the admin runs a
pull` as a `Given` read as though an admin were permanently on call to sync
before every gesture a user makes. That is not the system being described.

## Tag axes other than status

- **Origin** — `@in-nextcloud` / `@in-penpot`: where the action happened. The
  payoff of a scenario is always what reached the *other* side.
- **Channel** — `@gesture` (Files-app, driven over WebDAV) / `@occ` / `@admin` /
  `@scheduled`.
- **Backend** — `@team-folder` / `@plain-folder`: a dimension the suite is *run
  across*, not a reason to write a scenario twice.

`sync` vs `link` is **not** an axis. `@in-penpot` scenarios are mode-agnostic and
should not mention mode at all; only `@in-nextcloud` scenarios branch, because a
`link` is a read-only projection confined to its project. Flag a scenario written
twice per mode when the outcome does not differ.
