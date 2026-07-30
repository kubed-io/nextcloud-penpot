/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for the pure Files-integration helpers — the app's first JS tests.
 * The JS analog of the PHP service tests: dependency-free logic, fast, and the
 * regression net that makes a Vite major bump safe to land.
 *
 * The deep-link assertions are the load-bearing ones. `buildUrl` encodes a route
 * shape read off a live Penpot's own route table (saga §C6.1); if someone
 * "tidies" it into the legacy `/workspace/<project>/<file>` path form, every
 * unmapped mirror silently loses its link. These tests are what that person
 * trips over.
 */
import { describe, it, expect } from 'vitest'
import {
  PENPOT_MIME,
  readMetadata,
  getPenpotId,
  getPenpotMode,
  buildUrl,
  isPenpotFile,
  canOpenInPenpot,
} from '../../src/files-helpers.js'

const ID = '61d8ecb9-c430-8120-8008-6225c5b12134'

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
  // The route confirmed live against Penpot 2.17.0's own route table: the
  // current :workspace route is the bare path `/workspace`, so every param
  // reitit is handed rides the query string.
  it('builds the workspace deep link from base url + file id', () => {
    expect(buildUrl('https://penpot.example.com', ID))
      .toBe(`https://penpot.example.com/#/workspace?file-id=${ID}`)
  })

  it('keys on file-id alone — no project id, which an unmapped mirror lacks', () => {
    const url = buildUrl('https://penpot.example.com', ID)
    expect(url).toContain('?file-id=')
    expect(url).not.toContain('project-id')
  })

  it('uses the query form, not the legacy /workspace/<project>/<file> path', () => {
    expect(buildUrl('https://penpot.example.com', ID)).not.toMatch(/\/workspace\/[^?]/)
  })

  it('keeps the hash, because Penpot routes client-side', () => {
    expect(buildUrl('https://penpot.example.com', ID)).toContain('/#/workspace')
  })

  it('url-encodes the id', () => {
    expect(buildUrl('https://penpot.example.com', 'a b/c'))
      .toBe('https://penpot.example.com/#/workspace?file-id=a%20b%2Fc')
  })

  it('returns empty string when the base url is missing (unconfigured instance)', () => {
    expect(buildUrl('', ID)).toBe('')
  })

  it('returns empty string when the id is missing (untracked file)', () => {
    expect(buildUrl('https://penpot.example.com', '')).toBe('')
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

describe('canOpenInPenpot', () => {
  // THE break from both siblings: mode governs whether the archive is stored
  // locally, never whether the design can be opened (open-with.feature).
  it('offers the opener in sync mode', () => {
    expect(canOpenInPenpot('sync')).toBe(true)
  })

  it('offers the opener in link mode — identically', () => {
    expect(canOpenInPenpot('link')).toBe(true)
  })

  it('treats both modes the same, because both point at the same live design', () => {
    expect(canOpenInPenpot('sync')).toBe(canOpenInPenpot('link'))
  })

  // The one state with an id but no live design behind it: its Penpot original
  // was deleted, and a deleted design never comes back at its old id (§6.20),
  // so the link is permanently dead.
  it('hides the opener for unmapped, rather than following a dead link', () => {
    expect(canOpenInPenpot('unmapped')).toBe(false)
  })

  it('stays permissive when the mode is absent (the first-load race)', () => {
    expect(canOpenInPenpot('')).toBe(true)
  })
})
