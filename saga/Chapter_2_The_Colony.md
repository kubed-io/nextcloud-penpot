# Chapter 2 — The Colony

> Transmission log, Probe Designation **PENPOT-1**, Survey Division.
> Reclassified this chapter: **Settlement Division**.
>
> **Prerequisite:** Chapter 1 (*First Contact*) closed **complete**. The line of
> communication is open and the terms are negotiated — we know who may speak for
> whom (§6.18), what we may touch and what we only observe (§6.19), what their
> flatness becomes in our world (§6.29), and what happens to a thing they delete
> (§6.52). We have been down to the surface repeatedly; every claim in that
> chapter was witnessed, and three of our own confident conclusions were
> overturned by going back and looking again.
>
> Chapter 1 asked *"can we dock?"* The answer was **yes, but not the way we
> docked with the last two.**
>
> **Chapter 2 builds the colony.** We stop circling and start putting things
> down — in an order where each thing we build stands on ground we have already
> tested, and nothing load-bearing rests on a assumption we have not walked on.

---

## The doctrine — build on tested ground, in dependency order

The two previous settlements teach opposite lessons, and both apply here.

**From the master (`nextcloud-n8n`):** the settlement worked because the
**admin surface came first and came whole**. Every later feature — the pull, the
push, the lifecycle semantics — was a thing you configured, and configuration
that arrives late means every feature ships twice: once wired to nothing, once
wired for real.

**From the apprentice (`nextcloud-grafana`):** Chapter 2 there had exactly one
standard — *finish the dining room before lighting the stove.* Round 1 was the
admin page complete, **every control actually working**, before the sync engine
existed behind it. That ordering was right and we inherit it.

**What is different here, and it changes the order:** both siblings talked to a
**REST API with one credential and one obvious shape**. Penpot does not have
that. It has an internal RPC bus, Transit encoding, SSE responses that return
HTTP 200 on failure, and — confirmed across four commands — **no inferable
parameter convention at all**:

| Command | Identifier param | Source |
|---|---|---|
| `import-binfile` | `project-id` | §6.20 |
| `export-binfile` | `fileId` | §6.20 |
| `create-project` | `team-id` | §6.38 |
| `rename-file` | plain **`id`** | §6.54 |

Four commands, four conventions. There is no rule to derive — only a table to
build and to test. **So the client comes before the admin surface**, because
unlike the siblings, ours is the part most likely to be wrong in a way that
invalidates everything built on top of it. The admin page is still Round 2, and
still whole before the engine — the sibling doctrine survives, with one course
inserted underneath it.

> **Dr K's standing rule, inherited from Chapter 1 and now the colony's charter:**
> *call the thing before you design around it.* Every fork Chapter 1 resolved
> late was resolved by touching the thing. Two of them had been reasoned
> confidently in the wrong direction first (§6.26, §6.42).

---

## The full settlement plan — every structure, in the order we raise it

A structure is 🟢 *the siblings' technique, nouns swapped*, 🟡 *genuinely
reshaped for Penpot*, 🔴 *built fresh with no precedent*, or ⛔ *deliberately not
built* (named so it cannot creep onto a ticket).

### Landing site — already standing (Chapter 1)

| Structure | State |
|---|---|
| App installs on a real Nextcloud, `IBootstrap` + declarative settings | ✅ live |
| Admin **Instance** card — base URL only, no credential yet | ✅ live |
| `occ penpot_sync:set-url` / `show-config` | ✅ live |
| CI: Tests · Quality · Integration, all green | ✅ live |
| Behat harness + **headless Penpot token minting in GHA** (§6.47) | ✅ live |
| 23 `.feature` files, 267 scenarios, written before the code | ✅ live |

---

### 🛰️ Course 1 — **The Radio** *(Round 1 · the next PR)*

**The `PenpotClient`, and nothing else.** The one part with no sibling
precedent and the highest chance of being subtly wrong. It ships with real
tests against a real Penpot in CI — not mocks — because a mock of a protocol we
have partially misread would only encode the misreading.

| Structure | Kind | Notes |
|---|---|---|
| **Transit decode** | 🔴 | `~:kw`, `~u<uuid>`, `~m<millis>`, `~#set`, and the **cache-reference** form (`^0`, `^1`… back-references into earlier keys — visible in every live response in Ch1). This is the single most under-appreciated risk: a naive JSON parse *appears* to work on small payloads and silently mangles large ones. |
| **Per-command param table** | 🔴 | The four confirmed rows above, as explicit config — never inferred. Open question #21, promoted from tidy-up to prerequisite. |
| **SSE response handling** | 🟡 | `export-binfile`/`import-binfile` stream progress then `end`\|`error`. **HTTP 200 does not mean success** (§5.1/§6.20) — an error arrives as an event *inside* a 200. |
| **The two-step asset fetch** | 🟡 | The `end` event carries a *separate* `/assets/by-id/<uuid>` URL needing a **second authenticated** request (401 without the token, §6.20). |
| **Re-read after write** | 🔴 | §6.49's gotcha: `restore-deleted-team-files` reported success while `deleted_at` was still set. **Never trust the `end` event** — confirm by re-reading. |
| **`get-projects` workaround** | 🟡 | Upstream bug (§6.42, re-confirmed live in §6.54): it never filters `deleted_at`. Use `get-all-projects`. Isolate this so it can be deleted when upstream fixes it (#39). |
| **Typed errors** | 🟢 | Map `:validation`, `:not-found`, auth failures to app exceptions — errors.feature already specifies the behaviour. |
| **`occ penpot_sync:probe`** | 🟡 | A diagnostic that exercises the client against the configured instance and prints what it found. This is how the *next* course gets debugged. |

**Why this is Round 1 and not Round 2:** every other structure calls this one. If
the Transit cache-reference decoding is wrong, the mapping resolver, the pull and
the reconciler are all built on sand — and each would be debugged separately,
against the same root cause, three times.

---

### 🏛️ Course 2 — **The Settlement House** *(the admin surface, whole)*

The sibling doctrine, applied: **finish the room before lighting the stove.**
Every control persists, round-trips, and has an `occ` twin — even where the
engine it configures does not exist yet.

| Structure | Parity target | Kind | Notes |
|---|---|---|---|
| **Service-account token, encrypted** | both siblings' Instance card | 🟢 | The *required* credential (§6.18). Stored `sensitive` in appconfig. |
| **Test connection** | both | 🟡 | Authenticated `get-teams`, distinguishing *missing* from *rejected* from *unreachable* — and reporting **which teams the account can actually see**, since that is exactly what gates mapping (§6.18). |
| **Team mapping card** | `MappingSettings` | 🟡 | Team picker fed by `get-teams`. Refuses a team the service account cannot see, with the invite-as-`viewer` explanation (admin-mapping.feature). |
| **`folder_mode`, immutable** | — | 🔴 | `nested` default, `keyed` offered but **not implemented** — see ⛔ below. Immutable after create, mirroring `MappingService`'s existing refusal on structural fields. |
| **Default mode (`link`/`sync`)** | Grafana's format/mode | 🟢 | Per-mapping default; per-file promotion is Course 5. |
| **Personal token card** | Grafana personal settings | 🟡 | *Optional*, attribution-only (§6.18). Per-user storage — open question #9's one remaining mechanical bit. |
| **Sync schedule card** | `AutoSyncSettings` | 🟢 | Interval; persisted now, honoured in Course 3. |
| **`occ` twins** | both siblings' `occ` set | 🟢 | `set-token`, `test-connection`, `add/list/remove-mapping`. CLI-first stays the house style. |
| ⛔ **Webhook card** | n8n's `WebhookSettings` | ⛔ | **Not built.** Creation works but **delivery has never been observed** (§6.17, #19) — two confirmed mutations produced zero POSTs. Cron is the sole trigger until that is explained. Named here so it cannot sneak on. |

---

### 🧭 Course 3 — **The Survey Stakes** *(membership resolution + the pull)*

The heart of the app. Everything before this was plumbing; this is the thing
that makes a Penpot team appear in Nextcloud.

| Structure | Kind | Notes |
|---|---|---|
| **Nearest-ancestor resolver** | 🔴 | §6.29, *the single most load-bearing rule in the app*. Walk up folder metadata for the nearest `penpot_project_id`, then `penpot_team_id`. mapping-membership.feature is its spec. |
| **Team Folder provisioning + fallback** | 🟢 | groupfolders when present, plain shared folder when not — both siblings' `TeamFolderService` "optional dependency" precedent (#10). |
| **Folder metadata write/read** | 🟢 | Confirmed live on a real production Team Folder (§6.21). |
| **The pull** | 🟡 | `get-teams` → `get-all-projects` → `get-project-files`. **1 + P calls per team, zero exports** for an unchanged instance (§5.5) — this is what makes it scale to many files or few. |
| **Drafts as a state, never a folder** | 🔴 | §6.35. Files at team root are in Drafts. No `Drafts` folder is ever created. |
| **Project folders + visible tag** | 🟡 | Project id as metadata, plus the human-visible pill (§6.32) — under free nesting, position no longer tells you. |
| **`link` files** | 🟡 | The default. A pointer with `penpot_id` + metadata, deep-linking to the live design. **Never calls `export-binfile`.** |
| **Custom mimetype + Penpot icon** | 🟢 | `.penpot` (§6.4). |
| **`occ penpot_sync:sync pull`** | 🟢 | |

**Deferred into this course's own edges, decided when the code exists:** the
duplicate-project-id tie-break (#30) and the duplicate-project-name collision
(#31). Both are reachable only once free nesting is real, and both want to be
decided against a working resolver rather than in the abstract.

---

### 🔁 Course 4 — **Two-Way Traffic** *(the confirmed write paths)*

Every write Penpot permits us (§6.19), and no more. All of them attribute to the
acting user's personal token when present, service account otherwise, and on
failure **the local state always stands** (§6.18 rule 3).

| Structure | Kind | Notes |
|---|---|---|
| **File rename → `rename-file`** | � | Ratified §6.54. Strip/re-add `.penpot`; send under plain **`id`**. |
| **Project folder rename → `rename-project`** | 🟢 | §6.36/§6.39 — its own flow, *not* a variant of file rename. Different event, id, RPC, and response (204, no body). |
| **Move between projects → `move-files`** | 🟢 | Confirmed working both directions (§6.34 probe). Built and gated: `sync` files re-file, `link` files are refused (§6.43). |
| **`sync` mode + `export-binfile`** | 🟡 | Opt-in per file. Downloads the real archive when `revn` moves. |
| **The `/` guard, both levels** | 🔴 | `nested` mode refuses `/` in project *and* file names (§6.51/§6.54), reports which object, skips only that one. |
| ⛔ **Content push** | ⛔ | **Never.** §6.1 is the app's spine: Nextcloud mirrors, it does not edit shape data. |

---

### 🗑️ Course 5 — **The Salvage Yard** *(delete, restore, and the modes)*

| Structure | Kind | Notes |
|---|---|---|
| **Three-layer delete/restore** | 🔴 | §6.52: NC trash → Penpot's own trash (~7 days, **id/revn/history intact**) → our archive (last resort, lossy). **Always check Penpot's trash first.** |
| **Trash-aware reconciler** | 🔴 | §6.37/§6.45 — a trashed file keeps its fileid and metadata, so "in the trash with a matching id" **is** the hidden-link state. No separate flag. **Match by fileid, never by filename** (#43 — trashed files carry a `.dTIMESTAMP` suffix). |
| **`sync`↔`link` promotion** | 🟡 | Demotion deletes a local archive, so the confirmation wording matters (#23). |
| **`penpot:ignore` marker** | 🟢 | Sync mode only (§6.23). |
| **Grace-window rescue** | 🔴 | §6.42: `export-binfile` still exports a soft-deleted file. Converts an unrecoverable `link` deletion into a recoverable one (#38/#42). |
| **Permanent delete, explicit** | 🟡 | `permanently-delete-team-files` is the only irreversible call — never reachable from an ordinary delete. |

---

### 🎨 Course 6 — **The Town Square** *(the Files-app experience)*

| Structure | Kind | Notes |
|---|---|---|
| **Open in Penpot** | 🟢 | Deep link built from the carried `penpot_id` — zero extra lookup. |
| **Mode pills** | 🟢 | `penpot:sync` / `penpot:link`, app-maintained, mutually exclusive. |
| **"+ New" → design** | 🟡 | §6.33: scoped to where it is unambiguous; lands in Drafts otherwise. Needs `create-file` called live first (#27 — never exercised). |
| **Notifications** | 🟢 | Pull results, skipped objects, divergences. |
| **Personal projects** | 🟡 | §6.31 — mounts at the user's home root, the one project with no team ancestor. A **second pull pathway** with its own scheduling story (#28). Build last. |

---

### 🏁 Dessert — **The Charter** *(release readiness)*

| Structure | Kind | Notes |
|---|---|---|
| **Scheduled pull background job** | 🟢 | On the Course-2 interval. |
| **README un-hedged + screenshots** | 🟡 | It already describes the app as if built (deliberately). Drop the hedges, add real screenshots. |
| **Publish to apps.nextcloud.com** | 🟡 | The marquee, once feature-complete — same gate both siblings used. |

---

## The line order

```
  Course 1  The Radio ............  PenpotClient, tested against real Penpot   (Round 1 · next PR)
  Course 2  Settlement House .....  admin surface, whole, every control live
  Course 3  Survey Stakes ........  resolver + pull  ← the app becomes real here
  Course 4  Two-Way Traffic ......  rename, move, sync-mode export
  Course 5  Salvage Yard .........  delete, restore, reconciler
  Course 6  Town Square ..........  Files-app UX, create, personal projects
  Dessert   The Charter ..........  scheduling, docs, store
```

**Courses 1 and 2 are inverted relative to the siblings**, for the reason given
in the doctrine: they had a REST API they understood on day one. We have a
protocol that has already fooled us twice.

---

## What is deliberately not built (so it never sneaks on)

- **`keyed` folder mode.** The *fork* is locked (§6.53) and the mapping field
  exists; the mode is not implemented and has **no feature file**. Three real
  questions block it (#47): how inferred intermediate folders are distinguished
  from user folders, what a move-out-of-team means when position *is* the name,
  and whether a `foo/bar` key collision is refused or disambiguated.
- **Webhooks.** Delivery unexplained (#19). Cron is the trigger.
- **Content push.** §6.1. Not a phase-ordering question — a permanent boundary.
- **Creating teams or projects from Nextcloud** beyond §6.33's narrow carve-out.
  The §6.7/§6.15 fork stays parked.

---

## Round 1 — the commitment

**Course 1 — The Radio.** `PenpotClient`, complete, with its param table, Transit
decoder (including cache references), SSE handling, re-read-after-write
discipline, typed errors, and the `get-projects` workaround isolated behind one
seam. Unit-tested for the decoder; **integration-tested against a real Penpot in
CI** for everything else, on the token-minting harness §6.47 already proved.

Plus `occ penpot_sync:probe`, so Course 2 has something to debug against.

**This PR is also the day-ops shakedown.** It is the first real feature PR
through the pipeline, and finding where that flow is rough is half its job:
branch → CI (Tests · Quality · Integration) → Copilot review triage → merge.
Gaps found get fixed in the pipeline, not worked around.

> **Dr K, planting the first stake:** *"We spent a whole chapter learning that
> this planet lies to you politely — two hundred OK, error in the body. So the
> first thing we build is the thing that listens properly. Everything else is
> just deciding where to put the houses."*

---

## Round 1 — what building it actually found

> Five bugs. **Not one** would have been caught by a hand-written fixture, and
> four of them fail *silently* — the code runs, returns data, and the data is
> wrong. Recorded here because every one is a durable fact about this protocol,
> not a story about a PR.

### R1.1 — Transit's write cache does not cache what you would guess

The decoder's first draft cached every `~`-tagged token over 3 characters.
**Wrong.** Only **keywords (`~:`) and tags (`~#`)** enter the cache. Instants
(`~m…`) and UUIDs (`~u…`) do **not** — despite being long, repeated, and
superficially ideal cache candidates.

Derived, not guessed: walking a live `get-teams` body and checking that all
twelve back-references resolve to fields the record actually has.

```
^0 → features   ^1 → #set        ^2 → permissions  ^3 → type
^4 → membership ^5 → is-owner    ^6 → is-admin     ^7 → can-edit
^8 → name       ^9 → modified-at ^: → id           ^; → created-at
```

Note `^4 → membership` is a cached **value**, not a key. Values share the same
cache as keys.

### R1.2 — A composite tag consumes a cache slot, and its second occurrence is a reference

`["~#set", [...]]` — the **tag itself** is cached. Recursing straight to the
payload (the obvious implementation) drops a slot. In `get-teams` the `~#set`
sits at index 1, so every later reference resolves one slot early.

Worse: the *second* composite arrives as `["^1", [...]]` — a back-reference where
the literal tag was. Matching only on `~#` catches the first and silently leaves
every later one wrapped as a 2-element list instead of its payload.

> **Why R1.1 and R1.2 are the dangerous ones:** both produce **real but wrong
> field names**. `created-at` reads back as `modified-at`. Nothing throws.
> A cache reference past the end of the cache now **throws** rather than
> guessing — that throw is what surfaced both.

### R1.3 — `json_encode([])` produces `[]`, and Penpot 500s on it

A no-arg command has an empty param array. `json_encode([])` renders a JSON
**array**; Penpot's Clojure handler tries to `conj` it into a param map and dies:

```
HTTP 500  :server-error  "Vector arg to map conj must be a pair"
```

Confirmed: `[]` → 500, `{}` → 200. Needs `JSON_FORCE_OBJECT`. Without it, every
**no-arg** command fails while every command **with params** succeeds — which
reads as an auth or connectivity problem, not an encoding one.

### R1.4 — **Penpot content-negotiates. Never send `Accept: application/json`.**

The worst of the five, because the header looks like the tidy, correct thing to
send:

| Request header | Response |
|---|---|
| `Accept: application/json` | `{"teamId":…,"isDefault":true,"teamName":"Default"}` |
| *(none)* | `["^ ","~:team-id",…,"~:is-default",true,"~:team-name","Default"]` |

Two failures compound, neither loud:

1. **Every key lookup misses** — the client reads `team-name`, the response has
   `teamName`.
2. **The shape is mangled** — a plain JSON object has no `"^ "` map marker, so
   `Transit::decode()` walks it as a LIST and returns **numeric keys `0..n`**.

Verified live: with the header, `$record['team-name']` is *missing* and the keys
are `0,1,2,…`; without it they are `id, team-id, created-at, …`.

**Why it survived a live probe:** the probe used raw curl *without* the header
while the client sent it. The probe wasn't exercising the code under test — the
exact gap that makes *"it worked when I tested it by hand"* untrustworthy.
`Transit::decode()` now **refuses** a plain-JSON body with a message naming the
header, checked at the top level *and* at the first list element (listings are a
list of records, so the objects are one level down).

### R1.5 — Penpot has no `/api/health`

`/api/health` → **404**. `/readyz` → 200, but it reports the *process*, not the
API. CI now polls the **RPC bus** (`get-profile`, 200 unauthenticated), because
that is what the next step actually needs and the last thing to come up. A
readiness check that greens before its dependency is ready is worse than none —
it converts a clear *"not ready"* into a confusing failure one step later.

### R1.6 — PHPUnit assertions cannot fail inside Behat

Not a Penpot fact, but a harness trap worth naming. PHPUnit builds an assertion's
failure **message** through `TextUI\Configuration\Registry`, which only exists
when PHPUnit bootstrapped the run. Under Behat it is `null`, so a *failing*
assertion dies with:

```
Type error: Registry::get(): Return value must be of type Configuration, null returned
```

Passing assertions are unaffected — so this fires **only on the failure path**,
i.e. exactly when the diagnostic matters, and replaces it with a harness crash.
Behat steps here throw plain exceptions carrying the command's own output.
(`AdminSteps` had the same latent bug and looked fine, because its assertions had
never failed.)

### R1.7 — Nextcloud's SSRF guard blocks Penpot whenever Penpot isn't public

`allow_local_remote_servers` must be `true` for Nextcloud to reach Penpot at any
private address — a Kubernetes service name, a LAN IP, `localhost`. Otherwise
the request never leaves:

```
Host "localhost" violates local access rules
```

This is a **deployment requirement, not a CI quirk** — §6.17 hit the same gate
on the live cluster, where Penpot is reached at
`penpot.cloud.svc.cluster.local`. It is now in the README's setup section, and
`PenpotClient` catches `LocalServerException` specifically so the message names
the setting instead of reporting a generic connection failure.

**Worth noting how this one was found**, because it validates R1.6's fix: the
diagnostic the client emits is *exactly* the fix. Once the harness stopped
masking failures, CI printed the error message, and the error message named the
setting to change. No investigation was needed. That is what error text is for,
and it is why R1.6 was worth fixing before chasing the failure underneath it.

### What this round retires and what it hardens

- **Open question #21 is no longer a "systematic pass" nice-to-have.** It is a
  build prerequisite, and the table now has a fifth entry beyond the four param
  conventions: the **`Accept` header** is part of the calling contract too.
- **The doctrine held.** Course 1 was put before the admin surface on the
  argument that the client was the part most likely to be subtly wrong. It was
  wrong in five ways, four of them silent. Had it been built after the admin
  surface, those bugs would have surfaced as *"the mapping page shows no teams"*
  — debugged three layers from the cause.

---

## Course 3 — where the survey stakes stand

> The resolver and the first real pull. The app **mirrors** now: `occ
> penpot_sync:sync pull` turns a mapped team into a folder tree, and `occ
> penpot_sync:status` reads it back through the resolver. What follows is the
> scope line — what shipped, what was deliberately left for a later slice, and
> why the cut falls where it does — recorded so the next contributor builds the
> *next* thing, not this one again.

### C3.1 — The resolver landed first, and alone, because it has no sibling

`MembershipResolver` is the one piece of this app with no precedent in either
sibling: both `nextcloud-grafana` and `nextcloud-n8n` talk to a flat REST API
and derive nothing from folder position, so there was nothing to port. It is
also *the single most load-bearing rule in the app* (§6.29). So it shipped as
its own reviewed unit — pure logic over mocked `Node`s, one test per
`mapping-membership.feature` scenario — before anything wrote a marker for it to
read. The metadata keys (`PenpotMetadata`) rode in with it, since the resolver
reads them and DAV must advertise them.

### C3.2 — The pull ships the **fallback** backend, not the preferred one

The course table marks *Team Folder provisioning* 🟢, but this slice builds only
the **plain admin-owned folder** (`StorageService`), and `isAvailable()` skips a
`use_team_folder` mapping with a warning. The reason is testability, not
difficulty: the CI Nextcloud has **no groupfolders app**, so the groupfolders
backend cannot be proven end-to-end here, and an untestable backend is exactly
the kind of surface this project builds *last*, not first. The plain backend is
the siblings' documented fallback (#10), so this is a real path, not a stub — and
the two-method seam (`ensureRoot` / `findRoot`) means the groupfolders backend
drops in behind it without touching the pull.

The consequence to name loudly: the **default** mapping uses a Team Folder, so
in production the default mapping pulls nothing until that backend lands. CI maps
with `--no-team-folder` to exercise the built path.

> **Retired in Course 4 (see C4.3):** the groupfolders backend is now built, so
> a `use_team_folder` mapping mirrors for real. This section is left as written
> to record why the cut fell here first.

### C3.3 — The metadata IS the join, so re-pull is idempotent by construction

A project folder is matched on its `penpot_project_id`, a file on its
`penpot_id` — never on name. That is what makes a second pull reconcile in place
instead of creating `Acme (2)`, and it is asserted live (the third
`pull.feature` scenario pulls twice and proves no `(2)` folder appears). Names
are the *output* of the mirror, never its key — the same discipline the siblings
learned, arrived at here from the resolver's own requirement.

### C3.4 — No fabricated deep-link URL (the doctrine, applied to ourselves)

A `link` file is *"a pointer deep-linking to the live design"* — but Penpot's
workspace route has **not** been called live, and Chapter 1's whole method is
*call it before you design around it* (§6.26). So the link body carries the ids
and the instance base URL and stops there; the browser deep-link is Course 4's,
built against a running Penpot. Writing a plausible `/#/workspace/…` now would be
the exact guess this saga exists to refuse.

### C3.5 — What this slice deliberately does not do

Named here so the seams read as intentional, not forgotten:

- **the project-folder visible tag** (§6.32) — metadata is written; the
  systemtag pill waits for the Files-app surface;
- **`sync`-mode archive download** (`export-binfile`) — Course 4; both modes
  write a link marker for now, but stamp the mapping's mode faithfully;
- **prune of stale mirror files** — the pull is upsert-only; a file whose Penpot
  object vanished is left for the trash-aware reconciler (Course 5), which must
  match by fileid anyway (§6.37/§6.45);
- **the `/` guard as a user-facing report** (§6.51) — a project or file whose
  Penpot name contains `/` is skipped and logged now; Course 4 makes it a report.

### C3.6 — `occ penpot_sync:status` is the resolver's first live witness

The resolver had unit coverage but had never run over a *real* tree. `status`
gives it one: after a pull, `status Penpot/Acme` walks the folders the pull built
and prints the `in_project` / `drafts` / `personal` / `none` verdict — so the
integration suite asserts the resolver on live Nextcloud, and an operator gets an
honest *"where does Penpot think this lives"* answer. A read-only diagnostic that
doubles as the end-to-end assertion for the app's most load-bearing rule earns
its place cheaply.

---

## Course 4 — where the write paths stand

> The first Nextcloud → Penpot writes, and the Team Folder backend Course 3
> deferred. The app is no longer read-only in the strict sense: a **rename** now
> flows back. Content never will (§6.1) — that boundary is permanent, not a
> phase.

### C4.1 — Rename is the whole write surface this slice ships

Course 4's table lists rename, project rename, move, and `sync`-mode export. This
slice takes **only the two renames**, for the same reason Course 1 came before the
admin surface: build the smallest write that is fully understood, prove the
listener → push → Penpot wiring on it, and let move / export ride the seam it
leaves. `PenpotClient::renameFile` / `renameProject` already existed (Course 1
built the write surface ahead of a caller); Course 4 gives them their first one.

A `NodeRenamedEvent` listener (`NodeRenamedListener`) delegates to `PushService`,
which reads the node's own metadata to decide what it is — a `.penpot` file →
`rename-file` on its `penpot_id` (extension stripped, §6.4), a project folder →
`rename-project` on its `penpot_project_id`, anything else (a plain file, an
unmanaged `.penpot`, the team root) → ignored. The move half of the event
(same name, new parent) is left for the next slice; only a genuine name change
is pushed.

### C4.2 — The guard is the wall between the two directions

The pull renames mirror nodes to follow Penpot, and that fires the very event the
write-back listens for. Without a fence, the pull's own correction would be
pushed straight back — the app arguing with itself over a name it just set. A
counter-based `SyncGuard` (ported verbatim from both siblings — pure re-entrancy
bookkeeping, nothing penpot-specific) is raised for the whole pull; the listener
bails while it is active. One hop each way, never an echo: a user rename renames
the Penpot object, the next pull sees the matching name and does nothing.

Attribution rides `PersonalTokenService` exactly as §6.18 specified it before a
caller existed: the acting user's personal token when set, the service account
otherwise. A failure never reverts the local rename (§6.18 rule 3) — the NC name
has already committed, so the push logs and the next pull reconciles Penpot.

### C4.3 — The Team Folder backend, ported not invented

Course 3 shipped only the plain admin-owned folder because CI has no
groupfolders (C3.2). Course 4 adds the groupfolders backend, **ported wholesale**
from the siblings' `TeamFolderService` (saga §14.1) — the "optional dependency"
precedent this app was always going to inherit: `FolderManager` resolved lazily
so a disabled app never breaks DI, the ownerless mount shared to the mapping's
groups, the built-in `admin` group as the write actor, name→id lookups straight
against the `group_folders` table. `StorageService` now routes on
`use_team_folder` behind the same `ensureRoot` / `findRoot` seam, so the pull
never learns which backend answered — exactly the drop-in C3.2 promised.

The one deliberate deviation from the siblings is the permission grant. The
siblings gate write on their mapping's `mode` (a whole dashboard/workflow folder
is either read-only or writable). Penpot's `link`/`sync` is a **per-file** archive
choice, not a folder stance, so it cannot drive a folder permission. Instead the
content groups get a fixed **read + rename (UPDATE)** everywhere: the mirror is
read-only for *content* (§6.1) but a name flows back (§6.2, C4.1), so members may
read and rename and nothing more — create and delete wait for §6.33 / Course 5.

### C4.4 — Why the write paths stay integration-@todo (and that is honest)

The rename write-back is unit-tested to the decision boundary (`PushServiceTest`
pins every node-type verdict, the extension strip, the empty-name refusal, and
both attribution branches) and verified live on the pod. It stays `@todo` in the
integration suite for one concrete reason: the harness is **occ-only**. It has no
running HTTP server, so no WebDAV `MOVE` to fire a real `NodeRenamedEvent`, and no
logged-in session to exercise the personal-token branch. Adding a production
`occ` rename command purely to trip the event would be test scaffolding wearing a
feature's clothes — so the code is proven where it can be proven honestly (unit +
live), and the feature files flip on the day a Files-app channel lands, not the
day the code did. The Team Folder backend is `@todo` for the mirror of that
reason: no groupfolders in CI. Both are exercised live on the pod, where the
cluster's `groupfolders` app and the real Kubed Team Folder mapping are the test.

### C4.5 — The live smoke test earned its keep: a poisoned-transaction cascade

Deploying Course 4 to the pod and running a full `occ penpot_sync:sync pull` over
*both* live mappings failed where each mapping *alone* passed — the tell of state
leaking between them. The error was Postgres `SQLSTATE[25P02]: current transaction
is aborted`, and it surfaced deep in the *second* mapping's file write
(`ObjectStoreStorage::writeStream`), nowhere near its cause. 25P02 is a **cascade**:
once a transaction aborts, every later statement on that connection reports 25P02,
so the real first error was masked. Walking the JSON log back to the first
non-25P02 row found it: the plain (admin-owned) mapping's group share tripped a
bug in this instance's notifications app — `OCA\Notifications\Push::$appConfig` is
null, `Call to a member function getAppValueString() on null` — thrown *after* the
share row committed but with the notification's own transaction left open and
aborted. `syncGroupShares` (correctly) swallows a share failure — a missing or
awkward content group must never fail the pull — but swallowing the exception did
not clear the poisoned connection, so the *next* mapping's writes inherited it.

The fix is small and local: after a caught share create/update failure,
`StorageService` now discards any dangling transaction (`IDBConnection::inTransaction`
→ `rollBack`) before returning. The Team Folder backend is immune — it provisions
through groupfolders' `FolderManager`, never `IShareManager`, so it never triggers
the notification push. Two lessons the doctrine keeps: **a masked cascade error
means hunt for the first failure, not the loudest one**, and **swallowing an
exception is only half a decision — you also own whatever partial state it left**,
here a transaction that had to be explicitly rolled back. This is exactly the
class of bug the occ integration suite cannot see (single mapping, no groupfolders,
no notifications crash to reproduce) and the live pod can — the argument of C4.4,
paid back in a concrete catch.


### C4.6 — The drag, propagated: `move-files` and the two refusals

The rename slice left the move half of `NodeRenamedEvent` on the floor
deliberately, and this slice picks it up. Nextcloud fires **one** event for a
rename and a move, so the listener now routes both: the name changed ⇒
`PushService` (`rename-file`/`rename-project`), the parent changed ⇒ a new
`MotionService` (`move-files`). A WebDAV `MOVE` can do both at once, so both are
checked independently and pushed rename-first — a drag that also renames must not
silently lose one half.

`MotionService` is cut from both siblings' service of the same name, and it is
the smaller for one reason: §6.1 removes the hard part. Theirs delete, create and
re-upload content on a move; ours has exactly one call it can make. So the whole
service is a **classification**, and §6.29 makes the classification a one-liner —
a file's project is the nearest ancestor folder carrying a project id, so resolve
the destination, resolve where it came from, and compare. Same project (a plain
subfolder, two folders mapping to one project): Penpot is never contacted, which
is the common case and costs zero requests. Different project: one `move-files`.
Team root with no project above it: that is Penpot's **Drafts**, which is a real
(default) project, so the file is filed there rather than treated as "no
project". Out of every mapped folder: **nothing is pushed** — unmapping is
Course 5's decision to make explicitly, and a drag is not evidence of it.

### C4.7 — The guard refuses two moves, and only two

Both siblings pair their motion service with a `MoveGuardListener` on
`BeforeNodeRenamedEvent`, because some moves must be stopped rather than
reconciled — throwing `AbortedEventException` aborts the operation and shows the
user why, at the moment they try, instead of leaving them to discover it hours
later on a pull. This app inherits the pattern and carries two rules in it:

**§6.30 — a project folder may not leave its team folder.** A refusal rather than
a silent undo, because both alternatives are worse: honouring it means
reparenting the project in Penpot, a destructive cross-team mutation far outside
§6.1; ignoring it strands a folder carrying a `penpot_project_id` under a team it
no longer sits in, and every later resolution disagrees with Penpot forever
after. Inside its team folder the same folder moves anywhere, for free — §6.29
gives Nextcloud folder layout, and Penpot has no notion of the position.

**§6.43 — a `link` file is confined to its project.** This one changed the shape
of the slice. `link` and `sync` are not two flavours of the same file: a `sync`
file is a real archive, so moving it anywhere leaves the user holding something
valuable, while a `link` is a pointer — move it out and they hold an empty husk
that looks like a design and is not. So every project-changing move of a link is
refused, each with the same escape hatch: *promote to `sync` first*, which is not
a fob-off but exactly the action that makes the gesture safe.

The consequence is worth stating plainly rather than burying: **`sync` mode has
not landed** (its `export-binfile` is a later slice), so every mirrored file is a
link today, and the guard is the entire user-visible behaviour of a cross-project
drag — the `move-files` push sits behind it, built and tested and dormant. That
is deliberate. The classification is the part that has to be right, and it is far
easier to get right against the resolver now than to retrofit alongside an
archive download later. Two comparisons earned their own tests: the guard
compares **both** ids, not just the project, because two team roots each resolve
to "no project" while meaning two *different* Drafts; and the mode check lives in
exactly one place, so `MotionService` never second-guesses a decision the gate
already made.

The slice also collapsed a duplication before it started: "whose token attributes
this write?" now lives on `PersonalTokenService::tokenForActor()` instead of
being re-derived by each write path. Two callers is where that stops being
premature — and where, left alone, one of them would eventually have forgotten to
honour a personal token at all.
