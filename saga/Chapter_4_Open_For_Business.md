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

### §D4.9 — Decision (locked): the store steps are restored, not reinvented

**The call:** `publish.yml` gets n8n's sign-and-upload steps back, substituted for
this app id and otherwise unchanged.

They were never absent for a technical reason. The workflow was forked from
nextcloud-n8n with the two store steps cut out and a note in their place saying
to restore them *"once it's ready — the app is pre-code and must not be listed
on the store until it's feature-complete."* Chapter 3 closed complete. The
condition the deferral named has been met, so the deferral comes out.

Restoring beats rewriting because the n8n pipeline is **proven, not merely
plausible**: run `32099721227` on 2026-08-18 posted `n8n_sync` 1.0.0 to
apps.nextcloud.com and got back **HTTP 201**. A rewrite would be a second way of
doing a thing that already works, and its first real test would be a release.

---

### §D4.10 — Decision (locked): one signing identity, and no local certificate

**The call:** mint the key and the CSR, and stop. No self-signed stand-in
certificate.

Nothing in the release path needs one. Signing a tarball needs the **private key
alone** — `openssl dgst -sha512 -sign` never reads a certificate — and the store
verifies against the copy *it* holds. The countersigned certificate is a public
file that Nextcloud commits into their own repository beside our CSR, so it has
an authoritative home that cannot drift.

**This is a lesson taken off the sibling rather than learned again.** n8n minted
a stand-in self-signed cert so its pipeline had something to point at. That
worked. But the real countersigned certificate has existed since 2026-07-22, and
`nextcloud-n8n/.signing/n8n_sync.crt` **is still the stand-in** — same `CN`,
issuer `CN=n8n_sync` instead of `Nextcloud Code Signing Intermediate Authority`,
and nothing on disk says which is which. A file that had one job, kept past the
day it was needed, became a decoy.

> **The rule:** if a file has an authoritative remote home, do not keep a local
> copy that can rot. Fetch it when you need it.

**One key, forever.** Re-registering a certificate for an app id **deletes every
previous release** — their signatures no longer verify. So the keypair minted
this round is the app's identity for as long as the app id lives, and the only
reason to replace it is a leak. Its public key is `41722eef…`; the CSR and any
certificate that ever comes back must match it.

---

### §D4.11 — Decision (locked): a guard for an impossible condition is a test that cannot fail

**The call:** every "not built yet" guard comes out of the workflows.

The CI here was forked from n8n while this repository was an empty skeleton, and
each borrowed step was wrapped in a check for the thing that did not exist yet:
`if [[ -d lib ]]`, `if [[ -f src/files.js ]]`, `if: hashFiles('lib/**') != ''`,
and headers announcing a *"FIRST SLICE"*. All of them describe a repository that
stopped existing several chapters ago.

Stale guards are not dead weight, they are **inverted**. The one that matters:

```yaml
    - name: Upload JS bundle
      with:
        path: dist/penpot_sync-files.js
        if-no-files-found: ignore     # was: error
```

`ignore` was correct when there was no bundle to build. Now there is one, and the
line says: *if the build produced nothing, say nothing.* A bundle that silently
stopped being built would upload nothing, report green, and ship a release
missing its frontend. The guard against a vanished absence had become a hole
under the thing it was copied from.

> **The rule:** a guard for a condition that can no longer occur is not
> harmless. It is a check that has stopped being able to fail, and the only
> notice you get is that everything is green.

---

### §D4.12 — Decision (locked): the maintainer is **Dr K**, and no real name appears anywhere

**The call:** no first name, surname, family nickname, personal email, personal
domain or personal GitHub handle in any tracked file. `Dr K` in prose, `drk` in
fixtures, `kubed-io` in SPDX headers.

The convention already existed; it had simply never been **written down** in
either this repository or the sibling. A rule that lives only in the
maintainer's head is a rule an agent rediscovers by breaking it, which is
exactly what happened over the week before this round. It is now in `AGENTS.md`,
which is where the cascade (§D4.2) says the current rules live.

**What the scrub actually found is the useful part**, because none of it was
someone typing a name into prose. Every leak came in attached to something else:

- **Two SPDX headers** carrying a personal copyright line, against 184 files
  saying `kubed-io` — editor template drift, invisible in review.
- **A live hostname** pasted into a Chapter 2 transcript as evidence for a
  timing claim.
- **A username inside a database dump**, copied verbatim into a code block
  because it was evidence and evidence gets pasted whole.
- **A test fixture's user id**, where a real name reads as a neutral
  placeholder.

> **The rule:** personal details do not arrive in sentences. They arrive inside
> pasted evidence, generated headers, and fixtures — the three places nobody
> proofreads.

**What a scrub cannot reach:** git authorship. See Round 6.

---

### §D4.13 — Decision (locked): an unfinished feature ships hidden, not deleted

**The call:** the personal-token feature stays in the codebase and comes out of
the product. Its three entry points are **commented out**, not removed.

The feature is real and wanted, but it is unfinished and unproven — every
scenario in `features/connection/personal.feature` is `@todo`, as is the one
outline in `designs/create.feature` that needs a personal token. Shipping the
settings card would put a control on a user's settings page that nothing in the
suite proves works, and fixing it properly would delay a release that is
otherwise ready.

**Hiding the entry points is enough, because the fallback is already the
documented path.** `PersonalTokenService::tokenFor()` returns null when no token
is stored, and calls that "the ordinary case, not a failure" — every write then
attributes to the service account. With no way to *set* a token, every user is
simply a user who never set one, which is a path the suite covers thoroughly.
So the plumbing threaded through eight services stays exactly where it is and
keeps being compiled, analysed and unit-tested; only the doors are locked.

**Four lines are the whole switch**, and they are named in the comment at
`Application.php::register()` so nobody has to rediscover them:

| file | what comes out |
|---|---|
| `lib/AppInfo/Application.php` | the `use` import, and `registerDeclarativeSettings(PersonalSettings::class)` |
| `appinfo/info.xml` | `<personal-section>`, and the `SetPersonalToken` `<command>` |

**Hiding the card without hiding the `occ` command would have been worse than
doing nothing** — half a feature, reachable by whoever reads `occ list` and
invisible to everyone else. CLI-first is the house style; that cuts both ways.

**The advertising goes too.** A feature nobody can reach must not be described
in the README or listed in the release notes, or the first thing a new user does
is go looking for a control that is not there.

> **The rule:** hiding a feature means hiding *every* way in — the UI, the CLI,
> and the sentence in the README that says it exists.

---

### §D4.14 — Decision (locked): a folder is a project because of its metadata, and nothing else

**The call:** the `penpot` system tag is removed in full — the service, the
listener, the event subscription, the stubs and the harness's use of it.
`penpot_project_id` is the only thing that makes a folder a project.

The tag has been dying for three chapters and would not finish. It began as the
thing that *made* a folder a project; §C6.38 replaced that with promotion by
content, and it survived as decoration the pull stamped. Chapter 3 Round 10
removed the decoration and it survived again — as `onTagged()`, an explicit
opt-in with **no scenario anywhere in the suite**, kept alive because the test
harness used it to arrange the one thing nothing else could: a project folder
holding no design.

> **The rule:** a feature kept alive only by its test harness is not a feature.
> It is scaffolding wearing a feature's clothes, and it reads to everyone else as
> a supported second way to do the thing.

**What made it removable was asking what a real user does.** An empty project
folder is not exotic — every user gets one the moment they make a project in
Penpot and the pull mirrors it. The harness had been reaching for a private
gesture to arrange something the product already does in the open. It now creates
the project in Penpot and pulls, which is both simpler and a truer fixture: it
arranges the state the way the state actually arises.

**The cost was 14 unit tests**, all of them `onTagged()`'s. None covered
behaviour that survives: the refusal paths they exercised (unusable name, Penpot
refused, the mapped root, outside every mapping) all have `adoptForContent()`
twins that remain, and the "contents come too" case is covered by
`testAdoptionFilesTheDesignsAlreadyInTheFolder`.

**One thing deliberately did NOT follow.** `TagAssignedEvent` was the only stated
reason `info.xml` requires Nextcloud 32. The floor stays at 32 anyway, because
nothing has ever been run below it — lowering it would advertise support for two
server versions no CI leg has exercised. Widening the range is a change with a
matrix line behind it, not a side effect of deleting a listener.

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

### Round 5 — the release path, wired for the store

Three things happened, in the order they had to.

**The pipeline was un-deferred** (§D4.9). `publish.yml` got the `nextcloud-store`
environment, the `Sign tarball` step and the `Publish to Nextcloud App Store`
step, ported from the sibling that has already used them successfully. The
release notes now lead with `occ app:install penpot_sync` and keep the tarball as
the fallback, instead of announcing that the app is *"pre-code / early
development."*

**The identity was minted** (§D4.10). A 4096-bit RSA keypair and a
`CN=penpot_sync` CSR, written to the gitignored `.signing/`. Verified three ways
before being trusted: the CSR self-signature checks out, its public key matches
the private key, and `git status` sees none of it.

**The fiction came out of the rest of the CI** (§D4.11). `tests.yml`, `quality.yml`
and `package.yml` all still described an empty repository. `package.yml` was
building the frontend only `if [[ -f src/files.js ]]` and copying the bundle only
if it appeared — in a release workflow, for a file that has existed for months.

Two things were **kept** against the n8n baseline rather than converged away,
because on inspection this repository's version is the better one: the top-level
`permissions: contents: read` block in `publish.yml` (a CodeQL finding n8n has
not fixed), and the richer step comments in `quality.yml`, which explain the
Behat guards in terms of this app's own eleven-leg matrix. Convergence is for
drift, not for deleting improvements. One thing was **taken** from n8n that this
repository had dropped: the Psalm baseline seed steps, which matter here because
`psalm.xml` names `errorBaseline` and would abort if the file were ever reset.

**Proven locally as far as a pod can prove it:** the packaging step was run by
hand and produced a 334 KB tarball with exactly one top-level directory,
`penpot_sync/appinfo/info.xml` in place, and a valid SHA-512 signature from the
new key. That is every store precondition except the two that need Nextcloud.


### Round 6 — the scrub, before the doors actually open

A public repository about to become a listed app is a different exposure from a
public repository nobody visits. The maintainer's name came out of every tracked
file (§D4.12): two SPDX headers, a live hostname in a Chapter 2 transcript, a
username inside a pasted database dump, a test fixture id, and four cells of the
gate table in this chapter. All five screenshots were checked frame by frame and
carry no name; they also carry no PNG text metadata.

**The fork moved orgs.** `app-certificate-requests` had been forked to a personal
account. It is now forked from the upstream into
[kubed-io/app-certificate-requests](https://github.com/kubed-io/app-certificate-requests)
so the CSR is filed by the organisation that owns the app. No certificate PR was
opened — gate 4 is still deliberately unfiled.

**And the part a scrub cannot reach: git authorship.** Every commit in this
repository names a real person, and about half carry a personal email address.
That is not in a file, so no edit removes it — only a full history rewrite and a
force-push would, and that is a decision with real costs (every existing commit
link and review comment breaks) that belongs to the maintainer, not to an agent
doing a docs pass. It is recorded here rather than quietly left out.


### Round 7 — locking a door before opening the building

The last thing between the app and a release was a feature that was not ready to
be seen (§D4.13). The personal-token card, its `occ` twin and its settings
section are commented out with the reasoning and the restore instructions in
place; the README sentence advertising it and the CHANGELOG bullet claiming it
both came out.

**What made this cheap was a decision taken much earlier.** Because the token
lookup already treated "no personal token" as the ordinary case rather than an
error, removing every way to set one changed no behaviour at all — it just made
every user take the path the suite already covers. A feature whose absence is a
supported state can be withdrawn in four lines. One whose absence throws could
not have been.

**Nothing needed doing to the specification.** Every personal scenario was
already `@todo` — the spec had been honest about this the whole time, which is
what made the decision easy to verify rather than a judgement call.


### Round 8 — the tag, finally

Removed: `ProjectTags`, `ProjectTagListener`, their `TagAssignedEvent`
registration, `ProjectFolderService::onTagged()` and `refuse()`, the three
`tags->remove()` cleanups in `MotionService`, `PullService` and
`ProjectFolderService`, the `OCP\SystemTag` stubs, 14 unit tests, and the
harness's `iAssignThePenpotTagTo()`.

**The specification needed no changes at all.** Not one `.feature` file mentions
the tag — checked by parsing every non-comment step and table row across the
suite. The single grep hit was the word *stage*. So nothing was ever asserting
the tag existed; the spec had been describing the product correctly while the
code carried a mechanism the product did not have.

**The README was the only place a user could have learned it existed**, and it
described it as a feature: *"A folder you promote from this side is tagged
`penpot`, so the ones you made are easy to spot."* That sentence is gone.

**The harness swap is the substantive change.** `ensureProjectFolder()` used to
tag a folder and check the stamp appeared; it now creates the project in Penpot
and runs a pull. `kind: project` no longer MKCOLs the folder first — the pull
makes it, which is the only route there is.

### Round 8, continued — what the clearing actually swept up

Chapter 3 built the colony by trying things. Most worked. This round is the bill
for the ones that did not, and it is worth reading as a set rather than as four
separate deletions, because they failed the same way.

**The tag, three times.** It was invented in §C6.18 as the thing that *made* a
folder a project — a real answer to a real question, and the wrong one, because
it required teaching a gesture nobody performs. §C6.38 replaced it with
promotion by content and the tag became decoration the pull stamped: harmless,
and now describing something that was no longer true. Chapter 3 Round 10 removed
the decoration, and what survived was the third form — an explicit opt-in with
no scenario, kept because the harness used it. Each removal was correct and each
left a smaller version of the same thing behind.

**The carve-out that shipped.** "Only a folder with no project above it gets
promoted" was a reasonable-sounding rule that made two folders a user cannot
tell apart behave differently on a marker nobody can see. It shipped, and a live
instance reported it. §C6.38 reversed it.

**The pinning rule.** Project folders were once required to sit inside their
team folder. Reversing that nearly doubled the project leg of the suite, because
a pile of scenarios had been written `@unbuilt` against a rule that was about to
stop existing.

> **What they have in common:** every one of them was a mechanism invented to
> answer a question the metadata could already answer. The `penpot_project_id`
> stamp was there the whole time. The tag, the carve-out and the pinning rule
> were all attempts to make the *shape of the folder tree* carry meaning that
> was already recorded on the folder itself.

**Why it took four passes to finish.** Not because anyone was careless — because
each pass removed the part that was clearly dead and left the part that still
had a caller. The last caller was a test. That is the one that matters:

> **A mechanism with no user and one test is not "nearly gone". It is a
> mechanism, and it will be found by whoever reads the code next and reasonably
> assume it is supported.** Removing it means finding the test another way to
> arrange what it needs — which here meant asking, for the first time, how a
> *user* gets an empty project folder. The answer was that they make it in
> Penpot, which is what the harness now does.

**The grounds are clear.** A folder is a project because it carries
`penpot_project_id`. There is no second marker, no badge, no opt-in gesture and
no shape rule. That sentence is now true in the code, in the spec, in the notes
and in the README, which is the first time it has been true in all four at once.

### Round 9 — two scenarios that were waiting on nothing

`projects/create.feature` went from two live scenarios to four: `Move a design
into a folder Penpot has never seen` came off `@unbuilt`, and `Create a project in
Penpot` came off `@todo`. **No behaviour was built for either.** Both tags were
describing walls that Chapter 3 had already taken down and nobody had gone back to
re-read.

The `@unbuilt` note said three of the outline's four rows wanted capabilities the
rule did not supply — an untracked archive being imported, and a move seen across a
storage boundary. Round 7 built the first (§6.33: an arrival is imported as a new
design whatever id it carries) and the cross-team move built the second (core deletes
the metadata across a storage boundary, and the file id is what survives). The
scenario had been runnable for two rounds.

**What the round actually cost was three step definitions**, and only one of them was
interesting. `someone creates the "…" project in the "…" Penpot team` is the
Penpot-origin twin of the arrange that seeds a design: the gesture happens upstream,
so the pull is collapsed into the step rather than wedged into the scenario as an
admin's button. It reads the new project's id back through the probe rather than
trusting `create-project`'s answer, which keeps the seed channel and the read channel
cross-checking each other.

**Then the spec turned out to carry a defect, and it was a naming one.** The
Penpot-origin outline's first row created a project called `Team` in the Design
Team — a name the two scenarios above it leave standing in that same team, because
Penpot state accumulates across a leg and this app has no `delete-project`. Two
projects in one team may share a name (§31), and `PullService::ensureProjectFolder()`
adopts a same-named folder **only when it is bare**, precisely so one project cannot
inherit another's designs. So the second `Team` would have been mirrored to
`Team (2)`, and the row asserting `penpot_project_id` on `Penpot/Team` would have read
the *older* project's id off the older folder.

> Deterministically wrong rather than flaky, and it would have read as an app bug: the
> folder exists, it carries a project id, and the id is simply not the one the
> scenario means. The fix was four characters of Examples table — but only because
> the assertion was written to compare an **id** rather than a name. A by-name check
> would have gone green on the wrong project and stayed green.

**The lesson is the tag, again.** `@todo` claims the code exists and only the test is
missing; `@unbuilt` claims the opposite. Both are statements about the past written
by whoever last looked, and neither is re-checked by anything — which is the same
failure mode as Round 8's mechanism-with-one-test, and the reason the rule is that a
scenario changes status only on a PR that runs it.

---

### Round 10 — the same tag, wrong the other way

`projects/restore.feature` was next in the queue: two scenarios, both `@todo`, which
should have meant two step definitions and a green leg. Reading `lib/` first — the
habit Round 9 was supposed to teach — said otherwise in three places at once.
`RestoreFromTrashListener` opened with `if (!$node instanceof File) { return; }` on
both of its doors, so a restored FOLDER reached nothing. `RestoreService` had no
folder entry point to reach. And `TrashControl` could list a trashed file and destroy
it, but had no verb for taking anything back out.

> So Round 9 found two scenarios tagged `@unbuilt` that were runnable, and Round 10
> found two tagged `@todo` that were not built at all. Three rounds running, the tag
> has been the thing that was wrong. It is a queue, not an inventory.

**Neither scenario was a small test to write, and one of them closed an open fork.**

The folder half needed a walk. Core announces ONE node when a folder comes out of the
trash and nothing for the designs inside it — the same wall `DeletionService` meets
going the other way, which is why *it* hand-walks its children — so the restore had to
grow the inverse walk. The hard part was not the walk but the ORDER. Penpot has no
`restore-project` (§C6.19), so a deleted project only comes back through a design of
its own being restored; when nothing is left to come back through, the project has to
be made again and the folder re-stamped. That re-stamp has to happen BEFORE the
designs are handled, because a purged design comes back by import *into the project
its folder names*, and until the stamp is replaced that is a project Penpot deleted.

The Penpot half was §6.37 — the fork `PullService` had carried since the trash became
readable. The reconcile REAPS: it destroys a trashed mirror whose design Penpot
destroyed. The mirror image of that — a trashed mirror whose far side came *back* —
had no scenario asking for it, so it was documented and left. This slice is the
scenario, and the answer turned out to belong at the FOLDER level rather than the
file level, for a reason the trash itself dictates: trashing `Penpot/Doomed` puts one
item in the trash, and the designs beneath it are nested inside it rather than beside
it. There is no trashed `Alpha.penpot` to find. So the pull now looks for the
project's folder in the trash before provisioning a new one, and the folder comes back
whole — which is the only shape Nextcloud offers and the only one that cannot hand
somebody a half-restore.

**And the Examples table carried Round 9's defect again, in a new costume.** All four
rows named the same project, `Doomed`. Row 3 finishes by importing a design into that
folder; row 4 then claims the folder holds *no designs at all*. Deterministically
false, and it would have read as the restore inventing a design. Every row names its
own project now — `Parked`, `Purged`, `Empty` — which is the same fix as Round 9's
four characters, arrived at the same way: by tracing what each row leaves behind for
the next one.

> Two rounds, two Examples tables, one bug. Penpot state accumulates across a leg and
> nothing tears it down, so a fixture name is a shared resource. Worth stating once
> as a rule: **an Examples row may not reuse a name an earlier row leaves standing.**

**And then the rule turned out to be one file too narrow.** Fixing the table left the
second scenario still calling its project `Doomed` — the name `projects/delete.feature`
uses, and that file runs FIRST in the same leg and deliberately ends with
`Penpot/Doomed` sitting in the Nextcloud trash (*"is recoverable from the Nextcloud
trash"* is its closing assertion). This is the only scenario in the suite that asserts
a path is NOT in that trash, nothing empties the trash between scenarios, and the
assertion polls — so it would have hung for its full timeout and failed on a fixture
every single run, while reading as the revive not working.

Nothing found it but tracing the leg by hand. The three structural guards all passed,
the unit suite has no view of the trash, and the collision is invisible inside either
file: each is self-consistent, and the leg is the only place they meet.

> So the rule is wider than two rounds made it look. **A fixture name is shared across
> every file in a leg, not just across the rows of one table** — and the names that
> matter most are the ones a scenario asserts the ABSENCE of, because an absence is the
> one claim another file's leftovers can falsify without touching anything this one did.

**Then CI found the two things reading could not.** Both legs of the feature failed,
and the app's own log said why in one line each — which is the argument for logging
the decision rather than only the outcome.

**A restore needs somebody to be logged in, and a pull is nobody.** `Trashbin::restore()`
opens with `OC_User::getUser()` and throws *"Tried to restore a file while not logged
in"* when it answers false, and that reads the `user_id` SESSION key, which nothing
sets under `occ`. Everything else in the revive worked perfectly — the pull saw the
project come back, found the trashed folder by its project id, and called restore —
and then the one call that mattered threw. The fallback caught it and made a new
folder, exactly as designed, so the failure surfaced as *"the folder is still in the
trash"* rather than as an exception.

> Every other trash operation in this app got away without a session: listing and
> `removeItem()` take the user or the item as arguments. Only the restore reaches for
> ambient state, and only from the one caller that has none.

**And a design Penpot has "permanently deleted" is restorable again the moment
anything touches it.** The `Penpot has purged` Examples row failed with the folder
wearing its ORIGINAL id, and the log said `restore: the design came back on a second
call` — layer 2, on a design the arrange had destroyed. §C6.11 already recorded the
mechanism from the other side: `permanently-delete-team-files` stamps `deleted_at` to
now and leaves the row, so anything that re-stamps it puts the design back in the
trash listing. Here the thing that re-stamps it is `delete-project` — which is what
trashing the folder makes the app do, and therefore part of the gesture under test.

> The purge has to happen before the trash, and the trash undoes the purge. The state
> cannot be held still, which is the same wall `Trash a design that is already gone
> from Penpot` sits behind as `@blocked`. The row is gone; `no designs at all` proves
> the same branch by a road that holds still.

Three status tags wrong in three rounds, and now two spec rows describing states the
system will not hold. The common thread is the same one: **a claim written down once
and never re-measured**, whether it is a tag, an Examples row, or an API's docstring
promising an immediate delete.

**And then §6.49 collected its toll a third time, from the harness.** With the fixture
names unique, `Penpot holds a project named "Revived"` failed — and had only ever
passed because `Doomed` was a name the leg had several of. The new step confirmed its
restore against the TRASH listing and pulled the instant the design left it, which is
inside the window where Penpot's transaction has not settled and the project is still
deleted. The pull saw no such project, so it never looked for its folder.

> `RestoreServiceTest` has a test whose whole subject is this distinction — *"success
> is measured against the project listing, not the trash"* — written after the same
> window failed the suite's headline scenario about half the time. The app has obeyed
> it since. The harness, written months later by someone who had read that docblock in
> the same sitting, did not.

A rule the production code follows is not a rule the test code inherits, and the test
code is where it is easiest to get away with breaking.

---

### Round 11 — the tag was wrong again, and a live pod settled a contradiction

`projects/purge.feature` had four scenarios and three of them runnable, all `@todo`.
None had any code. `TrashPurgeHook` opened with `str_contains($path, '.penpot')` —
cheap, correct for a mirror, and blind to the thing the whole feature is about: a
trashed project folder is `Team.d1788058484`, with no extension anywhere in it. So
the hook returned before it could look, and emptying the trash on a whole project
left every design of it sitting in Penpot's trash. Going the other way,
`TrashReconcileService` only ever listed trashed FILES, so emptying Penpot's trash
could not reach the folder mirroring the project it destroyed.

> Four rounds, four wrong tags. `behat --tags @todo` is a queue, and the only thing
> that turns a queue entry into a fact is reading `lib/` before believing it.

**The guard that moved.** `TrashedFolder` shipped one round earlier carrying a
`restore` closure and no `purge` one, with a docblock arguing the point: *"a purge
reachable from the revive path is a purge that can be called by accident — the type
is the guard."* That held for exactly as long as a trashed folder had one thing that
could happen to it. It carries both verbs now, and the guard moved to the caller,
which is where it was always really going to live — the reap purges a folder only
when it has proved the folder holds nothing but designs Penpot no longer has. One
spreadsheet spares the whole folder, because a trash item cannot be partly purged.

**Two notes in AGENTS.md disagreed, and the pod broke the tie.** One said emptying a
Team Folder's trash *"cannot reach Penpot"* and was a gap unclosable from here. A
newer one, three thousand lines away, said the Team Folder purge *"reached Penpot
exactly like the plain one"*. Both were written from measurements; only one could
describe the row about to be shipped.

So it was measured rather than argued: two identical project folders on the live
instance, one under an admin-folder mapping and one under a Team Folder, trashed and
then purged. The admin folder's purge destroyed its design and said so in the log.
The Team Folder's produced no log line at all — groupfolders' `removeItem()` unlinks
and emits nothing, no typed event and no legacy hook. The Team Folder row is gone,
and it is not `@unbuilt` or `@blocked`, because there is no code anyone could write
for it from inside this app.

> The two notes were never in conflict. They describe opposite DIRECTIONS: the reap
> runs inside the pull and needs no hook, which is exactly why `designs/purge` can
> run a Team Folder row green on a backend where this one cannot. A contradiction
> that dissolves once you ask which way the news is travelling — and thirty seconds
> in a pod was cheaper than either reading.

**Then CI destroyed the round's headline, and a live Penpot said why.** The purge ran,
the hook fired, the log said *"permanently deleted a trashed project's designs"* — and
the designs were still sitting in Penpot's trash. Twice, once per row.

Measured rather than reasoned about, three runs and a control: Penpot will not
permanently delete a file whose PROJECT is deleted. On a live project the destroy
works and the file is unrecoverable; on a deleted one the RPC reports success and does
nothing at all, and the file is still restorable afterwards — restoring it revives the
project too. Ordering does not save it either: `delete-file` first, so the file has a
`deleted_at` of its own, then destroy, is still a no-op.

And trashing the folder is what deleted the project. `onFolderTrashed()` calls
`delete-project`, never `delete-file` per design, so there is no ordering of Nextcloud
gestures that reaches a destroyable state. The folder-purge branch was written,
shipped to a live instance, and taken out again: in every path it had, the id it sent
was one Penpot would ignore, while logging a successful destroy.

> **A log line is not a measurement.** I had "verified" this branch on the live pod an
> hour earlier and reported it working — by reading the app's own success line and
> never asking Penpot. §C6.11 has said *"success is not proof of success on these
> commands"* since Chapter 2. Two of this round's three failures were believed log
> lines, and one of them was mine twice over.

I blocked all three scenarios on that, and Dr K asked the obvious question — *"you
just blocked all of those?"* — which was worth asking, because I had measured two
orderings and not the third.

**The one I skipped is the one that works.** Deleting the DESIGN while its project is
still live gives it a `deleted_at` of its own, and then the destroy lands and the
design is unrecoverable. Do it after the project has gone and there is nothing to
stamp. So the fix is not in the purge at all: `onFolderTrashed()` now calls
`delete-file` on every design below the folder before `delete-project`, which is also
the more honest thing for it to have been doing — the designs really are going to
Penpot's trash, and until now they only LOOKED like it.

> Four runs and a control. The wall was real and it was one call earlier in the
> gesture than where I went looking for it. Two negative results are not a proof of
> impossibility, and a question from someone who had not seen the measurements was
> what turned that over.

**One scenario came back; two stayed blocked, and for a sharper reason.** A destroyed
design goes on appearing in `get-team-deleted-files` while its project is deleted, so
the listing shows destroyed and recoverable designs side by side, and `fileExists()`
cannot separate them either. The gesture scenario can ask by TRYING — issue a restore
and assert nothing came back, which is a mutating `Then` named so a reader sees it.
The reap cannot: asking Penpot to restore a design in order to find out whether it is
gone is not something production may do. So the code is built and unit-tested and its
two scenarios say `@blocked`, which is the honest place to leave it.

**And `Rename a project in Penpot` needed no code at all.** Its `@todo` said why in
its own words — *"the team still holds the New project the scenario above made, so
the pull adopts the wrong folder"* — which is the leg-wide fixture rule for the third
PR running. Distinct names, and it ran.


### Round 12 — the answer was a field on a record nobody had read

Round 11 closed by blocking two scenarios on a claim: with a project deleted, Penpot's
trash listing shows destroyed and recoverable designs side by side and nothing can tell
them apart. Dr K did not accept the shape of the workaround that came with it — a
`Then` that issued a restore in order to find out whether a design was gone — and asked
the question that unpicked the whole thing: *"can't we just determine we no longer need
to keep a trashed project-folder because it is now empty?"*

**It could, and the discriminator had been in every response all along.**
`get-team-deleted-files` returns RECORDS, and I had been reading it as a set of ids —
four rounds of measurement, every one of them asking *is this id present?*

| the record says | what it means | recoverable |
|---|---|---|
| no `will-be-deleted-at` | the file itself was never deleted; it is listed because its PROJECT is | yes — restoring it revives the project |
| a stamp a week out | in the trash proper | yes |
| a stamp that has PASSED | destroyed; a collector takes the row later | **no** |

Confirmed against the thing itself rather than inferred: a destroyed design was handed
to `restore-deleted-team-files`, which answered 200, named the id in its `end` payload,
and left it exactly where it was. Penpot claims that restore succeeds. It does not.

> **"Is it in the list?" and "will you give it back?" are different questions**, and
> the listing only ever answered the second. Round 11's note said the answer did not
> exist; it said so twice, in bold, having never looked at a record.

So `PenpotClient::recoverableFileIds()` is that reading, and the reap's `parkedIds()`
uses it. Both Penpot-side scenarios came off `@blocked`, and the mutating `Then` went
with them — `no design it held is in Penpot's trash any more` is now an observation,
which is what a `Then` is for. `PullService::penpotTrashIds()` deliberately keeps the
raw listing: it drives the prune, where a wider set means more mirrors KEPT.

> Round 11 recorded a question from Dr K turning over a wrong conclusion. This is the
> same round again, one layer down, and the shared lesson is not about Penpot: **a
> negative result about a thing I never inspected is a statement about my reading, not
> about the thing.**

`projects/purge.feature` has no `@blocked` left in it.

---

### Round 13 — the chapter that was not going to touch the code

This chapter opens by saying what it is not: *"Not a rewrite of the app. No
behaviour changes. If a round here changes `lib/`, it is because a comment was
false."* Chapter 3 had closed. The app **worked end to end and was deployed**. What
remained was the shopfront.

Five rounds later that sentence is not true, and it is worth saying plainly why
rather than quietly amending it.

#### What actually happened after Chapter 3 closed

| Round | The tag said | The code said |
|-------|--------------|---------------|
| 9  | two scenarios needed work | both were already runnable; the walls came down in Chapter 3 |
| 10 | two `@todo`, so two step definitions | **no code at all** — `RestoreFromTrashListener` returned on any folder |
| 11 | three runnable `@todo` | **no code at all** — `TrashPurgeHook` matched on `.penpot`, and a trashed project folder is `Team.d1788058484` |
| 12 | two `@blocked` on a real wall | the wall was a field on a record nobody had read |

Four consecutive rounds, and **the tag was wrong every single time** — in both
directions. Round 9 found work already done. Rounds 10 and 11 found `@todo` sitting
over behaviour that did not exist, which is the failure mode that matters: `@todo`
means *"the code EXISTS; only the test is missing"*, so a `@todo` over nothing is a
claim the app does something it cannot do.

That is what Chapter 3 was closed on. Not on a measurement — on an inventory of
tags, each of which was a claim somebody made once, and none of which anything
re-checked. `behat.dist.yml` had already written the lesson down: *"a status tag is
a claim somebody made once, and nothing re-checks it… `behat --tags @todo` is a
QUEUE, not an inventory."* It was in the config, correct, while the chapter above it
closed on exactly that mistake.

#### The lesson, stated so it survives this chapter

**A chapter closed on a count of tags is closed on a guess.** "The app works end to
end" was true of every gesture anybody had run, and false of three gestures nobody
had — restoring a project folder, purging one, and telling a destroyed design from a
recoverable one. The deploy was real; the confidence behind it was borrowed from a
tally.

The cheap correction is the one Round 9 was supposed to teach and Rounds 10 and 11
had to learn again: **read `lib/` before believing a tag**, and change a tag only on
a PR that runs it. The expensive correction is structural, and it is the one worth
carrying to the sibling apps: a chapter should close on a suite that ran, not on a
queue that was counted.

#### The cleanup itself

With the queue's release-blocking work finished, one PR took the tidy-up that had
accumulated across twelve rounds. No behaviour changed anywhere in it.

**The depth ceiling was declared fourteen times.** Thirteen `private const
MAX_DEPTH = 100` plus `MoveRules::SCAN_DEPTH`, each carrying a comment saying it was
"the same ceiling every other recursion uses" — a web of thirteen cross-references
pointing at each other with no authority anywhere in it. `Walk::MAX_DEPTH` is the
one now. They have to agree for a reason beyond tidiness, and §C6.20 is that reason:
several of these walks read the same tree for different halves of one question, and
a walk stopping shallower than its partner attributes files the partner still
claims. The symptom is a silent duplicate, not an error.

**Four documents held four different scenario counts**, and every hand-count had
missed the same thing — a `@blocked` on a `Feature:` covers every scenario beneath
it, and `designs/open-with.feature` contributes five that way. The true figures are
114 headers, 87 live, 175 executed rows, 13 `@todo`, 4 `@unbuilt`, 10 `@blocked`.
`behat.dist.yml` said 6; two other documents said 7 and "90 live".

**`SECURITY.md` was still written for a repo with no code in it** — "there is no
`lib/` or `src/` yet" — and described this app's egress as reads plus perhaps one
narrow rename. It ships twelve write commands and can permanently destroy designs
and projects. A security policy understating its own app's write surface by an order
of magnitude is the worst single piece of drift this repository has produced, and it
survived because nobody re-reads a file they are not editing.

**`SyncSettings` promised a button the panel it documents renders.** *"There is
deliberately no 'Sync to Penpot' button, and there never will be."* `features/README.md`
already names that exact sentence as its cautionary tale about decisions outliving
their reasons, which is why it was corrected in place rather than deleted.

#### And a flake that had been reading as a version bug

The same round settled a question that had been costing reruns: legs that passed on
every PR and failed on `main`. The obvious reading was a Nextcloud version
incompatibility, since a PR runs `stable34` alone and `main` runs all three.

It is not. The failures hop — `project-trash/stable33`, then `trash/stable32`, then
`project-trash/stable32` on three different scenarios — and every one goes green on a
rerun of the same commit with no change. `main` simply rolls the dice thirty-three
times where a PR rolls eleven. The race is in the harness: the Penpot-restore steps
confirm by watching a design **leave the deleted-files listing**, then run one `occ`
pull that reads the project's **live listing**, and those two transitions are not
atomic. In the gap the pull correctly finds nothing, and the `Then`'s ten-second
WebDAV poll is dead time against a decision already made.

> A failure that moves between legs, versions and scenarios is not a version
> incompatibility. **Rerun before you theorise** — one rerun of the identical commit
> would have answered this the first time it happened.

The fix is confined to the step definitions and is not in this round: confirm on the
side the pull actually reads, and give the three-attempt loop the backoff it has
never had.

---

### Round 14 — the request goes in

The certificate request is filed: **[PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213)**,
`penpot_sync/penpot_sync.csr`, opened from **`kubed-io`** rather than a personal
account — which is the one thing the n8n request (PR #1103) got wrong and could not
be corrected afterwards.

Three things the precedent taught, and one it did not:

**Signed, this time on the first push.** §D4.10 records that n8n's CSR commit landed
unsigned and needed an amend and a force-push. This one carried a valid SSH signature
before it left the container, checked by reading the raw commit object for its
`gpgsig` header rather than by trusting `git log --show-signature`, which reports
"No signature" for a perfectly signed commit whenever `gpg.ssh.allowedSignersFile`
is unset. Verifying the wrong thing looks identical to the failure.

**The DCO bot wanted a sign-off, and the note about this gotcha did not mention it.**
Gate 4's guidance named two things easy to miss — the public email and the signing
setup — and the first push failed on neither. It failed on a missing
`Signed-off-by:` trailer. The precedent had one; the prose describing the precedent
did not say so. **Read the artefact, not the description of the artefact** —
`gh api …/pulls/1103/commits` answered in one call what the paragraph had omitted.

**Gate 7 was open and nobody had noticed.** GCP Secret Manager held `nextcloud-n8n`
and `nextcloud-store-token` and nothing for penpot, so the only copies of the signing
key were a working copy in a container and a write-only GitHub secret. It is now
`nextcloud-penpot`, labelled to match, and verified by comparing the checksum of what
comes back out against the local file — **a backup nobody read back is not a backup.**

That also settled a duplication that looked like an inconsistency. It is not one:

- **GitHub Actions is what runs the release.** `publish.yml` reads
  `NEXTCLOUD_STORE_KEY` and `NEXTCLOUD_STORE_TOKEN` from the `nextcloud-store`
  environment. Both are write-only and can never be read back.
- **GCP is the retrievable backup**, one secret per app signing key
  (`nextcloud-<app>`, labelled `purpose=appstore-signing`), plus **one shared**
  `nextcloud-store-token` — no app suffix, because the store token authenticates the
  *account* rather than an app, and every sibling app uses the same value.

#### Gate 8 without a release

The token had been set and never exercised, and the dry run cannot exercise it:
`push=false` skips the release job, which is the only thing that touches the store.
So it would first have been tested during the release it could break.

It can be proven earlier, without mutating anything, by sending the real token with a
deliberately empty body and reading which way it fails:

```
real token,  {}  →  400  {"certificate":["This field is required."],
                          "signature":["This field is required."]}
bogus token, {}  →  401  {"detail":"Invalid token."}
```

The control is the whole trick — a `400` alone proves nothing until a `401` shows what
rejection looks like. The token authenticates, and the endpoint is asking for exactly
the two fields gate 9 sends.

**Everything that was ours is done.** Gate 5 is the wait, and nobody here controls it.

---

### Round 15 — the ceiling that failed open

**The countersign landed** — [PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213)
merged, so gate 5 is closed and `penpot_sync/penpot_sync.crt` exists in Nextcloud's
repository. What is left is gate 9 and gate 10, and both are ours. This round is the
last code before them.

#### A guard that failed open, found in the sibling, in code ported from here

`nextcloud-grafana` PR #73 got a Copilot review of `ExistingDashboards` — a straight
port of this app's `ExistingDesigns`. The defect went with the port and was still
here:

```php
private function designsBelow(Folder $folder, int $depth): array {
    if ($depth >= self::MAX_DEPTH) {
        return [];                                  // fails OPEN
    }

    try {
        $children = $folder->getDirectoryListing();
    } catch (\Throwable $e) {
        // AN UNREADABLE FOLDER IS NOT AN EMPTY ONE...
        throw new \InvalidArgumentException(...);    // fails CLOSED
    }
```

The comment on the second branch is the argument against the first, written eight
lines away from it and not applied to it. **A folder too deep to scan is not an empty
folder any more than an unreadable one is.**

Why it matters here and not in most of the other walks: `[]` from this method is not
"we stopped walking", it is a **verdict** — *this folder holds no designs, so a
`link` mapping may be made over it and the purge has nothing to destroy.* Below the
ceiling the designs really are there, the mapping is created `link` over them, and
the app is back in the state this class exists to prevent: the `sync` → unmapped →
re-mapped `link` contradiction that `MappingTeardownService` and
`mapping/delete.feature` answer differently and both correctly. That is not
hypothetical — a live instance reached it in three steps (mapped `sync`, unmapped,
re-mapped `link`), which is the reproduction `ExistingDesigns`' own docblock
carries. Reached through the one door left unlocked.

It now throws, logs, and says what to do about it — *"map a folder nearer the top,
or flatten the tree"*. The test builds a folder that is its own child, so the walk
can only terminate at the ceiling.

#### The distinction is the finding, not the fix

Thirteen classes share `Walk::MAX_DEPTH`, and porting a `throw` into all of them
would be worse than the bug. In most — `PullService`, `CopyService`, `PushService`,
the move scan — stopping at the ceiling means "we do not go deeper", which is a
**limit**: the answer is short, and a short answer costs a duplicate or an unswept
file that the next pass still takes. Ending the walk is right, and `[]` claims
nothing.

The ones to read differently are the ones where an empty answer **decides**
something. Three, now, and they answer three different ways because their contracts
differ:

| Class | What `[]` would permit | What it does now |
|---|---|---|
| `ExistingDesigns` | a `link` mapping over real designs, and a purge | **throws** — the mapping is refused |
| `TrashControl` | destroying something unexamined | **already refused** — *"past the ceiling nothing is known, so nothing is safe to destroy"* |
| `MappingTeardownService` | a mirror surviving a teardown that promised to take it | **logs and stops** — it may not throw |

`MappingTeardownService` is the awkward one and worth being explicit about. Its
`tearDown()` is documented **NEVER THROWS**, and for a good reason: the mapping's
removal is the act the admin asked for and must not fail because one file resisted.
So it cannot fail closed. What it was doing instead was failing open *silently* — the
unreadable branch logged and the ceiling branch did not — and the count it hands back
excluded whatever it never reached. It now says so in the log, and its own docblock
says what survives. Second-order by the same measure as before: it leaves a file
behind rather than destroying one.

`Walk`'s docblock carries the rule that produced this table, because the number was
never the interesting part: **the question to ask of a new walk is not "how deep" but
"what does empty mean here".** If it permits something, the ceiling has to fail
closed.

#### And the two walks that had no ceiling at all

PR #73 noticed in passing that `PullService::collectMirrors()` and
`indexFilesByPenpotId()` are the only recursive walks in the app with no guard, while
their siblings in the same class have one, and left them alone because adding one is
a behaviour change. It is — and it is the right one, because these two are the
pair `Walk`'s docblock is *about*: the prune's half and the upsert's half of one
question, whose disagreement about how hard to look is §C6.20 and produces silent
duplicates. They agreed by both being unbounded. They now agree by both stopping at
`Walk::MAX_DEPTH`, which is what every other walk over the same tree already does.

Both are limits rather than verdicts, so both simply stop: a short prune under-deletes
(the direction `collectMirrors()`' docblock already argues for at length), and a
mirror below the hundredth rung is invisible to both halves, so the pull writes a
fresh one beside it. A visible duplicate is what the ceiling costs. What it buys is a
pathological tree not spinning while an admin waits on a form.

#### Three notes in `features/AGENTS.md` that had gone stale

Level 2 of the cascade (§D4.2) holds the reasoning **in the present tense**, and
three of its notes were describing an app that no longer exists:

- *A link mapping may not be made over designs that already exist* was still
  labelled **`@unbuilt` — "nothing purges on create yet"**. It purges; the scenario
  is untagged and runs under `@occ`. It now says so, and carries the rule this round
  added: an empty answer from that check permits a purge, so it may only ever mean
  the thing it says.
- ***Removing a mapping deletes nothing*** — a whole section, sitting under
  `## mapping/create`, saying the fate of mirrored files was "Course 5's decision".
  Course 5 decided it, `mapping/delete.feature` states it in two scenarios and
  `MappingTeardownService` implements it. Nothing pointed at the section; it is gone.
- *designs/create* ended with **`@todo` — "no `lib/` exists yet"**. There are
  forty-two files in `lib/Service/` alone. One scenario there is `@todo`, for the
  personal mapping, and the file already has a section explaining why.

This is the cascade's own rule biting: **a retired decision left in a working
document is indistinguishable from a live one.** #73 recounted four documents and
did not audit this file's tag claims; these three are the ones a reader would have
been misled by, and the rest of the file is still unaudited.

#### One door for three callers

`call()`, `postStream()` and `importBinfile()` send wildly different payloads —
Transit, an event stream, a multipart archive — and each answered an unreachable
Penpot with the same sixteen lines: a `LocalServerException` branch naming
`allow_local_remote_servers`, then a `\Throwable` branch naming the URL, both
`KIND_UNREACHABLE`. Two of them then repeated the same non-2xx check as
`decodeResponse()`.

`post()` and `ok()` now hold those, which is the deferred item from #73's own list.
What is shared is not the request but the **failure** — and a fourth caller
classifying that subtly differently is exactly the drift worth closing before a first
release. `fetchAsset()` deliberately keeps its own: its messages name *which half*
failed (the export succeeded, the download did not), which is §5.3's whole point and
not a duplicate of anything.

**No changelog line.** Nothing here is visible to somebody running the app — the
`ExistingDesigns` refusal is reachable only by a hundred-deep tree — and
`[Unreleased]` is the first release's notes rather than a build log (§D4.8).

---

## The plan — reaching the store

The store's requirement is that **every release is signed by a certificate
Nextcloud themselves countersigned**. That countersign is the only step in this
chapter that is not ours to pace, so it is filed first and everything else
overlaps the wait. **It landed on 2026-09-03**, and every remaining gate is ours.

```
  mint key + CSR ──▶ file CSR PR ──▶ (Nextcloud countersigns — the wait)
       │                                        │
       ▼                                        ▼
  key ──▶ NEXTCLOUD_STORE_KEY          fetch .crt from their repo
                                                │
                                                ▼
                                        register the app id
                                                │
                                                ▼
                                   publish.yml push=true ──▶ 🎬
```

| # | Gate | Owner | State |
|---|------|-------|-------|
| 1 | App id `penpot_sync` locked across `info.xml`, `package.json`, namespace | — | ✅ done, long since |
| 2 | Signing keypair + CSR minted, gitignored, verified | agent | ✅ done this round |
| 3 | Release pipeline carries sign + upload | agent | ✅ done this round |
| 4 | **CSR filed** with `nextcloud/app-certificate-requests` | **Dr K** | ✅ [PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213), from `kubed-io` |
| 5 | **Countersigned `.crt` committed back** to that repo | Nextcloud | ✅ merged 2026-09-03, four days after filing |
| 6 | **Private key into `NEXTCLOUD_STORE_KEY`** | **Dr K** | ✅ pasted from the file |
| 7 | Durable backup of the private key | **Dr K** | ✅ GCP `nextcloud-penpot`, round-trip verified |
| 8 | `NEXTCLOUD_STORE_TOKEN` is a valid apps.nextcloud.com token | **Dr K** | ✅ proven against the live API — see below |
| 9 | **Register the app id** on the store (cert + ownership signature) | either | ⬜ unblocked by gate 5 |
| 10 | Dry run, then cut the release | either | ⬜ needs 9 |

### Gate 4 — what filing the CSR involves

Fork [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests),
add **`penpot_sync/penpot_sync.csr`** — that exact directory and filename, their
tooling asserts the structure — and open a PR linking to this repository as the
public source. **Done in Round 14** — [PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213),
from `kubed-io` this time rather than a personal account.

**Three** things their process needs that are easy to miss, not the two this
paragraph used to list:

1. The GitHub account must **show an email address on its public profile**.
2. The commit must be **signed**. n8n's landed unsigned because the local signing
   setup was broken, and had to be amended and force-pushed. Check `git config
   commit.gpgsign` first — but verify by reading the raw object
   (`git cat-file -p HEAD | grep gpgsig`), because `git log --show-signature`
   reports "No signature" on a correctly signed commit when
   `gpg.ssh.allowedSignersFile` is unset, and that looks exactly like failure.
3. The commit must carry a **`Signed-off-by:` trailer**, or the DCO bot fails the
   PR. This list did not mention it and the first push was rejected for it; PR
   #1103's own commit message had one all along. `git commit -s` adds it, and
   `git commit --amend -s --no-edit` fixes it without disturbing the signature.

The third is the general lesson: **read the artefact, not the description of the
artefact.** One `gh api …/pulls/1103/commits` call answered what this paragraph had
left out.

### Gate 7 — why a backup is a gate and not a nicety

`NEXTCLOUD_STORE_KEY` **cannot be read back** once set; GitHub environment
secrets are write-only. `.signing/` is a working copy in a container. If both are
lost the recovery is a new keypair, a new CSR, a second countersign, and
re-registering the app id — which **deletes every published release**. n8n keeps
its key in GCP Secret Manager as the retrievable copy.

**Done in Round 14**, the same arrangement: GCP secret `nextcloud-penpot`, labelled
`app=nextcloud-penpot;purpose=appstore-signing` to match `nextcloud-n8n`, and
verified by reading it back and comparing checksums against `.signing/`. Until that
round the gate had simply been open, and nothing surfaced it — the GitHub secret was
set, which *looked* like the key was safe.

### Gate 9 — registering the app id

One call, and it can only be made after the certificate exists:

```sh
curl -X POST https://apps.nextcloud.com/api/v1/apps \
  -H "Authorization: Token $NEXTCLOUD_STORE_TOKEN" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
        --arg c "$(cat penpot_sync.crt)" \
        --arg s "$(echo -n penpot_sync | openssl dgst -sha512 \
                    -sign .signing/penpot_sync.key | openssl base64 -A)" \
        '{certificate:$c, signature:$s}')"
```

`201` means registered. The signature proves possession of the private key behind
the certificate; both must come from the keypair in §D4.10.

### What is deliberately still not done

**`appinfo/signature.json`** — Nextcloud's *in-tarball* integrity manifest, which
is a different thing from the tarball signature the store checks. It is optional
for store acceptance, and generating it faithfully needs `occ integrity:sign-app`
against a real Nextcloud, so it stays deferred exactly as it is in n8n. Worth
naming so nobody discovers the distinction during a failing release.

### The two store rules this app should be looked at against

Both are inherited from the sibling's review and neither is a blocker:

- **Public APIs only.** `Migration\RegisterMimetype` writes into the core tree —
  `core/img/filetypes/`, `core/js/mimetypelist.js`, `config/mimetype*.json`. This
  is the supported way to register a mimetype and every app that does it does it
  this way, but it is the most likely question from a reviewer.
- **Clean uninstall.** Stronger here than in n8n: there is a real
  `UnregisterMimetype` uninstall repair step, it is the mirror image of the
  install step, and `lifecycle.feature` covers it. This one is answered.

---

## What this chapter is not

- **~~Not a rewrite of the app.~~ It became one, in part.** This bullet opened the
  chapter promising no behaviour changes, and Rounds 10, 11 and 12 built real
  behaviour in `lib/` — folder restore, folder purge, and a three-valued reading of
  Penpot's trash. Round 13 explains why, and the honest version is: the queue this
  chapter promised not to touch turned out to be holding work the release needed.
  The bullet is struck rather than deleted, because a promise this chapter broke is
  more useful on the page than off it.
- **Not a release yet — but nothing is waiting on anyone else.** The CSR is filed
  and countersigned
  ([PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213),
  merged 2026-09-03). Gate 5 was the one step nobody here controlled; what is left
  is registering the app id and cutting the release, and both are ours.
- **Not the end of the queue.** Chapter 3 left a named backlog of `@todo`,
  `@unbuilt` and `@blocked` scenarios. This chapter worked the part of it the
  release stood on — the project verbs are down to scattered singles — and the rest
  is still named, still tagged, and still not to be trusted without reading `lib/`
  first. 27 scenarios remain unrun; `features/README.md § Status` is the live count.

---

## Open questions

1. ~~**When does this publish?**~~ **Answered in Round 5, filed in Round 14, and
   unblocked in Round 15.** The pipeline carries the submission, the CSR is in and
   countersigned
   ([PR #1213](https://github.com/nextcloud/app-certificate-requests/pull/1213),
   merged 2026-09-03). Nothing is waiting on anybody else: gates 9 and 10 are the
   whole remainder.
2. ~~**Does the tag gesture come out?**~~ **Answered in Round 8** — yes, in
   full. The harness now arranges a project folder the way a user gets one:
   the project is made in Penpot and the pull mirrors it.
3. **Does the sibling family converge on one docs layout?** The cascade (§D4.2)
   is written for this repository. n8n and Grafana have the same four documents
   and the same drift, and nothing has been ported back to them.
