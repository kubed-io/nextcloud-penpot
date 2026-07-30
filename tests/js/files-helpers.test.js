/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for the pure Files-integration helpers — the app's first JS tests.
 * The JS analog of the PHP service tests: dependency-free logic, fast, and the
 * regression net that makes a Vite major bump safe to land.
 *
 * The deep-link assertions are the load-bearing ones, and they exist because the
 * first cut of buildUrl SHIPPED BROKEN (§C6.7): it emitted `?file-id=` alone and
 * Penpot answered with an internal error. The route table had been read
 * correctly; the required PARAMS were inferred from in-app call sites that
 * already had a team-id in the URL. Every assertion below that looks
 * over-specified is holding that door shut.
 */
import { describe, it, expect } from 'vitest'
import {
  PENPOT_MIME,
  readMetadata,
  getPenpotId,
  getPenpotTeamId,
  getPenpotMode,
  buildUrl,
  isPenpotFile,
} from '../../src/files-helpers.js'

const ID = '61d8ecb9-c430-8120-8008-6225c5b12134'
const TEAM = '4eda2e11-843e-8045-8008-51824bda07a1'

describe('readMetadata', () => {
  it('reads the plain metadata-<key> attribute', () => {
    expect(readMetadata({ attributes: { 'metadata-penpot_id': ID } }, 'penpot_id')).toBe(ID)
  })

  it('falls back to the bare key', () => {
    expect(readMetadata({ attributes: { penpot_id: ID } }, 'penpot_id')).toBe(ID)
  })

  it('falls back to the fully-qualified DAV attribute name', () => {
    expect(readMetadata({ attributes: { '{http://nextcloud.org/ns}metadata-penpot_id': ID } }, 'penpot_id')).toBe(ID)
  })

  it('returns empty string when the attribute is absent', () => {
    expect(readMetadata({ attributes: {} }, 'penpot_id')).toBe('')
  })

  it('is null/undefined safe (no node, no attributes)', () => {
    expect(readMetadata(undefined, 'penpot_id')).toBe('')
    expect(readMetadata(null, 'penpot_id')).toBe('')
    expect(readMetadata({}, 'penpot_id')).toBe('')
  })

  it('ignores a non-string value', () => {
    expect(readMetadata({ attributes: { 'metadata-penpot_id': 12345 } }, 'penpot_id')).toBe('')
  })

  it('does not cross-read a different key', () => {
    expect(readMetadata({ attributes: { 'metadata-penpot_mode': 'sync' } }, 'penpot_id')).toBe('')
  })
})

describe('getPenpotId', () => {
  it('reads the id off the listing attributes', () => {
    expect(getPenpotId({ attributes: { 'metadata-penpot_id': ID } })).toBe(ID)
  })

  it('returns empty string for an untracked file', () => {
    expect(getPenpotId({ attributes: {} })).toBe('')
  })
})

describe('getPenpotMode', () => {
  it('reads sync as-is', () => {
    expect(getPenpotMode({ attributes: { 'metadata-penpot_mode': 'sync' } })).toBe('sync')
  })

  // The wire value is `reference`, never `link`: the literal string `link` is
  // is_callable() in PHP and detonates core's PROPFIND handler.
  it('translates the stored wire value `reference` back to `link`', () => {
    expect(getPenpotMode({ attributes: { 'metadata-penpot_mode': 'reference' } })).toBe('link')
  })

  it('never reports the raw wire value to callers', () => {
    expect(getPenpotMode({ attributes: { 'metadata-penpot_mode': 'reference' } })).not.toBe('reference')
  })

  it('reads unmapped as-is', () => {
    expect(getPenpotMode({ attributes: { 'metadata-penpot_mode': 'unmapped' } })).toBe('unmapped')
  })

  it('returns empty string when absent (the first-load PROPFIND race)', () => {
    expect(getPenpotMode({ attributes: {} })).toBe('')
  })
})

describe('buildUrl', () => {
  // THE REGRESSION THIS FILE EXISTS FOR. The first cut emitted
  // `?file-id=<id>` alone, having read Penpot's in-app `go-to-workspace` call
  // sites — which pass file-id alone because team-id is ALREADY in the URL and
  // gets carried. A cold load from outside carries nothing, and Penpot answered
  // with an internal error. Penpot's own legacy-route redirect settles it: it
  // calls `get-project` purely to look the team up before navigating.
  it('includes BOTH team-id and file-id', () => {
    expect(buildUrl('https://penpot.example.com', TEAM, ID))
      .toBe(`https://penpot.example.com/#/workspace?team-id=${TEAM}&file-id=${ID}`)
  })

  it('never emits a file-id without a team-id — the shape that errored', () => {
    const url = buildUrl('https://penpot.example.com', TEAM, ID)
    expect(url).toContain('team-id=')
    expect(url.indexOf('team-id=')).toBeLessThan(url.indexOf('file-id='))
  })

  it('returns empty string when the team id is missing, rather than a broken link', () => {
    expect(buildUrl('https://penpot.example.com', '', ID)).toBe('')
  })

  it('keeps the hash, because Penpot routes client-side', () => {
    expect(buildUrl('https://penpot.example.com', TEAM, ID)).toContain('/#/workspace?')
  })

  it('uses the query form, not the legacy /workspace/<project>/<file> path', () => {
    expect(buildUrl('https://penpot.example.com', TEAM, ID)).not.toMatch(/\/workspace\/[^?]/)
  })

  it('url-encodes both ids', () => {
    expect(buildUrl('https://penpot.example.com', 'a b', 'c/d'))
      .toBe('https://penpot.example.com/#/workspace?team-id=a%20b&file-id=c%2Fd')
  })

  it('returns empty string when the base url is missing (unconfigured instance)', () => {
    expect(buildUrl('', TEAM, ID)).toBe('')
  })

  it('returns empty string when the file id is missing (untracked file)', () => {
    expect(buildUrl('https://penpot.example.com', TEAM, '')).toBe('')
  })
})

describe('getPenpotTeamId', () => {
  it('reads the team id off the listing attributes', () => {
    expect(getPenpotTeamId({ attributes: { 'metadata-penpot_team_id': TEAM } })).toBe(TEAM)
  })

  it('does not confuse the team id with the file id', () => {
    const node = { attributes: { 'metadata-penpot_id': ID, 'metadata-penpot_team_id': TEAM } }
    expect(getPenpotTeamId(node)).toBe(TEAM)
    expect(getPenpotId(node)).toBe(ID)
  })

  // A mirror pulled before the team id was stamped. The link cannot be built, so
  // the click must no-op rather than open a workspace with a missing team.
  it('returns empty string for a file stamped before the team id existed', () => {
    expect(getPenpotTeamId({ attributes: { 'metadata-penpot_id': ID } })).toBe('')
    expect(buildUrl('https://penpot.example.com', getPenpotTeamId({ attributes: {} }), ID)).toBe('')
  })
})

describe('isPenpotFile', () => {
  it('matches a single node carrying the custom mimetype', () => {
    expect(isPenpotFile({ nodes: [{ mime: PENPOT_MIME }] })).toBe(true)
  })

  // Not redundant with the mime arm: a file written before the mimetype repair
  // step ran still ends in .penpot and is still ours.
  it('matches on the .penpot basename when the mimetype has not been stamped', () => {
    expect(isPenpotFile({ nodes: [{ mime: 'application/zip', basename: 'Homepage.penpot' }] })).toBe(true)
  })

  it('does not match a plain zip with no .penpot extension', () => {
    expect(isPenpotFile({ nodes: [{ mime: 'application/zip', basename: 'designs.zip' }] })).toBe(false)
  })

  it('does not match a file that merely contains .penpot mid-name', () => {
    expect(isPenpotFile({ nodes: [{ mime: 'application/zip', basename: 'my.penpot.bak' }] })).toBe(false)
  })

  it('does not match a multi-node selection', () => {
    expect(isPenpotFile({ nodes: [{ mime: PENPOT_MIME }, { mime: PENPOT_MIME }] })).toBe(false)
  })

  it('is null/undefined safe', () => {
    expect(isPenpotFile()).toBe(false)
    expect(isPenpotFile({})).toBe(false)
    expect(isPenpotFile({ nodes: [] })).toBe(false)
  })
})
