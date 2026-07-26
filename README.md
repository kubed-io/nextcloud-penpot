# Penpot Sync

A Nextcloud app that mirrors your Penpot design files into the Files app — a
click-through to every design, a real backup for the ones that matter, and a
folder tree you can organise however you like.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-30--33-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb4?logo=php&logoColor=white)](composer.json)

> **Status: pre-alpha — most of what follows is design, not shipped behavior.**
>
> **What works today:** the app installs on Nextcloud and you can point it at a
> Penpot instance — an admin setting plus `occ penpot_sync:set-url` /
> `occ penpot_sync:show-config`. That's it. Six integration scenarios prove it
> against a real Nextcloud in CI.
>
> **Everything else below is the design.** No credentials, no sync engine, no
> file actions yet — those land slice by slice, and each `.feature` file stays
> tagged `@todo` until its slice ships. The behavior is written as if it already
> worked because that's how the spec is written; treat it as a detailed design
> document backed by a live-verified API survey. See [Status](#status).

---

## How it works (the design)

Penpot Sync walks your mapped Penpot teams on a schedule and reflects them into
Nextcloud. Each Penpot **team** becomes a Team Folder; each **project** inside it
becomes a folder; each **design** becomes a `.penpot` file with a real icon and a
deep link back to the live design in Penpot.

```
Penpot                              Nextcloud
─────────────────────────────       ─────────────────────────────────────
team    "Ferronescotia"        ⟶    Team Folder  Ferronescotia/
 └ project "My Stuff"          ⟶      folder       My Stuff/
    └ file  "My firsty"        ⟶        file         My firsty.penpot
```

A `.penpot` file is a plain ZIP (a `manifest.json`, a `files/` tree of
pages/colors/components/typographies, and an `objects/` tree of binary assets),
fetched through Penpot's `export-binfile` RPC. You can unzip and inspect one.

### Modes — what gets backed up, not which way edits flow

Every mirrored file is one of two things, and you choose per file:

| Mode | What it is | What it costs |
|---|---|---|
| **`link`** *(default)* | A pointer to the live design. Opens in Penpot. | Nothing — never exports |
| **`sync`** *(opt-in)* | A real, downloaded `.penpot` archive you can open offline | One export whenever the design changes |

**Neither mode ever pushes design content to Penpot.** In the sibling apps for
[n8n](https://github.com/kubed-io/nextcloud-n8n) and
[Grafana](https://github.com/kubed-io/nextcloud-grafana), `sync` means "edits flow
back." Here the axis decides only **whether we store the bytes** — a `sync` file
is still a read-only mirror.

Why: a `.penpot` export is a full archive with embedded images and fonts, not a
small JSON document. Backing up every design in a large team would be expensive
and mostly pointless — most designs need to be *findable and clickable*, not
duplicated. So `link` is the default, and you promote the ones worth keeping.

**Links stay where Penpot put them.** A `link` file holds no bytes, so moving one
out of its project would hand you an empty husk that looks like a design and
isn't. Links can be filed freely *within* their own project — including into
plain subfolders — but can't be moved to another project, out to Drafts, or out
of the mapping entirely. Deleting one just hides it. Every restriction offers the
same escape: **promote it to `sync`**, which is exactly the action that makes the
gesture safe. `sync` files have none of these limits, because they hold something
real.

### Read-only, on purpose

**This app never edits your designs.** A `.penpot` export is an opaque archive of
nested shape data — there is no sane way to hand-edit it in Nextcloud and
re-import it coherently. Design happens in Penpot; Nextcloud holds the backup and
the click-through.

- No "Edit as text" action — not even as a fallback, unlike both siblings.
- No content writeback. Editing a mirrored file's bytes never reaches Penpot.
- No tag sync — Penpot's API has no tags, labels, or annotations at all
  (confirmed by scanning its full RPC surface: 149 commands, zero hits).
- **Copying, deleting and trashing a mirror are purely local.** Nothing you do to
  a mirrored file in the Files app ever deletes anything in Penpot.

### What *can* reach Penpot, and why it's safe

The app isn't inert — it just never does anything destructive by accident. The
complete list:

| What you do | What happens in Penpot | Reversible? |
|---|---|---|
| Drag a design into another project folder | It moves to that project | Drag it back |
| Drag it to the team root | It moves to Drafts | Drag it back |
| **New → Penpot design** | A design is created | Delete it |
| Restore an archived design | It's imported | — |
| Rename a project folder | The project is renamed | Rename it back |
| **Delete in Penpot** *(explicit action)* | Moved to Penpot's trash | Restore it, for ~7 days |

**Exactly one operation in the entire app destroys anything** — and it only ever
runs behind an explicit, confirmed "Delete in Penpot". Everything else is
additive or reversible. That, rather than a short list, is what the read-only
promise actually protects.

### Nesting: flat in Penpot, however you like in Nextcloud

Penpot's hierarchy is rigid — team → project → file, no sub-projects. Nextcloud
is a file manager. **This app doesn't force Penpot's flatness onto your Nextcloud
folders.**

A file's project is **the nearest ancestor folder carrying a Penpot project id**.
That one rule buys a lot:

```
Ferronescotia/                 ← Team Folder (team id in metadata)
├── Clients/                   ← just a folder you made. Penpot never sees it
│   ├── Acme/                  ← project folder (project id in metadata) 🏷
│   │   ├── Homepage.penpot    → belongs to the "Acme" project
│   │   └── wip/               ← just a folder
│   │       └── Draft.penpot   → still belongs to "Acme" (nearest ancestor)
│   └── Globex/                ← project folder 🏷
│       └── Brand.penpot       → belongs to "Globex"
└── notes.txt                  ← ignored entirely, never touched
```

Identity lives in **metadata, not in path**, so a project folder works the same
at any depth and you can reorganise freely. Project folders also carry a visible
**tag** (🏷) so you can spot and search for them among ordinary folders.

**The one restriction:** a project folder may move anywhere *inside* its Team
Folder, but **not out of it**. Moving a project between teams is a destructive
cross-team change that belongs in Penpot, not in a drag gesture.

### Personal designs land in your home folder

Every Penpot account has a personal "Default" team. It gets **no Team Folder** —
a personal space isn't a sharing boundary. Instead, its projects mount as folders
at the root of your Nextcloud home:

```
Your home/
├── Sketches/          ← your personal Penpot project 🏷
└── Logos/             ← another one 🏷
```

This is the one part of the mirror that uses **your own** token — a service
account can never be a member of your personal team.

### Access: a service account reads, you write as yourself

Penpot has no service-account or admin credential type: every access token is
scoped to a personal user account (confirmed structurally — there is no
`admin`/`system` RPC module, and the organization layer above teams is
permission-gated off on self-hosted instances). So the app splits the job in two:

| | Service-account token | Your personal token |
|---|---|---|
| **Required?** | **Yes**, per mapped team | **No** — optional |
| **Set by** | Admin, once | You, in personal settings |
| **Does** | All mirroring: list, export, pull | Attributes *your* changes to *you* |
| | | Pulls your personal projects |

**Why a service account is required.** It does all the reading, as one background
job. If the pull ran per-user, two people on the same Penpot team would both
write the same mirrored file from separate jobs — a real data race. One puller,
no race.

The cost: someone with authority over each Penpot team must invite the service
account as a **`viewer`** before that team can be mapped. That's not us being
strict — Penpot gives *no* credential an instance-wide view, so a team has to be
brought into scope explicitly either way. It doubles as a clean opt-in gate:
inviting the service account is how a team says *"yes, Nextcloud may manage this."*

**Why your own token is still worth setting.** Penpot attributes every change to
whoever's token made it. Without one, every change from Nextcloud shows up in
Penpot's history as the service account — forever, unfixable after the fact.

---

## Features

### Mapping a team

An admin maps a Penpot **team**; its projects come along automatically as folders.
There is no project-level mapping to configure — nothing to add, and nothing that
could get out of sync with what Penpot actually contains.

A mapped folder's name tracks Penpot's team name, so two Nextcloud setups mapping
the same team stay recognizable. Renaming the team in Penpot renames the folder on
the next pull; the mapping is keyed on the team **id**, so it survives.

Non-Penpot content inside a mapped folder is expected and never touched. The pull
only acts on files it recognizes by their metadata.

**Team Folders are optional, not a hard dependency.** When the `groupfolders` app
is installed, a mapped team becomes a real Team Folder — the closest match to
Penpot's own model, where the team *is* the access boundary. Without it, the app
falls back to an ordinary folder shared to the mapped group, and everything else
behaves identically (folder metadata works the same on both). Note that Team
Folder *creation* is admin-only by default in Nextcloud, so mapping a team is an
admin action unless delegation has been configured.

### The pull, and why it scales

`get-project-files` returns every file's `revn` (revision number) and `modifiedAt`
for a whole project in **one response**. So the pull compares revisions against a
listing it already has, and only exports files that actually changed *and* are in
`sync` mode:

```
per team:   1 × get-projects  +  1 × get-project-files per project
per file:   an export ONLY if mode == sync AND revn moved
```

A 100-file team where nothing changed costs a handful of API calls and **zero
bytes** — while still reconciling every rename and project move, because names and
project ids come back in that same listing.

Renames and moves *in Penpot* are reflected on the next pull. Renames and moves
*in Nextcloud* are yours to make freely, as long as a file stays under a folder
mapping to its real project.

### Create a design from Nextcloud

**New → Penpot design**, the same affordance the sibling apps offer. It appears
only where the target project is unambiguous:

| Where you are | Where the design is created |
|---|---|
| Inside a project folder | That project |
| At a Team Folder's root | That team's **Drafts** |
| In a plain folder under a team | That team's **Drafts** |
| Anywhere with no team above it | *The action isn't offered* |

### Drafts is a state, not a folder

Penpot calls a design that belongs to a team but sits in no project a **draft**.
It has one Drafts bucket per team, because a flat system has nowhere else to put
an unfiled design.

**We never create a "Drafts" folder.** Being in Drafts simply means *"under a
team, but not under a project folder"* — which falls straight out of the
nearest-ancestor rule. So this all works, and all of it is Drafts on Penpot's
side:

```
Ferronescotia/
├── Inbox/2026/sketch.penpot     → Drafts
├── Scratch/idea.penpot          → Drafts
├── quick-thing.penpot           → Drafts
└── Acme/                        ← a project folder 🏷
    └── Homepage.penpot          → the "Acme" project
```

**Nextcloud is more expressive than Penpot here, for free** — one flat bucket on
their side can be any folder tree you like on ours.

**And filing a draft is just a drag.** Move a file from anywhere under the team
into a project folder and the design moves into that project in Penpot. Drag it
back out and it returns to Drafts. The gesture you already know *is* the Penpot
operation.

### Copying a design (a plain local duplicate)

Copying a mirrored file **never creates a design in Penpot**. Someone dragging a
file with Ctrl held is organising files, not authoring work — and a Penpot design
appearing out of nowhere is something a whole team would see.

A copy made under a mapped project is **stripped of its `penpot_id`** and becomes
ordinary untracked content — keeping the id would give the pull two files claiming
to be the same design. A copy made outside every mapping **keeps** the id as a
historical record of where the archive came from, which is what makes a later
restore possible.

*(Penpot does have a real `duplicate-file` endpoint, and it works. A deliberate
"Duplicate in Penpot" action would be one cheap call — recorded as available, not
adopted.)*

**Copying a project *folder* is refused.** Three reasons, any one sufficient: the
copy would claim the same project id for its whole subtree; Nextcloud's automatic
`My Stuff (2)` suffix instantly breaks the name-matching rule, and "fixing" it by
renaming would rename the *original* Penpot project; and on a cluster running all
three sibling apps, one folder can carry Penpot, n8n **and** Grafana mappings at
once — a folder copy asks three independent apps to agree on what a duplicate
means. Copying ordinary folders and individual files is unaffected.

### Renaming

**Project folders** and their Penpot projects always share a name — the two are
never allowed to diverge. Rename the project in Penpot and the folder follows on
the next pull; rename the folder in Nextcloud and the project follows immediately.
Position stays yours; only the name is pinned.

That invariant is what makes the project tag meaningful: a tagged folder named
"Acme" *is* the Penpot project "Acme", at any depth, with no ambiguity.

Renaming a project folder is a genuinely different operation from renaming a file
— different Nextcloud event, different Penpot endpoint, no file extension to
handle — so it lives in its own spec, [`project-folder.feature`](features/project-folder.feature).

**One caveat runs backwards from expectation.** Penpot's naming rules are *looser*
than Nextcloud's: it accepts essentially any non-empty string, including `/`,
which can never be a folder name. So a project called `Has/Slash` gets a sanitised
folder name and the app tells you the names couldn't match — the project id stays
authoritative. Going the other way, anything you can name a folder, Penpot will
accept.

**Files** are different. Renaming a design in Penpot renames the mirror on the
next pull, in both modes, with no export needed. Whether renaming a file in
**Nextcloud** propagates back is a genuine open decision — a file's name is
cosmetic, where a project folder's name is identity-bearing. If it's ratified, the
behavior is already settled: your personal token attributes it to you, and a
failure leaves your local rename standing rather than reverting your work.

### Ignoring a design — keep the file, stop the mirroring

Tag a `sync` file with the app's ignore marker and this app takes its hands off:
never refreshed, never renamed, never moved, **never pruned** — even if the design
is deleted in Penpot. The archive stays yours.

This is the same state as moving a file out of every mapped folder — one
mechanism, two entrances. Either way, **nothing is deleted in Penpot.** "Taken out
of Penpot" describes the mirroring relationship ending, not a remote deletion.

Ignoring is refused on `link` files, with an offer to promote them to `sync`
first: a `link` file holds no archive, so an "ignored link" is a pointer to
something nobody is tracking — it looks like a backup and isn't one.

### Restore — putting a design back

"Restore" can mean several different things. The app picks the best path
available and tells you which one it used — best first:

| # | Situation | What you get back |
|---|---|---|
| 1 | Only the **Nextcloud** file was deleted | Everything — the design never moved |
| 2 | It's in **Penpot's trash** (~7 days) | **Everything** — id, revision, history, links |
| 3 | Deleted in Penpot, you have a `sync` archive | The design, not its id or history |
| 4 | Deleted in Penpot, rescued by a **final snapshot** | The design, not its id or history |
| 5 | A `link` whose design vanished over a week ago | Nothing |

**Row 2 is Penpot's own Trash, and it's the important one.** A design deleted in
Penpot stays recoverable for about a week, and it can be restored either by a
human in Penpot's UI or by this app calling Penpot's restore directly — both
bring it back with its id, revision and history intact. Either way, the next pull
finds your old mirror in the Nextcloud trash and puts it back rather than
creating a duplicate. Nothing to configure.

Rows 3 and 4 are best-effort: **a deleted Penpot design cannot be resurrected at
its original id**, verified against a live instance, so importing returns the
*artwork* rather than the *file* — same name, pages and assets, new identity, no
edit history.

**Row 5 is the only real loss, and the app works hard to avoid it.** When a pull
notices a `link` file's design was deleted, it takes a **final snapshot** first —
Penpot still lets us export a deleted design for about a week — writes that
archive into the file, and *then* moves it to the trash. You end up holding a
real `.penpot` file instead of a dead pointer.

**Penpot has its own safety net, and it's better than ours.** Deleting a design in
Penpot doesn't erase it immediately — Penpot retains the data for roughly **7
days** before a purge worker removes it. That grace period isn't reachable through
the API, so this app can't drive it, but if you deleted something recently,
**recovering it in Penpot's own UI keeps the id, the links, and the history.** The
app says so rather than quietly offering you the worse option.

### Deleting

Two independent layers, and the app always says which one it's acting on:

**Deleting the file in Nextcloud** is purely local. Trash, restore, and
empty-trash never contact Penpot. Restoring a mirror from the Nextcloud trash
re-adopts it — the pull won't leave you with a duplicate.

**"Delete in Penpot"** is a separate, explicit action — and it is **recoverable**.
Penpot has its own Trash, and this app uses it:

| What happens | What a restore returns |
|---|---|
| The design goes to **Penpot's trash** for ~7 days | **Everything** — same id, revision, history, links |
| After that window closes | Best-effort rebuild from your archive — the design, not its id or history |

Restoring inside the window costs nothing: the app calls Penpot's own restore and
the design comes back exactly as it was. Verified against a live instance — same
id, same revision, deep links working again.

**There is no trash-bin setting to configure.** An earlier design built a
parallel "trash project" inside a service account's team, on the mistaken belief
that Penpot's own trash was unreachable by API. It isn't — and Penpot's trash
preserves strictly more, with no configuration and without a design vanishing
into a robot's private team.

**After the window, a best-effort restore still gets your work back.** Measured
on a real round trip:

```
comes back:  name, pages, shapes, assets, even the revision number
does not:    the file id (old deep links stay dead), the edit history
```

Nobody loses design work — you lose undo-history and a URL.

**Permanent deletion is its own explicit action.** It's the only irreversible
call in the app, and an ordinary delete never reaches it.

⚠️ **Best-effort restore needs the bytes.** A `link` file holds no archive, so
there is nothing to rebuild from once the window closes. The app says so *before*
you delete one, and if a link's design is deleted in Penpot the pull takes a
[final snapshot](#restore--putting-a-design-back) rather than leaving you a dead
pointer.

**Deleting a link is different: it just hides it.** A link has no content, so
there is nothing to delete — trashing one is a *visibility* choice. The file goes
to the Nextcloud trash and the pull leaves it alone instead of recreating it.
Restore it from the trash to unhide it. **Penpot is never contacted either way:**
a link's design is never imported, moved, or touched, for any reason.

> The trash *is* the hidden marker — there's no separate setting or flag. One
> consequence worth knowing: emptying your trash forgets the dismissal, so hidden
> links reappear on the next pull.

When the pull finds a design deleted in Penpot by someone else, it moves the
local mirror to the **Nextcloud trash** — never a hard delete.

### Failures never cost you data

Penpot's transport has more ways to fail than a plain REST call — `export-binfile`
and `import-binfile` are both SSE streams, in Transit encoding, and the actual
bytes come from a second authenticated request. **HTTP 200 does not mean success**
here; an error arrives as an event *inside* a 200 response.

The rules that follow from that:

- A failed export or download **keeps the existing mirror**, never truncating it.
- Archives are written atomically — a file is the old version or the new one,
  never a half-written ZIP.
- **Pruning requires a clean listing.** A failed listing looks exactly like
  "everything was deleted." An expired token, a network blip, or a lost team
  invitation never prunes anything.
- A failed write leaves your local change standing and reports the divergence.

### A first-class file type

Mirrored files get a custom Penpot mimetype and icon rather than showing as
generic archives, and expose their state over WebDAV:

| Property | What it is |
|---|---|
| `nc:metadata-penpot_id` | The Penpot design id — stable across renames and moves |
| `nc:metadata-penpot_revision` | Penpot's `revn` + `modifiedAt`, the drift signal |
| `nc:metadata-penpot_mode` | `sync` or `link` |

Folders carry `penpot_project_id` / `penpot_team_id` the same way. All of it is
read-only over DAV — the sync engine owns these properties.

---

## Administration

### Penpot connection

Two cards, deliberately separate:

- **Instance** — the base Penpot URL. Admin-scoped, no credential field.
- **Service account** — the required token that does all mirroring. Stored
  encrypted, never echoed back.

A connection test distinguishes *unset* from *rejected*, and names the required
Penpot instance flag if it's missing.

### Personal settings

Each user can store their own Penpot access token. Optional everywhere except
personal projects. Clearing it degrades attribution; it never stops team
mirroring and never deletes anything.

### Required Penpot instance flag

| Flag | Why |
|---|---|
| `enable-access-tokens` | Lets a Penpot user mint the token this app authenticates with. Off by default upstream. |

> `enable-webhooks` was expected to be a second requirement, as a fast-path
> trigger for the pull. It isn't currently part of the design: webhook *creation*
> works, but **delivery has never been observed** — two confirmed mutations
> against a validated webhook produced zero deliveries. Until that's explained,
> the scheduled pull is the only trigger.

---

## Status

Early development, pre-alpha, **version 0.1.0**.

**Implemented:** the admin Instance card (Penpot base URL) and its two `occ`
commands, so the app can be pointed at an instance entirely headlessly. Covered
by unit tests and by six Behat scenarios that install the app on a real Nextcloud
and drive the CLI.

**Not implemented:** everything else — credentials, mappings, the pull, file
actions, the frontend. There is no `src/` yet.

**Design chapter 1 is closed.** The [`saga/`](saga/) is the authoritative
"where are we" record, ahead of this README and the feature files. Start with
[Chapter 1: First Contact](saga/Chapter_1_First_Contact.md) — read §6.18–§6.48
first if you want the decisions rather than the survey that produced them, and
its closing section for what's settled, what's open, and where to build next.

### Executable specification

The specs *are* the requirements, read before any code lands.

| File | What it covers |
|---|---|
| [`admin-connection.feature`](features/admin-connection.feature) | The URL card, the required service token, the optional personal token. |
| [`personal-settings.feature`](features/personal-settings.feature) | The per-user token page. |
| [`personal-projects.feature`](features/personal-projects.feature) | Personal projects at the user's home root. |
| [`admin-mapping.feature`](features/admin-mapping.feature) | Mapping a team; the service-account precondition. |
| [`mapping-membership.feature`](features/mapping-membership.feature) | The nearest-ancestor rule — the app's most load-bearing spec. |
| [`sync-mode.feature`](features/sync-mode.feature) | `link` vs `sync`, promotion, and the one lossy direction. |
| [`reconcile.feature`](features/reconcile.feature) | The pull, revision gating, and safe pruning. |
| [`create-design.feature`](features/create-design.feature) | New → Penpot design, and Drafts semantics. |
| [`move.feature`](features/move.feature) | Free nesting; the project-folder restriction. |
| [`project-folder.feature`](features/project-folder.feature) | Renaming, tagging, and why copying a project folder is refused. |
| [`copy.feature`](features/copy.feature) | Copies never create designs. |
| [`rename.feature`](features/rename.feature) | Penpot→NC (settled) vs NC→Penpot (open fork). |
| [`ignore.feature`](features/ignore.feature) | Stop mirroring without losing the file. |
| [`restore.feature`](features/restore.feature) | Putting a design back, and what it can't recover. |
| [`delete.feature`](features/delete.feature) | Local-only deletes; Penpot's 7-day grace period. |
| [`errors.feature`](features/errors.feature) | Every failure mode, and why none of them prune. |
| [`file-type.feature`](features/file-type.feature) | Mimetype, icon, and the metadata key set. |
| [`open-with.feature`](features/open-with.feature) | Open in Penpot — no text-editor fallback, ever. |
| [`purge.feature`](features/purge.feature) | Reset the Nextcloud side without touching Penpot. |
| [`remove-mapping.feature`](features/remove-mapping.feature) | Tearing down a mapping safely. |
| [`uninstall.feature`](features/uninstall.feature) | Mimetype cleanup; data orphaned, not deleted. |
| [`lifecycle.feature`](features/lifecycle.feature) | App enable/disable. |
| [`team-import.feature`](features/team-import.feature) | **Speculative** — importing a team from personal settings. |

Deliberately **not ported** from either sibling: `tag-sync.feature` and
`reserved-tags.feature` (Penpot has no tags at all).

---

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for process and [AGENTS.md](AGENTS.md) for
a cold-start orientation.

This is a community integration and is not affiliated with, endorsed by, or
sponsored by Penpot (Kaleidos Ventures SL). "Penpot" and the Penpot logo are
trademarks of their respective owner, used here only to identify the service this
app integrates with.
