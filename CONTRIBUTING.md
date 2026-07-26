# Contributing

Thanks for stopping by. This is **Penpot Sync** — a Nextcloud app that mirrors
Penpot design files into Nextcloud as native, read-only files. It lives under the
[kubed-io](https://github.com/kubed-io) GitHub org, shares some workflow plumbing
with the rest of that org, and has a deliberate process around getting changes in.
Please read this whole doc before you push code — most of the "why is my PR stuck?"
questions are answered below.

**This repo is currently pre-code.** There is no `lib/`, `src/`, `tests/`, or
`features/` yet — only scaffolding (docs, tooling config, CI) and the design
narrative in [`saga/`](saga/). If you're picking up Chapter 2 (the first real
implementation work), read [saga/Chapter_1_First_Contact.md](saga/Chapter_1_First_Contact.md)
in full first — several architectural questions are still open forks, not decided.

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
| [saga/](saga/) | Long-form design narrative. Chapter 1 = the feasibility survey against a live Penpot instance, including locked decisions and open forks. **The "why" behind everything else.** |
| [appinfo/](appinfo/) | Nextcloud app metadata (`info.xml` only for now — no `routes.php` yet, it would need Controller classes that don't exist). |
| `lib/` | **Not created yet.** PHP backend (`OCA\PenpotSync`) — Chapter 2+ work. |
| `src/` | **Not created yet.** JS frontend source, built by Vite into `dist/`. |
| `tests/` | **Not created yet.** PHPUnit unit suite, Psalm stubs/baseline. |
| [composer.json](composer.json) | PHP deps + scripts (`test:unit`, `cs:check`, `cs:fix`, `lint`, `psalm`) — wired up ahead of the code that will use them. |
| [package.json](package.json) | JS deps + scripts (`build`, `dev`, `watch`, `test`). Node version pinned in `.nvmrc`. |
| [psalm.xml](psalm.xml), [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) | Static analysis + coding standard config, adapted from the sibling apps. |
| [.devcontainer/](.devcontainer/) | One-shot dev environment (PHP 8.3 + Node + GH CLI + docker-out-of-docker). |
| [.github/workflows/](.github/workflows/) | `pr.yml` (PR housekeeping), `tests.yml` (build + unit), `quality.yml` (audit + lint + Psalm), `publish.yml` (release tarball), `package.yml`, `copilot-setup-steps.yml`. |
| [vite.config.js](vite.config.js) | Frontend build config, targeting `src/files.js` once it exists. |

Things that don't exist yet, in rough order of when they'll be needed: `lib/` and
its PSR-4 tree, `src/files.js`, `tests/unit/` + `tests/phpunit.unit.xml`,
`tests/external-stubs.php` (referenced by `psalm.xml`), `features/` (if this app
family's Gherkin-first convention is adopted here), and the integration test
stack. See [saga/](saga/) for the running plan.

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
3. **Branch from `main`**, work, push, **open a PR** targeting `main`. Link the
   issue if there is one.
4. **Update [`CHANGELOG.md`](CHANGELOG.md)** with an entry under `## [Unreleased]`
   for any user-visible change. This is enforced in CI by
   [`tarides/changelog-check-action`](https://github.com/tarides/changelog-check-action)
   — a PR with no `[Unreleased]` diff fails the check. Internal-only refactors can
   use a one-line entry under `Changed` saying what was refactored.
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

## Anatomy of a feature change (once implementation starts)

The sibling apps in this family follow one repeatable shape for a feature PR — a
Gherkin spec first, then the code, then tests, docs, and a changelog entry. This
repo has no `features/` or `tests/` directory yet, so that shape isn't executable
here today. When Chapter 2 (the first real implementation chapter) starts, expect
a feature PR to land:

- **A feature file**, if this app family's Gherkin-first convention is adopted
  here (check the current saga chapter before assuming) — the behaviour in plain
  language, first.
- **The code** in `lib/` — a `Service` for the testable logic, a thin
  `Listener`/`Controller`/`Command` as the event adapter, wired in
  `lib/AppInfo/Application.php`.
- **A unit test** in `tests/unit/` for the service's rules.
- **README updates** when the feature changes what a user can do.
- **A `## [Unreleased]` changelog entry** (see [the flow](#the-flow-issue--pr--merge)
  above).

Two artifacts differ by who's driving:

- **Humans:** open **an issue** first to track what's desired or broken.
- **Agents:** update the **[saga](saga/)** — the long-form "why" behind the
  change, and the record of what's still open. The saga is the agent's durable
  memory across sessions; the changelog and README are for users.

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
npm run build   # currently has no src/files.js to bundle — see AGENTS.md
```

---

## Testing

There is no code to test yet. Once `lib/` exists, the policy is the same as the
sibling apps':

> **Every PR should have tests covering the change when it is reasonable to do
> so.**

"Reasonable" is judgement: a typo fix or a doc change doesn't need a test; a new
service method, a bug fix, or a behavior change does. If you choose not to add a
test, say so in the PR description and why. The default answer is "yes, add a
test."

### Static analysis + coding standard

These already run in CI even though there's little for them to check yet — set
them up locally so the tooling is exercised before real code lands:

```sh
# PHP
composer run cs:check    # php-cs-fixer dry-run
composer run cs:fix      # auto-fix
composer run psalm       # static analysis (once lib/ exists)
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

**Honest note on current state:** because `lib/` and `tests/` don't exist yet,
several of these jobs currently have nothing to lint, build, or test — they pass
trivially rather than being stubbed out. This is intentional per the scaffolding
brief: the pipelines are structurally complete and correct, ready for Chapter 2's
code to land into them, rather than being placeholder no-op workflows. As `lib/`
and `src/` are added, these same jobs start actually exercising real code with no
workflow changes required.

What the workflows look for from your PR regardless of code-repo maturity:

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

The app is pre-1.0 and pre-code — there is nothing meaningful to release yet.
This section documents the mechanism the sibling apps use, ready for when there
is.

---

## Security

If you've found a vulnerability, **do not open a public issue.** Follow
[SECURITY.md](SECURITY.md).

---

## Where to look next

- **"How does this app work?"** → [README.md](README.md)
- **"Why was it designed this way?"** → [saga/Chapter_1_First_Contact.md](saga/Chapter_1_First_Contact.md)
- **"I'm an AI agent — where do I start?"** → [AGENTS.md](AGENTS.md)

Thanks for contributing. Be kind in reviews, validate on a real instance once
there's something to validate, and write a test if you reasonably can.
