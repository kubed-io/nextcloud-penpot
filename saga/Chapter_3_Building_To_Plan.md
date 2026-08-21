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

## Where we are — 2026-08-21 · **THE SPINE IS IN, AND FOUR LEGS ARE GREEN**

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

| | |
|---|---|
| live | **15** headers → **41** executed (`admin` 25, `design` 7, `project` 7, `core` 2) |
| `@todo` | 87 |
| `@blocked` | 9 — no browser (6), no app removal (1), no way to author a design (2) |
| `@unbuilt` | 5 — each names what the code owes |

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

### The five `@unbuilt`, which are Round 3's queue

- `projects/create` ×2 — a design in a folder Penpot has never seen lands in
  Drafts; only a tagged folder becomes a project.
- `projects/move` — a move high in the tree renames only what moved, not the
  projects named THROUGH it.
- `projects/move` — a project folder cannot leave its team. **The spec disagrees
  with itself here**: `features/README.md`'s two-noun table says a project is
  "pinned inside its team folder", which is the app's behaviour, while the scenario
  expects the move to unmap it. That needs a decision before it needs code.
- `designs/rename` — renaming a link is allowed; `MoveRules` permits any move
  inside the link's own project and a rename is one.

### The one harness wall left

`projects/rename`'s Penpot-side outline is `@todo` for a reason that is neither
spec nor app: **Penpot state accumulates across a leg.** Teams are find-or-create
by name and survive the scenario, so a second scenario renaming `Old` → `New`
finds a `New` already there. Real isolation needs a `delete-project` RPC (absent
from `PenpotClient`, and its payload would be a guess) or a unique team per
scenario (which breaks every scenario that names a team).
