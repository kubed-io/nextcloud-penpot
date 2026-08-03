<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# How the feature files are organised

These are the app's specification, written before the code and kept true after
it. This file is the map: what belongs where, and what the tags mean.

## The organising rule: a feature is a BEHAVIOUR, not a mechanism

A feature file answers *"what happens when someone moves a design?"* — every way
a design can be moved, in either system, with the consequence on the other side.
It does not answer *"what does the pull do?"*, because a user does not think in
pulls.

That distinction is the whole layout. Before it, finding "how does move work"
meant reading `move-design.feature` for the Nextcloud half, `reconcile.feature` for the
Penpot half, and `gestures.feature` for the two that CI could actually prove.
Three files, one behaviour, and no single place that told you the shape of it.

**Behaviour files** — the answer to "what happens when…":

| File | Owns |
|---|---|
| `create-design.feature` | A design coming into existence, on either side |
| `copy-design.feature` | Duplicating a design |
| `move-design.feature` | A design changing project, team, or Drafts state |
| `rename-design.feature` | A design changing name, either side, and the file-name guards |
| `delete-design.feature` | Everything that removes a design: both trashes, the purge, link dismissal |
| `restore-design.feature` | Everything that brings a design back: both trashes, the archive |
| `create-project.feature` | How a folder BECOMES a project, and the `penpot` tag that marks one |
| `copy-project.feature` | Why copying a project is refused rather than half-done |
| `move-project.feature` | Where a project folder may and may not be dragged |
| `rename-project.feature` | A project changing name, and the project-name guards |
| `delete-project.feature` | Deleting a project — one call, not one per design |
| `restore-project.feature` | Bringing a project back whole, and the one case that cannot be |
| `personal-projects.feature` | Only the WHO and WHERE of a personal team — every verb lives with its verb |
| `reconcile.feature` | What a sync run does *as a run*: completeness, idempotency, what it reports |
| `mapping-membership.feature` | Which files a mapping owns, and what "unmapped" means |
| `set-mode.feature` / `sync-mode.feature` | `sync` ⇄ `link`, and what each mode means |
| `ignore.feature` | Excluding a file from the sync |
| `open-with.feature` / `file-type.feature` | The Files-app surface of a mirror |
| `admin-*.feature` | Reaching Penpot at all, and configuring what is mapped |

## The two nouns: a DESIGN and a PROJECT

Six verbs, two nouns, twelve files. A design is a `.penpot` file; a project is a
**folder**, because a folder is the only thing a Penpot project can be in
Nextcloud.

Every verb file names its noun, so "what happens when I rename X?" has one place
to look, and the two answers sit side by side. They are genuinely different
behaviours rather than one with a branch:

| verb | design | project |
|---|---|---|
| copy | duplicates it in Penpot | **refused** — Penpot has no duplicate-project call |
| move | anywhere its project reaches | pinned inside its team folder |
| delete | one design | one call that takes the whole project with it |
| restore | the same design, id intact | the project *and* everything in it, or not at all |

A **personal** project is still a project: the verbs behave identically, so only
the who (the user's own token) and the where (their home root) are special, and
that is all `personal-projects.feature` keeps.

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

**Mechanism files** — the sync run itself, whose actor is an admin or the clock:

| File | Owns |
|---|---|
| `reconcile.feature` | What a sync run does *as a run*: completeness, idempotency, ordering, refusing to prune on a short listing, what it reports, and who can start one |

A scenario belongs in `reconcile.feature` only when it is about the RUN. "A
design renamed in Penpot reaches Nextcloud" is a rename — it lives in
`rename-design.feature`. "A pull that could not list one project prunes nothing" is
about the run, and lives here.

**Configuration files** — the admin and per-user surface: `admin-connection`,
`admin-mapping`, `admin-section`, `personal-settings`, `remove-mapping`,
`mapping-membership`, `personal-projects`, `team-import`, `lifecycle`,
`uninstall`, `errors`.

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

### Storage backend — a DYNAMIC BACKGROUND, not a tag and not a table

Every behaviour is valid on both a plain (admin-owned) folder and a Team Folder,
and none of them has a different outcome. So the backend must be covered without
being *written down twice*. Three ways were considered and only one survives:

| | why not |
|---|---|
| duplicate each scenario, tagged `@team-folder` / `@plain-folder` | two identical blocks that prove nothing, ×97 |
| a `Scenario Outline` with a two-row `Examples` table | the rows would have identical expectations — the same duplication, compressed — and it runs them **sequentially**, so it costs wall time instead of saving it |
| **a dynamic Background** | ✓ |

The Background is one line of Gherkin whose *meaning* is resolved per run:

```gherkin
  Background:
    ...
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
```

`OccTrait::backendFlags()` reads `PENPOT_TEST_BACKEND` and maps either a plain
folder or a groupfolders-backed one. The spec never mentions the backend, which
is precisely what makes it a dimension rather than a claim — **a Background is
setup, and setup is the harness's business.**

The CI matrix then runs the whole suite once per backend, in parallel, so the
second one costs no wall-clock time (saga §C6.26).

**Why it cannot be an `Examples` table, concretely:** the difference is not a
mapping flag, it is *whether the groupfolders app is installed on the server*. One
Behat process runs against one Nextcloud, so a table would need groupfolders
installed always — and the "plain" rows would then be exercising a plain folder
**on a server that has groupfolders**, which is not what most deployments run. The
matrix varies the SERVER; a table can only vary the mapping.

That this was never covered is not theoretical: the structural scenarios in
`reconcile.feature` mapped a Team Folder and passed only because of where they sat
in the run. Moved, the folder resolved to nothing — Team Folder provisioning had
never actually been covered. More scenarios would not have caught that; running
the existing ones against both backends does.

Where a backend genuinely changes an OUTCOME it earns its own scenario, because
then the two rows would *not* be identical — and it carries `@plain-folder` or
`@team-folder` so the other leg skips it. There are two confirmed cases:

- **§C6.16** — on a shared mapping a pruned mirror goes to the **owner's** trash,
  not the acting user's.
- **§C6.27** — emptying a Team Folder's trash cannot reach Penpot at all.
  groupfolders registers its own `ITrashBackend` whose `removeItem()` emits
  nothing — no typed event, no legacy hook — so no app can observe it. It
  self-corrects, because Penpot's own trash expires after 7 days, so the
  divergence is a window rather than a permanent state.

Both were found by RUNNING the suite across both backends, which is the argument
for the dimension in one line: neither needed a new scenario to be discovered,
only an existing one pointed at the other backend.

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

#### What makes something `@blocked` rather than `@todo`

Name the missing capability, in the scenario or the section above it. The four
that exist today:

* **no browser** — anything about a button, card, panel, icon, or menu entry;
* **no logged-in session** — every personal-token attribution scenario, because
  the occ+DAV harness has no acting user to attribute to;
* **no groupfolders in the CI image** — Team Folder provisioning (which is also
  why `@team-folder` is a dimension to run across rather than a thing to write
  twice, above);
* **no time travel** — anything gated on Penpot's deletion delay, which is
  7 days by default and set per team, not per request (§C6.19).

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

One scenario is `@todo` because it FAILS, not because it is unwritten — the
restore/pull listing disagreement (§6.49), and it says so in a comment. That is
a deliberate use of the tag: the spec is right, the code is wrong, and the
scenario is the evidence. Promoting it is a bug fix, not a test-writing task.

Say so in a comment when you do this. A silent failing `@todo` is
indistinguishable from an unwritten one, and the difference is the whole value
of the tag.

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
