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
features = sorted((root / 'features').glob('*.feature'))

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

compiled = [re.compile('^' + p + '$') for p in patterns]

# ── the steps the suite actually runs ──────────────────────────────────────────
step_re = re.compile(r'^\s*(?:Given|When|Then|And|But)\s+(.*?)\s*$')
undefined = []
for feature in features:
    tags, in_scenario, runs = set(), False, False
    background_gaps, any_runs = [], False
    for raw in feature.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if line.startswith('@'):
            tags |= set(line.split())
            continue
        if line.startswith(('Scenario:', 'Scenario Outline:')):
            in_scenario, runs = True, not (tags & UNRUN)
            any_runs = any_runs or runs
            tags = set()
            continue
        if line.startswith(('Feature:', 'Background:')):
            # A BACKGROUND IS ONLY REQUIRED IF SOMETHING IN ITS FILE RUNS. It runs
            # once per scenario, so in a file that is entirely specification it never
            # runs at all — and demanding its steps be implemented would report 24
            # false failures on a suite CI is happily green on. Held aside and
            # judged after the file is read.
            in_scenario, runs = True, None
            tags = set()
            continue
        if line.startswith(('Examples:', '#')) or not line:
            continue
        if not in_scenario or runs is False:
            continue
        m = step_re.match(line)
        if not m:
            continue
        text = m.group(1)
        if '<' in text:  # a Scenario Outline placeholder; resolved per example row
            continue
        if not any(c.match(text) for c in compiled):
            (background_gaps if runs is None else undefined).append(f'{feature.name}: {text}')
    if any_runs:
        undefined.extend(background_gaps)

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
