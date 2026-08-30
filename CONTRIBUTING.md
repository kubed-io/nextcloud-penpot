# Contributing

Thanks for stopping by. This is **Penpot Sync** — a Nextcloud app that mirrors
Penpot design files into Nextcloud as native files. It lives under the
[kubed-io](https://github.com/kubed-io) GitHub org, shares some workflow plumbing
with the rest of that org, and has a deliberate process around getting changes in.
Please read this whole doc before you push code — most of the "why is my PR stuck?"
questions are answered below.

**Where the work is right now.** Chapters 1 (the API survey), 2 (the build) and
3 (building to the spec) are CLOSED. The app works end to end and is deployed:
both directions of sync, every design and project verb, 92 live scenarios run
against a real Nextcloud and a real Penpot on every push.
[Chapter 4](saga/Chapter_4_Open_For_Business.md) is open, and it is about the
outward-facing surfaces rather than the code.

**The queue is named, not hidden** — 13 `@todo`, 4 `@unbuilt`, 5 `@blocked`, each
saying what it wants. Read [features/README.md](features/README.md) for what those
tags oblige. The one rule: **a scenario stops being `@todo` only on a PR that runs
it** — and the corollary, learned twice: a tag is a claim somebody made once and
nothing re-checks it, so read the scenario against the code before believing it.

> Both counts drift with every PR. `behat --dry-run` and a `grep` over
> `features/` are the authority; these numbers are orientation.

If you only have time for one paragraph: **prefer opening an issue first so the
work can be scoped and approved, then open a PR with tests and a clear changelog
entry, and verify your change on a real Nextcloud instance before asking for
review.**

---

## Repo tour

A quick map so you know where to look. Each entry has a one-liner; the file/folder
itself is the authoritative detail.

| Path | What lives here |
|---|---|
| [README.md](README.md) | User-facing docs: what the app is meant to do, the read-only architecture, auth setup. **Start here for "how does it work?"** |
| [CHANGELOG.md](CHANGELOG.md) | Keep-a-Changelog format. Every PR adds a line under `## [Unreleased]`. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | This file — process, conventions, dev loop. |
| [SECURITY.md](SECURITY.md) | How to report vulnerabilities. Read before filing a "security" issue publicly. |
| [AGENTS.md](AGENTS.md) | Cold-start orientation for AI coding agents. |
| [saga/](saga/) | Long-form design narrative, and the only place history lives. Ch.1 the API survey and the architecture, Ch.2 the build, Ch.3 the current one. **The "why" behind everything else.** |
| [features/](features/) | **The specification.** Gherkin, written before the code — see [features/README.md](features/README.md). |
| [appinfo/](appinfo/) | Nextcloud app metadata — `info.xml` (incl. the mimetype repair steps) and `routes.php`. |
| [lib/](lib/) | PHP backend (`OCA\PenpotSync`): `Service/` holds the behaviour, `Listener/` the gesture adapters, plus `Command/`, `Controller/`, `Settings/`, `DAV/`, `BackgroundJob/`, `Migration/`. |
| [src/](src/) | JS frontend, built by Vite into `dist/`. The file action and the New-menu entry. |
| [tests/](tests/) | `unit/` (PHPUnit, no NC server) and `integration/` (Behat, against a real Nextcloud and a real Penpot). |
| [composer.json](composer.json) | PHP deps + scripts (`test:unit`, `cs:check`, `cs:fix`, `lint`, `psalm`). |
| [package.json](package.json) | JS deps + scripts (`build`, `dev`, `watch`, `test`). Node version pinned in `.nvmrc`. |
| [psalm.xml](psalm.xml), [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) | Static analysis + coding standard config, adapted from the sibling apps. |
| [.devcontainer/](.devcontainer/) | One-shot dev environment (PHP 8.3 + Node + GH CLI + docker-out-of-docker). |
| [.github/workflows/](.github/workflows/) | `pr.yml` (PR housekeeping), `tests.yml` (build + unit), `quality.yml` (audit + lint + Psalm), `publish.yml` (release tarball), `package.yml`, `copilot-setup-steps.yml`. |
| [vite.config.js](vite.config.js) | Frontend build config, targeting `src/files.js`. |

What is still missing, and roughly in the order it is wanted: personal projects
(a user's own Penpot team at their home root), the mode pills, and tracking a
copied project folder. [Chapter 3's close](saga/Chapter_3_Building_To_Plan.md#chapter-3--where-it-stands-closed)
is the honest inventory.

---

## Principles

Internalize these. They are the difference between a PR that merges and one that
spirals.

### Do things the Nextcloud way

This is a **Nextcloud app**, not "a PHP project that happens to run inside
Nextcloud." When you need to pick between a Nextcloud-native primitive and a
generic one, pick the Nextcloud one — every time. Examples:

- Background work → `OCP\BackgroundJob\*`, not raw cron.
- Config storage → `IAppConfig`, not files.
- HTTP out → `IClientService`, not `curl`.
- File metadata → the WebDAV/Files-Metadata API, not ad-hoc tables.
- Settings UI → the admin settings section pattern, not a bespoke route.
- Tags, mimetypes, notifications, activity, flow → use the real subsystems.

If a Nextcloud-native path isn't obvious, look at how a mature first-party app does
it (Deck, Files, integration_openai) or how the sibling apps
([nextcloud-grafana](https://github.com/kubed-io/nextcloud-grafana),
[nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n)) solved the same problem,
before inventing.

### Don't silently resolve an open fork

Several structural questions in [saga/Chapter_1_First_Contact.md](saga/Chapter_1_First_Contact.md)
are explicitly **raised, not decided** (§6.2, §6.7, §6.9, §6.10). If your work
touches one of them, say so in the PR description and either keep both options
open or get explicit sign-off on which one you're picking — don't quietly bake in
an assumption.

### Validate on a real Nextcloud instance

CI green is necessary, not sufficient. **Every change must be tried by a human on
a real Nextcloud instance with the change applied**, once there's anything
installable. Until then (pre-`lib/`), this mostly applies to `appinfo/info.xml`
changes and anything else that could break app installation.

### When AI writes code, validate harder

AI assistance is welcome — most of this app family was built with it — but the
quality bar does not move. If an agent wrote it:

- **Nitpick everything.** Names, signatures, defaults, error paths, the lot.
- **Read the surrounding code before trusting the diff.** Agents will happily
  invent helpers that already exist or misuse APIs that are right next to the
  line they changed.
- **Re-derive the assertion before the test.** First-pass AI tests often assert
  what the code happens to do, not what the spec says.
- **Verify external references.** Action versions, package versions, API
  endpoints — all of it. LLMs reach for stale majors. Check `gh api
  repos/<o>/<r>/releases/latest`.
- **Don't fabricate Penpot API behavior.** If it's not confirmed in the saga and
  not independently verifiable against a live instance, mark it TBD rather than
  guessing — the saga is unusually careful about labeling "the docs say" versus
  "we watched it happen"; hold new work to the same bar.

The human submitting the PR owns the diff. "An agent wrote it" is not a defense.

---

## The flow: issue → PR → merge

The steps below describe the happy path. Steps 1–2 are **strongly encouraged but
not hard-gated** — they exist so non-trivial work gets scoped before code is
written, not to bureaucratize a typo fix. Steps 3 onward are the actual gates.

1. **Prefer opening an issue first.** Use the
   [`🤖 Agent task` template](https://github.com/kubed-io/.github/blob/main/.github/ISSUE_TEMPLATE/agent-task.yml)
   from the org defaults, or a plain issue if it's a small fix. Describe the
   problem and what "done" looks like. For obvious small fixes (typo, dependency
   bump, one-line doc fix) you can skip straight to a PR.
2. **Let a maintainer weigh in on the issue** before writing code on anything
   non-trivial. This is where scope is agreed and dead-end PRs are avoided. A
   short comment or a label (e.g. `approved`, `enhancement`) is enough — there's
   no formal sign-off ceremony.
3. **Branch from `main`**, work, push. **Opening the PR is a separate step and it
   needs the maintainer's word for it** — see below. Target `main` and link the
   issue if there is one.

   > 🚨 **Agents: never open a PR you were not explicitly told to open**, and never
   > open a second one while work is already in flight on an open branch. Push to
   > the branch instead. [AGENTS.md](AGENTS.md#-never-open-a-pull-request-without-being-told-to-not-once-not-ever)
   > carries the full rule and what does *not* count as approval.
4. **Update [`CHANGELOG.md`](CHANGELOG.md)** with an entry under `## [Unreleased]`
   for any user-visible change. This is enforced in CI by
   [`tarides/changelog-check-action`](https://github.com/tarides/changelog-check-action)
   — a PR with no `[Unreleased]` diff fails the check. Internal-only refactors can
   use a one-line entry under `Changed` saying what was refactored, and a change
   with nothing user-visible in it at all takes the **`no changelog`** label.
5. **CI must pass.** All required workflows green (see
   [What CI expects](#what-ci-expects)). This is a hard gate.
6. **Get at least one approval** from a maintainer. This is a hard gate enforced
   by branch protection. Address review comments by pushing more commits — don't
   force-push over the review unless asked.
7. **Squash-merge** (default) once CI is green and approved. The PR title
   becomes the commit message — keep it clean.
8. **Release** is a separate, manual step (`publish.yml` workflow with
   `push: true`), not on every merge. The merge just lands the change in
   `## [Unreleased]`.

---

## Anatomy of a feature change

**A change starts with a scenario, not with code.** The spec is the requirement
here, not a description written afterwards — see
[features/README.md](features/README.md) for how the suite is laid out.

1. **Find the scenario** your change is about.
   [`features/README.md`](features/README.md) says which file owns which
   behaviour: the folder is the noun (`designs/`, `projects/`, `mapping/`,
   `connection/`) and the file is the verb.
   - If a scenario already describes it, that scenario **is** the acceptance
     criterion. Read it, and read its `# notes:` pointer into
     [`features/AGENTS.md`](features/AGENTS.md) before you decide it is wrong.
   - If none does, **the spec is the thing to agree on first.** Propose the
     scenario in the issue or the PR description and let a maintainer look at it.
     A new `.feature` scenario is a conversation, not a commit you make on the way
     past.
2. **Write the code** in `lib/` — a `Service` for the testable logic, a thin
   `Listener` / `Controller` / `Command` as the adapter, wired in
   `lib/AppInfo/Application.php`.
3. **Make the scenario run**, and take its status tag off. See
   [Testing](#testing) below — this is the step with the rule attached.
4. **Add unit tests** in `tests/unit/` for the rules Behat cannot reach cheaply.
5. **Update the README** if a user could notice the change, and add a
   `## [Unreleased]` changelog entry (see [the flow](#the-flow-issue--pr--merge)).
6. **Update the notes in the same commit.** If behaviour changed, the section of
   `features/AGENTS.md` that explains it is now wrong.

Two artifacts differ by who's driving:

- **Humans:** open **an issue** first to track what's desired or broken.
- **Agents:** update the **[saga](saga/)** — the long-form "why", and the record of
  what is still open. It is the durable memory across sessions; the changelog and
  README are for users.

### Where each kind of writing goes

Four documents, and putting something in the wrong one is how they drift apart:

| Document | Holds | Tense |
|---|---|---|
| [`features/**/*.feature`](features/) | The specification — what the app does | present |
| [`features/AGENTS.md`](features/AGENTS.md) | Why a scenario is the shape it is | present |
| [`saga/`](saga/) | What was decided, what it replaced, what proved it | **past** |
| [`README.md`](README.md) | What a user can do with this | present |

**History goes in the saga and nowhere else.** A note that opens *"this used to…"*
in any of the other three is in the wrong file — a retired decision sitting in a
working document is indistinguishable from a live one to whoever reads it next.

---

## Getting set up

The devcontainer is the supported path. Anything else, you're on your own.

### With the devcontainer (recommended)

1. Install Docker and the VS Code "Dev Containers" extension.
2. Open this repo in VS Code → "Reopen in Container."
3. Wait for `postCreateCommand` to finish (`nvm install && nvm use && npm install`).
4. You now have PHP 8.3, Node (per `.nvmrc`), `gh`, and docker-outside-of-docker.
5. Run `composer install` to pull PHP dev deps.

You'll need a Nextcloud instance to deploy into once there's anything to deploy —
the homelab cluster's `cloud` namespace is the canonical test target once that
matters (see the sibling apps' saga chapters for the deploy loop pattern this app
is expected to follow).

### Without the devcontainer

You'll need PHP 8.4 (matches prod/CI), Composer, Node from `.nvmrc`. Then:

```sh
composer install
npm ci
npm run build   # bundles src/files.js to dist/penpot_sync-files.js
```

---

## Testing

Two suites, and they answer different questions.

| Suite | Runner | Asks |
|---|---|---|
| [`tests/unit/`](tests/unit/) | PHPUnit | Does this rule hold, for every input I can think of? |
| [`tests/integration/`](tests/integration/) | Behat | Does the app do what the spec says, against a real Nextcloud and a real Penpot? |

```sh
composer run test:unit                                    # unit
cd tests/integration && vendor/bin/behat                  # every suite
cd tests/integration && vendor/bin/behat --suite=motion   # one leg
cd tests/integration && vendor/bin/behat --tags '@todo'   # the work queue
```

### The Gherkin comes first

The `.feature` files are the specification, written before the code and kept true
after it. So a feature PR runs **scenario → code → test**, in that order:

1. **Pick the scenario the change is for.** It is the acceptance criterion, and
   agreeing on it is cheaper than agreeing on a diff. If none exists, propose one
   and get it looked at before you build against your own reading.
2. **Build it.**
3. **Make that scenario run**, and take its status tag off.

**The rule, and it is the only hard one here: a scenario stops being `@todo` only
on a PR that runs it.** Promote it to live when it passes; move it to `@unbuilt`,
`@blocked` or `@decision` **with the reason written down** when it does not. Never
by re-reading the spec and deciding what it probably is — the four status tags and
what each one obliges are in
[features/README.md § Status](features/README.md#status--four-tags-and-only-one-of-them-is-a-backlog).

A scenario promoted and then failed in CI is not wasted work. That is how a wall
gets found, and the tag afterwards records a fact rather than an assumption.

### Which suite gets the test

Behat is expensive — every scenario is a real Nextcloud, a real Penpot and a real
round trip — so the split is by what the assertion needs, not by importance.

- **A rule with no I/O** — a path resolver, a mode table, interval parsing, the
  Transit decoder — is a **unit test**. Faster, and it can enumerate cases a
  scenario would have to arrange one at a time.
- **A request payload** is a unit test too: Behat can see what changed at both
  ends, never what went over the wire.
- **Anything a person would describe as a thing they did** is a **scenario**.

If you are writing a scenario whose `When` has no human in it — *"when the export
stream closes with no end event"* — it is a unit test wearing a scenario's clothes.

### Policy

> **Every PR should have tests covering the change when it is reasonable to do
> so.**

"Reasonable" is judgement: a typo fix or a doc change doesn't need one; a new
service method, a bug fix, or a behaviour change does. If you choose not to add a
test, say so in the PR description and why. The default answer is "yes, add a test."

Unit tests live in `tests/unit/`, mirroring the `lib/` tree. The bootstrap is
standalone — no running Nextcloud — and OCP interfaces are stubbed in
`tests/ocp-stubs.php`, which grows one entry per interface a test mocks.

### Three guards you can fail without failing a test

They run in the quality job, and each protects something that otherwise rots while
the build stays green:

| Script | Checks |
|---|---|
| `tests/integration/bin/check-suites.sh` | Every feature file is in exactly one Behat suite. A file in none is never run. |
| `tests/integration/bin/check-notes-anchors.sh` | Every `# notes:` breadcrumb resolves, and no comment block exceeds its two-line budget. |
| `tests/integration/bin/check-step-definitions.sh` | Every step definition is reachable from `FeatureContext`. |

CI also runs Behat `--strict`, so **an undefined step in an untagged scenario fails
the build** — a scenario with no status tag is claiming to be live, and CI enforces
the claim.

### Static analysis + coding standard

These run in CI; run them locally before pushing to save a round trip:

```sh
# PHP
composer run cs:check    # php-cs-fixer dry-run
composer run cs:fix      # auto-fix
composer run psalm       # static analysis
composer run lint        # php -l across lib/

# JS
npm run lint             # ESLint
npm run lint:fix         # ESLint auto-fix
```

Once a `tests/psalm-baseline.xml` exists, **don't regenerate it on your branch**
unless you're explicitly paying down debt. New findings should be fixed, not
baselined — same rule the sibling apps use.

---

## What CI expects

Workflows on PRs into `main` (and where it makes sense, on push to `main`):

| Workflow | Trigger | Jobs | Must pass? |
|---|---|---|---|
| [`pr.yml`](.github/workflows/pr.yml) (🔀 PR) | PR only | Auto-assign author + changelog check | yes |
| [`tests.yml`](.github/workflows/tests.yml) (🧪 Tests) | PR + push to `main` | PHP: install deps, lint `lib/` if present, run the unit suite if `tests/phpunit.unit.xml` exists. JS: install deps, build if `src/` exists, run vitest (`--passWithNoTests`, so it's green with nothing to test). | yes |
| [`quality.yml`](.github/workflows/quality.yml) (🛡️ Quality) | PR + push to `main` | composer audit + php-cs-fixer + Psalm (if `lib/` exists) + ESLint + npm audit | yes |
| `CodeQL` (default setup) | PR + push to `main` | GitHub-managed JS/TS code scanning | yes |
| [`publish.yml`](.github/workflows/publish.yml) (🧬 Publish) | manual `workflow_dispatch` | release tarball | n/a |

**What the jobs actually exercise.** All of them run against real code now — `lib/`,
`src/`, both test suites and the eleven-leg Behat matrix. The pipelines were
scaffolded complete and correct before there was anything to point them at, so no
workflow changed as the code landed into them; the only jobs that still pass
trivially are the ones whose input is genuinely optional.

What the workflows look for from your PR:

- **`CHANGELOG.md` has a new entry** under `## [Unreleased]`
  (`tarides/changelog-check-action`). The PR author is auto-assigned
  (`kentaro-m/auto-assign-action`) per [`.github/assign.yml`](.github/assign.yml).
- **No new php-cs-fixer violations**, once there's PHP to check.
- **ESLint clean** — `npm run lint` exits 0, once there's JS to lint.
- **No new high-severity advisories** from `composer audit` or `npm audit
  --omit=dev --audit-level=high`.

Action versions in workflows are kept current by Dependabot. If you're editing a
workflow, **verify the action's latest major** with `gh api
repos/<owner>/<repo>/releases/latest`.

### Workflow authoring conventions

- **Never put `${{ }}` expressions inside `run:` bash.** GitHub interpolates them
  into the script *before* the shell runs — a script-injection hole and a mix of
  templating with logic. Instead, bind the expression to an **`env:`** entry
  (step- or job-level) and let bash read the clean `$VAR`:
  ```yaml
  - name: Do the thing
    env:
      VERSION: ${{ steps.bump.outputs.version }}
    run: echo "shipping $VERSION"     # not: echo "${{ steps.bump.outputs.version }}"
  ```
  `${{ }}` is fine in `with:`, `if:`, `name:`, `env:` values — just **not** woven
  into `run:`.
- **Prefer `env:` for static or derivable values too** (job-level `env:` for
  repo-wide constants like `APP_ID`), so each `run:` step reads as its actual
  purpose, not plumbing.
- **Invoke scripts with `bash path/to/x.sh`** rather than relying on the
  executable bit.
- **Provision first, act second — don't stagger.** Group all setup/install steps
  up front (checkouts, language runtimes, dependency installs, service
  bring-up), then a readiness gate, then the steps that *do the work*.

---

## Commits, changelog, versions

- **Commits**: keep them focused and descriptive. The PR squash-merge title is
  what ends up in `main`'s history, so make that one count. Conventional Commits
  prefixes (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`) are
  encouraged for the PR title even though they're not strictly enforced yet.
- **Changelog**: every user-visible change adds an entry under
  `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md), grouped by `Added` /
  `Changed` / `Fixed` / `Removed` / `Deprecated` / `Security`. The
  `tarides/changelog-check-action` CI step enforces that the `[Unreleased]`
  section has new content on every PR.

  **The changelog is the release notes.** One line per entry — never a
  paragraph, nested bullet, or implementation detail. Write for an end user
  reading "what's new," not a maintainer reading git history. Entry length
  tracks user impact:

  - **Functional change** (a feature/behavior users notice) → the most detail
    you get, but still one line.
  - **Non-functional** (refactor, types, tests, lint) → short, often half a
    line.
  - **Tooling / CI / DevOps not touching app code** → shortest, three or four
    words (e.g. `- Dependabot enabled.`).
  - **`**BREAKING:**` is the only thing that may stretch** — what breaks, how
    to migrate — under `Changed`.
  - The deeper why / file lists / design go in the **saga** or PR description.
  - When in doubt, write the line, then cut it in half.
  - **Only ever edit `## [Unreleased]`.** Every versioned section below it is
    **immutable** — those notes shipped with a release; never reword, reorder,
    or remove them.
- **Versioning**: SemVer. The release workflow (`publish.yml`) bumps
  `package.json` and mirrors the version into `appinfo/info.xml` — you don't
  bump these in feature PRs.
- **Tags**: `v<major>.<minor>.<patch>`.

---

## Releases

Manual, intentional, not on every merge.

1. Maintainer goes to the Actions tab → `🧬 Publish Version`.
2. Picks the bump type (`patch` / `minor` / `major` / `pre*`).
3. **First run with `push: false`** to verify the tarball builds correctly.
4. **Second run with `push: true`** to actually commit the bump, tag, and create
   the GitHub Release.

The app is pre-1.0 and has not been released yet. The mechanism is the one both
sibling apps use, and reaching the Nextcloud app store with it is what
[Chapter 4](saga/Chapter_4_Open_For_Business.md) is about.

---

## Security

If you've found a vulnerability, **do not open a public issue.** Follow
[SECURITY.md](SECURITY.md).

---

## Where to look next

- **"How does this app work?"** → [README.md](README.md)
- **"What is it supposed to do, exactly?"** → the `.feature` file for that
  behaviour. [features/README.md](features/README.md) says which one owns it.
- **"Why is that scenario the shape it is?"** → [features/AGENTS.md](features/AGENTS.md)
- **"Why was it decided that way?"** → [the saga](saga/), via that section's
  `saga:` pointer. Start with [Chapter 4](saga/Chapter_4_Open_For_Business.md) —
  it is the only chapter that can still be wrong about today.
- **"I'm an AI agent — where do I start?"** → [AGENTS.md](AGENTS.md)

Thanks for contributing. Be kind in reviews, validate on a real instance, and start
from the scenario.
