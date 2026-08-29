<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Chapter 4 — Open for Business

> Transmission log, Probe Designation **PENPOT-1**.
> Reclassified this chapter: **Trade and Settlement**.
>
> **Prerequisite:** Chapter 3 (*Building to Plan*) closed **complete**, and it
> closed on a sentence that is this chapter's whole brief: *the doors open in
> Chapter 4*.
>
> **The colony works.** Both directions of sync, every design verb and every
> project verb, deployed on a real Nextcloud mapped to two real Penpot teams and
> used daily by the people who built it. Three of the last bugs were found by
> living in it rather than by testing it, which is the strongest thing Chapter 3
> can say about itself. Its close carries the inventory; this chapter does not
> restate it.
>
> **And nobody outside it knows.** That is not a small remaining task. A colony
> nobody can find is a colony nobody joins, and every artefact a visitor would
> arrive at — the README, the app-store listing, the admin panel's own copy —
> was written by and for the crew that built the place.
>
> Chapter 1 asked *can we dock?* Chapter 2 asked *what do we build?* Chapter 3
> asked *is it actually there?* **Chapter 4 asks who it is for, and whether they
> could tell.**

---

## Status: **OPEN** — 2026-08-29

The first rounds are landed and merged. What follows is the record.

---

## The doctrine — the pitch is a feature, and it had rotted

Chapter 3's doctrine was *a drawing is not a building*. This chapter's is its
sequel, and it is less obvious:

**A building nobody can read the sign on is not open.**

Every one of this project's outward-facing surfaces had been written as an
engineering artefact by someone who already knew the answer. Not badly written —
written for the wrong reader:

- The **README** opened with a `Status: pre-alpha` block and 827 lines of design
  rationale, including sections arguing against decisions nobody was proposing.
- The **admin panel's field help** ran two to four times the length of the
  sibling apps', and three of its strings were actively false — one told every
  admin their mapping did nothing.
- The **`info.xml`** — the app store's entire shopfront — had no screenshots, no
  `<documentation>` block, and a description written as an essay.
- The **`AGENTS.md` files** carried the project's history inline, so a retired
  decision read exactly like a live one.

None of that was a bug in the sense Chapter 3 meant. Every one of it was a defect
in the sense this chapter means: **it cost a reader something.**

### The rule that came out of it

> **Documentation rots in the direction of its author.** A file written by the
> person who built the thing drifts toward being about the building of it. The
> fix is not to write more carefully; it is to name the reader, and to give the
> reasoning somewhere else to live.

That second clause is §D4.2, and it is the load-bearing decision of the chapter.

---

## The decisions

Numbered `§D4.n` — Chapter 1 used `§6.n`, Chapter 2 `§C6.n`, Chapter 3 used
rounds. Cite these the way the others are cited.

### §D4.1 — Decision (locked): the README is an advertisement

**The call:** `README.md` sells the app. It says what someone can do, brags a
little about the most interesting behaviour, and stops. It does not carry status,
roadmap, rationale, or an argument with a previous version of itself.

`kubed-io/nextcloud-n8n`'s README is the reference implementation and the bar —
and the bar is a floor, not a ceiling.

**What this rules out**, because each of these was in the file:

| Removed | Why |
|---|---|
| A `Status: pre-alpha` block, first thing after the badges | A reader deciding whether to install does not want the build's self-assessment first |
| A five-row "best restore path available" table | Design deliberation, not a feature |
| Per-section notes on what was *not* built | The absence of a feature is not a feature |
| Sibling-app comparisons | The reader has not read the siblings |
| Networking and instance-flag setup detail | See §D4.4 |

**The test:** if a section's subject never appears in the sibling's README, it is
probably the author talking to themselves.

### §D4.2 — Decision (locked): the documentation cascade

**The call:** four documents, one hop per level of detail, each linking to the
next. **History lives in the saga and nowhere else.**

| Level | Document | Holds | Tense |
|---|---|---|---|
| 1 | `features/**/*.feature` | The specification | present |
| 2 | `features/AGENTS.md` | Why a scenario is the shape it is | present |
| 3 | `saga/` | What was decided, what it replaced, what proved it | **past** |
| — | `README.md` | What a user can do | present |

Every scenario already ended with a `# notes:` breadcrumb into level 2. Level 2
now opens every section with a `saga:` pointer into level 3, so a reader stops at
the depth their question needs.

**Why this is a decision and not a tidy-up.** Round 10 of Chapter 3 established
that a retired mechanism with live code behind it *will get proposed again*. The
same is true of prose: a withdrawn decision sitting in a working document is
indistinguishable from a live one, and it will be read as current. The cascade is
that finding applied to the docs.

**The obligation it creates:** a note that opens *"this used to…"* is in the
wrong file. Move it down a level and leave a pointer.

**What it moved.** Several hundred lines: seven retired feature files documented
in `features/AGENTS.md`, four more sections buried inside current ones, a
round-by-round queue narrative in `features/README.md`, and 180 lines of the root
`AGENTS.md` restating saga decisions §6.18–§6.48.

**That last one is the argument in miniature**, because every restatement had
rotted: it called the trash bin *adopted* (withdrawn, §6.34 → §6.52), said a
project folder can never leave its team folder (it can, §C6.38), documented the
ignore marker (retired), and listed the §6.2 rename fork as open (§6.54 closed
it). Four wrong facts in one section, each correct when written.

### §D4.3 — Decision (locked): admin copy describes the field, not the code

**The call:** one sentence per field, matching the sibling's density. A tooltip
says what the field is and what it costs to change. It never says where the
implementation stands.

Three strings were not merely long:

- The mapping panel's footer read *"Nothing is mirrored yet — the pull is not
  built (see the project roadmap)."* The pull had worked for courses. **The panel
  was telling admins their mapping did nothing.**
- Mode's help offered *"promote or demote individual files instead"* — a
  capability that does not exist, and which `PullService`'s own comment says was
  retired.
- The base-URL help gave an in-cluster Kubernetes service address as its example.

**The pattern worth keeping:** a status note in a UI string is worse than the
same note in a comment, because it outlives its truth in front of a user who
cannot check it.

### §D4.4 — Decision (locked): user-facing docs make no infrastructure assumptions

**The call:** nothing user-facing describes how anyone runs Penpot or Nextcloud.
No cluster addresses, no ingress or TLS notes, no SSRF configuration, no
deployment topology. The URL example is `https://penpot.example.com`.

The homelab this was built on is one deployment of many, and its shape is not a
fact about the app. Operator detail belongs in the cluster repository, which
already has it.

**One clause survives** and earns its place: `enable-access-tokens` must be on,
because without it there is no Access tokens page and the admin is stuck with no
way to know why. That is a prerequisite of the *field*, not of a deployment.

### §D4.5 — Decision (locked): screenshots are a shared convention

**The call:** `screenshots/` at the repository root, 600px-wide copies in
`screenshots/thumbnails/`, and `info.xml` naming both by raw URL. Names are
shared with the sibling apps wherever the surface is shared —
`connection.png` and `admin-actions.png` are identical across the family,
`team2folder-mapping.png` mirrors n8n's `tag2folder-mapping.png`, and
`penpot-files.png` mirrors its `n8n-files.png`.

Three properties, each learned by getting one wrong first:

- **Never upscale.** A source narrower than 600px is copied, not enlarged.
- **The store fetches by raw URL from `main`**, so a rename that misses
  `info.xml` shows the listing a broken image. The list is a coupling and says so
  in a comment.
- **Screenshots do not ship in the release tarball.** `package.yml` copies an
  explicit allowlist, which excludes them for free.

**The pairing decision.** The two team screenshots are one Penpot team seen from
both sides, so they sit side by side under the diagram they prove — Nextcloud
left, Penpot right. Markdown has no float, so the copy that fills the space
beside the tall image lives in the same table cell as the short one. This is
presentation, not content, and it is written down only because the next person
will otherwise re-derive why the table is shaped that way.

### §D4.6 — Decision (locked): `info.xml` is a store listing

**The call:** the description follows the sibling's shape — what it does, a
*"what you can do"* list, a section per distinctive behaviour, a documentation
link, the trademark notice. It is the only outward artefact most people will ever
read, and it is not a place for reasoning.

Three defects fixed alongside:

- `<licence>agpl</licence>` → `AGPL-3.0-or-later`. The shorthand is deprecated
  and the store scopes it to apps targeting Nextcloud ≤ 30; this app requires 32.
- No `<documentation>` block at all. Now user → README, admin → the setup
  section, developer → `CONTRIBUTING.md`.
- The summary said **read-only**, which undersells it and has been imprecise
  since Chapter 3's Round 8. Renames, moves, copies, deletes and restores all
  reach Penpot. It is the design *content* that never flows back.

### §D4.7 — Decision (locked): an agent does not open a pull request unasked

**The call:** opening a PR requires the maintainer's explicit approval, in that
session, for that PR. Work in flight on an open branch is a **commit on that
branch**, never a second PR.

This is in `AGENTS.md` as its own 🚨 section, above *First moves*, in the
position and register the sibling gives its version rule — and it carries a table
of the things that are **not** approval, because each row is a rationalisation
that was actually used:

| Not approval | |
|---|---|
| *"and then let's get the next PR started"* | Approves **one**. Spend it once. |
| Approval of an earlier PR this session | Each is its own ask. |
| The work being finished and green | Finishing is not permission to publish. |
| The task obviously ending in a PR | Say it is ready, and ask. |

**Why it is a saga decision and not a preference.** Deciding that finished work
belongs in its own PR re-scopes a decision that was the maintainer's, and the
usual cause is a first PR drawn too narrow. The failure happened here, was caught
by the maintainer rather than by any check, and the two PRs were merged back into
one.

### §D4.8 — Decision (locked): before a first release there is no *Changed* and no *Fixed*

**The call:** while nothing has shipped, `CHANGELOG.md` carries an `Added` list
and nothing else.

Both other headings are relative to a version somebody is running. Nobody is
running one, so every bug this project fixed was fixed before a user could meet
it, and describing those fixes describes a past they never had. A first release
has exactly one thing to say: here is what it does.

**The state it replaced** was 207 lines with three `Added` sections, three
`Changed` and two `Fixed`, interleaved in build order — an accurate record of how
the app was made, filed where a user looks for what it is. The record was not
lost; §D4.2 says where it goes.

The rule expires at the first release. From `0.1.0` onward, `Changed` and `Fixed`
mean what Keep a Changelog says they mean.

---

## The rounds

### Round 1 — the README stops being a design doc

827 lines to 224. The rewrite was from scratch against the feature suite and the
code, because respecting the existing text guarantees inheriting its assumptions.

**Seven claims in it were no longer true**, which is the finding rather than the
length. A README nobody re-reads against the code drifts exactly as far as the
code moves:

| It said | Actually |
|---|---|
| A copy never creates a design in Penpot | It creates a real one, with its own id |
| A `/` in a project name is skipped and reported | The name **is** a path, both directions |
| Only a folder with no project above it is promoted | Every folder, any depth (Round 10) |
| A push is permanently off the table | *Sync to Penpot* shipped in Round 8 |
| Tag a file with the ignore marker | Retired; no scenario anywhere |
| A five-row "best restore path" narrative | A link is refused for trash, copy, create and move-out |
| — | Cross-team moves keep id, revision and history |

### Round 2 — the copy, the cascade, and a guard with its own bug

The admin surface (§D4.3) and the documentation cascade (§D4.2) landed together,
and the sweep that implemented the cascade found five load-bearing comments that
were false:

- `PenpotClient::createProject()` documented *"exactly one caller, `onTagged()`"*
  and tag-only promotion. Two callers, and promotion by content is the normal
  route since Round 10. `Application.php` and `ProjectTags` repeated it.
- `MappingService::updateGroups()` and `PenpotClient::fileExists()` each claimed
  one caller; both have two.
- `ScheduledPullJob`'s 60-second floor never binds — `ScheduleConfig` clamps to
  300 first.
- 75 references across `lib/` and `features/AGENTS.md` pointed at feature files
  retired by §C6.38.

**And two live notes were filed inside `RETIRED` sections.** `designs/create.feature`
had been pointing into `## mapping-membership — RETIRED` for its Drafts rule and
`lifecycle.feature` into `## uninstall — RETIRED`. Both anchors resolved, so CI
was green the whole time.

> **`check-notes-anchors.sh` proves a pointer LANDS, not that it lands somewhere
> true.** The guard could not have caught this, and it is the second time in two
> chapters that a check has been mistaken for the property it approximates.

**The guard also had the bug it exists to prevent.** Its slug rule collapsed runs
of spaces; GitHub's does not, so a heading containing an em-dash produces a
*double* hyphen. Three breadcrumbs passed CI and 404ed for anyone who clicked
them — and nobody clicks a breadcrumb in CI, which is the entire reason the
script exists.

### Round 3 — the shopfront

Screenshots (§D4.5) and the store listing (§D4.6).

**No image tooling in the dev pod** — no PIL, no ImageMagick, no `file`. The
Nextcloud pod has both GD and Imagick, so the thumbnails were generated there:
copy in, resize, copy out. Recorded because the constraint recurs and the
workaround is not obvious.

The first pass used GD and produced thumbnails **larger** than their sources —
GD writes truecolour where the originals were palette PNGs. Imagick with
quantisation fixed it. The first pass also upscaled a 554px-wide source to 600,
which is how §D4.5's no-upscale rule got written.

### Round 4 — the live deploy, and the CHANGELOG reset

The admin copy was deployed to the running instance through
`apps/nextcloud/components/penpot/deploy-dev.sh` and **verified in the pod rather
than in the repo** — the deployed template read back, and the roadmap footer
confirmed absent. A green repository proves nothing about a running Nextcloud.

**The CHANGELOG was reset**, and the reasoning is §D4.8:

> **Before a first release there is no `Changed` and no `Fixed`.** Both are
> relative to a version somebody is running, and nobody is running one. Every
> bug this project fixed was fixed before anyone could meet it, so describing
> those fixes to a user is describing a past they never had.

207 lines of build history became a short `Added` list of what the app does.
The history is not lost; it is in this saga, which is where §D4.2 says it goes.

---

## What this chapter is not

- **Not a rewrite of the app.** No behaviour changes. If a round here changes
  `lib/`, it is because a comment was false.
- **Not a release.** Publishing is its own decision and has not been taken.
- **Not the end of the queue.** Chapter 3 left a named backlog of `@todo`,
  `@unbuilt` and `@blocked` scenarios; its close is the inventory. This chapter
  does not touch them, and does not pretend they are gone.

---

## Open questions

1. **When does this publish?** The publish workflow stops at a GitHub Release
   deliberately. The app-store submission is a separate decision, and the
   `info.xml` work is a prerequisite for it rather than a commitment to it.
2. **Does the tag gesture come out?** Chapter 3 left it dated and queued: live
   code for a mechanism §C6.18 retired, kept because the harness arranges 27
   `kind: project` rows with it. It is still a second answer to a settled
   question.
3. **Does the sibling family converge on one docs layout?** The cascade (§D4.2)
   is written for this repository. n8n and Grafana have the same four documents
   and the same drift, and nothing has been ported back to them.
