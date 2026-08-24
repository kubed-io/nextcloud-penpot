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

## Where we are — 2026-08-23 · **THE SPINE IS IN, AND A RULE HAS REVERSED**

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
| live | headers → executed | **15** → **41** | **23** → **54** |
| `@todo` | the queue | 87 | 76 |
| `@blocked` | no browser (6), no app removal (1), no way to author a design (2) | 9 | 9 |
| `@unbuilt` | each names what the code owes | 5 | 8 |

The legs now stand at `admin` 25, `design` 10, `project` 17, `core` 2.

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

**The harness and the rule turned out to agree already.** Refusing deletes inside a
link mapping looked like it would break `ArrangeSteps::emptyMappedFolder()`, which
clears mapped folders between scenarios. It does not: the arrange unmaps every
mapping BEFORE it clears, and its own comment says why — *"while a mapping is live,
deleting inside it is a gesture that reaches Penpot."* The same reasoning that
makes `isLinkTeam()` return false for a torn-down mapping.

### The one harness wall left

`projects/rename`'s Penpot-side outline is `@todo` for a reason that is neither
spec nor app: **Penpot state accumulates across a leg.** Teams are find-or-create
by name and survive the scenario, so a second scenario renaming `Old` → `New`
finds a `New` already there. Real isolation needs either a unique team per scenario
(which breaks every scenario that names a team) or a teardown that deletes what a
scenario created — and Round 4 removed the excuse for the second: `delete-project`
takes `{id}`, read off `schema:delete-project`. Whether a teardown SHOULD delete
from Penpot is still a question worth asking before it is built.
