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

---

## The plan — reaching the store

The store's requirement is that **every release is signed by a certificate
Nextcloud themselves countersigned**. That countersign is the only step in this
chapter that is not ours to pace, so it is filed first and everything else
overlaps the wait.

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
| 4 | **CSR filed** with `nextcloud/app-certificate-requests` | **Dr K** | ⬜ blocked on a human |
| 5 | **Countersigned `.crt` committed back** to that repo | Nextcloud | ⬜ the wait (~2 days for n8n) |
| 6 | **Private key into `NEXTCLOUD_STORE_KEY`** | **Dr K** | ⬜ secret exists, value empty |
| 7 | Durable backup of the private key | **Dr K** | ⬜ see below |
| 8 | `NEXTCLOUD_STORE_TOKEN` is a valid apps.nextcloud.com token | **Dr K** | ◑ set 2026-08-29, never exercised |
| 9 | **Register the app id** on the store (cert + ownership signature) | either | ⬜ needs gate 5 |
| 10 | Dry run, then cut the release | either | ⬜ needs 6 + 9 |

### Gate 4 — what filing the CSR involves

Fork [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests),
add **`penpot_sync/penpot_sync.csr`** — that exact directory and filename, their
tooling asserts the structure — and open a PR linking to this repository as the
public source. Two things their process needs that are easy to miss: the GitHub
account must **show an email address on its public profile**, and n8n's CSR
commit landed unsigned because the local signing setup was broken at the time,
which had to be amended and force-pushed afterwards. Check `git config
commit.gpgsign` before committing.

### Gate 7 — why a backup is a gate and not a nicety

`NEXTCLOUD_STORE_KEY` **cannot be read back** once set; GitHub environment
secrets are write-only. `.signing/` is a working copy in a container. If both are
lost the recovery is a new keypair, a new CSR, a second countersign, and
re-registering the app id — which **deletes every published release**. n8n keeps
its key in GCP Secret Manager as the retrievable copy; the same arrangement
works here.

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

- **Not a rewrite of the app.** No behaviour changes. If a round here changes
  `lib/`, it is because a comment was false.
- **Not a release yet.** The decision to publish has been taken and the
  pipeline is wired for it (§D4.9), but nothing has been submitted. The plan
  above names what is still outstanding and who holds it.
- **Not the end of the queue.** Chapter 3 left a named backlog of `@todo`,
  `@unbuilt` and `@blocked` scenarios; its close is the inventory. This chapter
  does not touch them, and does not pretend they are gone.

---

## Open questions

1. ~~**When does this publish?**~~ **Answered in Round 5.** The submission is
   committed to and the pipeline carries it. The remaining question is not
   *whether* but *when the countersign lands* — gate 5, and the only one nobody
   here controls.
2. ~~**Does the tag gesture come out?**~~ **Answered in Round 8** — yes, in
   full. The harness now arranges a project folder the way a user gets one:
   the project is made in Penpot and the pull mirrors it.
3. **Does the sibling family converge on one docs layout?** The cascade (§D4.2)
   is written for this repository. n8n and Grafana have the same four documents
   and the same drift, and nothing has been ported back to them.
