# Penpot Sync

A Nextcloud app that mirrors your Penpot design files into the Files app — a
click-through to every design, a real backup for the ones that matter, and a
folder tree you can organise however you like.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-30--33-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb4?logo=php&logoColor=white)](composer.json)

> **Status: pre-alpha — most of what follows is design, not shipped behavior.**
>
> **What works today:**
>
> - The app installs on Nextcloud and you can point it at a Penpot instance —
>   an admin setting plus `occ penpot_sync:set-url` / `occ penpot_sync:show-config`.
> - A **Penpot API client**: Transit decoding, the per-command parameter table,
>   and typed errors. Store a service-account token with
>   `occ penpot_sync:set-token`, then check the connection with
>   `occ penpot_sync:test-connection` — it reports which teams that token can
>   actually see, which is what decides what you can map.
> - **The complete admin surface**: instance URL, service-account credential,
>   team mappings, scheduled-pull settings, and an optional per-user token for
>   attribution. Every control persists and has an `occ` twin
>   (`list-teams`, `add-mapping`, `list-mappings`, `remove-mapping`, …).
> - **The pull** (Penpot → Nextcloud): `occ penpot_sync:sync pull` mirrors a
>   mapped team into a plain Nextcloud folder — projects become folders and files
>   become `.penpot` files, each stamped with Penpot metadata, and re-pulling
>   reconciles in place instead of duplicating. `occ penpot_sync:status <path>`
>   shows a node's metadata, what the file actually holds, and the project/team
>   the **membership resolver** derives by walking its ancestor folders.
> - **Moving things** — the two illegal moves are refused *before* they happen
>   (a project folder leaving its team folder; a `link` file changing project),
>   and moving a stored design between project folders re-files it in Penpot for
>   real via `move-files`.
> - **`sync` mode — real archives.** `occ penpot_sync:set-mode <path> sync`
>   exports a design from Penpot and stores the actual `.penpot` ZIP in
>   Nextcloud; `… link` empties the file again (after confirming, as that
>   deletes a local backup). A pull re-exports a `sync` file only when its
>   Penpot revision moved or its archive went missing, so **a team of links
>   costs zero exports.**
> - **The prune, with a parachute.** A design deleted in Penpot no longer leaves
>   a mirror that opens nothing: the pull moves it to the **Nextcloud trash** —
>   never a hard delete — and a pointer gets one last export on the way out, so
>   what lands in the trash is a real, openable archive. Any incomplete listing
>   switches pruning off entirely, because a network blip and "everything was
>   deleted" look identical from here.
>
> Verified against a real Nextcloud *and* a real Penpot in CI — including a pull
> asserted end-to-end against a project seeded directly in Penpot, and a
> promotion asserted to leave real ZIP bytes on disk.
>
> **It mirrors, it keeps the bytes you ask it to, and it respects your folder
> layout.** It is still narrow: only the plain admin-owned folder backend (the
> groupfolders Team Folder backend, the Files-app surface, and the remaining
> write-back paths are the next slices), and controls that configure an unbuilt
> part still say so. The admin surface was built *whole* first so that every
> later feature is something you configure rather than something that ships
> twice.
>
> **Everything else below is the design**, written as if it already worked
> because that is how the spec is written. Each `.feature` file stays tagged
> `@todo` until its slice ships. Treat it as a detailed design document backed by
> a live-verified API survey. See [Status](#status).

---

## How it works (the design)

Penpot Sync walks your mapped Penpot teams on a schedule and reflects them into
Nextcloud. Each Penpot **team** becomes a Team Folder; each **project** inside it
becomes a folder; each **design** becomes a `.penpot` file with a real icon and a
deep link back to the live design in Penpot.

```
Penpot                              Nextcloud
─────────────────────────────       ─────────────────────────────────────
team    "Northwind"            ⟶    Team Folder  Northwind/
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
| **`link`** *(default)* | An empty file that points at the live design. Opens in Penpot. | Nothing — never exports, stores no bytes |
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
- No design content is ever written back. Deleting *is* passed on — to Penpot's
  own trash, which is as reversible as your Nextcloud one, and restoring the
  file brings the design back with it. Nothing is destroyed until you empty the
  trash, and even then only if Penpot still has it in its own.

### What *can* reach Penpot, and why it's safe

The app isn't inert — it just never does anything destructive by accident. The
complete list:

| What you do | What happens in Penpot | Reversible? |
|---|---|---|
| Drag a design into another project folder | It moves to that project | Drag it back |
| Drag it to the team root | It moves to Drafts | Drag it back |
| **New → Penpot design** | A design is created | Delete it |
| Copy a design file | A real copy is created | Delete it |
| Rename a design or project folder | It's renamed | Rename it back |
| Delete a mirror | Moved to **Penpot's trash** | Restore it from your trash |
| Restore a mirror from your trash | Taken back out of Penpot's trash | Delete it again |
| **Empty your trash** | Permanently deleted **— only if still in Penpot's trash** | **NO** |

**Exactly one operation in the entire app destroys anything** — emptying your
Nextcloud trash, the one gesture Nextcloud itself treats as irreversible.
Everything else is additive or reversible, and the delete/restore pair mirrors
Penpot's own trash step for step. That, rather than a short list, is what the
read-only promise actually protects.

### Nesting: flat in Penpot, however you like in Nextcloud

Penpot's hierarchy is rigid — team → project → file, no sub-projects. Nextcloud
is a file manager. **This app doesn't force Penpot's flatness onto your Nextcloud
folders.**

A file's project is **the nearest ancestor folder carrying a Penpot project id**.
That one rule buys a lot:

```
Northwind/                     ← Team Folder (team id in metadata)
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

### Two folder modes, chosen per team

The nesting above is **`nested` mode** — the default, and what the rest of this
README describes. There is one alternative, and the two are mutually exclusive:

| Mode | Penpot side | Nextcloud side |
|---|---|---|
| **`nested`** *(default)* | Projects are plain names; `/` is not allowed in one | Nest freely under the Team Folder |
| **`keyed`** | A project's **name is its path** — `foo/bar` | Mirrors that path exactly; no free nesting |

Either `/` carries structure or it doesn't — it can't do both, which is why this
is a choice rather than a feature. A team that already types `client/project` in
Penpot picks `keyed`; a team that organises in Nextcloud picks `nested`.

**The choice is made when the team is mapped and cannot be changed afterwards.**
Flipping it would restructure every folder *and* rewrite every project name in
Penpot — a bulk, two-sided migration behind a dropdown. Changing your mind means
removing the mapping and re-adding it.

> **`keyed` mode is designed, not built.** Only the choice is settled; the mode
> itself waits for a later release. Everything below describes `nested`.

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

**You name the folder; Penpot names the projects.** A mapping binds a Penpot team
to a Nextcloud folder, and the folder can be called whatever suits your instance.
Leave the name blank and it defaults to the Penpot team's own name — the same
rule the Grafana integration uses for its folder mappings, so the two behave
alike. The mapping is keyed on the team **id**, so renaming the team in Penpot
never breaks it, and never silently renames the folder you chose.

Project folders *inside* the mapped folder are the exception: they always match
their Penpot project's name exactly, in both directions. A rename in Penpot
propagates down on the pull, and renaming a project folder in Nextcloud renames
the project upstream. The reasoning for the split: a team folder is a mount point
you chose to create, so naming it is yours; a project folder is a mirror of a
Penpot object, and letting its name drift would break the identity the pull uses
to match folders to projects.

Two mappings cannot target the same Nextcloud folder — their project subfolders
would interleave and the pull would fight over the same names on every run.

**Most of a mapping is fixed once it is created**, following the same rule the
n8n and Grafana integrations use: a field is immutable when changing it would
force a live migration of already-mirrored content. The team, the Nextcloud
folder, the Team Folder setting, the default mode and the folder mode are all set
at creation; the groups the folder is shared with stay editable. To change
anything else, remove the mapping and add it again — which makes the cost
visible instead of hiding it behind a dropdown.

The **default mode** is the one place this app is stricter than the Grafana
integration, which leaves its mode editable. Here the axis decides whether the
app *holds the bytes*, so flipping it in bulk would either delete every
downloaded archive under the mapping or export every file at once. Promote or
demote individual files instead.

Non-Penpot content inside a mapped folder is expected and never touched. The pull
only acts on files it recognizes by their metadata.

**Each mapping carries the Nextcloud groups its folder is shared with**, exactly
as the n8n and Grafana integrations do — same control, same meaning, same
defaults, so configuring all three is the same act each time. Groups start empty
and are opt-in.

**Team Folders are optional, not a hard dependency.** When the `groupfolders` app
is installed, a mapped team becomes a real Team Folder — the closest match to
Penpot's own model, where the team *is* the access boundary. Without it, the app
falls back to an ordinary folder shared to the mapping's groups, and everything
else behaves identically (folder metadata works the same on both). Note that Team
Folder *creation* is admin-only by default in Nextcloud, so mapping a team is an
admin action unless delegation has been configured.

> The groups and Team Folder settings **persist today and are honoured when the
> pull provisions the folder** (not yet built) — the same "saved now, applied
> later" state the Grafana integration ships them in.

### The admin section

Laid out to match the n8n and Grafana integrations, so an admin who has
configured one already knows where to look:

| Panel | What's in it |
|---|---|
| **Instance** | The Penpot base URL and the service-account token. |
| **Sync Settings** | Whether the pull runs on a schedule, and how often. |
| **Team mappings** | One card per mapped team — folder name, mode, sharing. |
| **Sync Actions** | Every button in the section: Test connection, and (later) bulk sync and purge. |

Each user also gets a personal **Penpot** section holding their own optional
access token, used only to attribute their changes in Penpot's history.

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
Northwind/
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

| # | Situation | What you get back | |
|---|---|---|---|
| 1 | Only the **Nextcloud** file was deleted | Everything — the design never moved | ✅ |
| 2 | It's in **Penpot's trash** (~7 days) | **Everything** — id, revision, history, links | ✅ |
| 3 | Deleted in Penpot, you have a `sync` archive | The design, not its id or history | not yet |
| 4 | Deleted in Penpot, rescued by a **final snapshot** | The design, not its id or history | not yet |
| 5 | A `link` whose design vanished over a week ago | Nothing | — |

**Rows 1 and 2 need no thought and no clicks: just restore the file from your
Nextcloud trash.** The app works out which case it is and does the least
destructive thing that applies. If the design never left Penpot, nothing is sent
at all; if it's in Penpot's trash, the app takes it back out — with its id,
revision, history and links intact — and confirms by re-reading, because Penpot's
restore command reports success for ids it did not restore. Either way you end up
with one mirror, not two, and the next pull leaves it alone.

Rows 3 and 4 are best-effort and **not built yet**: **a deleted Penpot design
cannot be resurrected at its original id**, verified against a live instance, so
importing returns the *artwork* rather than the *file* — same name, pages and
assets, new identity, no edit history. That's a trade worth making, but it's
yours to make, so it needs a confirmation step rather than a listener. Until it
lands, the app tells you when you're in this case instead of failing quietly:
the design is gone from Penpot and your file is the only copy of it.

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

**Each Nextcloud gesture gets the Penpot operation with the same
reversibility** — that symmetry is the whole design:

| You do | Penpot does | Get it back by |
|---|---|---|
| Delete a mirror (→ your trash) | The design goes to **Penpot's trash**, ~7 days | Restoring the file |
| Restore it from your trash | The design comes **back out**, losslessly | — |
| Empty your trash | The design is **permanently deleted** | Nothing. This is the irreversible one |

An earlier version of this app kept the delete purely local, on the belief that
Penpot's trash was unreachable by API. It isn't — and once that's true, "purely
local" stops being the safe choice and starts being the surprising one: someone
who deletes a design in Nextcloud and finds it still in Penpot hasn't been
protected, they've been ignored.

**Restoring inside the window costs nothing.** The app calls Penpot's own restore
and the design comes back exactly as it was — verified against a live instance:
same id, same revision, deep links working again.

It does not trust the reply. Penpot's restore answers "success" for ids it did
not restore, and it answers *before its own transaction settles*, so the app
re-reads the design's project listing — the same listing the sync reads — and
calls the restore a second time if the design is not in it yet. That is the
difference between your file coming back and your file coming back for ninety
seconds until the next sync tidies it away again.

**There is no trash-bin setting to configure.** An earlier design built a
parallel "trash project" inside a service account's team, on the same mistaken
belief. Penpot's own trash preserves strictly more, with no configuration and
without a design vanishing into a robot's private team.

**Emptying your trash is the irreversible one, and it has a guard.** Penpot's
`permanently-delete-team-files` does *not* check that a design is in the trash —
proven live on a design that had been restored, which it destroyed anyway. So the
app reads Penpot's trash listing first and passes on only ids that come back in
it. If someone restored the design in Penpot in the meantime, emptying your trash
leaves it alone.

⚠️ **After the window closes, only the bytes are left.** A `sync` file holds a
real archive and a `link` holds none, so if a link's design is deleted in Penpot
the pull takes a [final snapshot](#restore--putting-a-design-back) on its way out
rather than leaving you a dead pointer. Rebuilding a design *from* that archive
is the one restore path still to come — see rows 3 and 4 above.

**Deleting a `link` currently behaves like deleting a `sync`** — the design goes
to Penpot's trash, and restoring the file brings it back. The intended end state
is different: a link holds no content, so trashing one should be a *visibility*
choice that Penpot never hears about, with the trashed file itself acting as the
"hidden" marker. That needs the pull to read your trash before it recreates a
mirror, or a dismissed link would simply reappear on the next run — so the two
land together, in a later release, and until then the delete is uniform.

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

The mimetype is `application/vnd.penpot`, and it carries no `+json` / `+zip`
suffix on purpose: a `sync` mirror really is a ZIP archive while a `link` mirror
holds nothing at all, so either suffix would be wrong for half your files.
Removing the app reverts the registration and leaves Nextcloud as it found it.

**A `link` file is empty — zero bytes.** Everything that identifies it (the
design id, the revision it reflects, its mode) lives in the metadata above, so a
body would only be a second copy of the same facts, free to drift from the first.
It is deliberately *not* a small placeholder archive either: that would be
indistinguishable from a real export, which is how you end up trusting a backup
that was never taken. `occ penpot_sync:status` tells you which a file is.

### Opening a design

Clicking a mirrored `.penpot` file opens the live design in Penpot. That is the
only opener it gets — unlike this app's siblings for n8n and Grafana, there is no
"edit as text" action, in any mode, for any file. A `.penpot` archive is opaque
nested design data; there is nothing coherent to hand-edit and no way to
re-import it if there were.

`sync` and `link` files open **identically**. The mode decides whether the
archive is stored on your Nextcloud, never whether the design can be opened.

The link is built from the design id the file already carries, so it keeps
working after you rename the file or drag it somewhere else — including out of
its mapped folder entirely. The one case where the action disappears is a file
whose design was deleted in Penpot: that id is permanently dead, so the app hides
the action rather than send you to a 404.

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

### Required Nextcloud setting, if Penpot is not public

If Nextcloud reaches Penpot at a private or in-cluster address — a Kubernetes
service name, a LAN IP, `localhost` — Nextcloud's SSRF guard blocks the request
before it leaves:

```
Host "penpot.cloud.svc.cluster.local" violates local access rules
```

```bash
occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

`occ penpot_sync:probe` reports this case by name rather than as a generic
connection failure, so you should not have to guess. Not needed when Penpot is
on a public hostname.

> `enable-webhooks` was expected to be a second requirement, as a fast-path
> trigger for the pull. It isn't currently part of the design: webhook *creation*
> works, but **delivery has never been observed** — two confirmed mutations
> against a validated webhook produced zero deliveries. Until that's explained,
> the scheduled pull is the only trigger.

---

## Status

Early development, pre-alpha, **version 0.1.0**.

**Implemented:** the complete admin surface — Instance (URL + service-account
token), Sync Settings, Team mappings, and Sync Actions — plus a personal
per-user token page. Every control persists and has an `occ` twin
(`set-url`, `set-token`, `test-connection`, `list-teams`, `add-mapping`,
`list-mappings`, `remove-mapping`, `set-personal-token`, `show-config`, `probe`).

On top of that, **the mirror itself**: `sync pull` walks a mapped team into a
plain Nextcloud folder, `status` inspects any node (metadata, resolved
membership, and whether the file holds a real archive or a pointer), `set-mode`
promotes a design to a stored `.penpot` archive or demotes it back, a move
between project folders is either refused or propagated to Penpot, and a design
deleted in Penpot has its mirror snapshotted and moved to the Nextcloud trash.
Covered by unit tests and by Behat scenarios that install the app on a real
Nextcloud and drive the CLI against a real Penpot — including an export asserted
to land real ZIP bytes on disk.

The Files-app surface has opened: a mirrored design carries its own file type and
icon, and **"Open in Penpot"** is the default click — a deep link built from the
id the file already carries, so it survives being renamed and moved. It is the
only opener a `.penpot` file gets; there is deliberately no "edit as text".

**Not implemented:** creating designs from Nextcloud, the ignore and restore
actions, the mode pills, refusing to download a `link` file as though it were an
archive, adopting a mirror back out of the Nextcloud trash, and personal
projects. The scheduled pull is configurable but does not yet run.

**The [`saga/`](saga/) is the authoritative "where are we" record**, ahead of
this README and the feature files.
[Chapter 1: First Contact](saga/Chapter_1_First_Contact.md) is the API survey and
the decisions it forced — read §6.18–§6.48 first if you want the decisions rather
than the survey that produced them, and its closing section for what's settled,
what's open, and where to build next. [Chapter 2: The
Colony](saga/Chapter_2_The_Colony.md) is what has actually been built, course by
course, and its table is the honest map of what is done and what is next.

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
| [`set-mode.feature`](features/set-mode.feature) | **Live.** The real export: promotion leaves ZIP bytes, links cost zero exports. |
| [`reconcile.feature`](features/reconcile.feature) | The pull, revision gating, and safe pruning. |
| [`prune.feature`](features/prune.feature) | **Live.** A deleted design's mirror is snapshotted, then trashed — and an unchanged pull prunes nothing. |
| [`pull.feature`](features/pull.feature) | **Live.** The pull as CI proves it, end to end against a real Penpot. |
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
