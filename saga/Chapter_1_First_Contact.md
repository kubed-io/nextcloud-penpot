# Chapter 1 — First Contact

> Transmission log, Probe Designation **PENPOT-1**, Survey Division.
>
> Command sent us to a new system with a simple brief: *"There's a signal coming
> from a planet called Penpot. Figure out if we can dock with it the same way we
> docked with the n8n system and the Grafana system. File a report before anyone
> builds anything."*
>
> We've done this twice before. The n8n system had a friendly-enough dock, but the
> engineers there hadn't finished building the folder system, so we had to duct-tape
> a folder simulator out of tags. The Grafana system, we docked at faster than
> either of us expected — turned out their dock was better-built than n8n's, real
> folders and everything, we were home before happy hour.
>
> Penpot is a different kind of planet. First transmission back to base: **this
> planet has beautiful architecture and a slightly suspicious customs desk.** Their
> public tourism brochure (the marketing site) promises an "open API." Their actual
> border control (the API you get when you land) is an internal RPC bus that the
> locals themselves argue about on public forums, wrapped in a transit-encoding
> scheme nobody enjoys, gated behind two separate "are you REALLY sure" switches
> that ship turned off. We are not saying it's hostile. We are saying: **read the
> customs forms before you drive through the gate.**
>
> This chapter is the survey. We do not dock, we do not build, we circle the planet,
> we scan, and we come home and tell Command exactly what's down there — the good
> parts, the "yes but" parts, and the one part with a live bug report still open
> against it.

---

## Status: **REOPENED** — 2026-07-26

> The chapter was closed, then reopened the same day. Two things surfaced while
> researching Dr K's `/`-as-path question, and both are too load-bearing to leave
> in a closed chapter:
>
> 1. **§6.26 was WRONG — corrected in §6.49.** Penpot's trash *is* reachable by
>    API (`get-team-deleted-files`, `restore-deleted-team-files`,
>    `permanently-delete-team-files`), verified live: a deleted file restores
>    with its **id, revision and history intact**. My earlier probe guessed
>    command names and concluded absence; the real ones are team-scoped and no
>    guess would have found them. **This makes §6.34's trash bin unnecessary** and
>    demotes §6.41's best-effort restore to a last resort.
> 2. **The nesting fork is genuinely open — §6.50.** Whether `/` in a project
>    name should drive the Nextcloud path, or whether free nesting (§6.29) stands.
>    Evidence is gathered and a recommendation recorded; the call is Dr K's.
>
> **Still true:** first contact succeeded. The architecture (§6.1–§6.50), the
> executable spec (23 feature files, ~259 scenarios) and the shipped first slice
> all stand — the app installs on a real Nextcloud and its base URL is
> configurable entirely over `occ`, green in CI.
>
> **To close this chapter:** ratify or reject §6.50, then rewrite §6.34/§6.41 and
> the delete/restore features around §6.49's correction.
>
> **Read [§6.18–§6.48](#618--decision-locked-the-access-model--a-required-service-account-reads-an-optional-personal-token-writes-as-you)
> first.** They carry the current decisions; everything before them is the survey
> that produced them, and several sections are explicitly superseded (each one
> says so inline).
>
> See [Chapter 1 — closed](#chapter-1--closed) at the end for what this chapter
> settled, what it deliberately left open, and where Chapter 2 should start.

---

## Where we were — 2026-07-26 · **THE ACCESS MODEL IS DECIDED. RESTORE IS REAL BUT LOSSY.**

> **Latest pass (2026-07-26).** Command read the whole survey and said: *stop
> surveying, decide.* This is where the big forks close. Read §6.18–§6.25 first
> if you're picking this up cold — they supersede parts of §6.1, §6.9, §6.12,
> §6.13 and §6.16, and the sections they supersede say so inline.
>
> **The five things that changed:**
>
> 1. **The credential question is closed (§6.18).** A **required service-account
>    token** does all reading/mirroring; an **optional personal token** exists
>    only to attribute the two write actions to the human who made them. The
>    fork felt unresolvable because "who reads" and "who writes" were being
>    asked as one question.
> 2. **`import-binfile` works** — first live exercise (§6.20). Both variants.
>    It's SSE, its params are kebab-case, and its `name` param is ignored.
> 3. **A deleted Penpot file cannot be resurrected at its original id** (§6.20).
>    This killed the easy version of "restore" and forced the honest one (§6.23).
> 4. **Team Folders carry Files-Metadata** identically to ordinary folders,
>    proven on a real production Team Folder (§6.21) — closing the last doubt
>    about the mapping mechanism.
> 5. **`sync` vs `link` is back** (§6.22), meaning *whether we store the bytes*
>    rather than *which way writes flow* — and the mapping is now **a team**,
>    with projects mirrored rather than mapped (§6.24).
>
> **Second pass, same day (§6.26–§6.33)** — Dr K pushed on nesting, trash, copy,
> and creation. Five more things changed:
>
> 6. **Penpot HAS a 7-day soft delete** (§6.26) — `deleted_at` is set a week in
>    the *future*, and the row, its history and its assets all survive until a
>    purge worker fires. But **no API command can reach or restore it**, so it's
>    a caveat to document, not a feature to build. The recycle-bin idea is
>    **rejected** (§6.27), though cross-team moves were proven to work.
> 7. **`duplicate-file` is real** (§6.28) — copies need no import round-trip, and
>    unlike `import-binfile` it honours the `name` param.
> 8. **Nesting is now free** (§6.29). The "one level, hard cap" from §6.13 is
>    **withdrawn**: membership is a **nearest-ancestor** lookup on folder
>    metadata, so Nextcloud can be as hierarchical as the user likes while Penpot
>    stays flat. Project folders still may not leave their team folder (§6.30).
> 9. **Personal projects mount at the user's home root** (§6.31) — the one
>    project kind with no team folder above it, and the one thing only a personal
>    token can pull.
> 10. **Creating designs from Nextcloud is ratified in principle** (§6.33),
>     scoped to locations where the target project is unambiguous, with a team's
>     **Drafts** as the landing zone otherwise. Project folders also now carry a
>     **visible tag** (§6.32) so they're findable among ordinary folders.
>
> **Third pass (§6.34–§6.37)** — Dr K overturned one of my own decisions and
> closed three more:
>
> 11. **§6.27 is REVERSED (§6.34).** The opt-in **trash project** is adopted.
>     Rejecting it compared "move the file" against *doing nothing*, when the
>     real alternative is `delete-file` — irreversible for us. A trash round trip
>     was proven lossless: **same id, same name, same revn, same history.**
> 12. **Drafts is a STATE, not a folder** (§6.35). "In a team but not in a
>     project" — so a team folder's root and every plain folder under it are all
>     Drafts. This makes Nextcloud **more expressive than Penpot** at zero cost,
>     and makes filing a draft an ordinary drag.
> 13. **A project folder's name always equals its Penpot project name** (§6.36),
>     both directions. Position stays free; only the name is pinned. This is what
>     earns the tag its keep.
> 14. **The reconciler is Nextcloud-trash-aware** (§6.37) — restoring a mirror
>     from Nextcloud's trash re-adopts it instead of creating a duplicate.
>
> **Fourth pass (§6.38–§6.41):**
>
> 15. **`create-project` and `rename-project` both work** (§6.38, first live
>     exercise). And the name rules run **backwards from expectation**: Penpot
>     accepts almost anything including `/`, which Nextcloud folders cannot — so
>     the guard protects the *pull* direction, not the push. `delete-project` is
>     soft too, same ~7-day grace.
> 16. **Renaming a project folder is its own flow** (§6.39), not a variant of
>     file rename — different node type, different RPC, different response, no
>     extension handling, and it's *decided* where file rename is still open.
> 17. **Copying a project folder is DISABLED** (§6.40). The clinching reason is
>     that n8n, Grafana and Penpot can all map the same folder on this cluster —
>     a copy asks three apps to agree on what a duplicate means.
> 18. **With the bin off, restore is BEST-EFFORT, not a failure** (§6.41).
>     Measured: name, `revn`, pages and assets all survive; the id and edit
>     history do not. Nothing *real* is lost — so the bin's job is preserving
>     history, not the difference between recoverable and gone.
>
> **Fifth pass (§6.42–§6.43):**
>
> 19. **The "Penpot shows you deleted projects" hope is a BUG, not a feature**
>     (§6.42). `get-projects` doesn't filter `deleted_at`; every other command
>     disagrees with it. So there's no visible trash to restore from, and the
>     trash bin (§6.34) **stays**. One real gain survives: `export-binfile`
>     still exports a soft-deleted file, which is a genuine rescue path for
>     `link` files inside the 7-day window.
> 20. **`link` files are confined to their project** (§6.43) — movable within it,
>     refused everywhere else, and a local delete just *hides* them. A pointer
>     with no content can't survive the gestures a real archive can, and every
>     refusal offers "promote to `sync` first" as the escape.
>
> **Sixth pass (§6.44–§6.46) — the Nextcloud trash turns out to be load-bearing:**
>
> 21. **A trashed Nextcloud file is fully reachable** (§6.44, tested): same
>     fileid, metadata intact and writable, content readable **and writable**,
>     enumerable. That single finding makes the next two possible.
> 22. **The trash IS the hidden marker for links** (§6.45) — no separate flag
>     needed, un-hiding is just "restore from trash", and it self-cleans. Links
>     are never restored *to Penpot*: their content is never touched, ever.
> 23. **A final snapshot before pruning** (§6.46) converts the app's only
>     unrecoverable case into a best-effort-restorable one — and a human
>     restoring in **Penpot's own UI** now round-trips perfectly, because our
>     reconciler re-adopts the trashed mirror by id.
>
> Everything below through §6.17 is the original survey text, left intact.

## Earlier — 2026-07-25 · **WE LANDED. WE HIT TWO LAND MINES. WE HAVE A SPECIMEN.**

> **Update, same day, after Command handed us actual boots (a real personal
> access token) and said "stop circling, go touch something."** We did. The
> planet has customs *and* two buried mines nobody marked on the chart — both
> now defused, both root-caused to the actual wire, not guessed at. We have a
> real `.penpot` file in the cargo hold, unzipped, verified with our own hands.
> This is no longer survey-only; see Course 5 for the full landing report.
>
> **Short version:** `export-binfile` was failing every single time with an
> opaque S3 403, and even after that was fixed, downloading the resulting file
> 502'd. Neither bug was Penpot misconfiguration in the way we'd have guessed —
> one was a known **upstream AWS SDK bug** (GCS-interop rejects the SDK's new
> default checksum headers), the other was a **chart default landmine**
> (nginx's DNS resolver silently falls back to the frontend pod's own IP,
> which is not a nameserver, when left unset). Both are now fixed in
> `apps/penpot`, deployed, and verified against a real file: exported,
> downloaded, unzipped, contents inspected. The `manifest.json`/`files/`/
> `pages/` shape from Course 2 matches exactly what came out of the actual
> instance.
>
> Below (through Course 4) is the original survey-only text, left intact —
> everything in it held up under the real test. Course 5 is the new landing
> report.

- **The planet is real and reachable.** Our own colony (`apps/penpot`) is already
  live in-cluster — Nextcloud 33's neighbor in namespace `cloud`, OIDC-gated
  through Keycloak, backed by the shared Postgres, already running Penpot's own
  **MCP server** for AI tools. We didn't have to build a test instance; we already
  operate one.
- **Customs desk is closed by default.** The two flags this whole mission depends
  on — `enable-access-tokens` (lets a human mint an API credential) and
  `enable-webhooks` (lets Penpot push events outward) — are **not** in our live
  `PENPOT_FLAGS` list today (`apps/penpot/components/config/values.yaml`). Day
  one of any real work is a one-line flag change and a redeploy, before a single
  line of plugin code gets written.
- **The cargo hold has a shape, and it's a good one.** Unlike n8n's opaque
  workflow JSON, a `.penpot` file is a **plain ZIP** — `manifest.json` + a
  `files/` tree of pages/colors/components/typographies/tokens + an `objects/`
  tree of binary assets (images, fonts). You can `unzip` one and read it with
  `jq`. This is closer to Grafana's "pre-portioned ingredient" than n8n's raw
  JSON blob — genuinely inspectable, versioned (`manifest.json` carries a format
  version, currently v3), and self-describing via feature flags
  (`design-tokens/v1`, `components/v2`, etc.).
- **The tractor beam that pulls a `.penpot` file out of Penpot is real, documented
  by its use in the wild, and has one known bug we can steer around.** The RPC
  command is `export-binfile` (`POST /api/rpc/command/export-binfile`, body
  `{fileId, includeLibraries, embedAssets}`). **Known issue (penpot#7649,
  still open):** requesting `includeLibraries: true` **and** `embedAssets: true`
  in the same call throws an opaque 500 (a maintainer fix that turns it into a
  clean validation error is in review, not yet merged as of this survey).
  Single-option exports (either flag alone, or neither) work fine today. Our
  sync engine should default to one option at a time and treat "both at once" as
  a known-bad combination, not a bug in our own code.
- **There is no confirmed `import-binfile` counterpart verified yet** — the
  in-app "Import files" flow accepts `.penpot`/`.zip` uploads, but whether that's
  exposed as a stable RPC command the way export is needs a live probe against
  our own instance before we lock the inbound direction. Flagged as the first
  open question for the Test Cook (a future chapter).
- **The border guards speak a strange dialect.** Content negotiation between
  plain `application/json` and Penpot's native `application/transit+json` is a
  known source of "unreadable error" reports in the community (see the linked
  GitHub discussion below) — mitigation is straightforward (always set
  `Accept: application/json` explicitly) but worth calling out so nobody loses a
  day to it.
- **Nothing is docked yet.** Next move is the same as both prior missions: one
  honest **test cook / test dock** — mint a token, flip both flags on our live
  instance, pull one real `.penpot` file down via `export-binfile`, look at what's
  actually inside it, and confirm we understand the shape before designing the
  sync engine around it.

---

## Course 0 — Does the planet let us land? (the API + auth reality check)

**Docking clearance exists, but two switches are off.** Self-hosted Penpot ships
with access tokens and webhooks **disabled by default** — both are opt-in flags
(`enable-access-tokens`, `enable-webhooks` in `PENPOT_FLAGS`), unlike n8n (API key
always available) and Grafana (service-account tokens are a first-class, always-on
operator feature). Our live `apps/penpot/components/config/values.yaml` currently
sets:

```
enable-login-with-oidc enable-login-with-ldap enable-oidc-registration
disable-registration disable-login-with-password enable-smtp enable-mcp
```

Neither `enable-access-tokens` nor `enable-webhooks` is present. **This is the
literal first line item of implementation** — before any plugin code, before any
saga chapter 2 — because nothing below works without it.

Also useful for later chapters: `enable-backend-api-doc` exposes a live `/api/doc`
endpoint listing every RPC method the *running instance* actually supports —
better than trusting any blog post, since Penpot's RPC surface visibly moves
between versions (the backend RPC URL scheme itself changed at least once per
the changelog). Turn this on during the survey/build phases; it's reasonable to
leave off in steady-state production if we want to minimize discoverable surface.

## Course 1 — Auth: how a credential is actually obtained

Two credential paths exist, same shape as n8n/Grafana's "one bearer credential"
pattern:

1. **Personal access token** (user-account level, not team/service-account level
   — worth flagging as a design constraint below). Minted at *Your account →
   Access tokens* in the Penpot UI, or presumably via RPC once
   `enable-access-tokens` is live — needs verification against our instance.
   Sent as `Authorization: Token <token>`. Expiry choices: never / 30 / 60 / 90 /
   180 days — **no auto-rotation mechanism**, which matters for an unattended
   sync job the same way it mattered for n8n's stored API key.
2. **OIDC session** — irrelevant for server-to-server sync; this is how *humans*
   log into our instance already (Keycloak). Not a path for the plugin's own
   calls.

**Open design question for a later chapter:** n8n and Grafana both use a
service-account-style credential that isn't tied to a specific human's login.
Penpot's access tokens are explicitly **personal** ("Your account → Access
tokens") — there is no visible team-level or bot-level service account in the
docs surveyed. If that holds up under a live probe, the sync engine's credential
is effectively "some admin's personal token," which is a real operational
difference worth deciding on deliberately (a dedicated service-login Penpot
account that mints its own personal token, most likely) rather than discovering
it by accident later.

## Course 2 — The file format: what we'd actually be syncing

The `.penpot` file format (current: **v3**, ZIP + JSON; v1 binary format is
deprecated-but-importable) is the best-documented of the three ingredients we've
surveyed across all missions:

```
your-design.penpot (ZIP)
├── manifest.json          ← format version, penpot version, feature flags
├── files/
│   ├── file-metadata.json
│   └── file-data/
│       ├── pages/         ← one sub-file per page
│       ├── colors/
│       ├── components/
│       ├── typographies/
│       ├── tokens.json
│       └── media/         ← references, not the binaries themselves
└── objects/                ← actual PNG/JPG/SVG binaries + per-object metadata
```

This shape has a direct consequence for our mapping design: a `.penpot` export is
a **multi-file archive representing an entire project's file**, not a single
lightweight resource the way one n8n workflow or one Grafana dashboard is. Our
"one API resource ↔ one Nextcloud file" precedent from both prior missions still
holds (one Penpot *file* ↔ one Nextcloud `.penpot`), but each individual sync is
heavier — an archive with embedded binaries, not a JSON diff. Bulk reconcile and
webhook-triggered writeback both need to budget for that: exporting is not free
(`embedAssets: true` pulls every referenced binary into the archive), and doing
it on every save the way n8n does immediate writeback may be the wrong default
here. Worth a dedicated fork in a future chapter (something like: sync `link`
mode by default given file weight, same axis n8n and Grafana both already use,
possibly weighted even harder toward `link` here).

## Course 3 — Integration mechanisms actually on offer

| Concern | Mechanism | Notes / caveats |
|---|---|---|
| Pull a file out | `POST /api/rpc/command/export-binfile` | `{fileId, includeLibraries, embedAssets}`. **Don't set both boolean flags true** (penpot#7649) — validate/normalize on our side regardless of whether upstream ships the cleaner error first. |
| Push a file in | Unconfirmed RPC for `.penpot`/`.zip` import | UI supports it; RPC-level equivalent (`import-binfile`?) not yet verified against our own instance — first item for the Test Cook. |
| Read file data directly | `get-file`, `get-page`, `update-file` RPC commands | Community reports (penpot/penpot#4180) that `update-file`'s `changes` payload is fragile/underdocumented and errors return in the Transit encoding even when JSON was requested. Treat incremental in-place edits via RPC as **unproven** — export/reimport-whole-file is the safer default path, at least for a first cut. |
| Outbound events (Penpot → us) | Team-level **webhooks**, gated by `enable-webhooks` | Configured per-team in the UI, not per-file. Payload format choice: JSON or Transit. **No official events reference yet** — Penpot's own docs point you at the backend RPC source and recommend literally pointing a webhook at webhook.site to reverse-engineer payload shapes by observation. This is the single biggest unknown of the mission and deserves its own probe. |
| Auth | Personal access token, `Authorization: Token <token>` | See Course 1 — personal, not service-account; expiring, not auto-rotating. |
| Content negotiation | `Accept: application/json` vs default `application/transit+json` | Always set the header explicitly; community reports of "unreadable" errors trace back to this. |
| Self-documentation | `enable-backend-api-doc` → `/api/doc` | Ground truth for what our *specific version* actually exposes — use this over any blog post, including this one, once we're building. |
| AI-facing surface (adjacent, not a sync path) | Penpot's own MCP server (already `enable-mcp` in our config) | A parallel, AI-agent-oriented API surface, not a substitute for the sync engine's RPC/webhook plumbing — noted because it's already running in our cluster and might be useful for a very different future chapter (AI-assisted design review inside Nextcloud), not this one. |

## Course 4 — What this means against the master's menu (n8n) and the apprentice's (Grafana)

Both prior missions established a shared shape worth re-checking here, not
re-deriving:

- **Not `External Storage` / `OCP\Files\Storage`.** Same rejection as both prior
  missions, same reason — wrong tool for "API ⇆ archive of files," and this
  time the archive is heavier, which makes the rejection even more obviously
  correct.
- **Files-Metadata API as the file↔resource link**, keyed on Penpot's file id —
  same pattern as `n8n_id` / `grafana_uid`. No naming decided yet
  (`penpot_id` is the obvious default).
- **`link` vs `sync` mode axis** — same fork both prior apps used (Fork A in the
  n8n saga). Given the export weight noted in Course 2, this mission may want to
  default new mappings to `link` rather than `sync`, unlike n8n's original
  default — a decision for the next chapter, not this one.
- **Loop prevention (`SyncGuard`-equivalent)** — same requirement, mechanically
  identical to both prior missions' implementation once we're writing code.
- **Auth is a single credential** stored via AppConfig `sensitive` — same
  pattern, with the caveat from Course 1 that Penpot's token is personal-account
  scoped, not a true service account, so *whose* account mints it is a real
  decision, not a formality.

## Course 5 — The landing report (2026-07-25, same day, real token)

Command supplied a real personal access token against a private, near-empty
instance (one team, one project, one file: "My firsty"). This section is the
honest record of what actually happened when we touched the ground — including
the two bugs we didn't expect, both root-caused to the actual mechanism, not
guessed at.

### 5.1 — What's now confirmed true (upgrades from "the docs say" to "we watched it happen")

- **`get-profile`, `get-teams`, `get-projects`, `get-project-files` all work
  exactly as documented.** Trivial GETs, no surprises.
- **`get-access-tokens` confirms the Course 1 open question:** the token is
  scoped to the human account (`kelly@...`), named `claude`, 30-day expiry —
  there is no team/service-account-level credential visible anywhere in the
  live API surface. The "dedicated bot user's personal token" plan from
  Course 1 is now the confirmed approach, not a hedge.
- **`/api/doc` (via `enable-backend-api-doc`) is real and worth using.** Pulled
  the live RPC command list straight off our own running 2.17.0 instance —
  better ground truth than any blog post. Confirms `import-binfile` **is a
  real, documented RPC command** (answers the Course 3/open-question #2 doubt):
  `POST /api/rpc/command/import-binfile`, params `{name, projectId, fileId?,
  version?, file?, uploadId?}`. Critically: **passing `fileId` does an
  in-place import into an existing file** instead of creating a new one — this
  is our writeback path for `sync` mode, confirmed to exist, not inferred.
  Also supports chunked upload via `uploadId` for files past the multipart
  size limit. Only for `binfile-v3` and only when the archive contains exactly
  one Penpot file — both true of our export shape.
- **`export-binfile` is SSE, not a direct binary response** — this was not
  clear from the docs. Calling it returns a `text/event-stream`: `progress`
  events per file/page, then either `error` or `end`. The `end` event's data
  carries a **separate asset URL** (`/assets/by-id/<uuid>`) that must be
  fetched in a second request, with the same bearer token, to get the actual
  ZIP bytes. Any sync engine built against this needs an SSE-aware client, not
  a plain POST-and-save.
- **The `.penpot` format documentation in Course 2 is accurate.** A real
  export of "My firsty" unzipped clean (`zipfile.testzip()` → no errors) with
  exactly the documented shape: `manifest.json` at the root, `files/<file-id>.json`,
  `files/<file-id>/pages/<page-id>.json`, and per-shape JSON files nested under
  the page. `manifest.json`'s `generatedBy` field literally says
  `"penpot/2.17.0"` — self-describing, as promised.
- **`create-webhook` validates the target URI at creation time** (a live
  reachability/handshake check, not just a URL-shape check) — pointing it at a
  non-listening host fails immediately with `{"type":"server-error","code":
  "webhook-validation"}` rather than silently accepting a dead endpoint. Good
  operational property; means we can't stand up the webhook.site probe from
  Open Question #3 without a real listener behind it — still open.

### 5.2 — Bug #1: `export-binfile` failed every time with an opaque S3 403

**Symptom:** every `export-binfile` call died mid-stream with `event: error`,
`{"type":"server-error","code":"unexpected","hint":"Invalid argument.
(Service: S3, Status Code: 403, Request ID: null)"}`. Backend logs showed the
export itself succeeding (`app.binfile.v3 — "exportation finished"`) followed
immediately by a failure in `app.storage/put-object!` — i.e. **writing** the
finished export to the GCS bucket, not reading design data from it.

**Root cause, confirmed not guessed:** AWS Java SDK v2 ≥ 2.30.0 (bundled in
Penpot's 2.17.0 image) turns on default request-integrity checksums (CRC32)
for S3 writes. GCS's S3-interop layer doesn't support them, rejects the
signature (`SignatureDoesNotMatch`), and the SDK flattens that into the
generic `Invalid argument (403)` we saw. This is a **known, already-reported
upstream bug** — [aws/aws-sdk-java-v2#5987](https://github.com/aws/aws-sdk-java-v2/issues/5987)
— not a Penpot config mistake and not our credentials.

**How we proved it wasn't our credentials/IAM/region:** hand-signed a raw
SigV4 `PUT` against the same bucket with the same HMAC key, no SDK involved —
succeeded (`HTTP 200`) on the first try. That isolated the fault to the SDK's
request construction, not the infrastructure around it.

**Fix (deployed):** `AWS_REQUEST_CHECKSUM_CALCULATION=when_required` and
`AWS_RESPONSE_CHECKSUM_VALIDATION=when_required`, restoring pre-2.30 SDK
behavior. Landed as a JSON6902 patch on `penpot-backend` and `penpot-exporter`
in `components/storage-gcs/kustomization.yaml`
— **not** `config.extraEnvs` in `values.yaml`, because helm replaces arrays
wholesale on deep-merge rather than concatenating them, and the keycloak
component already has its own `extraEnvs`-shaped need
(`PENPOT_SSRF_ALLOWED_HOSTS`) solved the same patch-based way for the same
reason. One patch block targets both deployments via a regex name match
(`penpot-(backend|exporter)`) rather than duplicating it.

### 5.3 — Bug #2: fixing #1 revealed a second bug — downloading the export 502'd

**Symptom:** after Bug #1's fix, `export-binfile` completed cleanly (`event:
end` with a real asset URL), but fetching that URL 502'd:
`nginx — storage.googleapis.com could not be resolved (110: Operation timed
out)`.

**Root cause, confirmed by reading the actual nginx config out of the running
pod, not assumed:** `/assets/*` requests hit the frontend's nginx, which
proxies to the backend; the backend responds with an HTTP redirect straight to
the GCS object URL; nginx's `@handle_redirect` location catches that redirect
and re-proxies to it dynamically, which requires nginx to resolve
`storage.googleapis.com` itself at request time via its own `resolver`
directive — **separate from the pod's normal `/etc/resolv.conf`.** The chart's
`config.internalResolver` value was unset in our config, and the chart's own
default fallback for that case is `status.podIP` — **the frontend pod's own
IP**, not a nameserver. nginx was, in effect, asking itself for DNS and
getting nothing back, on every asset fetch.

**Fix (deployed):** set `config.internalResolver` to the cluster's CoreDNS
Service ClusterIP (the cluster's CoreDNS ClusterIP, read live via `kubectl get svc -n kube-system
kube-dns`) in `components/config/values.yaml`.
This one's a plain string value, not an array, so no merge-hazard — safe
directly in `values.yaml`. Flagged in the comment there that this IP isn't
documented anywhere else in the repo as a durable contract, and should be
re-verified if the cluster's service CIDR ever changes.

### 5.4 — Verified end to end, honestly

After both fixes: exported "My firsty" fresh, downloaded the real asset bytes
(`HTTP 200`, 5330 bytes — not an error page), confirmed `PK\x03\x04` ZIP magic
bytes, ran it through Python's `zipfile` (`testzip()` → clean), and listed +
read its contents. This is the first point in the mission where "the
mechanism works" moved from *documented* to *witnessed*.

### 5.5 — The actual JSON on the wire

Every shape below is a real response captured from our own live instance
(`penpot.example.com`, app 2.17.0), not reconstructed from the schema
docs — `curl` against the real RPC endpoints with the real token, pretty
printed as-is. UUIDs and timestamps are real values from our one-team/
one-project/one-file test instance; the account email is redacted since
there's no reason to commit a real address to git.

**`get-teams`** — no params. Note the **`features` array differs per team**
(the default team has `fdata/path-data`; the second one doesn't) — team
features aren't fixed at server-version, they're per-team flags, worth
capturing per mapping if we ever gate behavior on a feature flag:

```json
[
  {
    "features": [
      "fdata/path-data", "plugins/runtime", "design-tokens/v1", "variants/v1",
      "layout/grid", "styles/v2", "fdata/objects-map", "tokens/numeric-input",
      "render-wasm/v1", "components/v2", "fdata/shape-data-type"
    ],
    "permissions": { "type": "membership", "isOwner": true, "isAdmin": true, "canEdit": true },
    "name": "Default",
    "modifiedAt": "2026-07-13T00:52:29.043072Z",
    "id": "4eda2e11-843e-8045-8008-51819d3bce9d",
    "createdAt": "2026-07-13T00:52:29.043072Z",
    "isDefault": true
  },
  {
    "features": [
      "plugins/runtime", "design-tokens/v1", "variants/v1", "layout/grid",
      "styles/v2", "fdata/objects-map", "tokens/numeric-input",
      "render-wasm/v1", "components/v2"
    ],
    "permissions": { "type": "membership", "isOwner": true, "isAdmin": true, "canEdit": true },
    "name": "Ferronescotia",
    "modifiedAt": "2026-07-13T00:55:27.847826Z",
    "id": "4eda2e11-843e-8045-8008-51824bda07a1",
    "createdAt": "2026-07-13T00:55:27.847826Z",
    "isDefault": false
  }
]
```

**`get-projects?team-id=<uuid>`** — `count`/`totalCount` are a cheap
file-count cache Penpot maintains server-side (useful to detect "something
changed in this project" without walking every file):

```json
[
  {
    "teamId": "4eda2e11-843e-8045-8008-51824bda07a1",
    "name": "My Stuff",
    "modifiedAt": "2026-07-26T00:09:16.301192Z",
    "isPinned": false,
    "id": "61d8ecb9-c430-8120-8008-622627f23540",
    "count": 1,
    "totalCount": 1,
    "createdAt": "2026-07-25T23:07:04.515485Z",
    "isDefault": false
  },
  {
    "teamId": "4eda2e11-843e-8045-8008-51824bda07a1",
    "name": "Drafts",
    "modifiedAt": "2026-07-25T23:07:13.197920Z",
    "isPinned": false,
    "id": "4eda2e11-843e-8045-8008-51824bdafd88",
    "count": 0,
    "totalCount": 0,
    "createdAt": "2026-07-13T00:55:27.850411Z",
    "isDefault": true
  }
]
```

**`get-project-files?project-id=<uuid>`** — this is the per-file poll shape a
scheduled pull job would diff against: **`modifiedAt` + `revn` (revision
number) together are the drift signal** — if either has moved since our last
recorded value for this `id`, re-export and re-pull:

```json
[
  {
    "teamId": "4eda2e11-843e-8045-8008-51824bda07a1",
    "name": "My firsty",
    "revn": 5,
    "modifiedAt": "2026-07-26T00:09:16.301192Z",
    "vern": 0,
    "id": "61d8ecb9-c430-8120-8008-6225c5b12134",
    "isShared": false,
    "projectId": "61d8ecb9-c430-8120-8008-622627f23540",
    "createdAt": "2026-07-25T23:05:23.908789Z"
  }
]
```

**`get-access-tokens`** — no params; scoped to the calling account (confirms
§5.1's "personal, not service-account" finding — this list has exactly the
one token we created, nothing team-wide):

```json
[
  {
    "id": "61d8ecb9-c430-8120-8008-62269c07b4a1",
    "name": "claude",
    "createdAt": "2026-07-25T23:09:03.390974Z",
    "updatedAt": "2026-07-25T23:09:03.390974Z",
    "expiresAt": "2026-08-24T23:09:03.390940Z"
  }
]
```

**`get-profile`** — no params; the account the token acts as. `defaultTeamId`
/ `defaultProjectId` are a convenience default, not a constraint — every call
elsewhere still takes an explicit `teamId`/`projectId`:

```json
{
  "email": "REDACTED",
  "isDemo": false,
  "authBackend": "ldap",
  "fullname": "Kelly Ferrone",
  "modifiedAt": "2026-07-13T00:52:29.031719Z",
  "isActive": true,
  "defaultProjectId": "4eda2e11-843e-8045-8008-51819d3f622b",
  "id": "4eda2e11-843e-8045-8008-51819d3925cd",
  "isMuted": false,
  "defaultTeamId": "4eda2e11-843e-8045-8008-51819d3bce9d",
  "createdAt": "2026-07-13T00:52:29.031719Z",
  "isBlocked": false,
  "props": { "...": "onboarding/UI-state bag, not useful for the sync engine" }
}
```

**`export-binfile`** — this is the one that isn't a plain JSON response; it's
an SSE stream (see §5.1). Full real transcript, in order:

```
event: progress
data: {"~:section":"~:file","~:id":"~u61d8ecb9-c430-8120-8008-6225c5b12134","~:name":"My firsty"}

event: progress
data: {"~:section":"~:page","~:id":"~u61d8ecb9-c430-8120-8008-6225c5b12135","~:name":"Page 1","~:file-id":"~u61d8ecb9-c430-8120-8008-6225c5b12134"}

event: end
data: {"~#uri":"https://penpot.example.com/assets/by-id/75b356e7-a3fd-4a9b-8012-b4095eecada1"}
```

Note the payload is **Transit-JSON, not plain JSON**, even though we sent
`Accept: application/json` — `~:section`, `~u<uuid>`, `~#uri` are Transit's
type-tagging syntax (`~:` = keyword, `~u` = uuid, `~#` = tagged map). A real
client needs a Transit decoder for this endpoint specifically, or naive
string-parsing of these specific tag prefixes — this is the "border guards
speak a strange dialect" caveat from the original survey, now shown with a
real payload instead of asserted from a community thread. The failure form
(`event: error`) uses the same tagging: `{"~:type":"~:server-error",
"~:code":"~:unexpected","~:hint":"..."}`, captured verbatim in §5.2.

**The downloaded `.penpot` file's `manifest.json`** — the ground-truth
confirmation that Course 2's documented format matches a real export from our
own instance:

```json
{
  "type": "penpot/export-files",
  "version": 1,
  "generatedBy": "penpot/2.17.0",
  "refer": "penpot",
  "files": [
    {
      "id": "61d8ecb9-c430-8120-8008-6225c5b12134",
      "name": "My firsty",
      "features": [
        "fdata/path-data", "design-tokens/v1", "variants/v1",
        "layout/grid", "components/v2", "fdata/shape-data-type"
      ]
    }
  ],
  "relations": []
}
```

**Not shown here because not called:** `rename-file` and `import-binfile`
were deliberately **not** exercised during this survey — both are mutating
calls, and there was only one real file on the instance to test against. Their
shapes above (§6.2, §5.1) are the documented request schema from `/api/doc`,
not a witnessed response. Calling both for real, against a disposable test
file, is open-question #6 for the next chapter.

## Course 6 — The architecture spec: read-only, rename, tags, and the mapping shape

Command looked at everything above and made four calls. Each is recorded here
with the live-instance evidence that either confirms or refines it — this is
no longer speculation, it's checked against the running planet.

### 6.1 — Decision (locked): Nextcloud is a read-only mirror of Penpot, not a peer

> **⚠️ Partially superseded by §6.22 and §6.23.** The read-only stance itself
> **stands** — Nextcloud never pushes design *content* to Penpot. Two things
> below have since changed: the "no `link` vs `sync` mode axis" bullet is
> **reversed by §6.22** (the axis returns, meaning *whether we store the bytes*,
> not which way writes flow), and the blanket "nothing is ever written" framing
> is narrowed by §6.19's two write paths (rename, restore).

**The call:** unlike n8n and Grafana, this module does **not** offer a `sync`
mode with Nextcloud→Penpot writeback for file *content*. A `.penpot` file is
an opaque exported archive — editing it inside Nextcloud (the "Edit as text"
right-click both prior apps have) makes no sense, because there is no sane
way to hand-edit a ZIP of nested-JSON shape data and re-import it coherently.
Design happens in Penpot; Nextcloud's job is to **hold the backup and provide
the click-through**, not to be a second place edits happen.

**What this actually removes, concretely, versus the n8n/Grafana precedent:**
- No `link` vs `sync` mode axis (Fork A in the n8n saga) — there's only one
  mode. Every mapped file is what n8n/Grafana would call `sync`-shaped (real
  content stored in NC) but permanently read-only from the NC side.
- No "Edit as text" file action (n8n has this for raw JSON editing).
- No Nextcloud→Penpot writeback listener (`NodeWrittenEvent`-driven push) —
  there is nothing to push, because NC never originates a change.
- No dual-channel writeback config (REST vs webhook, à la n8n's Fork B) — moot,
  same reason.
- **What stays, because it's inbound-only and still needed:** the file-action
  click → "Open in Penpot" deep link (works identically whether the file is
  "live" content or a pointer); the custom icon/mimetype registration; the
  Files-Metadata id link (`penpot_id`) so renames/moves/re-syncs can find the
  right Penpot file; a `SyncGuard`-equivalent is arguably unnecessary now
  (loop prevention only matters when two directions can race — with one
  direction, there's nothing to loop), pending confirmation once the pull job
  is actually built.

**What the admin settings page reduces to:** base URL + access token (Course
1), and a **scheduled pull** (interval, or "Sync now" button) that walks
mapped team→project scopes and calls `export-binfile` per file, replacing the
NC copy if Penpot's `modifiedAt` moved. No writeback timing fork (n8n's Fork
C — sync-vs-async push) exists on our side, because there is no push. Webhooks
(Course 3) become the *trigger* for that pull, not a payload we store directly
— confirmed by §5.1: `create-webhook` fires on file/project/team-scoped
Penpot-side events, exactly the "wake up and re-pull" signal a scheduled-pull
design wants, and the webhook payload doesn't need to carry the actual design
data (we still need `export-binfile` for that regardless).

### 6.2 — Rename: confirmed both directions are simple, one is currently unimplemented

**Penpot → Nextcloud (file gets renamed in Penpot):** trivial — it's covered
by the same scheduled pull as any other content change; the pull job compares
Penpot's current `name` against what's on disk and renames the NC file to
match, keyed on `penpot_id`. No new mechanism needed.

**Nextcloud → Penpot (someone renames the file in the Files app):** confirmed
possible via a real, simple RPC: `rename-file`, params `{id: uuid, name:
string 1–250 chars}`, response `SimplifiedFile {id, name, createdAt,
modifiedAt}` — one field, keyed on the Penpot file id we're already storing
per Files-Metadata for the click-through link (Course 6.1). **It also fires a
webhook** (confirmed `WEBHOOK` tag on the command in `/api/doc`), so a rename
done in Nextcloud is symmetrically visible to anyone watching Penpot's side.

This is a genuine design fork worth naming, not yet decided: does the
read-only stance from 6.1 extend to the *filename*, or only to *content*?
Renaming a file is a much smaller, safer surface than editing shape data —
`rename-file` is a one-field RPC call, not a re-import — so it's plausible to
allow NC-side renames to propagate to Penpot (via a `NodeRenamedEvent`-style
listener calling `rename-file`) while keeping content strictly one-way. Left
as an open fork for the next chapter rather than assumed either way.

### 6.3 — Tags/labels/annotations: confirmed absent, and it simplifies the module

Grepped the full live `/api/doc` RPC surface (149 commands) for anything
tag-, label-, or annotation-shaped. **Zero real hits** — every apparent match
on a first pass was noise (substring hits inside unrelated words). Penpot has
**no first-class tagging system at the API level**, unlike n8n (which the
n8n saga's Fork-A precursor had to fake folder structure out of, via a
tag→folder binding) — Penpot doesn't need that trick because it already has
real folders in the form of projects (Course 6.4).

**Consequence for this module:** no tag-sync machinery at all — n8n's
`TagSyncService`/`TagReconcileService`/`TagMerge`/`ReservedTagResolver`
quartet (flagged back in the original shared-module research as n8n-specific
weight Grafana didn't need either) has no Penpot equivalent whatsoever, not
even Grafana's simpler "folder is the unit" version. One less axis to design,
confirmed rather than assumed.

### 6.4 — Mimetype: a real extension, but not a free custom mimetype from Penpot itself

**Correction to the "known extension, known mimetype" framing:** the `.penpot`
extension is real and Penpot-specific (confirmed by the file format doc and
the live export). But when Penpot's own server hands us the exported archive,
it serves it as **`Content-Type: application/zip`** (confirmed via `curl -I`
against a live `/assets/by-id/...` URL) — a generic ZIP mimetype, not
anything Penpot-branded. So this module is in the **same position n8n and
Grafana were in**: it still has to register its own custom mimetype
(`application/vnd.penpot+json`-style, or similar) in Nextcloud via the same
mechanism both prior apps used — `occ maintenance:mimetype:update-db` /
`update-js` plus the extension→mimetype mapping config, per the shared
"no clean per-app mimetype API yet" caveat from the n8n saga (server#10131,
still true). The advantage over n8n/Grafana isn't a free mimetype — it's a
**simpler, single-token extension** (`.penpot`, one part) instead of their
compound two-part scheme (`.n8n.json` / `.grafana.json`), which sidesteps
the "the extension is a compound and must not be simplified" fragility n8n's
AGENTS.md specifically warns about. One real win, one assumption corrected.

### 6.5 — Mapping shape: team → project → file, confirmed as a flat 3-level hierarchy

Verified directly against `/api/doc`'s param schemas, not inferred:

- **`create-file` requires `projectId`** (not `teamId`) — a file belongs to
  exactly one project.
- **`create-project` requires `teamId`** — a project belongs to exactly one
  team.
- **`move-files` takes `projectId`; `move-project` takes `teamId`** — moving
  a project between teams and moving files between projects are the only two
  reparenting operations that exist. No sub-projects, no nested teams — the
  hierarchy is flat, exactly three levels: **team → project → file**.
- **A project is genuinely lightweight**, confirmed both from `get-project`'s
  params (`{id}` only) and a live `get-projects` response: `{id, teamId, name,
  isPinned, isDefault, count, totalCount, createdAt, modifiedAt}` — no
  permissions, no settings of its own. It's a plain named container, nothing
  more, which matches project↔folder being the obvious, low-risk mapping
  unit.

**Structural proof, not just schema-shape inference — the containment is
hard, not soft/optional metadata:**

- Called `get-project` with **only** `{id}` in the request — no `teamId`
  supplied anywhere — and the response still came back carrying `teamId`.
  A project record cannot be represented without its team; the server
  doesn't treat team as an optional tag on a project, it's intrinsic to the
  record.
- Called `get-all-projects` (no params — every project this account can see,
  across every team). **Every single project in the response carries
  `teamId` and `teamName`**, no exceptions, including a **second
  auto-provisioned "Drafts" project** on the other (Default) team that hadn't
  shown up in the earlier per-team sampling — direct confirmation that 6.6's
  "every team gets one automatically" claim holds structurally, not just for
  the one team spot-checked earlier:

  ```json
  [
    { "id": "4eda2e11-843e-8045-8008-51819d3f622b", "teamId": "4eda2e11-843e-8045-8008-51819d3bce9d",
      "isDefault": true, "name": "Drafts", "teamName": "Default", "isDefaultTeam": true },
    { "id": "4eda2e11-843e-8045-8008-51824bdafd88", "teamId": "4eda2e11-843e-8045-8008-51824bda07a1",
      "isDefault": true, "name": "Drafts", "teamName": "Ferronescotia", "isDefaultTeam": false },
    { "id": "61d8ecb9-c430-8120-8008-622627f23540", "teamId": "4eda2e11-843e-8045-8008-51824bda07a1",
      "isDefault": false, "name": "My Stuff", "teamName": "Ferronescotia", "isDefaultTeam": false }
  ]
  ```
- Same shape one level down: `create-file` *requires* `projectId` (no
  team-level or rootless file creation exists in the schema at all), and
  every live `get-project-files` record (§5.5) carries `projectId`
  unconditionally.

So the chain is **team `contains` project `contains` file**, evidenced at
both links, not merely implied by which id-field a create call happens to
ask for.

**This is what justifies mapping Penpot teams to Nextcloud groups, not just
folders.** A Penpot team isn't only "the top of the containment tree" — per
the ACL finding below, it's *the thing membership and roles attach to*,
exactly the role an NC group plays. An NC **group** (not just a folder) is
the structurally honest counterpart: group membership drives access the same
way team membership does, and a **Team Folder** shared with that group is
then the natural place for the mapped project-subfolders to live — group for
the ACL semantics, Team Folder for where the files actually sit. Binding team
only to a folder (with no group) would reproduce the folder shape without the
access-boundary meaning that makes a team a team.

**Confirmed: teams are the real ACL boundary, projects carry no permissions of
their own.** Every permission/membership/invitation command in the live doc
(`create-team-invitations`, `get-team-members`, `delete-team-member`,
`update-team-invitation-role`, `get-team-users`, …) takes `teamId` — none
take `projectId`. Roles are `viewer | admin | editor | owner`, team-scoped
only. `set-file-shared` / `create-share-link` are a separate, unrelated
mechanism (public share links for outside viewers), not part of the
membership model. This means: **a project cannot have narrower or broader
access than its team** — whatever a Penpot user can see at the team level,
they see for every project under it.

**Proposed mapping (not yet locked, for next chapter to ratify):**
- **Team → top-level Nextcloud folder** (or a Team Folder / group-shared
  folder, mirroring n8n/Grafana's `TeamFolderService` pattern already in the
  shared-module research) — since team membership *is* the access boundary in
  Penpot, mirroring it onto an NC Team Folder with matching group ACLs is the
  only mapping that doesn't quietly leak or restrict visibility relative to
  Penpot's own model.
- **Project → subfolder within the team's folder** — matches the "lightweight
  named container" finding in 6.5 exactly; no separate ACL handling needed at
  this level since Penpot has none either.
- **File → `.penpot` file within the project's subfolder**, keyed on Penpot's
  file `id` via Files-Metadata, per 6.1/6.2.

**Tension with 6.7, noted not resolved:** the structural-containment evidence
above (team `contains` project, hard not soft) is a point *against* 6.7's
"team as metadata-only" fork — if team is dropped to metadata, the mapping
stops mirroring a real structural fact the API itself enforces. 6.7 is still
worth keeping open (the Drafts-as-landing-zone idea has real appeal), but
whoever ratifies it should weigh it against this evidence explicitly, not
independently of it.

This is a cleaner 1:1 shape than n8n ever got (n8n has no real folders, so its
mapping is a tag-simulated fiction) and matches Grafana's folder-to-folder
mirror almost exactly — except one level deeper (Grafana: folder → NC folder;
Penpot: team → project → NC folder-of-folders).

### 6.6 — "Drafts" is a project with special meaning — the same smell as Grafana's recycle-bin folder, but inverted

Both live teams' `get-projects` (§5.5) include a project named **"Drafts"**
with `"isDefault": true` — not something either team's owner created; every
team gets one automatically. There's no dedicated `draft`-anything RPC command
in the live `/api/doc` (checked — zero hits), so this isn't a distinct object
type at the schema level, just an ordinary project that happens to carry
`isDefault: true` and gets auto-provisioned. **Not yet verified:** whether
`delete-project` actually refuses to delete it, or whether that's purely a
client-side UI convention — the schema alone doesn't say, and this instance
only has one real team to test against, so this is flagged as unconfirmed
rather than assumed.

This is worth naming next to Grafana's `RecycleBin` service
(`nextcloud-grafana/lib/Service/RecycleBin.php`) because both are "a
folder-shaped thing with a special meaning baked in" — but the resemblance
stops at the shape, not the direction:

- **Grafana's recycle bin is *our* invention, opt-in, and points *outward*.**
  Grafana itself has no native trash; `RecycleBin.php`'s docblock says so
  directly ("Grafana has no native trash, so this is it"). It's an admin
  setting (`bin_enabled` + `bin_folder`, off by default) that our module
  layers on top of Grafana by *resolving a folder title the admin chose* via
  `GrafanaClient::resolveFolderUidByTitle` — nothing about it exists until an
  admin opts in and names a folder. It exists to give Nextcloud-side deletes a
  soft-delete behavior Grafana doesn't have on its own.
- **Penpot's Drafts project is *Penpot's* invention, always-on, and points
  *inward*.** It's not a setting, not something our module creates or names —
  it's a fact about every team that exists before our module ever touches the
  instance, auto-provisioned, `isDefault: true`. If it turns out
  (open question, above) that Penpot also refuses to let it be deleted, that
  would be Penpot enforcing its own convention on its own object model, not us
  adding a convenience on top of a gap.

**Practical consequence for the mapping (6.5):** Drafts is not a container to
special-case away — it's a completely ordinary project by every RPC signature
that touches it (`get-project`, `move-files`, etc. take it like any other
`projectId`). The one open question is purely cosmetic: does the mapping admin
UI need to visually flag "this is each team's default/undeletable project" so
it doesn't look like an arbitrary user-created folder when it shows up as a
subfolder under the mapped team, or is treating it identically to every other
project the right call? Left for the next chapter, once the delete-resistance
question above is actually tested.

### 6.7 — Fork (raised, not decided): project↔folder binding, team as metadata, Drafts as the unmapped-create landing zone

Command floated a reframe of 6.5's mapping, worth recording as a real fork —
**vibing, explicitly not decided**, per the same "record forks with pros/cons,
let the implementor back out" convention the n8n saga uses.

**The idea:** bind **project ↔ folder** as the primary mapping unit (not
team → folder → project → folder as 6.5 sketches); team becomes **metadata
only**, not a folder level. Then: creating a new Penpot file from an
*unmapped* Nextcloud location (e.g. clicking "create" at the NC root, which
has no project binding) has a natural home — it lands in that team's
**Drafts** project (6.6), the same way a designer creating a file outside any
organized project in Penpot's own UI would. A mapped folder becomes
meaningful precisely *because* it's mapped: "put it in a mapped folder, or it
defaults to Drafts" gives the mapped/unmapped distinction real teeth instead
of being arbitrary.

**Why this is appealing:** Penpot already ships the "landing zone for
unsorted stuff" concept for free (6.6) — reusing it beats inventing our own
equivalent of Grafana's `RecycleBin.php` pattern from scratch. It also gives
"is this folder mapped or not" an actual behavioral consequence for creates,
not just for the pull job.

**What real scrutiny surfaces, unresolved:**

- **Team ambiguity.** Drafts is *per-team*. If team is metadata-only (not a
  folder level), "an unmapped create goes to Drafts" is underspecified the
  moment more than one team exists — *whose* Drafts? Some notion of a default
  team has to survive somewhere, even if it's not a folder level, or this only
  ever works by accident on a single-team instance (which is all we have to
  test against right now — Course 5's instance has exactly one team with real
  content).
- **This reopens 6.1, not just extends it.** 6.1 locked Nextcloud as
  **read-only** — no writeback, no Nextcloud-originated content. "New Penpot
  file from Nextcloud's create menu" is Nextcloud *originating* a Penpot
  object, which 6.1 as written doesn't accommodate. That's not disqualifying,
  but it means this fork is really proposing a narrower rule than blanket
  read-only: *existing files stay read-only; creation is a distinct,
  Nextcloud-can-originate path.* Worth stating as its own decision, not
  smuggled in as a detail of the mapping shape.
- **The inverse case is unhandled:** a `.penpot` file already sitting
  unmapped in Nextcloud (dragged there, or left over, not newly created via
  NC) — does moving it into a mapped folder trigger an import-into-Drafts,
  then a project move? Or does the mapping only ever watch for genuinely new
  creates, leaving pre-existing unmapped files alone? Not addressed by the
  idea as stated.
- **Priority shift:** this makes `import-binfile` (open question #6) **load-
  bearing for the core UX** — a create-from-Nextcloud flow can't exist without
  it working — rather than "nice to have for a future writeback mode." It
  needs to be tested before this fork is buildable, not after.

**Status: raised, not decided.** No code, no locked decision — same posture
as any other unresolved fork in this saga. Next chapter should either ratify
it (and update 6.1's read-only scope to match) or explicitly reject it in
favor of 6.5's plainer team→project→folder-of-folders shape.

### 6.8 — Credential ceiling, confirmed: no admin scope, no service account — personal token is the whole story

Direct follow-up to Course 1's open question. Checked structurally, not
inferred:

- **Every RPC module enumerated** (`access-token, auth, binfile, comments,
  demo, feedback, files-snapshot, files, fonts, management, media, nitrate,
  profile, projects, teams-invitations, teams, viewer, webhooks`) — **no
  `admin` or `system`/`instance` module exists.** Grepped specifically for
  instance-, server-, global-, and sysadmin-shaped command names: zero hits.
  Server-level administration (what our own `apps/penpot` deploy config
  already handles via `PENPOT_FLAGS`/env vars) is **entirely out-of-band from
  the RPC API** — there is no API path that reaches it, for any credential.
- **There *is* a real layer above team** — `organizationId` appears as a
  field on `create-team` and a cluster of `nitrate:`-namespaced commands
  (`add-team-to-organization`, `check-org-members`,
  `get-owned-organizations-summary`, …) — but it's **permission-gated off on
  our self-hosted instance**, confirmed live: calling
  `add-team-to-organization` with syntactically-valid-but-fake UUIDs returned
  `{"type":"validation","code":"insufficient-permissions"}` (HTTP 400) — a
  permission check firing before any data lookup, not a 404 "command doesn't
  exist" or a schema-validation error on the fake ids. Reads as SaaS/
  enterprise-tier billing-and-procurement grouping (multiple teams under one
  paying org), inert here regardless of which account holds the token. Also
  confirmed indirectly: `get-owned-organizations-summary` (a `profile:`-module
  command, not gated) returns `[]` for our account — zero organizations,
  consistent with a self-hosted instance that never created one.
- **"Owner" is the ceiling, and it's not a special account type.** Checked
  `create-team`'s schema: no explicit owner field — whoever's token calls it
  becomes owner implicitly. `update-team-member-role`'s `role` enum is
  `viewer | admin | editor | owner` — owner is a peer in that list, not a
  distinct escalation path or account class. `leave-team` takes an optional
  `reassignTo: uuid`, confirming Penpot enforces "a team always has an
  owner" by forcing reassignment rather than allowing an ownerless team —
  ownership is a transferable role on an ordinary account, not a fixed
  property of whoever happened to sign up first.

**Answer:** no, there's no escape from "personal token belonging to whichever
account is team owner." The three things that might have offered an
alternative — an admin API, an organization-level credential, or a distinct
"owner" account type — were each checked directly and each ruled out. This
locks in Course 1's tentative plan (§Course 1: "a dedicated service-login
Penpot account that mints its own personal token") as the only real option,
not a hedge against an unconfirmed possibility.

### 6.9 — Fork (raised, not decided): per-Nextcloud-user Penpot tokens instead of one admin credential, and the "Default" personal team confirmed real

> **⚠️ Superseded by §6.18.** This fork is closed. The live-instance findings
> here (the "Default" personal team, the provisioning timestamps) remain valid;
> the *proposed resolution* — per-user tokens as the load-bearing credential —
> is not what was chosen. See §6.18.

§6.8 closed one door (no service account) and Command opened a different one
in response: **don't fight the personal-token model — lean into it.** This is
a genuine architecture fork against Course 1's original assumption
("`InstanceSettings`-style: one admin-configured credential for the whole
app," the same shape n8n's `InstanceSettings.php` and Grafana's equivalent
both use), not a refinement of it. Recorded here raised, not decided, same as
6.7.

**The personal "Default" team is real, confirmed exactly as described.**
Every account's `get-teams` includes a team named **"Default"** with
`isDefault: true` — this is the "your penpot" team. Checked precisely, not
assumed:
- `get-profile`'s `defaultTeamId` matches that team's `id` exactly.
- The team's `createdAt` (`...043072Z`) is **12 milliseconds** after the
  profile's own `createdAt` (`...031719Z`) — one atomic provisioning
  transaction: account → personal team → (presumably) its Drafts project, not
  something a user builds or could opt out of.
- Whether it's deletion-protected the way Drafts might be (§6.6) is
  **unverified** the same way — `delete-team`'s docstring shows no special
  casing, and there's no safe way to test it against a real account without a
  disposable one. Same open-question posture as §6.6's Drafts question.

**The fork, concretely:** instead of one admin-configured Penpot credential
(n8n/Grafana's `InstanceSettings` shape), each **Nextcloud** user gets their
own personal-settings page to paste in their own Penpot access token — a
genuinely new UI surface neither prior module has (checked: no per-user
`ISettings`/personal-settings precedent exists in either `nextcloud-n8n/lib`
or `nextcloud-grafana/lib`, only `IUserSession` reads inside listeners to
check who's currently acting — this would be new territory for the module
family, not reuse). The admin's role shrinks to instance-level plumbing only:
the base Penpot URL, and — since this cluster already has LDAP service
accounts for exactly this purpose (`components/service-account/ldap.yaml` in
`apps/penpot` is the live example) — optionally a **service-account-backed
Penpot login** an admin could use to mint one token for shared/team-level
mappings, separate from any individual user's own token. That LDAP account
would itself just be an ordinary Penpot user with its own "Default" team plus
whatever real teams it's invited into — §6.8 already established there's no
special account class to grant it, service account or not.

**Why this is appealing:** it maps Penpot's actual security model onto
Nextcloud's rather than fighting it. A single admin-wide token (Course 1's
original assumption) can only ever see the teams *that one account* belongs
to — for any Penpot user not on that account's teams, the admin credential is
simply blind to their work, no matter how "admin" it sounds. Per-user tokens
sidestep that ceiling entirely: each Nextcloud user's mapped folders are
backed by *their own* Penpot visibility, which is also the more honest
security posture (no single credential that can silently read every user's
private "Default" team content).

**What real scrutiny surfaces, unresolved:**
- **Storage/UX for a personal-settings page.** Neither prior module has this
  pattern built; it needs its own design pass (where does a per-user
  `sensitive` AppConfig-equivalent live — `IUserSession`-scoped preferences?
  — not covered by anything in the shared-module research either).
  Multi-account note: `AWS`-style "one Penpot login → many Nextcloud users"
  isn't precluded, but the natural default is 1:1 (each NC user, their own
  Penpot account, their own token) — worth stating explicitly next chapter,
  not left implicit.
- **Token lifecycle is now N times the surface.** Course 1 already flagged
  no auto-rotation on Penpot tokens; with one credential per NC user instead
  of one for the whole instance, expiry/revocation/re-auth has to be handled
  per user, not once centrally. A dead token now degrades one user's sync,
  not the whole instance — arguably better fault isolation, but more moving
  parts to monitor.
- **Does the "Default" team get mapped automatically, or does a user have to
  opt in?** If every NC user's personal Penpot team maps to *something* by
  default (their own NC home, or a personal top-level folder), that's a
  sensible zero-config starting point — but needs to be a stated default, not
  assumed.

### 6.10 — Fork (raised, not decided): Team Folder as the team-mapping mechanism — but scrutinized against the established "optional dependency" precedent

Direct extension of 6.7/6.9: if team maps to something structural after all
(6.5's evidence favors this over 6.7's metadata-only idea), the natural
Nextcloud-side counterpart Command is proposing is a **Team Folder**
(`groupfolders`) per Penpot team, with **projects as subfolders inside it** —
and the drag-and-drop consequence falls out for free: a `.penpot` file
sitting directly in the Team Folder's root, or in any subfolder that isn't a
mapped project, **is** a draft by construction (no separate "is this a
draft?" bookkeeping needed — location *is* the answer), and dragging it into
a mapped project-subfolder is the same physical gesture Penpot's own UI uses
to move a file between projects.

**This needs real scrutiny, and Command named the right reason why:** checked
directly — **`groupfolders` is not in this cluster's installed-apps list**
(`apps/nextcloud/components/lifecycle/before-start.sh`'s `APPS=` line has no
`groupfolders`). More importantly, n8n's and Grafana's own
`TeamFolderService.php` (`Service/TeamFolderService.php` in both) already
establish the house precedent for exactly this situation, in their own
docblock: *"FQCN resolved lazily so a disabled groupfolders app doesn't break
DI"* — Team Folders is treated as an **optional enhancement**, with the
module required to still function on **plain NC folders + regular group
sharing** when the app is absent. Committing the Penpot module's core
team-mapping concept to Team Folders as a hard dependency would be a real
regression from that precedent, not a neutral choice — the same "no
dependency principle" Command flagged.

**What this means, concretely, for 6.10 to be buildable without that
regression:** the mapping needs **two tiers**, mirroring how
`TeamFolderService` already handles it — Team Folder when available (real
ownerless shared folder, matches Penpot's team-is-an-ACL-boundary finding
from 6.5 most precisely), falling back to an ordinary NC folder + the house
group-sharing pattern when `groupfolders` isn't installed. That fallback tier
needs its own design pass — a plain folder shared to an NC group doesn't have
groupfolders' ownerless/no-single-owner property, which may or may not matter
for how Penpot-team access is meant to mirror onto it. Not resolved here.

**Where this leaves the Drafts idea from 6.7:** the "unmapped location →
Drafts" consequence reframes cleanly once team = Team Folder (or its
fallback) and project = subfolder: *any* `.penpot` file outside a mapped
project-subfolder, whether at the team-folder root or in an unmapped
subfolder, is a draft by the same rule, uniformly. This resolves 6.7's
"team-as-metadata-only" awkwardness (the tension noted in 6.5 against hard
structural evidence) — team **is** structural again, matching 6.5's evidence,
while still keeping the Drafts-as-landing-zone behavior 6.7 wanted. **Still
open:** 6.7's other unresolved points (which per-user "whose Drafts" team
applies at the Nextcloud root, now partly answered by 6.9's per-user token
model — presumably the acting user's own Default team/Drafts when no
containing Team Folder is mapped at all — and 6.1's read-only-vs-creation
scope) still need to be settled, not just the folder-shape question this
section resolves.

**Status: both raised, not decided.** Same posture as every other fork in
this chapter — no code follows from this until ratified next chapter.

### 6.11 — Decision (mostly locked): a dedicated Instance settings card, URL-only, split from the credential question

Regardless of how 6.9's credential fork resolves (one admin token vs.
per-Nextcloud-user tokens), **every version of this app needs an admin to
configure the base Penpot URL somewhere** — that part isn't in question, only
the credential shape sitting next to it is. Worth locking the URL piece now
rather than let it stay tangled up in the still-open fork.

**Checked both prior apps' actual pattern, and they solve this two different
ways for a reason that matters here:**

- **Grafana's `InstanceSettings.php` bundles URL + token in one card** —
  justified in its own docblock: Grafana has exactly *one* API and *one*
  credential, so there's nothing to split.
- **n8n's `InstanceSettings.php` is URL-only**, deliberately separated from
  credentials — justified in its own docblock: the n8n URL is *global*, it
  scopes multiple credential channels (API key in `AdminSettings`, webhook
  token in `WebhookSettings`), so it doesn't belong to either one and gets
  its own card.

**Penpot's situation matches n8n's shape, not Grafana's — for a related but
new reason.** It's not that Penpot has multiple *simultaneous* credential
channels (it has one: the personal access token, confirmed in §6.8); it's
that **who holds that one credential is still an open fork** (§6.9: one
admin-wide token vs. per-Nextcloud-user tokens). Bundling URL + token into
one card the way Grafana did would force the URL to be decided *inside*
whichever credential-card design 6.9 eventually settles on — n8n's split
avoids exactly that coupling. So: **Instance settings card is URL-only,
admin-scoped, decided now; the credential card(s) next to it stay
undesigned until 6.9 resolves.**

**Concretely, following the n8n shape (adapted, not copied verbatim):**
- A `Settings/InstanceSettings.php` implementing `IDeclarativeSettingsForm`,
  `section_id => 'penpot_sync'`, one field: `penpot_url`
  (`DeclarativeSettingsTypes::URL`, placeholder `https://penpot.example.com`,
  description noting in-cluster URLs work too, no trailing slash — matching
  both prior apps' URL-field convention verbatim).
- **Not yet decided, deliberately left to 6.9:** how many credential cards
  exist next to it (one `AdminSettings`-style card for a shared/admin token,
  vs. a wholly different per-user personal-settings surface neither prior app
  has built before), and where the `enable-access-tokens`/`enable-webhooks`
  reminder from Course 0 belongs — probably a description string on whichever
  card ends up holding the token, once that's designed.
- A matching `Settings/AdminSection.php` (`IIconSection`, `getID() =>
  Application::APP_ID`, own sidebar entry rather than living under
  "Additional settings") — mechanically identical to both prior apps' version,
  no Penpot-specific content in this class at all, so it can be built directly
  from either reference with just the namespace/icon-asset swapped.

**Status: URL-only Instance card is locked as of this chapter — build it
directly off n8n's `InstanceSettings.php` shape once `lib/Settings/` exists.
The credential card(s) beside it remain gated on 6.9.**

### 6.12 — Refinement of 6.9: user tokens do the real work, the admin token is optional and read-only — but its reach is capped by team membership, not by us

> **⚠️ Superseded by §6.18 — and specifically inverted.** The load-bearing
> finding here **stands and is load-bearing for §6.18**: no credential gets an
> instance-wide view; a team enters scope only via an explicit `viewer` invite.
> But the *conclusion* is reversed — the service token is **required** and does
> the reading; the user token is **optional** and only attributes writes.

Command proposed a concrete resolution to 6.9's open credential-shape
question: **per-Nextcloud-user Penpot tokens are load-bearing** (they're what
actually reads/exports each user's own files — content access has to be
theirs, not borrowed); **the admin-section token becomes optional**, useful
only for read-only convenience work like pre-provisioning folders based on
what Penpot already has, before/without a given user ever configuring their
own token.

**Worth naming precisely, because this cluster happens to have a specific
overlap that a general design shouldn't assume:** in this instance, LDAP +
Keycloak sync means the Nextcloud admin account and a Penpot account named
"nextcloud" could be *the same identity* in practice. That's a nice property
*here*, but it's an operational fact about this cluster's IdP wiring, not
something the app can rely on structurally — a different self-hosted Penpot
instance won't necessarily have that account-identity overlap. The saga
should describe the mechanism (an admin-configured, optional, read-only
credential) without assuming the identity coincidence as a requirement.

**Checked against the API, and this is the part that needs to be stated
precisely rather than assumed generous:** an admin/service token's visibility
is **not** instance-wide, no matter whose account it belongs to or what it's
named. Confirmed directly — `get-teams` returns `"permissions": {"type":
"membership", ...}` on every entry; it is hard-scoped to teams the calling
account is actually a **member** of. There is no elevated or admin view of
"every team on this Penpot instance" for any credential (consistent with
§6.8: no server-admin API surface exists at all). So:

- **"Provision folders ahead of time based on Penpot info" only works for
  teams the nextcloud/admin account has actually been invited into** — not
  proactively, not instance-wide. The mechanism that grants it visibility is
  the same one any human uses: `create-team-invitations` with `role: viewer`
  (confirmed — a real, working RPC command, live schema: `{teamId, emails,
  role}`), called by that team's owner. Read-only is a real, correctly-scoped
  role choice here — `viewer` is sufficient for "see the team's projects and
  files to provision folders," no write access needed or wanted for this
  purpose.
- **This converges naturally with 6.10's Team Folder proposal**, not by
  coincidence: whoever owns a Penpot team already has to take one explicit
  opt-in action — invite the nextcloud account as `viewer` — before any
  admin-side provisioning can see that team at all. That's structurally the
  same shape as an NC admin deciding which group gets a Team Folder: nothing
  happens automatically instance-wide; a specific team/group has to be
  explicitly brought into scope, per-team, by someone with authority over it.
- **Net effect on 6.9's fork:** resolved, not just narrowed. User tokens are
  the load-bearing credential for a user's own content (files, per-project
  reads/exports) — no ambiguity there anymore. The admin token is real,
  optional, and legitimately useful, but scoped to exactly the teams it's
  been invited into as a viewer — never a shortcut around per-team
  visibility, because Penpot itself doesn't offer one to any credential.

**Status: 6.9's credential-shape fork is now resolved along these lines** —
per-user tokens for content, an optional admin/service viewer-token for
provisioning convenience, its reach always bounded by explicit per-team
invitation. What's still open from 6.9: the per-user personal-settings page
design itself (storage mechanism, 1:1 assumption) — unchanged by this
section, tracked as open question #9.

### 6.13 — Decision (locked): 6.10 ratified — Team Folder (or shared-folder fallback) mounts a team, one level of real subfolders are projects, tolerating non-Penpot content — and mapping is admin-tightened, not per-user-open

> **⚠️ Refined by §6.24, and point 1 is REVERSED by §6.29.** Two changes: the
> mapping *object* is **a team only** (project subfolders are mirrored by the
> pull, not separately mapped — §6.24); and the **"exactly one level, hard cap"**
> rule below is **withdrawn** — §6.29 replaces it with a nearest-ancestor lookup,
> so folders may nest freely in Nextcloud. The pull still *creates* project
> folders one level under the team folder; users may then move them anywhere
> within it (§6.30). Points 2–4 below (tolerated content, server-authoritative
> naming, no orphaned teams) are unchanged.

Command ratified 6.10's fork with two tightening constraints, resolving it
into a locked design rather than leaving it open:

**1. The hierarchy is real but capped at one level — team → its Team
Folder/shared folder; a *direct* subfolder of that = a project.** Not
"however deep a user wants to nest things." This is a deliberate
simplification, not a limitation discovered by testing: letting every user
freely map any folder-at-any-depth to any project would make the mapping
state itself hard to reason about (which folder maps to what, at a glance,
across many users) — the same complexity tax 6.5 already flagged team↔folder
binding as needing to avoid. One level down, matching Penpot's own real
hierarchy depth (6.5: team → project → file, no sub-projects), keeps the
Nextcloud side legible by construction rather than by convention.

**2. Non-Penpot content inside a mapped folder is expected and must be
tolerated, following Grafana's precedent exactly.** Grafana doesn't
distinguish managed files by extension alone — it stamps an **ownership
pill** (an NC system tag: `grafana:sync` / `grafana:link` / `grafana:unmapped`,
see `Service/OwnershipTags.php`) on every file it actually manages, and
touches nothing else in the folder. A mapped Penpot project-subfolder works
the same way: arbitrary other files (notes, exports, anything a user drops
in) sit alongside the `.penpot` files untouched: the sync engine only ever
acts on files it recognizes via metadata/ownership marker, never "everything
in this folder." This needs its own `OwnershipTags`-equivalent once
`lib/Service/` exists — likely simpler than Grafana's three-state version,
consistent with 6.1's read-only stance (no `unmapped`-and-restorable state
needed if there's nothing to restore a push from).

**3. Naming stays server-authoritative, not admin-renamable at mapping
time.** Command's stated reason is the right one: if two different
Nextcloud setups (or two users, under the per-user token model) mapped the
*same* underlying Penpot team/project to differently-named folders, keeping
them in sync — or even just recognizing "these are the same thing" during
support/debugging — gets hard fast, purely from a naming mismatch that
carries no signal. So: **a mapped folder's name tracks Penpot's project/team
name** (via the same pull-driven rename mechanism as 6.2 — Penpot→NC rename
propagation, already confirmed to exist), not a name the person creating the
mapping gets to choose independently. This doesn't forbid renaming
*in Penpot* (6.2's Penpot→NC direction still applies normally) — it forbids
the mapping UI from letting "map this Penpot project" and "call the folder
something else" be two independent decisions.

**4. Team origin is untracked by Penpot, and that's fine — because Penpot
appears to make the question moot.** Checked directly: neither the team
object nor a team-member record carries any `creator`/`createdBy` field —
`get-teams`/`get-team-members` show only live, mutable roles (`isOwner`,
`isAdmin`), no historical "who founded this" marker. Command then ran the
actual experiment live against this instance: created a team ("Foo"),
immediately left it as the sole member. Result, confirmed by direct
`get-teams`/`get-owned-teams`/`get-all-projects` checks immediately after:
**the team and its auto-created Drafts project were both gone** — not
orphaned-but-invisible, not still enumerable via `get-owned-teams`, actually
deleted, cascading to its projects. So Penpot appears to prevent an
ownerless team from persisting at all (leaving as the last member deletes
it, rather than leaving it stranded) — there's no "who does this abandoned
team actually belong to" edge case for the mapping design to handle, because
Penpot's own data model doesn't allow that state to exist. Not exhaustively
proven (single trial, one account, self-hosted instance — worth a note if a
future chapter ever needs the exact mechanism, e.g. does it require sole
membership or does last-owner-with-other-members-present also cascade), but
strong enough evidence to stop treating "orphaned team" as a case the
Nextcloud mapping needs to defend against.

**Status: locked.** Team → Team Folder (or shared-folder fallback per 6.10);
exactly one level of real subfolders = projects; non-Penpot content inside
is expected and untouched, following Grafana's ownership-pill pattern;
mapped-folder naming is Penpot-authoritative, not independently admin-set;
no orphaned-team case to design around.

### 6.14 — Big question, checked against the live Nextcloud, not assumed: can a folder carry metadata? Yes — confirmed at three separate layers, including Team Folders specifically

Command's instinct going in was exactly right, and pushed further with a
sharp follow-up mid-investigation: *"I don't think team folders have tags
though"* — worth stating plainly that this was checked, not waved past. All
of the below is real evidence from the live cluster's own Nextcloud pod
(`the live Nextcloud pod`), not documentation reading.

**Confirmed: `\OC\Files\ObjectStore\S3` backing storage, `objectPrefix:
urn:oid:` — this is exactly the flat-storage observation from the bucket.**
Live config, read directly: `occ config:system:get objectstore` shows the
storage class, bucket (`<nextcloud-bucket>`), and — the load-bearing
detail — `objectPrefix: urn:oid:`. Every file's actual bytes live at a flat
key `urn:oid:<fileid>` in the bucket; there is **no folder structure in the
object store at all**. The entire hierarchy (what's inside what, names,
nesting) lives exclusively in Nextcloud's own database (the filecache), not
in the storage backend. This confirms the theory precisely: **a folder is
not a "real" filesystem-style container from the storage layer's point of
view — it's a database row**, structurally the same kind of row as a file,
distinguished by a mimetype value (`httpd/unix-directory`) and referenced by
other rows' parent pointers, not by being a different kind of object in the
backing store. This matches what a Team Folder-mount instance already showed
us in passing back in Course 6: object storage doesn't know about
directories, full stop — this just confirms it's true of *every* folder on
this instance, not only Penpot's export bucket.

**Confirmed, live: an ordinary Nextcloud folder can carry a system tag.**
Ran `occ tag:files:add "kelly/files/n8n" "test-metadata-probe" "invisible"`
against the **live "n8n" Team Folder** (a real, in-production `groupfolders`
mount, folder id 4, shared to the `friends`/`family`/`devs`/`admin` groups —
not a scratch folder) — succeeded (`invisible tag named test-metadata-probe
created. ... added.`), verified present via `occ tag:list`, then fully
reverted (tag removed from the folder, tag definition deleted, confirmed
gone via a follow-up `tag:list`). **This directly answers the "team folders
don't have tags" concern: they do** — `tag:files:add`'s own help text says
"Add a system-tag to a file **or folder**," and the live test against a real
Team Folder proved it's not just documentation talk.

**Confirmed, from the actual Nextcloud server source shipped in this pod:
the Files-Metadata API — the mechanism n8n/Grafana actually use for
`n8n_id`/`grafana_uid`, not system tags — is uniform across files and
folders by construction, not by coincidence.** Read
`lib/public/FilesMetadata/IFilesMetadataManager.php` directly off the live
pod. Its two core methods:

```php
public function refreshMetadata(Node $node, int $process = self::PROCESS_LIVE, string $namedEvent = ''): IFilesMetadata;
public function getMetadata(int $fileId, bool $generate = false): IFilesMetadata;
```

`Node` is Nextcloud's **common base type for both files and folders** —
there is no separate `FolderMetadataManager` or file-only restriction
anywhere in the interface. `getMetadata` takes a plain `int $fileId`, the
same fileid space used for every filesystem entry regardless of type. This
is the strongest form of the answer: it's not "metadata happens to work on
folders too," it's that the API was designed with one uniform id space and
one uniform `Node` type from the start — files and folders were never
architecturally distinct as far as metadata is concerned.

**So: yes, we could put the Penpot project/team id as Files-Metadata
directly on the mapped folder itself**, the same mechanism n8n stores
`n8n_id`/`n8n_mode`/etc. on individual files, just pointed at a folder's
fileid instead of a file's. This is a real, available option — not
theoretical — and it's a stronger, more idiomatic answer to "how does the
mapping remember which Penpot project a folder is bound to" than a
side-table (a separate app-owned DB table mapping folder-path-or-fileid →
Penpot-project-id) would be, because:
- It survives folder moves/renames automatically (Files-Metadata is keyed on
  fileid, which is stable across both — matches the same reasoning 6.2/6.5
  already used for why the Penpot file link is keyed on Penpot's own id, not
  a name).
- It's visible/inspectable the same way any other Files-Metadata is (via
  `occ metadata:get <fileid>`), which is operationally convenient for
  debugging a mapping without needing a bespoke admin command.
- It keeps the "what is this folder bound to" fact colocated with the folder
  itself in Nextcloud's own data model, rather than in an app-private table
  that could silently drift out of sync with the actual folder structure
  (get orphaned by a manual folder deletion the app doesn't observe, etc.).

**Not yet decided — this section establishes the option is real and
available, not that it's chosen.** Whether the mapping should actually use
folder-level Files-Metadata (vs. an app-owned mapping table, vs. a system
tag, vs. some combination — e.g. a tag for human-visible "this folder is
Penpot-mapped" affordance plus Files-Metadata for the actual id) is a design
decision for the next chapter, informed by this section rather than answered
by it.

### 6.15 — The team-import flow: a real proposal, with one load-bearing premise corrected against the live instance

Command sketched a concrete admin-UI flow: from wherever the Penpot token
gets configured, query the account's teams, show which already have a
matching Nextcloud Team Folder and which don't, let the user check a box to
"import" one as a Team Folder. Projects inside never need to be Team Folders
themselves (they're one level down, inside an already-shared folder — 6.13
already locked this). For creating a **new** Penpot project from the
Nextcloud side: make an ordinary folder inside the Team Folder and tag it
with a special app-owned tag; that tag is what makes it a project rather
than incidental content (6.13's "tolerate non-Penpot content" clause, used
as the *mechanism* for the reverse direction too — a plain folder becomes a
project by being tagged, not by name-matching alone).

**One premise needs correcting before this is buildable as stated: "all
users can create Team Folders" is not true by default.** Checked directly,
both against the live groupfolders README and this cluster's actual
instance:

- Team Folders are, by the feature's own documentation, **"Admin configured
  folders"** — creation is admin-only out of the box. There is a real,
  documented escape hatch — **"Team folder admin delegation"** (since NC
  25) — that lets an admin grant specific non-admin users or groups the
  right to create/manage them. But it's opt-in and admin-configured, not a
  capability every user has by default.
- Checked this specific cluster: no delegation is currently configured
  (`occ config:list groupfolders` shows only the bare app-enabled state,
  nothing delegation-related). So on this instance, right now, the "check a
  box, make it a Team Folder" step in the proposed flow would need either
  admin privileges or a deliberate admin action to grant delegation first —
  it is not something any Nextcloud user can already do here.
- This doesn't kill the idea — it reframes one step of it. The "query
  Penpot teams, show which are/aren't imported, check a box" UI can still
  live in the per-user settings surface from §6.9/6.12 exactly as proposed;
  it's specifically the **folder-creation action behind the checkbox** that
  needs to either (a) require the acting Nextcloud user to actually hold
  Team Folder admin/delegated rights, surfaced honestly in the UI (grey out
  or explain the checkbox if they don't), or (b) go through an admin-side
  approval/creation step instead of being instant for every user. Which of
  those is next chapter's call — flagged, not resolved here.
- One more real constraint surfaced by the same README, worth carrying
  forward: **"you need to be a member or admin of a team in order to assign
  it to a Team folder"** (their "team" here means an NC Circle/Team, a
  different concept from an NC *group* — worth not conflating the two when
  this gets built) — another point where "just check a box" has a real
  permission gate underneath it that the UI needs to respect, not paper
  over.

**Where the proposal holds up well, confirmed against the actual API/docs:**

- **"Already imported, visible to other members automatically" is correct
  and doesn't need separate design.** The groupfolders README confirms
  Team Folders "show up in the home folder for each user in the configured
  groups or teams" automatically — there's no separate "pending/shared but
  not yet visible" state to build; once a Team Folder exists and a user's
  group is granted access, they simply see it. So "would you see it as
  already imported" resolves itself: if the querying user is in a group
  already granted access to a matching Team Folder, it just shows up in
  their Nextcloud regardless of who created the mapping — the per-user
  settings page's job is just to *detect* that correspondence (match a
  Penpot team id already recorded via 6.14's Files-Metadata mechanism
  against the teams this Penpot token can see) and reflect it, not to grant
  new access.
- **Matching an imported project by name, with the tag as the *creation*
  signal for the reverse direction, is a clean, coherent design — and it's
  the same mechanism doing two jobs, which is worth stating explicitly
  rather than leaving as two separate ideas:** pulling *from* Penpot, a
  project named "Foo" becomes (or matches, if it already exists) a
  same-named subfolder — ordinary name-matching, fine because 6.13 already
  locked mapped-folder naming as Penpot-authoritative on the pull direction.
  Going the other way — a user makes a plain Nextcloud folder inside a
  mapped Team Folder and wants it to *become* a Penpot project — name-match
  alone can't disambiguate "this is meant to be a project" from "this is
  just a folder of reference files," which is exactly why a dedicated
  app-owned tag (the same mechanism 6.13 already established folders can
  carry, confirmed live in 6.14) is the right signal: **tag present = create
  the project in Penpot (via `create-project`, confirmed real in 6.5) on
  next sync; tag absent = ordinary tolerated content, untouched.** This
  reuses 6.13's ownership-pill pattern for a second purpose (creation
  trigger, not just "which files does the sync engine touch") rather than
  inventing a separate mechanism.
- **This is Nextcloud-originating a Penpot object** (a new project, and by
  extension eventually files placed in it) — same tension already flagged in
  6.7 against 6.1's blanket read-only stance. 6.7 raised it for *files*; this
  extends the same open question to *projects*. Doesn't need a new fork
  entry — folding into 6.7's existing "creation is a distinct,
  Nextcloud-can-originate path, separate from content read-only-ness" framing
  is the right home for it, now with a second concrete example (project
  creation via tag) alongside 6.7's original one (file creation via unmapped
  location → Drafts).

**Status: raised, refining 6.7/6.13/6.14 rather than a new independent
fork.** The Team-Folder-creation permission gate is the one genuinely new
open point; everything else here is confirmed-workable detail on forks
already on the table.

### 6.16 — A real gap surfaced: what actually runs the scheduled pull, given per-user tokens? Webhook-as-trigger plus a service-account-first design closes most of it

> **⚠️ Resolved by §6.18.** The duplication/race analysis below is the reasoning
> that *drove* §6.18's decision and is worth reading. Its open (a)-vs-(b)
> question is closed: **(a) wins** — the service account must be invited before
> a team can be mapped. Note also that the webhook-as-trigger design here is
> still **unproven** (delivery never observed, §6.17) — the cron pull of §6.22
> is the sole trigger until that's explained.

§6.9/§6.12 resolved the credential model for the *interactive* case (a human
configures/tests their own token, an optional admin token for provisioning
convenience). Command's question exposes that **the scheduled background
pull was never actually designed against that model** — a human clicking
"test connection" and an unattended cron-driven job are different actors
with different needs, and the credential answer for one doesn't
automatically answer the other. Worth working through properly rather than
leaving implicit.

**1. Naive per-user pull has a real duplication/race problem, not just
waste.** If the scheduled job is "for each Nextcloud user, walk their
mappings using their own token," then two users both mapped to the *same*
Penpot project hit two separate problems depending on how their mappings
land: if both resolve to the same Nextcloud location (the normal case per
§6.15 — a Team Folder auto-appears for every member of a granted group, so
two members of one team naturally share one mapped folder), **two
independent job runs write the same mirror file with no coordination between
them** — a real race, not a style objection. If instead each user has their
own separate import of the same content, it's silent duplicated storage and
duplicated `export-binfile` traffic for identical bytes. Either outcome is a
real problem the earlier per-user-token resolution didn't account for.

**2. Async (Nextcloud's `IJobList`/cron-worker mechanism, same as n8n's
saga documents) solves "does this block a request," not "whose credential
does the job run as."** Worth being precise about this because it's easy to
conflate: making the pull a background job is uncontroversial and
orthogonal — it doesn't touch the credential question at all, it just
answers *when* the job runs relative to a user's request. The real question
survives async exactly as-is.

**3. The "nextcloud" service-account token (§6.12) is the actual answer for
most of the fleet, not just a nice-to-have — but it has one real gap.**
§6.12 already established the load-bearing fact: a service account's reach
is capped by which teams it's been **invited into as viewer**, not by us.
That reframes cleanly here: **the scheduled pull's primary path is one job,
one service-account token, walking every team that account has been invited
into** — no per-user duplication, no race, because there's exactly one actor
doing the pulling for every team in that set. This is a genuinely better
answer than "async-but-still-per-user" because it collapses N users' worth
of redundant pulls into one pass whenever the underlying team only needs
pulling once regardless of how many Nextcloud users can see it.

The gap: nothing stops a Nextcloud user from mapping a Penpot team the
service account was **never invited into** — their personal token can see
it, map it, but the service account can't pull it. For that residual case,
either (a) the mapping flow *requires* the service account to hold a viewer
invitation on a team before it can be mapped at all (turns "invite the
nextcloud account" into a real precondition, not just a nice-to-have for
provisioning convenience — a stronger claim than §6.12 made), or (b) a
per-user fallback pull still has to exist for teams the service account
can't reach, scoped only to that user's own uniquely-visible mappings (no
duplication risk in that narrower case, because by definition no other
user's token can see the same team). (b) is more flexible but reintroduces a
second pull pathway to build and reason about; (a) is simpler but adds a
real onboarding requirement Command hasn't stated a preference on yet. Left
open, not decided here.

**4. Webhook-as-trigger is real, checked, and worth doing — but the payload
still can't replace `export-binfile`.** Two things confirmed:
- `create-webhook`'s schema shows no role-restriction tag beyond `AUTH`
  (checked directly against the live `/api/doc` entry — only `['AUTH',
  'SCHEMA']`, no owner-only marker) — weak but real evidence that a
  viewer-scoped token could plausibly register a webhook itself, not just
  read data. Not proven (server-side role checks can exist without being
  schema-visible) — would need a live test to confirm, not assumed here.
- **This app registering its own endpoint and having Penpot call it is
  architecturally sound and exactly what webhooks are for** — Course 3
  already established the mechanism is real (`create-webhook`, live
  reachability validation at creation time, confirmed in §5.1). Used this
  way, on file/project/team-scoped events, a webhook becomes the **trigger**
  that wakes the scheduled-pull-equivalent job early/on-demand instead of
  waiting for the next cron tick — the same "webhook as fast-path, cron as
  the reliable baseline" shape n8n's own saga already uses for its own
  writeback timing (Fork C, C2-cron vs C2-worker), just pointed the other
  direction (inbound trigger, not outbound push). This does NOT replace
  `export-binfile` — Course 3 already established Penpot's webhook payload
  carries event metadata (which file/team/event type), not the design
  content itself; a webhook firing still means "go pull this specific file
  now" via the same RPC call, just triggered sooner than the next scheduled
  pass.
- **One real, unverified architectural assumption this leans on:** the
  webhook target needs to be reachable **from wherever Penpot's backend
  actually runs**, not from a browser. On this cluster specifically, both
  `apps/penpot` and `apps/nextcloud` are in-cluster services, so this should
  work the same direction Nextcloud already successfully reaches Penpot's
  in-cluster service (confirmed working throughout Course 5) — but that's an
  inference from the existing working direction, not something tested in
  the new direction (Penpot backend → Nextcloud's webhook receiver
  endpoint). Worth a real Test-Cook-style probe before relying on it,
  same posture as every other "confirmed real, not yet exercised" item in
  this saga (`import-binfile` is the closest precedent — real schema,
  untested call).

**Net design shape, as of this section (not fully locked — see the open
questions this raises):** scheduled pull runs primarily as one background
job under the "nextcloud" service-account token, covering every team it's
been invited into; a registered Penpot webhook wakes that job early per
event instead of waiting for the next tick; the residual "team the service
account can't see" case needs the (a)-vs-(b) decision above; per-user tokens
remain load-bearing for the *interactive* click-through/test-connection use
case from §6.9/§6.12, which this section doesn't change.

### 6.17 — The real Test Cook for §6.16's webhook theory: creation confirmed working end to end (two real gates found and closed); delivery is a genuine open anomaly, not yet explained

Command asked to actually test the webhook theory rather than reason about
it further — "can users create them... this is a solid way to get events
back into Nextcloud." Ran the real experiment against the live cluster,
provisioning both sides of the integration **as if the app already
existed** (Command's framing): picked the real future receiver path
(`/apps/penpot_sync/webhook`), allowlisted real candidate hostnames rather
than a disposable one, and only used a throwaway pod as a stand-in for the
one thing that doesn't exist yet — the actual PHP controller.

**Confirmed working, in order, each a real gate that had to be found and
closed:**

1. **Penpot enforces its own SSRF allowlist on webhook targets, and it's a
   strict allowlist once set — not "block private ranges only."** First
   attempt (an in-cluster hostname, not yet allowlisted) failed with
   `blocked-request:uri target is not allowed`. Tried a raw ClusterIP
   instead of a hostname — blocked identically, confirming it's a real
   target check, not a naive string match on hostname patterns. Tried the
   instance's own **public** hostname (`cloud.example.com`) without
   adding it — also blocked. So `PENPOT_SSRF_ALLOWED_HOSTS`, once set to
   anything, allowlists *everything* explicitly listed and blocks
   everything else, public or private — not merely a private-range
   SSRF guard. This is the same mechanism already used for the Keycloak
   OIDC discovery call (`components/keycloak/kustomization.yaml`), now
   confirmed to gate webhook targets identically.
2. **Fixed for real** — added both `nextcloud.cloud.svc.cluster.local` (the
   in-cluster service, avoids a public-ingress round-trip) and
   `cloud.example.com` (the instance's real public identity, already in
   Nextcloud's own `trusted_domains`) to `PENPOT_SSRF_ALLOWED_HOSTS`,
   redeployed. Both are left in place as the real, provisioned config — not
   reverted — since both are legitimate candidate homes for the eventual
   receiver, not test artifacts.
3. **Nextcloud has its own independent gate: `trusted_domains`.** Once
   Penpot's SSRF block was cleared, a direct request to
   `nextcloud.cloud.svc.cluster.local` got a real HTTP response — but it was
   Nextcloud's own **"Access through untrusted domain"** page, not a 404.
   This cluster's `trusted_domains` (checked live: `occ config:system:get
   trusted_domains`) only had `localhost` and `cloud.example.com` — the
   in-cluster service name was never on it. **Not configured declaratively
   anywhere in `apps/nextcloud`** (same out-of-band-provisioning pattern
   already flagged for `groupfolders` in open question #14) — this is a
   second, independent instance of that same gap, worth noting as a pattern,
   not a coincidence. Fixed for real: `occ config:system:set trusted_domains
   2 --value=nextcloud.cloud.svc.cluster.local`, live on the running pod.
   Left in place.
4. **`create-webhook`'s validation is a live HEAD request requiring a real
   2xx — reachability alone isn't enough.** After both gates above were
   cleared, `nextcloud.cloud.svc.cluster.local/apps/penpot_sync/webhook`
   still failed webhook creation (`code: webhook-validation, hint:
   internal`) — because that route doesn't exist yet (zero code), so
   Nextcloud correctly returned a **404**, and Penpot's validation treats
   anything short of 2xx as an invalid target, not merely "reachable."
   Confirmed by testing against `status.php` (a real, always-200 Nextcloud
   endpoint) instead — `create-webhook` succeeded immediately. **Confirmed
   the exact validation mechanism** by watching a throwaway listener's
   request log: Penpot's backend sends a **`HEAD /` request via a Java HTTP
   client** at creation time. This is a precise, previously-unknown
   mechanical fact worth carrying into Chapter 2: **the
   `/apps/penpot_sync/webhook` route must exist, be publicly routable
   through this app's `routes.php`, and answer `HEAD` with 2xx, before
   `create-webhook` will ever accept it as a target** — the receiver has to
   ship before the webhook can be registered at all, not just before it can
   usefully deliver.

**Confirmed real (§5.1's claim, re-verified live in this pass):**
`rename-file` is genuinely tagged `WEBHOOK` in the live `/api/doc` schema
(`['AUTH', 'WEBHOOK', 'SCHEMA']`) — not assumed, checked directly again as
part of this test.

**The open anomaly, stated honestly rather than papered over: the webhook
never actually delivered.** With a real webhook created (`errorCount: 0`,
confirmed via `get-webhooks`), pointed at a listener proven capable of
receiving and logging any HTTP method, two separate real `rename-file`
calls were made against the mapped file — each one a genuine, successful
mutation (`HTTP 200`, `modifiedAt` advanced both times, confirmed in the
response body). **Neither rename produced a POST at the listener.** Waited
up to ~10 seconds each time; checked the backend logs broadly (not just
grepping "webhook") for any dispatch/worker/delivery activity in the
relevant window — found nothing at all, not even a failed-delivery attempt,
which is itself informative: if the webhook task were being enqueued and
failing to send, some trace would be expected in the `webhooks/0` worker
queue's logging (confirmed present and started at backend boot, per §5's
startup log). The absence of *any* trace suggests the event may not be
reaching the dispatch layer in the first place, rather than reaching it and
failing silently — but this is inference, not confirmed root cause.

**Not yet explained, genuinely open — do not assume a cause without a
further probe:** possibilities include (a) webhook delivery has its own
batching/delay window longer than tested, (b) the `WEBHOOK` schema tag
doesn't necessarily mean every instance of that command fires one in
practice (e.g. some additional runtime condition beyond the tag), (c)
something about this specific webhook/team/token combination that isn't
obviously wrong from the outside, or (d) a self-hosted-specific gap not
present on `design.penpot.app`. All cleaned up (test webhook deleted, file
renamed back, throwaway pod removed) before this could be chased further
live — the next probe should extend the wait window significantly, try a
different event type (e.g. a file/project-level event rather than rename),
and check whether the webhook needs to be created by the file/team *owner*
specifically versus any member with access, before concluding anything
about mechanism.

**Bottom line for Command's four original questions, now evidence-based
rather than reasoned-from-docs:** webhook **creation** is real, works, and
required exactly the same "provision both sides ahead of code" work this
section did — SSRF allowlist, trusted_domains, and a route that answers
HEAD. Webhook **delivery**, the actual payoff ("events back into
Nextcloud"), is unconfirmed — worked through creation successfully but hit
a genuine unexplained gap at the delivery step. This doesn't kill the idea
(§6.16's design still stands as reasoned architecture), but it means the
"solid way to get events back into Nextcloud" claim is **not yet proven**,
only "the plumbing for it is real and now provisioned" — the harder half is
still open.

### 6.18 — Decision (locked): the access model — a required service account reads, an optional personal token writes as you

> **Dr K, finally putting the fork down:** *"You've been asking one question
> that's actually two. Who reads and who writes are not the same person here,
> and they never were. Split it and it stops spinning."*

This is the section that closes §6.9, §6.12, and §6.16 — three overlapping,
partially-contradictory positions on the credential question — into one model.
Everything those sections proposed is superseded by this.

**The question, stated properly.** Command's own framing contained the answer:

> *"per user tokens is how penpot does it and that is their model, so i keep
> thinking we need to map to that … but it seems like renaming deleting and
> moving is really the only thing we could do from nextcloud as an action"*

The instinct to mirror Penpot's personal-account model is right — but it was
being applied to the *whole app*, when it only actually matters for **the
actions that write**. And this app barely writes at all. So the real question
is two questions that were being asked as one:

1. **Who reads?** (walking teams, listing files, exporting archives, mirroring)
2. **Who writes?** (the small set of Nextcloud actions Penpot would ever see)

They have different answers. That's why the fork felt unresolvable — it *was*
unresolvable while one credential had to do both jobs.

**The decision:**

| | Service-account token | Personal user token |
|---|---|---|
| **Required?** | Yes — per mapped team | No — opt-in, per user |
| **Configured by** | Admin, once, in Instance settings | Each user, in personal settings |
| **Scope needed** | `viewer` on each mapped team | Whatever that human already has |
| **Job** | All reading: list, export, pull, mirror | Attribution for write actions only |
| **Runs** | The scheduled background pull | Interactive actions the user takes |
| **If missing** | The team cannot be mapped at all | Writes fall back to the service account |

**Why the service account must be required, not optional.** §6.16 found the real
reason and it isn't convenience: if the scheduled pull runs per-user, two
Nextcloud users who are both members of one Penpot team both have mappings
resolving to *the same Team Folder*, and two independent job runs write the same
mirror file with no coordination. That's a genuine data race. One puller, one
credential, one pass — the race cannot occur because there is only ever one
writer.

This makes "invite the service account as `viewer`" a real precondition for
mapping a team. That's a genuine onboarding cost, and it's worth it: §6.12
already established Penpot offers *no* credential an instance-wide view.
Something has to be explicitly brought into scope per-team by someone with
authority over it. Requiring it up front makes that visible instead of producing
a mapping that silently pulls nothing.

**Why the personal token is still worth having.** Command's argument, which is
correct:

> *"like if a user changes a name of a file then we could use the user token
> which would leave their name on the audit trail"*

This is the entire case for per-user tokens and it's a good one. If Nextcloud
renames using the service account, Penpot's history says "nextcloud renamed
this" for every rename by every user, forever. With a personal token it says
"Kelly renamed this" — which is *true*, and which is the point of an audit
trail. The personal token isn't load-bearing for the app to **function**; it's
load-bearing for the app to be **honest about who did what**.

**Why not admin-only, even though Command correctly called it the easy route.**
It is the easy route, and if the personal layer were expensive it'd be the right
call. It isn't expensive, because of §6.19: the personal token has exactly two
call sites. `PenpotClient` accepting a token per call instead of holding one is
a constructor change, not an architecture. The cost of *not* having it is
permanent: Penpot's file history is append-only from our side, so every rename
attributed to a robot can never be re-attributed to the human who did it.

**But it stays optional**, because requiring every user to mint a Penpot token
before renaming a file in the Files app is a terrible first-run experience.

**The write-attribution rule (locked).** For any Nextcloud-originated write:

1. Acting user has a valid personal token that can see the target file → use it.
2. Otherwise → use the service-account token, and surface in the UI that the
   action was performed as the service account.
3. Neither works → the write fails **loudly and locally-recoverably**: the
   Nextcloud-side change stands, Penpot is untouched, the divergence is visible.
   Never silently drop the write; never revert the user's local action to "fix"
   a remote failure.

Rule 3 is Command's don't-lose-data principle applied directly. The next pull
reconciles the divergence by Penpot's authority (§6.22), so the user sees their
rename revert and can retry — recoverable, not destructive.

**Still open within this decision:** whether a personal token should *also*
widen the mirror to teams the service account can't see. **Current answer: no** —
that reintroduces exactly the dual-pull-path complexity §6.16 rejected. Revisit
only if real usage demands it.

### 6.19 — The complete list of what Nextcloud can cause in Penpot

Command's observation — *"renaming deleting and moving is really the only thing
we could do"* — is worth making exact, because the shortness of this list is
what makes §6.18 work.

**⚠️ This list grew in the later passes** (§6.33 creation, §6.34 trash, §6.35
file moves, §6.36 project renames). It is still short, and every entry is still
either **non-destructive** or **explicitly confirmed by a human** — which is the
property that matters, not the count:

| Nextcloud action | Penpot RPC | Destructive? | Status |
|---|---|---|---|
| Move a file between projects (a drag) | `move-files` | No — reversible by dragging back | **§6.35** |
| Create a design | `create-file` | No — additive | **§6.33**, RPC unexercised |
| Restore a design from an archive | `import-binfile` | No — additive | **§6.23** |
| Rename a project folder | `rename-project` | No — reversible | **§6.36**, RPC unexercised |
| Delete in Penpot, bin ON | `move-files` | **No** — fully reversible | **§6.34** |
| Delete in Penpot, bin OFF | `delete-file` | **YES** — confirmed on user action | **§6.34** |
| Purge from the Penpot trash | `delete-file` | **YES** — confirmed on user action | **§6.34** |
| Rename a file | `rename-file` | No | Open fork §6.2 |
| Ordinary move/copy/delete/trash of a mirror | *none* | — | Purely local, §6.1 |

**Exactly one RPC in the entire app destroys anything: `delete-file`**, and it
is only ever reached through a deliberate, explicitly-confirmed user action —
never as a side effect of a file-manager gesture. That, not the length of the
list, is what §6.1's read-only promise actually protects.

Attribution follows §6.18 uniformly for every row: the acting user's personal
token when they have one, the service account otherwise.

### 6.20 — Test Cook: `import-binfile` works, and a deleted file cannot be resurrected

Four open questions were answerable with the token already in hand, so we went
back down. All witnessed live against `penpot.cloud.svc.cluster.local:8080`
(2.17.0), not read off a schema.

**`import-binfile` works — open question #6, closed.** Exported the real "My
firsty", posted the archive back as `multipart/form-data`. Three findings:

1. **The params are kebab-case on the wire** — `project-id`, `file-id` — not the
   camelCase `/api/doc` renders and §5.1 recorded. camelCase returns
   `params-validation` / `:malli.core/missing-key`. Note `export-binfile` and
   `get-project-files` **do** take camelCase (`fileId`, `projectId`), confirmed
   working throughout. **The commands genuinely differ** — this is a per-command
   fact to encode, not a global convention.
2. **`import-binfile` is SSE too**, undocumented anywhere:

   ```
   event: progress
   data: {"~:section":"~:manifest"}

   event: progress
   data: {"~:section":"~:storage-objects"}

   event: progress
   data: {"~:section":"~:file","~:file-id":"~u61d8ecb9-...-6225c5b12134"}

   event: end
   data: ["~ufeb88e73-162b-80dd-8008-62fc60df72c7"]
   ```

   The `end` event carries **an array of resulting file id(s)**, Transit-tagged.
   One SSE+Transit client handles both directions — one mechanism, not two.
3. **The `name` parameter is ignored.** We passed `name=RestoreProbe`; the created
   file came back named **"My firsty"** — the name baked into the archive's
   `manifest.json`. Consequence: **you cannot rename a file by importing it under
   a new name.** A restore needing a different name is `import-binfile` **then**
   `rename-file` — two calls, second can fail independently.

**In-place import (`file-id`) works and does not duplicate.** Posting with
`file-id=<existing>` returned that **same** id, and `get-project-files` showed 2
files before and 2 after — content replaced, no third file created.

**But a deleted Penpot file cannot be resurrected at its original id.** This is
the finding that changes a design:

1. `delete-file {id}` → **HTTP 204**, gone from `get-project-files`.
2. `import-binfile` with `file-id=<the-just-deleted-id>` →

```
event: error
data: {"~:type":"~:not-found","~:code":"~:object-not-found",
       "~:hint":"database object not found"}
```

**HTTP 200 with an `error` event** — the status code lies; the SSE stream carries
the real outcome. Another reason the client must parse the stream, never infer
success from status.

**What it means:** `file-id` is "import *into an existing* file," not "create
this file *with* this id." There's no way to make Penpot accept an id we choose.
Therefore **a Penpot file id is dead the moment the file is deleted in Penpot**,
and **"restore" is inherently create-new-with-new-id** — contents come back,
identity does not. Every deep link and stored `penpot_id` for the old id is
permanently dead. Not a bug to route around; it's the data model, and §6.23 is
designed against it.

**The asset URL: auth-gated, ~24h signed expiry, stable id.** Never tested
before. Without `Authorization: Token …` → **401**; with it → **200**,
`application/zip`, real `PK\x03\x04` bytes. So it is *not* a pre-signed public
handoff. The `x-internal-redirect` header shows the inner GCS URL carries
`X-Amz-Expires=87300` (24h15m). But the **asset id is stable** — re-fetched the
same `/assets/by-id/<uuid>` minutes apart, 200 and identical bytes each time;
the clock is on the inner signed URL, regenerated per request. **Consequence:**
no tight window to engineer around, but never persist the inner signed GCS URL —
persist the asset id and re-request.

All probe artifacts were removed and the instance returned to its prior state
(the probe file deleted, one real file remaining, as before).

### 6.21 — Team Folders carry metadata (§6.14 extended, Command's specific doubt tested)

§6.14 established Files-Metadata *can* attach to folders, proven from the
interface. Command raised the sharper follow-up:

> *"for whatever reason, team folders can't have tags … can we confirm if team
> folders will handle metadata properly"*

Tested against a **real production Team Folder** — `observe`, groupfolder id 5,
fileid 21092, actively shared to four groups — with a write/persist/read-back
cycle through the real `IFilesMetadataManager` API, ordinary home folder as
control:

```
--- TEAM FOLDER root (groupfolder 5) (fileid 21092)
  node class: OC\Files\Node\Folder
  is Folder:  YES
  save: OK
  readback string: 'team-uuid-1234'
  readback int:    42
  keys: penpot_probe,penpot_probe_int

--- ORDINARY home folder (control) (fileid 696)
  … identical results …
```

**Confirmed: a Team Folder root is metadata-identical to an ordinary folder.**
Both probes fully reverted afterward (keys unset, verified empty).

**Why this matters more than the tag question:** whatever the reason tags behave
oddly on Team Folders, **the mapping doesn't need tags** — metadata is the better
mechanism anyway (§6.14's three reasons). This removes the last doubt about the
locked mapping design. Tags stay only for the *human-visible* concerns where
being visible is the point (§6.23's ignore marker).

### 6.22 — Decision (locked): reconciliation — sync vs link comes back, meaning something new

> *"we decided the nxt copy is more like a backup that acts like a link … we
> don't necessarily need realtime backups into nxt, we just need to know when
> the name changes or it's moved … i agree that the mode for sync/link is a good
> idea here"*

§6.1 removed the sync/link axis on the grounds that read-only means one file
state. That reasoning was **correct about writes and wrong about weight.** The
axis returns, meaning something different than in either sibling:

| | n8n / Grafana | **Penpot** |
|---|---|---|
| `sync` | Content in NC, **edits push back** | Content in NC, **read-only backup** |
| `link` | Pointer, read-only | Pointer, read-only, **no archive stored** |
| Axis means | *Which direction writes flow* | *Whether we store the bytes* |

**Neither Penpot mode ever pushes content** — §6.1's read-only lock is untouched.
The axis is purely **"do we pay to store this?"**, the right question for a
format where Course 2 already flagged every export is a full archive with
embedded binaries.

- **`link`** — the default. A lightweight pointer carrying `penpot_id` and
  metadata, deep-linking to the live design. **Never calls `export-binfile`.**
- **`sync`** — opt-in, for files worth backing up. Real archive downloaded and
  stored. Costs a full export whenever `revn` moves.

**Mode is per-file, defaulting per-mapping.** "Which files are important" is a
human judgment that can't be derived: a mapping carries a default (`link` unless
set otherwise); an individual file can be promoted or demoted by the user, stored
in metadata, surviving pulls. Demoting `sync` → `link` **deletes the stored
archive**, so it needs confirmation — but never touches Penpot.

**The pull algorithm** (Command asked for something that handles "a lot or a
little"):

```
for each mapped team (service-account token):
    get-projects(team)                        → 1 call per team
    for each project:
        get-project-files(project)            → 1 call per project
        for each file:
            reconcile name/location           (always — free, from the listing)
            if mode == link:      done        (no further calls, ever)
            if mode == sync and revn/modifiedAt unchanged:  done
            if mode == sync and revn moved:   export-binfile + download
```

**Cost when nothing changed: `1 + P` calls per team, zero exports, zero bytes.**
`get-project-files` returns `revn` and `modifiedAt` for every file in one
response (§5.5), so the drift check needs no per-file call. That's why this
scales. Command's *"we just need to know when the name changes or it's moved"*
is served entirely by that same listing — name and `projectId` are both in it, so
renames and moves reconcile every pull regardless of mode, without a single
export.

**Penpot is authoritative for name and placement (locked).** The rule that makes
reconciliation coherent, and the one the old `move.feature` and
`reconcile.feature` contradicted each other on:

> **Within a mapped folder, Penpot decides what a mirrored file is called and
> which project subfolder it sits in. A pull restores both.**

A user who moves a mirrored file between two mapped project subfolders sees it
**return on the next pull**. That's correct, not a bug — and it's a *placement*
correction, not data loss. But it must be **stated to the user**, not discovered.
Moving a file **out of every mapping** is a different, meaningful act — §6.23.

### 6.23 — Decision (locked): the ignore marker and restore are the same mechanism

Command's items 7 and 8 turn out to be one mechanism viewed from two directions.

> *"adding a special ignore tag would simply treat it like it was unmapped …
> this just means the penpot file is on nxt but taken out of penpot"*
>
> *"if we take a penpot file out of a mapped folder and therefore out of penpot
> we get the .penpot file like a zip in nxt only"*

Both describe one state: **the archive is in Nextcloud, and this app is no longer
mirroring it.** Reached two ways — move it out of a mapped folder, or tag it
`penpot:ignore` in place. Same state, same behavior, one implementation.

**Critically, entering this state never deletes anything in Penpot.** §6.1 is
intact. "Taken out of Penpot" describes the *mirroring relationship* ending, not
a remote deletion. If the user wants it gone from Penpot they delete it in
Penpot; this app never will.

**Ignore is only meaningful on `sync`** (per Command), and §6.22 explains why: a
`link` file has no stored archive, so ignoring one leaves an orphaned pointer
with no content and no purpose. So `penpot:ignore` on a `sync` file is honored;
on a `link` file it's refused, offering to promote it to `sync` first.

**Restore, and the honest version of what it can do.** Here §6.20 bites. The
user's mental model is *"move it back in, it goes back into Penpot."* What's
actually possible depends on whether the original still exists:

| Situation | What restore does | Identity |
|---|---|---|
| Penpot file **still exists** | `import-binfile` with `file-id` → contents replaced in place | **Preserved** — same id, links, history |
| Penpot file **was deleted** | `import-binfile` create-new → a new file appears | **New id.** Old links dead, history gone |

The second row must never be silently performed. Restoring a design whose
original was deleted produces *a new file that looks like the old one* — same
name, same contents, different identity. A user who thinks they undeleted
something has been misled.

**So restore always asks first, and says which of the two it's about to do.**
That's the whole design: a confirmation whose text differs based on a
`get-project-files` check — the difference between an honest feature and a
subtly lying one.

### 6.24 — Decision (locked): the mapping is a team; projects are mirrored, not mapped

> *"you are right that we talked about mapping teams and projects, that was
> before we fully knew the api … so yes let's refine it so teams are the mapping
> and projects come with the team as subfolders"*

Refining §6.13, which described two mapping levels:

- **A mapping is a team.** One object: Penpot team ⇄ Nextcloud Team Folder (or
  shared-folder fallback). The only thing anyone maps, creates, or removes.
- **Projects are not mapped. They are mirrored.** Every project in the team
  appears as a direct subfolder, created and named by the pull. There is no
  "project mapping" to add, configure, or remove.
- **Metadata records both levels** (§6.21): team id on the Team Folder, project
  id on each project subfolder — written by the pull, not by a user.

**What this fixes:** the old `remove-mapping.feature` had a "remove the My Stuff
project mapping" scenario. Under this model that operation doesn't exist — and
never coherently could, since the next pull would immediately recreate the
subfolder. One mapping object, one lifecycle.

### 6.25 — Failure modes, enumerated (Command's item 4)

This chapter specified almost no error behavior until now. The SSE +
two-phase-fetch shape means more failure surfaces than either sibling. **The
governing rule is Command's: don't lose data. A remote failure must never destroy
local state.**

**`export-binfile` SSE failures:**

| Failure | Detection | Behavior |
|---|---|---|
| `event: error` mid-stream | Parse Transit `~:type`/`~:code` | Log, skip file, **keep existing mirror**, continue |
| Stream ends with no `end` | No `end` seen at close | Same as above |
| **HTTP 200 but error event** | §6.20 — status lies | **Always parse the stream** |
| `includeLibraries`+`embedAssets` both true | Pre-flight | Never send — penpot#7649 |

**Asset download failures.** Per §6.20 the asset id is stable ~24h, so these are
genuinely transient: retry with backoff; on give-up keep the existing mirror and
log. A failed download **never** truncates or deletes the file already on disk.

**Partial-pull safety.** A pull dying halfway must leave every already-written
file valid: write archives to a temp location and move into place atomically — a
file is either the old version or the new one, never a half-written ZIP.

**Pruning requires a clean listing.** If `get-project-files` *failed*, everything
looks gone. **Never prune on a failed or partial listing** — the single most
dangerous operation in the app, and the one most likely to destroy user data via
a transient network error.

**Write failures (rename, restore).** Per §6.18 rule 3: local change stands,
Penpot untouched, divergence visible, next pull reconciles by §6.22. For the
two-call restore (§6.20), a successful import followed by a failed rename leaves
a correctly-restored file with the wrong name — report partial success naming
both halves; don't roll back the import.

**Credential failures:**

| Failure | Behavior |
|---|---|
| Service token unset | Mappings can't be created; existing ones stop pulling with a clear reason |
| Service token expired/revoked | Pull halts, admin notified. **No pruning** — auth failure isn't evidence of deletion |
| Personal token invalid | Fall back to service account per §6.18, tell the user |
| Service token lost team access | That mapping halts, others continue. Never prune |

The repeated "never prune" is deliberate: every credential failure looks
identical to "everything was deleted" from the pull's perspective, and the
difference between the two is the difference between a working app and one that
deletes a user's backups because a token expired.

### 6.26 — ~~Test Cook: Penpot HAS a trash — but the API cannot reach it~~ — **WRONG, corrected by §6.49**

> **⚠️ THE CONCLUSION BELOW IS WRONG.** The API *can* reach the trash:
> `get-team-deleted-files`, `restore-deleted-team-files` and
> `permanently-delete-team-files` all exist and work (verified live, §6.49). My
> probe guessed command names and found none; the real ones are **team-scoped**.
> The 7-day soft-delete *finding* is correct and useful — only the "unreachable"
> verdict is wrong.

> **Dr K:** *"does penpot have a trash bin or soft delete or archive?"*

Worth answering properly, because the answer is "yes, and it doesn't help us,"
which is a more useful finding than either a plain yes or a plain no. Probed four
ways, ending at the database because the API alone was misleading.

**1. No trash-shaped RPC command exists.** Probed for `restore-file`,
`get-deleted-files`, `get-trash`, `archive-file`, `set-file-archived`,
`undo-delete-file`, `restore-deleted-file`, `untrash-file`, `undelete-file`,
`recover-file` — **every one 404s.** (A real command with bad params returns
400 with a schema; 404 means the command genuinely doesn't exist. `delete-file`,
`duplicate-file`, `move-files`, `move-project` all return 400 against the same
probe, confirming the technique distinguishes correctly.)

**2. `delete-file` looks permanent from the API's side.** After a real
`delete-file` (HTTP 204): gone from `get-project-files`, and `get-file` on the id
returns `{"type":"not-found","code":"object-not-found","table":"file"}`.

**3. But the database says the deletion is SOFT — and scheduled.** Queried
Penpot's own Postgres directly:

```
select name, deleted_at, deleted_at - now() as grace from file where deleted_at is not null;

CopyProbe   | 2026-08-02 16:02:21+00 | 6 days 23:58:39
My firsty   | 2026-08-02 14:43:43+00 | 6 days 22:40:01
```

**`deleted_at` is set roughly 7 days in the FUTURE.** It isn't "when this was
deleted" — it's **when the purge worker will actually remove it**. The row, its
`file_data`, its `file_change` history, and its `storage_object`s are all still
there for a week. `deleted_at` exists on 29 tables (`file`, `file_data`,
`file_change`, `project`, `team`, `storage_object`, …), so the same deferred-GC
pattern covers projects and teams too. This also explains the 16 worker tasks /
8 cron tasks the backend registers at boot.

**4. The grace window is real but unreachable.** A soft-deleted file is still
*addressable* by mutating RPCs — `move-files` on one returned HTTP 204 and
actually changed its `project_id` — but it stays **invisible to every listing**
(`get-project-files` on the destination returned 0 files) and `deleted_at` is
never cleared. So there is no API path back: nothing reads it, nothing restores
it, and the purge still fires on schedule.

**What this means for us, stated honestly:**

- **Penpot's 7-day grace exists for Penpot's own UI**, which presumably clears
  `deleted_at` through a route we don't have. We cannot offer "restore from
  Penpot's trash" as a feature, because no API command does it.
- **§6.20's finding stands and gets sharper.** A deleted file can't be
  resurrected at its original id *by us* — now known to be because the row is
  alive but unreachable, not because it's gone. The practical consequence is
  identical: our restore is create-new-with-new-id.
- **It does buy the USER a real safety net we should document rather than
  own:** if someone deletes a design in Penpot and notices within a week,
  Penpot itself may be able to recover it. Our README should say so, and say
  it's Penpot's mechanism, not ours.
- **Direct DB manipulation is not on the table.** Clearing `deleted_at` in
  Postgres would "work" and is exactly the kind of thing this app must never do
  — unsupported, version-fragile, and outside every boundary the rest of this
  design respects.

### 6.27 — ~~Decision (locked): the recycle-bin idea is REJECTED~~ — **REVERSED by §6.34**

> **⚠️ THIS SECTION'S CONCLUSION IS WRONG AND HAS BEEN OVERTURNED.** §6.34
> adopts the trash bin (opt-in, off by default). The error here was comparing
> "move the file" against *doing nothing*, when the real alternative is
> `delete-file` — which §6.20/§6.26 already proved is irreversible for us. The
> **evidence** below (cross-team moves work, 204, `teamId` updates
> automatically) is correct and is what §6.34 builds on. Read the mechanism,
> ignore the verdict.

> **Dr K:** *"maybe have a trash project in the SAs personal team … we would
> need to prove we can move a file from one team to another"*

**The mechanism is proven.** Duplicated a real file, then moved it from the
`Ferronescotia` team's project into the `Default` (personal) team's Drafts
project via `move-files` — **HTTP 204**, and the file appeared in the
destination listing with its `teamId` **updated automatically** to the new team.
Cross-team file moves work, in one call, with no re-import. (`move-project`
similarly takes a `team-id`, so whole projects can be reparented too.)

**And we're still not going to do it.** The idea was to have this app move a
"deleted" design into a service-account-owned trash project instead of deleting
it, mirroring Grafana's `RecycleBin.php`. Reasons it's the wrong call here:

1. **It contradicts §6.1 in the one direction that matters.** This app's
   central promise is that *no Nextcloud action ever mutates Penpot
   destructively*. Moving a user's design out of their team into a robot's
   private team is one of the most destructive-feeling things we could do — it
   would vanish from their team for every other member, with no notice.
2. **Grafana's bin exists because Grafana has NO trash.** Penpot *does* (§6.26,
   7 days). We'd be reinventing a worse version of something upstream already
   provides, and the two would interact confusingly.
3. **We never originate the delete anyway.** Under §6.1, Nextcloud-side deletes
   are purely local — there is no delete in Penpot for us to intercept. A
   recycle bin is a solution to a problem this app doesn't have.
4. **Nextcloud's own trash already covers the local side**, which is the only
   side we own.

**Status: rejected, with the mechanism documented** in case a future chapter
finds a legitimate use for cross-team moves (e.g. a user-initiated "move this
design to another team" feature, which would be *their* action, not ours).

### 6.28 — Decision (locked): `duplicate-file` is real — copies are a first-class Penpot operation

> **Dr K:** *"does penpot have an endpoint for this? … if we do copy do we need
> to do an import?"*

**No import needed. `duplicate-file` exists and works.** Confirmed live:

```
POST /api/rpc/command/duplicate-file   {"fileId": <uuid>, "name": "CopyProbe"}
→ 200, full file record: new id, same teamId, revn preserved, name honoured
```

Schema: `{file-id: uuid, name?: string(max 250)}`. Two notes that matter:

- **It takes camelCase `fileId` on the wire** (verified — the call above
  succeeded), unlike `import-binfile`'s kebab-case. §6.20's warning about
  per-command casing is now confirmed across three commands rather than two.
- **Unlike `import-binfile`, it DOES honour `name`.** We asked for "CopyProbe"
  and got "CopyProbe". So a Penpot-side copy is one call, not the
  import-then-rename pair a restore needs.

**Design consequence — and it does NOT change copy.feature's local behaviour.**
A Nextcloud copy still creates nothing in Penpot (§6.1: we don't originate
objects from an ordinary file-manager gesture — a user dragging a file with
Ctrl held is not asking to create a design). What this finding does is make a
*deliberate, explicit* "Duplicate in Penpot" action **cheap and safe to build if
ever wanted** — one call, no archive round-trip, no id collision. Recorded as
available, not adopted.

### 6.29 — Decision (locked): nesting is flexible in Nextcloud, because membership is a nearest-ancestor lookup

> **Dr K:** *"let's get funky with nesting … as long as the folder itself has
> the link to penpot project id then it technically should not matter the
> nesting or location in nxt … we would always simply look up to the nearest
> folder with a penpot project id"*

This supersedes §6.13's "exactly one level, hard cap" and the corresponding
language in §6.24. The cap was a legibility guess made before we understood how
cleanly folder metadata resolves; the nearest-ancestor rule is both simpler to
implement and far more useful.

**The rule, complete:**

> A `.penpot` file belongs to the Penpot project recorded on **the nearest
> ancestor folder carrying a project id**. A project folder belongs to the team
> recorded on **the nearest ancestor folder carrying a team id**. If no such
> ancestor exists, the file belongs to no mapping.

**Why this is strictly better than the cap:**

- **It matches the asymmetry between the two systems honestly.** Penpot is flat
  and rigid (team → project → file, no sub-projects). Nextcloud is a
  hierarchical file manager people organise however they like. Forcing
  Nextcloud to be as flat as Penpot imposes Penpot's limitation on a system that
  doesn't share it, for no gain.
- **It costs nothing to implement.** "Walk up until you find a folder with the
  key" is the same lookup as "check exactly one level up," just without an early
  exit. Both siblings' resolvers already walk ancestors.
- **Moves become uniform.** Moving a file anywhere — up, down, sideways —
  resolves the same way: find the nearest project-id ancestor at the
  destination. There is no special "too deep" case to define or explain,
  which removes a whole category of confusing state (the old
  move.feature had to invent "tolerated content because it's one level too
  deep," a rule with no counterpart in either system).
- **Ordinary Nextcloud folders can freely group project folders**, which is the
  real user need: a `Clients/` folder holding five project folders is natural in
  Nextcloud and impossible in Penpot.

**The initial layout is unchanged** — a pull drops project folders one level
under the team folder, exactly as §6.24 said. What changes is that the user may
then **move them around freely**, and everything keeps working because identity
lives in metadata, not in path.

**Placement authority is narrowed accordingly** (revising §6.22): Penpot remains
authoritative for a file's **project membership**, but **Nextcloud is
authoritative for folder layout**. A pull no longer drags a file back to a fixed
path — it only ensures the file sits under *a* folder mapped to its real Penpot
project. If the user moved that project folder somewhere else, the file follows
it there. This kills the "in-mapping moves silently revert" awkwardness §6.22
had to apologise for.

### 6.30 — Decision (locked): project folders may not leave their team folder — for now

> **Dr K:** *"this might get weird and tricky to move a project folder outside
> of a team folder … for now don't let projects move out of teams."*

Agreed, and there's a concrete reason beyond caution. Moving a project folder
out of its team folder would mean one of:

- **Reparenting the project in Penpot** (`move-project` with a new `team-id` —
  confirmed real in §6.27) — a genuinely destructive, cross-team mutation that
  changes who can see the work. Far outside §6.1.
- **Silently desyncing** — the folder keeps its project id but now resolves to
  the wrong team, or no team. Ambiguity with no good answer.

So: **a project folder may move anywhere *within* its team folder** (§6.29 makes
this free and meaningful), and **may not move outside it**. The attempt is
refused with an explanation, not silently undone. Penpot is never contacted.

Revisit only once everything else is mature — and if it's ever built, it should
be an explicit "move this project to another team" action with a confirmation,
not a side effect of a drag in the Files app.

### 6.31 — Decision (locked): personal projects mount at the user's home root, and this is the one project without a team folder

> **Dr K:** *"the personal team is special and doesn't need a folder to itself …
> i think in the personal token perspective, we map the projects directly to the
> root of the users home."*

This is the one place the personal token does more than attribute a write
(§6.18), so it's worth stating precisely.

**The shape:**

- Every Penpot account has a **"Default" personal team** with `isDefault: true`,
  auto-provisioned 12ms after the account itself (§6.9 — real, confirmed).
- That team gets **no team folder**. Wrapping a personal team in a shared Team
  Folder would be actively wrong — it's a *personal* space, and a Team Folder is
  a *sharing* primitive.
- Instead, **its projects mount as folders at the root of the user's Nextcloud
  home**, each carrying its Penpot project id as metadata exactly like any other
  project folder.
- Everything else works identically: files inside resolve by §6.29's
  nearest-ancestor rule, and the user can move those folders anywhere in their
  home.

**This is a real exception to §6.29's second clause, and it must be written down
rather than discovered:** a personal project folder has **no team-id ancestor**.
Resolution therefore needs an explicit fallback: *no team ancestor + a project
id owned by the acting user's personal team = a personal project.* Without that
rule, the natural implementation ("walk up for a team id; none found = broken
mapping") would treat every personal project as an error.

**Whose token does this use?** The user's own, necessarily — the service account
is not a member of anyone's personal team and never can be (§6.12: no credential
gets an instance-wide view). So personal projects are the one part of the mirror
the service account cannot see or pull.

**Consequence for the pull (a real gap, named):** §6.18 locked "one puller, the
service account," specifically to avoid a shared-Team-Folder write race. That
reasoning **does not apply to personal projects** — a user's home folder has
exactly one writer by construction, so there is no race to prevent. A per-user
pull for personal projects only is therefore safe. But it is a **second pull
pathway**, which §6.18 deliberately avoided, and it should be built only after
the primary team pull works. Marked as a follow-on, not day-one scope.

### 6.32 — Decision (locked): a project folder is marked with a visible tag

> **Dr K:** *"make sure the projects get the special tag that says it is actually
> in penpot … this is the feature that allows normal nxt folders under a team
> without needing to be a project … this also makes it visual and easy to find
> them with a search."*

Right on all three counts, and it fits the existing split cleanly:

- **Metadata** (folder-level, §6.21) is the *authoritative machine* record — the
  project id, used for every lookup.
- **A system tag** is the *human-visible* marker — it shows as a pill in the
  Files app, it's searchable/filterable, and it's how a user tells "this folder
  is a Penpot project" from "this is a folder I made to organise things."

Same division already established for the ignore marker (§6.23): machine state
in metadata, human-facing state in tags. Both point at the same truth; neither
is derived from the other at runtime.

**Why the visible marker matters more under §6.29 than it would have under the
old cap:** once folders can nest freely and ordinary folders can sit among
project folders, "which of these is real?" stops being answerable from position
alone. The tag is what keeps a deeply-nested tree legible.

**The tag is app-owned and not a user input.** A user applying it by hand does
NOT create a Penpot project — that's the still-open creation fork (§6.7/§6.15),
and nothing here ratifies it. The pull stamps the tag on folders it created or
adopted; a hand-applied tag on an unmapped folder is inert.

### 6.33 — Decision (locked): create-in-Nextcloud is scoped to where it's unambiguous, and Drafts is where it lands otherwise

> **Dr K:** *"we do want a create option in the new button just like n8n and
> grafana … however it does not seem to make sense to do this outside of a
> project folder or team folder … if a file is at the root of a team then it's
> not in a project and therefore in the drafts project which is invisible to nxt."*

This resolves the §6.7 "unmapped-create lands in Drafts" idea into something
concrete and much narrower, and it's the first real ratification of a
Nextcloud-originates-a-Penpot-object path.

**Where "New → Penpot design" appears, and what it does:**

| Location | Behaviour |
|---|---|
| Inside a **project folder** | Creates the design in that Penpot project. Unambiguous. |
| Inside a **team folder** (not in a project) | Creates it in that team's **Drafts** project |
| Inside a plain folder **under a team folder** | Same — that team's Drafts |
| Anywhere with **no team ancestor** | The action is **not offered** at all |

**Why Drafts is the right landing zone rather than an error:** it's Penpot's own
answer to the same question. Every team auto-provisions a `Drafts` project with
`isDefault: true` (§6.6, confirmed on every team live), and it's exactly where
Penpot's own UI puts a design created outside any project. We're not inventing a
convention; we're matching theirs.

**The asymmetry to document, because it will surprise someone:** Drafts is a
real Penpot project, so under §6.29 it *would* normally be mirrored as a folder.
Dr K's framing — "the drafts project which is invisible to nxt" — is the right
call for the team-folder-root case: a file created at a team folder's root lives
in Drafts in Penpot, but stays visually at that root in Nextcloud rather than
jumping into a `Drafts/` folder the user didn't ask for. **Open sub-question:**
whether Drafts is *also* mirrored as an ordinary visible folder (for designs
created in Penpot's own UI and left in Drafts), and if so, whether a file can
appear to be in two places. Flagged, not resolved — see open question #29.

**This is still gated on the creation carve-out.** §6.1 forbids Nextcloud
originating Penpot content; §6.23 already carved out restore. Creation is a
second carve-out and Dr K has now asked for it explicitly, so it's ratified in
principle — but `create-file` has never been called live (unlike
`import-binfile`, §6.20), so it stays `@todo` until it is.

### 6.34 — ~~Decision (locked): an opt-in trash project~~ — **premise removed by §6.49**

> **⚠️ THIS SECTION'S PREMISE IS GONE.** It exists because §6.26 concluded
> Penpot's own trash was unreachable, making a service-account trash project
> "the only way to make delete reversible." §6.49 disproved that: Penpot's trash
> restores id, history and links — strictly better than moving a design into a
> robot's private team. **This design should be dropped**; pending the same
> rewrite pass as §6.50.

> **Dr K:** *"we can't rely on the penpot trash in their api … the trash bin
> feature would be opt in … moving from some team to the SAs personal team in a
> mapped trash project keeps all history and ids … if we don't do this, which is
> the same as disabling the trash bin, then we can only fully delete on penpot
> side."*

**§6.27 rejected this idea. §6.27 was wrong, and the reasoning that overturns it
is Dr K's second clause: it isn't a choice between "trash bin" and "nothing" —
it's a choice between "trash bin" and "permanent, id-destroying deletion."**

Where §6.27's reasoning failed, precisely:

- **It argued the mechanism was too destructive.** But it compared moving a file
  against *doing nothing*, when the real alternative is `delete-file` — which
  §6.20/§6.26 already proved is **irreversible for us**: the id dies, the deep
  links die, the history becomes unreachable, and our "restore" degrades to
  create-a-look-alike. Moving a file preserves **everything**. Between the two,
  the move is dramatically *less* destructive.
- **It leaned on Penpot's own 7-day grace period as the safety net.** §6.26
  established that grace period is **unreachable through the API**. Citing a
  recovery path this app cannot invoke, as the reason not to build a recovery
  path this app *can* invoke, was the actual error.
- **The "it vanishes for the rest of the team" objection still stands — but it
  describes deletion itself, not this mechanism.** When a user deletes a design,
  it is *supposed* to disappear for the team. That's the point. The question is
  only whether it disappears **recoverably** or **permanently**.

**Proven end to end, not reasoned about.** Round-tripped a real file:

```
duplicate-file          → new file in "My Stuff",  revn 5
rename-file             → "TrashRoundTrip-Edited", HTTP 200
move-files → personal team's project   → HTTP 204   (the "trash")
move-files → back to "My Stuff"        → HTTP 204   (the "restore")

after: same id, name "TrashRoundTrip-Edited", revn 5, correct project
```

**Same id, same name, same revision, same history rows.** A trash round-trip is
lossless in a way `delete-file` + `import-binfile` can never be.

**The design (opt-in, admin-configured — mirroring Grafana's `RecycleBin.php`
shape exactly):**

- An admin enables the trash bin and names a **trash project** inside the
  **service account's own personal team** — a space no ordinary user is a member
  of, so a trashed design genuinely disappears from the team's view.
- **Deleting** a mirrored design (a deliberate "Delete in Penpot" action, not an
  ordinary Nextcloud file delete — §6.1 still holds for those) moves it there.
  To the team, it's gone.
- **Restoring** moves it back to its original project. Everything survives
  because nothing was ever destroyed.
- **Purging** from the trash is what finally calls `delete-file`. That's the
  irreversible step, and it's explicit.
- **With the bin disabled** (the default), a delete is a real `delete-file`, and
  restore degrades to §6.23's lossy create-new path. The setting's honest
  description is: *"without this, deleting is permanent."*

**What matters is the restore, not the stay — Dr K's clarification, and it
simplifies the design:**

> *"we don't really care about the file 'working' in our trashbin — as long as
> it works after we restore it doesn't really matter what state it's in in the
> trash."*

Exactly right, and it dissolves most of what looked like a caveat. A trashed
design is **not expected to function while trashed** — it's expected to *exist*
so it can come back. Nobody opens a design in the bin; they restore it first.
So "does this render correctly while parked in the service account's team" is a
question we don't have to answer.

**One caveat that survives, narrowed to the restore itself:** `file_library_rel`
is keyed on file ids only (checked: `file_id, library_file_id, created_at,
synced_at`), so the relation **rows** survive the round trip intact. Penpot
scopes library *visibility* by team, so a **shared** library (`is_shared: true`)
may not resolve for its consumers *while it sits in the bin* — which by the rule
above is fine — and should resolve again once restored to its original team.
Worth one real test with an actually-shared library before the feature ships, but
it is not a blocker and not a reason to warn on every trash.

**The one thing we genuinely must get right: record the origin ourselves.**
Penpot does not remember where a file came from. The origin project id goes into
the Nextcloud file's metadata at trash time, or restore has nowhere to put it
back. This is our bookkeeping, not Penpot's.

**Status: §6.27 is superseded. The trash bin is adopted, opt-in, off by default.**

### 6.35 — Decision (locked): Drafts is a *state*, not a folder — and it's where Nextcloud gets flexibility Penpot lacks

> **Dr K:** *"drafts just represents the case that a file is in a team but not
> within a project … this gives flexibility beyond penpot, penpot needs drafts
> because there is not much else it could do."*

This corrects §6.33's open sub-question (and closes saga open question #29).
**Drafts is never mirrored as a folder.** It is the *name Penpot gives* to the
condition "this design belongs to a team but sits in no project."

**The mapping between the two systems' vocabularies:**

| Nextcloud location | Penpot reality |
|---|---|
| Inside a project folder | In that project |
| At a Team Folder's root | In that team's **Drafts** |
| In any plain (non-project) folder under a Team Folder | In that team's **Drafts** |

So "in Drafts" is simply **"has a team ancestor but no project ancestor"** — a
direct application of §6.29's nearest-ancestor rule, needing no new machinery.

**Why this is better than Penpot's own model, which is the interesting part.**
Penpot has *one* Drafts bucket per team because a flat system has nowhere else to
put an unfiled design. Nextcloud has folders — so the same "not in a project"
state can be expressed as **any arrangement of ordinary folders the user likes**,
all of which map to Drafts on Penpot's side. A user gets `Team/Inbox/`,
`Team/Scratch/2026/`, `Team/Unsorted/`, and Penpot sees one coherent Drafts
project. **We are strictly more expressive than the system we mirror**, at zero
cost, because the flat side doesn't need to know.

**And filing a draft is a drag.** Moving a file from a team folder's root into a
project folder means: nearest project ancestor changed from *none* to *that
project* ⇒ call `move-files`. The gesture Nextcloud users already know *is* the
Penpot operation. Same in reverse: dragging a design out of a project folder but
still under the team un-files it back to Drafts.

**This makes file moves the third Nextcloud→Penpot write path** (after rename and
restore/create — §6.19). It's a deliberate widening of §6.1, justified because
`move-files` is non-destructive, one call, and instantly reversible by dragging
back. Note the asymmetry with **project folders**, which still may not move
between teams (§6.30): moving a *file* between projects is cheap and safe;
moving a *project* between teams changes who can see a whole body of work.

**Open, minor:** whether a design already sitting in Penpot's Drafts appears at
the Team Folder root on pull. Consistency says yes, and §6.29 makes it fall out
naturally. Recorded so it isn't discovered as a surprise.

### 6.36 — Decision (locked): a project folder's name always equals its Penpot project name

> **Dr K:** *"we don't want to allow a folder in nxt to have a different name
> than the corresponding project … this makes the tag earn it's keep, simple tag
> becomes same name as folder project in penpot."*

§6.13 point 3 already made mapped-folder naming Penpot-authoritative. This
extends it to project folders under free nesting, and makes it a two-way
invariant:

> **A folder carrying a Penpot project id is named exactly what that project is
> named in Penpot. Renaming either side renames the other.**

- **Penpot → Nextcloud:** the pull renames the folder. Already how team folders
  work (§6.13).
- **Nextcloud → Penpot:** renaming a project folder calls `rename-project`
  (confirmed real — returns 400-with-schema, not 404). Since names must agree,
  the only coherent outcomes are "propagate" or "refuse," and propagating is
  what a user renaming a folder plainly intends.
- **Position stays free** (§6.29). Only the *name* is constrained, not the
  location. You can put a project folder anywhere in the team; you just can't
  call it something else.

**Why this earns the tag its keep, which is Dr K's real point.** Under free
nesting, a project folder is otherwise indistinguishable from an ordinary folder
someone happened to name the same thing. The tag is what makes it legible: a
tagged folder named "Acme" **is** the Penpot project "Acme" — no ambiguity, no
divergence, and a search for the tag lists exactly the real projects.

**Note this is the inverse of the FILE rename fork (§6.2), and deliberately so.**
A file's name is cosmetic and one-way propagation is debatable. A project
folder's name is *identity-bearing* under this rule — divergence would break the
legibility the tag exists to provide. So project-folder renames propagate;
file renames remain open.

**Naming collisions** are the one wrinkle: Penpot permits two projects in a team
with the same name, Nextcloud does not permit two sibling folders with the same
name. Since position is free, the second folder can simply be created elsewhere —
but the pull needs a defined behaviour rather than a crash. Flagged as open
question #31.

> **⚠️ One real exception, added by §6.38.** Penpot accepts project names
> Nextcloud cannot use as folder names — confirmed live, `Has/Slash` creates
> fine. For such a project the invariant **cannot** hold: the folder name is
> sanitised, the **project id remains authoritative**, and the app reports the
> divergence rather than pretending the names match.

### 6.37 — Decision (locked): the reconciler is Nextcloud-trash-aware

> **Dr K:** *"let's say we deleted a file, then i restored from the ui … our
> reconciler should realize that the id of some file that should be in nxt is in
> nxt but in the trash and therefore we are restoring from nxt trash back to a
> full sync."*

A gap nothing covered. Every prior section treated the Nextcloud trash as a
terminal state — files go in, and the reconciler forgets them. That produces a
real bug: restore a mirrored file from Nextcloud's trash and the pull, seeing a
design with no mirror, **creates a second copy**. The user restores one file and
ends up with two.

**The rule:** before creating a mirror for a Penpot design, **the reconciler
checks the Nextcloud trash for a file carrying that `penpot_id`.** If one is
there, it is **restored and re-adopted** rather than duplicated.

This also gives the user's own manual restore the obvious meaning: pulling a file
out of the Nextcloud trash *is* how you undo a local delete, and the next pull
simply confirms it's current or refreshes it. Both directions land in the same
place, which is the sign the rule is right.

**Interaction with §6.34's Penpot-side trash — worth stating because they can
both be true at once.** They are independent layers:

- The **Nextcloud trash** holds the local mirror. Restoring from it recovers the
  *file in Nextcloud*.
- The **Penpot trash project** holds the design. Restoring from it recovers the
  *design in Penpot*.

A design deleted in both places needs both restores, and the UI should say which
layer it's acting on rather than implying one button fixes everything.

### 6.38 — Test Cook: `create-project` and `rename-project` both work, and the name rules run the OPPOSITE way from expected

> **Dr K:** *"we also need to verify we can rename a project in penpot."*

Done — both exercised live for the first time (closing open question #33).

**`create-project`** — `{team-id, name}`, **kebab-case**, returns the full
project record:

```json
{"id":"feb88e73-…","teamId":"4eda2e11-…","name":"RenameProbe",
 "isDefault":false,"isPinned":false,"createdAt":"2026-07-26T17:03:40Z"}
```

**`rename-project`** — `{id, name}`, **HTTP 204**, no body. Verified by reading
the project back renamed. Note the casing split *again*: `create-project` takes
`team-id` (kebab) while `rename-project` takes `id` (no compound word to
disagree about). Four commands now confirmed inconsistent — this must be encoded
per command, never inferred.

**The name-validation finding, and it inverts Dr K's concern.** The schema says
`[:string {:max 250, :min 1}]`, and testing what Penpot *actually* accepts:

| Name | Penpot | Nextcloud folder? |
|---|---|---|
| `lower case name` | ✅ 200 | ✅ |
| `UPPER CASE` | ✅ 200 | ✅ |
| `emoji 🎨 name` | ✅ 200 | ✅ |
| `dot.name` | ✅ 200 | ✅ |
| `  leading space` | ✅ 200 | ⚠️ trimmed/awkward |
| **`Has/Slash`** | **✅ 200** | **❌ IMPOSSIBLE** |
| `` (empty) | ❌ 400 | ❌ |

**Penpot is more permissive than Nextcloud, not less.** Casing doesn't matter to
Penpot at all — it accepts essentially any non-empty string up to 250 chars,
**including `/`**, which can never be a Nextcloud folder name. So the guard Dr K
asked for is real and needed, but it protects the **Penpot → Nextcloud**
direction, which is the direction we can't refuse:

- **Nextcloud → Penpot** (tagging a folder, renaming a project folder): whatever
  a user can name a folder, Penpot will accept. The only real check is
  non-empty. **A guard here is cheap and mostly reassurance.**
- **Penpot → Nextcloud** (the pull): a project named `Has/Slash` **cannot** be
  mirrored as a folder of that name. This is the case that will actually break,
  and it needs a defined behaviour — sanitise the folder name while keeping the
  project id authoritative, and report the divergence, because §6.36's
  names-always-match invariant genuinely cannot hold for such a project.

**This is a real exception to §6.36** and is recorded as such rather than
discovered in production: names match *except* where Nextcloud's filesystem
rules make it impossible, in which case the id remains the source of truth and
the app says so.

**Also confirmed: `delete-project` is soft, exactly like `delete-file`.** Eight
probe projects deleted (204 each) still appeared in `get-projects` immediately
after, and the database showed all eight with `deleted_at` ≈ **7 days out** —
same deferred-purge pattern as §6.26. Two consequences: **(a)** the pull must not
assume a 204 means the project vanishes from the next listing; **(b)** §6.26's
grace-period caveat applies to projects too, not just files.

> **⚠️ Follow-up in §6.42: the "still appeared in `get-projects`" part is an
> upstream BUG, not a feature.** `get-projects` doesn't filter `deleted_at`,
> while `get-all-projects` does, `get-project` 404s, and `get-project-files`
> returns `[]`. Don't build anything on that listing — treat
> `get-all-projects`/`get-project` as authoritative.

### 6.39 — Decision (locked): renaming a project folder is its own flow, not a variant of file rename

> **Dr K:** *"i think this needs it's own special case for renaming … it would
> be all the same flow and all as renaming a file but i'm thinking the events
> and specifics in the code may be a bit different, like the endpoint to rename
> a project."*

Correct, and worth separating explicitly so an implementer doesn't try to reuse
one listener for both. The *shape* rhymes; nearly every specific differs:

| | Rename a **file** | Rename a **project folder** |
|---|---|---|
| NC event | `NodeRenamedEvent` on a file | `NodeRenamedEvent` on a **folder** |
| Which nodes | Has `penpot_id` metadata | Has `penpot_project_id` metadata |
| RPC | `rename-file` `{id, name}` | `rename-project` `{id, name}` |
| Response | 200 + `SimplifiedFile` | **204, no body** |
| Extension | Must strip/re-add `.penpot` | **No extension** — folder names are bare |
| Decided? | **Open fork (§6.2)** | **Locked — propagates (§6.36)** |
| Why | A file's name is cosmetic | Identity-bearing; divergence breaks the tag |
| Name guard | Penpot accepts anything | Same — but see §6.38's reverse direction |

The two must not share an implementation path. In particular: the file flow has
to reason about the `.penpot` extension (renaming `A.penpot` → `B.penpot` means
sending `B`, not `B.penpot`), and the folder flow has none of that but *does*
have to reject empty names and handle the sanitisation exception from §6.38.

**And a tagged-folder creation guard follows the same shape** (Dr K's follow-up):
when a user tags a plain folder to make it a Penpot project, the app validates
the folder's name is acceptable *before* calling `create-project` — non-empty,
trimmed, no filesystem-hostile leftovers. If it fails, **refuse with an
explanation and leave the tag off** so the user can rename and re-tag. Same
guard, same place, for both `create-project` and `rename-project`. (The creation
half remains gated on the still-open §6.7/§6.15 fork; only the guard's shape is
settled here.)

### 6.40 — Decision (locked): copying a project folder is DISABLED, for a reason that generalises

> **Dr K:** *"maybe this is a disabled for now action as it could get tricky with
> the recursive lookups and actions and fact that technically we could have a n8n
> workflow and a grafana dashboards mapped to a folder that is a penpot project."*

That last clause is the sharpest observation in this round, and it's not
hypothetical: **all three sibling apps are installed on the same Nextcloud.** A
single folder can plausibly carry `penpot_project_id`, an n8n tag binding, *and*
a Grafana folder mapping simultaneously. Copying such a folder asks three
independent apps to decide what a duplicate means, with no coordination between
them.

Even confined to this app, copying a project folder is genuinely ambiguous:

- The copy carries `penpot_project_id` — so two folders claim the same project
  (already flagged as open question #30, now reachable by an ordinary gesture).
- Under §6.29's nearest-ancestor rule, every file in the copied tree resolves to
  the same project as the original — a whole tree of duplicate claims, not one.
- Does the copy mean "make a new Penpot project"? Almost certainly not what a
  user dragging a folder intends — and inventing a project as a side effect is
  precisely what §6.1 forbids.

**Decision: copying a folder that carries `penpot_project_id` is refused**, with
an explanation. Not silently stripped, not silently allowed — refused, because
either alternative produces a state the user didn't ask for and can't easily see.

**Deliberately narrow:** copying ordinary folders (even inside a Team Folder) and
copying individual `.penpot` files are both unaffected — the file rule from
`copy.feature` (strip the id, keep the bytes) already handles those cleanly.

**The name-collision point Dr K raised is the tell.** Nextcloud auto-increments a
copy to `Acme (2)`, which under §6.36 would immediately violate names-always-match
— the folder would claim project "Acme" while being named "Acme (2)". Renaming to
fix it would then rename the *original* Penpot project. The feature can't be made
coherent without answering questions nobody has asked yet, so it stays off.

### 6.41 — Refinement: with the bin off, restore is **best-effort**, not a failure mode

> **⚠️ Demoted by §6.49.** The measurements below are correct and still apply —
> but importing from our archive is now a **last resort**, reached only after
> Penpot's own ~7-day trash window closes. Inside that window, restore losslessly
> via `restore-deleted-team-files`.

> **Dr K:** *"we do delete from penpot … we simply import back into penpot …
> readme and docs fairly warn that this is best effort restore and that history
> is lost … as long as the restore is good enough, we are not technically
> loosing anything real."*

This reframes §6.23/§6.34's second row correctly, and the earlier write-up was
needlessly bleak about it. Measured what a bin-off restore actually preserves,
by exporting a real file and importing it into a fresh project:

| | Original | Restored |
|---|---|---|
| Name | `My firsty` | `My firsty` ✅ |
| **`revn`** | 5 | **5** ✅ |
| Pages, shapes, assets | — | **all present** ✅ |
| File id | `61d8ecb9…` | `feb88e73…` ❌ new |
| **`file_change` rows** | 5 | **0** ❌ none |

**So the design comes back whole.** The artwork, the pages, the assets, even the
revision number survive. What does not: the **id** (so old deep links stay dead)
and the **edit history** (`file_change` rows are 0 — you cannot step back through
past versions of a restored file).

**That is a genuinely good outcome for a backup**, and the docs should say so
plainly rather than framing it as a degraded consolation prize. Dr K's standard
is the right one: *nothing real is lost.* Nobody loses design work; they lose
undo-history and a URL.

**And it clarifies what the trash bin is FOR.** Two tiers, each honest about
itself:

- **Bin off (default): best-effort restore.** Delete really deletes in Penpot;
  Nextcloud's own trash holds the `.penpot` archive; restoring imports it back.
  You get your design; you don't get its history or its old link.
- **Bin on: perfect restore.** The design is moved, never deleted, so restoring
  returns *the same file* — id, history, links, everything (§6.34, proven).

The bin now earns its keep as a **deeper flow for people who care about history**,
rather than being the difference between "recoverable" and "gone." That's a much
more defensible setting, and it makes the default safe rather than dangerous.

**One caveat to keep stating:** best-effort restore depends on the archive
actually existing locally — i.e. the file was in `sync` mode (§6.22). A `link`
file holds no bytes, so there is nothing to restore from. That's the real
argument for promoting designs you care about to `sync`, and it belongs in the
docs next to the mode explanation.

### 6.42 — Test Cook: the "deleted projects are visible" observation was an upstream BUG, not a feature — so the trash bin stays

> **Dr K:** *"if the get returns deleted projects then our restore can actually
> work great … if this actually works, i'd say we may not even need the 'trash
> bin' feature at all."*

Exactly the right instinct, and it would have been a genuine simplification —
worth chasing properly rather than assuming either way. Chased it. **It doesn't
hold**, and the reason is specific enough to be worth recording so nobody
re-derives this hope later.

**What §6.38 actually observed:** after eight `delete-project` calls (204 each),
`get-projects` still listed all eight. That looked like "Penpot shows you your
trash."

**What it really is: `get-projects` doesn't filter `deleted_at`.** Four checks:

1. **`get-all-projects` filters correctly** — same account, same moment,
   returned exactly the 3 live projects. Two sibling commands disagree.
2. **`get-project` on a deleted project id → HTTP 404**,
   `{"type":"not-found","code":"object-not-found","table":"project"}`. The
   project is genuinely unreachable; the listing is simply lying.
3. **`get-project-files` on a deleted project → `[]`.** Its contents are gone
   too, so even a "visible" deleted project is an empty shell.
4. **Files behave CORRECTLY.** Created a file, deleted it, and
   `get-project-files` dropped it immediately (count went 2 → 1) while the
   database still showed `deleted_at` set. So the bug is specific to the
   *projects* listing, not a general "Penpot shows deleted things" property.

**Conclusion: there is no visible trash to restore from.** The one command that
appeared to offer it is inconsistent with every other command in the API, and
**we must not build on it** — it's an upstream bug that will be fixed, and a
feature depending on it would break silently on a Penpot upgrade. Practically,
the pull should treat `get-all-projects` (or a `get-project` confirmation) as
authoritative, and never conclude a project exists merely because
`get-projects` listed it.

**One genuinely new and useful finding, though:** **`export-binfile` still
exports a soft-deleted file.** Confirmed live — exported a file deleted moments
earlier, downloaded 6496 real bytes of valid ZIP. So within the ~7-day grace
window the *content* is recoverable through the API even though the file is
invisible to every listing and 404s on `get-file`.

What that does and doesn't buy us:

- **It cannot revive a file in place.** `move-files` on a soft-deleted file
  returns 204 but leaves `deleted_at` untouched (tested) — it stays invisible
  and still gets purged on schedule. There is no un-delete.
- **It's a safety net for one specific case:** a `link`-mode file (no local
  archive) whose design is deleted in Penpot. If we notice within 7 days we can
  still export it and hand the user a real `.penpot` archive, converting an
  unrecoverable situation into a best-effort-restorable one. **Worth building as
  a rescue path**, and it is the one part of this thread that survives.

**Net effect on §6.34: the trash bin stays, unchanged.** The simplification Dr K
hoped for isn't available, because the premise (a visible, restorable Penpot
trash) turned out to be a listing bug rather than a feature. The two tiers stand
as §6.41 framed them — best-effort by default, perfect restore with the bin on.

### 6.43 — Decision (locked): `link` files are strictly confined to their project

> **Dr K:** *"i feel like links for now could be a strict 'they are in the
> projects they are in' and you can only move them around within the project that
> penpot says it is in … this should simplify the logic handling here."*

Right, and the justification is sharper than "simpler": **a `link` file has no
content, so almost every gesture that's meaningful for a `sync` file is
meaningless or harmful for a link.**

A `sync` file is a real archive — moving it out of a mapping leaves the user
holding something genuinely valuable (§6.23's "zip in Nextcloud only" state),
and deleting it locally still leaves a restorable backup. **A `link` file is a
pointer.** Move it out and the user holds an empty husk that looks like a design
and isn't. Delete it and there's nothing to restore from. Every one of those
states is a way to mislead someone.

**The rules, all refusals rather than silent fixes:**

| Gesture on a `link` file | Behaviour |
|---|---|
| Move within its own project (incl. into plain subfolders) | **Allowed** — pure local filing |
| Move to a different project folder | **Refused** |
| Move to the team root (→ Drafts) | **Refused** |
| Move out of every mapping | **Refused** |
| Delete locally | **Hidden, not deleted** — see below |
| Ignore-tag it | **Refused** (already §6.23) |

Each refusal offers the same escape hatch: **"promote to `sync` first."** That's
not a fob-off — it's exactly the right action, because it converts the pointer
into something that *can* survive the gesture the user is attempting.

**Deleting a link "just hides it from Nextcloud" — Dr K's phrasing, and it's the
correct model.** There is nothing to delete: the design lives in Penpot, and the
local file is a pointer with no content. So a local delete of a link is a
**visibility** operation, not a destructive one. Concretely: the file goes to the
Nextcloud trash as usual, and the pull **does not recreate it** — because
recreating a pointer the user just dismissed would be an infinite argument
between the user and the reconciler. Restoring it from the Nextcloud trash, or
re-pulling deliberately, brings it back.

**This needs a "hidden" marker**, since "has a `penpot_id`, design exists in
Penpot, no local file" is otherwise indistinguishable from "not yet pulled."
Cheapest home is the mapping/folder-level record rather than the file (the file
is gone). Left as an implementation detail, flagged as open question #40.

**Why confine only links, and not sync files:** because `sync` files *earn* their
freedom by holding real content. The asymmetry isn't inconsistency — it's the
mode axis (§6.22) doing exactly the job it was reintroduced for.

### 6.44 — Test Cook: the Nextcloud trash is a first-class place, not a black hole — which makes three of Dr K's ideas buildable

Three proposals in this pass all rested on the same unverified assumption: that a
trashed Nextcloud file is still *reachable* — enumerable, readable, and
writable — rather than a tombstone. Dr K flagged the doubt explicitly (*"i'm not
sure if you can update trashed items in nxt"*). Tested it directly rather than
designing on a guess.

**Probe:** created a real `.penpot` file in a live user's home, stamped it with
`penpot_id`/`penpot_mode` metadata exactly as a mirror would be, deleted it to
the trash, then tried everything. Results:

```
created fileid=47567
deleted (now in trash)

trash items now: 1
  name=penpot-trash-probe.penpot   fileid=47567     ← SAME fileid

metadata of TRASHED file: penpot_id='design-uuid-1234' mode='link'   ← survives
metadata WRITE on trashed file: 'yes'                                ← works
trashed node: penpot-trash-probe.penpot.d1785087619  class=OC\Files\Node\File
    content readable: 'PK…FAKE-ARCHIVE-BYTES'                        ← works
    content WRITE:    OK -> 'PK…UPDATED-SNAPSHOT'                    ← works
```

**Everything works.** Four facts worth carrying into implementation:

1. **The fileid is preserved** across the trash boundary (47567 before and
   after). So Files-Metadata — keyed on fileid — keeps working untouched, which
   is why §6.37's trash-aware reconciler is even possible.
2. **Metadata survives and stays writable** on a trashed file.
3. **Content is readable AND writable** in the trash.
4. **Enumeration works** via `Files_Trashbin\Helper::getTrashFiles()`, and the
   on-disk name gains a `.dTIMESTAMP` suffix (`…penpot.d1785087619`) — so match
   trash entries by **fileid or metadata, never by filename**.

All probe artifacts were purged and the trash returned to empty.

### 6.45 — Decision (locked): the trash IS the hidden marker for links (open question #40, answered)

> **Dr K:** *"maybe we can 'hide' links by deleting them and then if they are in
> the trash that counts as being present in the reconciliation … restoring a link
> just unhides it."*

This is the answer to open question #40, and it's better than the extra marker
that question was reaching for. §6.43 established that deleting a `link` is a
*visibility* operation, then created a problem: with no local file, "the user
dismissed this" and "never pulled" look identical, so §6.43 proposed a separate
hidden-flag on the folder or mapping record.

**No separate flag is needed. The trash is already the flag.** Per §6.44 the
trashed file keeps its fileid, its `penpot_id`, and its `penpot_mode` — so the
reconciler can simply *look*:

| Design in Penpot | Local state | Meaning |
|---|---|---|
| exists | file present | mirrored normally |
| exists | **file in trash with matching `penpot_id`** | **hidden — leave it alone** |
| exists | nothing anywhere | not yet pulled — create the mirror |

**Why this is the right shape rather than merely a clever trick:**

- **No new state to invent, store, or garbage-collect.** A hidden-flag on the
  mapping would need its own lifecycle: written on delete, cleared on restore,
  cleaned up if the design disappears. The trash already does all of that.
- **Un-hiding is a gesture the user already knows.** "Restore from trash" is
  exactly what unhiding means, with no new UI.
- **It's self-cleaning.** Nextcloud's own trash retention eventually purges the
  entry, at which point the design simply reappears on the next pull — a
  defensible outcome (the user's dismissal expired along with their trash).
- **It generalises to `sync` files for free.** §6.37 already made the reconciler
  check the trash before creating a mirror, to avoid duplicating a
  user-restored file. This is the same lookup serving a second purpose.

**One consequence to state plainly:** emptying the Nextcloud trash un-hides
everything that was hidden. That's coherent — you threw away the record of the
dismissal — but it should be in the docs rather than discovered.

**And links are never restored *to Penpot*, ever.** Dr K's framing is the rule:
*"link just says it's there in penpot and shows it in nxt but the file contents
are never touched for any reason."* A link has no content, so there is nothing
to push, import, or restore remotely. Trashing and restoring a link are **purely
Nextcloud-side visibility operations**, and Penpot is never contacted by either.
This makes `link` the genuinely inert mode it was always meant to be.

### 6.46 — Decision (locked): take a final snapshot before pruning, and detect a Penpot-side restore

Two more of Dr K's ideas, both now buildable on §6.44's findings.

**1. The final snapshot.** *"if a file was deleted from penpot, our reconcile can
at least take a final snapshot before moving to the nxt trash."*

Today's prune (reconcile.feature) moves the local mirror to the trash when a
design vanishes from Penpot. For a `sync` file that's fine — the archive is
already there. For a **`link`** file it's the one genuinely lossy moment in the
whole app: a pointer to a design that no longer exists, and nothing to rebuild
from.

**§6.42 found the fix and §6.44 confirmed it lands somewhere useful:**
`export-binfile` still exports a soft-deleted file for ~7 days, and a trashed
Nextcloud file's content is writable. So the prune becomes:

```
design gone from Penpot
  └─ mode == link?  → try export-binfile within the grace window
                       ├─ success → write the archive into the file, promote to
                       │            sync, THEN move to the trash
                       └─ fail    → trash the pointer as before, and say so
  └─ mode == sync?  → archive already local; just trash it
```

The user ends up with a real, openable `.penpot` archive in their trash instead
of a dead pointer. **This converts the app's only unrecoverable case into a
best-effort-restorable one**, which is exactly the standard §6.41 set.

Best-effort by design: if the grace window has passed, or the export fails, we
trash the pointer and report it honestly rather than pretending.

**2. Detecting a Penpot-side restore.** *"maybe that info at least help when
someone restores within penpot ui and then our extension could restore more
cleanly."*

§6.42 established we cannot *drive* Penpot's trash — no API command restores a
file. But we can **notice** when a human does it in Penpot's own UI: the design
simply reappears in `get-project-files` under its **original id**.

That's the cleanest possible restore, and it costs nothing new — §6.37's
trash-aware reconciler already handles it:

- Design vanished → we trashed the mirror (keeping `penpot_id`).
- Human restores it in Penpot's UI within 7 days.
- Next pull sees that id again, finds the matching mirror **in the Nextcloud
  trash**, and **restores it in place** rather than creating a fresh one.

Identity, metadata, and mode all survive because the fileid never changed
(§6.44). So a Penpot-UI restore round-trips perfectly through Nextcloud without
a single extra mechanism — the *best* restore path in the app, and it belongs to
Penpot rather than to us.

**The full hierarchy of restore paths, now complete** (best to worst):

| # | Path | Preserves |
|---|---|---|
| 1 | Human restores in Penpot's UI → our pull re-adopts from trash | **Everything** — id, history, links |
| 2 | Penpot trash project, bin on (§6.34) | **Everything** |
| 3 | Nextcloud trash, design never deleted (§6.37) | Everything local |
| 4 | Import a `sync` archive (§6.41) | Design, not id or history |
| 5 | Grace-window snapshot, then import (§6.46) | Design, not id or history |
| 6 | A `link` whose design is long gone | Nothing — the only real loss |

Row 6 is now reachable *only* if the design was deleted more than ~7 days before
the pull noticed. Every other case has a real answer.

### 6.47 — Test Cook: Penpot CAN mint a token headlessly in CI — the full chain exists

Both sibling apps needed an answer to "where does the integration suite's
credential come from," and they answered it differently: Grafana mints a service
account over admin basic-auth (`bin/mint-grafana-token.sh`, no secret required);
n8n could not, and needs one supplied. **Penpot lands on Grafana's side**, which
means our integration suite needs no GitHub secret either.

**The chain, all four commands confirmed present on the live instance:**

| Step | Command | Params |
|---|---|---|
| 1 | `prepare-register-profile` | `{fullname, email, password}` → a token |
| 2 | `register-profile` | `{token}` → the account |
| 3 | `login-with-password` | `{email, password}` → an auth cookie |
| 4 | `create-access-token` | `{name}` → the bearer token we actually use |

Every one returned **400 with a real schema** (not 404), which per §6.38's probe
technique means the command exists. Only `create-access-token` returned 401
unauthenticated, as expected.

**Why our own instance can't complete the chain, and why that doesn't matter.**
Running it here returns `registration-disabled` and `login-disabled` — but those
are **our deployment's choices**, not Penpot's defaults. Our `PENPOT_FLAGS`
carries `disable-registration` and `disable-login-with-password` because this
instance is OIDC/LDAP-gated through Keycloak. Confirmed against the official
configuration guide: *"By default, the email/password registration is enabled and
the rest are disabled"*, and `disable-registration` is explicitly opt-in.

**So a CI container simply omits those flags**, and additionally needs
`enable-access-tokens` (Course 0) plus `disable-email-verification` so step 2
doesn't wait on an email nobody will read. A GHA `services:` container with:

```
PENPOT_FLAGS: enable-access-tokens disable-email-verification disable-onboarding
```

...can be registered against, logged into, and asked for a token, entirely from
a bootstrap script — exactly Grafana's shape, no secret in the repo.

**One asymmetry worth noting for whoever writes the script:** Grafana's mint is
2 calls against an admin that already exists; Penpot's is 4 calls that *create*
the account first, because Penpot has no pre-existing admin (§6.8 — there is no
admin concept at all). That's more steps but strictly less privileged, and it
means each CI run gets a genuinely isolated account rather than sharing one.

### 6.48 — Idea parked (NOT decided): `/` in a Penpot project name as an inferred Nextcloud path

> **Dr K:** *"what if we used the '/' in a penpot project name to infer the path
> in nextcloud … then we use the path like naming to our advantage. This could
> open a lot of edge cases though … we can capture this as a maybe feature when
> things smooth out."*

Recorded deliberately as a **maybe**, with the groundwork done so a future
chapter can evaluate it without re-deriving anything. Not on any roadmap.

**The idea.** A Penpot project named `foo/bar` would materialise in Nextcloud as
a plain folder `foo/` containing a project folder `bar/`. Penpot stays flat;
Nextcloud gets structure inferred from the name. `foo/bar`, `foo/baz` and
`fuzz/buzz` would produce two ordinary parent folders and three project folders.

**Why it's genuinely appealing.** It turns §6.38's most awkward finding into a
feature. Penpot accepts `/` in project names but Nextcloud can't use it in a
folder name — currently that's an *exception* we have to sanitise around and
report (§6.36's names-always-match rule cannot hold for such a project). This
idea reframes the same character as the thing that carries structure. It also
gives Penpot users a way to express hierarchy their own tool doesn't have, using
a convention designers already reach for by hand.

**What was checked live, so the evaluation starts from facts:**

| Probe | Result |
|---|---|
| `foo/bar`, `foo/baz`, `fuzz/buzz` | all created fine |
| `a/b/c` (multi-level) | created fine |
| `/leading`, `trailing/` (degenerate) | **created fine** |
| A **duplicate** `foo/bar` in the same team | **created fine — two projects, same name** |
| Rename `foo/baz` → `fuzz/buzz` when `fuzz/buzz` already exists | **HTTP 204, silently allowed** |

**So Penpot enforces nothing here**, which is exactly what makes this hard: every
constraint would have to be ours, on a namespace Penpot lets users freely
collide in.

**The edge cases, the reason this is parked rather than adopted.** Dr K named the
central one; the probes surface several more:

1. **The rename case.** Renaming `foo/bar` → `fuzz/buzz` has to reconcile
   against *both* sides: does `fuzz/buzz` already exist in Penpot (yes, allowed —
   proven above), and does the `fuzz/` folder already exist in Nextcloud, and is
   the existing `buzz/` a project folder or an ordinary one?
2. **Duplicates are legal in Penpot and impossible in Nextcloud.** Two projects
   named `foo/bar` want the same folder. §6.36's collision question (open #31)
   already exists for flat names; path-inference multiplies it.
3. **Whose folder is `foo/`?** It's app-created but not a project — so is it
   tagged, tolerated, or something new? What happens when the last project under
   it is renamed away: does `foo/` get garbage-collected, and what if the user
   put their own files in it?
4. **It collides head-on with free nesting (§6.29).** Users may already move
   project folders anywhere. If the name also dictates a path, the two rules
   contradict: does renaming a project *move* its folder out from where the user
   deliberately put it? That is a real conflict with a locked decision, not a
   detail.
5. **Degenerate names.** `/leading`, `trailing/`, and `a/b/c` all create
   successfully, so the design would need defined behaviour for each rather than
   assuming users type clean paths.
6. **The reverse direction.** If a user creates a folder tree in Nextcloud and
   tags a leaf as a project (§6.39's guard), does the project get named with the
   inferred path? If so, moving that folder would rename the Penpot project —
   surprising, and again in tension with §6.29.

**Status: parked.** Point 4 alone means adopting this requires revisiting a
locked decision, so it isn't a small additive feature — it's a different model of
what a project folder's *location* means. Revisit only once the core mirror is
built and stable, and only with an explicit answer to "does the name control the
path, or does the user?" — those cannot both be true.

## Open questions for the next chapter

Struck items are now answered (see Course 5); the rest are still open.

1. ~~Flip `enable-access-tokens` + `enable-webhooks` on the live instance, mint
   a token, confirm `export-binfile` against a real file.~~ **Done — §5.4.**
2. ~~Determine the actual `import-binfile` (or equivalent) RPC shape.~~
   **Done — §5.1, and now fully exercised live in §6.20:** both the create-new
   and in-place (`file-id`) variants work; it's SSE like export; params are
   kebab-case; the `name` param is ignored.
3. Stand up a `webhook.site`-style probe against our own instance to capture
   real event payloads — still open; blocked on standing up a real listener
   (Penpot validates the target URI at creation time, per §5.1).
4. ~~Confirm whether a non-personal (service-account-style) credential path
   exists.~~ **Done — §5.1 + fully closed in §6.8: no admin API, no usable
   organization layer (permission-gated off), no distinct "owner" account
   type. Personal token is the whole story; §6.9 now proposes leaning into
   that per-user instead of routing around it.**
5. Measure one real `export-binfile` call's latency/size on a moderately
   complex design file — **still open**; "My firsty" is a near-empty starter
   file (6466 bytes exported, re-measured §6.20), not representative of a real
   design's weight. **Less urgent now that §6.22 made `link` the default** —
   weight only matters for files a human explicitly promoted to `sync` — but
   the `sync` path's real cost is still unmeasured. Needs one genuinely complex
   file.
6. ~~Actually call `import-binfile` against our instance — create a new file,
   then the in-place `fileId` variant.~~ **Done — §6.20. Both work.** Also
   discovered: a **deleted** file cannot be resurrected at its original id
   (`object-not-found`), which is what forced §6.23's honest restore design.
7. **New (§6.6):** test whether `delete-project` actually refuses to delete a
   team's auto-provisioned "Drafts" project, or whether that protection (if
   any) is purely client-side UI convention with no server-side enforcement —
   needs a disposable test team, not our one real one.
8. **New (§6.9):** same question, one level up — does `delete-team` refuse to
   delete an account's auto-provisioned "Default" personal team? Same
   need-a-disposable-account caveat as #7.
9. **(§6.9) Partially closed by §6.18.** The *role* of the per-user token is now
   decided (optional, attribution-only, two call sites). What remains is purely
   mechanical: the storage class for a per-user `sensitive` credential —
   `IConfig::setUserValue` mirroring both siblings' AppConfig pattern at user
   scope is the presumed answer, but it isn't built or tested. The 1:1
   assumption stands as a documented default, not an enforced constraint.
10. ~~Ratify or reject the two-tier Team-Folder-with-fallback design.~~
    **Ratified — §6.13.** Still open within it: work out what the
    plain-folder-plus-group-sharing fallback tier looks like concretely when
    `groupfolders` is absent, mirroring how `TeamFolderService.php` already
    solves this for n8n/Grafana.
11. **New (§6.13):** the "leaving as last member deletes the team" finding
    is one trial, one account, self-hosted only — worth confirming the exact
    trigger condition (sole membership specifically, vs. e.g. last *owner*
    even with non-owner members still present) before the mapping design
    leans on it as a hard guarantee rather than an observed default.
12. **New (§6.13):** design the Penpot-module `OwnershipTags`-equivalent —
    likely simpler than Grafana's three-state (`sync`/`link`/`unmapped`)
    version given 6.1's read-only stance, but needs its own pass once
    `lib/Service/` exists rather than assuming a straight copy.
13. ~~Decide the actual mapping-storage mechanism.~~ **Done — §6.21 + §6.24:**
    folder-level Files-Metadata, confirmed to write/persist/read-back on a real
    production Team Folder identically to an ordinary folder. Team id on the
    Team Folder, project id on each project subfolder. System tags are kept only
    for human-visible concerns (§6.23's ignore marker), not for the mapping.
14. **New (§6.14), operational not architectural:** this cluster's
    `groupfolders` app (v21.0.8, confirmed installed and actively used by the
    live "n8n"/"observe" Team Folders) isn't referenced anywhere in
    `apps/nextcloud`'s declarative config (`before-start.sh`'s `APPS=` list
    has no `groupfolders`) — it was installed out-of-band, by hand or via the
    UI. Not a Penpot-module problem to fix, but worth a note for whoever
    eventually documents `apps/nextcloud`'s own app inventory, since the
    module family's `TeamFolderService.php` docblocks already assume
    `groupfolders` might not be present and degrade gracefully — it'd be
    worth knowing this repo's declarative config doesn't actually guarantee
    that assumption's opposite case (present) is reproducible from scratch
    either.
15. **New (§6.15):** decide how the "import as Team Folder" checkbox behaves
    for a Nextcloud user who lacks Team Folder creation rights (no admin
    delegation configured, the default and this instance's current state) —
    grey out with an explanation, route to an admin-approval step, or
    something else. Also confirm whether this cluster should turn on Team
    folder admin delegation at all, and for whom, as part of getting this
    app usable day-to-day rather than admin-mediated for every team import.
16. ~~Decide (a) vs (b) for teams the service account can't reach.~~
    **Done — §6.18: (a) wins.** The service account must hold a `viewer` invite
    before a team can be mapped at all. The per-user token survives, but for
    write *attribution* only, not as a second pull pathway.
17. ~~Live-test whether `create-webhook` genuinely works with a
    viewer-scoped (not owner) token.~~ **Partially open still — §6.17 only
    tested with the same owner-scoped token used throughout this chapter
    (the live instance has no second/invited account to test with). Webhook
    *creation itself* is confirmed working mechanically; whether an
    invited-viewer token specifically can call `create-webhook` remains
    untested.**
18. ~~Verify Penpot's backend can actually reach a webhook endpoint
    registered on `apps/nextcloud`'s in-cluster service.~~ **Done —
    §6.17: confirmed reachable (HEAD request observed live, Java HTTP
    client), both the SSRF allowlist and Nextcloud's `trusted_domains` gates
    identified and closed for real.**
19. **New (§6.17), the actual unresolved core of this thread:** webhook
    *delivery* never happened for two separate confirmed `rename-file`
    mutations against a real, validated webhook with `errorCount: 0`. Root
    cause unknown — extend the wait window well beyond ~10s, try a
    different event type (file/project-level rather than rename), and
    check whether webhook delivery requires the creating token to hold a
    specific role beyond bare team membership, before drawing conclusions.
    This blocks treating §6.16's "webhook as pull-trigger" design as
    confirmed-workable rather than merely well-reasoned.
20. **New (§6.17), operational pattern now seen twice:** both `groupfolders`
    (open question #14) and `trusted_domains` were found configured
    out-of-band on this cluster's live Nextcloud, absent from
    `apps/nextcloud`'s declarative config. Two independent instances of the
    same gap is worth flagging as a pattern to whoever eventually audits
    `apps/nextcloud`'s reproducibility from scratch, not just a one-off
    oversight.
21. **New (§6.20):** the kebab-case/camelCase split was found by hitting it
    (`import-binfile` takes `project-id`; `export-binfile` takes `fileId`).
    Worth a systematic pass over every command the client will call, rather
    than discovering each one at runtime.
22. **New (§6.20):** does `import-binfile` respect its `version` param?
    Untested, and irrelevant while we only ever import archives we exported
    ourselves — but it would matter if a user ever hand-drops a `.penpot` file
    exported from a different Penpot version.
23. **New (§6.22):** the `sync`↔`link` promotion/demotion UI surface. The mode
    model is locked, but *where* a user flips it (file action, sidebar tab,
    bulk selection) isn't designed. Demotion deletes a local archive, so it
    needs a confirmation whose wording matters.
24. **New (§6.18):** the service account needs a real Penpot identity on this
    cluster. `apps/penpot/components/service-account/ldap.yaml` already exists
    as the precedent; whether the `nextcloud` LDAP account is the one that
    mints this token, and who invites it as `viewer` to each team, is an
    operational step nobody has performed yet.
25. **New (§6.26):** does Penpot's own UI actually restore a soft-deleted file
    within the 7-day window, and through what route? We proved the grace period
    exists in the database and that no *RPC command* reaches it — but not what
    the UI does. If it turns out to use a command we simply didn't guess the
    name of, "restore from Penpot's trash" becomes buildable and §6.20/§6.23's
    lossy-restore design could be narrowed. Worth one look at the frontend's
    network traffic before accepting the caveat permanently.
26. **New (§6.26):** is the 7-day window configurable, and is it the same for
    projects and teams? `deleted_at` exists on 29 tables; only `file` was
    measured. Matters for what the README promises.
27. **New (§6.33):** `create-file` has never been called live — unlike
    `import-binfile` (§6.20). Before building the New-menu action, confirm the
    call, its param casing, and whether a created file needs a follow-up
    `import-binfile` to have any content at all (an empty design may be fine).
28. **New (§6.31):** the per-user pull for personal projects is a **second pull
    pathway**, which §6.18 deliberately avoided for team content. It's safe
    (one writer per home folder, no race), but it needs its own scheduling and
    failure story. Build after the primary team pull works.
29. ~~Is a team's **Drafts** project mirrored as a visible folder?~~
    **Answered — §6.35: no, never.** Drafts is a *state* ("in a team, not in a
    project"), not a folder. Still minor and open within that: does a design
    already sitting in Penpot's Drafts surface at the Team Folder root on pull?
    Consistency says yes; recorded so it isn't a surprise.
30. **New (§6.29):** with free nesting, two folders could end up carrying the
    **same** Penpot project id (e.g. a user copies a project folder). The
    nearest-ancestor rule stays well-defined for any given file, but the pull
    needs a tie-break for "which folder do I write new files into." Probably
    "first by path, warn about the rest" — not yet decided.
31. **New (§6.36):** Penpot permits two projects in one team to share a name;
    Nextcloud does not permit two sibling folders to. Since position is free
    (§6.29) the second folder can live elsewhere, but the pull needs a defined
    behaviour rather than a collision. Decide before the naming rule ships.
32. **New (§6.34):** test the trash round trip with an actually-**shared**
    library (`is_shared: true`). `file_library_rel` rows survive (id-keyed), and
    per Dr K a trashed file needn't function while trashed — but confirm the
    library resolves again for its consumers after restore.
33. ~~`rename-project` and `create-project` have never been called.~~
    **Done — §6.38: both work.** `create-project {team-id, name}` → 200 + record;
    `rename-project {id, name}` → 204. Also learned: Penpot's name rules are far
    *looser* than Nextcloud's (it accepts `/`), and `delete-project` is soft with
    the same ~7-day grace as files.
34. **New (§6.35):** file moves between projects are now a real write path
    (`move-files`). Confirmed working in both directions during the §6.34 trash
    probe, but never exercised as the *user-facing drag* it's specced as —
    including the "drag out of a project, back to Drafts" direction.
35. **New (§6.38):** decide the exact sanitisation rule for a Penpot project name
    Nextcloud can't use as a folder name (`Has/Slash` confirmed creatable). What
    character substitution, and how the divergence surfaces to the user, is
    undecided — only that the id stays authoritative.
36. ~~Determine whether `get-projects` returning soft-deleted projects is
    eventual-consistency or a filtering bug.~~ **Answered — §6.42: it's a bug.**
    `get-projects` never filters `deleted_at`; `get-all-projects` does,
    `get-project` 404s, `get-project-files` returns `[]`, and *files* filter
    correctly everywhere. **The pull must not trust `get-projects` alone.**
    Worth reporting upstream.
37. **New (§6.41):** best-effort restore requires the archive to exist locally,
    which means the file must have been in `sync` mode. Consider whether the
    delete flow should *offer to promote to sync first* when the bin is off and
    the file is a `link` — otherwise "delete" is genuinely unrecoverable for
    link files, which is worth surfacing at the moment of deletion.
38. **New (§6.42):** build the **grace-window rescue** — `export-binfile` still
    exports a soft-deleted file (confirmed live, 6496 real bytes from a file
    deleted moments earlier). For a `link` file whose design was deleted in
    Penpot, exporting it within the ~7-day window converts an unrecoverable
    situation into a best-effort-restorable one. Needs a decision on whether the
    pull does this automatically on noticing a deletion, or offers it.
39. **New (§6.42):** confirm the `get-projects` filtering bug against a newer
    Penpot than 2.17.0 before relying on the workaround — if upstream fixes it,
    the workaround is harmless, but the behaviour should be re-checked rather
    than assumed permanent.
40. ~~Design the "hidden link" marker.~~ **Answered — §6.45: the trash IS the
    marker.** A trashed file keeps its fileid, `penpot_id` and `penpot_mode`
    (§6.44, tested), so "in the trash with a matching id" *is* the hidden state.
    No separate flag, no lifecycle to manage, and un-hiding is the restore
    gesture users already know.
41. **New (§6.45):** emptying the Nextcloud trash un-hides every hidden link, so
    they reappear on the next pull. Coherent (the dismissal record is gone) but
    it must be documented rather than discovered. Consider whether a purge of a
    hidden link should warn.
42. **New (§6.46):** decide whether the grace-window snapshot runs automatically
    on every prune of a `link` file, or only when the user asks. Automatic is
    kinder but adds an export to a path that currently costs nothing; the window
    is only ~7 days, so "ask later" may be too late.
43. **New (§6.44), operational:** trashed files carry a `.dTIMESTAMP` suffix on
    their on-disk name (`foo.penpot.d1785087619`). Match trash entries by fileid
    or metadata, **never by filename** — a filename match will silently miss.
44. ~~(§6.48) `/` in a project name as an inferred Nextcloud path, parked.~~
    **Reopened as a real fork — §6.50.** Evidence gathered (Penpot's own docs
    describe projects as flat "folders in a file system", suggest organising *"by
    client, product, feature"*, and never mention nesting or paths).
    **Recommendation: keep free nesting, don't adopt `/`-as-path.** Awaiting
    Dr K's ratification.
45. **New (§6.49), BLOCKING the chapter close:** rewrite §6.34 (drop the
    service-account trash bin) and §6.41 (demote best-effort restore to a last
    resort) around the corrected trash finding, then update `delete.feature`,
    `restore.feature`, `reconcile.feature` and the README to match. The saga now
    says the right thing; the specs still describe the superseded design.
46. **New (§6.49):** `restore-deleted-team-files` returned success while
    `deleted_at` was still set — the SSE `end` event fires before the transaction
    settles. A second call cleared it. **Any client must re-read to confirm a
    restore**, never trust the `end` event alone. Worth checking whether this is
    a race or requires a retry, before the restore path ships.
47. **New (§6.49):** restoring a file also restores its **containing project**
    (the source clears `deleted_at` on `project` too). Confirm what that means
    for the pull when a whole project was deleted and one file is restored —
    does the project folder reappear with only that file in it?

### 6.49 — Correction: **§6.26 WAS WRONG. Penpot's trash IS reachable by API — and the trash bin (§6.34) is now unnecessary**

> Chasing Dr K's naming question sent me to Penpot's own docs, which describe a
> **Trash with Restore** in the dashboard. §6.26 concluded no such API existed.
> §6.26 was wrong, and the error is worth naming precisely because it's the kind
> I'd otherwise repeat.

**How I got it wrong.** §6.26 probed for trash commands *by guessing names* —
`restore-file`, `get-trash`, `undelete-file`, and a dozen more, all 404. From
that I concluded "no API reaches the grace window." But **absence of evidence
from a guessed-name sweep is not evidence of absence.** The real commands are
namespaced by *team*, not by file, and no reasonable guess would have found them:

```
get-team-deleted-files       {team-id}         → the trash listing
restore-deleted-team-files   {team-id, ids[]}  → restore (SSE)
permanently-delete-team-files{team-id, ids[]}  → hard delete (SSE)
```

Confirmed by reading Penpot's actual source
(`backend/src/app/rpc/commands/files.clj`), then verified live. The Trash
feature shipped in **2.13**; we run 2.17.0, so it was there the whole time.

**Verified end to end, twice:**

```
duplicate-file "RestoreProbe2"  → created
delete-file                     → 204, gone from get-project-files
get-team-deleted-files          → lists it (with all 5 earlier probe deletions)
restore-deleted-team-files      → event: progress {index 1, total 1}, event: end
→ back in get-project-files, SAME id, revn 5, get-file returns 200
```

**One real gotcha:** the first restore call reported success while `deleted_at`
was still set — the SSE returns before the transaction settles. A second call
cleared it. **Any client must re-read to confirm, not trust the `end` event.**
(The source explains why the restore is thorough: it clears `deleted_at` across
`file`, `file_media_object`, `file_change`, `file_data`, `file_thumbnail`,
`file_tagged_object_thumbnail` **and the containing project** — so restoring a
file resurrects its project too if that was deleted.)

**What this changes, and it's a lot:**

1. **§6.34's trash bin is now unnecessary — and should be dropped.** Its entire
   justification was: "Penpot's own grace period is unreachable, so our only
   alternative to `delete-file` is moving files to a service-account project."
   That premise is false. Penpot has a real trash with a real restore that
   preserves **id, history, links, everything** — strictly better than our
   move-to-a-robot's-team scheme, with none of its downsides (no design vanishing
   into a private team, no origin bookkeeping, no shared-library caveat).
2. **§6.41's "best-effort restore" is demoted to a last resort.** With the bin
   off, deleting no longer means "rebuild from the archive, lose the history" —
   it means "it's in Penpot's trash for ~7 days; restore it there." Import from
   our archive is only needed *after* that window closes.
3. **The restore hierarchy simplifies.** §6.46's six-row table collapses: rows 1
   and 2 merge into "restore it in Penpot's trash — via the UI *or* via our
   own API call," and we can now *drive* it rather than only pointing at it.
4. **§6.20's finding still stands** and is unaffected: a **purged** file (past
   the window, or `permanently-delete-team-files`) still cannot be resurrected
   at its original id. The grace window is the difference between recoverable
   and not.

**The lesson for the rest of this saga:** when a vendor's *documentation*
describes a feature and my probe says it's absent, the probe is the thing to
doubt. Read the source next time — it took one file to answer definitively what
a dozen guessed names could not.

**Consequences for the trash-bin design are recorded but NOT yet rewritten into
§6.34 and the features** — that's a deliberate, sizeable edit that belongs with
the nesting decision in §6.50, so both land together.

### 6.50 — The nesting fork, reopened: `/`-as-path vs. free nesting (Dr K's call, evidence gathered)

> **Dr K:** *"I'm honestly open to only doing this and rewriting our whole nested
> idea … I want to go with the option that most fits with the intended penpot
> model … probably just picking one would make all the logic and code simply less
> and more straightforward and expectable."*

§6.48 parked this as a "maybe." Dr K has reopened it as a real fork, and the
final clause is the right instinct: **the two models should not coexist.** Here
is the evidence, then a recommendation.

**What Penpot itself intends — from their own documentation, verbatim:**

> *"Projects are containers that help you organize and group related design files
> together. **Think of them as folders in a file system.** You can create as many
> projects as you need to organize your work by client, product, feature, or any
> other structure that fits your workflow."*

And on files: *"Files are your design documents… Files can be created within a
project."* There is **no mention of nesting, paths, or hierarchy anywhere** in
the projects-and-files guide. The organizational axes Penpot suggests are *"by
client, product, feature"* — i.e. **one flat level, chosen per workspace.**

**So the honest reading: Penpot's intended model is a flat, single-level folder
space.** The `/` character is permitted in a name the same way `🎨` and a leading
space are permitted — because the field is `[:string {:max 250}]` and nothing
more. It is **not** an S3-style key convention:

- Penpot's own docs never suggest it.
- Nothing in the API treats `/` specially (§6.38: `a/b/c`, `/leading`,
  `trailing/`, and *duplicate* names all create fine; a rename into an existing
  name returns 204).
- The dashboard renders projects as a **flat sidebar list**, so `foo/bar` and
  `foo/baz` appear as two unrelated entries whose names happen to share a prefix
  — they are not grouped.
- Penpot ships a real hierarchy feature for the *other* level (pages/boards
  inside a file), which is where they put structure deliberately.

**Recommendation: keep free nesting (§6.29). Do not adopt `/`-as-path.**

The reasoning, weighed against Dr K's own criterion ("most fits the intended
Penpot model"):

1. **`/`-as-path invents a convention Penpot doesn't have**, then makes our app
   depend on users honouring it. §6.38 proved Penpot enforces nothing — so every
   guarantee (uniqueness, valid segments, no collisions on rename) becomes ours
   to police, in a namespace the source system lets users freely break. That is
   the opposite of "less logic."
2. **It makes Penpot's flat list the master of Nextcloud's tree.** A user who
   organises folders in Nextcloud would find their layout overwritten whenever
   someone renames a project in Penpot. §6.29's split — *Penpot owns membership,
   Nextcloud owns layout* — is what lets each system be good at what it actually
   is.
3. **It fails the don't-lose-data test in a way free nesting doesn't.** Dr K's
   own example is the tell: renaming `/foo/baz/buz/fuz/nuz/cuz` → `/foo/baz/buz/fuz`
   requires us to unmap a folder, remap another, move files up, and conditionally
   delete a directory *only if* it contains nothing but `.penpot` files. That is
   a delicate destructive cascade triggered by a **text edit in another system** —
   exactly the class of action §6.1 exists to prevent.
4. **Free nesting already delivers the benefit Dr K wants**, without the cost.
   "Any folder in the structure could have any kind of files — markdown, text,
   Grafana, n8n" is *already true* under §6.29, because ordinary Nextcloud folders
   may sit anywhere among project folders. The tree Dr K described is buildable
   today; the only difference is **who authors it** — the user, rather than a
   naming convention.
5. **The n8n/Grafana comparison actually argues against it.** Grafana has real
   nested folders and we mirror them structurally; n8n has none and we *don't*
   fake them (§6.3 notes n8n's tag→folder trick as weight Grafana didn't need).
   Penpot is the n8n case: flat by design. Faking depth from a string is
   precisely the n8n-era hack the family moved away from.

**What we keep from the idea anyway.** §6.38's exception stands and is the one
place `/` still matters: a project genuinely named `Has/Slash` can't be a folder
name, so it's sanitised, the id stays authoritative, and the divergence is
reported (open question #35). Users who *type* `foo/bar` get a folder literally
named `foo bar` (or similar) — surprising-but-safe, and far better than silently
restructuring their Nextcloud tree.

**If Dr K still wants it**, the honest shape is an **admin-level, per-team,
mutually-exclusive** setting — *"Flat projects"* (default, §6.29) vs. *"Paths
from project names"* — precisely because the two models cannot both be
authoritative about location. But it should be a **later chapter**, built on a
working flat mirror, not a rewrite of the core before any of it exists.

**Status: recommendation recorded, decision Dr K's.** Nothing in the locked
design changes unless it's ratified.

---

## Chapter 1 — where it stands (REOPENED)

> **Dr K:** *"we made first contact — chapter one was more like opening the line
> of communication and probing to figure out how the two species will interact."*
>
> That framing still holds — and it's exactly why the chapter reopened. Two more
> probes (§6.49, §6.50) found that we'd misread part of the anatomy. First
> contact isn't over until the map is right.
>
> That's the chapter, exactly. We were sent to answer *"can we dock with this
> planet?"* and the honest answer turned out to be **yes, but not the way we
> docked with the last two.** Penpot isn't a system that speaks our protocol
> badly — it's one with a genuinely different anatomy: no admin caste (§6.8),
> flat territory where we expected hierarchy (§6.5), a customs desk that speaks
> its own dialect (Transit), and a strange biology where a "deleted" thing is
> alive but unreachable for seven days (§6.26).
>
> So the work wasn't translation, it was **negotiating the terms of contact**:
> who may speak for whom (§6.18), what we're allowed to touch and what we only
> observe (§6.19 — one destructive verb in the entire vocabulary), what their
> flatness should become in our world and what ours should stay out of theirs
> (§6.29, §6.35). Several of those terms were only settled by *going down and
> touching things* — and twice by being wrong first and corrected (§6.27→§6.34,
> §6.42).
>
> First contact is *nearly* over — two terms are still being negotiated
> (§6.49's correction and §6.50's fork). Chapter 2 is where we build the embassy,
> and it starts once those two are settled.

What this chapter has settled, what it corrected, and what is still open:

**What it produced:**

- **A surveyed and then *landed-on* planet.** Every API claim in this chapter was
  witnessed against a live instance, not read off a blog post — including two
  real infrastructure bugs found and fixed along the way (§5.2, §5.3), and a
  handful of findings that contradicted the documentation (`import-binfile` is
  SSE and ignores `name`; `get-projects` doesn't filter deleted rows; Penpot's
  name rules are looser than Nextcloud's, not stricter).
- **A near-complete architecture**, §6.1–§6.50: the access model, the mapping
  shape, the mode axis, nesting, drafts, the trash, restore, failure behaviour.
  Every locked decision carries the evidence that produced it, and every
  superseded one carries an inline marker saying what replaced it — including
  three of my own conclusions that were overturned (§6.27→§6.34, §6.42, and
  §6.26→§6.49).
- **An executable specification**: 23 `.feature` files, ~259 scenarios, written
  before the code rather than after it.
- **A working first slice**, shipped and green: the app installs on a real
  Nextcloud and its base URL is configurable entirely over `occ`, proven by six
  live integration scenarios in CI.

**What must be settled before this chapter can close:**

- **§6.50 — the nesting fork.** `/`-as-path vs. free nesting. Evidence gathered,
  recommendation recorded (keep free nesting); the call is Dr K's.
- **§6.49's fallout.** Penpot's trash is API-reachable after all, so §6.34's
  trash bin should be dropped and §6.41 demoted to a last resort. The saga says
  so; the feature files and README have not yet been rewritten around it.

**What it deliberately left open** — the honest inheritance, not oversights. The
full list lives below; the load-bearing ones are:

- **The §6.2 file-rename fork.** Still genuinely undecided. Note the *project*
  rename direction was settled (§6.36) — only files remain open.
- **Webhook delivery (#19).** Creation works and is provisioned; delivery has
  never been observed. Nothing in the design depends on it, and nothing should
  until it's explained.
- **Export weight on a real design file (#5).** Still unmeasured. `link`-by-
  default makes it less urgent, but the `sync` path's true cost is unknown.
- **The creation carve-out** (§6.7/§6.15) and the `/`-as-path idea (§6.48), both
  parked with their edge cases enumerated.

**For whoever picks up Chapter 2:** the build order is already implied by what's
locked. `PenpotClient` first (SSE + Transit + the per-command param-casing
table — four commands now confirmed to disagree), then the credential cards,
then the nearest-ancestor resolver, then the pull. Read §6.18–§6.48 before
anything else; the earlier sections are the survey that produced them and several
have been superseded.

---

## References

- [Penpot Integration Guide](https://help.penpot.app/technical-guide/integration) — webhooks + access tokens, official.
- [Penpot Configuration Guide](https://help.penpot.app/technical-guide/configuration) — full flag/envvar reference; confirms `enable-access-tokens` / `enable-webhooks` / `enable-backend-api-doc` are opt-in.
- [The .penpot file format](https://help.penpot.app/user-guide/export-import/penpot-file-format) — official spec of the ZIP/JSON structure.
- [How to integrate Penpot with your developer toolchain](https://penpot.app/blog/how-to-integrate-penpot-with-your-developer-toolchain-apis-and-webhooks-for-workflow-automation) — official blog, practical examples, token-hygiene advice.
- [penpot/penpot#7649](https://github.com/penpot/penpot/issues/7649) — the `export-binfile` empty-zip / opaque-500 bug (mutually exclusive options), fix in review.
- [penpot/penpot discussion #4180](https://github.com/penpot/penpot/discussions/4180) — community report of `update-file` fragility and Transit-encoding error confusion.
- [Penpot MCP server](https://help.penpot.app/mcp) — adjacent AI-facing surface, already live in our cluster via `enable-mcp`.
- [aws/aws-sdk-java-v2#5987](https://github.com/aws/aws-sdk-java-v2/issues/5987) — the SDK-checksum-vs-GCS-interop bug behind §5.2 (Bug #1).
- Our own live install — `apps/penpot/README.md`, `apps/penpot/components/config/values.yaml`, `apps/penpot/components/storage-gcs/kustomization.yaml`, where both fixes from §5.2/§5.3 live. (Paths in a private homelab repo, not this one.)
