<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# The feature suite

`features/**/*.feature` is this app's **specification**. It is written before the
code and kept true after it — documentation that happens to execute, not a
test-naming convention.

This file is the map: what lives where, what the tags mean, and how the suite is
run. It is the first of three documents, each a level deeper than the last:

| | Document | Answers |
|---|---|---|
| **this file** | `features/README.md` | *Where is the behaviour I'm looking for?* |
| the notes | [`AGENTS.md`](AGENTS.md) | *Why is this scenario the shape it is?* — one section per file, present tense |
| the saga | [`../saga/`](../saga/) | *What was decided, what did it replace, and what proved it?* |

Each level links to the next: every scenario ends with a `# notes:` breadcrumb,
and every section of `AGENTS.md` opens with a `saga:` pointer. Stop at the depth
your question needs.

For **how to get a change in** — the order a PR lands in, and where the tests go —
see [CONTRIBUTING.md § Testing](../CONTRIBUTING.md#testing).

## The layout: the folder is the noun, the file is the verb

A feature file answers *"what happens when someone moves a design?"* — every way a
design can be moved, from either side, with the consequence on the other. It does
not answer *"what does the pull do?"*, because nobody thinks in pulls.

Four folders. Everything in one of them acts on the same kind of thing:

| Folder | The noun | The verbs |
|---|---|---|
| [`designs/`](designs/) | a `.penpot` file | create · view · edit · copy · move · rename · delete · restore · purge · open-with |
| [`projects/`](projects/) | a project folder | create · copy · move · rename · delete · restore · purge |
| [`mapping/`](mapping/) | a mapping | create · view · manage-groups · delete · sync-now |
| [`connection/`](connection/) | the instance | admin · personal · sync-now |

Plus [`lifecycle.feature`](lifecycle.feature) at the top level — the app enabling,
disabling and being removed belongs to no noun in the app.

**A scenario describing a behaviour another file owns is a defect**, even when it
passes. Move it.

### Why a design and a project are separate nouns

They are two Penpot objects with different calls, different failure modes and
different blast radii. A design is moved with `move-files` and a project with
`move-project`; deleting a design trashes one thing, deleting a project takes
everything in it. Folding them together would put a one-file gesture and a
whole-tree one in the same table, reading as though they carried the same risk.

**A mapping is configuration rather than content**, which is why it gets the same
verb treatment: creating one, looking at one, changing the one field that is
editable, tearing it down, and the two things you can ask it to do.

### Two rules that have no file, deliberately

- **Nearest-ancestor membership.** The most load-bearing rule in the app, and it is
  only ever observed *through* a gesture: you move something and it still belongs;
  you create something and it lands in Drafts. Every scenario that tried to test it
  directly turned out to be a move or a create that already existed elsewhere.
- **Personal projects.** The ordinary rules with a different mapping. A design in a
  personal project is created, moved, renamed and deleted by exactly the scenarios
  in `designs/`; the only thing that differs is that setting a personal token maps
  the user's home root to their personal team, which is
  [`connection/personal.feature`](connection/personal.feature)'s.

Deliberately **not ported** from the n8n and Grafana siblings: `tag-sync.feature`
and `reserved-tags.feature`. Penpot has no tags, labels or annotations at all.

## The files are also a partition — eleven Behat suites

Every file belongs to **exactly one** suite, declared in
[`tests/integration/behat.dist.yml`](../tests/integration/behat.dist.yml), and the
integration matrix runs one suite per leg:

| Suite | Holds |
|---|---|
| `admin` | the whole settings surface — the connection, the personal token, and the mapping list |
| `authoring` | the verbs that MAKE or CHANGE a design: create, edit |
| `copying` | `designs/copy.feature` |
| `naming` | `designs/rename.feature` |
| `trash` | a design's state: delete, restore, purge |
| `motion` | `designs/move.feature` — the gestures that cross a mapping boundary |
| `core` | viewing, opening, and the app lifecycle |
| `projects` | `projects/create.feature` — promotion by content |
| `project-trash` | a project's state: delete, restore, purge |
| `project-motion` | `projects/move.feature` |
| `project-copy` | copying and renaming a project folder |

**The axis is the path, not a tag.** A tag partition leaks: `@occ`, `@ui` and
`@in-penpot` are carried by some scenarios and not others, so an untagged scenario
would match no leg and quietly stop running — with every leg still green. A path
partition cannot leak, because `ls features/**/*.feature` minus the union must be
empty. [`tests/integration/bin/check-suites.sh`](../tests/integration/bin/check-suites.sh)
checks exactly that, in the quality job, in about a second.

The design verbs are split finer than the project ones because they cost more: a
`designs/move.feature` scenario is a real WebDAV move plus a Penpot round trip, and
several are Outlines over both storage kinds.

Running plain `behat` still runs all eleven in sequence, so a local run is
unaffected by the split.

## Tags

Two orthogonal axes, because the same verb means two different things depending on
where it happened — and the interesting part is always the *other* side.

### Origin — where the action happened

| Tag | Meaning |
|---|---|
| `@in-nextcloud` | Someone acted in Nextcloud. The payoff is what reached Penpot. |
| `@in-penpot` | The design changed in Penpot — a human, another client, or Penpot itself. The payoff is what reached Nextcloud, and a sync is implied. |

A scenario with **neither** never crosses the boundary: configuration, a refusal,
or a local-only surface. That absence is information — don't invent an origin to
fill the column.

### Channel — how it was triggered

| Tag | Meaning |
|---|---|
| `@gesture` | A Files-app action: drag, rename, delete, restore, "+ New", upload. Driven over WebDAV, which is what a browser sends. |
| `@occ` | Reachable from the CLI. |
| `@admin` | Needs the admin settings panel or an admin-only command. |
| `@ui` | The behaviour has a user-interface surface at all. |
| `@dav` | Asserted through a raw WebDAV request rather than a gesture. |
| `@scheduled` | The timed job, with no human present. |

These describe the FEATURE's surfaces, not how the harness drove it. A scenario the
test runs via `occ` is still `@ui` if the admin panel has a button for it —
otherwise the index answers "how do we test this", which nobody needs to ask.

### The storage backend is a CELL, not an axis

Every behaviour is valid on both a plain admin-owned folder and a groupfolders Team
Folder, and almost none of them has a different outcome. The backend is therefore
stated **in the mapping table**, where a scenario can ask for it:

```gherkin
    And the following mappings were made:
      | team      | folder   | mode | storage      |
      | Northwind | Penpot   | sync | admin folder |
      | Second    | Shared   | sync | team folder  |
```

groupfolders is installed on every leg, so either kind is reachable from any
scenario. [`mapping/manage-groups.feature`](mapping/manage-groups.feature) is the
worked example: one outline, two `Examples` blocks, one per backend, compared in a
single run rather than across two legs that never meet.

Where a backend genuinely changes an **outcome** it earns its own scenario, because
then the rows would not be identical. There is one confirmed case: on a shared
mapping a pruned mirror goes to the **owner's** trash, not the acting user's.

### `sync` vs `link` is a restriction in one direction, not an axis

The tempting move is to write every behaviour twice, once per mode. Don't — the
modes only diverge in one direction.

- **`@in-penpot` scenarios are mode-agnostic.** A design renamed, moved or deleted
  in Penpot reaches Nextcloud the same way either way; a `link` simply has no bytes
  to update. These should not mention mode at all.
- **`@in-nextcloud` scenarios branch.** A `link` is a read-only projection confined
  to its project: moves out are refused, trashing is refused, and there is nothing
  to copy. That is a business rule, and it is stated as one.

The test: can you write the restriction as a sentence beginning *"A link…"*? If yes
it is a rule and deserves its own scenario. If mode makes no difference to the
outcome, leave it out.

### Status — four tags, and only one of them is a backlog

`@todo` on its own means four different things at once: the test is unwritten, the
*feature* is unwritten, the harness cannot reach it, or there is nothing to execute
in the first place. Those need four different people to do four different things,
and lumping them together makes *"what is built but untested?"* — the most useful
question you can ask a spec — a hand-analysis every time.

| Tag | Meaning | What to do about it |
|---|---|---|
| *(none)* | Runs in CI. | Keep it green. |
| `@todo` | **The code exists; only the test is missing.** | Write the test. |
| `@unbuilt` | A spec awaiting code. | Build the feature. |
| `@blocked` | Real behaviour this harness cannot reach. | Build the harness capability — or accept it. |
| `@decision` | Records a deliberate absence. There is no operation. | Nothing, ever. |

All four are excluded from the run, and the distinction is not about whether a
scenario executes today — it is about **who picks it up**.
`behat --tags @todo` is the work queue.

Where the suite stands: **114 scenarios, 87 live**, 13 `@todo`, 4 `@unbuilt`,
10 `@blocked`, 0 `@decision`. A `@blocked` on a `Feature:` covers every scenario
under it — `designs/open-with.feature` contributes five that way, which is what
every hand-count of that number has missed. That line is orientation and goes stale on any PR
that promotes one — a `grep` over `features/` is the authority.

#### A scenario stops being `@todo` only on a PR that runs it

Promote it to live when it passes, or move it to its true status **with the reason
written down**. Never by re-reading the spec and deciding what it probably is —
triage from the spec alone is a guess, and this project has a chapter's worth of
confident guesses that were wrong
([§C6.38](../saga/Chapter_2_The_Colony.md#c638--the-finale-the-spec-outran-the-harness-on-purpose)).

The round trip is the point in both directions. A scenario promoted and then failed
in CI is not wasted work: that is how a wall gets found, and the tag afterwards
records a fact instead of an assumption. Equally, a scenario can go from `@unbuilt`
straight to green when the code catches up with what it already said — which only
works if the tag was honest about what was owed.

#### `@blocked` must NAME the missing capability

In the scenario, or in the section above it. A `@blocked` with no stated reason is
a `@todo` nobody checked — and that is not hypothetical: flattening the tags once
turned up one that had been excused from scrutiny for months and had no wall at all.

The walls that exist today:

* **no browser** — anything about a button, card, panel, icon or menu entry;
* **no app removal** — `occ` enables and disables; removing and reinstalling an app
  is a store operation this suite cannot perform;
* **no fault injection** — anything needing a real Penpot to fail in a specific way,
  or a sync killed mid-write;
* **no way to author a design's content** — the harness creates, renames, moves and
  deletes, but cannot change what is *inside* a design;
* **no logged-in session** — the personal-token attribution scenarios have no
  acting user to attribute to;
* **no time travel** — anything gated on Penpot's deletion delay, which is 7 days
  and set per team rather than per request.

If a stated reason stops being true, the tag is stale and the scenario is probably
promotable. That has already happened once here: *"no groupfolders in the CI image"*
retired the day the app started being installed on every leg.

#### `@unbuilt` vs `@todo` is about the CODE, not the test

If `lib/` cannot do the thing, it is `@unbuilt`, however well specified it is.
Marking unbuilt work `@todo` inflates the queue with items no test could pass, which
is exactly what makes a queue worth ignoring.

The strongest form of `@unbuilt` is worth calling out in the comment: **the app does
the OPPOSITE of what the scenario says.** Promoting one of those is a bug fix, not a
test, and a reader deserves to know which they are picking up.

#### `@decision` is a permanent absence, not "nothing happened"

`@decision` records a capability that **does not and will not exist**. Do not
confuse it with a scenario asserting that nothing happened — *"Penpot is never
contacted"*, *"no folder named Drafts is created"*. Those are ordinary behaviour,
entirely testable by absence, and several run in CI today.

The test is on the `Then`, not the `When`: does it name **the outcome of an
operation**, or **the permanent absence of a capability**?

There are none in this suite today, and one cautionary tale about why the bar is
high. *"There is no Sync to Penpot button, ever"* was a `@decision` for two
chapters, on a reading of §6.1 that turned out to be too broad — the button now
exists. A permanent absence is a hard thing to be right about.

#### A `@todo` that FAILS is a finding, not a status

Legitimate — but it must say so in a comment, or it is indistinguishable from one
nobody has written yet.

## Writing a scenario

The rules below are the ones that have cost this suite something. Each is cheap to
follow and expensive to notice afterwards.

### `Rule:` is NOT available — verified, not assumed

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

### Never end a line with the bare word `json`

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


#### …and never start a token with an apostrophe

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

### Comments: two lines, then a breadcrumb

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

### Metadata is a POST-STATE, never a subject

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

**An end state is absolute, not a diff.** No `unchanged` / `changed`, and no
`Given I note the state of "…"` to back them — that is an *action* wearing a
`Given`, harness bookkeeping in the one position that may only say how the world
already is. A row names the thing its value came **from** instead: `the design's
id` is resolved out of Penpot by name, at assertion time, against the design the
`Given` already named. That is what "the id survived a rename" is reaching for,
and it is stronger — the id is not merely different-from-before, it is *that
design's*.

**The rows an action did not touch are half the reason the table exists.** "A
move re-files a design without re-fetching it" and "a rename cannot break the
Penpot link" are promises this app makes; as prose they are comments nobody
executes, and as rows they are assertions.

The vocabulary is deliberately small — a table that can say anything stops being
readable: `the design's id`, `set`, `absent`, `an archive`, `empty`,
`the design's` (for a clock), or a quoted literal.

Three more name no value on purpose, because the point of the row is that the
*mapping* decided it — an outline sends one gesture into a `sync` mapping, a Team
Folder and a `link` one, and spelling `sync` would split one claim into three
scenarios: `the mapping's team`, `the mapping's mode`, `the mapping's body`.

**One claim, one spelling.** Two wordings for one idea are two step
implementations to keep in step, and a scenario reaching for the unimplemented one
fails on *"not a value this vocabulary knows"* — a fixture failure wearing the
shape of an app failure.

`penpot_mode` reads `"link"` in a table even though the stored value is
`reference` (the literal string `link` is `is_callable()` and crashes core's
PROPFIND). The wire quirk is spelt out once, in [`designs/view.feature`](designs/view.feature), where the
DAV surface genuinely is the subject; everywhere else a table speaks the
vocabulary the admin chose.

**One canonical table, then deltas.** [`designs/view.feature`](designs/view.feature) spells out the full
property set once, because there LOOKING is the behaviour. Every other file shows
only the rows its action touches or promises not to touch.

### Data tables: an input, or a different rule?

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

### Wording is an API

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

## Where the tests are

A scenario is only real if a step definition matches every one of its lines.

| What | Where |
|---|---|
| The scenarios | `features/**/*.feature` — at the repo root, because they are docs |
| The step definitions | [`tests/integration/bootstrap/Steps/`](../tests/integration/bootstrap/Steps/) |
| The context that composes them | [`tests/integration/bootstrap/FeatureContext.php`](../tests/integration/bootstrap/FeatureContext.php) |
| Transports — `occ`, WebDAV, the Penpot RPC | [`tests/integration/bootstrap/Support/`](../tests/integration/bootstrap/Support/) |
| What CI actually runs | [`tests/integration/behat.dist.yml`](../tests/integration/behat.dist.yml) |
| The unit suite | [`tests/unit/`](../tests/unit/) — no Nextcloud server; OCP interfaces are stubbed |

Integration runs against **a real Nextcloud and a real Penpot**, one suite per
matrix leg, in [`.github/workflows/integration.yml`](../.github/workflows/integration.yml).
The Penpot token is minted by the workflow against a throwaway instance.

```sh
composer run test:unit                          # the unit suite
cd tests/integration && vendor/bin/behat         # every suite, in sequence
cd tests/integration && vendor/bin/behat --suite=motion
cd tests/integration && vendor/bin/behat --tags '@todo'    # the work queue
```

CI runs `--strict`, so **an undefined step in an untagged scenario fails the
build.** That is the safety net: a scenario with no status tag is claiming to be
live, and CI enforces the claim.

A new `*Steps.php` that nobody `use`d in `FeatureContext` is silently dead.

### Three guards run in the quality job

They exist because each protects something that rots **silently** — every one of
these failures leaves the build green.

| Script | Checks |
|---|---|
| [`check-suites.sh`](../tests/integration/bin/check-suites.sh) | Every feature file is in exactly one Behat suite. A file in none is never run. |
| [`check-notes-anchors.sh`](../tests/integration/bin/check-notes-anchors.sh) | Every `# notes:` breadcrumb resolves, and no comment block is over its two-line budget. |
| [`check-step-definitions.sh`](../tests/integration/bin/check-step-definitions.sh) | Every step definition is reachable from `FeatureContext`. |

`check-notes-anchors.sh` proves a pointer **lands**, not that it lands somewhere
true — two scenarios once spent a whole chapter pointing into notes about feature
files that no longer existed. When you move a section, read what points at it.

### Which unit tests, and which scenarios

Behat is expensive: every scenario is a real Nextcloud, a real Penpot and a real
round trip. So the split is by what the assertion needs.

- **A rule with no I/O** — a path resolver, a mode table, interval parsing, the
  Transit decoder — is a **unit test**. It is faster, and it can enumerate cases a
  scenario would have to arrange one at a time.
- **A request payload** is a unit test too. Behat cannot see what went over the
  wire, only what changed at both ends.
- **Anything a person would describe as a thing they did** is a **scenario**.

If you find yourself writing a scenario whose `When` has no human in it — *"when
the export stream closes with no end event"* — it is a unit test wearing a
scenario's clothes.

---

## Changing any of this

The order a change lands in — scenario first, then code, then tests — is
[CONTRIBUTING.md § Testing](../CONTRIBUTING.md#testing). The reasoning behind an
individual scenario is [`AGENTS.md`](AGENTS.md); the history behind a decision is
[the saga](../saga/).
