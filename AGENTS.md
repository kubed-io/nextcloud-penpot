# AGENTS.md

> Cold-start orientation for AI coding agents. Keep it open in another tab.
> **Goal of this file:** get you productive in 60 seconds, then point you to the
> right deeper doc for whatever task you were given.

---

## What this repo is

**Penpot Sync** — a Nextcloud app (PHP backend + a small JS frontend) that mirrors
Penpot design files into Nextcloud folders as `.penpot` files. Mirrors are not
edited back into Penpot; a `.penpot` Penpot has never seen can be pushed up as a
new design.
Lives under the [kubed-io](https://github.com/kubed-io) GitHub org. Licensed
AGPL-3.0-or-later. Target: the official Nextcloud app store, eventually.

**The app mirrors for real, in both directions.** The admin surface, the
membership resolver, the pull, the scheduled job, the push ("Sync to Penpot"),
the write paths (create, copy, move, rename, delete, restore, purge) and the
Files-app surface are all built. `src/` holds the file action and the New-menu
entry; `lib/Service/` is where the behaviour lives.

Not built: **personal projects** (a user's own Penpot team mounting at their home
root), **the mode pills**, and tracking a **copied project folder**. A handful of
"Penpot went unreachable mid-gesture" paths log rather than notify.

Chapter 1 of the saga is CLOSED and carries a complete architecture — but read
it before building, because several load-bearing decisions are still open forks
(see [Architectural non-negotiables](#architectural-non-negotiables) below), and
a fork is not yours to resolve silently.

For the user-facing "what does it do?" → [README.md](README.md).

## What this repo is **not**

- Not an `External Storage` backend (rejected — same reasoning as both sibling
  apps, see saga Course 4, even more clearly correct here given `.penpot`
  export weight).
- Not a generic file plugin.
- Not a fork of any upstream Nextcloud app.
- Not a two-way sync engine — this app **never overwrites a design Penpot already
  has** (locked, saga §6.1). Don't design or scaffold a writeback path that edits
  an existing design's *content*. An archive Penpot has NEVER SEEN is the one
  exception and it already exists: "Sync to Penpot" imports it as a new design
  ({@see lib/Service/BulkPushService.php}), the same door a dragged-in file uses.

---

## First moves on any task

1. **Read the `.feature` file for the behaviour you were sent to change.**
   [`features/README.md`](features/README.md) says which file owns what. The specs
   are the requirements, and reading the one that already describes your task is
   faster than reading anything else in this repo.
2. **Read [CONTRIBUTING.md](CONTRIBUTING.md)** for the process — the Gherkin-first
   order, PR rules, what CI expects, release flow. **Don't re-derive it.**
3. **Follow the scenario's pointers when you need the why.** Each one names its
   section of [`features/AGENTS.md`](features/AGENTS.md), which names the saga
   decision behind it. Stop at the depth your question needs — that is what the
   cascade is for.
4. **Before anything design-relevant, read the saga.** [Chapter 3](saga/Chapter_3_Building_To_Plan.md)
   first, because it is the only chapter that can still be wrong about today; then
   Chapter 1 from §6.18, which carries the architecture. It is long and written in
   an alien-survey narrative (deliberate, per the project owner) — don't skim it.

This repo is a deliberate third member of a family started by
[kubed-io/nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n) (the
"master") and continued by
[kubed-io/nextcloud-grafana](https://github.com/kubed-io/nextcloud-grafana) (the
"apprentice," a cleaner second-generation copy of n8n). Grafana is the closer
template for anything structural (tooling config, CI shape, doc structure); n8n
is the reference for anything Grafana simplified away that Penpot might still
need. But **Penpot is not a drop-in third copy** — §6.1 (no editing a design
Penpot already holds) is a genuine structural break from both siblings, whose
`sync` mode means edits flow back. Don't assume parity beyond what the saga
confirms.

If the task is about **how the app is meant to work**, the README + the saga are
where to look. If the task is about **the process of getting a change in**,
CONTRIBUTING.md owns that — don't ask the human to re-explain the PR flow.

---

## Repo map (where stuff lives)

| Path | What's there |
|---|---|
| [appinfo/](appinfo/) | NC app metadata — `info.xml` (incl. the mimetype repair steps) and `routes.php`. |
| [lib/](lib/) | PHP backend (`OCA\PenpotSync`). `AppInfo/`, `Settings/`, `Command/` (the `occ` twins), `Service/` (client, pull, push, archive, metadata, resolver), `Listener/` (the gesture listeners and guards), `Controller/`, `DAV/`, `Notification/`, `BackgroundJob/` (the scheduled pull) and `Migration/` (the mimetype repair steps). |
| [src/](src/) | JS frontend, Vite-built to `dist/penpot_sync-files.js` per [vite.config.js](vite.config.js). `files.js` registers the one file action; `files-helpers.js` holds the pure logic so Vitest can reach it without `@nextcloud/*` imports. |
| [tests/](tests/) | `unit/` (standalone, no NC server — see `bootstrap.php` + `ocp-stubs.php`) and `integration/` (Behat against a real Nextcloud; the Penpot token is minted by the workflow). `ocp-stubs.php` grows one entry per OCP interface a test mocks. |
| [.github/workflows/](.github/workflows/) | CI: `tests.yml`, `quality.yml`, `integration.yml`, `pr.yml`, `package.yml`, `publish.yml`, `copilot-setup-steps.yml`. |
| [.devcontainer/](.devcontainer/) | PHP 8.3 + Node + GH CLI dev environment. |
| [saga/](saga/) | The long-form design narrative, and the **only** place history lives. |
| [composer.json](composer.json) [package.json](package.json) | Dep manifests + script entrypoints, matching the sibling apps' tooling (PHPUnit, Psalm, php-cs-fixer / ESLint, Vite, Vitest). |
| [psalm.xml](psalm.xml) [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) | Static analysis + style config, copied from the apprentice with only namespace/product-name changes. |
| [CHANGELOG.md](CHANGELOG.md) | Every PR adds a line under `## [Unreleased]`. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Process. **Read it.** |
| [SECURITY.md](SECURITY.md) | Vuln reporting policy. |

Out-of-repo but relevant: the [kubed-io](https://github.com/kubed-io) org has
shared workflow plumbing (issue templates, reusable actions) this repo inherits.
The live Penpot instance this app will integrate with is `apps/penpot` in the
`cluster` homelab repo — see
[saga §Course 0](saga/Chapter_1_First_Contact.md#course-0--does-the-planet-let-us-land-the-api--auth-reality-check)
for the exact config path.

---

## Core commands

```sh
# PHP
composer install
composer run test:unit       # PHPUnit unit suite
composer run cs:check        # php-cs-fixer dry-run
composer run cs:fix          # auto-fix style
composer run psalm           # static analysis
composer run lint            # php -l across lib/

# JS
npm ci
npm run build                # produces dist/penpot_sync-files.js
npm run watch                # rebuild on save
```

Same commands as the sibling apps, and all of them run for real today.
Integration runs via Behat from `tests/integration/` (see
`.github/workflows/integration.yml`).

See [CONTRIBUTING.md §What CI expects](CONTRIBUTING.md#what-ci-expects) for what
each workflow does, and [CONTRIBUTING.md §Testing](CONTRIBUTING.md#testing) for the
Gherkin-first order a change lands in.

---

## Architectural non-negotiables

These are pulled directly from the saga's **locked** decisions (§6.1–§6.4, and
Course 4's carry-overs from the sibling apps). **Don't relitigate** without a
real reason documented in the saga — and don't confuse these with the *open
forks* listed right after, which are explicitly NOT decided.

- **No `External Storage` / `OCP\Files\Storage` backend.** Wrong tool for "API
  ⇆ archive of files" — same rejection as both sibling apps, more clearly
  correct here given `.penpot` export weight (saga Course 4).
- **Nextcloud never overwrites a design Penpot already has — locked (saga §6.1).**
  No "Edit as text" file action, no `NodeWrittenEvent`-driven content writeback,
  no dual-channel writeback config. Do not scaffold any of these.
  **⚠️ Three corrections to earlier wording:** there IS a `sync` vs `link` mode
  axis (§6.22) — it means *whether we store the bytes*, not which way edits
  flow; there IS a small set of non-content write paths (§6.19: file moves,
  create, restore, project rename, delete); and there IS a push, for archives
  Penpot has never seen. An existing design's content stays one-way regardless,
  which is the line §6.1 actually draws.
- **No tag/label/annotation sync of any kind.** Penpot's API has no first-class
  tagging system at all (confirmed by grepping the full 149-command `/api/doc`
  RPC surface — zero real hits). There is no Penpot equivalent to n8n's
  `TagSyncService` quartet or even Grafana's simpler folder-based tag mirror
  (saga §6.3). **Note:** this app does put one Nextcloud-side system tag on folders — the
  project marker (§6.32) — which is unrelated: a local UI affordance, not a
  two-system tag sync.
- **Files-Metadata API is the file↔resource link**, keyed on Penpot's file
  `id` — same pattern as n8n's `n8n_id` / Grafana's `grafana_uid`. The key is
  `penpot_id`, and it is indexed alongside `penpot_mode`.
- **Pulling a file is `export-binfile`, and it is SSE, not a plain POST.**
  Calling it returns `text/event-stream` (`progress` events, then `error` or
  `end`); the `end` event carries a separate `/assets/by-id/<uuid>` URL that
  must be fetched in a second authenticated request for the actual ZIP bytes.
  A sync engine needs an SSE-aware client (saga §5.1). Don't design around a
  simple synchronous POST-and-save.
- **`export-binfile` must never be called with both `includeLibraries: true`
  and `embedAssets: true`** — known upstream bug (penpot#7649), opaque 500.
  Default to one option at a time (saga Course 3).
- **Responses are Transit-JSON-tagged even when `Accept: application/json` is
  set explicitly on some endpoints** (confirmed on `export-binfile`'s SSE
  payload — `~:section`, `~u<uuid>`, `~#uri` tag prefixes, saga §5.5). A real
  client needs a Transit decoder or targeted string-parsing of these tags for
  that endpoint specifically; don't assume every endpoint round-trips clean
  JSON just because the header was set.
- **Auth is a single Penpot personal access token**, sent as
  `Authorization: Token <token>` — not `Authorization: Bearer` (that's
  Grafana), not `X-N8N-API-KEY` (that's n8n). There is no service-account or
  admin-level credential on the Penpot side (saga §6.8, checked structurally
  — no `admin`/`system` RPC module exists, and `organizationId` is
  permission-gated off on self-hosted instances).
- **`enable-access-tokens` is required and off by default upstream** — it is what
  lets a Penpot user mint the token this app authenticates with. `enable-webhooks`
  is NOT a requirement: creation works but delivery has never been observed, so
  the scheduled pull is the only trigger (§6.17).
- **The `.penpot` extension is a single token, not a compound** — unlike
  n8n's `.n8n.json` / Grafana's `.grafana.json`, there's no fragility risk
  from someone "simplifying" it, but Penpot's own server still serves the
  export as generic `Content-Type: application/zip` — this app still has to
  register its own custom mimetype via the same `occ
  maintenance:mimetype:update-db`/`update-js` mechanism both sibling apps use
  (saga §6.4). Don't assume a free Penpot-branded mimetype exists.

### Where the decisions live — and it is not here

**The decisions are in the saga, and the saga is the only place they are.** What
follows is an index, not a summary — enough to find the right section, never enough
to act on without opening it. **Do not restate a decision here.** A summary of a
living record is a second answer that nobody updates, and it will be read as the
current one.

<!-- This file carried such a summary for a while: 180 lines restating §6.18-§6.48,
     of which the trash bin, the project-folder-cannot-leave-its-team rule, the
     ignore marker and the open rename fork had all changed underneath it. Saga
     Chapter 3, Round 11
     (saga/Chapter_3_Building_To_Plan.md#round-11--the-docs-stop-carrying-history-and-gain-a-direction). -->

| Chapter | What it is | Read it when |
|---|---|---|
| [1 — First Contact](saga/Chapter_1_First_Contact.md) · CLOSED | The API survey and §6.1–§6.54, the architecture. Several sections are superseded **in place** and say so inline. | You need to know what Penpot's API actually does, or why a rule exists. Start at §6.18. |
| [2 — The Colony](saga/Chapter_2_The_Colony.md) · CLOSED | §C6.1–§C6.38, the build. Ends by reorganising the spec around behaviour. | You want to know how a mechanism came out the way it did. |
| [3 — Building to Plan](saga/Chapter_3_Building_To_Plan.md) · **open** | Rounds 1–11: one behaviour per PR, and what running it found. **The current chapter.** | Always. It is the only chapter that can still be wrong about today. |

**Two rules reversed during Chapter 3, and code written against the older one is
still the likeliest thing you will meet.** Check both before reasoning from an
earlier saga section:

- **Round 7** — a file's `penpot_id` decides nothing on arrival. An archive moved
  into a mapping is imported as a new design, whatever id it is carrying.
- **Round 10** — the folder a design lands in *is* the project, at any depth. The
  "nearest project ancestor" short-circuit was the bug, not the rule.

**A fork marked "raised, not decided" is not yours to resolve silently.** Chapter 1
still carries genuinely open ones — webhook delivery has never been observed, and
export weight on a real design has never been measured. If a task forces the issue,
say so in the PR rather than writing code that quietly forecloses one branch.

### Verifying things yourself

This dev pod has **no public DNS or egress**. To reach Penpot, `kubectl exec`
into the nextcloud pod in namespace `cloud` and use the in-cluster service on
**port 8080**:

```sh
kubectl exec -n cloud <nextcloud-pod> -c nextcloud -- \
  curl -s -X POST http://penpot.cloud.svc.cluster.local:8080/api/rpc/command/get-teams \
  -H "Authorization: Token $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{}'
```

**Probing whether a command exists:** POST it with `{}`. A real command returns
**400** with its full schema in the `explain` field (which is also the fastest way
to learn its exact params and casing); a nonexistent one returns **404**.

---

## Process — short version

Long version in [CONTRIBUTING.md](CONTRIBUTING.md). Short version:

1. **Issue first is preferred, not strictly required.** For non-trivial work,
   open an issue and let a maintainer weigh in on scope before you write code.
2. **PR targets `main`.** Must pass CI and get one maintainer approval.
3. **Changelog entry** under `## [Unreleased]` for any user-visible change —
   see [CHANGELOG.md](CHANGELOG.md)'s header comment for the exact style.
4. **Release is manual**, via `publish.yml`. Don't bump versions in feature
   PRs.

If you're working on behalf of a human, **point them at CONTRIBUTING.md**
rather than re-explaining the flow each session.

### Shape of a feature change

**A PR starts with a scenario, not with code.** Full version in
[CONTRIBUTING.md §Testing](CONTRIBUTING.md#testing); the order is:

1. **Pick the scenario** the change is about, in `features/`. If none describes it,
   the spec is what to discuss first — a new scenario is a conversation, not a
   commit you make on the way past.
2. **Write the code** in `lib/` — a `Service` for the logic, a thin
   `Listener`/`Controller`/`Command` as the adapter, wired in `AppInfo/Application.php`.
3. **Make the scenario run**, and take its `@todo` off. A scenario stops being
   `@todo` only on a PR that runs it — never by reading the spec and deciding what
   it probably is.
4. **Unit-test the rules** that Behat cannot reach cheaply, in `tests/unit/`.
5. **README + `## [Unreleased]` changelog** if a user could notice.

### The cascade: which document answers which question

Four documents, one hop each, and the rule is what keeps them from drifting:

| | Document | Holds |
|---|---|---|
| 1 | [`features/**/*.feature`](features/) | **The specification.** What the app does. Each scenario points at its note. |
| 2 | [`features/AGENTS.md`](features/AGENTS.md) | **The reasoning, in the present tense.** Each section points at its decision. |
| 3 | [`saga/`](saga/) | **The history**, the proofs, the reversals, the open forks. |
| — | [`features/README.md`](features/README.md) | How the suite is laid out: the nouns, the tags, the suites. |

**History goes in the saga and nowhere else.** A note in levels 1–2 that opens
*"this used to…"* is in the wrong file. That is not tidiness: a retired decision
sitting in a working document is indistinguishable from a live one, which is how
the same withdrawn mechanism got proposed three rounds running.

**Agents: update the saga**, not just the changelog, when you do design-relevant
work. It is the durable "why" across sessions, and the record of what is still open.

---

## Principles for AI work in this repo

- **Nextcloud-native first**, once code exists. If there's a Nextcloud
  primitive, use it — don't reinvent `IAppConfig`, `IClientService`,
  BackgroundJob, etc.
- **Don't fabricate Penpot API behavior.** If something isn't confirmed in the
  saga and isn't independently verifiable, say so and mark it TBD rather than
  guessing. The saga is unusually rigorous about distinguishing "the docs say"
  from "we watched it happen" (see §5's landing report) — hold code and docs
  to the same standard.
- **Don't silently pick a winner on an open fork.** See the list above. If a
  task requires picking one to make progress, say explicitly that you're
  doing so and why, rather than writing code that quietly forecloses the
  other option.
- **Verify external references.** Action versions, package versions, API
  endpoints — all of it. Use `gh api` / package registries to confirm.
- **A change is not done until a human has tried it on a real Nextcloud
  instance.** CI green is necessary, not sufficient.

---

## When stuck

- **"How does X work?"** → the `.feature` file for that behaviour, then the
  service in `lib/Service/`. `features/README.md` says which file owns what.
- **"What's the convention for Y?"** → [CONTRIBUTING.md](CONTRIBUTING.md); if
  it's not there, check the apprentice ([nextcloud-grafana](https://github.com/kubed-io/nextcloud-grafana))
  first, then the master ([nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n)).
- **"Why is this scenario the way it is?"** → [features/AGENTS.md](features/AGENTS.md).
- **"Why was this decided?"** → the saga, via that scenario's `saga:` pointer.
- **"Is this a vulnerability?"** → [SECURITY.md](SECURITY.md).
- **"Is this fork resolved yet?"** → the saga's latest chapter, never this file.
  AGENTS.md is a map; the saga is the record.

That's the whole map. Now go read [CONTRIBUTING.md](CONTRIBUTING.md) before
opening anything.
