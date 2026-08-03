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
| **SSE response handling** | 🟢 | `export-binfile`/`import-binfile` stream progress then `end`\|`error`. **HTTP 200 does not mean success** (§5.1/§6.20) — an error arrives as an event *inside* a 200. Built and live in C4.8. |
| **The two-step asset fetch** | 🟢 | The `end` event carries a *separate* `/assets/by-id/<uuid>` URL needing a **second authenticated** request (401 without the token, §6.20). Built in C4.8; the two failures are reported apart, because §5.3's 502 was the fetch, not the export. |
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
| **The pull** | 🟢 | `get-teams` → `get-all-projects` → `get-project-files`. **1 + P calls per team, zero exports** for an unchanged instance (§5.5) — this is what makes it scale to many files or few. Reconciles both ways as of C5.1: it adds what appeared and prunes what vanished. |
| **Drafts as a state, never a folder** | 🔴 | §6.35. Files at team root are in Drafts. No `Drafts` folder is ever created. |
| **Project folders + visible tag** | 🟡 | Project id as metadata, plus the human-visible pill (§6.32) — under free nesting, position no longer tells you. |
| **`link` files** | 🟡 | The default. **Zero bytes** as of C6.6 — the identity lives entirely on the file's metadata, which is what the deep link is built from. **Never calls `export-binfile`.** |
| **Custom mimetype + Penpot icon** | 🟢 | `.penpot` (§6.4). Built in C6.3 as `application/vnd.penpot` — no structured suffix, because `+json` lies for a `sync` mirror and `+zip` for a `link` one. |
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
| **File rename → `rename-file`** | 🟢 | Ratified §6.54. Strip/re-add `.penpot`; send under plain **`id`**. |
| **Project folder rename → `rename-project`** | 🟢 | §6.36/§6.39 — its own flow, *not* a variant of file rename. Different event, id, RPC, and response (204, no body). |
| **Move between projects → `move-files`** | 🟢 | Confirmed working both directions (§6.34 probe). Built and gated: `sync` files re-file, `link` files are refused (§6.43). |
| **`sync` mode + `export-binfile`** | 🟢 | Opt-in per file via `occ penpot_sync:set-mode`. Downloads the real archive when `revn` moves — or when the archive is missing (self-healing). C4.8. |
| **The `/` guard, both levels** | 🔴 | `nested` mode refuses `/` in project *and* file names (§6.51/§6.54), reports which object, skips only that one. |
| ⛔ **Content push** | ⛔ | **Never.** §6.1 is the app's spine: Nextcloud mirrors, it does not edit shape data. |

---

### 🗑️ Course 5 — **The Salvage Yard** *(delete, restore, and the modes)*

| Structure | Kind | Notes |
|---|---|---|
| **Three-layer delete/restore** | 🟡 | §6.52: NC trash → Penpot's own trash (~7 days, **id/revn/history intact**) → our archive (last resort, lossy). **Always check Penpot's trash first.** The prune's half is built (C5.1): a vanished design's mirror goes to the NC trash, never further. |
| **Trash-aware reconciler** | 🔴 | §6.37/§6.45 — a trashed file keeps its fileid and metadata, so "in the trash with a matching id" **is** the hidden-link state. No separate flag. **Match by fileid, never by filename** (#43 — trashed files carry a `.dTIMESTAMP` suffix). The remaining half of C5.1: needs `files_trashbin`. |
| **`sync`↔`link` promotion** | 🟢 | Built early, in C4.8 — the move guard needed a real escape hatch to offer. `occ penpot_sync:set-mode`, confirmed on the lossy direction (#23). The Files-app surface is Course 6. |
| **`penpot:ignore` marker** | 🟢 | Sync mode only (§6.23). |
| **Grace-window rescue** | 🟢 | §6.42: `export-binfile` still exports a soft-deleted file. Built in C5.1 — a doomed `link` is exported one last time before it is trashed, converting an unrecoverable deletion into a recoverable one (#38/#42). |
| **Permanent delete, explicit** | 🟡 | `permanently-delete-team-files` is the only irreversible call — never reachable from an ordinary delete. |

---

### 🎨 Course 6 — **The Town Square** *(the Files-app experience)*

| Structure | Kind | Notes |
|---|---|---|
| **Open in Penpot** | 🟢 | **Built (C6.1), fixed (C6.7).** `<base>/#/workspace?team-id=<team>&file-id=<id>` — **both required**; the `file-id`-only form C6.1 shipped returns an internal error. Both ids ride the directory PROPFIND, so the click costs no lookup. Confirmed by opening one. |
| **Mode pills** | 🟢 | `penpot:sync` / `penpot:link`, app-maintained, mutually exclusive. |
| **"+ New" → design** | 🟡 | §6.33: scoped to where it is unambiguous; lands in Drafts otherwise. **#27 settled in C4.8** — `create-file` is now called live by the integration fixture; `projectId` and `project-id` are both honoured. |
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

> **Answered in Course 6 (see C6.1):** the route was read off a live instance's
> own route table, and it is `/#/workspace?file-id=…` — a **query** parameter,
> not the path segment this section was on the verge of inventing. The plausible
> guess was the wrong one, and the shape it would have produced still resolves
> today as a *legacy* route, so it would have worked well enough to look correct
> and quietly failed on the one case that matters (a mirror moved out of its
> project folder). Left as written, because that near-miss is the argument.

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

> **Retired in C4.8:** `sync` mode has landed, so the `move-files` push is no
> longer dormant — a promoted design that changes project is re-filed in Penpot
> for real. This section is left as written to record why the classification was
> built first and proved alone.

### C4.8 — The archive, and the four things a mock would have let us believe

This is the slice that gives the app something to *hold*. Until now every
mirrored file was a pointer, because §6.43 confines links and `export-binfile`
had never been called — which meant the guard was the whole visible behaviour of
a drag (C4.7), the push was dormant, and "sync mode" was a word in a config
table. Now `occ penpot_sync:set-mode <path> sync` fetches the real `.penpot`
archive, and everything §6.43 was protecting people from stops applying to that
file.

**The export is four unmockable steps, and each was found by watching.** The
survey (§5.1–§5.4) had already established the shape; building it turned each
observation into a thing the code has to survive:

1. **HTTP 200 is not success.** The response is an **SSE stream**, and a failure
   arrives as an `error` *event inside* a 200. So `postEventStream()` parses
   events rather than checking a status code, and an `error` event raises the
   same exception a transport failure would.
2. **The stream ends by naming a URL, not by carrying bytes.** The `end` event's
   payload is `{"~#uri": "https://…/assets/by-id/<uuid>"}`, which needs a
   **second authenticated GET** — a different path, on which the token is
   mandatory (401 without it, §6.20). This is the step §5.3 caught failing in
   production for a reason nothing in this app could see: an nginx
   `internalResolver` bug made the asset fetch 502 while the export itself
   "succeeded". So the fetch reports its own failures in its own words — *"the
   export succeeded; this is the asset fetch"* — because the two failures have
   completely different causes and completely different fixes.
3. **The last check is physical.** What comes back is asserted to begin with
   `PK\x03\x04`. Not "the request returned 200" — a ZIP arrived. That single
   assertion is what a proxy returning an HTML error page cannot pass — and it
   immediately earned its keep, see below.
4. **Both boolean flags are sent `false`** (penpot#7649: both `true` returns an
   opaque 500), and the params are **camelCase** — `fileId`, `includeLibraries`,
   `embedAssets` — while its own mirror image `import-binfile` is kebab. That
   disagreement is not ours to rationalise, so the param table records it with a
   comment and a test row rather than a rule.

**The ZIP check caught a real one on its first CI run: Penpot's BACKEND does not
serve exported files.** The integration suite ran `penpotapp/backend` alone —
reasonably enough, since that is what answers the RPC bus — and every export
"succeeded" while downloading **zero bytes**. Reproduced deliberately against our
own instance, the two answers to the same `/assets/by-id/<uuid>` are:

| Asked | Answer |
|---|---|
| `penpot-backend:6060` | **HTTP 200**, `Content-Length: 0`, plus `x-internal-redirect: <signed storage URL>` |
| `penpot:8080` (nginx) | **HTTP 200**, `Content-Type: application/zip`, `Content-Length: 54171` |

The backend authenticates the request and then hands the real location to
**nginx**, which acts on it and streams the file. Nothing about the failure looks
like a failure — the token works, the stream completes, the status is a success —
so the only symptom is *empty backups*.

**A review caught us naming the wrong culprit, and the correction is the useful
part.** `x-internal-redirect` is not the backend's header at all — it is nginx's,
an `add_header` echo of what it just resolved, and reading it off our own
instance we mistook a diagnostic for an instruction. What the *backend* sends
depends on how its object storage is configured, which is why one name was never
going to be enough:

| Backend storage | The backend answers |
|---|---|
| `fs` (the docker default, and CI) | **204**, `x-accel-redirect: /internal/assets/…` |
| `s3` (ours — GCS behind the S3 API) | **307**, `location: <signed storage URL>` |

Nginx then either serves the aliased path from disk or follows the signed URL,
and *in both cases* adds `x-internal-redirect` naming what it resolved. So the
empty-body check keyed off a header that only exists once the request has gone
through the very component whose absence it was written to detect — it happened
to work where we tested it and would have gone quiet everywhere else, including
against our own CI. It now accepts either name. The lesson is narrower than
"test more": we read a header off a live response and assumed the thing that
answered was the thing that set it.

**And we do not choose which one we get.** The asset address arrives in the `end`
event, built by Penpot from its own `PENPOT_PUBLIC_URI` — so `penpot_url` is
irrelevant to this hop. Verified deliberately: pointing the app's `penpot_url`
straight at our backend still exported 1 964 389 bytes, because the URL Penpot
handed back still named the frontend. The failure is therefore a *Penpot-side*
misconfiguration, and the error now says so, quoting the address it was given
rather than blaming a setting the operator can see. CI hit it because its
`PENPOT_PUBLIC_URI` was the backend; the fix was in the stack, not the client.

The CI stack now runs the real two-container topology, because a suite that talks
to the backend alone cannot prove the sentence it exists to prove. It also
retro-explains §5.3: nginx returning 502 was nginx failing to *follow* this
redirect, not the export failing.

**Adding the frontend was necessary and not sufficient**, and the second failure
is the more interesting half. With nginx in front, the downloads stopped being
empty and started being **404** — which is a better error, because an empty 200
tells you nothing and a 404 tells you nginx looked for something and could not
find it. The reason: the backend's answer is `x-accel-redirect`, a path under
`/opt/data/assets`, and **nginx then serves that file off its own filesystem.**
It is not a proxy hop. The archive the backend had just written existed only in
the backend's container. Penpot's own compose file shares one `penpot_assets`
volume between exactly those two services, for exactly this reason; ours now
does too. The topology is not "an app server and a proxy" — it is *one* file
server split across two containers, and the split is invisible until something
asks for a file.

**And a fifth thing, found by reading rather than by failing.** Transit's tagged
values have a *map form* — `{"~#uri": "…"}` — as well as the array form the
survey saw. `Transit::assertNotPlainJson()` did not know that, so decoding the
`end` event would have thrown **"Penpot returned plain JSON… send no `Accept`
header"**: advice that is both wrong and unactionable, since the client already
sends no `Accept` header (R1.4). The fix is narrow — a single-entry map whose
one key starts with `~#` is a tagged value, unwrap it, and deliberately **do not
cache the tag**. It is worth recording because of *how* it was caught: the
error message that would have appeared was so confidently misleading that it
would have sent the next person hunting a content-negotiation bug that does not
exist.

**The pull's rule: `sync` mode is the only thing that costs anything.** A team of
links costs one listing, because the listing already carries `name`,
`projectId`, `revn` and `modifiedAt` for every file (§5.5). So a pull exports
only when it must, and "must" is two conditions, cheap one first:

- the stored revision differs from the live `revn@modifiedAt` — real drift; or
- the file does not actually hold an archive — **self-healing**, which covers a
  promotion whose export failed, a restored-from-trash placeholder, and any
  file that was stamped `sync` while holding a pointer.

The second is the one that keeps a stamp from lying. A stamp says what a file is
*supposed* to hold; only the bytes say what it does. `occ penpot_sync:status`
now prints both, so a `sync` / `pointer` disagreement is visible rather than
inferred from a byte count.

**A failed export must never advance the revision stamp** (§6.18 rule 3, applied
to a counter). If it did, the pull would record "you are at revision N" for a
file that never got revision N's bytes, and the drift check — the very thing
meant to fix it — would agree everything was fine forever. So a failure logs,
counts, keeps the previous content, and leaves the stamp where it was, which
makes the *next* pull retry automatically. For the same reason the pull reports
`failed` separately and does **not** treat it as an error: one design that would
not export must not make a 200-file mirror look broken.

**`set-mode` exports before it stamps**, for exactly that reason one level up.
Stamping first would leave a file claiming to be a backup while holding a
pointer; export first and a failure changes nothing at all. The two directions
are deliberately asymmetrical: promotion is additive, safe, and free to retry,
while demotion **deletes a local backup** that nobody upstream is keeping — so it
confirms first, `--force` is the explicit way to skip the question, and it does
not touch the revision stamp, because demoting does not change which revision the
mirror reflects.

**What this cost, in practice: nothing, until asked.** On the pod a full pull
across three projects reports `0 archive(s) exported`; one `set-mode … sync`
stores 54 171 bytes; the next pull reports `0` again. That last zero is the
whole claim of `link` mode, so it is asserted in CI rather than assumed — a
regression that quietly exported every file would pass every other scenario and
first be noticed as a bandwidth bill.

**Settled in passing (open question #27):** `create-file` had never been called
live. It has now, both spellings, and both `projectId` and `project-id` are
accepted and honoured — the file lands in the named project, not Drafts. The
integration fixture uses it to seed a design to export, so the question is closed
by something that keeps re-answering it.


---

## Course 5 — where the salvage yard stands

### C5.1 — The prune, and the one deletion that had to be built backwards

Until now the pull only ever added. A design deleted in Penpot left its mirror
behind forever — a `.penpot` file that opens nothing, clicks through to a 404,
and is indistinguishable from a real one. That is not a cosmetic gap: the whole
value of the mirror is that it is *honest about what Penpot has*, and a mirror
that only grows stops being that on the first deletion.

**So this slice builds the app's most dangerous operation, and almost all of the
work is in refusing to perform it.** The prune is driven by a single negative
fact — *Penpot did not name this file* — and every way of failing to ask
produces exactly the same fact:

| What actually happened | What the seen-set says |
|---|---|
| The design was deleted | not named |
| `get-project-files` returned 502 | not named |
| A project was skipped for a `/` in its name | not named |
| The token expired mid-walk | not named |

Nothing downstream can tell those apart, and three of the four must never delete
anything. So the pull now carries a `$complete` flag beside its seen-set, and the
prune runs only if it is still true at the end. **A skip is not free any more** —
that is the part that had to be built backwards from the failure. The existing
code skipped an illegally-named project with a warning and carried on, which was
correct when the pull was upsert-only and becomes *a project's worth of deleted
mirrors* the moment a prune exists. The same line of code changed meaning
without changing.

**The one skip that stays cheap is the one that has an id.** A file Penpot names
but that we refuse to mirror — a `/` in its name — is recorded in the seen-set
*before* the legality check, because "we would not write this" is not evidence
about what Penpot holds. Only a file with no id at all takes the prune down, and
only because there is nothing to record.

**Trash, never destroy.** `delete()` on a user-visible node is a move to the
Nextcloud trash, recoverable for as long as the instance's retention allows.
Verified on the pod: the pruned mirror came back out of
`occ trashbin:restore --dry-run` naming its original path.

**And the stamp is the only thing that makes a file ours.** Not the `.penpot`
extension, not its position — under free nesting a mirror may sit in any plain
subfolder the user made, and any file may sit in a project folder. A node with no
`penpot_id` is never touched, which is what keeps a mapped folder usable as an
ordinary folder. For the same reason the prune's walk is **recursive** while the
upsert's index is not: a one-level prune would leave every moved mirror behind
forever, and would be right often enough to look like it worked.

**The final snapshot: the app's one lossy moment, fixed by something Penpot does
for us.** A pruned `link` is a pointer to something that no longer exists, with
nothing to rebuild from — the worst outcome in the app, and it arrives by doing
nothing wrong. But §6.42 established that `export-binfile` still exports a
soft-deleted design for as long as Penpot's own trash holds it, so a doomed
pointer gets **one last export on its way out** and is trashed as a real archive.
Confirmed end to end on the pod: seed a design, pull, `delete-file` (204), pull
again —

```
Pulled 3 project(s): 1 folder(s), 2 file(s), 0 archive(s) exported, 0 skipped.
1 design(s) no longer exist in Penpot. Their mirrors were moved to the Nextcloud
trash: 1 saved as a final archive first, 0 could not be recovered.
```

— and the trashed file is a real ZIP, not the pointer it was a second earlier.

**Best-effort, and it says so.** Past the grace window the export simply fails.
The pointer is trashed anyway (leaving a mirror of nothing would be worse) and
counted as `lost`, because the alternative — claiming a snapshot that was never
taken — is the failure mode this app keeps refusing everywhere else. `rescued`
and `lost` are separate counters for that reason: one number would let a
completely unsuccessful rescue read as a completed one.

**This is the only live claim in the suite about Penpot's behaviour rather than
its wire format**, which is why it earns an integration scenario. Everywhere else
the live tests exist because Transit, SSE and the asset hop cannot be mocked
faithfully. Here a mock would return bytes for a design that never existed and
prove nothing at all — the sentence under test is *Penpot keeps exporting it*,
and only Penpot can be asked.

**Deliberately not built, and named so it does not get assumed:** adopting a
mirror out of the **Nextcloud** trash (§6.37). A design that comes back — because
a human restored it in Penpot's own UI — is currently re-created beside its
trashed mirror instead of being matched to it by `penpot_id`. That needs
`files_trashbin` as an optional dependency and is its own slice; nothing here
hard-deletes, so the cost of the gap is a duplicate, not a loss.

---

## Course 6 — where the town square stands

### C6.1 — The route was read, not reasoned about

Course 3 wrote a `link` pointer that carried ids and an instance URL and
deliberately stopped short of a deep link, because *"Penpot's workspace route has
not been called live"* (C3.4). That refusal is the only reason this slice is
short: the work was not designing a URL, it was **asking the instance what its
URLs are** and then writing down the answer.

The instance answers in its own compiled route table, served at `js/main.js`:

```
["/workspace",                       :workspace]
["/workspace/:project-id/:file-id",  :workspace-legacy]
```

and its router resolves a route as `(match->path (match-by-name router id)
params)` — `match-by-name` is handed **no** path params, so everything reitit
receives lands in the **query string**. The `:workspace` route has no path
segments at all. Two call sites in the dashboard bundle navigate with `file-id`
alone; others add `page-id`. So:

```
<base>/#/workspace?file-id=<penpot_id>
```

> **⚠️ THE SECOND HALF OF THIS SECTION WAS WRONG, AND SHIPPED.** What follows the
> route table below was corrected in **§C6.7** after the link was clicked and
> returned an internal error. The route table is right; the conclusion drawn
> about its *parameters* was not. Left standing, with the refutation inline,
> because how it was gotten wrong is the useful part.

~~**The guess C3.4 refused was the wrong one, and it would not have looked
wrong.**~~ The obvious invention was `/#/workspace/<project-id>/<file-id>` — and
that route still exists and still resolves, as `:workspace-legacy`. ~~It fails on
exactly one case: a mirror **moved out of its project folder** is *unmapped* and
has no ancestor carrying a project id.~~

~~The current route needs **only the id the file itself carries**, which is why
the deep link now survives every move.~~ **It does not.** It needs `team-id` as
well, and without one Penpot errors out. §C6.7 has the correction and the
evidence — including the fact that `:workspace-legacy` exists precisely *because*
the modern route cannot work from a project id alone.

**`page-id` is deliberately omitted, and this part held up.** Confirmed live: a
URL carrying only `team-id` and `file-id` opens the design at its first page.
Penpot's own legacy redirect passes `page-id` through as nil when the legacy URL
had none, so the workspace has always had to cope without one. A mirror does not
know which page a user wants, and inventing one would be the same class of
mistake one level down.

### C6.2 — One action, and the absence is the feature

Both siblings register two openers: theirs, and "Open with text editor" as a
default click for whichever modes hold editable JSON. This app registers **one**,
in every mode, for every file, forever (§6.1). A `.penpot` archive is an opaque
ZIP of nested design-shape JSON: there is no coherent hand-edit and no way to
re-import one if there were, so a text editor would be offering a round-trip that
does not exist.

That single omission is why `src/files.js` is a third the size of its siblings' —
no editor modal, no save path, no injected stylesheet, no `NodeWrittenEvent`, no
priority fight between two openers over which one wins a click. **The read-only
architecture is not being worked around here. It is why the surface is this
small.**

**And the mode axis does not gate the opener**, which is the sharp break from
both siblings and the thing most likely to be "fixed" by someone porting their
code. Their modes change what a click *means*. Here `sync` and `link` differ only
in whether the archive is stored locally (§6.22); both carry the same `penpot_id`
and both point at the same live design. What *does* gate the action is having a
design to open at all: `unmapped` means the id is real but its Penpot original is
gone, and a deleted design never returns at its old id (§6.20), so the action
hides rather than follow a link it knows is dead.

### C6.3 — A mimetype that refuses to claim a structure

`.penpot` is a real, Penpot-specific, **single-token** extension — the one clean
win over both siblings' compound `.n8n.json` / `.grafana.json` (§6.4). But Penpot
serves an export as plain `application/zip`, so there is no branded type to
inherit, and a search turns up no registered mimetype for the format. We pick
one.

We picked `application/vnd.penpot`, **with no structured suffix**, and the reason
is the `sync`/`link` axis. A `sync` mirror holds a real ZIP; a `link` mirror holds
a small JSON pointer. `+json` is a lie for half our files and `+zip` for the
other half — and `+zip` is the *worse* lie, because it actively invites a client
to try to unpack a pointer. So the type claims only what is true of every
`.penpot` row: that it is Penpot's. The vendor tree rather than `x-` because
Penpot is a real vendor and RFC 6838 deprecates `x-` for new types.

The same asymmetry decides the **uninstall** revert. Both siblings re-stamp their
rows to `application/json` on removal; ours go to `application/zip`, which is what
Penpot's own server calls the format. That is right for a `sync` mirror and wrong
for a `link` one, and no extension-keyed mimetype can be right for both — but the
pointer is an implementation detail of an app that is being removed, while the
archive is the thing the user is left holding.

### C6.4 — A sibling bug not inherited

Both siblings resolve their own directory as `\OC::$SERVERROOT . '/custom_apps/'
. APP_ID`. That is right only for a store install. **This repo's own integration
workflow checks the app out into `apps/penpot_sync`**, where that path does not
exist — so the icon copy would have missed, warned into a log nobody reads, and
left every `.penpot` file with a generic glyph while every other step in the
repair reported success. Ported verbatim, the bug would have shipped and been
invisible in exactly the environment built to catch it.

The path is resolved through `IAppManager::getAppPath()` instead, which knows
whichever apps directory the app actually lives in. Worth recording not for the
one-line fix but for the shape: **copying a sibling is this project's default,
and the defaults it copies were written for a different deployment.**

### C6.5 — What is asserted, and what is only claimed

Honest scope, because this slice's most important pieces are the least testable
in CI:

- **Asserted** (`tests/js/files-helpers.test.js`, 32 cases): the deep-link shape,
  including negative assertions that it is *not* the legacy path form and carries
  no project id; the `reference` → `link` wire translation; both modes offering
  the opener identically; `unmapped` hiding it.
- **Not asserted, and named so it is not assumed:** the mimetype registration
  itself. A repair step that silently failed to merge `config/mimetype*.json`
  would look exactly like one that worked. The integration harness is occ-only,
  and every scenario in `open-with.feature` is a click — so the registration, the
  default-click promotion, and the icon are all **claimed, not proven**.
- **Half-built, deliberately:** the deleted-design case. Hiding the opener for an
  `unmapped` file is the "instead of dead-linking" half; *reporting* why, and
  offering the restore, is a later slice. Hiding is the safe subset.

### C6.6 — A `link` stops carrying a body, because the metadata already was one

Course 3 gave a `link` file a small JSON pointer — ids, name, `revn`,
`modified_at`, `team_id`, `instance_url`. It was written at a moment when the
metadata keys existed but nothing consumed them, so the body was the only place
those facts were legible. C6.1 removed that condition: the deep link is now built
from `penpot_id` read off the file's own metadata, over DAV, in the browser.

Which left the body as **a second copy of facts already recorded elsewhere.** Two
copies of a fact drift; the only question is when. And this pair had a named
mechanism ready to do it: the stamp joins `revn` and `modifiedAt` into one opaque
signal, while the body kept them in separate fields — so a demotion had to take
the signal back apart to write a pointer. `ArchiveService::signal()`'s own
docblock warned about exactly this, in exactly these words: *"Joining in one file
and splitting in another is how the two drift."* The class had documented its own
future bug and then shipped it anyway, because there was nowhere better to put
the halves.

**So a `link` is now zero bytes.** The splitter is deleted, the body is deleted,
and with them the `json_encode` failure path, the `instance_url` copy that went
stale the moment an admin changed the base URL, and `storeLink()`'s five
parameters — it takes a `File` and nothing else, because there is nothing left to
describe.

**The rejected alternative is the instructive one: an empty ZIP carrying the
metadata.** It is the obvious shape — the file is *supposed* to be a `.penpot`,
which is a ZIP, so make it a real but empty one. It would have been a quiet
disaster. A ZIP containing any entry begins with `PK\x03\x04`, the same magic
`holdsArchive()` reads, and `holdsArchive()` is the app's only independent
witness to what a file actually holds. Three things would have broken silently:

| What reads the bytes | What an empty ZIP would do to it |
|---|---|
| The prune's grace-window rescue (C5.1) | Every doomed `link` looks like it already holds its backup → trashed with **no final snapshot**, losing the one thing C5.1 was built to save |
| `set-mode … link` | Demands confirmation to delete an "archive" that does not exist |
| `occ penpot_sync:status` | Reports `archive` for a file holding no design |

None of those would have thrown. It is also precisely the "quiet lie" this app
refuses everywhere else — `open-with.feature` and `restore.feature` both exist to
avoid handing someone a placeholder that looks like a design export.

**Zero bytes is the only representation that cannot be mistaken for one**, and it
fails `holdsArchive()` on the size check before a byte is read.

**One clarification worth keeping, because the obvious reading of this change is
wrong.** It is tempting to conclude that the *mode stamp* is now how you know
whether a file holds data. It is not, and inverting that would break C4.8's
self-healing: an export that dies halfway leaves a file stamped `sync` holding
nothing, and only *looking at the bytes* catches it. The stamp says what a file
is supposed to hold; the bytes say what it does. This change **strengthens** that
split rather than softening it — "no bytes" used to be inferred from a body we
had written ourselves, and is now simply true.

**Two things fall out for free.** `storeLink()` became idempotent — an
already-empty file is left strictly alone, because `putContent('')` still moves
the mtime and etag, and the pull calls it once per `link` per pass. Written the
naive way, every desktop client would re-download every `link` file after every
pull. And a legacy JSON body needs no repair step or version check: the next pull
calls `storeLink()` on it and the body is gone.

`occ penpot_sync:status` keeps `pointer` as a third state alongside `archive` and
`empty`, and it now means something useful — *a body from before this change,
not yet truncated*. The integration step that used to accept it now demands
`empty`, so a demotion that left a body behind is a test failure rather than a
shrug.

**A closing note on where this decision actually came from.** The README has said
*"a `link` file holds no bytes"* since it was written, as the justification for
why a link may not be moved out of its project — an empty husk that looks like a
design and isn't. The spec described zero bytes; the implementation shipped a
JSON body; and the gap survived four courses because nothing ever forced the two
to be compared. It took someone reading the mirror and asking *"if everything we
need is in the metadata, why even have the JSON?"* This slice did not invent a
new design. It made the code agree with the document that had been sitting on top
of it the whole time.


### C6.7 — The route was read. The parameters were assumed. It shipped broken.

`/#/workspace?file-id=<uuid>` returns **an internal error**. C6.1 shipped it as
the deep link, and it took one click on a live instance to disprove a section
that had been written with some confidence.

**The route table reading was correct.** `["/workspace" :workspace]`, no path
params, `router/resolve` puts everything in the query string — all true, all
still true. The failure is entirely in the next step: *which* query params the
workspace actually requires.

**The bad inference, precisely.** The bundle's `go-to-workspace` call sites pass
`file-id` alone, and that was read as "file-id is sufficient." Those are **in-app
navigations**. The user is already inside a team; `team-id` is already in the URL
and is carried across the transition. A cold load from outside carries nothing at
all. The evidence was real, and it was evidence about a *different situation than
the one being designed for* — a link arriving from Nextcloud is by definition
never an in-app navigation.

**The refutation was sitting in the same file, unread.** `:workspace-legacy`
takes `/#/workspace/<project-id>/<file-id>` and — this is the whole tell — calls
**`get-project {id}` purely to look up the team id**, then navigates with
`{team-id, file-id, page-id, layout}`. An entire RPC round trip exists in
Penpot's own code for no other purpose than obtaining a team id before opening a
workspace. C6.1 read that redirect's *route* and stopped before its *body*. Had
it read ten lines further, the requirement was stated outright.

**And the conclusion drawn from the bad premise was the confident one.** C6.1
argued the modern route was *better* than the legacy form because it needed only
the id the file carries, so an unmapped mirror would still link. The opposite is
true: the legacy form needs a project id, the modern form needs a team id, and
**neither is on the file**. The section congratulated itself for a property the
design did not have.

#### Where the team id comes from, and why not the resolver

`penpot_team_id` is now stamped on the file. The obvious objection — *we have a
whole membership resolver for exactly this* — is right that the mechanism exists
and wrong about which end it serves:

|  | Resolver at click time | Stamp on the file |
|---|---|---|
| Where it runs | Server-side PHP, walking `Folder` nodes | Rides the directory PROPFIND the browser already has |
| Cost per click | An endpoint + a round trip | Zero |
| Mirror dragged out of its mapping | Resolves to **nothing** — no ancestor left | Still correct: the design never moved in Penpot |

That last row decides it. `unmapped` means the *position* was lost, not the
design — it is alive and openable, and the file's own stamp is the only surviving
record of where it lives. A resolver-backed link would break exactly when someone
rearranged their files.

#### But the resolver is still the authority — as the writer

The stamp is a **cache**, and a cache with no way to check it is a rumour. Two
mapped team folders means dragging a mirror from one tree to the other genuinely
changes which team owns the design, and a stamp left naming the old team is a
link that opens the *wrong team's workspace*. So:

- **`MotionService` re-stamps** from the resolved destination, *after* Penpot
  accepts the move — never before, or a failed `move-files` would leave a stamp
  describing a move that did not happen (§6.18 rule 3).
- **`occ penpot_sync:status` reports a mismatch** between the stamp and the
  folder walk, the same way it already contrasts the mode stamp against the
  actual bytes. A disagreement is visible rather than inferred.
- A file resolving to **no** team is not reported as a mismatch: that is the
  unmapped state, where the stamp is *supposed* to be the only record.

This is the shape the whole app already uses, arrived at from the other
direction: **the stamp says what something is supposed to be; looking says what
it is; and the thing that looks is what corrects the stamp.**

#### A second bug, found while fixing the first

The opener was gated on `canOpenInPenpot(mode)`, hiding itself for `unmapped` —
described in C6.1 (and to the project owner, who approved a fallback on the
strength of it) as "the design was deleted, so the id is dead."

`unmapped` does not mean that. `PenpotMetadata` defines it as *carries a
`penpot_id` but resolves to no Penpot ancestor* — a mirror moved out of its
mapped folder, whose design is perfectly alive. Hiding the opener there would
have broken the link precisely in the case the deep link is meant to survive.

It was also **dead code**: nothing in the app has ever written `MODE_UNMAPPED`.
The pull stamps `sync` or `link` and nothing else. And the state it was reaching
for is unreachable anyway, because the prune (C5.1) moves a vanished design's
mirror to the Nextcloud trash rather than leaving it in the tree. A gate that
could never fire, guarding against a state that cannot occur, justified by a
definition that was wrong.

#### The lesson, narrower than "test more"

C3.4 refused to invent this URL and was right to. C6.1 then *did* call the live
instance — and still got it wrong, because it called the thing that answers
"what are the routes?" and not the thing that answers "what happens when a
stranger opens this link?" **Reading an implementation is not the same as
exercising an entry point**, and the gap between them is exactly wide enough to
fit a confident paragraph. The check that found this was a person clicking a
link, which no amount of further reading would have replaced.
### C6.8 — The copy probe: it was never impossible, only decided against

`copy.feature` says, in its loudest scenario, *"No copy, anywhere, ever writes to
Penpot."* Reading it cold, that sounds like a limitation. It is not — the file
already recorded that `duplicate-file` works (§6.28) and filed a **"PROPOSED,
NOT ADOPTED"** scenario for it. The refusal was a *design* judgement (a Ctrl+C is
someone organising files, not authoring work — §6.1), not a capability finding.

What had never happened is the thing this project's charter demands: **calling
it.** So it was called, against the running instance, and the answers moved two
sections of the survey.

#### What the wire actually says

| Command | Live schema (Penpot 2.17.0) | Result |
|---|---|---|
| `duplicate-file` | `{file-id: uuid, name?: string≤250}` | new design, **name honoured**, full record returned |
| `move-files` | `{project-id: uuid, ids: set<uuid> min 1}` | **204**, including on a just-created duplicate |
| `delete-file` | `{id: uuid}` | **204** (soft; §6.42's grace window) |

**Two corrections to §6.28.** It records `duplicate-file` as taking **camelCase
`fileId`** — the schema says **kebab `file-id`**. And it has **no project
parameter at all**, which §6.28 never claimed but every reader would assume from
"a Penpot-side copy is one call": a duplicate always lands in the *source's*
project. That single missing parameter is why the user's two gestures are two
different mechanisms rather than one.

#### The copy is two calls, and which two depends on where it lands

- **Copy inside the same folder** — the destination resolves to the same Penpot
  project, so `duplicate-file` alone is the whole operation.
- **Copy up to the team root** — the destination is Drafts, a *different*
  project, so it is `duplicate-file` **then** `move-files`. Confirmed working end
  to end: duplicate → 204 move → the copy is in Drafts.

Both are the same feature and want to be scenarios of one feature file, not two
features. They differ only in whether the nearest-ancestor walk (§6.29) returns
the source's project or another one — which is the same question every other
write path already asks.

#### The bug this probe did NOT find, and the retraction

This section first read: *"`move-files` wants `project-id` and `ids`.
`PenpotClient::moveFiles` sends `project` and `files`. Course 4's move
write-back has never worked once."* Written with the schema quoted beside it,
and completely wrong.

`PenpotClient` has a **per-command parameter table** (`PARAMS`), because §6.21
established there is no convention to derive — only a table. `move-files` has a
row in it:

```php
'move-files' => ['project' => 'project-id', 'files' => 'ids'],
```

The method's array keys are the *app's* vocabulary; `call()` translates them to
Penpot's on the way out. `PenpotClientTest` even asserts this exact row. The
move write-back is fine, and always was.

**The error was reading one layer and concluding about another** — the method
body, not the wire. It is the same shape as the id mistake below: a plausible
reading of partial evidence, stated as a finding about the remote system. Twice
in one probe.

What makes it worth keeping is that the table is the *reason* the mistake was
available. A per-command translation layer is exactly right for an API with four
casing conventions, and it also means **a method signature no longer tells you
what goes on the wire.** Anyone auditing this client against a live schema must
read `PARAMS`, not the callers. That is the cost of the table, and it is worth
paying — but it should be paid knowingly.

#### The other error, same shape

The first duplicate's id was extracted with a greedy `sed` that captured the
**last** uuid in the response rather than the file id. Every call made with it
failed — a 500 on `move-files`, a 404 on `delete-file` — and the obvious reading
was "Penpot refuses to move or delete a fresh duplicate." That conclusion was
about to be written down as a finding. It was entirely an artefact of the probe.

Reading the id back out of `get-project-files` instead of scraping it made all
three calls succeed. **A probe that mutates is only as trustworthy as the ids it
echoes back.**

Both errors in this section share one shape, and it is worth naming because the
saga's whole method is built to prevent it: *evidence from one layer, stated as a
conclusion about another.* A uuid scraped from a response is not the file id. A
method's parameter names are not the wire's. Both readings were plausible, both
were confident, and both were wrong in the direction of "the remote system is
broken" rather than "I am holding it wrong." That direction is the tell.

### C6.9 — The decoder bug Course 1 predicted, found two courses late

Course 1 named the Transit write cache *"the single most under-appreciated
risk"*, and said exactly how it would fail: *"a naive parse appears to work on
small payloads and silently mangles large ones."* That is precisely what
happened, and it took a user copying a file to surface it.

The copy created the design in Penpot and then reported failure:

```
penpot_sync copy: could not duplicate the design; the copy is untracked
Penpot response referenced Transit cache entry "^23" (index 91)
but only 91 entries were seen.
```

**Two bugs, both in the cache, both invisible until a big record arrived.**

#### 1. The ceiling was 94. It is 1936.

`CACHE_MAX` was set to 94 with a comment asserting Transit "holds at most 94
entries before it resets". It does not. An index is one or two base-44 digits,
so `MAX_CACHE_ENTRIES = 44 * 44 = 1936`.

The damage is worse than the number suggests, because **a capped cache does not
fail at the cap.** It keeps decoding against a cache that has stopped growing,
so every later reference resolves against the wrong slot or misses.

#### 2. Keys and values are not cached by the same rule

`isCacheable()` demanded a `~:` or `~#` prefix in **both** positions. Transit
caches **every map key** over three characters — plain strings included — and in
value position only the tagged forms (`~:`, `~#`, `~$`).

Every plain-string key was therefore skipped, shifting every subsequent index.

**Why it survived two courses:** the payload the decoder was originally verified
against — `get-teams` — has only keyword keys and fewer than 94 entries. It
cannot exercise either bug. Both faults were latent in every response too small
to reach them, which is the exact failure profile Course 1 wrote down.

#### The proof, measured rather than argued

A real 65 KB `get-file` body was captured off the running instance and replayed
through both rule sets:

| Rule | Cache built | Bad references |
|---|---|---|
| As shipped (prefix required in both positions, cap 94) | 161 | **109** |
| Corrected (any map key; tagged values only; cap 1936) | 206 | **0** |

Not "looks right" — 109 versus 0 on a payload the app fetches in normal use.

#### What this cost, and the shape of it

A copy created a real design in Penpot and then told the user nothing had
happened, leaving an untracked file beside an orphaned design. A retry would
have made a second one. The write succeeded; only our reading of the reply
failed — which is the worst division of labour available, because it is the one
where retrying makes things worse.

**And the silent case is the one to fear.** A missing reference throws in key
position, which is how this was caught at all. A *shifted* reference does not:
it resolves to a real, plausible field name for the wrong field. `created-at`
reads back as `modified-at`, and the pull's drift check quietly compares the
wrong number. There is no telling how many small responses were decoded slightly
wrong before a big one finally threw.

The guard that saved us is the one the decoder already had: refusing to guess on
a key-position miss instead of falling back to the raw token. It converted a
silent corruption into a loud failure two courses later — late, but not never.

### C6.11 — The trash commands, called before designing around them

Course 5 built the prune on Penpot's trash but never called the trash commands
themselves; §6.52 recorded their names and one behaviour, and §6.49 recorded a
gotcha. Before building delete/restore, all four were called against the running
instance. Three of the four answers were not what the survey implied.

#### The schemas

| Command | Schema (Penpot 2.17.0) | Shape |
|---|---|---|
| `create-file` | `{name: ≤250, project-id: uuid, id?: uuid, is-shared?: bool, features?}` | plain 200 |
| `get-team-deleted-files` | `{team-id: uuid}` | plain 200, a list |
| `restore-deleted-team-files` | `{team-id: uuid, ids: set<uuid>}` | **SSE** |
| `permanently-delete-team-files` | `{team-id: uuid, ids: set<uuid>}` | **SSE** |

`create-file` takes **kebab `project-id`** and has an optional `id` — you may
supply the uuid yourself, which is a door worth knowing about and not one this
app opens.

#### 1. `permanently-delete-team-files` DOES NOT REQUIRE THE FILE TO BE IN THE TRASH

The single most important finding here, and the opposite of what its name
implies. The probe design had been **restored** — live, listed in its project,
not deleted at all. Passing its id to `permanently-delete-team-files` destroyed
it: HTTP 200, a progress event, and gone from the project.

So this is not "empty the trash". It is **"destroy these designs"**, and it will
do that to a perfectly live file if you hand it one.

The rule that follows is narrow and absolute: **the ids passed to it may only
ever come from `get-team-deleted-files`.** Never from a mirror's metadata, never
from a user's selection, never from anything the app resolved itself. §6.52
already called this "the one irreversible call"; what it did not know is that
the command has no safety of its own, so ours is the only one there is.

#### 2. Restore reports success for ids it did not restore

`restore-deleted-team-files` with an id that is not in the trash answers **200
with an `end` event carrying an EMPTY SET**. No error, no warning.

So the `end` event is not a boolean. It carries **the set of ids actually
restored**, and that set is the only honest answer to "did this work". A caller
that treats 200 as success will happily report a restore that restored nothing —
which for a user is worse than an error, because they go looking for the file.

#### 3. §6.49's lying restore did not reproduce

§6.49 recorded `restore-deleted-team-files` reporting success while `deleted_at`
was still set, needing a second call. On 2.17.0 it did not: immediately after the
`end` event the file was out of the trash and back in its project, checked in the
same breath.

**The re-read rule stays anyway.** One non-reproduction does not disprove a race
— it is exactly the shape of thing that reappears under load — and confirming
costs one cheap listing call against the alternative of telling someone their
work is back when it is not. §6.49 was right about the discipline even if this
instance no longer needs it.

#### 4. Both are SSE, like `export-binfile`

`restore` and `permanently-delete` stream `event: progress` (one per file, with
`index`/`total`) then `event: end`. So they need the SSE reader, not `call()` —
and, exactly as §5.1 warned for export, **HTTP 200 says nothing about whether the
work happened**. The client's existing event-stream path applies to all three.

### C6.12 — The rename that only a real gesture could show

The delete listener shipped with a green unit suite and failed on the first run
of its integration scenario. The purge never reached Penpot, and the reason is
one line:

```php
if (!str_ends_with($node->getName(), '.penpot')) { return; }
```

**Nextcloud renames a node on its way into the trash**, stamping it with the
deletion time: `Gone For Good.penpot.d1785457295`. By the time the purge event
fires, the extension is no longer last — so the guard meant to identify our files
rejected the very file it existed to catch, and the design stayed in Penpot's
trash forever.

**No unit test could have caught it, and that is the point.** A mocked node has
whatever name the test gives it, and the test gives it a sensible one. The
rename is *Nextcloud's*, not ours: it happens in the gap between our two events,
in code we do not call, to a node we did not create. Mocks reproduce our
assumptions faithfully — which is exactly why they cannot contradict them.

n8n's WebDAV helper had the fact written down (*"NC trashbin renames entries with
a `.dNNNN` deletion-time suffix"*), in a comment about finding trash entries. It
was read, ported, and not connected to the listener being written twenty minutes
later — the fact was in the repository and still not in the code.

The listener now recognises both spellings and has its own test for the routing
decision, which is worth having on its own terms: getting it backwards is the
worst bug available here, because it would permanently destroy a design on an
ordinary delete.

**The general shape, which has now recurred three times in this course** (§C6.8's
param table, §C6.10's membership shape, this): *a test that mocks the boundary
cannot fail in the way the boundary actually behaves.* Every one was found by a
real gesture — twice by a person, once by the integration suite that exists
because of the first two.

### C6.13 — The purge is not an event, and two siblings disagreed about it

The delete listener was built to handle both steps of a delete, telling them
apart by the node's path — `<uid>/files/…` for the first delete,
`<uid>/files_trashbin/files/…` for the purge. The soft step worked. The purge
never ran at all, and the design sat in Penpot's trash forever.

**Nextcloud does not fire a typed event when a file is purged from the trash.**
The trashbin's `removeItem` emits the legacy `\OCP\Trashbin` `preDelete` hook,
wired with `\OCP\Util::connectHook`, and that is the only entry point there is.

#### Both siblings had already been here, and they disagree in writing

`nextcloud-n8n`'s delete listener says, in its docblock:

> *"The same event fires for BOTH lifecycle steps … Discriminated by path prefix."*

`nextcloud-grafana` has a whole separate class for the purge, whose docblock
says:

> *"unlike the move-to-trash step — Nextcloud does NOT fire a typed
> `BeforeNodeDeletedEvent` when a file is purged from the trash (**proven live**:
> the trashbin's `removeItem` fires nothing typed)."*

Two apps in the same family, against the same Nextcloud version, documenting
opposite behaviour. This app read n8n's — the older and more confident of the
two — and inherited the bug. Grafana's is the one that says *proven live*, and it
is the one that is right.

**The lesson is not "read both siblings", which was already the rule.** It is
that when they disagree, the tie-break is which claim carries evidence. One
docblock asserts a mechanism; the other reports an experiment. Those are not the
same kind of sentence and should never have been weighed equally.

#### What actually found it

Not review, and not a unit test — the integration scenario, on its first run.
The purge step ran, returned clean, and the assertion against Penpot's trash
failed. Every mock in the unit suite was faithfully reproducing an event that
does not occur.

That is the third time this course a mocked boundary certified a wrong
assumption (§C6.8, §C6.10, §C6.12), and the second time the fix was already
sitting in a sibling repository.

#### Two details the port carries, both learned by grafana the hard way

- The retention background job (`Files_Trashbin`'s `ExpireTrash`) runs with **no
  session user**, so the uid falls back to `\OC_User::getUser()` — the FS context
  it sets up for the user it is processing. Without that, a mirror Nextcloud
  expired on its own schedule would leak the design silently, which is the case
  nobody is watching.
- `connectHook` **appends with no de-duplication**, so a second `boot()` in one
  process stacks the handler and purges twice per file. Guarded with a static
  flag.

#### And then it still did not fire — the second half of the bug

Porting the hook did not fix the failing scenario. Three more rounds went into
that, two of them wasted on evidence about the wrong thing (a pod running code
from before the port; a `Trashbin::delete()` call missing its timestamp, which
made the method early-return *before* the emit). Both silences were the test's,
not the app's.

What finally settled it was instrumenting rather than theorising: CI integration
failures were throwing away `nextcloud.log`, and **every listener in this app
logs its failure and swallows it by design** — a remote problem must never break
a file operation — so the reason was always in a file nobody kept. Dumping it on
failure is now part of the workflow.

With the log in hand the answer was immediate: **no purge line at all**, while
the same code logged one locally. The difference is which entry point serves the
request:

```php
// remote.php — the WebDAV door
$appManager->loadApps(['authentication']);
$appManager->loadApps(['extended_authentication']);
$appManager->loadApps(['filesystem', 'logging']);
```

`register()` runs for every enabled app during bootstrap. **`boot()` runs only
when an app is LOADED**, and a DAV request loads only those types. This app
declared none, so on every DAV request `boot()` never ran.

That is why the gap was invisible for so long: everything else this course built
is wired in `register()` — copy, move, rename, create, soft delete — and all of
it works over DAV. Exactly one thing lived in `boot()`, and it was the one thing
that never fired.

`<types><filesystem/></types>` in `appinfo/info.xml` is the fix, and it is the
right one on its own terms: `filesystem` is the type for apps that must be
present when the filesystem is in play, which is what a file-event-driven mirror
is.

#### Filed against BOTH siblings

n8n has no `connectHook` anywhere in `lib/`, so its hard step has only ever had
the trigger that does not fire.

**And grafana declares no `<types>` either.** It has the hook, correctly wired in
`boot()` — and on a DAV request `boot()` does not run. Its purge almost certainly
never fires either, which would mean a dashboard parked in Grafana's bin outlives
the Nextcloud file that owned it, silently, forever. Grafana got the mechanism
right and the loading wrong; this app copied the mechanism and inherited the
second half of the bug without ever seeing the first.

Both notes are **unverified against those apps** and say so.

### C6.14 — The endpoint existed, the controller existed, and it 404ed anyway

The sync triggers were built, deployed, verified on disk — and the button
returned a red 404. Twice, on two different endpoints. Nothing was wrong with the
code.

Nextcloud caches its **route collection** in `memcache.local`, which is
`\OC\Memcache\APCu` on this instance, under the key
`"<host>#<baseUrl>#rootCollection"` with a 3600s TTL
(`lib/private/Route/CachingRouter.php::findMatchingRoute`). Two properties of
that key turn a normal dev loop into an hour-long ghost hunt:

- **The key contains no app version.** The instinct — bump `<version>` to
  invalidate — does nothing. There is no version in the key to change.
- **APCu is per-process-tree.** `php occ` gets its own segment. Clearing the
  route cache from the CLI reports success, and the web server never sees it.
  That success message is the trap: it looks like the fix landed.

So a deploy that ADDS a route leaves Apache matching against a collection that
predates it. Existing routes keep working, which is what makes it read as "my new
code is broken" rather than "the router never learned about it".

#### The probe that settled it in one shot

Unauthenticated, from inside the pod, no session needed:

```
404  POST /apps/penpot_sync/sync/pull          <-- new route
404  GET  /apps/penpot_sync/sync/status        <-- new route
404  POST /apps/penpot_sync/mappings/{id}/sync <-- new route
401  GET  /apps/penpot_sync/mappings           <-- route that predates the deploy
```

**404 vs 401 is the whole diagnosis.** A route that does not exist 404s; one that
exists and wants a session 401s. Same app, same request, same auth state — the
only variable is when the route was added. No amount of reading the controller
would have produced this; one curl did.

#### The second cache, hiding behind the first

With the 404 fixed the button then rendered *the previous build's* placeholder
text. Nextcloud stamps asset URLs `?v=<hash>-<cachebuster>`, where `<hash>`
derives from core and app **versions** — so a dev deploy that rewrites `js/` in
place produces a byte-identical URL and the browser serves the file it already
had, in front of the new PHP. `config:system:set cachebuster` is the supported
lever.

Both caches are keyed to a version that a dev deploy, by construction, never
changes. Releases bump `<version>` and are fine; only this loop is exposed, which
is exactly why it survived this long unnoticed.

#### What the header comment had promised

`deploy-dev.sh` said *"no pod restart needed"* — reasoning from opcache
(`validate_timestamps=1`, `revalidate_freq=60`). True, and true only of PHP code.
It generalised one cache's behaviour to every cache, and the two it missed do not
expire on a timer anyone would wait out.

The script now bumps `cachebuster`, recreates the pod (the only lever that clears
the web server's APCu — `apache2ctl -k restart` is refused, we run as `www-data`
and PID 1 is root-owned), and then **probes every static route in `routes.php`
and fails loudly on a 404**. It reads the routes out of the file, so it keeps
telling the truth as routes are added.

`delete pod`, not `rollout restart`: the PVC is ReadWriteOnce and the Deployment
is `maxSurge=1/maxUnavailable=0`, so a surge rollout can deadlock waiting to
mount the volume into a second pod.

#### The recurring shape, again

§C6.8, §C6.10, §C6.12 and §C6.13 all say a version of *a test that mocks the
boundary cannot fail the way the boundary fails*. This one is the same lesson one
layer out: **the unit tests, the integration tests and the files on disk were all
correct simultaneously, and the feature was still dead in the browser.** Nothing
in the repo could have caught it, because the defect lived in the deploy path.
The verification had to run where the user's click runs.

### C6.15 — The delete grew an undo, and the command it needs lies twice

Course 5's salvage yard shipped its destructive half first — delete, purge, and
the prune that trashes a mirror whose design vanished. The gesture that undoes
all of it was written up as "the next slice" and left there, with the gap stated
in `delete.feature`'s own header:

> restoring a mirror from the NEXTCLOUD trash puts the file back in the folder,
> but the design stays in Penpot's trash. The next pull will not see it named by
> Penpot and will prune the mirror again.

Nothing was lost when that happened. The file simply appeared to delete itself a
second time, a few minutes after the user had rescued it — which is its own kind
of bad, and the kind that makes someone stop trusting the app rather than file a
bug.

#### The gesture is a typed event, and its opposite number is not

§C6.13 cost an integration test to establish that a trash **purge** fires nothing
typed at all — the trashbin's `removeItem` emits a legacy `\OCP\Trashbin`
`preDelete` hook and that is the only door. The restore is not like that:
`files_trashbin` dispatches `NodeRestoredEvent` after the rename lands, carrying
source and target. Both sibling apps already listen to exactly it.

Worth stating together, because the pair is genuinely asymmetric and nothing
about the API suggests which half is which:

| Gesture | Signal |
|---|---|
| delete → trash | `BeforeNodeDeletedEvent` (typed, abortable) |
| restore ← trash | `NodeRestoredEvent` (typed, after the fact) |
| purge | **nothing typed** — legacy `\OCP\Trashbin` `preDelete` hook |

The listener reads the event's **target**, not its source. The source is the node
at its trash path, where the name carries a `.dTIMESTAMP` suffix — so a listener
that read it would fail its own `.penpot` extension check and never fire, and it
would fail *silently*, which is the failure mode this app keeps meeting. That is
pinned by a unit test whose source node is deliberately named
`Login.penpot.d1785457295`.

#### The DAV protocol for a restore is a MOVE to nowhere

The integration step had to perform a real restore, and the plausible guess —
MOVE the trash href back to the file's original path — is wrong in a way that
would have passed a weaker assertion: it copies, and leaves the trash entry
behind. Read out of the running server instead (§C6.1's rule, again):
`RestoreFolder` is an `IMoveTarget` whose `moveInto()` ignores the target name
entirely and calls `$sourceNode->restore()`. So the protocol is

    MOVE /remote.php/dav/trashbin/<user>/trash/<entry>
    Destination: /remote.php/dav/trashbin/<user>/restore/<entry>

and the destination name is decoration. That door is the one that reaches
`Trashbin::restore()`, which is what dispatches the event — a test that put the
file back any other way would never have fired the listener it was testing.

#### The command reports success in two different ways without doing the work

This is why restore was its own slice rather than three lines in the delete's.
`restore-deleted-team-files` has now been caught lying twice, in two unrelated
ways, and both were already in the record:

1. **§C6.11 — an id it does not restore gets 200 and an `end` event carrying an
   EMPTY SET.** No error, no warning. The stream succeeded and the work did not
   happen.
2. **§6.49 — the `end` event once arrived while `deleted_at` was still set.** A
   second call cleared it. This did not reproduce on 2.17.0.

So the client returns **the ids the `end` event actually carries**, and the
service compares them against what it asked for. A false success is worse than an
error, because the user stops looking.

That makes three commands in this app where **HTTP 200 settles nothing**: the
export (§5.1), the permanent delete, and now the restore. It is no longer a
gotcha about one endpoint; it is how Penpot's streaming commands work.

#### …and the second lie came back, exactly where §6.49 left it

The first draft of this slice handled lie 2 by re-reading the **trash listing**:
restore, then ask `get-team-deleted-files` whether the design had left. That
sounds equivalent to "it is back" and it is not, and the reasoning that produced
it is worth naming because it was comfortable and wrong:

> §6.49 saw this once, §C6.11 could not reproduce it, so the check is a
> formality — any cheap read will do.

The integration suite then failed on **the one scenario the whole slice exists
for** — *"a pull after a restore neither prunes the mirror nor duplicates it"* —
about half the time. Passing, failing, failing, passing across four runs of the
same code.

What the log said, once it was made to say anything (see below): the pull trashed
`Second Thoughts.penpot` and `Round Trip.penpot`, the two files those scenarios
had just restored. Each survived the pull immediately after its own restore and
was trashed by the next scenario's pull, seconds later. And the scenario's own
assertions had passed first — the design was out of the trash and, at that
moment, listed.

The mechanism is §6.49's, stated in Chapter 1 in one sentence that this slice had
read and discounted:

> **the SSE returns before the transaction settles.** A second call cleared it.

Inside that window the two listings disagree. `get-team-deleted-files` can have
stopped naming the design while `get-project-files` still omits it, because
`deleted_at` is what the second one filters on and the transaction has not
committed. So the trash listing answers "yes, it left the trash" for a design
that is, as far as every other query is concerned, still deleted.

**The oracle mattered more than the check.** Every decision in this app is made
from the project listing: the pull builds its seen-set from it and trashes any
mirror missing from it. Confirming a restore against anything else confirms
nothing that matters. The service now asks the same question the pull will ask —
*is the design back in its project's listing?* — and, when it is not, issues the
second call §6.49 prescribed and asks again. A mirror at the team root is
confirmed against that team's **Drafts** project, because Drafts is a real
project with no folder (§6.35).

Two live details that made this hard to see, both worth keeping:

- **The live instance never reproduced it.** Delete/restore/poll for 40 seconds
  on `drive.kellyferrone.com` showed the design listed continuously from 600 ms
  after the restore returned. A near-idle Penpot with five designs settles inside
  the gap; CI's, mid-suite with seventeen projects, does not. *"It works on the
  real instance"* was true and meant nothing.
- **The failing pull reported 14 files** — which is exactly the number of designs
  those scenarios leave alive, including the restored one. That count is what
  ruled out "the listing came back short" and pointed at a specific id being
  absent from a specific project.

#### Three layers, and the honest report for the one that is not built

"Restore" means genuinely different things depending on what survived (§6.52),
and the app must pick the cheapest, most lossless layer that applies:

1. the design still exists in Penpot → **Penpot is not contacted at all**;
2. it is in Penpot's trash → `restore-deleted-team-files`, lossless;
3. it is gone for good → only the local archive is left, and importing it mints a
   **new id** (§6.20, tested directly: a purged id cannot be resurrected).

Layer 3 is not built, and the interesting decision was what to do when the app
lands in it. Silence would be indistinguishable from success. Attempting the
restore command anyway would produce §C6.11's empty set and a misleading log. So
the app spends one project listing to tell layers 1 and 3 apart — the only
uncommon path, since the ordinary trash-then-restore round trip never gets there
— and says plainly that the design is gone and this mirror is now the only copy
of it.

Layer 3 stays deferred for a reason that is not phase ordering: it is the only
restore that changes a design's identity, so it cannot be a listener that fires
on a gesture. It needs a human to be told what they are trading and to say yes.
`restore.feature` is that specification, and what it is waiting for is the
confirmation surface, not the detection.

#### The prune could not say what it had done

None of the above was visible for two CI runs, and the reason is its own finding:
**the prune logged nothing on its success path.** It moves a user's files to the
trash, driven entirely by an absence — "Penpot did not name this id" — and it
reported a count to the CLI and left no record anywhere of *which* files. The
first failing run could only be described as "one mirror was pruned that should
not have been". The second, after one log line was added, named both files
immediately and turned a mystery into a mechanism.

Two things follow, and neither is about this bug:

1. **An operation that deletes on inference must name its subjects.** "Why did my
   file disappear?" is the only question anyone asks afterwards, and until now
   this app could not answer it for its most dangerous operation. The line now
   carries the path (which project folder it was in), the id, whether a final
   archive was rescued, and **how many ids Penpot named that run** — a prune
   against a plausible count is a real deletion; a prune against a suspiciously
   small one is a short listing, which is the failure `$complete` exists to catch
   and cannot always see.
2. **The integration suite ran at the default WARN level**, so info-level lines
   were dropped before the failure-path log dump could show them. Every listener
   in this app logs its outcome and swallows its failures by design (§6.18 rule
   3) — which means at WARN, a run that did the wrong thing *successfully* leaves
   no trace at all. The suite now runs at INFO. A log dump is only as useful as
   the level allows, and this one had been quietly useless since it was added.

#### The shape, one more time

§C6.14 said: the unit tests, the integration tests and the files on disk were all
correct simultaneously, and the feature was still dead in the browser. This is
its sibling and the sharper version, because here the tests *did* catch it:

**a flaky test is a finding, not a nuisance.** The instinct on a 50%-failing
scenario is to re-run it, and the first re-run passed — which is precisely the
evidence that would have shipped the bug. What made it a bug rather than a flake
was refusing to accept the green and going after what the red had named.

And underneath that, the reason the wrong check was written at all: **a
non-reproduction is not a disproof.** §C6.11 tried §6.49's race once, did not see
it, and wrote "the re-read rule stays anyway" — which was right — but the code
that followed weakened the rule to a cheaper read on the strength of the same
non-reproduction. The rule survived; the reason for it did not.

### C6.16 — The prune's promise was never asserted, and the trash it fills is not yours

A report, in the user's own words: *"I delete `My Ultra Kicker` in Penpot. I
expect Nextcloud to prune it. Confirmed I do not see it in the Nextcloud folder.
**Fail** — I do not see it in the Nextcloud trash."*

Two findings came out of it, and only one is a bug.

#### 1. It WAS in a trash. Just not theirs.

The prune log — added one course earlier, and the reason this took minutes rather
than a day — named it exactly:

```
"message":"penpot_sync pull: trashed a mirror whose design Penpot no longer lists"
"scriptName":"/var/www/html/cron.php","user":"--"
"file":"/nextcloud/files/Design Files/My Stuff/My Ultra Kicker.penpot"
"penpot_id":"86f123cb-…-68bf65e62ba7","final_archive":"true","ids_listed":"4"
```

The trash row and the blob were both there, on **storage 1**:

```
oc_files_trash    My Ultra Kicker.penpot | nextcloud | 1785513504 | /Design Files/My Stuff
oc_filecache      files_trashbin/files/My Ultra Kicker.penpot.d1785513504 | storage 1 | 2558 bytes
oc_storages       1 = object::user:nextcloud       3 = object::user:kelly
```

The mapped folder is owned by the service account, and **the pull runs as its
owner**, so the mirror went to the owner's trash. The human looking at their own
Files app is a *member* of that share and sees nothing.

This is not something the app invents — it is how Nextcloud handles every shared
file: the owner's delete fills the owner's trash. Which makes it a documentation
problem rather than a bug to engineer around, because working around it means
second-guessing Nextcloud's sharing model on the most destructive path in the
app. The README now says whose trash to look in, and offers the reliable escape:
**move a file out of the mapped folder** and no prune can ever reach it.

Also visible in that dump, and worth keeping: the 2558 bytes are the final
snapshot. The rescue worked. The user's design was never at risk — only findable
in the wrong place.

#### 2. "Trash, never destroy" was a comment for three courses

`prune.feature`'s header said it. `reconcile.feature` said it. Neither asserted
it. Every scenario checked **"there is no node at that path"** — which a hard
delete satisfies exactly as well as a trash does.

So the app's single most destructive operation had its central safety property
verified by prose. It happened to be true; nothing would have caught it becoming
false. The scenarios now assert the file is *in* the trash, including a new one
where Penpot has **permanently** deleted the design — reached in CI by calling
`permanently-delete-team-files`, which puts Penpot in the same state a seven-day-
old deletion does without waiting a week.

Same shape as §C6.14 and §C6.15, at a different layer: a green suite that was
green about the wrong thing.

#### 3. The rule that has no exception, and why the tidy symmetry is wrong

The user asked the right question directly: should a design *purged* in Penpot be
purged in Nextcloud too, mirroring the trash flow one-to-one? And answered it —

> we could have a rule that states nextcloud never auto purges a file from trash
> because it's no longer in penpot

That is the rule, it is now written down, and the argument for it is stronger
than symmetry. **The two trashes expire on schedules neither side controls.**
Penpot's is ~7 days and not configurable; a Nextcloud instance may keep 30. Mirror
the purge and every design that ages out of Penpot's trash silently takes the
user's last copy with it — precisely when the mirror has become the only copy
that exists. The gesture that empties the Nextcloud trash is the user's, and the
pull has no business reaching into it.

It also collapses a branch out of restore, which is the second reason to like it:
a mirror in the Nextcloud trash is always restorable *locally*, whatever Penpot
did. Whether Penpot can match the restore is then a separate question with three
honest answers (§C6.15's layers) rather than a precondition.

The code already behaved this way — `prune()` has only ever called `$node->delete()`,
which trashes. What was missing was any statement that this is a **rule** rather
than an implementation detail someone could later "fix" into symmetry.

#### 4. A latent trap in the test harness, found by the new scenario

The purge scenario needed `permanently-delete-team-files`, whose payload is a
**set of ids** — the first list-valued param the seed channel had ever sent. Both
RPC helpers encoded with `JSON_FORCE_OBJECT` unconditionally:

```php
json_encode($params, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)
```

That flag is there for §R1.3 — Penpot 500s on `[]` where it wants `{}` — but it
rewrites **every nested list too**, so `ids: ["<uuid>"]` would have gone out as
`{"0":"<uuid>"}` and failed validation. A non-empty PHP map already encodes as a
JSON object with no help at all, so the flag was only ever needed for the empty
case. Fixed to apply only there.

Worth noting how it was found: not by reading, but by asking what the wire would
actually carry before sending it — the same habit §C6.7 records failing to apply
to route parameters, which shipped broken instead.

#### 5. A stale claim, retired

`reconcile.feature` had been asserting, in a comment, that *"we cannot DRIVE
Penpot's trash — no API command restores a file"*. `restore-deleted-team-files`
exists, was confirmed live in §6.52, was called in §C6.11, and is the backbone of
§C6.15's slice. The sentence predates the discovery of Penpot's trash and
survived every rewrite around it, because comments are not executable and nothing
was checking.

Its neighbour claimed the trash-aware reconciler "already does this". It does
not — reading `files_trashbin` during a pull is still unbuilt, so a design
restored in Penpot's own UI today gets a second mirror beside the trashed one.
Both corrected. The lesson is not new but it keeps arriving in new clothes: **a
feature file's prose ages exactly like a code comment, and neither is tested.**

#### 6. The reconciler's field of view, stated at last

The sharpest framing came from the user, and it retires a question rather than
answering it:

> we only spoke of the reconcile seeing a penpot file went to penpot trash or
> it's gone and the state in nextcloud was the file is visible and active in a
> folder. the other state is when nextcloud put it in the trash, both penpot and
> nextcloud have it in the trash, so if penpot purges, the reconciler maybe
> shouldn't care about the nextcloud trash and only focus on visible files which
> need pruning.

That is exactly what `collectMirrors()` has always done — it walks the mapped
folder's directory listing, which does not contain trashed files. A trashed
mirror is not *spared* by a check; it is **never seen**. But nothing said so, and
the difference between "we decided not to" and "we happen not to" is the
difference between a rule and an accident waiting to be refactored.

Written down, a whole class of question stops existing:

| Question | Answer once the rule is stated |
|---|---|
| Both trashes hold it, then Penpot purges — now what? | Nothing. The pull was never looking. |
| Do the two trashes need reconciling against each other? | There is no such comparison anywhere in this app. |
| Can a Penpot-side expiry take a user's last copy? | No. Nothing reaches into the Nextcloud trash. |

**The price, named rather than hidden:** a design that comes back in Penpot while
its old mirror sits in the Nextcloud trash gets a *new* mirror beside the trashed
one, because the pull cannot re-adopt what it cannot see. §6.37 wanted the
opposite — read the trash, match by `penpot_id`, adopt.

What makes that a fork rather than a bug is that the restore slice removed the
ordinary route into the state. Deleting a mirror deletes the design in Penpot, so
the pull stops naming it and creates nothing; restoring the mirror restores the
design with it (§C6.15). What remains are odd cases — the delete never reached
Penpot, or a human restored the design in Penpot's own UI — where *"the design
exists, so a visible mirror should exist"* is a defensible answer. `reconcile.feature`
now carries both readings and claims neither, which is the honest state.

The general lesson is the one this chapter keeps circling from new directions:
**the strongest simplification available is usually a scope boundary, not a
smarter algorithm.** Two trashes with independent retention policies is a genuinely
hard reconciliation problem. Deciding that one of them is simply not ours to look
at makes it disappear.

#### 7. `permanently-delete-team-files` returns before the data is gone

The new purge scenario asserted that no final archive could be saved for a
permanently-deleted design — the obvious consequence of §6.42's grace window
closing. Penpot disagreed, live:

```
And the design "No Way Back" is permanently deleted in Penpot
And the admin runs a pull
  → 1 design(s) no longer exist in Penpot. Their mirrors were moved to the
    Nextcloud trash: 1 saved as a final archive first, 0 could not be recovered.
```

`export-binfile` **still exported it**, seconds after the destroy command
returned 200 with an `end` event. Chapter 1 §2229 had the explanation waiting:
Penpot's deletion is *"soft — and scheduled"*, rows marked and removed later by a
worker. The permanent delete is no different in kind from the soft one; it just
schedules the removal instead of the expiry.

Two consequences worth keeping:

- **A destroy command that returns is not a destroy that happened.** This is the
  fourth Penpot command where the reply describes an intention rather than an
  outcome, after export, restore and the trash listing. The pattern is now
  reliable enough to assume: *ask again if it matters.*
- **The scenario stopped asserting it.** Whether the snapshot lands is Penpot's
  worker timing, not this app's behaviour, and a test that asserts the other
  side's scheduling is a test that fails on a busy afternoon. It asserts where
  the mirror ends up, which is the thing the feature is actually about.

#### 8. An error is not an empty result

The same run failed a second scenario with `expected a design named 'Round Trip'
in Penpot project 'Stay Put'; found: (none)` — which reads exactly like a restore
that silently did not work, and sent this investigation straight back to §C6.15's
territory.

It was not. The restore had logged success, the pull had listed the design and
pruned nothing. What failed was the **probe**, for one project, transiently:
`occ penpot_sync:probe --files` catches a per-project listing error, prints
`<error>…</error>` where the files would be, and exits 0. The step's parser
looked for lines containing `revn=`, found none, and reported an empty project.

A listing failure and an empty listing are not the same fact, and a test that
collapses them will send its reader to the wrong place every time. The step now
raises the error line as itself.

That makes three findings in this section that are all the same shape one level
apart: the prune reported a count and not its subjects (§C6.16.2); the restore
confirmed against a listing that could not answer the question (§C6.15); and the
harness turned a failure into an absence. **Every one of them was a system
answering a question that had not been asked.**

### C6.17 — Who performs a copy, and why the answer was never really ours

A copy in Nextcloud could reach Penpot two ways, and they are not equivalent:

1. **Nextcloud copies, then creates.** Duplicate the local file, then push its
   bytes up as a new design — `import-binfile` with the archive we hold.
2. **Penpot duplicates, then we mirror.** Call `duplicate-file` and let the new
   design come down on the next pull.

The app does **(2)**, and `CopyService` has always done (2). What is worth
recording is that this was never a free choice — three separate facts force it,
and each one alone would be enough.

#### A `link` has no bytes to copy

This is the decisive one. A `link` mirror is **zero bytes** (C6.6): its whole
identity is metadata, and the design lives only in Penpot. Under strategy (1) a
`link` is simply uncopyable — there is nothing to upload. The app would have to
either refuse to copy the majority mode (`link` is the default), or silently
promote it to `sync`, downloading an entire archive as a side effect of a
drag.

Under (2) the mode does not enter into it. Penpot duplicates server-side and
**no bytes travel at all** — a `link` copies exactly as completely as a `sync`,
which is why `copy.feature` can state that without qualification.

#### Import cannot preserve what a duplicate keeps

§6.41 measured a real export→import round trip. What comes back: name, pages,
shapes, assets, even the revision number. What does not: **the file id and the
edit history** (0 `file_change` rows against the original's 5). §6.20 is
harder still — a chosen id is not accepted at all.

So strategy (1) does not produce a copy of the design; it produces a
*reconstruction* of the artwork, missing the history, at an id we cannot
choose. `duplicate-file` is a server-side operation on Penpot's own storage and
keeps what its own duplication keeps. We could not match it from outside if we
tried.

#### The archive is a snapshot, not the design

Worth stating separately because it is easy to forget once `sync` mode exists:
a `.penpot` archive is what the design looked like **at export time**. Copying
from it copies a moment. `duplicate-file` copies the design as it is now,
including anything changed since the mirror was last refreshed.

#### What (2) costs, and the one wrinkle

`duplicate-file` has **no project parameter** (proven live, §C6.8) — the
duplicate lands beside its source. So a copy into a different folder is two
calls: `duplicate-file`, then `move-files` to re-file it. That is the entire
price, and it is the same `move-files` the drag path already uses.

The boundary is the other half: a file with no `penpot_id` has nothing to
duplicate, and a destination with no mapped ancestor has nowhere to put one.
Both are checked, and a copy that fails either is ordinary Nextcloud content
being copied — which is now a live scenario rather than an assumption.

#### The shape

This chapter keeps finding that the strongest design constraints come from the
other system's facts rather than our preferences (§C6.16's scope boundary,
§C6.15's oracle). Here it is at its clearest: the copy strategy looks like an
architectural choice, and there was never a version of this app where (1) was
viable. **The design that survives contact is usually the one the other
system's limits already picked.**

---

### C6.18 — A folder becomes a project, and the one marker that means both

Two live reports opened this. A project made in Penpot did not seem to arrive in
Nextcloud (it had — on the next five-minute pull), and there was no way to look
at a folder and know it was a Penpot project. Out of the second came a feature
request in one sentence: *every folder that maps to a Penpot project is tagged
`penpot`, and adding that tag to a Nextcloud folder creates the project in
Penpot.*

That sentence contains a design the app had been circling for two courses.

#### The asymmetry is the point

    every Penpot project      →  a folder in Nextcloud     (automatic)
    SOME Nextcloud folders    →  a project in Penpot       (opt-in only)

Inbound is total: the pull mirrors every project it can see, because a project
existing in Penpot is unambiguous evidence that someone wanted it. Outbound is
opt-in, because a folder existing in Nextcloud is evidence of nothing at all. A
mapped folder that turned every subfolder into a Penpot project would be
unusable for the ordinary things folders are for — notes, exports, a subfolder
of references — and this app has refused inference at every equivalent fork
(§6.33 on creation, §C6.4 on the drag-in).

So the two directions are not symmetrical, and trying to make them symmetrical
would have broken the more important one.

#### One tag, two jobs — which is what makes it good

The tag is the app's **marker** and the user's **opt-in**, and the temptation
was to separate them: `penpot:project` written by the app, `penpot:make-project`
assigned by the user. That is worse, and the reason is a question a user should
never have to answer: *did this folder start life in Penpot, or did someone opt
it in from Nextcloud?* With two markers you have to know before you can read the
folder. With one you do not — if it carries the tag, it is a project.

The cost is a small idempotency obligation on the pull, which restamps the tag
on every run. That turns out to be a feature: a folder whose tag someone removed
gets it back on the next pull, because the thing that actually decides —
`penpot_project_id` — never went anywhere. There is no repair path to write,
because there is no broken state to repair.

#### Not subscribing is a stronger guarantee than subscribing

`TagUnassignedEvent` exists. The app does not listen to it.

The scenario says *removing the tag does not delete the project*, and the
obvious implementation is a listener that receives the event and returns early.
That is a branch, and a branch is somewhere a later change can add an `else`.
Declining to register the listener at all makes "Penpot is never contacted" true
by construction — there is no code path to audit, and the scenario has nothing
to assert against because nothing could possibly happen.

Untagging is unmapping, not deleting: the same rule as moving a design out of a
mapping (§6.23) and as deleting a project folder. Destroying someone's project,
with every design in it, because they took a label off a folder would be the
worst surprise this app could produce.

#### Late opt-in is the whole reason to do it this way

The interesting half is what happens to a folder someone has been *filling*
before they tag it. It becomes a project **with its contents** — one
`move-files` for the lot, non-destructive and reversible (§6.27/§6.34), nothing
exported or re-id'd. Verified live: a design created in the folder went to the
team's Drafts, and tagging the folder re-filed it into the new project.

That is the reason to allow opting in late rather than forcing the decision up
front, and it is only possible because Penpot's re-file is cheap and lossless.
Another case of the other system's facts choosing the design (§C6.17).

The descent that collects those designs reads the tree the way
{@see MembershipResolver} reads it upwards, stopping at any subfolder carrying
its own project id. The two have to agree or the re-file would claim designs the
resolver still attributes elsewhere — the same class of bug as §C6.10, where a
unit test passed against a shape the resolver never emits.

#### The floor moved, and saying so is the point

`OCP\SystemTag\TagAssignedEvent` is `@since 32.0.0`. `appinfo/info.xml` declared
`min-version="30"`, inherited from the siblings — one of which uses the same
event under the same false floor.

On 30 or 31 nothing would crash. `registerEventListener(TagAssignedEvent::class, …)`
resolves a class *constant*, which does not autoload, and the listener is only
instantiated when the event fires — which it never would. The tag would land on
the folder, nothing would happen, and nothing would say why. A **silently absent
feature** is the exact failure mode this chapter keeps having to dig out of live
reports (§C6.15's oracle, §C6.16's unasserted promise). The floor is now 32.

#### The occ channel has no session, and that changed the design

The browser gesture carries a user; `occ tag:files:add` fires the identical
event with none. A listener written against `IUserSession` alone would pass
every unit test and do nothing from the CLI.

So the resolution falls back to the sync actor's home — the same uid
`penpot_sync:status` reads through, which is by definition where the mapped
folders live. The attribution follows honestly: with no session there is no
personal token, and the project is created by the service account, which is
exactly who asked for it.

That fallback is what made the feature testable at all. Assigning a tag over
WebDAV means a PROPPATCH against `systemtags-relations` and a round trip to find
the tag's numeric id; `occ` fires the same event through the same
`ISystemTagObjectMapper::assignTags()`. The cheaper channel was also the one that
proved the harder case.

#### Reading the tag back: ask core, not the app

The assertion could not go through this app. The claim under test is that
`penpot` is a **real, user-visible Nextcloud tag** — the same object the Files
sidebar shows and a user can assign by hand — not a private marker the app draws
for itself, and asking the app would only prove the app agrees with the app.

`occ info:file` looked like the answer and prints no tags at all (checked live
on 33.0.4). The DAV property `{http://nextcloud.org/ns}system-tags` does, and it
is the very one the sidebar reads.

#### The file that owned too much

Writing the scenarios exposed something older. `project-folder.feature` owned
every verb a project folder could be on the receiving end of: renaming one,
copying one, moving one, deleting one. That is the mistake `gestures.feature`
made in the other direction — organising by the KIND OF THING acted on rather
than by the BEHAVIOUR — and it had already cost the same thing. `rename.feature`
carried **live** coverage of the project-folder rename while `project-folder.feature`
still called it unbuilt. `move.feature` had all four project-folder move
scenarios. `copy.feature` had the refusal plus a comment pointing back here for
reasoning it could simply have stated.

Two files, one behaviour, already drifted — and the drift was invisible because
nobody reads two files to answer one question.

A project folder is not a separate universe. Renaming one is a RENAME. What is
left in `project-folder.feature` is the one thing no behaviour file can own: a
folder's **identity** as a project — how it acquires one, and the marker that
says so.

Its Background also turned out to be fiction: it provisioned a Team Folder and
mirrored a project called "My Stuff", and none of those three steps had ever
existed. Harmless while the whole file was `@todo`; an instant `--strict`
failure the moment one scenario went live. **A Background under an all-`@todo`
file is unexecuted code, and unexecuted code is not known to work** — the same
lesson as §C6.14's floating tag, arriving from the other end.

---

### C6.19 — What Penpot does when you delete a project, and two things nobody had measured

Two questions arrived together, and both turned out to be answerable only by
doing it: *should deleting a tagged folder delete the Penpot project?* and *do
the mirrors carry Nextcloud's ordinary file dates?* The first needed Penpot's
source and a live probe; the second needed a stopwatch on two apps.

#### The rule that was proposed, and why it did not apply

The proposal was careful: *if Penpot cannot delete a project that still has
files in it, then neither should we.* That is exactly the right instinct — this
chapter keeps finding that the other system's limits pick the design (§C6.17) —
and it turns out not to bind, because Penpot has no such limit.

`delete-project {id}` answers **204** and is entirely soft. It sets
`project.deleted_at` to a timestamp *in the future* — `now + deletion-delay`,
7 days by default, longer on paid tiers — and submits a `delete-object` worker
task that cascades the **same** future timestamp onto every file in the project,
plus their changes, data fragments, media objects and thumbnails.

So there is no emptiness precondition to mirror, no refusal to inherit, and the
grace window lines up with a Nextcloud trash almost exactly. Proven live against
a project holding two designs:

    delete-project                          → HTTP 204
    get-all-projects                        → the project is gone IMMEDIATELY
    get-team-deleted-files                  → both designs are listed IMMEDIATELY
    get-project-files (on the dead project) → STILL RETURNS THEM

That third line is the one to remember. The trash query matches on
`p.deleted_at > now` **OR** `f.deleted_at > now`, so the project's own mark is
enough — the designs show as trashed before the worker has touched them. And the
fourth line is a trap: `get-project-files` does not filter on the project's
deletion at all, so anything holding a stale project id gets a confident, wrong
answer. This app is safe by accident of an earlier decision — §6.42 made the pull
use `get-all-projects`, which filters `deleted_at is null`, so a deleted project
simply stops appearing and is never queried.

One project refuses: a team's **default (Drafts)** answers
`:non-deletable-project`. It has no folder of its own in `nested` mode — it *is*
the team root (§6.35) — so this app cannot reach it by the folder gesture, but
the guard is worth stating so a future folder mode cannot back into it.

#### Restore is not delete run backwards

There is **no `restore-project` RPC**. `projects.clj` offers create, rename,
delete and pin, and nothing else. A project returns only as a *side effect* of
restoring one of its files: `restore-deleted-team-files` collects the
`project-id` of every file it restores and clears `deleted_at` on those projects
too.

Measured, because it is surprising enough to be worth measuring. Deleting a
project holding **Alpha** and **Beta**, then restoring only Alpha:

    the project  → back, listed by get-all-projects again
    Alpha        → back in the project
    Beta         → still in the trash

Delete cascades; restore does not. So "restore the project folder" has to mean
"restore every design that was in it, in one call" — not because one call is
tidier, but because a per-file loop that failed halfway would leave a project
holding some of its designs and no signal that anything went wrong.

And a project deleted while **empty** has no file to carry it back. It cannot be
restored through the API at all; it expires. That is a real end state the app has
to be able to explain, not a case to leave undefined.

#### What actually happens today: nothing, twice over

Deleting a project folder in Nextcloud reaches Penpot **not at all**. Verified
live: the project survived, its design survived, and the folder came back on the
next pull — which reads as the app undoing the user's deletion.

Two independent reasons stack, and the second is the one that would have bitten
a quick fix:

1. `DeleteListener` returns unless the node is a `File`.
2. **Nextcloud fires `BeforeNodeDeletedEvent` for the FOLDER ONLY.** There is no
   per-child event. So removing (1) would still not reach the designs inside —
   a recursive walk is something this app must do *itself*, before the node is
   gone.

Worth writing down next to §C6.13's finding that the trash purge fires nothing
typed at all: the Nextcloud event surface is not uniform, and the shape of each
gesture has to be checked rather than assumed from the one next to it.

#### The orphan the probe found on the way past

Deleting the project upstream and then pulling did the file half correctly —
both mirrors pruned to the Nextcloud trash, each with a final rescue archive
(§C6.16). But the **folder survived**, still stamped with a `penpot_project_id`
that no longer resolves and still wearing the `penpot` tag (§C6.18).

Anything dropped into that folder afterwards resolves to a project Penpot will
refuse. And the pull cannot fix it by deleting the folder, because
`get-all-projects` gives it no way to tell "deleted upstream" from "never
existed" — it must not delete a folder on the strength of an absence. Un-stamping
it, and taking the tag off, turns it back into an ordinary folder, which is the
truthful end state and the only one reachable from what the pull knows.

#### The second question: mtime is a protocol, not decoration

*Do the normal Nextcloud metadata props get set?* Two separate answers.

**Does an unchanged pull churn them?** No — measured. Two consecutive pulls over
an untouched instance left mtime and etag byte-identical. That is not luck; it
rests on two guards that were each added for their own reason and turn out to be
load-bearing together: `storeLink()` returns early on an already-empty file
(§C6.6), and `driftedOrMissing()` gates the archive write on the revision signal.

The sibling was measured the same way, and the suspicion about it was right:
`nextcloud-n8n` calls `putContent()` on every workflow on every run,
unconditionally, and a pull with nothing changed upstream moved both mtime and
etag (1:34:03 → 1:34:54, new etag). That is a bug, and its blast radius is larger
than it looks — mtime and etag *are* how every desktop and mobile client decides
what to re-download, so rewriting a byte-identical file broadcasts "this changed"
to every device the user owns.

**Are Penpot's own dates mapped onto the node?** No. `get-project-files` returns
`created-at` and `modified-at` for every design, and the pull already reads
`modified-at` — it folds it into the opaque drift signal (§5.5) and discards the
value. So a mapped folder sorted by "Modified" in Files sorts by *sync activity*,
and a `link` design untouched for a year shows the timestamp of the pull that
created it.

That is a real gap, and the fix has a trap in it that the measurement above is
exactly what protects against: setting mtime from Penpot must not become writing
mtime on every pull, or this app acquires the sibling's bug while appearing to
fix a different one. The scenario forbidding it is written before the code.

#### The shape

Three findings, one form. Penpot's project delete cascades but its restore does
not; Nextcloud's delete event fires for the folder but not its children; the
timestamps look symmetrical and are not. **In every case the asymmetry was
invisible from the API's shape and obvious the moment it was run once.**

---

### C6.20 — The two halves of one question, one of which had never been asked

Promoting the `@todo` scenarios that were already built turned up a defect on
the first run. Not in the new tests — in the pull, and it had been there since
the prune slice shipped.

#### The asymmetry

The pull asks "where is the mirror for this design?" in **two** places, and they
disagreed about how hard to look:

    collectMirrors()        the PRUNE's half     — walked the whole tree
    indexFilesByPenpotId()  the UPSERT's half    — read direct children only

`move.feature` explicitly allows a user to file a mirror into a plain subfolder;
§6.29 makes that meaningless to Penpot, which has no concept of subfolders. So
the moment anyone does it, the upsert cannot see the file, and creates a
**second** mirror for the same design at the canonical path.

Then nothing cleans up. The prune walks recursively, finds the id, sees Penpot
still lists it, and correctly prunes nothing. Two files, one design, forever, no
complaint anywhere — which is precisely the state `copy.feature`'s "exactly one
file per design id, always" exists to forbid.

#### Why nothing caught it

Three separate scenarios describe behaviour that this breaks:

* *Moving a file into a plain subfolder of its project keeps its project*
* *A file nested deeper inside a project folder still belongs to that project*
* *A pull never relocates a file the user filed into a subfolder*

The first two pass **without a pull afterwards** — the move is correct, and the
resolver is correct; the bug only appears on the next sync. The third is the one
that runs a pull, and it was the one still `@todo`.

So the defect sat behind exactly the scenario that had not been promoted, while
its two neighbours went green and looked like coverage. **A gap in the test suite
is not randomly placed: it is precisely where nobody looked, which is precisely
where the bug is.**

#### The fix is a rule the app already had, applied a third time

The descent stops at any subfolder carrying its own `penpot_project_id` — those
files have a nearer project ancestor and belong to that project. That is
{@see MembershipResolver} read downwards, and it is character-for-character the
rule `ProjectFolderService::managedDesignsBelow()` already used to collect
designs when a folder is opted in by tag (§C6.18).

Three call sites now walk the tree the same way. They *have* to agree: any one
of them reading the tree differently claims files another attributes elsewhere,
and the symptom is a silent duplicate rather than an error.

Worth noting how the third one arrived. Writing `managedDesignsBelow()` for the
tag opt-in meant thinking carefully about where a downward walk should stop —
and that reasoning was sitting in the codebase, correct and documented, while
the pull's own index a few hundred lines away still read one level. The rule was
known; it just had not been applied everywhere it was already needed.

#### The shape

§C6.19 ended on asymmetries invisible from an API's shape and obvious on the
first run. This is the same lesson turned inward: the asymmetry was between two
methods **in the same class**, both answering the same question, and it survived
because the cheap scenarios around it passed. The suite did not lie — it simply
never asked.

---

### C6.21 — A token is a mapping, and the option not to build

Two rules arrived together, and the second turns out to be the first one's
consequence rather than a separate feature.

#### Nextcloud cannot make a design; it can only ask for one

A `.penpot` is a Penpot artefact. Nextcloud can write an empty file with that
extension and nothing more. So "+ New → Penpot design" was never a local create
— it is a **request**, and a request needs somewhere to go. Penpot has no
rootless design (§C6.11: `create-file` requires a project), so "somewhere" means
a resolvable Penpot home:

    inside a project folder    →  that project
    under a mapped team        →  that team's Drafts        (§6.35)
    anywhere else              →  NOTHING HAPPENS

The last line is the rule, and the important thing about it is that it is a
**refusal to guess, not an error**. A `.penpot` outside every mapping is an
ordinary inert file: the user made a file, it is theirs, and it simply is not a
design. Inventing a team to file it into would be worse than doing nothing, and
erroring would make a mapped folder unusable for the ordinary things folders are
for — the same rule the tag opt-in rests on (§C6.18).

The code already behaved this way. What was missing was saying so as a rule, and
a live scenario, rather than leaving it as an implementation detail three
different features happened to depend on.

#### The consequence: a user's own home has nowhere to put a design

Which immediately raises the case the rule handles badly. A user makes a
`.penpot` in their own home folder — the most natural place for personal work —
and it is inert, because the home root has no team ancestor. That is correct by
the rule and wrong by intent.

The fix is not an exception to the rule. It is to give the home root a marker.

#### Setting a personal token IS creating a mapping

    admin maps a team    →  a folder, listed in the admin panel, visible
    user sets a token    →  their home root, IMPLICIT and invisible

Nothing for the user to see, decide, or name. The mapping exists exactly because
the token does, and it goes away when the token does. **Showing it would be
offering a choice that has only one possible answer** — a personal team can only
map to the one home that can reach it, because §6.12 established no Penpot
credential ever gets an instance-wide view and a personal team is precisely the
space nobody else is in.

Framing it as a *mapping* rather than as a *pull* is what makes the rest fall
out for free. §6.31 already had personal projects mounting as folders at the home
root; that gave those folders a project id but left the root itself bare. Once
the root carries a team id, every other feature works unchanged:

* a design made at the home root → the personal team's Drafts (§6.35, same rule);
* a design made in a plain folder in the home → also Drafts (nearest-ancestor,
  §6.29, same walk);
* a drag between the home and a mapped Team Folder → an ordinary cross-team move.

No new rules. One new marker, on a root that never had one.

#### Crossing between two mappings is not a new mechanism either

A user's home and a mapped Team Folder are two mappings to two different teams,
so a drag between them is a real cross-team move — and Penpot supports that
directly: `move-files` carries the destination's team with it, proven live in
§6.27/§6.34. One call does both hops.

So the scenarios went to `move.feature` and `copy.feature`, not to
`personal-projects.feature`. That is the §C6.18 lesson applied before it could
cost anything: a move is a move whatever its two ends are, and filing it under
the *kind of thing* being moved is how one behaviour ends up described in two
places and drifting. `personal-projects.feature` keeps only the fact that makes
the crossing possible — the root has a team ancestor because a token was set.

#### The option NOT taken, and why it is recorded rather than built

There is an obvious next want: **an admin switch on a team mapping that forbids
moving designs out of its Team Folder.** A shared team's work leaving for
somebody's personal space is a real concern, and the switch would be three lines
of guard.

It is deliberately not specified, and the reason is worth writing down because
"three lines of guard" is exactly how the estimate goes wrong. The switch has to
answer, at minimum:

* **What does it do to a move that already happened?** The guard fires on
  `BeforeNodeRenamedEvent` and can abort — but a user with the file open on a
  desktop client gets a sync error, not an explanation.
* **Does it forbid COPY too?** A copy out is the same data leaving. If yes, the
  one gesture that is genuinely non-destructive becomes the one that is refused;
  if no, the switch does not do what its name says.
* **What about the pull?** A design moved to another team *in Penpot* leaves the
  mapping (move.feature). The switch cannot forbid that — it has no authority
  over Penpot — so the same outcome is blocked from one side and not the other.
* **Whose rule wins when a folder carries two mappings?** On this cluster a
  folder can also be n8n's and Grafana's (§6.40). A per-app move ban is a
  per-app answer to a shared question.
* **Does it apply to admins?** A switch nobody can override is a support ticket;
  one everybody can override is a suggestion.

None of those is hard on its own. Together they are a feature, not a flag — and
the failure mode of shipping it as a flag is that the answers get chosen
implicitly, one bug report at a time.

So the current behaviour is the simple one, stated plainly: **moves and copies
work in both directions and mirror to Penpot.** The user moved a design, so the
design moved. If the ban is wanted later, this section is the list of questions
it has to answer first.

#### The shape

§C6.20 was one rule that had not been applied everywhere it was needed. This is
the opposite and rarer thing: a *new* capability that needed **no new rules at
all** — just a marker on a root, after which four existing rules produced the
whole feature. Worth noticing which kind you have, because the two are worked
very differently.

---

### C6.22 — Who did this? Reads, writes, and the job that has no answer

A question worth grounding rather than answering by instinct: when does this app
act as the user, and when as the service account? The rule turns out to be one
line, and most of the apparent complexity comes from conflating two different
kinds of statement.

    READS are always the service account.
    WRITES attribute to the acting user when there is one.

#### The reads half is a requirement, not a default

Penpot has no admin scope. Every token sees exactly the teams its account
belongs to (§6.12) — that is not a permission setting, it is the shape of the
API. So the puller has to be an account that is a member of every mapped team,
and that is the entire reason a service account exists at all.

Which means using a personal token to read would not merely change a name in a
history log. **It would change what is mirrored, per user** — two people with
different Penpot memberships would produce two different folder trees for the
same mapping. That is §6.16's rejected dual-pull-path and §6.18's
shared-Team-Folder race, arriving by a different door.

#### The writes half is safe precisely because it cannot widen anything

A write always targets something the service account already mirrored. So
swapping in the user's token changes who Penpot records as having done it and
nothing else — no new visibility, no new reachable object. That is what makes it
safe to be best-effort, and why `PersonalTokenService` returns `null` rather than
throwing: attribution is a garnish on an action that must happen either way.

#### "Can a move in a team folder be attributed to a user?" — yes, already

This was the sharp version of the question, and the answer is better than
expected: **every gesture already runs inside the acting user's own HTTP
request.** Rename, move, copy, create, delete, restore and tag are all driven by
Files events during a WebDAV or web-UI call, so `IUserSession` has the user and
`tokenForActor()` finds their token with no extra machinery.

There is no gap here. The gap people expect — "background work loses the user" —
is real, but the app does almost none of its writing in the background.

#### The pull has no acting user because nobody performed it

This is worth stating as a fact about the *work* rather than about the harness. A
scheduled pull reconciles what Penpot already says, on a timer. There is no user
to attribute it to; picking one would be an invented answer to a question that
has none, and attributing forty follow-renames to whoever happened to click
"Sync" would make Penpot's history actively misleading.

So the service account is the honest answer, not the fallback.

#### But a background job *can* act as a user, and it is worth knowing how

Because the interesting future question is not "can we?" — it is "when should
we?". Nextcloud's answer is `IUserSession::setVolatileActiveUser(?IUser)`,
`@since 29.0.0`: *"Temporarily sets the active user for this session without
persisting it in the session storage."*

Core uses it exactly this way, and its own comment is the useful part:

    // Set an active user so that event listeners can correctly work
    $this->session->setVolatileActiveUser($user);
    $folder = $this->rootFolder->getUserFolder($user->getUID());
    …
    $this->setupManager->tearDown();   // per user, or the FS cache grows

(`apps/files/lib/BackgroundJob/SanitizeFilenames.php`, verified in the live 33.0.4
tree.) So a `QueuedJob` carrying a uid in its arguments can set the volatile user
and everything downstream — including `tokenForActor()` — sees them.

**When this app would want it:** a gesture that fans out. Dragging forty designs
between projects is forty Penpot calls inside one request; queueing them with the
uid would keep the request fast *and* keep the attribution. Nothing today needs
it, and building the machinery before a gesture needs it would be scaffolding.
Recorded so the answer is ready when one does.

#### The finding: the promised fallback does not exist

Chasing the rule turned up a gap between a docblock and its code.
`PersonalTokenService` states that every caller "is expected to fall back to the
service account and carry on — the user's rename must still happen, just
attributed less precisely."

The fallback that exists is **pre-flight**: `$token = $actorToken ?? $this->getToken()`.
No token, service account. There is no **post-failure** retry. If Penpot rejects
the user's token, the write is simply lost.

And rejection is not exotic. A Nextcloud user need not have a Penpot account at
all; if they do, they need not be a member of the Penpot team behind a shared
Team Folder — the mapping only ever required the *service account* to be a
member (§6.18). "The acting user's token cannot write here" is therefore an
**ordinary state**, arguably the common one on any instance where Penpot
membership is not managed alongside Nextcloud's.

The trade is not close: losing a user's rename to protect the accuracy of a
history entry is wrong in every case. But the retry has to be narrow —
authorisation failures only. Retrying a *timeout* as the service account would
double every outage and could apply a write twice.

Three scenarios now specify it (`admin-connection.feature`), including the one
that keeps it honest: report the degradation **once**, not on every gesture. A
per-gesture warning for a routine state is how people learn to ignore warnings.

#### The shape

§C6.21 was a capability that needed no new rules. This is the inverse and the
more common trap: a rule that was already **written down as though it were
implemented**. The docblock was not aspirational when it was written — it
described what callers should do — and then every caller did the easy half. A
promise in prose with no test is indistinguishable from a promise kept, right up
until someone's token expires.

---

### C6.24 — The clock the mirror was never wearing

Both siblings shipped this first, which is unusual — the pattern all chapter has
been us finding the trap and them porting the fix. Here it was the reverse, and the
reason is instructive: **we had already written the spec for it and left it
`@unbuilt`**, complete with the warning that made the siblings' work safe.

> *a naive implementation writes the timestamp every run, which is exactly the churn
> `reconcile.feature` forbids — and which the sibling app demonstrably has.*

That note (in `file-type.feature`) was right about the danger, right about the
sibling, and the thing that stopped n8n reintroducing churn through the front door.
It just never got built here.

#### What was wrong

A mirror carried Nextcloud's clocks — *when the app wrote this node* — which is never
the question a person sorting a folder of designs by date is asking. Worse for us
than for either sibling: a `link` is zero bytes and is **never rewritten after
birth** (§C6.6), so its date froze at whenever we first happened to see the design.

#### What it cost: nothing, twice

Both records already carry both clocks, and both listings already run every pull:

| | carries | extra calls |
|---|---|---|
| file (`get-project-files`) | `created-at`, `modified-at` | **none** — `upsertMirrorFile` already reads `modified-at` into the drift signal and throws the value away |
| project (`get-projects`) | `created-at`, `modified-at` | **none** — `getAllProjects()` already runs |

No `link`-mode dilemma like Grafana's, where the pointer listing carries no
timestamps and dating a link costs a request each. Ours were free on both sides.

#### The format trap that would have shipped silently

The siblings' sources send ISO-8601. **Penpot sends epoch milliseconds, as a
string** — `"1785020723908"`. A ported `strtotime()` parser returns `false` on that,
which becomes `null`, which means *"leave the clock alone"* — so a straight port
would have set **nothing at all** and looked exactly like success. The parser is
ours, and `MirrorTimesTest` asserts both directions: it reads Penpot's format, and it
**rejects** the siblings'. Either test alone could be satisfied by the wrong parser;
the pair cannot.

#### The two clocks are not symmetric, and the folder is why

Measured on the live instance rather than reasoned about:

| | result |
|---|---|
| set a folder's `creation_time`, then write a design inside it | **survives** |
| set a folder's `mtime`, then write a design inside it | **overwritten with `now`** |

Nextcloud propagates a folder's mtime from its children. So a project folder takes
its **creation time only**. Stamping its mtime would be a fight lost on every pull
that writes any design — set, overwritten, set again — which is churn wearing the
timestamp feature's clothes. And it would be *worse information*: a propagated mtime
honestly says "something in this project changed", while Penpot's project
`modified-at` only moves on a rename (measured: `created-at == modified-at` on an
untouched project).

Files have no such conflict. They get both.

#### Where the scenarios went — and three of ours were retired

None of this got a scenario of its own, and the three standalone `@unbuilt` ones we
had been carrying were **retired rather than promoted**. A modification time is not a
behaviour anyone performs; it is the shared *result* of editing, moving, copying and
renaming, each already owned by its own feature file. Two of the three were end
states of behaviours `reconcile.feature` already specs, so the assertions moved onto
those. The third — *"setting the times never makes an unchanged pull look like a
change"* — was never a timestamp scenario at all. It is the churn rule, and it was
already live in `reconcile.feature` under its own name.

Which means the note that guarded this feature for three apps has now been absorbed
into the scenario it was really about.

#### The correction we owed the siblings

Two places in this repo still asserted, in present tense, that `nextcloud-n8n`
rewrites every mirror on every run. True when written; fixed in both siblings today.
A cross-repo claim in a comment is a claim with no test behind it, and it ages
exactly as badly as the docblock §C6.23 was about.

> **Dr K, turning the crate label over:** *"You wrote the warning, pinned it to the
> wall, and then watched two other kitchens follow it while yours never did. That's
> not humility, it's a note nobody read twice — including you. And the milliseconds:
> you'd have copied their parser, set nothing at all, and the tests would have gone
> green. Measure the thing you're certain about."*

---

### C6.25 — "Later" became "never", and the spec said so out loud

The design/project split turned up a scenario nobody had re-read since it was
written:

> **Scenario: Deleting a personal project folder never touches Penpot**

Command caught it in review, and the objection was the premise of the whole app:

> *"the whole point of the extension is to be a mirror … if you delete a tagged
> folder then the corresponding project is removed in penpot as well. This is a
> full mirror to get full parity as if I'm fully using penpot from nextcloud."*

That is right, and the spec was not describing a rule. **It was describing the
current defect as though it were the intent.** §C6.11 had already measured what
actually happens — deleting a project folder reaches Penpot *not at all*, for two
stacked reasons — and somewhere between "we will deal with this later" and
writing it down, later became never.

The tell is the wording. A real rule says what the system does *and why the
alternative is wrong*. This one said "never touches Penpot" with no reason
attached, because there wasn't one — only an unimplemented path.

#### The evidence was already in the chapter, which is the uncomfortable part

None of this needed new research. §C6.11 had measured all of it live:

| | measured |
|---|---|
| `delete-project {id}` | HTTP 204, and **entirely soft** — sets `deleted_at` to `now + 7 days` and a worker cascades the same timestamp to every file |
| restore | there is **no `restore-project` RPC**; a project returns only as a *side effect* of restoring one of its files |
| delete vs restore | **delete cascades, restore does not** — deleting a project holding two designs and restoring one brought back the project and that design, leaving the other in the trash |
| an empty project | has no file to carry it back, so it cannot be restored at all — it expires |

So the grace window lines up with the Nextcloud trash almost exactly: soft on
both sides, recoverable on both sides, for roughly the same week. That is not an
obstacle to mirroring the delete — it is the thing that *makes it safe*, and it
had been sitting in the chapter the whole time under a heading about something
else.

**Where the app can do better than Penpot.** Because restore does not cascade,
"restore the project folder" has to mean "restore every design that was in it, in
ONE call". Penpot has no operation for that; Nextcloud knows which designs were
in the folder. The extension can therefore offer a project restore that Penpot's
own UI cannot — which is precisely the kind of thing a mirror is for, and it is
now specified in `restore-project.feature` rather than left implicit.

#### What changed

`delete-project.feature` carries the correction at the top, with the measurements
inline, and the personal scenario now says the same thing as the team one —
because a personal project *is* a project, and only the credential differs. The
old scenario stays visible in the history as what it was: a gap, not a decision.

> **Dr K, reading the old ticket back:** *"You wrote 'we don't do that' on a line
> that meant 'we haven't done that yet', and then filed it. Every cook who read
> it after was reading a decision you never made. And the whole answer was three
> pages earlier in your own notebook — you'd measured it, written it up properly,
> and then not gone back and fixed the menu."*

---

### C6.26 — Two axes, and they do opposite things

Command's ask was blunt: *"long story short I want these integration tests to take
way less time as each one takes like 10 min."* The answer turned out to need a
measurement first, because the obvious split was the wrong one.

#### The cost model decides how many legs are worth it

| | measured on this repo |
|---|---|
| fixed setup (4 service containers + NC install + token mint) | **~95s** |
| Behat itself | **~320s** for 97 live scenarios |

`wall = 95 + 320/N`. So one leg is ~7m, three are ~3.4m, four ~2.9m, six ~2.5m.
**Diminishing returns past four**, because the Penpot stack is paid N times. Four
is the point where another leg buys 15 seconds and costs a whole stack.

#### The axis is the FILENAME, and tags are the trap

Tags looked like the obvious split — the suite is already tagged four ways. They
are the wrong tool, and the reason is arithmetic rather than taste. Counted over
the LIVE scenarios:

| candidate | distribution | verdict |
|---|---|---|
| channel | `@gesture` 37 · `@occ` 19 · `@admin,@occ` 10 · **none 28** | 28 match nothing |
| origin | `@in-nextcloud` 44 · `@in-penpot` 18 · **none 32** | worse |
| filename | 30 files | exhaustive by construction |

A tag partition **leaks**, and it leaks silently: a scenario matching no leg
simply stops running and every leg still reports green. You could patch it with a
negated catch-all (`~@gesture&&~@occ&&~@admin`), which then breaks the day
somebody adds a tag — again silently. A path partition cannot leak, because
`ls features/*.feature` minus the union must be empty, and that is one line to
check. `bin/check-suites.sh` checks it, in the QUALITY workflow, so a partition
error fails in seconds instead of after four stack boots.

#### The two axes are not the same kind of thing

This is the part worth carrying to the siblings:

    suite    DIVIDES the scenarios — four legs, a quarter each, wall time drops
    backend  REPEATS them — the same scenarios against a second storage backend

Only the first makes anything faster. The second makes the run *mean more* at no
wall-clock cost, because those legs are parallel too. And the reason `backend` is
a matrix axis rather than a flag some step reads is that **the two halves have
different dependencies**: a Team Folder is the groupfolders app, so the team legs
install it and the plain legs do not. Different setup, not different data.

`features/README.md` had already called the backend *"a dimension the suite is
run across"* and named the bug that cost: the structural scenarios in
reconcile.feature mapped a Team Folder and passed only because of where they sat
in the run — moved later, the folder resolved to nothing at all. Team Folder
provisioning had never actually been covered. More scenarios would not have found
that. Running the existing ones against both backends does.

The step said `mapped as a plain folder`, which was true of the only backend CI
could reach and is a lie on half the legs now. It reads `mapped to the folder`,
and the harness decides which — the Gherkin says nothing about the backend, which
is exactly what makes it a dimension rather than a duplicated scenario.

#### The flakiness was one shape, five times

Four runs of one unchanged commit failed on THREE DIFFERENT scenarios, and `main`
was failing roughly half its runs. Every one was the same shape: a gesture
MUTATED Penpot, and the very next assertion read a Penpot listing back. Penpot
applies deletes and restores through worker tasks, so the row can still be there —
or still be missing — a moment after the call that changed it returned success.

Six assertions now poll (`until()`, 10s in 250ms steps) instead of sampling once.
A poll is the honest fix and a sleep is not: it returns the instant the state is
right, so the common case costs one request, and it **fails with the same message
as before** once the window closes. It cannot mask a real bug — a state that never
arrives still fails, only later.

Worth being blunt about why this mattered beyond tidiness: a suite that goes red
half the time for reasons unrelated to the change teaches everybody to re-run
instead of read. That is the same failure mode as the warnings §C6.23 was about,
and it is how a genuine failure eventually gets waved through.

#### What ports to the siblings

All of it except the carve. `n8n_sync` and `grafana_sync` both have the same
`use_team_folder` mapping option and the same never-tested groupfolders path, and
both have feature files that partition cleanly by name. What each repo picks for
itself is only the suite names — the matrix shape, the guard script, the backend
axis and the poll are the house pattern now.

> **Dr K, watching four pans go on at once:** *"You didn't make the cooking
> faster. You stopped doing it one pan at a time — which is a different thing, and
> the only one that was ever available. And the second row of pans doesn't cost
> you anything, because the stove was already lit. Just don't let me catch you
> counting a pan you never put on."*

---

### C6.27 — The second backend found two bugs on its first run

§C6.26 added `backend` as a matrix axis on the argument that the groupfolders
path had never been exercised. It paid for itself immediately, and in the most
useful way: **`design/plain` passed and `design/team` failed on the same 32
scenarios.** No new scenario was written to find either bug. The existing ones
were pointed at the other backend, which is the entire case for the dimension.

The failures were also diagnostic rather than mysterious, because the poll from
§C6.26 was already in place: the purge and the restore both *succeeded in
Nextcloud* — no step threw — and then the assertion that Penpot had changed
waited the full ten seconds and failed. That rules out slowness and leaves only
"the app never heard about it".

#### Why it never heard about it: groupfolders is not files_trashbin

Read from `custom_apps/groupfolders/lib/Trash/TrashBackend.php` rather than
inferred. groupfolders registers its own `ITrashBackend`, and the two halves
behave differently:

| | what it emits | consequence |
|---|---|---|
| `restoreItem()` | the **legacy** hook `\OCA\Files_Trashbin\Trashbin` / `post_restore` | our listener is on the TYPED `NodeRestoredEvent`, so it never fired |
| `removeItem()` | **nothing** — `$node->getStorage()->unlink()` and a cache remove | no entry point exists for ANY app to observe it |

So one half was a wiring mismatch and the other is a hole in the platform.

#### The restore half: fixed, and by a pattern already in the file

`RestoreFromTrashListener` gained a `postRestore()` entry point and
`Application::boot()` connects the legacy hook beside the purge hook it already
connects — same guard flag, same reason (`connectHook` appends without
de-duplication). The hook hands over a PATH rather than a node, so it resolves
through the acting user's view.

The bug it fixes is real and user-facing: on the backend shared teams actually
use, restoring a mirror brought the file back in Nextcloud while the design
stayed in Penpot's trash — and the next pull then pruned the file a second time.
That is the exact gap `delete-design.feature` was written to close, closed on one
backend only, and nothing had ever noticed.

#### The purge half: not fixable from here, so it is TRACKED rather than hidden

Command's ruling, and it is the right one:

> *"this is an edge case and does deserve a tag like team-folder … we have to
> track the specific scenario so we are not only aware but can solve it for that
> case in a special way. Technically penpot will eventually delete its own trash
> anyway so it self corrects itself in a way."*

That last clause is what makes this an edge case rather than data loss, and it is
worth stating precisely: the design is *already* in Penpot's trash from the
ordinary delete, and that trash expires on its own — `deleted_at` is `now + 7d`
(§C6.11). **The divergence is a window, not a permanent state.** What is lost is
the immediacy, not the outcome.

So it is written as TWO scenarios, one per backend, tagged `@plain-folder` and
`@team-folder`, and each leg skips the other's. That is the same rule §C6.16
already established — a backend that changes an OUTCOME earns its own scenario,
because then the two would not be identical.

The `@team-folder` one asserts the design is **still** in Penpot's trash, and
deliberately does not poll: the other assertions wait for a state to arrive, this
one asserts a state that will not change. Its failure message says what to do if
it ever passes — delete it, the gap is closed.

#### The cost of repeating a filter, and the guard for it

A CLI `--tags` REPLACES behat's config filter rather than adding to it, so the
workflow has to restate the status list in order to append the per-backend skip.
Two copies of one fact drift; the only question is when. `bin/check-suites.sh`
now asserts the two expressions are identical, and the failure mode it prevents is
the silent kind: a leg quietly starting to run `@todo` scenarios, or quietly
ceasing to run real ones.

> **Dr K, reading the ticket:** *"You built the second row of pans to see whether
> anything burned, and something was burning. That's not a setback, that's the
> whole reason you built it. One you can fix; one is the oven's fault and you've
> written which is which on the wall. And the burnt one puts itself out in a
> week — say so, or the next cook will think it's worse than it is."*

#### One report for seven legs, and no XML merging at all

Splitting the run split the REPORT too: seven legs each published their own
check and their own pull-request comment update, for one test run. The question
a reader has is "did the suite pass?", and seven partial answers is a worse way
to answer it than one.

The instinct was to merge the JUnit XML — and the research says don't. This is
the reporter's own documented matrix pattern:

> *In a scenario where your tests run multiple times in different environments
> (e.g. a strategy matrix), the action should run only once over all test
> results. For this, put the action into a separate job that depends on all your
> test environments.*

`files:` takes a **glob** and aggregates across everything it matches. So each leg
only uploads its XML as an artifact, and one `needs: integration` job downloads
them all (`pattern: junit-*`, `merge-multiple: true`) and reports once. No merge
tool, no `github-script`, no XML library, and no deep-merge edge cases to get
wrong — the thing that looked like the work was work nobody has to do.

It is also strictly better than merging would have been: the action distinguishes
TESTS from RUNS precisely for this case, so a scenario exercised on both backends
reads as one test with two runs rather than two tests. A hand-merged file would
have had to invent that distinction or lose it.

The version pins were wrong on the first try in the way `AGENTS.md` warns about —
`v4` for both artifact actions, when the current majors are `v7` and `v8` and the
rest of this repo already used them. Checking `gh api repos/<o>/<r>/releases/latest`
is in the gotchas list for a reason.

##### …and the no-merge answer was right about the merge and wrong about the download

The research held: `files:` really is a glob, the action really does aggregate,
and no XML merging was needed. What shipped alongside it was a bug anyway, in the
line nobody was studying — `merge-multiple: true` on the download.

Behat names its report after the SUITE, not the leg. Every leg writes
`<suite>.xml`, so flattening seven artifacts into one directory lands
`design/plain` and `design/team` on the same `design.xml`, and the second
download overwrites the first. Seven artifacts in, four files out. 173 tests
reported as 97, 54 suites as 30.

The number that matters is not the loss, it is WHICH results were lost: download
order decides. That run published **`97 tests, 97 passed, 0 failed`** while
`design / team` was red. A green report over a red matrix — the one failure mode
a reporter must never have, and the one that would have quietly trained everybody
to trust it.

It was found by the person reading the summary and asking whether `4 files`
looked right for a seven-leg matrix. It was not found by the workflow, because a
silently smaller run looks exactly like a healthy one. So the fix carries a guard
that counts legs against reports — one leg uploads one suite report, so any
collision is arithmetic — and the guard was tested against both layouts before
being trusted: 7/7 passes, the flattened layout fails.

The lesson is the session's own recurring one, arriving from a new direction.
Every previous instance was a hollow verification of the CHANGE. This one was a
correct change with an unverified NEIGHBOUR: the merge question was researched
properly and the download question was answered from habit in the same breath.

#### An open question the spec had already assumed the answer to

`restore-design.feature` carried a scenario that uploaded an untracked `.penpot`
file INSIDE a mapped project folder and asserted the app left it alone. Nothing
in this app produces that state — anything under a mapping is mirrored — so the
scenario was quietly asserting a capability that does not exist.

The only thing that WOULD produce it is an opt-out marker: a `penpot:ignore` tag,
or a naming convention, that tells the mirror to keep its hands off a file
sitting inside a mapped folder. That is a real idea with real questions behind
it — does the pull skip it, does the prune skip it, what happens when the tag is
removed later, and does an ignored file block the name its Penpot design wants —
and none of them have been asked, let alone answered.

It is recorded here as an open question rather than left implied by a test.
Until it is designed, the features assume the simple truth: a design under a team
folder or a project folder is in Penpot, and the only way to have a `.penpot`
file that is not is to keep it outside every mapping. The scenario was rewritten
to do exactly that.

The general lesson is the one this whole chapter keeps re-learning from a new
angle. A test is a claim about the system. This one made a claim nobody had
decided was true, and because it passed — an uninvolved file being uninvolved
passes trivially — it looked like evidence for a feature that was never built.

### §C6.28 — `reconcile.feature` was never a feature

Thirty-four scenarios describing a machine. The reconciler is what carries every
`from Penpot` change into Nextcloud; it is the mechanism behind the behaviours,
not one of them. Written as a feature it did what a misplaced abstraction always
does — it grew, because everything the machine touches looks like it belongs to
it, and nothing in the file's own terms says otherwise.

The symptoms were all in the inventory before anyone read a line of prose. Of
thirty-four scenarios, three were behaviours. Ten were RULES. Thirteen restated a
verb another file already owns. One asserted a feature that does not exist. Half
the file could not be built, and the reason they were blocked is that they were
never behaviours — an unbuildable scenario is usually a scenario about the wrong
thing.

#### The three that are real, and their two actors

The behaviour is **sync now**, and who or what triggers it:

| actor | behaviour |
|-------|-----------|
| admin | sync now on ONE mapping — a synchronous pull of that team |
| admin | sync all now — the background job |
| time  | the schedule's first run, with nobody asking |

Everything the old file said about mirroring roots, projects and files is the
OUTCOME of those, not separate scenarios. A first sync is genuinely its own
situation — whatever put the designs in Penpot before this app existed is out of
scope, so "existing designs arrive for the first time" is a real, independent
thing to describe, and it only needs one or two designs to describe it.

`sync-now.feature` will replace `reconcile.feature` once the scenarios are moved. Scenarios 1, 2 and 8 of the old
file become its assertions.

#### Redundant with the file that owns the verb (13)

    rename                #11 #22   -> rename-project / rename-design
    move                  #9 #23 #24 -> move-design / move-project
    trash and re-adopt    #13 #14 #29 -> restore-design
    prune and snapshot    #25 #26 #27 -> delete-design
    Drafts at the root    #15       -> mapping-membership
    modes                 #16 #17 #18 -> set-mode / sync-mode

"A rename in Penpot renames the mirrored file" is a rename. That it arrives via
the reconciler is HOW it arrives. Renaming has its own file, covered every which
way, and a second copy of the claim under the machine's name is a duplicate that
drifts.

#### Rules, not behaviours (10)

    #3 #12    a second pull creates no duplicates
    #4 #19    an unchanged pull prunes nothing and costs no exports
    #21       an unchanged pull moves no mtime or etag
    #30       never prune on a failed or incomplete listing
    #32       there is no push counterpart          (@decision)
    #33 #34   the pull always runs as the service account

These are invariants the CODE owes, and a Gherkin scenario is a poor way to hold
one. "The pull always runs as the service account, never as a user" describes an
implementation guarantee with no actor and no gesture; nobody does it. It belongs
in prose beside the code and in a unit test that fails when it stops being true.

That is the honest home for most of this group. Idempotence, no-churn, and
"never prune on an incomplete listing" are all cheap and exact as unit tests and
vague and expensive as scenarios — #21 in particular is the anti-churn guard from
§5.11, which a unit test pins precisely and an integration scenario only gestures
at.

#### Asserting a feature that does not exist (1)

`An ignored file is skipped by the pull, not pruned` — there is no user-set per-file ignore marker implemented yet.
It is the same assumption the untracked-restore scenario smuggled in, and it goes where that one went:
the `penpot:ignore` open question, not a test.

#### The one that moves house (1)

`A snapshot that cannot be taken is reported, not faked` is an error-reporting
behaviour and belongs in `errors.feature`.

#### Sync now for a USER, and the mappings we are not building

A per-user sync-now is the same behaviour with a different token. The scope
differs — what that token can see in Penpot, and what that user can see in
Nextcloud — but the END STATE does not, and a scenario that differs only in scope
is the same scenario. The one genuine difference is that the personal team
mapping is AUTOMATIC, so the user's sync-now button is scoped to exactly one
folder and needs no mapping card at all.

Per-user team mappings are explicitly OUT OF SCOPE, recorded as a `@decision`
rather than left ambiguous. No feature file has ever asked for them, and letting
users author mappings breeds edge cases faster than anything else on the table:
two users mapping one team, a user mapping a team the service account cannot see,
what happens to their folders when the admin removes the mapping underneath them.
The personal team mapping stays automatic and singular.

#### The rule this leaves behind

**A mechanism does not get a feature file.** It gets a name in the prose of the
behaviours it serves. When a file starts collecting scenarios because they all
happen to travel through the same code, that is the signal — the question to ask
is "who does this, and what do they get", and if the answer is "nobody, it is
just true", it is a rule and belongs where rules live.


### §C6.29 — Two names for a team, one name for a project

The Background every behaviour file leans on said this:

```gherkin
    And a Penpot team is mapped to the folder "Penpot"
```

One name for a two-name object. A mapping is a row holding a **team id** and an
**nc_folder**, and §6.13 settled long ago that the folder name is the admin's
choice with the team's name merely its default. The step could not say so — it
named the destination and let the fixture supply the source, which is how "the
first visible team" got into the spec in the first place. Every scenario built on
it was quietly a *whatever-team-CI-happens-to-have* scenario.

The step now carries both:

```gherkin
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"
```

and `admin-mapping.feature` states the independence outright, with the two cases
side by side rather than as prose:

| team | folder |
|---|---|
| Northwind | Northwind |
| Northwind | Design Files |

The first row is the ordinary case and, left alone, reads like a rule. The second
is the one that says it is not one.

#### Why a team gets two names and a project cannot

This is the asymmetry, and it is **structural, not stylistic**:

- A **team** has a mapping row. There is somewhere to remember that "Northwind"
  lives in "Design Files", so the two names may differ, and the mapping still
  resolves because it is keyed on the team **id**.
- A **project** has no mapping row at all (§6.24 — a mapping is a team, that is
  the whole object). The project folder's **name** is the only thing tying it to
  its Penpot project. Give it a second name and nothing on either side can pair
  them again.

So project names are pinned equal in both directions (§6.36) — and *pinned* is
the right word, not *frozen*. A rename is never refused; it **propagates**:
rename the folder and the app calls `rename-project`, rename the project in
Penpot and the pull renames the folder. That is how the single name is kept
single. `rename-project.feature` is where both directions live.

#### What the fixture had to grow

Naming a team means the fixture has to be able to produce one, so `teamNamed()`
does find-or-create over Penpot's own `create-team` RPC. Creating it makes the
service account a member, which is what mapping is gated on (§6.18) — no invite
dance required.

`firstVisibleTeamId()` deliberately keeps its old behaviour and does **not** seed.
The scenarios that use it are the ones *about* mapping, asserting on what the
service account can and cannot see; a helper that quietly invented a team would
make those assertions vacuous.

### §C6.30 — What a mapping feature is not about

`admin-mapping.feature` had accumulated eight scenarios that were not about
mapping. They came off in four groups, and the groups are the reusable part.

**Someone else's behaviour.** Four scenarios described syncing a single mapping
from its card: that it syncs only its own team, that it answers synchronously,
that a failure is reported on the card, that the run is recorded like any other.
Every one of them is a **sync**, described from the place the button happens to
sit. They belong to `sync-now.feature`, which already has both actors (§C6.28).

Two of them were not behaviours at all. "A per-mapping sync is synchronous" is an
implementation detail — sync or queued, the admin asks and gets an answer, and
nothing an admin can observe distinguishes them except latency. "Reports its
failure on the card" is an **end state wearing a UI**: what is owed is that a sync
reports its result, and the card is one place that result gets displayed.

**A dimension, written down as a claim.** "Both storage backends grant the same
surface" asserted the thing the CI matrix already establishes by running the whole
suite twice (§C6.26). A scenario that restates the harness proves nothing and
rots the moment the harness changes. The rule stands: the backend is invisible to
the spec, and only a scenario that finds a genuine DIFFERENCE earns a mention.

**Not our software.** "Creating a file in a mapped folder never writes to Penpot
by itself" tests that Nextcloud can make a file. And the invite scenario — a team
the service account has not been invited to — tests Penpot's permission model. On
this side of the wire an uninvited team and a nonexistent one are the same case,
because `get-teams` is membership-scoped (§6.12): the id resolves to nothing
either way. One scenario covers it, and it is honest about being an id that
resolves to nothing.

**Two of the same.** Two scenarios named "A Penpot team may only be mapped once",
one live and one `@todo`. The live one is now the DRY shape, and it is the shape
worth copying:

```gherkin
  Given a Penpot team named "Northwind" is mapped to the folder "Design Files"
  When the admin maps the team "Northwind" into the folder "Design Files"
  Then the mapping is rejected
```

The old one mapped twice inside the `When` block — half the setup living in the
behaviour, and a "the admin maps the same team again" step that referred back to a
team the scenario had never named. Given-as-pre-state and a named subject removed
the need for a step whose only job was to remember something.

#### Where the backend stops being invisible

Every other suite treats the storage backend as a matrix dimension the spec must
not mention. `admin-mapping.feature` is the exception, and the exception has a
rule: **the backend is invisible except where it is the outcome.** "Mapping a team
provisions a Team Folder" and "…with Team Folders turned off provisions a plain
folder" are two things an admin chooses between, so both must be askable in one
run. That needs groupfolders installed and a scenario that names the kind it
wants.

So the admin leg's `exclude` flipped: it drops `plain` and keeps `team`. It still
runs once — the cost is unchanged — but on the leg where both halves can be
asked. On the `plain` leg the Team Folder half was untestable.

The same scenario also stopped mirroring. It ran on past a second `When the pull
runs` to assert project subfolders, which made a mapping look like it pulls. It
does not: the folder appears when the mapping is made, and what lands inside it is
`sync-now.feature`'s to state.

### §C6.31 — A form is not a set of behaviours, and a default has to work

Two findings from one reading of `admin-mapping.feature`, and the second is a
product bug the first uncovered.

#### Options are rows

Five scenarios sat here mapping a team and reading one field back each: the
default mode, the folder mode, the folder name, the groups, the Team Folder flag.
Read together they made picking `sync` look like a different BEHAVIOUR from
picking `link`. It is not. Nothing an admin picks changes what creating a mapping
does, and none of the values can even be OBSERVED until something later acts on
one — the mode decides whether a file's bytes are held, the groups and the
storage flag decide what the pull provisions, the folder mode decides how project
names become paths.

So they became an `Examples` table over one behaviour — *creating a mapping saves
the option the admin chose* — with a defaults block and a per-option block. The
step takes the CHOICE in the spec's words ("the mode \"sync\"") and translates it
to a flag, so the Gherkin never grows a `--`.

The refusals collapsed the same way, and the split between the two tables is the
useful part: **a refused OPTION** (a path where a folder name goes, `keyed`) sits
in one table, and refusals about the **TEAM** (not there, already mapped) stay
separate, because no option would have changed them.

#### The default asked for an app that might not be installed

Writing the defaults down in one column made it obvious: `use_team_folder`
defaulted to **true**. Team Folders are the `groupfolders` app — optional, absent
on a stock Nextcloud. So the default mapping, the one an admin gets by naming a
team and nothing else, asked for a backend `StorageService::canProvision()` then
refuses. A default that fails on an unconfigured instance is not a default.

It is now **false**, and the flag inverted: `--no-team-folder` became
`--team-folder`, an opt-in. The plain shared folder is core, always present, and
carries the same folder metadata a Team Folder would (§6.21) — the difference is
sharing, not mechanism, which is exactly why every other scenario can ignore it.

This diverges from the sibling apps' "prefer groupfolders" wording, and the
divergence is the lesson: **"prefer X when available" and "assume X when nobody
said" are different sentences.** The siblings meant the first. This app had
implemented the second.

The scenario that had been asserting the old default was not wrong to exist — it
was the only thing in the suite that stated what an unconfigured admin gets. It
just stated the wrong answer, in a place where nothing made the answer look odd.
A table of defaults does.

### §C6.32 — A mapping is a folder, so making one makes the folder

`add-mapping` wrote a row and touched no storage. The folder appeared later, when
`PullService` called `ensureRoot()` on its first pass — which on a default
schedule could be an hour after the admin pressed save. For that hour the mapping
was real and its destination was not, and the only way to tell the difference
from "broken" was to know the implementation.

`MappingService::add()` now calls the same `ensureRoot()` itself. Not a new
function — **the same one**, still idempotent, still called by every pull. Two
callers, two different jobs:

- **`add()` — promptness.** The admin asked for a folder; the folder exists when
  the call returns.
- **the pull — repair.** Someone deleted it by hand, or a migration lost it. The
  next pass puts it back, silently, because nobody asked for that and nobody is
  watching it happen.

`add()` also now refuses up front when `isAvailable()` says the chosen backend
cannot be built — the realistic case being `--team-folder` on an instance without
groupfolders. Saving that row created a mapping that could only ever fail, once
per sync, forever. The refusal names the fix.

#### The scenario this DELETED

There was a scenario asserting the folder appeared, and after the change it could
not survive in any form:

```gherkin
  Scenario Outline: The first sync builds the kind of folder the mapping asked for
```

It had been live for exactly one CI run, and re-reading it after the fix showed
what it really documented: **the old timing.** Its `When` was a pull, so the only
thing it pinned was that the folder did *not* exist until a sync ran — the very
behaviour being removed. Rewriting the `When` to be `add-mapping` would not have
saved it either, because then it asserts that creating a mapping created the
mapping's folder, which is not a behaviour but a definition.

So the section holds a comment and no scenario. That is the same rule §C6.30
found from the other direction: **a mechanism does not get a feature file**, and
now — an end state does not get a scenario. What an admin DOES is map a team.
That the folder is there afterwards is what "mapped" means.

The run that scenario got was not wasted. It failed on its second row with
`'Northwind' is a Team Folder, but the mapping never asked for one`, which is a
true fact about the system worth keeping: **removing a mapping deletes nothing**,
so a Team Folder outlives the mapping that made it, and a later mapping reusing
that name inherits a folder of the wrong kind. That is Course 5's open question
in miniature, and it now has a live reproduction rather than a paragraph.

### §C6.33 — Immutability belongs in the signature, not in five guards

`MappingService::update()` took a whole `Mapping` and refused five fields:
the team, the Nextcloud folder, the Team Folder flag, `mode`, `folder_mode`.
Four scenarios in `admin-mapping.feature` described those refusals, and five unit
tests exercised them.

None of it was reachable. There is no `occ` command that edits a mapping, and the
one HTTP endpoint — `MappingController::update()` — accepts `ncGroups` and
rebuilds every other field FROM STORAGE before calling the service. It could not
have moved a locked field if it tried. The guards were a lock on a door with no
handle, and the scenarios described a refusal no caller could provoke.

The method is now `updateGroups(string $id, array|string $ncGroups)`. Immutability
stopped being something the code checks and became something the API cannot say.
Nothing refuses a folder change because nothing can request one.

What went with it: five unit tests (replaced by one asserting a group change moves
nothing else), the "blank folder on update means keep it" test — a rule about a
parameter that no longer exists — and four `@todo` scenarios, replaced by a
comment stating why the fields are locked and where that reason lives.

**A refusal only earns a scenario when someone can provoke it.** The refusals that
survive in this file — a nonexistent team id, a folder name with a `/`, `keyed`,
a team already mapped — are all things an admin can genuinely type.

#### The one edit, and the command it needed

The single mutable field had no CLI at all: groups could be changed from the admin
panel and nowhere else, so the scenario for it had been `@todo` since it was
written. `occ penpot_sync:set-groups <id> <groups>` closes that, and the scenario
runs.

It also draws a line worth keeping: the command records the groups; the SHARE on
the provisioned folder is re-asserted by `ensureRoot()` on the next sync, exactly
as §C6.32 arranged. Two events, and the scenario stops at the one the admin gets
an answer about.

### §C6.34 — The folder owns its groups; the mapping should not

`admin-mapping.feature` carried this:

```gherkin
  @todo
  Scenario: A pull re-asserts the folder's group rights
    Given its group rights have been changed by hand
    When the pull runs
    Then the mapping's groups hold read, update, create and delete again
```

with a note saying hand-editing the share "is not a supported way to restrict a
mapped folder — remove the group from the mapping instead". That is backwards.
An admin editing the groups on a Team Folder, or on the plain folder's share, is
doing an ordinary and legitimate thing with their own Nextcloud. Reverting it on
the next pull is the app fighting its user.

**The folder is the source of truth.** A mapping should not carry a second copy
of the answer, because a second copy is a thing that can disagree — and when it
does, the pull silently picks the copy the admin did not touch.

`occ penpot_sync:set-groups` keeps its place, but its job changes from "record
groups on the mapping" to "apply groups to the folder". It stays worth having
for the reason it was written: it does the right thing on either backend, so
nobody has to know that groupfolders takes a group ASSIGNMENT and a plain folder
takes a group SHARE. A helper for a job you may equally do by hand.

#### What this costs, which is why it is not in this PR

The scenario is deleted here — it describes behaviour we no longer want, and a
`@todo` for the wrong thing is worse than nothing. The implementation is a
source-of-truth change and wants its own review:

- **`Mapping`** drops `ncGroups` — constructor, `fromArray()`, `toArray()`,
  `withNcGroups()`. The persisted JSON keeps the key harmlessly on old rows; it
  simply stops being read, which is correct, because the folder already knows.
- **`StorageService::ensureRoot()`** currently APPLIES `$mapping->ncGroups` every
  time it runs. Groups become an optional argument applied only when explicitly
  passed. *That removal is the feature* — it is what makes a hand edit survive.
- **a read path per backend**: `TeamFolderService::appliedGroups()` exists and is
  private; the plain side reads `IShareManager::getSharesBy()`, which
  `ensureRoot()` already walks.
- **three commands** — `add-mapping --groups`, `set-groups`, and `list-mappings`,
  which prints a column it would no longer have — and `MappingController`'s
  create, index and update.
- **three test files**, including the eight-row group outline, whose assertion
  reads `nc_groups` off `list-mappings` and must read the FOLDER instead. That
  makes it a stronger test: it would stop trusting our own record of what we did
  and go look.

No UI work: the admin panel never exposed groups.

#### The rule

**Do not store what you can read.** A cache of someone else's state needs a
reason, an invalidation story, and a rule for who wins a disagreement. `nc_folder`
earns its place — the admin chose it and nothing else records that choice.
`nc_groups` never did: Nextcloud already knows who a folder is shared with.
