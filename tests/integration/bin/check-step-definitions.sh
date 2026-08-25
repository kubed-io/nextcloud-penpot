#!/usr/bin/env bash
#
# Fast structural checks on the Behat step definitions, so mistakes that cost a FULL
# CI CYCLE each are caught in a second instead.
#
# Ported from nextcloud-grafana and adapted, because the two suites write their
# patterns differently. Grafana uses bare step TEXT, so its guard bans parentheses —
# Behat reads `(...)` as an optional group. Penpot uses REGEX-DELIMITED patterns
# (`@Given /^"([^"]*)" is in the trash$/`), where parentheses are the capture groups
# every step depends on. Porting that rule verbatim would flag every step in the repo.
#
# What does port, and what replaces it:
#
#   1. DUPLICATE STEP PATTERN. Behat ignores the KEYWORD when matching, so the same
#      pattern under @Given and @When is one step registered twice. Behat refuses the
#      second and then fails EVERY scenario in the suite — including ones that never
#      mention it — reporting "already defined" against whatever ran first. It reads as
#      "the app is broken". One function may carry several phrasings (that is how a
#      gesture gets its past-tense pre-state form); it may never carry one twice.
#
#   2. UNDEFINED STEPS. A step renamed in a .feature file without its definition
#      becomes an undefined step, and `--strict` turns that into a failure minutes into
#      a run that first booted Nextcloud, Postgres, Valkey and two Penpot containers.
#      This answers the same question with no services at all.
#
#      SCOPED TO WHAT CI ACTUALLY RUNS. @unbuilt, @blocked, @decision and @todo mark
#      specification that deliberately has no implementation — they are the point of
#      the spec-first style, not a defect. The tag list here MUST track the one the
#      integration workflow filters on; check-suites.sh already pins that expression.
#
# Runs in the PHP Quality job, which finishes in seconds. The integration matrix takes
# minutes across seven legs and needs a live Nextcloud and Penpot to say the same thing.

set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

python3 - "$root" <<'PY'
import re, sys, pathlib

root = pathlib.Path(sys.argv[1])
bootstrap = root / 'tests' / 'integration' / 'bootstrap'
features = sorted((root / 'features').rglob('*.feature'))

# Scenarios carrying any of these are specification, not implementation.
UNRUN = {'@todo', '@unbuilt', '@blocked', '@decision'}

fail = False

# ── the definitions ────────────────────────────────────────────────────────────
# TWO STYLES, AND THE GUARD MUST KNOW BOTH. This suite writes most steps as
# regex (`@Given /^the file "([^"]*)" ...$/`) and a sizeable minority as plain
# text (`@Given the app is enabled`). Behat accepts either; a guard that knows
# only one reports every step of the other kind as undefined, which is a
# spectacularly unhelpful way to fail.
regex_re = re.compile(r'@(?:Given|When|Then)\s+/\^(.+?)\$/')
plain_re = re.compile(r'@(?:Given|When|Then)\s+(?!/)(\S.*?)\s*$')
patterns, seen = [], {}
for php in bootstrap.rglob('*.php'):
    for line in php.read_text(encoding='utf-8').splitlines():
        m = regex_re.search(line)
        if m:
            body = m.group(1)
        else:
            m = plain_re.search(line)
            if not m:
                continue
            # Plain text is literal, except Behat's `:name` placeholders — and a
            # one-line docblock closes on the same line, so `*/` is not step text.
            text = re.sub(r'\s*\*/\s*$', '', m.group(1))
            body = re.sub(r':\w+', '(.+)', re.escape(text).replace('\\:', ':'))
            # `file(s)` IS an optional group to Behat, so compile it as one.
            # Escaped literally instead, a step declared `... file(s)` matches
            # only text carrying the parentheses — so every singular use of it in
            # a .feature file reads as undefined. Found on nextcloud-grafana,
            # where it reported reconcile.feature's live `holds exactly 1
            # dashboard file` as undefined and --strict proved otherwise.
            #
            # This suite writes no optional plurals today, which is precisely why
            # it is worth fixing now: the failure is silent in the OTHER
            # direction too. A pattern this mangles never matches anything, so it
            # can also make a genuinely undefined step look defined.
            body = body.replace(r'\(s\)', 's?')
        patterns.append(body)
        seen.setdefault(body, []).append(php.name)

dupes = {p: f for p, f in seen.items() if len(f) > 1}
if dupes:
    fail = True
    print('✘ DUPLICATE STEP PATTERN — Behat ignores the keyword, so these register twice')
    print('  and fail the WHOLE suite, including scenarios that never mention them:')
    for p, files in sorted(dupes.items()):
        print(f'    /^{p}$/  ({", ".join(files)})')
    print('  One function may carry several phrasings; never the same phrasing twice.')

# ── the collision Behat never gets to report ──────────────────────────────────
#
# The check above is about STEP TEXT. This one is about PHP, and it is a harder
# failure: every *Steps trait is composed into the one FeatureContext, and two
# traits cannot contribute the same METHOD NAME to a class. PHP fatals on the
# collision while loading the context — before Behat resolves a single step — so
# all four legs die at once with no JUnit written and the run reports as "matched
# no scenarios" rather than as a conflict.
#
# It cost a full CI cycle to find, on a cursor-form step named for its sentence
# (`someone deletes the design in Penpot`) whose obvious method name was already
# taken by the path form one trait over. The text check could not see it: the two
# phrasings are genuinely different, which is the whole point of having both.
method_re = re.compile(r'^\s*(?:public|private|protected)\s+function\s+(\w+)\s*\(')
methods = {}
for php in sorted(bootstrap.rglob('*.php')):
    if php.name == 'FeatureContext.php':
        continue
    for line in php.read_text(encoding='utf-8').splitlines():
        m = method_re.match(line)
        if m:
            methods.setdefault(m.group(1), []).append(php.name)

clashes = {n: sorted(set(f)) for n, f in methods.items() if len(set(f)) > 1}
if clashes:
    fail = True
    print('✘ DUPLICATE METHOD NAME across two traits — PHP fatals when FeatureContext')
    print('  composes them, before Behat runs anything, and EVERY suite dies at once:')
    for n, files in sorted(clashes.items()):
        print(f'    {n}()  ({", ".join(files)})')
    print('  Rename one. A cursor-form step is usually the one to rename — name it')
    print('  for the cursor (…TheCursoredDesign…) rather than for its sentence.')

compiled = [re.compile('^' + p + '$') for p in patterns]

# ── the steps the suite actually runs ──────────────────────────────────────────
# TWO PHASES, BECAUSE `Examples:` COMES AFTER THE STEPS IT FILLS IN.
#
# This used to be one line-by-line pass, and it skipped any step containing a `<`
# with the comment "resolved per example row" — which nothing ever did. So every
# undefined step inside a Scenario Outline was invisible here, and the guard whose
# entire job is "do not let --strict find this minutes into a live run" reported
# green while three undefined steps sat in a promoted outline. CI found them, which
# is the expensive way round and exactly what this file exists to avoid.
#
# So: parse each feature into scenarios first, then resolve every step against
# every example row. A step with no placeholder is checked once, which is the same
# answer the old pass gave for the cases it could see.
step_re = re.compile(r'^\s*(?:Given|When|Then|And|But)\s+(.*?)\s*$')
row_re = re.compile(r'^\s*\|(.*)\|\s*$')


def cells(line):
    m = row_re.match(line)
    return [c.strip() for c in m.group(1).split('|')] if m else None


undefined = []
for feature in features:
    lines = feature.read_text(encoding='utf-8').splitlines()
    feature_tags, pending = set(), set()
    background, scenarios = [], []
    cur = None          # the scenario being read, or None
    mode = None         # 'background' | 'scenario'

    for raw in lines:
        line = raw.strip()

        if line.startswith('@'):
            pending |= set(line.split())
            continue
        if line.startswith('Feature:'):
            feature_tags, pending, mode = set(pending), set(), None
            continue
        if line.startswith('Background:'):
            mode, background, pending = 'background', [], set()
            continue
        if line.startswith(('Scenario:', 'Scenario Outline:')):
            cur = {'runs': not ((pending | feature_tags) & UNRUN), 'steps': [], 'rows': []}
            scenarios.append(cur)
            mode, pending = 'scenario', set()
            continue
        if line.startswith('Examples'):
            # Rows belong to the scenario above; the header names the placeholders.
            if cur is not None:
                cur['rows'].append([])
            continue

        row = cells(line)
        if row is not None:
            # A table under an Examples heading is example data; one under a step is
            # that step's own argument and has no placeholders to bind.
            if cur is not None and cur['rows'] and mode == 'scenario':
                cur['rows'][-1].append(row)
            continue

        if line.startswith('#') or not line:
            continue

        m = step_re.match(line)
        if not m:
            continue
        if mode == 'background':
            background.append(m.group(1))
        elif mode == 'scenario' and cur is not None:
            cur['steps'].append(m.group(1))

    # A BACKGROUND IS ONLY REQUIRED IF SOMETHING IN ITS FILE RUNS. It runs once per
    # scenario, so in a file that is entirely specification it never runs at all —
    # demanding its steps be implemented would report false failures against a suite
    # CI is happily green on.
    live = [s for s in scenarios if s['runs']]
    for scenario in live:
        steps = background + scenario['steps']
        bindings = []
        for block in scenario['rows']:
            if len(block) < 2:
                continue
            header = block[0]
            for row in block[1:]:
                if len(row) == len(header):
                    bindings.append(dict(zip(header, row)))

        for step in steps:
            for resolved in ([step] if not bindings else [
                re.sub(r'<([^>]*)>', lambda mm: b.get(mm.group(1), mm.group(0)), step)
                for b in bindings
            ]):
                if '<' in resolved:
                    # A placeholder no Examples column fills. That is a broken
                    # outline rather than a missing definition, and it is the
                    # feature file's bug — say so instead of guessing a pattern.
                    undefined.append(f'{feature.name}: {resolved}  (no Examples column fills this)')
                    continue
                if not any(c.match(resolved) for c in compiled):
                    entry = f'{feature.name}: {resolved}'
                    if entry not in undefined:
                        undefined.append(entry)

if undefined:
    fail = True
    print('✘ UNDEFINED STEPS in scenarios the matrix runs — --strict fails on these,')
    print('  minutes into a run that first booted Nextcloud and two Penpot containers:')
    for u in undefined:
        print(f'    {u}')
    print('  Either add the definition, or tag the scenario as specification.')

if not fail:
    print(f'✓ step definitions: {len(patterns)} patterns, no duplicates, '
          f'every runnable step defined across {len(features)} feature files')
sys.exit(1 if fail else 0)
PY
