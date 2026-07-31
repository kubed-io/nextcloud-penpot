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
