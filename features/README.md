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
meant reading `move.feature` for the Nextcloud half, `reconcile.feature` for the
Penpot half, and `gestures.feature` for the two that CI could actually prove.
Three files, one behaviour, and no single place that told you the shape of it.

**Behaviour files** — the answer to "what happens when…":

| File | Owns |
|---|---|
| `create-design.feature` | A design coming into existence, on either side |
| `copy.feature` | Duplicating a design |
| `move.feature` | A design changing project, team, or Drafts state |
| `rename.feature` | A design or project folder changing name |
| `delete.feature` | Everything that removes a design: the two trashes, the prune, the purge |
| `restore.feature` | Everything that brings one back: both trashes, the archive |
| `set-mode.feature` / `sync-mode.feature` | `sync` ⇄ `link`, and what each mode means |
| `ignore.feature` | Excluding a file from the sync |
| `open-with.feature` / `file-type.feature` | The Files-app surface of a mirror |

**Mechanism files** — the sync run itself, whose actor is an admin or the clock:

| File | Owns |
|---|---|
| `reconcile.feature` | What a sync run does *as a run*: completeness, idempotency, ordering, refusing to prune on a short listing, what it reports, and who can start one |

A scenario belongs in `reconcile.feature` only when it is about the RUN. "A
design renamed in Penpot reaches Nextcloud" is a rename — it lives in
`rename.feature`. "A pull that could not list one project prunes nothing" is
about the run, and lives here.

**Configuration files** — the admin and per-user surface: `admin-connection`,
`admin-mapping`, `admin-section`, `personal-settings`, `remove-mapping`,
`mapping-membership`, `project-folder`, `personal-projects`, `team-import`,
`lifecycle`, `uninstall`, `errors`.

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

### Status

| Tag | Meaning |
|---|---|
| `@todo` | The steps do not exist. Excluded by `behat.dist.yml`, which filters `~@todo`. |

**A `@todo` tag sits on its own scenario, never above a comment block.** Gherkin
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
