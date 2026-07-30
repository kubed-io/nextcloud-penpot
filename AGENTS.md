# AGENTS.md

> Cold-start orientation for AI coding agents. Keep it open in another tab.
> **Goal of this file:** get you productive in 60 seconds, then point you to the
> right deeper doc for whatever task you were given.

---

## What this repo is

**Penpot Sync** — a Nextcloud app (planned: PHP backend + small JS frontend) that
mirrors Penpot design files into Nextcloud folders as read-only `.penpot` files.
Lives under the [kubed-io](https://github.com/kubed-io) GitHub org. Licensed
AGPL-3.0-or-later. Target: the official Nextcloud app store, eventually.

**The app mirrors for real, and is now clickable.** The admin surface, the
membership resolver, the pull (both directions — it adds what appeared and
prunes what vanished), the confirmed write paths (rename, move, `sync`-mode
export) and the Files-app opener are all built. `src/` landed with Course 6 and
holds exactly one file action, "Open in Penpot".

Still absent: the scheduled background job, the mode pills, "+ New → design",
personal projects, and notifications. See the Course tables in
[`saga/Chapter_2_The_Colony.md`](saga/Chapter_2_The_Colony.md) for the live
status of every structure — that table is maintained per slice and is the
fastest way to see what exists.

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
- Not a two-way sync engine — this app is **read-only from the Nextcloud side**
  (locked, saga §6.1). Don't design or scaffold a writeback path for file
  *content*.

---

## First moves on any task

1. **Read [README.md](README.md)** if you don't already know what the app is
   meant to do — its "what works today vs. what's design" split is literal, not
   boilerplate.
2. **Read [CONTRIBUTING.md](CONTRIBUTING.md)** for the process — issue-first
   flow, PR rules, what CI expects, testing policy, release flow. **Don't
   re-derive any of this; the contributing doc is the source of truth.**
3. **Read [`saga/Chapter_1_First_Contact.md`](saga/Chapter_1_First_Contact.md)
   before writing anything design-relevant.** It's long and written in an
   alien-survey narrative style (deliberate, per the project owner) — don't skim
   it. It is the authoritative source for what's confirmed true about Penpot's
   API, what's locked architecturally, and what's still an **open fork**. A fork
   marked "raised, not decided" is not yours to silently resolve — either flag it
   back to a human or write code that keeps both options open.

   **Chapter 1 is CLOSED.** Read **§6.18–§6.48 first** — they carry the current
   decisions; everything before them is the survey that produced them, and
   several sections are explicitly superseded (each says so inline). The
   "Chapter 1 — closed" section at the end summarises what was settled, what was
   left open, and where to start building.

This repo is a deliberate third member of a family started by
[kubed-io/nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n) (the
"master") and continued by
[kubed-io/nextcloud-grafana](https://github.com/kubed-io/nextcloud-grafana) (the
"apprentice," a cleaner second-generation copy of n8n). Grafana is the closer
template for anything structural (tooling config, CI shape, doc structure); n8n
is the reference for anything Grafana simplified away that Penpot might still
need. But **Penpot is not a drop-in third copy** — the read-only architecture
(§6.1) is a genuine structural break from both siblings, so don't assume
parity beyond what the saga confirms.

If the task is about **how the app is meant to work**, the README + the saga are
where to look. If the task is about **the process of getting a change in**,
CONTRIBUTING.md owns that — don't ask the human to re-explain the PR flow.

---

## Repo map (where stuff lives)

| Path | What's there |
|---|---|
| [appinfo/](appinfo/) | NC app metadata (`info.xml` only — no `routes.php` yet; it would reference Controller classes that don't exist). |
| [lib/](lib/) | PHP backend (`OCA\PenpotSync`). `AppInfo/`, `Settings/`, `Command/` (the `occ` twins), `Service/` (client, pull, push, archive, metadata, resolver), `Listener/` (rename/move guards + the Files script) and `Migration/` (the mimetype repair steps). `BackgroundJob/` arrives with the scheduled pull. |
| [src/](src/) | JS frontend, Vite-built to `dist/penpot_sync-files.js` per [vite.config.js](vite.config.js). `files.js` registers the one file action; `files-helpers.js` holds the pure logic so Vitest can reach it without `@nextcloud/*` imports. |
| [tests/](tests/) | `unit/` (standalone, no NC server — see `bootstrap.php` + `ocp-stubs.php`) and `integration/` (Behat against a real Nextcloud; the Penpot token is minted by the workflow). `ocp-stubs.php` grows one entry per OCP interface a test mocks. |
| [.github/workflows/](.github/workflows/) | CI: `tests.yml`, `quality.yml`, `integration.yml`, `pr.yml`, `package.yml`, `publish.yml`, `copilot-setup-steps.yml`. All green on the current slice. |
| [.devcontainer/](.devcontainer/) | PHP 8.3 + Node + GH CLI dev environment. |
| [saga/](saga/) | The long-form design narrative. **Read Chapter 1 before touching anything design-relevant.** |
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

CI runs the JS/PHP install + lint/build steps today; see
[CONTRIBUTING.md §What CI expects](CONTRIBUTING.md#what-ci-expects) for exactly
what each workflow currently does versus what it's structured to do once code
lands.

---

## Architectural non-negotiables

These are pulled directly from the saga's **locked** decisions (§6.1–§6.4, and
Course 4's carry-overs from the sibling apps). **Don't relitigate** without a
real reason documented in the saga — and don't confuse these with the *open
forks* listed right after, which are explicitly NOT decided.

- **No `External Storage` / `OCP\Files\Storage` backend.** Wrong tool for "API
  ⇆ archive of files" — same rejection as both sibling apps, more clearly
  correct here given `.penpot` export weight (saga Course 4).
- **Nextcloud never pushes design CONTENT to Penpot — locked (saga §6.1).** No
  "Edit as text" file action, no `NodeWrittenEvent`-driven content writeback, no
  dual-channel writeback config. Do not scaffold any of these.
  **⚠️ Two corrections to earlier wording:** there IS a `sync` vs `link` mode
  axis (§6.22) — it means *whether we store the bytes*, not which way edits
  flow; and there IS a small set of non-content write paths (§6.19: file moves,
  create, restore, project rename, delete). Content stays one-way regardless.
- **No tag/label/annotation sync of any kind.** Penpot's API has no first-class
  tagging system at all (confirmed by grepping the full 149-command `/api/doc`
  RPC surface — zero real hits). There is no Penpot equivalent to n8n's
  `TagSyncService` quartet or even Grafana's simpler folder-based tag mirror
  (saga §6.3). **Note:** this app DOES use Nextcloud-side system tags — the
  project marker (§6.32) and the ignore marker (§6.23) — which is unrelated:
  those are local UI affordances, not a two-system tag sync.
- **Files-Metadata API is the file↔resource link**, keyed on Penpot's file
  `id` — same pattern as n8n's `n8n_id` / Grafana's `grafana_uid`. The metadata
  key name is not yet decided; `penpot_id` is the obvious default but is not
  confirmed in the saga as final.
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
- **Two instance flags are required and off by default upstream:**
  `enable-access-tokens` and `enable-webhooks`. Any setup doc, install script,
  or admin-settings validation should account for both being potentially
  unset (saga Course 0 / §5).
- **The `.penpot` extension is a single token, not a compound** — unlike
  n8n's `.n8n.json` / Grafana's `.grafana.json`, there's no fragility risk
  from someone "simplifying" it, but Penpot's own server still serves the
  export as generic `Content-Type: application/zip` — this app still has to
  register its own custom mimetype via the same `occ
  maintenance:mimetype:update-db`/`update-js` mechanism both sibling apps use
  (saga §6.4). Don't assume a free Penpot-branded mimetype exists.

### Decided across §6.18–§6.48 — these ARE locked

Several forks that earlier drafts of this file listed as open have since been
closed. **Read §6.18–§6.48 before anything else** — they supersede parts of
§6.1, §6.9, §6.12, §6.13 and §6.16, and the superseded sections carry inline
markers saying so.

- **§6.18 — The access model.** A **required service-account token** does all
  reading/mirroring (one background job, one credential — a per-user pull would
  race on shared Team Folders). An **optional per-user personal token** exists
  only to attribute the two write actions to the human who performed them. A
  team cannot be mapped unless the service account holds a `viewer` invite on it.
- **§6.19 — The write paths are few, and only ONE destroys anything.**
  `move-files` (file between projects, and the trash), `create-file`,
  `import-binfile` (restore), `rename-project`, and `rename-file` (still gated
  on §6.2 below). **`delete-file` is the only destructive call in the app**, and
  is only ever reached through an explicitly confirmed user action. Ordinary
  file-manager gestures on a mirror stay purely local.
- **§6.22 — `sync` vs `link` is back**, meaning *whether we store the bytes*,
  **not** which direction edits flow. Neither mode ever pushes content. `link`
  is the default. Penpot is authoritative for a mirrored file's name and project
  placement.
- **§6.23 — Ignore and restore are one mechanism.** "Tagged ignore" and "moved
  out of a mapped folder" are the same state. Restore is always user-confirmed.
- **§6.24 — A mapping is a TEAM.** Projects are mirrored as subfolders by the
  pull, never mapped individually. There is no project-mapping object.
- **§6.21 — Folder metadata is the mapping store**, confirmed live on a real
  production Team Folder (write, persist, read back — identical to an ordinary
  folder). Membership is derived by walking up, never stored on the file.
- **§6.25 — Failure behaviour.** Don't lose data: never prune on a failed or
  partial listing; a remote failure never destroys local state.
- **§6.29 — NESTING IS FREE.** The old "exactly one level, hard cap" is
  **withdrawn**. A file's project is **the nearest ancestor folder carrying a
  project id**; a project folder's team is the nearest ancestor carrying a team
  id. Nextcloud may nest as deeply as the user likes while Penpot stays flat.
  Authority split: **Penpot owns project membership, Nextcloud owns folder
  layout** — a pull never drags a file to a fixed path.
- **§6.30 — A project folder may not leave its team folder.** Free to move
  anywhere inside it; refused (loudly, never silently undone) outside it.
- **§6.31 — Personal projects mount at the user's home root**, with no team
  folder. The ONE exception to the team lookup: no team ancestor + a personal
  project id is valid, not broken. Pulled with the user's own token — the
  service account can never see a personal team.
- **§6.32 — Project folders carry a visible tag** as well as their metadata.
  Metadata is authoritative machine state; the tag is the human-visible,
  searchable marker. The tag is app-owned output, never user input.
- **§6.33 — Creation from Nextcloud is ratified in principle**, scoped to
  locations where the target project is unambiguous; a team's **Drafts** is the
  landing zone otherwise. `create-file` is still unexercised live.
- **§6.34 — The trash bin is ADOPTED, opt-in, off by default.** (This REVERSES
  §6.27, which rejected it — that rejection compared "move the file" against
  doing nothing, when the real alternative is the irreversible `delete-file`.)
  Deleting in Penpot with the bin on **moves** the design to a trash project in
  the service account's personal team. Proven lossless: same id, name, revn,
  history. Restoring moves it back. **We must record the origin project id
  ourselves** — Penpot doesn't remember it. A trashed design is NOT required to
  function while trashed; only the restore must work.
- **§6.35 — Drafts is a STATE, not a folder.** "Has a team ancestor, no project
  ancestor." Never mirror Drafts as a folder. A file at a team folder's root, or
  in any plain folder under it, is in that team's Drafts. Dragging it into a
  project folder files it (`move-files`); dragging it out un-files it. **This
  makes Nextcloud more expressive than Penpot** — one flat Drafts bucket on
  their side, any folder arrangement on ours.
- **§6.36 — A project folder's name always equals its Penpot project's name**,
  both directions (`rename-project` propagates a folder rename). Position is
  free; only the name is pinned. This is what earns the project tag its keep.
  Note this is the INVERSE of the still-open *file* rename fork (§6.2).
- **§6.37 — The reconciler is Nextcloud-trash-aware.** Check the NC trash for a
  matching `penpot_id` before creating a mirror, or restoring one file yields
  two. The NC trash and the Penpot trash project are **independent layers**; say
  which one an action is operating on.
- **§6.39 — Renaming a project folder is its OWN flow**, not a variant of file
  rename. Different node type (folder vs file), different metadata key, different
  RPC (`rename-project` → **204, no body**), no `.penpot` extension to handle,
  and it's **decided** (propagates) where file rename is still an open fork.
  Don't share an implementation path between them. See
  `features/project-folder.feature`.
- **§6.40 — Copying a folder that carries `penpot_project_id` is REFUSED.** Not
  stripped, not allowed. Copying ordinary folders and individual files is
  unaffected. (Reason that generalises: on this cluster one folder may carry
  Penpot, n8n *and* Grafana mappings simultaneously.)
- **§6.41 — With the bin off, restore is BEST-EFFORT, not a failure.** Measured:
  name, pages, assets and `revn` all come back; the id and `file_change` history
  do not. Frame it as "here's what you get" rather than as data loss. It requires
  the file to be in `sync` mode — a `link` file has no bytes to restore from.
- **§6.42 — `get-projects` DOES NOT FILTER `deleted_at`.** It lists deleted
  projects; `get-all-projects` filters correctly, `get-project` 404s,
  `get-project-files` returns `[]`, and *files* filter correctly everywhere.
  **Never conclude a project exists because `get-projects` listed it** — confirm
  with `get-project`/`get-all-projects`, or the pull resurrects deleted folders.
  (This also killed the hope of dropping the trash bin: there is no visible
  Penpot trash to restore from.)
- **§6.42 — `export-binfile` still exports a soft-deleted file** (confirmed:
  6496 real bytes from a file deleted moments earlier, while `get-file` 404s).
  There is still no un-delete — `move-files` on one succeeds but leaves
  `deleted_at` set. Useful as a **rescue path** for `link` files inside the
  ~7-day window.
- **§6.43 — `link` files are confined to their project.** Movable within it
  (including plain subfolders); refused into another project, to the team root,
  or out of the mapping; can't be ignore-tagged. A local delete **hides** them
  and the pull must not recreate them. Every refusal offers "promote to `sync`
  first". `sync` files have none of these limits.
- **§6.44 — A trashed Nextcloud file is fully reachable** (tested live): the
  **fileid is preserved**, metadata survives and is **writable**, content is
  readable **and writable**, and the trash is enumerable via
  `Files_Trashbin\Helper::getTrashFiles()`. ⚠️ Trashed files gain a
  `.dTIMESTAMP` suffix on disk — **match by fileid or metadata, never filename.**
- **§6.45 — The trash IS the hidden marker for links.** No separate flag. "In the
  trash with a matching `penpot_id`" *is* hidden; restoring unhides. Emptying the
  trash un-hides (document it). **A link is NEVER restored to Penpot** — its
  content is never touched for any reason.
- **§6.46 — Take a final snapshot before pruning a `link`.** `export-binfile`
  still works on a soft-deleted file for ~7 days, and trashed-file content is
  writable — so export, write the archive in, promote to `sync`, *then* trash it.
  Also: a design restored in **Penpot's own UI** reappears under its original id,
  and the trash-aware reconciler re-adopts the trashed mirror automatically —
  the best restore path in the app, costing no new mechanism.

### Explicitly NOT decided — do not treat these as locked

- **§6.2 — Does read-only extend to the filename?** `rename-file` is a real,
  simple RPC (one field, fires a webhook) — it's plausible a Nextcloud-side
  rename could propagate even though content stays one-way. **Still open.** Note
  §6.18 already settled *how* it would work if ratified (attribution + failure
  behaviour); the open part is narrowly "do we call it at all."
- **§6.1's creation-scope tension (via §6.7/§6.15).** Whether Nextcloud may ever
  *originate* a Penpot object — a new project from a tagged folder, a new file
  from an unmapped create — is a real open question, separate from the restore
  carve-out §6.23 approved. Don't paper over it.
- **Webhook delivery (§6.17, open question #19).** Creation works and is
  provisioned; **delivery has never been observed.** Nothing in the current
  design depends on webhooks — the cron pull is the sole trigger. Don't build
  webhook-triggered behaviour until this is explained.
- **Export weight on a real design file (open question #5).** Still unmeasured;
  the only test file is ~6 KB. `link`-by-default makes this less urgent, but the
  `sync` path's real cost is unknown.

### Confirmed live in the latest probe — build against these facts

- **`import-binfile` works** (both create-new and in-place `file-id`). It is
  **SSE**, its params are **kebab-case** (`project-id`, `file-id` — unlike
  `export-binfile`, which takes camelCase `fileId`), and its **`name` param is
  ignored** (the archive manifest wins), so a rename is a second call.
- **A deleted Penpot file cannot be resurrected at its original id** —
  `object-not-found`. Restore of a deleted design creates a NEW id, and the user
  must be told.
- **HTTP 200 does not mean success.** Errors arrive as an SSE `error` event
  inside a 200 response. Always parse the stream.
- **The asset URL requires the bearer token** (401 without it) and its inner
  signed GCS URL expires in ~24h — but the asset **id** is stable and
  re-fetchable. Never persist the signed URL.
- **`duplicate-file` is real and works** — `{fileId, name?}`, camelCase, and it
  **does** honour `name` (unlike `import-binfile`). Three commands now disagree
  on param casing; encode it per command, never assume a convention.
- **Penpot has a 7-day soft delete** — `deleted_at` is set to the future *purge*
  time, and the row/history/assets survive until then. But **no API command
  reaches it** (every plausible name 404s), and a soft-deleted file stays
  invisible to all listings. Document it as a user-facing caveat; **never touch
  Penpot's database to work around it.**
- **Cross-team file moves work** — `move-files` to a project in another team
  returns 204 and updates `teamId` automatically. A full round trip is
  **lossless** (same id, name, revn, history), which is what makes §6.34's trash
  bin viable.
- **`create-project {team-id, name}`** → 200 + full record (**kebab-case**).
  **`rename-project {id, name}`** → **204, no body**. Both exercised live.
- **Penpot's name rules are LOOSER than Nextcloud's** — it accepts any non-empty
  string ≤250 chars including `/`, `🎨`, leading spaces. So validation protects
  the **pull** direction (a project named `Has/Slash` can't be a folder), not the
  push. Only `""` is rejected.
- **`delete-project` is soft too**, same ~7-day grace as files — and
  `get-projects` **still returned deleted projects** right after a 204. Don't
  assume a delete disappears from the next listing.

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

### Shape of a feature change (once Chapter 2 starts)

The sibling apps follow one repeatable shape for a feature PR — spec, code,
tests, docs, changelog — described in full in
[nextcloud-grafana/AGENTS.md § Shape of a feature change](https://github.com/kubed-io/nextcloud-grafana/blob/main/AGENTS.md).
This repo has a full `features/` (Gherkin) spec but no `tests/` directory yet, so
that shape isn't executable here today — every `.feature` is tagged `@todo` and
skipped. **The specs are the requirements**: read the relevant `.feature` before
writing code for that area, and update it in the same PR if behaviour changes.
Don't invent a different process without checking whether the saga has already
settled on this one.

**Agents: update the [saga](saga/)**, not just the changelog, when you do
design-relevant work — it's the durable "why" and the record of what's still
open, across sessions.

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
  instance**, once there's anything to try. CI green is necessary, not
  sufficient.

---

## When stuck

- **"How does X work?"** → there is no `lib/` to grep yet; the saga is the
  only source of truth right now, especially Chapter 1.
- **"What's the convention for Y?"** → [CONTRIBUTING.md](CONTRIBUTING.md); if
  it's not there, check the apprentice ([nextcloud-grafana](https://github.com/kubed-io/nextcloud-grafana))
  first, then the master ([nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n)).
- **"Why was this decided?"** → [saga/Chapter_1_First_Contact.md](saga/Chapter_1_First_Contact.md).
- **"Is this a vulnerability?"** → [SECURITY.md](SECURITY.md).
- **"Is this fork resolved yet?"** → check the saga's latest chapter, not this
  file — AGENTS.md records the state as of scaffolding time; the saga is the
  living record.

That's the whole map. Now go read [CONTRIBUTING.md](CONTRIBUTING.md) before
opening anything.
