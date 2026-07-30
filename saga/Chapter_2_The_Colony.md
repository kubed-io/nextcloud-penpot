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
| **`link` files** | 🟡 | The default. A pointer with `penpot_id` + metadata, deep-linking to the live design. **Never calls `export-binfile`.** |
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
| **Open in Penpot** | 🟢 | **Built (C6.1).** `<base>/#/workspace?file-id=<penpot_id>`, read off a live instance's route table, not guessed (C3.4's refusal, answered). The id rides the directory PROPFIND, so zero extra lookup — and keying on `file-id` alone is what keeps an *unmapped* mirror linkable. |
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

**The guess C3.4 refused was the wrong one, and it would not have looked wrong.**
The obvious invention was `/#/workspace/<project-id>/<file-id>` — and that route
still exists and still resolves, as `:workspace-legacy`. Every manual test would
have passed. It fails on exactly one case: a mirror **moved out of its project
folder** is *unmapped* (file-type.feature) and has no ancestor carrying a project
id, so there is no project id to put in the path. The link would have died on the
files most likely to be dragged around, and the failure would have read as a
Nextcloud bug rather than a URL we made up.

The current route needs **only the id the file itself carries**, which is why the
deep link now survives every move — the same property that makes `penpot_id` the
join everywhere else in this app. The route table did not just answer the
question; it answered it *better than the design that would have been built
around a guess*.

**`page-id` is deliberately omitted.** It is accepted, and Penpot's own dashboard
sometimes sends it — but a mirror does not know which page a user wants, and the
file's first page is the right destination for "open this design." Sending a page
id we invented would be the same class of mistake one level down.

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
