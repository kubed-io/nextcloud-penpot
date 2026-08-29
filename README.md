# Penpot Sync

**Your Penpot designs, living in Nextcloud as real files.** File them into folders, rename them, copy them, trash them, restore them — and every one of those gestures lands in Penpot for real. 🎨

[![🧪 Tests](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/tests.yml/badge.svg)](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/tests.yml)
[![🛡️ Quality](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/quality.yml/badge.svg)](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/quality.yml)
[![🔗 Integration](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/integration.yml/badge.svg)](https://github.com/kubed-io/nextcloud-penpot/actions/workflows/integration.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-32--34-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb4?logo=php&logoColor=white)](composer.json)

---

## The whole idea, in one breath

Map a Penpot **team** to a Nextcloud **folder**. Its projects arrive as folders, its designs arrive as `.penpot` files with the real Penpot icon, and clicking one opens the live design.

```
Penpot                          Nextcloud
──────────────────────────      ─────────────────────────────────
team    "Northwind"        ⟶    folder    Northwind/
 └ project "Brand/2026"    ⟶      folder     Brand/2026/
    └ design  "Homepage"   ⟶        file       Homepage.penpot
```

Nothing is matched on filename. Every file carries its design's **id**, so renaming, moving, copying, trashing and restoring never break the link — and re-running a sync never duplicates a thing. Ever. 🙅

---

## ✨ Every gesture means the same thing on both sides

Do it in Nextcloud and Penpot hears about it immediately. Do it in Penpot and the next pull brings it here.

| You do this in Nextcloud… | …and this happens in Penpot |
|---|---|
| Drag a design into another folder | It's re-filed into that project |
| Drag it into another team's folder | It changes team **and** project in one move — same id, same history |
| Drag it to the mapping root | It becomes a draft |
| **+ New → Penpot design** | A design is created in the project that folder spells |
| Drop a `.penpot` archive in | It's imported as a new design |
| Copy a design | A real new design, with its own id |
| Rename a file or a folder | The design or the project is renamed |
| 🗑️ Trash it | It goes to **Penpot's** trash |
| ↩️ Restore it | It comes back out — id, revision, history and deep links intact |

…and the mirror image, on the next pull: create, duplicate, rename, move or delete a design over there and the file here does the matching thing. A design duplicated in Penpot arrives as its own new file; a design moved into another project surfaces at that project's folder.

**Exactly one file per design, always.** Penpot lets three designs in one project share a name; Nextcloud can't. So they arrive as `Original.penpot`, `Original (2).penpot`, `Original (3).penpot` — one file each, still all named `Original` upstream, and no pull ever shuffles which is which.

📋 [`create`](features/designs/create.feature) · 🚚 [`move`](features/designs/move.feature) · 🍝 [`copy`](features/designs/copy.feature) · 🔤 [`rename`](features/designs/rename.feature)

---

## 🗂 The folder *is* the project

Penpot's hierarchy is rigid — team → project → design, no sub-projects. Nextcloud is a file manager. This app doesn't force Penpot's flatness onto your folders; it teaches Penpot yours. 😏

**A folder becomes a project the moment a design lands in it**, and the path spells the name:

```
Northwind/                     ← the mapped team
├── quick-thing.penpot         → a draft: under the team, under no project
├── Brand/                     ← project "Brand" 🏷
│   ├── Logo.penpot            →   …because a design landed in it
│   └── 2026/                  ← project "Brand/2026" 🏷
│       └── Homepage.penpot    →   …and the path is the project's name
└── Moodboards/                ← no designs in it, so it stays a plain folder
    └── notes.txt              ← never touched
```

It runs both ways: a project named `Brand/2026` in Penpot mirrors as two nested folders here. Make a folder, drop a design in, and Penpot has a project named after the path to it. A folder holding **no** designs stays an ordinary folder, however much else is in it — nothing is inferred until a design arrives.

**Drafts is a state, not a folder.** Penpot calls a design that belongs to a team but no project a draft, and we never invent a `Drafts/` folder for it: being at the mapping root simply *is* being in Drafts. Filing a draft is a drag; un-filing it is a drag back. 📥

Identity lives in metadata, not in path, so you can reorganise freely — and a project folder wears a visible `penpot` tag so you can spot one among ordinary folders.

🗂️ [`projects/create`](features/projects/create.feature) · 🚚 [`projects/move`](features/projects/move.feature) · 🔤 [`projects/rename`](features/projects/rename.feature)

---

## 🔁 Sync or Link — what gets *stored*, not which way edits flow

Every mapping is one of two modes, and it applies to every design the mapping pulls.

| Mode | The file holds | What it costs |
|---|---|---|
| 🔗 **Link** *(default)* | Nothing — zero bytes, pure pointer | Nothing. A team of links never exports |
| 💾 **Sync** | The real `.penpot` archive, openable offline | One export whenever the design changes |

⚠️ **This axis is not the sibling apps' axis.** In [n8n Sync](https://github.com/kubed-io/nextcloud-n8n) and [Grafana Sync](https://github.com/kubed-io/nextcloud-grafana), `sync` means "edits flow back". Here it decides only **whether we keep the bytes** — because a `.penpot` export is a full archive with embedded images and fonts, not a small JSON document. Most designs need to be findable and clickable, not duplicated; the ones worth backing up get `--mode=sync`.

**A link is Penpot's copy, so Nextcloud treats it as read-only.** It can't be trashed, copied, created or moved out of its project from this side — a pointer that wandered would hand you an empty husk that looks like a design and isn't. `sync` files have none of those limits, because they hold something real.

**And a `sync` mapping is a backup you can point at.** A 100-design team where nothing changed costs a handful of API calls and zero bytes, because Penpot hands back every design's revision number for a whole project in one response — so only what actually moved is re-exported.

---

## 🖌 It never edits a design Penpot already has

Design happens in Penpot. Nextcloud holds the archive and the click-through.

A `.penpot` export is an opaque tree of nested shape data — there is no sane way to hand-edit it here and re-import it coherently. So there is **no "Edit as text" action**, in any mode, for any file, unlike both siblings. Editing a mirror's bytes never reaches Penpot.

The one thing that *does* travel upward is an archive Penpot has **never seen**: drop a `.penpot` into a mapped folder, or press **Sync to Penpot**, and it becomes a new design in the project its folder spells. Nothing existing is touched. That's the button for the day something went wrong upstream and Nextcloud is the copy you trust. 💾

*(No tag sync, either — Penpot's API has no tags, labels or annotations at all. We scanned all 149 RPC commands looking.)*

---

## 🗑️ Two trashes, one gesture

Penpot has a real trash of its own, so a delete here is a delete there — and just as reversible.

| You do | Penpot does | Get it back by |
|---|---|---|
| 🗑️ Move a design to the trash | It goes to **Penpot's trash** | Restoring the file |
| ↩️ Restore it from the trash | It comes **back out**, losslessly | — |
| 💥 Empty the trash | **Permanently deleted** | Nothing. This is the irreversible one |

It works from the other side too: delete a design in Penpot and its mirror lands in your Nextcloud trash — never a hard delete, and a pointer gets one last export on the way out so what you're holding is a real, openable archive rather than a dead link. Destroy it for good over there and the trashed file here is cleared.

**Exactly one operation in this entire app destroys anything** — emptying your Nextcloud trash, the one gesture Nextcloud itself treats as irreversible. Everything else is additive or undoable.

Even that has a guard. Penpot's permanent delete does *not* check that a design is in the trash (we proved it live, on a design it cheerfully destroyed anyway), so the purge reads Penpot's trash listing first and passes on only ids that come back in it. If someone restored the design upstream in the meantime, emptying your trash leaves it alone. 🛡️

And the restore doesn't trust Penpot's reply: the RPC answers "success" for ids it did not restore, and answers *before its own transaction settles*, so the app re-reads and asks again. That's the difference between your design coming back and it coming back for ninety seconds.

🗑️ [`delete`](features/designs/delete.feature) · ↩️ [`restore`](features/designs/restore.feature) · 💥 [`purge`](features/designs/purge.feature)

---

## 🎨 A first-class file type — icon, mimetype, honest timestamps

A mirrored design isn't a generic ZIP. The app registers the `application/vnd.penpot` mimetype so your designs wear the **real Penpot icon**, and removing the app puts Nextcloud back exactly as it found it.

Then the detail we're quietly proud of: **a mirror gets the timestamps of the thing it mirrors.** Penpot's `modified-at` becomes the file's modification time and `created-at` its creation time — because "the sync job wrote this at 15:02" is never the question someone sorting a folder by date is actually asking. A design nobody has touched in a year should *look* like it. 🕰️

Every file's state is queryable over WebDAV, too:

| DAV property | What it holds |
|---|---|
| `nc:metadata-penpot_id` | The design's id in Penpot — stable across renames and moves |
| `nc:metadata-penpot_revision` | Penpot's revision + `modified-at`, the drift signal |
| `nc:metadata-penpot_mode` | `sync`, `reference`¹ or `unmapped` — and it's **indexed** |

Folders carry `penpot_project_id` / `penpot_team_id` the same way. All of it is **read-only** — no `PROPPATCH`; the sync engine owns these.

¹ `reference` is the on-the-wire value for **link** mode — Nextcloud's PROPFIND treats a stored `link` as a callback and falls over. Everywhere a human looks, it's **link**.

👀 [`view`](features/designs/view.feature) · 🖱️ [`open-with`](features/designs/open-with.feature)

---

## 🔑 A service account reads; you write as yourself

Penpot has no admin or service credential type — every access token is scoped to a user account. So the job splits in two:

| | Service-account token | Your personal token |
|---|---|---|
| **Required?** | **Yes**, once | No — optional |
| **Set by** | An admin | You, in personal settings |
| **Does** | All mirroring: list, export, pull | Attributes *your* changes to *you* in Penpot's history |

One puller means no race: if the pull ran per-user, two people on the same team would write the same mirrored file from separate jobs. The cost is that someone has to invite the service account to each Penpot team as a **viewer** before it can be mapped — which doubles as a clean opt-in gate. Inviting it is how a team says *"yes, Nextcloud may manage this."*

---

## 🛠 Setup, in three moves

**1. Point it at Penpot.** Base URL and a service-account token, stored encrypted and never echoed back. **Test connection** reports which teams that token can actually see — which is what decides what you can map.

**2. Map a team.** Pick the team, the folder name, the mode, and the groups it's shared with. Its projects come along automatically; there's no project-level mapping to configure and nothing that can drift from what Penpot contains. With the `groupfolders` app installed you get a real Team Folder — the closest match to Penpot's own model, where the team *is* the access boundary — and without it, a plain shared folder that behaves identically.

**3. Sync it.** A scheduled pull on whatever interval you like, plus **Sync from Penpot** / **Sync to Penpot** buttons for when you're impatient.

Two things worth knowing about a Penpot instance:

- `enable-access-tokens` must be on — it's off by default upstream, and it's what lets a user mint the token this app authenticates with.
- If Nextcloud reaches Penpot at a private address (a Kubernetes service name, a LAN IP), Nextcloud's SSRF guard blocks it: `occ config:system:set allow_local_remote_servers --value=true --type=boolean`. `occ penpot_sync:probe` reports that case by name rather than as a generic failure.

🔌 [`connection/admin`](features/connection/admin.feature) · 🗂️ [`mapping/create`](features/mapping/create.feature) · 🔄 [`sync-now`](features/connection/sync-now.feature)

---

## ⌨️ Every button is also a command

The whole setup is scriptable, so a Kubernetes init job can stand it up with no clicking. Exit `0` on success, non-zero on failure.

```sh
# Connect
occ penpot_sync:set-url https://penpot.example.com
occ penpot_sync:set-token                          # reads stdin, stays out of your history
occ penpot_sync:test-connection

# Map a team to a folder
occ penpot_sync:list-teams
occ penpot_sync:add-mapping <team-id> --folder="Northwind" --mode=sync --groups=designers
occ penpot_sync:list-mappings
occ penpot_sync:set-groups <mapping-id> designers,admins
occ penpot_sync:remove-mapping <mapping-id>

# Sync — either direction, one mapping or all of them
occ penpot_sync:sync pull
occ penpot_sync:sync push --mapping=<mapping-id>

# Ask what the app thinks about a path
occ penpot_sync:status "Northwind/Brand/2026/Homepage.penpot"
occ penpot_sync:probe
```

---

## 📋 The specs are the docs

Every feature above links to an **executable specification** — a Gherkin `.feature` file under [`features/`](features/), written in plain language, which also *drives the integration tests* against a real Nextcloud and a real Penpot. The folder is the noun and the file is the verb: [`designs/`](features/designs/), [`projects/`](features/projects/), [`mapping/`](features/mapping/), [`connection/`](features/connection/). They're written before the code and kept true after it. 🧪

Read [`features/README.md`](features/README.md) for how they're organised and [`features/AGENTS.md`](features/AGENTS.md) for why each scenario is the way it is.

---

## 🚧 Status

**Early development, v0.1.0** — this runs, and it hasn't yet run in anger anywhere but its authors' homelab.

Most of what's above is proven by scenarios CI drives against a live Nextcloud and a live Penpot. The known gaps: **personal Penpot projects** don't mount into your home folder yet, copying a whole project **folder** isn't tracked, and a handful of "Penpot went unreachable mid-gesture" paths log the failure rather than report it to you. The Files-app surface — the icon, the context menu — is checked by hand, because the harness has no browser.

The [`saga/`](saga/) is the authoritative record of what's built, what's blocked and why; [Chapter 3](saga/Chapter_3_Building_To_Plan.md) is the current one.

---

## 📜 Licence & trademark

AGPL-3.0-or-later. See [LICENSE](LICENSE). Development notes are in [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

This is a community integration and is not affiliated with, endorsed by, or sponsored by Penpot (Kaleidos Ventures SL). "Penpot" and the Penpot logo are trademarks of their respective owner, used here only to identify the service this app integrates with.
