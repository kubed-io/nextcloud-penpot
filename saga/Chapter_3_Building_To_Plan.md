# Chapter 3 — Building to Plan

> Transmission log, Probe Designation **PENPOT-1**.
> Reclassified this chapter: **Public Works**.
>
> **Prerequisite:** Chapter 2 (*The Colony*) closed **complete**. Two things came
> out of it, and the second one is why this chapter exists.
>
> **We got our footing.** Every course in the settlement plan is standing — the
> radio, the settlement house, the survey stakes, two-way traffic, the salvage
> yard, the town square. We know how to talk to this planet, and the parts of the
> colony that exist, exist because we called the thing rather than reasoned about
> it. Three chapters' worth of confident wrong readings are on the record to prove
> the method (§C6.7, §6.26, §C6.37, and the reporter that printed 97 passes over a
> red matrix).
>
> **And then we drew the master design.** That is the real finale (§C6.38). The
> colony had been built course by course against a plan filed under the names of
> the crews who did the work — so *"what is true about this house?"* meant reading
> three crews' logbooks and hoping they agreed. Twice they did not. The plan is now
> filed by **house**: the folder is the noun, the file is the verb, 116 drawings,
> not one of them named after a crew.
>
> **The master design is complete and none of it is built to.** Rewriting the plan
> moved the survey marks out from under the 46 sections that had been signed off,
> so every drawing came back unstamped. 116 claims about this colony, zero
> witnessed.
>
> Chapter 1 asked *"can we dock?"* Chapter 2 asked *"what do we build, and in what
> order?"* and answered it with a plan good enough to build from. **Chapter 3 is
> the building.** One drawing at a time, and a drawing is not finished until
> somebody has stood in the room.
>
> Nothing new gets designed in this chapter. That is the whole discipline of it.

---

## Status: **CLOSED** — 2026-08-29

> **Closed by Dr K.** The chapter opened with 116 claims about this colony and
> zero of them witnessed. It closes with the colony standing, occupied, and in
> daily use by the people who built it.
>
> **Every major feature is a go.** Both directions of sync, the two buttons and
> their `occ` twins, the whole design verb set — create, rename, move, copy,
> delete, restore, purge — the project verb set beside it, mappings in both
> storage kinds and both modes, and the Files-app surface people actually touch.
> Nothing load-bearing is a drawing any more.
>
> **And it is relatively bug free**, which is a more careful claim than *bug
> free* and the honest one. Every defect this chapter found is fixed. The last
> three were found by living in the colony rather than by testing it — a folder
> shared with five groups that nobody could see, a design that duplicated itself
> crossing between storages, and a rule that behaved differently in two folders
> a person cannot tell apart. That is what a chapter of building buys: the bugs
> left are the ones only occupancy finds.
>
> **The queue that remains is written down and honest**: 24 `@todo`, 9
> `@unbuilt`, 4 `@blocked`, each naming what it wants. None of them is a
> surprise, and none of them is load-bearing.
>
> Chapter 1 asked *can we dock?* Chapter 2 asked *what do we build, and in what
> order?* Chapter 3 asked *is it actually there?* — and the answer is yes, one
> room at a time, with somebody standing in each of them.

---

## The doctrine — a drawing is not a building

Chapter 2 has one recurring failure and one recurring cure, and they are the whole
method here.

**The failure:** something was believed because it had been read. The route was
read and the parameters assumed, and it shipped broken (§C6.7). The trash was
declared unreachable from a guessed-name sweep (§6.26). A settle constant was
"measured, not guessed" off the wrong phenomenon entirely (§C6.37). A reporter
printed `97 tests, 97 passed` across a red matrix. Every one of those was
confident, documented, and wrong.

**The cure, every time, was to call the thing.** Not to reason harder about it.

Chapter 3 is that cure applied to the master design itself. **116 scenarios are
`@todo`, which means 116 statements about this app that nobody has witnessed since
the plan was redrawn.** A beautiful plan is exactly the kind of thing that gets
believed without being checked — which is why the plan being *good* makes this
chapter more dangerous, not less.

> **Dr K, opening Public Works:** *"The drawings are the best we have ever had.
> That is precisely why nobody gets to tell me a room is finished because it looks
> right on paper. We built two chapters on things we had read. We are going to
> stand in every one of these rooms."*

### The rule, and it is the only rule in this chapter

**A scenario stops being `@todo` only on a PR that runs it.** Promote it to live
when it passes; move it to `@unbuilt`, `@blocked` or `@decision` with the reason
written down when it does not. **Never** by re-reading the plan and deciding what
it probably is — that is the move Chapter 2 got wrong four separate times.

Two corollaries, both learned expensively:

- **Name the wall or it is not blocked.** `features/README.md` has said so for a
  while, and the collapse proved it: of the seven `@blocked` scenarios flattened at
  the end of Chapter 2, one named no wall anywhere and turns out to have none
  (§C6.38). The tag had been excusing it from scrutiny for months.
- **A failing `@todo` is a finding, so say so in the comment.** Two scenarios
  already carry that note — the app does the *opposite* of the plan, and promoting
  them is a bug fix. A silent failing `@todo` is indistinguishable from an
  unwritten one, and the difference is the entire value of the tag now that the
  tag no longer sorts anything.

---

## The plan — 116 drawings, and where we break ground

The queue is flat on purpose (§C6.38). It is not unordered, though: some rooms are
known to stand up.

| suite | scenarios | what it covers |
|---|---|---|
| `design` | 59 | the ten verbs on a `.penpot` file |
| `project` | 35 | the six verbs on a project folder |
| `admin` | 19 | the connection, and the mapping as configuration |
| `core` | 3 | the app enabling, disabling, being removed |
| | **116** | |

### Round 1 — re-survey: the nine that were already signed off

**These prove the harness, not the behaviour.** They passed in CI the moment
before the collapse, so if they do not pass now the fault is in the rewritten step
vocabulary and not in the app — which makes them the cheapest possible signal and
the only sane place to break ground.

| file | scenario |
|---|---|
| `connection/admin.feature` | An admin enters valid connection details |
| `connection/admin.feature` | An admin enters bad connection details *(outline)* |
| `lifecycle.feature` | Enabling the app |
| `lifecycle.feature` | Disabling the app |
| `mapping/create.feature` | Creating a mapping saves the form *(outline)* |
| `mapping/create.feature` | A team id that resolves to nothing cannot be mapped |
| `mapping/create.feature` | A mapping may not reuse a team or a folder *(outline)* |
| `mapping/manage-groups.feature` | The groups a mapped folder is shared with can be changed *(outline)* |
| `mapping/sync-now.feature` | Syncing one mapping brings its projects and designs into Nextcloud |

**Do these in one PR, not nine.** They are one question — *did redrawing the plan
break the harness?* — and nine PRs asking it would be eight wasted matrices.

### Round 2 — the two rooms built wrong

Both carry a comment saying the app does the opposite of what the plan now
demands. A plan the app *contradicts* is worth more than a plan the app merely
lags, because the contradiction is a live defect with a written-down expectation
already attached to it.

- **`designs/delete.feature` — "Trash a link".** The app trashes the file and calls
  it "hidden". A link is Penpot's copy to remove, so trashing it must be refused.
- **`designs/move.feature` — "Move an untracked design file into a project".** The
  app leaves the file untracked. A mapping that ignores a design sitting inside it
  is not a mapping.

### Round 3 onward — by noun, then by verb

After that the order is the plan's own: take a noun, take a verb, build the file.
One feature file is roughly one PR; the big ones (`designs/move.feature` at 13,
`projects/move.feature` at 10) split by **rule** rather than by scenario count,
because the `# ── RULE:` banners already group them and those groups are what a
reviewer can hold in their head.

**Report the count every time.** A PR that builds out a file says how many of its
scenarios went live, how many moved to a real status, and what wall each of those
names. That running total is the only number that says whether this chapter is
progressing — and Chapter 2's worst defect was a green report over a red run, so a
count nobody states is a count nobody can check.

---

## What this chapter is explicitly not

- **Not a design chapter.** Nothing on Chapter 2's "deliberately not built" list
  moves here. `keyed` folder mode stays unbuilt with its three open questions
  (#47), webhooks stay unexplained (#19), content push stays a permanent boundary
  (§6.1). Building to the plan means building *to* it.
- **Not a licence to redraw the plan again.** It was reorganised twice before it
  was reorganised right (§C6.28, then §C6.30–§C6.36, then §C6.38). If a scenario
  turns out to be wrong, fix that scenario and say so in `AGENTS.md`. A third
  restructure is a far bigger claim than any single scenario, and it needs the
  evidence this chapter produces before it can be argued at all.
- **Not finished when the queue is empty.** A scenario moved to `@blocked` with a
  named wall is *accounted for*, not *built*. This chapter tells you which rooms
  stand; deciding which walls are worth demolishing is the chapter after it.

---

## Where we stood — 2026-08-23 · **THE SPINE IS IN, AND A RULE HAS REVERSED**

> **Opening state.** 116 scenarios, all `@todo`. Zero running. Four integration
> legs reporting `tests="0"`.

### Round 1 — the nine that were already signed off · **9 live, 1 re-triaged**

The re-survey passed: all nine, in one PR, exactly as the plan said to. The only
work was two things the rewrite had dropped — `connection/admin.feature`'s blank
Background, and `lifecycle.feature`'s "Removing the app" flattened from `@blocked`
to `@todo` with its wall still written above it.

### Round 2 — the arrange spine, and the design and project verbs

Round 2 was supposed to be the two rooms built wrong. It is not, and the reason is
worth recording: **the spec rewrite replaced the step VOCABULARY, not just the
filing.** Measured across the 107 excluded scenarios, four sentences gated almost
all of them — `the app is connected to Penpot` (94), `the following mappings were
made:` (89), `a design file named … in …` (61), `the following items in the
mappings:` (42) — and exactly ONE excluded scenario had every step it needed. So
there was no cheap batch to pick up; the shared Background had to be built first.

| | | after Round 2 | now |
|---|---|---|---|
| live | headers → executed | **15** → **41** | **24** → **58** |
| `@todo` | the queue | 87 | 74 |
| `@blocked` | no browser (6), no app removal (1), no way to author a design (2) | 9 | 9 |
| `@unbuilt` | each names what the code owes | 5 | 7 |

The legs now stand at `admin` 25, `design` 10, `project` 21, `core` 2.

**`@unbuilt` GROWING IS THE HEALTHY DIRECTION HERE.** It went 5 → 13 across Round
3 and 13 → 11 across Round 4, and every move is the same measurement working:
Round 3 promoted nine scenarios and found that most of them described behaviour the
app did not have, so they were tagged rather than quietly skipped. Round 4 paid two
of those off and sent a third back with a better reason than it left with. A queue
that only ever shrinks is a queue nobody is measuring against.

### What the run found in `lib/`, which is the whole argument for running it

Four defects, none reachable by any amount of mocking, every one of them found by
a scenario failing rather than by reading:

1. **A project was named after its folder, not its path.** The pull has always
   read a Penpot project name AS a path — `PullService::ensureProjectFolder()`
   hands it to `newFolder()`, so `foo/Old` becomes the folders it spells. The push
   used `$folder->getName()`. One fact, two answers, and the flat case hid it
   perfectly: for `Penpot/Old` both readings give `Old`.
2. **A moved project folder never told Penpot.** `NodeRenamedListener` compared
   names to decide whether to push a rename — correct until (1) was fixed, and
   wrong the moment a project's name became its path.
3. **`rename-file` takes `id` on the wire, not `file`.** `PenpotClient::PARAMS` is
   a translation table; reading the call site and copying its array is not the same
   as reading the wire. Penpot answered with its own schema.
4. **A guard that could not see inside a `Scenario Outline`.**
   `check-step-definitions.sh` skipped every step containing a `<`, commented
   "resolved per example row", and nothing ever resolved them — so undefined steps
   were invisible in every outline in the repo. Now two-phase, and watched failing
   before being believed.

### The five `@unbuilt`, which were Round 3's queue

- `projects/create` ×2 — a design in a folder Penpot has never seen lands in
  Drafts; only a tagged folder becomes a project.
- `projects/move` — a move high in the tree renames only what moved, not the
  projects named THROUGH it. **Paid off in Round 4.**
- `projects/move` — a project folder cannot leave its team. **The spec disagreed
  with itself here**: `features/README.md`'s two-noun table said a project is
  "pinned inside its team folder", which was the app's behaviour, while the
  scenario expected the move to unmap it. **Settled in Round 4: the scenario was
  right, and so was `projects/copy`.**
- `designs/rename` — renaming a link is allowed; `MoveRules` permits any move
  inside the link's own project and a rename is one.

### Round 4 — §C6.38, and what a wrong rule costs

Round 4 had one subject: **the biggest thing the rewrite changed that the code had
not caught up with.** The gherkin said a project folder's name is its PATH below
the mapping, which makes a move a rename; the code still refused every move that
changed the team, in three places, citing §6.30.

The refusal was wrong, and not by a matter of taste. It was written down as a
consequence of the API — *moving a project between teams has to be done in Penpot
itself* — and the API says otherwise. `move-project` has taken `{project-id,
team-id}` since Penpot 1.16.

**A LIMIT THAT WAS NEVER CHECKED BECAME A RULE, AND THE RULE THEN DEFENDED
ITSELF.** §6.30 locked it "for now". `features/README.md`'s two-noun table wrote it
down as design. The `@unbuilt` note on the scenario then cited that table as
evidence the app was right and the spec needed a decision. Three artefacts, one
unverified belief, and each one citing the next.

The same read of `app/rpc/commands/` corrected two more claims of the same kind:

- **`duplicate-project` exists** (`{project-id, name?}`, "Duplicate an entire
  project with all the files"). README's table said Penpot has no such call and
  therefore a project copy is *refused* — while `projects/copy.feature` had already
  been rewritten to say a copy makes a new project. The table was the stale half.
- **`delete-project` exists**, taking a bare `{id}`. The harness wall below said
  its payload "would be a guess". It is not one any more.

What changed in `lib/`:

1. `MoveRules` lost the team rule and gained the MODE rule the spec actually
   states — nothing crosses the edge of a `link` mapping, in either direction. The
   old rule had been covering three of that scenario's four rows by accident, and
   getting the fourth (a link project moved *within* its own team) wrong.
2. `MotionService` stopped waving folders through. A project folder that crossed a
   mapping is one `move-project`; one that left every mapping is an unmapping, and
   Penpot is not contacted at all.
3. `PushService::pushFolderRename()` became a subtree walk. Penpot has no parent
   field, so re-parenting `foo` is one `rename-project` per project named through
   it — the cost of the path model, now paid rather than skipped.

### The fourth scenario, and why promoting it was still right

`Move a project folder into another team` was promoted with the other three, failed
in CI, and went back to `@unbuilt`. The wall is neither the rule nor the API, which
is exactly why no amount of re-reading would have found it: `Shared` is a **Team
Folder**, so the drag crosses a storage boundary, and core fires no
`NodeRenamedEvent` for those — it is a copy+delete underneath, and neither half
routes a folder. Nothing destructive happens. Nothing happens at all.

**PROMOTING IT IS HOW THE WALL GOT FOUND**, and the tag now records something
measured instead of something assumed. The temptation was to point the scenario at
two admin-folder mappings, where it would pass — and it would then be testing
something nobody asked about, one scenario after this file says out loud that *the
storage a mapping uses makes no difference to what a move is*.

The code owed turns out to be shared: this, `Move a folder of untracked designs
into a team`, and all three of `projects/copy`'s `@unbuilt` are the same missing
capability — **noticing a folder that has arrived inside a mapping**. Five
scenarios, one piece of work, which makes it the obvious next round.

### The asymmetry left behind, named rather than hidden

A review of the PR found a sixth thing for that round, and it is worth recording
because it is a place the two halves of one event now disagree.

`PushService::pushFolderRename()` walks the subtree; `MotionService::onFolderMove()`
does not. So dragging a PLAIN folder that holds projects across two mapped teams
renames those projects to their new path and leaves them in the old team.

**Incomplete, not broken.** Before §C6.38 that gesture did nothing whatsoever —
neither half fired — so it moved from entirely wrong to half right. What made
closing it in the same PR the wrong call is that it could not be PROVEN: the only
cross-team pair the suite can express crosses a storage boundary, which is the wall
above. Adding unit-tested-only code to a green PR is precisely the move this round
had already demonstrated the cost of, one scenario earlier.

It is written into `MotionService` at the line that returns early, so the next
person to read that branch meets it there rather than in a closed review thread.

**The one thing deliberately left half-done, said out loud:** the designs *under* an
unmapped folder keep their `penpot_team_id` and their `sync` mode. Finishing that
is `designs/move.feature`'s own `@todo`, and it needs `PenpotMetadata` to remove a
SINGLE key — it can write keys and drop a whole record, and unmapping a design
wants neither. Doing it here only for designs that happen to sit under a moved
project would make the two paths disagree in a new direction instead of fixing the
old one.

### Round 5 — deleting a project, and a `@todo` that was a lie

Round 4 ended by confirming `delete-project` exists and takes a bare `{id}`, and
recorded it as no-longer-a-guess. Round 5 is the round that used it, and the first
thing it found was that the whole verb was missing:

- `DeleteListener` returned on anything that was not a `File`;
- `DeletionService` had `onTrashed(File)` and `onPurged(File)`, and no folder path;
- `PenpotClient` had `createProject`, `renameProject`, `moveProject` — and no
  `deleteProject`.

Trashing a project folder was a plain local delete that reached Penpot not at all.

**AND `Trash a project folder` WAS TAGGED `@todo`**, which in this repo means *the
code exists; only the test is missing*. It did not exist. That tag is the failure
the four-tag vocabulary was introduced to prevent, arriving from the one direction
the vocabulary does not defend: an `@unbuilt` mis-tagged as `@todo` reads as work
QUEUED rather than work MISSING, and nothing collects on the promise until someone
runs the scenario. Worth carrying forward — the remaining 76 `@todo` are promises,
not an inventory.

The round closed four scenarios and two shapes:

**A gesture reaches everything named through the folder.** `DeletionService::
onFolderTrashed()` is a subtree walk, the delete-shaped twin of Round 4's
`PushService::pushFolderRename()`. It does NOT stop at a marked folder the way
`ProjectFolderService::managedDesignsBelow()` does, and the difference is the
question each is asking: that one wants *which designs belong to this project*, so
a nearer project ancestor ends the descent; this one wants *what is about to stop
existing*, and a project nested inside a trashed one is going too.

**The guard covers every verb.** Two holes, one rule apiece:

- `method:DELETE` reached nothing, so trashing a link project was a plain 204. A
  read-only mirror you can delete is not read-only.
- A link could be RENAMED, because a rename is a move to a sibling path — same
  verb, same event, same pair of nodes — so the position test resolved it to the
  project it started in and waved it through. `A link cannot be moved out of the
  project it points into` had been green for courses while `Rename a link in
  Nextcloud` sat `@unbuilt`, and the two are one rule. The NAME is the only thing
  that separates them, which is why `refusalForLandingIn()` now takes it: the DAV
  side asks before the destination exists, so it cannot read one off a node.

**The subtree walk had a blast radius nobody had drawn.** Review caught it: the
mapping ROOT carries a team marker and no project of its own, so `onFolderTrashed()`
started from it would descend the whole mapping and delete every project in the
team. One local folder delete destroying a Penpot team's work — from a walk written
to be careful about exactly this.

`PushService::pushFolderRename()` already had the carve-out and this did not, which
is the tell: the same shape written twice, and the second copy lost the guard the
first one had. It matters far more here, too — there, missing it costs a batch of
no-op renames. Tearing a mapping down is `occ penpot:remove-mapping` and is
deliberately non-destructive; a Files gesture must never be a more powerful version
of the command that exists to do the job.

**The harness and the rule turned out to agree already.** Refusing deletes inside a
link mapping looked like it would break `ArrangeSteps::emptyMappedFolder()`, which
clears mapped folders between scenarios. It does not: the arrange unmaps every
mapping BEFORE it clears, and its own comment says why — *"while a mapping is live,
deleting inside it is a gesture that reaches Penpot."* The same reasoning that
makes `isLinkTeam()` return false for a torn-down mapping.

### Round 6 — a folder is a project when a design is in it, and a spec that fought itself

The third doctrine reversal in as many rounds, and the same shape as §6.30:
`ProjectFolderService` opened with **"by opt-in, never by accident"** and named the
`penpot` tag as the only way in, while the spec had moved to *promotion by content*.

The old reasoning was sound and the conclusion too strong. It was protecting the
case where a mapped folder becomes unusable for anything else — notes, exports, a
subfolder of references — and that case is protected by a NARROWER rule the suite
already runs live: `Create a folder in a mapping` says an EMPTY folder is not a
project. None of those folders holds a design. What the broad rule cost was the
case that actually matters: someone makes a folder, fills it with designs, and
Penpot never hears about it.

**AND THE SPEC CONTRADICTED ITSELF, load-bearingly.** Two notes in `AGENTS.md`,
each with a feature file following it:

| | |
|---|---|
| *"the project is created as a CONSEQUENCE of the first design landing in it"* | `projects/create.feature` — `Penpot/Team` becomes the project `Team` |
| *"ONE RULE, AND DEPTH IS NOT PART OF IT … a plain folder three levels down … therefore Drafts"* | `designs/create.feature` — `Penpot/Inbox` files into Drafts |

Same situation, opposite answers, and no way to build the round without picking
one. Settled in favour of `projects/create.feature` on the organising rule the
suite already runs on — **`projects/` owns a folder's identity as a project** —
and because the adoption note reasons about the choice (*"a move is a gesture
people already make, and a tag is one they have to be taught"*) where the other
only asserted uniformity.

So the rule keeps depth in exactly one place: **the mapping ROOT is Drafts and
nothing else is**, which is not an exception bolted on but
`pathBelowMapping()` returning null. A root has no path below a mapping to be
named by, so there is no project it could become — the same method §C6.38 added to
NAME a project, read here to decide whether there is one.

`designs/create.feature` lost the `Penpot/Inbox` row and the fixture behind it;
the case now lives in `projects/create.feature` where the noun belongs.

**Where the rule stops is where the EVENT stops.** `Create a design in a folder
Penpot has never seen` passes on all four rows. `Move a folder of untracked designs
into a team` does not, and it fails on two walls at once, neither of them this rule:
a folder move fires ONE event for the folder and none per child, and the design
inside is an uploaded archive, which §6.33 has always refused to import. Either
alone would be enough. It reads as though it should work, which is why the note now
says why it does not.

**The round also retired a harness workaround it had written itself.**
`ArrangeSteps::declareDesign()` tagged a folder before writing a design into it,
with a comment explaining that a design alone would land in Drafts and that
`projects/create.feature` "is still @todo and stays that way — this PR has not run
it". It runs now, so the tag stopped being load-bearing and the arrange says what
the app does instead of working around what it did not. That also fixed the ROOT
case for free: `a design file named … in "Penpot"` used to tag the mapping root,
which `ProjectFolderService` correctly untags, leaving the arrange to throw about a
folder that was never going to be a project.

**A NEGATIVE ASSERTION WENT GREEN FOR THE WRONG REASON, and only reading it
found that.** Both `Move a folder that other projects are named through` and its
delete-side twin arrange `foo/bar` and `foo/bar/baz` as projects, and used to get
them free because the harness tagged every folder it wrote a design into. Under
promotion by content `baz` is a plain subfolder of `foo/bar` — nearest ancestor —
so it is not a project unless the scenario says so. The MOVE scenario failed
loudly. The DELETE one passed: *"Penpot holds no project named `foo/bar/baz`"* is
trivially true of a project that never existed. Both arranges now spell the nesting
out with the `kind` column, which is what it is for.

**TWO THINGS LEFT OPEN, AND SAID OUT LOUD.**

*A link mapping could be promoted.* A brand-new `.penpot` PUT into a link mapping
is still created as a design, because `LinkWriteGuardPlugin` classifies from the
file's OWN metadata and a new file has none. That is older than this round and is
`designs/create.feature`'s `Creating a design in a link-mapped folder is refused`,
still `@todo`. What this round DID close is promotion on top of it: a stray design
is one thing, a stray design plus a project nobody asked for is another.

*A failed re-file leaves a stamped folder.* `adoptForContent()` stamps the marker,
then files the designs already in the folder, and swallows a failure from the
second. So designs can stay in their old Penpot project while the folder claims a
new one, and the marker's fast path stops a retry. `onTagged()` has had exactly
this shape since it was written, and the docblock argues for it: an unstamped
folder would be a project in Penpot that nothing in Nextcloud points at. Both
orderings lose something, which is why this is recorded rather than quietly
changed on a round that is green.

**BOTH ROUTES IN MUST MEAN THE SAME THING.** Review caught the second half of it:
`onTagged()` re-files the designs already in the folder — that is the whole point
of allowing a LATE opt-in — and `adoptForContent()` did not. A managed design can
already be below a plain folder (one that left a mapping and came back, one whose
own promotion Penpot refused), so filing only the newcomer left two designs in one
folder showing up in two projects. Both routes now share `fileExistingDesigns()`,
because which gesture promoted a folder must not change what the folder means.

The same shape as #38's near-miss, one class over: a behaviour written twice, the
second copy quietly weaker than the first.

**A RACE THE OLD RULE DID NOT HAVE.** Review caught it: promotion reads "is this
folder a project", then makes a network round trip, then stamps. Dragging three
designs into a new folder is three concurrent requests in three processes, and
Penpot allows two projects of the same name in one team — so the unmitigated race
splits the designs across duplicates while the marker records whichever write
landed last.

Tagging had the same shape and was nearly unreachable, because tagging is one
deliberate act by one person. Promotion by content is reachable by an ordinary
multi-file drag, so the exposure is this round's.

Not closed with a lock, which is a dependency and a failure mode of its own.
Narrowed instead by re-reading the marker after the create: a loser returns the
WINNER's id, so every design is filed into one project and the only casualty is an
empty project nobody references. **Files together and one stray project beats files
scattered across two**, and it costs one metadata read on a path that has just made
a round trip. The residual window is written down where the code is.

**One seam, three arrival paths.** `DestinationResolver::projectForContentIn()` is
a second method rather than a flag on `projectFor()`, because it can CHANGE THE
WORLD — it creates a project as a side effect, so every call site has to have
decided it means to. Create, move-in and copy-in all adopt; the one that must
never is `MotionService::sourceProject()`, which asks where a file CAME from, and
adopting there would create a project for the folder someone just dragged a design
out of. Two methods make that a compile-time distinction instead of a boolean
nobody reads.

**What the sweep for "anything else now doable" actually found.** Two more
mis-tagged scenarios, in the other direction from Round 5's:

- `designs/move.feature`'s `Move a design between projects` is `@todo` for a
  contradiction inside itself — it asserts `content | an archive` across both
  Examples blocks, and the second carries a link row, which is zero bytes by
  design and which `designs/view.feature` pins as `empty`, live. Left alone rather
  than trimmed: dropping the row would make it pass while giving up a real claim.
- `designs/move.feature`'s `Move an untracked design file into a project` is
  tagged `@todo` while its own comment says `@unbuilt`.

Both are worth more than the scenarios they block: a `@todo` queue is only as good
as the tags in it, and this is now three rounds running where reading one closely
found it lying.

### The wall that fell over without anyone pushing it

**Penpot state no longer accumulates across a leg, and nobody built that on
purpose.** Chapter 3 has carried this since Round 2: teams are find-or-create by
name, so a project an earlier scenario made survives into the next one, and
`projects/rename`'s Penpot-side outline is `@todo` because of it.

Round 5 gave the app a folder delete. `ArrangeSteps::emptyMappedFolder()` clears
each mapped folder between scenarios by deleting its children — and a child that
carries a `penpot_project_id` now takes its Penpot project with it. The clean-up
that used to be local reaches Penpot.

Measured, not deduced. In one run of this round, `Create a design in a folder
Penpot has never seen` made a project called `Team` in two different teams, and a
scenario a few lines later reported the team's projects as
`Drafts, Drafts, Existing, Drafts, Existing, Drafts`. The `Team` projects were
gone.

**It is load-bearing and it is incidental, which is the worst combination.** The
adoption Outline in `projects/create.feature` relies on it: without it, row 1
leaves `Penpot/Team` a project, and row 2's `Penpot/Team/Deep` resolves to it by
nearest ancestor and is never promoted. A review raised exactly that and was right
about the mechanism — the rows only pass because something else cleans up first.
Written down here so the next person to touch `emptyMappedFolder()` or the delete
carve-outs knows what they are holding.

Whether `projects/rename`'s outline can now be picked up is a question for a round
that tries it, not a claim to make from here.

### The one harness wall left

`projects/rename`'s Penpot-side outline is `@todo` for a reason that is neither
spec nor app: **Penpot state accumulates across a leg.** Teams are find-or-create
by name and survive the scenario, so a second scenario renaming `Old` → `New`
finds a `New` already there. Real isolation needs either a unique team per scenario
(which breaks every scenario that names a team) or a teardown that deletes what a
scenario created — and Round 4 removed the excuse for the second: `delete-project`
takes `{id}`, read off `schema:delete-project`. Whether a teardown SHOULD delete
from Penpot is still a question worth asking before it is built.

---

## Round 7 — the id stopped deciding anything

Two scenarios had sat `@blocked` since the spec rewrite on the reasoning that
*"`I select` is Nextcloud's conflict dialog and there is no browser here; WebDAV
never asks the question, it just overwrites"*. Both halves are true and the
conclusion does not follow, which the grafana sibling had already demonstrated by
running the same two scenarios all along.

**The dialog is a CLIENT concept.** `moveOrCopyAction.ts` PROPFINDs the
destination, finds the collision, and opens the picker *before a single request
goes out* — so the answer decides whether one is sent at all, and under what name.
Each answer is one ordinary WebDAV request:

| answer | request |
|---|---|
| the existing version | none at all — doing nothing IS the implementation |
| both versions | one MOVE to a free name |
| the new version | one MOVE with `Overwrite: T`; Sabre deletes the destination, then moves |

A `@blocked` tag that names a *capability* the harness lacks is checkable. This one
named a conclusion, and the conclusion was wrong for two years of rounds.

### What the scenarios turned out to be asking for

`the new version` wanted the surviving file to hold the arriving archive **and**
keep the destination's id. Penpot cannot do that. The whole surface `PenpotClient`
wraps is `create-file`, `rename-file`, `duplicate-file`, `delete-file`,
`move-files`, `export-binfile`, `import-binfile` and the project/trash commands —
and `import-binfile` always mints a new design. There is no way to put new bytes
inside an existing one.

So the spec was asking for something the API cannot express, and the two halves of
that `Then` were mutually unsatisfiable: keep the id, or have Penpot hold the
chosen bytes. Not both.

**Content won, and it was not close.** What a person answering that dialog is
choosing is which *design* they end up with; the id is bookkeeping they never see.
`its id` went from an Examples column to nothing at all — six rows became two.

### The rule underneath, which was the real find

Chasing that exposed something older and worse. Moving a file back into a mapping
**reattached**: read the id off the file, untrash the design if parked, file THAT
design into the project. The id was authoritative for identity while Nextcloud
stayed authoritative for content — and inside one sync interval those collide:

> park a design → unarchive it in Penpot → edit it → trash it again → drag the
> file back, all before a scheduled pull

The reattach hands back bytes the user never saw and could not have asked for.
Nothing local can find out in time; the pull that would have noticed has not run.

The bytes in Nextcloud are what the person is holding, so they are what must exist
afterwards. An import guarantees it and mints an id doing so, which is why the id
a file arrives carrying now decides **nothing**. Three scenarios collapsed into
one: a live design, a parked one, a stranger's id and no id at all had been four
branches of a question that no longer has branches.

`revive()`, `isParked()`, `untrash()`, `restoreOnce()` and `staysOutOfTheTrash()`
went with the rule they served — **−200 lines from `MotionService`**, and no unit
test covered any of it, which is how it lasted.

### The duplicate guard, and the state it prevents

The other half of the same rule. `both versions` lands two files in one mapping
carrying one `penpot_id` — the "two files, one design, forever" state the pull's
own indexes are written to avoid, which no later pass separates. An arrival whose
id is already held by another file under the same mapping root now imports as its
own design. **The arrival always gives way**: the file already there is what every
other node has been resolving against.

### What review caught that CI could not

`$from === null` stood in for "arrived from outside every mapping". But
`DestinationResolver::projectFor()` returns null for two unrelated reasons — no
team above the source, *or* a team whose Drafts project the token cannot see. The
second reading imports a file that never left, minting a design and abandoning its
history, because a lookup failed on our side. It asks `sourceTeam()` now: folder
markers only, nothing in it that can fail the way a Penpot lookup can.

Correct for every case considered, wrong for one that was not. That is the case
for a reviewer, and no integration run would have produced it — the failure needs
a Drafts lookup to fail, which CI has no reason to arrange.

### Seven harness bugs, one shape

Getting there cost seven CI rounds, and the failures rhymed: **a shared fixture
whose assumptions changed without its other callers being checked.** A cursor an
arrange never set. A path an assertion inherited instead of naming. A folder two
features apart, deleted by a scenario with nothing to do with this one. A fake
archive that stopped being good enough the moment arrivals started being imported
— and then a real one, in the single feature that has no mapping to export
through, because it is the feature that *makes* mappings.

The step definitions in this suite are far more coupled than they look. Every one
of those was caught by CI rather than by reading, which worked, but the reading
would have been cheaper.


## Round 8 — the push, and eleven runs spent on one test

Two PRs. The first built "Sync to Penpot" — the direction three files said would
never exist — and the second spent an evening making its test pass.

### §6.1 said less than everyone thought

Three files claimed this app is read-only for design content and that a push was
therefore permanently off the table. That over-read the rule. §6.1 forbids pushing
SHAPE DATA into a design Penpot already has; it says nothing about an archive
Penpot has never seen — and the app had been importing those on every drag-and-drop
for courses.

So `BulkPushService` walks each `sync` mapping and hands every archive that is not
already a mirror to the same `ImportService::adopt()` a dragged-in file uses, with
the destination resolved by the same `DestinationResolver::projectForContentIn()`.
The path model, Drafts at the root, project creation: all free, rather than
answered a second time.

**An `unmapped` file IS pushed, and this is where the siblings stop being the bar.**
Grafana skips them, correctly for Grafana: a file there keeps its uid and reattaches
to the same dashboard. Penpot cannot reattach at all (§6.20 — a deleted design
cannot be resurrected, and `import-binfile` always mints a new id), which is exactly
why Round 7 removed the reattach. Skipping them would strand real bytes in a mapped
folder that nothing in Penpot answers to.

### The bug the push found in provisioning

`ensureRoot()` created the mapping's folder and never marked it: the only writer of
`penpot_team_id` on a root was the pull. So between "mapping saved" and "first
pull", the folder was indistinguishable from any unmapped folder — and two things
failed silently on the first thing anyone does with a new mapping. A push declined
every file and reported "processed, nothing done". Creating a design in the folder
was refused outright by `MoveRules`, which cannot tell an unmarked root from
somewhere outside every mapping.

The marker is a property of the mapping EXISTING, not of a sync having run. It
moved into `ensureRoot()`, which all three callers already go through — the same
reasoning that had already moved PROVISIONING there from the first pull, and which
nobody had thought to apply to the marker.

### Eleven runs, and the pattern in every wrong answer

`connection/sync-now.feature` failed at 30/32 for an evening. Five diagnoses were
argued from the symptom and every one was wrong-or-partial: the mapping teardown,
a fixture's own delete, per-row folder emptying, `get-projects` reading
soft-deleted projects, five separate reshapings of `emptyMappedFolder()` — three of
which broke other legs.

Two things made it that expensive.

**Two independent faults produced one symptom.** The arrange's folder-emptying
destroyed the team; `get-projects` (which does not filter soft-deleted projects,
§6.42) then read the dead project, saw the designs it still listed, and skipped
re-seeding — hiding the damage. Fixing either alone moved the count not at all, so
each correct fix looked wrong and one was reverted. **When a fix does not move the
number, suspect a second fault before concluding the first was wrong.**

**The Background was the fixture.** This is the only file in the suite whose
Background holds what its assertion checks; the other ten seed per scenario. An
arrange that clears a mapped folder between scenarios is harmless there and fatal
here — and in penpot a delete inside a live mapping reaches Penpot, which is a
hazard Grafana's harness does not have because it never clears at all.

The fix was not in the harness. It was three lines of Gherkin: drop the Scenario
Outline whose second row re-ran the Background against a folder the first row had
mirrored into, and give each scenario its own `Given`. Every harness change was
reverted; `ArrangeSteps` ended byte-identical to where it started. The feature is
smaller than when the evening began.

**A probe found in one run what five arguments could not.** Printing Penpot's
actual contents at each step boundary named the deleting call between two adjacent
lines. Measure the state; do not reason from the symptom.

### And the names

Reused fixture names are safe for a feature asserting only its own rows — which is
why ten files share `Cogs` happily — and unsafe for one asserting a whole tree.
`connection/sync-now.feature` shared its team and three designs with
`mapping/sync-now.feature`, in the same leg, against one Penpot. No harness fix
could ever have made that pass: the neighbour was entitled to reshape the team.
Every noun in that file is now its own.

## Round 9 — two minutes against a live instance, and a wall that was a listener

Round 8 ended on a lesson about measuring rather than reasoning, and Round 9 is the
round that took it somewhere. Two things came off the blocked list, and neither
needed a new idea — both needed somebody to look at a running instance instead of at
the code that describes one.

### The scenario that had been `@blocked` for four rounds

`Move a design into another team` was tagged with a reason that was true and
incomplete: *the only two teams this suite can map sit on different storages, and a
file's metadata does not survive the crossing.* Four CI rounds had established
**that** the metadata was lost. Nothing had established **what lost it**, and the
difference turned out to be the whole thing.

The probe was a plain `.txt` file, stamped with `penpot_id`, moved from a home folder
into a groupfolder mount — **with a same-storage rename as the control, in the same
script, in the same run.** Penpot was never contacted. It took about two minutes.

| | file id | `penpot_id` afterwards |
|---|---|---|
| same-storage rename | preserved | **survives** |
| cross-storage move | **preserved** | **gone** |

**The file id is preserved**, which nobody had assumed and everybody had reasoned
past. A cross-storage move looks like a copy-and-delete, so the natural story is a
new file with a new id and orphaned metadata. The real story is that the id survives
and the metadata is destroyed *on purpose*: removing the source cache entries raises
`CacheEntriesRemovedEvent`, and core's own `MetadataDelete` listener drops the rows.

That reframes the problem completely. There is nothing to look up afterwards and
nothing to repair — but there is a last moment when the record still exists, and it
has a name: `BeforeNodeRenamedEvent`. `MoveMemoryListener` reads the identity there;
`MoveMemory` holds it for the length of the request, on exactly the argument
`SyncGuard` already makes; `MotionService` consults it only when the arriving file
has no metadata at all. From the re-stamp onward nothing downstream can tell the
difference between a design that crossed a storage and one that did not — the
project comparison, the `move-files` and the team re-stamp all work unchanged.

Penpot needed nothing. `move-files` has carried the destination team in one call
since §6.27/§6.34. **Nextcloud was the half that could not express it**, and the
app's own docblock had been asserting the wrong mechanism since before `sync` mode
existed: *"a move that crosses a storage boundary … never reaches this service."* It
reaches it. It always did. It just arrived with nothing to identify it.

### The bug the live instance volunteered

While the probe was running, the instance produced a second finding nobody went
looking for: a mapped folder shared with five groups was invisible to every member
of all five.

The shares were there. Correct node, correct permissions, correct groups. And
`DefaultShareProvider::create()` writes `accepted = STATUS_PENDING` for every share
it makes, unconditionally — there is no flag to set and no argument to `createShare()`
that changes it. `Files_Sharing`'s mount provider then built the super-share and
declined to mount it. A group share raises no acceptance prompt the way a user share
does, so there was nothing for anyone to click.

Proved both directions in one script: the mount provider returned the super-share at
status 0 and produced no mount; accepting the four pending shares and asking again
produced the mount, nothing else changed. `StorageService` now accepts each share for
each member as it shares — and only from `PENDING`, so somebody who deliberately
removed the folder from their own Files view is not handed it back every time an
admin re-saves the mapping's groups.

**This one had been shipping.** Every mapping made with groups had provisioned a
folder nobody could see, and no test in eleven legs could have caught it: the suite
asserts through WebDAV as a user who is the owner, and an owner sees their own folder
whatever the recipients' shares say.

### What the round is actually about

Round 8's closing line was *measure the state; do not reason from the symptom*, and
it was written about a Penpot probe inside CI. Round 9 is the same lesson at a
larger radius: **the running instance is a primary source, and it is cheaper than
the argument about it.** Four rounds of inference produced a tag saying "cannot".
Two minutes of measurement produced a listener — and, unasked, a user-facing bug
that had been invisible precisely because it was invisible.

## Round 10 — the rule's own edge, and a leftover that argued for itself

Round 9 said the running instance is a primary source and cheaper than the argument
about it. Round 10 is the round where the instance produced a bug report instead of
a measurement.

### The edge

`projects/create.feature` has stated one rule since §C6.38: **a folder is a project
when a design is in it, named by the path from the mapping down to it.** It shipped
with an edge that seemed like a refinement and was a trap:

> A design in `Penpot/foo/bar/baz` where `foo/bar` is already a project belongs to
> `foo/bar` — nearest ancestor, §6.29 — so `baz` does NOT become a project by
> holding it. Only a folder with no project above it is promoted.

Written down twice, in `features/AGENTS.md` and in the class docblock, and pinned by
an Examples block in `designs/move.feature` captioned *"a plain subfolder is
Nextcloud's layout, which Penpot cannot see"*. A second block sat directly beneath
it teaching that a nested project folder names its project by its path, with a note
between them explaining that the two shapes *"look identical AND MEAN OPPOSITE
THINGS"*, which was offered as a subtlety worth learning.

The report: a project folder `Bubbles`, a new folder `Bubbles/pustice`, a design
dragged in — and nothing at all in Penpot. Both designs still in `Bubbles`.

**The app was correct.** Every marker on the live instance was exactly what the spec
described. The rule was the defect, and the shape of the defect is the lesson: two
folders that a user cannot tell apart behaved differently, and which behaviour they
got was decided by the accident of which one had received a design first. There is
no gesture that reveals it and no way to choose. A rule you cannot see is a rule you
cannot use.

It also made the feature file's own headline false of every folder below a project,
which is the kind of contradiction a spec can carry for months because each half is
read on its own.

So the edge is gone. The folder a design lands in is the project.

**READING AND ARRIVING SPLIT, and keeping them apart is the whole of the change.**
§6.29 still resolves a node to the nearest project ABOVE it — a design already
sitting in a plain subfolder belongs to the project above until something arrives in
that subfolder. Arriving promotes; sitting there does not. `DestinationResolver`
answers the arriving question and `MembershipResolver` the reading one, and the
short-circuit that used to make them the same method's answer was the bug.

The one asymmetry left is a `link` mapping, where promotion is refused because the
tree is Penpot's to write. The fallback there is the project ABOVE and deliberately
not Drafts: Drafts would move somebody's design out of the project Penpot has it in,
because they made a folder.

### The leftover that argued for itself

Offered as an option before the rule was reversed: *tag the folder `penpot` and it
becomes a project* — which is live code, does exactly that, and files the designs
inside. It was also the mechanism §C6.18 **retired** several rounds earlier, in
favour of promotion by content.

The answer came back: *the fact you even suggested this means there is conflicting
information in the code, the saga and the AGENTS files.* There was, and it is worth
recording what the conflict actually consisted of, because none of it was a comment
someone forgot to delete:

- `ProjectTagListener`, registered on `TagAssignedEvent`, with its own unit test;
- `ProjectFolderService::onTagged()`, ~120 lines, fully documented;
- `ProjectTags`' class docblock describing the tag as *"both the app's marker and the
  user's opt-in"*;
- `PenpotClient::createProject()` documented as *"ONLY EVER CALLED ON AN EXPLICIT
  OPT-IN … exactly one caller, `onTagged()`"*;
- and a README paragraph advertising it as a headline feature.

`features/AGENTS.md` had even flagged it — *"It has no scenario anywhere in this
suite — a leftover worth deciding about rather than inheriting"* — and that sentence
had been sitting there, undecided, being inherited.

**A RETIRED RULE WITH LIVE CODE BEHIND IT IS NOT DEAD CODE. It is a second answer,
and it will get proposed.** The right time to delete a mechanism is the round that
replaces it; every round after that, the replacement and the leftover are both true
of the repository and only one of them is true of the product.

It is still here. The reason is measured rather than sentimental: the HARNESS uses
it. `ArrangeSteps::ensureProjectFolder()` tags a folder to make it a project, which
is how every `kind: project` row is arranged — 27 of them, of which **10 need a
folder that is a project while holding no design at all**. The other 17 get a design
and are promoted by content anyway. So removing the gesture is a harness change
first and a deletion second, and putting it in the same PR as a reversal of the
promotion rule would have stacked two independent regression surfaces.

What went in this round: the README stopped claiming it, and `features/AGENTS.md`
stopped calling it "worth deciding about" and started saying what it costs and when
it goes. What did not: the code. Named, dated, and next.

---

## Round 11 — the docs stop carrying history, and gain a direction

Round 10 closed on a leftover that argued for itself: a retired rule with live code
behind it is a second answer, and it will get proposed. This round is the same
observation aimed at the prose, because the prose had the same problem at a larger
scale — three files were each carrying a bit of the history, and none of them was
the record.

### The cascade, which is the actual decision

There are four documents about behaviour in this repo and, until now, no rule about
which one holds what. So each grew whatever its author needed at the time:
`README.md` had a pre-alpha status block, `AGENTS.md` had 180 lines restating
decisions the saga already owned, and `features/AGENTS.md` had seven sections about
feature files that no longer exist.

The rule now is a **cascade**, one hop per level of detail, each level linking up to
the next:

| Level | Document | Holds | Points at |
|---|---|---|---|
| 1 | `features/**/*.feature` | The specification. What the app does, in plain language. | its section of `features/AGENTS.md` |
| 2 | `features/AGENTS.md` | Why each scenario is the shape it is — as of today. | the saga § that decided it |
| 3 | `saga/` | **The history.** What was decided, what it replaced, what proved it. | nothing; it is the bottom |

The load-bearing half is level 2's constraint: **`AGENTS.md` describes the present
tense.** A note that opens *"this used to…"* is a note in the wrong file. It goes
here, and level 2 keeps a pointer.

That is not a style preference. A retired decision sitting in a working document is
indistinguishable from a live one to whoever reads it next — which is precisely how
Round 10's tag mechanism kept getting proposed, and how §C6.38 found two crews'
logbooks disagreeing about the same house.

### What moved, and what it cost

Seven top-level sections of `features/AGENTS.md`, 454 lines, all of them about
feature files that were retired during Chapter 2. They are reproduced whole below —
nothing was summarised, because the reason each scenario was dropped is the part
worth keeping.

**Two live notes were buried inside them**, which is the argument for the cascade in
one finding. `designs/create.feature` has been pointing into
`## mapping-membership — RETIRED` for its Drafts rule, and `lifecycle.feature` into
`## uninstall — RETIRED` for the wall on removing the app. Both anchors resolved, so
`check-notes-anchors.sh` was green the whole time: the checker proves a pointer
lands somewhere, not that it lands somewhere true. Both notes moved to the sections
that own them.

**Six feature files were pointing at anchors named after the old layout** —
the five `mapping/*.feature` still said `#team-mapping*`, seven rounds after
§C6.38 renamed the folder, and `designs/edit.feature` pointed at `#edit-design`,
a heading left over from the flat filenames. Same shape as the mis-filed notes:
resolving, and wrong.

### And the checker had the bug it was written to prevent

`check-notes-anchors.sh` exists because a breadcrumb rots silently. Its own slug
rule did not match GitHub's: it collapsed runs of spaces, and GitHub does not.

A heading with an em-dash in it — *"Filing a draft — dragging from the team root
into a project"* — loses the dash and keeps the spaces on both sides of it, so
GitHub's anchor carries a **double** hyphen there. The checker produced a single
one and was satisfied. Three breadcrumbs passed CI and 404ed for anyone who
clicked them.

Nobody clicks a breadcrumb in CI, which is the whole reason the script exists —
and it is a fair reminder that a guard is code, and gets the same class of bug as
the thing it guards. Rule matched to GitHub's, two live breadcrumbs corrected, and
the reason written into the script's header where the next person will find it.

### The record

Everything below was `features/AGENTS.md`'s until this round. It is the disposition
of every scenario in seven retired feature files — where each one went, or why it
was dropped rather than moved.

### admin-section — RETIRED

`features/admin-section.feature` is **gone**. It described the settings panel:
which cards exist, what order they appear in, which fields each holds, where the
buttons live. Twelve scenarios, none of them a thing anyone does.

| it said | why it went |
|---|---|
| The section presents four panels in the family's order | panel ORDER is an implementation detail of the UI |
| The Instance card holds both the URL and the service-account token | the structure of a form — those fields are the INPUT to connecting, not a behaviour |
| The token field never echoes a stored token back | already asserted verbatim in `connection/connection.feature`, as the end state of saving a token: *the token is stored as a sensitive value* |
| Every button in the section lives in Sync Actions | a UI nuance, and `@blocked` because nobody is testing whether the layout looks good |
| Test connection works today and reports what the account can see | `connection/connection.feature`'s, and already there |
| "Sync from Penpot" queues a background job and says so | `connection/sync-now.feature`'s — and whether a run is queued or synchronous is a mechanism this suite asserts nowhere, deliberately |
| The panel reports the outcome of the last run | an END STATE of syncing → moved onto the sync outline as `And the run is recorded with when it ran and what it did` |
| A second click while a sync is running does not start another | a real edge case → moved to `connection/sync-now.feature` |
| The scheduled pull uses the interval from Sync Settings | implementation detail. The interval and the enable toggle are connection settings — inputs, which Gherkin need not describe. The schedule already appears as an actor row in the sync outline |
| Turning the schedule off stops the runs | a negative with nothing to observe: the only honest test is that every `@in-penpot` behaviour stops arriving, which is a test that waits forever |
| There is no "Sync to Penpot" button, ever | a negative check on a feature that will never exist |
| Purge is offered but disabled until the delete machine exists | pins the presence and disabled-ness of a button; if it is anything it is `designs/purge.feature`'s |

THE PATTERN WORTH REMEMBERING: a settings panel is where a behaviour is
*configured*, not a behaviour. Its fields are inputs to the thing they configure,
and its layout is not specification at all. Everything real in this file already
had a home in `connection/` — which is why the folder split is what made the
duplication visible.

#### A second sync started while one is running does not queue another

FOUR DOORS, AND THEY ALL HOLD THE SAME LINE NOW: the section's button, the
scheduled job, the card's button and `occ penpot_sync:sync`. Two pulls over one
folder tree race on the same files, and the scope of each does not make it safe —
a card sync and an instance-wide one collide exactly as two instance-wide ones do.

THE CLI GETS AN ESCAPE HATCH THE BUTTONS DO NOT NEED. `isBusy()` reads a STORED
flag, so a run killed outright — SIGKILL, an evicted pod — leaves it at `running`
forever. A button can wait for the admin to try again later; the CLI is the
headless door someone reaches for when the UI is the thing misbehaving, so
refusing it without a way through would wedge the one tool that could unwedge
things. `--force` runs anyway.

`@blocked` on the scenario itself — no fault injection, and no way to hold a run
open while a second is issued. The CLI half is the one that could be driven (set
the status, run the command, assert the refusal); it is not written yet.

`@blocked` — **no browser**, and no way to hold a run open while a second is
issued. Two concurrent pulls over one folder tree would race on the same files,
which is the only reason this is worth stating.

### errors — RETIRED

`features/errors.feature` is **gone**. "Failures never cost the user data" is an
INVARIANT, not a behaviour: nobody performs an error. An error is what happens
when something a person *did* goes wrong, so each one belongs with the behaviour
that can fail — the same reasoning that retired `file-type` (a construct),
`reconcile` (a mechanism) and `admin-section` (a panel).

The `When` lines gave it away. Almost none had a human actor:

    When an export stream closes with no "end" event      the transport
    When the app exports any file                         the app itself
    When "get-project-files" fails for that project       an RPC command, by name
    When the pull is interrupted partway through          the reconciler again

Twenty-one scenarios in, eight out.

#### Where each one went

| scenario | disposition |
|---|---|
| An error inside a 200 response is treated as a failure | → `team-mapping/set-mode.feature`, row 1 of one outline |
| A stream that ends without an end event | → same outline, row 2 |
| A failed asset download never truncates the existing mirror | → same outline, row 3 |
| An unauthenticated asset fetch is a credential failure | → same outline, row 4 |
| A pull interrupted halfway leaves every written file valid | → `connection/sync-now.feature` |
| A file that fails to export does not stop the rest of the pull | → `connection/sync-now.feature`, one outline with the row below |
| Losing access to a team halts only that mapping | → same outline: one failure at mapping scale rather than file scale |
| A failed project listing prunes nothing | → `designs/delete.feature`, row of one outline |
| A failed team listing prunes nothing anywhere under it | → same outline |
| An expired service token prunes nothing | → same outline |
| The pull does not trust "get-projects" alone | → `projects/delete.feature` — the behaviour is a project deleted in Penpot |
| A restore whose follow-up rename fails | → `designs/restore.feature` |
| A missing service token blocks mapping | → `team-mapping/create.feature` |

#### And what was dropped, with the reason

| scenario | why |
|---|---|
| Penpot error codes are decoded from Transit, not string-matched | "not string-matched" describes how the parser works. `tests/unit/TransitTest.php` |
| The known-bad export flag combination is never sent | asserts a REQUEST PAYLOAD, which Behat cannot see. `tests/unit/PenpotClientTest.php` |
| The inner signed storage URL is never persisted | an internal storage decision with no observable outcome at all |
| A transient download failure is retried before giving up | backoff is a mechanism, and its end state is identical to the outline's |
| A pruned file goes to the trash, never straight to deletion | duplicate — `designs/delete.feature` asserts it LIVE |
| A design deleted in Penpot can still be rescued inside the grace window | duplicate — the snapshot and the window closing are both already there |
| A failed rename leaves the local rename standing | duplicate — `designs/rename.feature` "A failed propagation never reverts the user's local rename" |
| An invalid personal token falls back rather than blocking | belongs with the WRITE GESTURE, which is where its twin went when `connection.feature` was rewritten |

#### THREE THINGS THIS FILE HID

**Its Background was fiction.** All three steps — `the app is connected to
Penpot`, `a Team Folder mapped to the Penpot team …`, `the Penpot project … is
mirrored as a folder inside it` — had never been written. The identical trio that
had rotted in `remove-mapping.feature`, invisible for the same reason: every
scenario in the file was tagged.

**A missing token blocking a mapping existed ONLY here.** "Without a
service-account token, nothing can be mapped" was dropped when `connection.feature`
was split into `admin`/`personal`, and this file was quietly the last copy. It is
now `team-mapping/create.feature`'s, where refusing a mapping belongs.

**Four `@blocked` named no capability**, which is the one thing the tag exists to
do.

#### A promotion that fails leaves the file as it was

FOUR SCENARIOS, ONE RULE. Each described a different way the export can break on
the wire and then asserted the same end state: the file is untouched. That is an
`Examples` table, not four scenarios — the `reason` column carries the only thing
that genuinely differs, which is what the admin is told.

`@blocked` — **no fault injection.** Every row needs a real Penpot to fail in a
specific way, and the harness can only ask it to succeed.

FILED UNDER PROMOTION because promotion was what triggered the first export.
⚠️ RETIRED WITH ITS FILE: promotion no longer exists, so the first and riskiest
export is now the first pull under a `sync` mapping. The four export-failure rows
below went with `set-mode.feature` and are asserted nowhere — they were `@blocked`
on fault injection the harness cannot do, and they remain a real gap rather than
a solved one.

#### An incomplete listing prunes nothing

THE MOST IMPORTANT RULE IN THE APP, and it was four scenarios saying it four
ways. Not knowing what Penpot holds is not evidence that anything was deleted —
an expired token, a failed team listing and a failed project listing all mean the
same thing, and all must mean "prune nothing".

These are NOT the empty negatives this suite rejects elsewhere. Something did
act: a sync ran, and a dangerous branch was available to it. The claim is that
the branch did not fire, which is an outcome.

`@todo` rather than `@blocked` because one row IS drivable today — a rejected
token needs no fault injection, only a bad token — and it happens to be the row
that matters most.

#### One failure never costs the rest of the sync

TWO SCALES, ONE RULE: one design failing must not cost the other designs, and one
team failing must not cost the other teams. They were two scenarios that shared
every line but the noun.

### mapping-membership — RETIRED

`features/mapping-membership.feature` is **gone**. The nearest-ancestor rule is
this app's most load-bearing decision and it is still true; it was never a
behaviour. A rule is only ever OBSERVED through a gesture — you move something
and it still belongs, you create something and it lands in Drafts — so every
honest scenario in the file was already a move or a create.

Which is exactly why six of them had been rewritten elsewhere, word for word,
without anyone noticing.

#### THE RULE, which now lives here instead of in a file

A file's project is **the nearest ancestor folder carrying a Penpot project id**,
found by walking up; its team is the nearest ancestor carrying a team id, however
far up that is. Nothing is cached on the file — a copy would go stale on the
first move, which is the whole point (§C6.7). Penpot is flat; Nextcloud need not
be (§6.29).

Two consequences worth stating once: a folder Penpot has no concept of is simply
walked past, and a file under a team but under no project is in that team's
Drafts — which is a state, not a folder (§6.35).

#### Six duplicates of scenarios that were already live

| it said | already asserted by |
|---|---|
| A file nested deeper inside a project folder still belongs to that project | `designs/move.feature` — same gesture, same `wip` subfolder, same assertion |
| Project folders can be grouped under ordinary Nextcloud folders | `projects/move.feature` — which even asserts *"the folder still resolves to the same team, found further up"* |
| A file with no project-id ancestor belongs to no mapping | `designs/create.feature` "A `.penpot` file created outside every mapping is an inert file" |
| A file at the mapped folder's root is in that team's Drafts | `designs/create.feature` |
| No folder is ever created to represent Drafts | `connection/sync-now.feature` — `there is no node at "<folder>/Drafts"`, in the tree table |
| A folder opted in by tag resolves exactly like a mirrored one | `projects/create.feature` "A folder opted in late brings the designs already inside it" |

#### Four with no `When` at all

`A file's project is the nearest ancestor folder carrying a project id` was the
file's own thesis restated as a test — and its third `Then` is already
`designs/view.feature`'s. `A project folder's team is the nearest ancestor
carrying a team id` and `A personal project folder has no team ancestor` are the
same shape.

`Two folders carrying the same project id is a reported conflict` had a second
problem: **nothing can produce that state.** `projects/copy.feature` refuses a
project-folder copy precisely to prevent it, so the scenario specified recovery
from a situation the app is built to make unreachable — there is no `Given` a
test could arrange. It also carried the file's only `But`, which is a real
Gherkin keyword and a pure synonym for `And`: keywords are ignored in step
matching, so it reads as contrast and asserts nothing. Contrast is what you write
when you are describing a situation rather than an outcome.

#### Three survived, two of them as Examples rows

| it said | where it went |
|---|---|
| The nearest project id wins when project folders are nested | a row on `projects/move.feature`'s "moved anywhere inside its team folder" — the destination is the only thing that differs |
| A file in any plain folder under a team is also in Drafts | a row on `designs/create.feature`'s Drafts scenario — the same rule at a different depth |
| Non-Penpot content inside a project folder is left alone | `connection/sync-now.feature`, as "A sync leaves content it does not manage alone" |

#### A project folder can be moved anywhere inside its team folder

ONE RULE, TWO DESTINATIONS. A project folder may be filed under a plain folder —
which Penpot has no concept of — or under another project folder, where the
nearest id wins and the outer project does not swallow the inner one. Same
gesture, same end state, so it is an `Examples` column rather than two scenarios.

#### A sync leaves content it does not manage alone

FROM THE RETIRED `mapping-membership.feature`. A `notes.txt` sitting in a project
folder is not the app's business, and a sync must not touch it — pruning keys on
metadata, never on a file extension or on where a file happens to sit.

Filed with the sync rather than with membership because the actor is a sync: the
question is what a run does to things it did not create.

IT ARRIVED BROKEN, in the way this suite documents and I still walked into. It
came from `mapping-membership.feature`, whose Background maps a shared `Penpot`
folder — and `sync-now.feature` deliberately maps NOTHING, so every scenario here
names its own folder and cannot inherit another's leftovers. Pasted across
unchanged, the `PUT` for `notes.txt` 404'd on a parent that did not exist. It now
maps `Untouched` itself, like everything else in this file.

`.github/instructions/gherkin.instructions.md` names this trap and names these
two files as the pair it happens between. Check the destination's Background
before moving a scenario into it.

### personal-projects — RETIRED

`features/personal-projects.feature` is **gone**. Personal projects are not a
feature: they are **the ordinary rules with a different mapping**. A design in a
personal project is created, viewed, moved, renamed, deleted and restored by
exactly the scenarios in `designs/`; a personal project folder behaves exactly as
`projects/` says. The file existed because "personal" felt like a category — the
same error that produced `errors`, `mapping-membership` and `file-type`.

Only two things are genuinely different, and both are end states of setting a
token, so both went to `connection/personal.feature`:

| it said | where it went |
|---|---|
| Setting a personal token maps the personal team to the home root | a `Then` on "A user enters a valid token" |
| Clearing the token removes the implicit mapping | a new scenario, "A user clears their token" |

#### The rest, and why none of it survived

| it said | why |
|---|---|
| One user's personal projects never appear in another user's home | a negative on the impossible. Nextcloud homes and per-user tokens make it so; nothing acts on the other user |
| Clearing a personal token stops personal pulls without deleting anything | the "nothing deleted" half is now one `And` on the clear scenario, where it is a post-state rather than a scenario of its own |
| The personal team itself gets no folder | see the correction below — it is the same fact as the mapping, stated backwards |
| A user's personal projects mount at their home root | the first sync of the personal mapping, which `connection/sync-now.feature`'s "A user syncs their own personal team" already owns |
| Personal projects are pulled with the user's own token, never the service account | implied by the above: the service account cannot see a personal team, so the projects appearing at all IS the proof |
| Without a personal token, no personal projects appear at all | the inverse of the mapping end state, and asserted by the clear scenario's "inert, as it was before the token" |
| A personal project folder resolves without a team ancestor | **not true** — see below |

#### TWO CORRECTIONS THIS FILE WAS CARRYING

**"The personal team itself gets no folder" was only half true.** The mapping's
folder is the user's home root. `/` is a folder — it is simply the one every user
sees as theirs — so the honest statement is that the personal team maps to `/`,
with the team's name and the folder's name never needing to agree because nobody
names either. "No folder is created" and "mapped to the home root" are the same
fact, and only the second one is useful.

**"Resolves without a team ancestor" claimed an exception that does not exist.**
The scenario called itself *"the explicit exception to saga §6.29's team
lookup"* — but the team ancestor of a personal project IS the personal team, sat
on the home root. `MembershipResolver` walks ancestors looking for markers and
has no special case for any of this, because it needs none: put the team id on
the home root and the ordinary rule resolves it. A spec that invents an exception
the code does not have is worse than a silent one, because the next person builds
the exception.

### team-mapping/set-mode — RETIRED (and `sync-mode` with it)

`features/team-mapping/set-mode.feature` is **gone**, and so is the
`occ penpot_sync:set-mode` command it specified. The whole per-file mode axis has
been removed from the app.

**THE SECTION THIS REPLACES CALLED ITS OWN SHOT.** It was headed *"WHOSE DECISION
IS THIS, AND WAS IT EVER ASKED FOR?"*, recorded that per-file mutable mode
*"diverged from the design without a decision"*, that *"nobody asked for per-file
switching — it arrived because the move guard needed an escape hatch to offer"*,
and named the exact price of undoing it: *"the lever goes, the move guard loses
the escape it offers, and every 'promote to sync first' refusal in move.feature
needs a different answer."* That is precisely what was paid.

THE RULE NOW, AND IT IS THE SIBLINGS' RULE: **the mapping alone decides the
mode.** It is an immutable field of the mapping, exactly like the folder name and
the Team Folder flag. A design's mode follows from the mapping it was mirrored
under, and changing it means removing the mapping and mapping the team again —
which re-mirrors the same designs, by the same ids, into the same folder.

Neither `nextcloud-grafana` nor `nextcloud-n8n` ever had a per-file lever. This
app growing one made "the mapping says link" quietly untrue, and gave a third
place for the same question to be answered differently.

WHAT WENT WHERE:

| it said | where it went |
|---|---|
| Promoting a mirrored design fetches a real ZIP from Penpot | the export is still proven live — a `sync` **mapping** pulls, and `move.feature` / `rename.feature` / `edit.feature` assert real ZIP bytes on disk |
| A promoted file is not re-exported by the next pull | the revision check it rested on is `edit.feature`'s subject, where an edit in Penpot is the action |
| Demoting throws the archive away and never contacts Penpot | deleted — the action does not exist |
| Demoting asks first, because it deletes the only local copy | deleted with the prompt, the `--force` flag and `SetModeTest` |
| A link refusal offers to promote the file to "sync" mode first | deleted — the refusal now names the rule and stops, like both siblings' |
| Promoting a link first makes the move work normally | deleted — there is no promoting |

`features/sync-mode.feature` had already been retired *into* `set-mode.feature`,
so its note is folded in here rather than left pointing at a file that no longer
exists. Its own diagnosis still stands and now reads as the earlier half of this
one: it was sixteen `@todo` scenarios restating live ones, its
*"files inherit their mapping's default mode"* scenario described a bulk mode flip
that has never existed, and two of its scenarios named the pull as the actor —
the same defect that retired `reconcile.feature` in the Grafana sibling.

HOW A SCENARIO GETS A REAL ARCHIVE NOW: it asks for a sync mapping —
`Given a Penpot team named "…" is mapped to the folder "…" in "sync" mode` — and
lets the pull export. The step resets the mappings first, so a scenario stating
it is doing exactly what a person would do: mapping the team the other way.

### team-import — RETIRED

`features/team-import.feature` is **gone**. "Importing a team" was mapping a team
and syncing it, stated differently — `team-mapping/create.feature` and
`connection/sync-now.feature` own both halves. What the file added beyond them
was a listing UI and three refusals, and none of the four survived contact:

| it said | why it went |
|---|---|
| A team already mapped is detected, not re-imported | a status label on a listing. The BEHAVIOUR — a team may be mapped once — is `team-mapping/create.feature`'s "A mapping may not reuse a team or a folder", live |
| Importing an unmapped team requires Team Folder rights | a permission gate on an operation users do not have. Mapping is admin-only, which is the premise of `team-mapping/` — there is no user-facing import to gate |
| A team the service account cannot see is not importable | a negative on the impossible: a team the service account cannot see cannot be mapped at all, which is `team-mapping/create.feature`'s precondition |
| The import surface explains that tagging a folder creates a project | no `When`. Its first half is `projects/view.feature`'s live "A plain folder inside a mapped folder is tolerated, not adopted"; its second asserts UI copy |

#### THE FORK THIS FILE GUARDED IS CLOSED, AND WAS CLOSED BY SHIPPING

The section used to say, at length, that "a new Penpot project from a tagged
Nextcloud folder" reopens §6.1's read-only lock, that the carve-out was **not
granted**, and that *"nothing here should be implemented against until a future
saga chapter ratifies it"*.

`projects/create.feature`'s "Tagging a folder `penpot` creates the project in
Penpot" is **live and green in CI**. The carve-out was taken. The prose warning
against it survived the decision by some months, which is its own lesson: a note
that describes the old world is worse than no note, because it will be believed.

#### WHAT IS STILL OPEN, AND STILL WORTH KNOWING

**Creating a Penpot design for a local file that never had one** — the
import-as-restore path — remains undecided, and `designs/restore.feature` rows 3
and 4 are where it bites. That is a narrower question than the one above:
creating an EMPTY project is cheap and reversible; importing an archive mints a
design with a new id, no history, and no way back to the original.

Three facts from the live `import-binfile` testing (saga §6.20) apply whenever it
is built, and are the reason `designs/restore.feature` needs a follow-up rename:

  - the call is SSE, not a plain request;
  - its params are kebab-case (`project-id`, never `projectId`);
  - its `name` parameter is IGNORED — an imported file takes the name from its
    archive manifest.

**The service account must already be on the team.** A user's personal token
showing them a team is not sufficient for it to be mappable (saga §6.18); the
service account needs its own `viewer` invite. That is `team-mapping/create.feature`'s
precondition now, not a property of an import screen.

### uninstall — RETIRED, folded into `lifecycle.feature`

`features/uninstall.feature` is **gone**. Enabling, disabling and removing an app
are three points on one lifecycle, and they were split across two files because
the removal grew an essay rather than because a reader needed two places to look.

THREE SCENARIOS IN, ONE OUT:

| it said | verdict |
|---|---|
| Removing the app reverts the custom mimetype registration | **kept**, as `lifecycle.feature`'s "Removing the app" — real work of ours, and the exact mirror of what "Enabling the app" now asserts |
| Disabling the app leaves the mirrored design files in place | **deleted** — the app does nothing on disable. There is no code to write and none to break; it asserted Nextcloud's behaviour, not this app's |
| Re-enabling and pulling reconciles the existing files without duplicates | **deleted** — `sync-now.feature` "A folder already named like a Penpot project is adopted, not duplicated" already asserts id-matched reconciliation. Disabling and re-enabling changes nothing about how a pull matches |

**The data-orphan promise is still true and still worth knowing** — it is just
not a scenario. The app never deletes a `.penpot` file, never clears its
Files-Metadata, never touches a Team Folder and never contacts Penpot on removal.
Every `sync` file is a real archive, so deleting one would be genuine data loss;
a `link` holds no bytes but its `penpot_id` is what makes a later reconnect free.
To wipe the Nextcloud side deliberately, an admin uses Purge (`purge.feature`).
That is a promise kept by writing no code, which is exactly why it reads as a
paragraph rather than as a `When`.


### Four more, from sections that are otherwise current

These sat inside live sections of `features/AGENTS.md` rather than in a retired
file's, so the sweep above would have missed them. Same rule: a scenario that no
longer exists is history.

#### From `designs/view`

#### RETIRED — three more, when this file was aligned with its siblings

Grafana's `dashboards/view.feature` is three scenarios; n8n's is the same three
plus two CLI listings this app has no command for (`occ` here maps teams, it
does not list designs). This file was five, and each of the three that went was
a fact already owned somewhere else:

| scenario | why it went |
|---|---|
| The row icon is the app's colour mark | pixels, and unreachable from HTTP — it had been `@blocked` since it was written. Its only observable half is that a mirror carries the app's own mimetype instead of `application/zip`, which the mimetype scenario asserts. The renderer fact it existed to record is kept below, where a note can hold it without pretending to be a test |
| A file carries the team its design belongs to, but never a project | `penpot_team_id` is a row of the DAV outline, so the positive half was already said. The rest was a NEGATIVE — a scenario proving a key the app deliberately does not write. Why it is not written is documented above; nobody performs it |
| What the app manages, only the app changes | the refusal is core's and not this app's: every key is registered EDIT_FORBIDDEN, so a PROPPATCH is turned away before any of our code runs. Grafana keeps the note and has no scenario for it, and this file now matches. n8n does keep one, in `workflows/edit.feature` — filed as an edit, which is what a PROPPATCH is |

THE RENDERER FACT, kept because it will otherwise be rediscovered the hard way:
Nextcloud serves mimetype icons out of `core/img/filetypes/` WITHOUT recolouring
them, so that file must carry its own fill or it renders invisible. That is the
opposite contract from the context-menu glyph, which core DOES recolour — which
is why the menu half lives in `open-with.feature`, beside the action that draws
it (saga §C6.1/§C6.7).

══ NEXTCLOUD'S TIMESTAMPS ARE PENPOT'S NOW ═══════════════════════════════

A mirror carries two sets of dates and they used to mean different things:

  Nextcloud's `mtime` / `creation_time`   when the app last wrote the node
  Penpot's `created-at` / `modified-at`   when the DESIGN was last changed

The first is now stamped FROM the second, so sorting a mapped folder by date
sorts by the designs rather than by sync activity (saga §C6.24).

THERE ARE NO SCENARIOS FOR IT HERE, DELIBERATELY. A modification time is not
a behaviour anyone performs — it is the shared RESULT of editing, moving,
copying and renaming, each of which is already owned by its own feature file.
A scenario asserting "the mtime moved" would be specifying Nextcloud, in the
wrong file, with an invented actor. So the assertions ride the behaviours that
cause them: a design changed in Penpot, and a mirror coming into existence —
both `sync-now.feature`.

This file keeps only what is genuinely about LOOKING at a mirror: which DAV
properties exist and who may write them.

THE CONSTRAINT THAT MADE IT SUBTLE (§C6.19) still holds and is now enforced
in `sync-now.feature`: a pull that changes nothing must move neither mtime
nor etag. `touch()` leaves a file's own etag alone but propagates a fresh one
to its PARENT FOLDER — which is what sync clients poll — so an unconditional
stamp would churn the folder on every tick. Every write is conditional.

A PROJECT FOLDER TAKES ITS CREATION TIME ONLY. Core propagates a folder's
mtime from its children, so stamping that would be a fight lost on every pull
that writes any design — and a propagated mtime is better information anyway
("something in this project changed"), since Penpot's project `modified-at`
only moves on a rename.

---

#### From `designs/rename`

#### A created design is attributed to the acting user when possible — WITHDRAWN

**The two scenarios this note describes were removed, and the rule was not.** They
were judged low quality and pulled to be redone properly rather than left standing
as a spec nobody would want to build to. Nothing points at this anchor now; it is
kept because the RULE is still wanted and the reasoning below is still the
argument for it.

What has to come back is a statement of authorship at creation — with a personal
token the design is the user's, without one it is the service account's, and in
the second case the user is TOLD. The shape below is the part worth keeping; the
scenarios that carried it are not.

Authorship is a durable property of a design rather than a line in its history,
which is why this matters more at creation than for any other write. With a
personal token the design is the user's; without one it is the service account's.

TWO SCENARIOS, NOT TWO EXAMPLES ROWS — the same call as `designs/restore.feature`,
for the same reason: the end states are not the same shape. Without a token the app
also TELLS the user who the design will be authored by, and a row cannot carry a
post-condition the other row does not have. Squeezing them into Examples meant
dropping that sentence, which is the half a user would actually notice.

The old file had three scenarios on this theme; the third was the same rule stated
for a personal project folder.

#### From `designs/rename`

#### RETIRED — the admin purge

`purge.feature` described an admin button that removed every `.penpot` file the app
had mirrored, across every mapping, on the promise that a later sync would bring
them back. Six scenarios, four of them about which files it spared and how to undo
it.

Removed for the reason it was removed from n8n and from grafana: it deleted a great
deal on a promise that only held for files that were faithful mirrors, and the ones
that were not are exactly the ones you would miss. It was never built here — every
scenario was @unbuilt or @blocked — so retiring it is a matter of deleting the spec.

Purge now means the same thing in all three apps: emptying the Nextcloud trash,
which finishes the delete the trash gesture started.

**AND THE BUTTON OUTLIVED THE SPEC BY TWO COURSES.** Retiring the scenarios left
`templates/sync_settings.php` still rendering a *disabled* "Purge Nextcloud files"
between the two working buttons, with a tooltip promising it was *"available once
the purge machine lands"*, two settings-hint paragraphs describing what it would
spare, and matching notes in `SyncSettings.php` and `js/sync-settings.js`. Nothing
was ever wired to it — no route, no controller action, no `occ` command — so this
was pure dead surface, and the only thing it did was tell every admin who read the
panel that a feature was coming which had already been cancelled.

The general rule, since this is the second time a *present-but-disabled* control has
gone stale here: the argument for shipping one is that the finished shape of the
section is visible early and enabling it later is deleting an attribute. That holds
exactly as long as somebody still intends to enable it. The sync button earned it and
went live; this one's feature was cancelled underneath it, and at that moment the
button stopped being a preview and became a lie. **When a feature is retired, the
retirement includes its UI.**

#### From `connection/sync-now`

#### RETIRED — six scenarios, and what happened to each

| scenario | why it went |
|---|---|
| A folder already named like a Penpot project is adopted | now a Background row and a result row |
| A sync leaves content it does not manage alone | same — `notes.txt` goes in and comes out |
| A sync that cannot finish says so, and says why | `<what is wrong>` was a whole clause in a placeholder, and a connection failure belongs to `connection/admin.feature` |
| One failure never costs the rest of the sync | `<one thing fails>` likewise, and neither sibling states it |
| A sync that dies halfway leaves every file whole | @blocked with no fault injection and no sibling equivalent |
| A second sync started while one is running does not queue another | a negative about a thing that must not happen, @blocked, and absent from both siblings |
| A user syncs their own personal team | parked with the rest of the per-user work, to be done across all three apps at once |
| Two Penpot projects in one team sharing a name | the collision rule now lives with the naming rule in `projects/create` |

The old Outline also varied the mapped FOLDER by actor — `All Mappings` for the admin,
`On Schedule` for the schedule — so the two rows never touched the same tree. That is a
fixture working around a collision, not an input the behaviour depends on.


### What `features/README.md` used to carry about the queue

The status-tag section of `features/README.md` had grown a round-by-round account of
how the queue was drained — which was the right information in the wrong file: it
describes what happened, not what a tag means, and its scenario counts were stale
within two rounds of being written. It is here now, unchanged.

##### Why the queue is one flat list, and how a scenario earns its status back

The reorganisation this file describes — the folder became the noun, the file
became the verb, `reconcile.feature` stopped being a feature — rewrote the spec
faster than the harness could follow. Nine scenarios were still running when it
landed, and the step vocabulary underneath them had moved. The choice was to
re-triage 116 scenarios inside the PR that did the rewriting, or to collapse them
to one flat queue and let each PR that implements a behaviour restore that
scenario's real status. **The second, because triage done from the spec alone is a
guess, and the honest answer for each one is found by trying to make it pass.**

**Chapter 3 Round 1 drew the first nine** — the ones green in CI the moment before
the collapse: the two admin-connection scenarios, enabling and disabling the app,
the three mapping-creation ones, changing a mapped folder's groups, and syncing
one mapping. They prove the HARNESS rather than any behaviour, which is what made
them the only sane place to break ground. Where the suite stands now:

| status | scenarios | |
|---|---|---|
| *(none)* — runs in CI | 61 | 123 executed, spread over eleven suite legs |
| `@todo` | 34 | the queue |
| `@blocked` | 8 | no browser, no app removal, no way to author a design |
| `@unbuilt` | 9 | the app disagrees with the spec; see below |
| `@decision` | 0 | |

**The `project` leg nearly doubled without a single test being written for it.**
§C6.38 reversed a rule — a project folder was pinned inside its team folder — and
scenarios moved straight from `@unbuilt` to green because the code caught up with
what they already said. That is the payoff of tagging honestly: the work queue told
the truth about what was owed, so the PR that paid it needed no re-triage.

It also cuts the other way in the same file. A fourth scenario was promoted, failed
in CI, and went back to `@unbuilt` naming a wall nobody had measured — a move into a
Team Folder crosses a storage boundary and fires no rename event at all. **That
round trip is the point**: promoting it was how the wall got found, and the tag now
records a fact rather than an assumption.

**The `design` leg then nearly doubled too, and the tests were the small half.**
`designs/move.feature` held thirteen scenarios and ran none; the round promoted two
and retagged six, and only ONE of the two was a test anyone could simply have
written. `Move a design between projects` was held off the run by a defect in the
**spec** — its shared `Then` demanded `content | an archive` of both Examples
blocks, and the second block moves a `link`, which holds zero bytes on purpose. The
row now reads `the mapping's body`, so both modes can answer the same claim.

So the queue shrank by eight and gained two passing scenarios. **That gap is the
finding, not an accounting error**: `@todo` means *the code exists and only the
test is missing*, and six of these were something else — four places the app
contradicts the spec, and two the harness cannot reach without a browser. A queue
that cannot be read at face value costs more than a short one.

**Every leg reports tests now**, so the empty-suite exemption in the workflow
no longer carries any of them. It stays, because it is self-healing in both
directions and the next spec-first feature file will empty a leg again.

**Round 1 also found two things the rewrite had dropped**, which is the argument
for running a scenario rather than reading it:

* `connection/admin.feature` lost `And nothing is configured yet` from its
  Background. Commit `466f92d` had added that line on purpose — *"a bad URL names
  the url field, and the Background starts blank"* — because the bad-URL row
  relies on it: `set-url` refuses a URL it cannot build requests from, so nothing
  is stored, and the health check must fail on a MISSING url rather than on the
  good one the row above left behind. Restored.
* `lifecycle.feature`'s "Removing the app" was flattened from `@blocked` to
  `@todo` along with everything else, even though the comment above it names its
  wall. Restored to `@blocked`; it is the tag's only member.

Seven were `@blocked` and one was `@decision`, and **that record is only half in
the files.** Where the reason was already written as a comment it survives the
collapse untouched, which is why those comments still open with the old tag name:

```gherkin
  # @blocked — no app removal. The harness can enable and disable, which is what
  # `occ` offers; removing an app and reinstalling it is a store operation this
  # suite has no way to perform.
  @blocked
  Scenario: Removing the app
```

**A comment naming a status the tag no longer carries is the record, not a
contradiction** — the tag was the temporary flat state, the comment is the truth,
and the one above has now been reconciled back. Two more read that way (both of
`designs/edit.feature`), and the two `# @unbuilt — THIS IS THE SPEC, AND THE APP
DOES THE OPPOSITE TODAY` notes in `designs/delete.feature` and
`designs/move.feature` are the same thing and matter more, because they mark
places where promoting the scenario means **fixing the app, not writing a test**.

The other four `@blocked` and the one `@decision` had no such comment, so their
status lived only in the tag and is now only in the saga's Chapter 2 close, which
names all eight. Do not re-derive them from the spec.

**The rule that goes with it:** a scenario stops being `@todo` only on a PR that
runs it. Promote it to live when it passes, or move it to its true status with the
reason written down — never by re-reading the spec and deciding what it probably
is. The build order is in the saga's Chapter 3.

---

## Chapter 3 — where it stands (CLOSED)

What this chapter built, what it corrected about itself, and what it leaves to
Chapter 4.

### The arithmetic the chapter opened with

**116 claims, zero witnessed.** That was the opening line, and it was the whole
problem: §C6.38 had redrawn the plan around behaviour, which moved the survey
marks out from under 46 sections that had been signed off. Every drawing came
back unstamped.

Eleven rounds later:

| | |
|---|---|
| **73 scenarios live** | witnessed, in CI, every push |
| 24 `@todo` | specified, not built, each naming what it wants |
| 9 `@unbuilt` | built wrong or half-built, each naming the code owed |
| 4 `@blocked` | real behaviour this harness cannot reach |
| 0 `@decision` | nothing is waiting on someone to make up their mind |

**110 scenario blocks, not 116**, and the difference is not attrition. Scenarios
merged as rules turned out to be one rule — three collapsed into one when the id
stopped deciding anything (Round 7) — and Round 11 retired seven feature files
whose contents had been folded elsewhere. The plan got smaller because it got
truer.

Those 73 blocks expand to **141 executed scenarios** per Nextcloud version, run
across **11 parallel legs** on **three Nextclouds — 32, 33 and 34** — so a green
push is 423 scenario runs against a real Nextcloud talking to a real Penpot.
Underneath them, **564 unit tests and 1,233 assertions**.

### What it produced

- **Both directions of sync, and the buttons that drive them.** The pull was
  Chapter 2's; this chapter built the push — *"Sync to Penpot"* — and the rule
  underneath it, which turned out not to be the one everyone had been quoting.
  §6.1 never said *read-only*; it said **never overwrite a design Penpot already
  has**, and an archive Penpot has never seen is not that (Round 8).
- **The design verbs, complete.** Create, rename, move, copy, delete, restore,
  purge — in both storage kinds, in both mapping modes, at any depth, including
  the gestures that leave a mapping and the gestures that come back.
- **The project verbs beside them**, and the rule that makes them possible: a
  project's name is its **path** below the mapping (§C6.38), so a folder move is
  a rename of every project spelled through it.
- **Cross-team moves**, which needed nothing from Penpot and everything from
  Nextcloud (Round 9).
- **A folder is a project when a design is in it** — and, after Round 10, that
  means *every* folder, with no edge.
- **A documentation cascade** (Round 11): the feature files specify, `AGENTS.md`
  explains the present tense, the saga holds the history. One hop per level, each
  pointing at the next.

### The bugs that only building found

This is the chapter's thesis, so it gets the list. Not one of these was
discoverable by reading the code that contained it.

- **A freshly mapped folder was never stamped with its team.** `PullService` was
  the only writer of `penpot_team_id` on a root, so a mapping made and used
  before the first pull resolved to nothing: the push did nothing and `MoveRules`
  403'd every design created there. Found by building the push (Round 8).
- **A mapped folder shared with groups was invisible to every member.**
  Nextcloud creates every group share `PENDING`, and a pending group share does
  not mount — with no prompt to click, because group shares raise none. Correct
  node, correct permissions, correct groups, seen by nobody. **This had been
  shipping**, and no test in eleven legs could have caught it: the suite asserts
  as the folder's owner, and an owner sees their own folder whatever the
  recipients' shares say (Round 9).
- **A cross-storage move destroyed a design's identity, and the file id survived
  it.** Everyone's assumption — including this repository's own docblocks — was
  copy-and-delete with a new id and orphaned metadata. The truth is the opposite:
  the id is preserved and the metadata is deliberately dropped, because removing
  the source cache entries raises `CacheEntriesRemovedEvent` and core's own
  listener deletes the rows. Four CI rounds established *that* it broke; two
  minutes against a live instance, with a same-storage rename as the control,
  established *what* broke it (Round 9).
- **A folder inside a project folder became nothing.** Reported from the live
  colony: `Bubbles/pustice` held a design and Penpot never heard about it. The
  app was correct and the *rule* was the defect — two folders a user cannot tell
  apart behaved differently, decided by the accident of which one received a
  design first (Round 10).
- **A personal project stopped receiving writes.** Introduced by that fix and
  caught in review: collapsing *no team* into *outside every mapping* meant every
  design arriving in someone's personal project silently stopped reaching Penpot,
  with a usable project id sitting right there in the membership. **564 unit
  tests and eleven integration legs did not notice.**
- **`check-notes-anchors.sh` had the bug it was written to prevent.** Its slug
  rule did not match GitHub's, so three breadcrumbs passed CI and 404ed for
  anyone who clicked them. Nobody clicks a breadcrumb in CI, which is the entire
  reason the script exists (Round 11).

### What it corrected about itself

The method lessons, in the order they were paid for.

- **When a fix does not move the number, suspect a second fault before concluding
  the first was wrong.** Eleven CI runs went into one test because two independent
  faults produced one symptom, and each correct fix looked wrong alone. One was
  reverted for that reason (Round 8).
- **Measure the state; do not reason from the symptom.** Printing Penpot's actual
  contents at each step boundary named a deleting call between two adjacent lines
  that five arguments had missed. The larger version of the same lesson:
  **the running instance is a primary source, and it is cheaper than the argument
  about it** (Rounds 8–9).
- **A retired rule with live code behind it is not dead code. It is a second
  answer, and it will get proposed.** The `penpot`-tag opt-in was retired in
  Chapter 2 and kept its listener, its service method, its unit tests and a README
  paragraph — and was duly offered as a solution to a problem the rule that
  replaced it already owned. The right time to delete a mechanism is the round
  that replaces it (Round 10).
- **A fixture that cannot fail the way production fails will certify the bug.**
  Twice: a mocked source node that answered `getId()` when the real one throws,
  and file fixtures with no parent folder because the old resolver never asked for
  one. Both passed while production broke (Rounds 9–10).
- **A guard is code, and gets the same class of bug as the thing it guards**
  (Round 11).
- **A negative assertion can go green because the thing it denies stopped
  existing.** Worth remembering whenever a rule changes underneath a suite
  (Round 6).
- **Every mutate-then-read assertion polls.** The trash flake was never flaky
  behaviour; it was an assertion looking once at a second storage that took
  longer to settle.
- **A design name is a scenario's own.** Penpot's trash accumulates across a whole
  feature file and nothing empties it, so any assertion phrased by name is really
  an assertion about the entire run.

### What it leaves

Named, not hidden.

- **The 24 `@todo`** are mostly two families: the Penpot→Nextcloud project verbs
  (create, rename, move, purge, restore, seen from the far side) and the
  *"while Penpot is unreachable"* failure paths. Neither is load-bearing; both are
  specified and waiting on a round that runs them.
- **The 9 `@unbuilt`** each name the code they want. Three of them —
  `projects/copy`'s set — are the same missing capability: **noticing a folder
  that has arrived inside a mapping**, which core reports as one event for the
  folder and nothing per child.
- **The 4 `@blocked`** are harness walls, not app walls: no browser, no app
  removal, no way to author a design's contents.
- **The tag gesture's code.** `ArrangeSteps::ensureProjectFolder()` tags a folder
  to arrange every `kind: project` row — 27 of them, 10 needing a project folder
  that holds no design — so removing it is a harness change first and a deletion
  second. Dated and queued rather than left to be rediscovered.
- **Push and pull can still run concurrently**, recorded in `SyncController`'s
  docblock as a known gap rather than fixed on a green PR.
- **A cross-storage move of a FOLDER** is still unhandled, and still for the
  original reason: neither half of core routes it.

### The state of the colony

It is deployed. Not in a test rig — on the author's own Nextcloud, mapped to two
Penpot teams across two storage kinds, holding real designs that real people
opened this week. Three of the bugs above were found there, by using it.

That is the whole argument of this chapter, and it took eleven rounds to earn the
right to say it plainly: **a drawing is not a building, and a building is not
finished until somebody lives in it.**

Chapter 2 handed over a master design with nothing built to it. Chapter 3 hands
over a colony that works, a queue that is honest about itself, and a method that
has now been wrong often enough, in public, to be trusted.

The doors open in Chapter 4.
