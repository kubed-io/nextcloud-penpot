/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure, dependency-free helpers for the Files integration (src/files.js).
 *
 * Split out from files.js precisely because files.js imports `@nextcloud/*` ESM
 * at the top level, which makes it awkward to unit test. This module imports
 * nothing, so Vitest can exercise the branchy logic directly. Keep it free of
 * NC imports, DOM and network.
 */

/** The custom mimetype the pull stamps onto mirrored design files (saga §6.4). */
export const PENPOT_MIME = 'application/vnd.penpot'

/** The one extension, single-token — no compound-extension fragility (§6.4). */
export const PENPOT_EXT = '.penpot'

/**
 * Read a metadata value from a node's DAV attributes, tolerating the three
 * shapes it can arrive in depending on which PROPFIND produced the node: the
 * Files app's own listing strips the namespace, a raw stat may not, and some
 * paths hand back the bare key.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @param {string} key  The metadata key, e.g. "penpot_id".
 * @return {string}  '' when absent or not a string.
 */
export function readMetadata(node, key) {
  const a = node?.attributes ?? {}
  const raw = a[`metadata-${key}`] ?? a[key] ?? a[`{http://nextcloud.org/ns}metadata-${key}`]
  return typeof raw === 'string' ? raw : ''
}

/**
 * Read the Penpot file id from a node's DAV attributes (the listing fast path).
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}
 */
export function getPenpotId(node) {
  return readMetadata(node, 'penpot_id')
}

/**
 * Read the Penpot TEAM id the design belongs to.
 *
 * Stamped on the file itself by the pull (§C6.7) rather than resolved by walking
 * up to the Team Folder, because this runs in a browser holding one directory
 * PROPFIND: the file's own properties are free, an ancestor's are not. It is
 * also the only copy that survives a mirror being dragged out of its mapped
 * folder — at which point the design is still perfectly alive in Penpot, and the
 * folder walk has nothing left to find.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}
 */
export function getPenpotTeamId(node) {
  return readMetadata(node, 'penpot_team_id')
}

/**
 * Read the file's mode, translating the WIRE value back.
 *
 * `link` is stored as `reference` because the literal string `link` is
 * `is_callable()` in PHP and detonates core's PROPFIND handler — see
 * PenpotMetadata's class docblock. This is the browser end of that same
 * translation, and the only place in the frontend that knows about it.
 *
 * ## THE MODE DOES NOT GATE THE OPENER — A CORRECTION (§C6.7)
 *
 * An earlier cut of this file hid "Open in Penpot" for `unmapped`, believing
 * `unmapped` meant "the design was deleted, so the id is dead." It does not.
 * PenpotMetadata defines it as *carries a `penpot_id` but resolves to no Penpot
 * ancestor* — a mirror dragged OUT of its mapped folder. The design is alive and
 * perfectly openable; only its position was lost. Hiding the opener there would
 * have broken the link exactly when someone rearranged their files, which is the
 * case the deep link is supposed to survive.
 *
 * It was dead code as well: nothing in the app writes `unmapped`. The pull
 * stamps `sync` or `link`, and nothing else ever calls writeFile with a mode.
 *
 * The state that gate was reaching for — an id whose design no longer exists —
 * is not reachable either: the prune moves a vanished design's mirror to the
 * Nextcloud trash (C5.1) rather than leaving it in the tree.
 *
 * So no mode is consulted when deciding to show the action. This is kept because
 * the wire translation is real knowledge, and because the mode-pill and
 * download-guard slices both need the mode on the listing.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}  '' | 'sync' | 'link'
 */
export function getPenpotMode(node) {
  const mode = readMetadata(node, 'penpot_mode')
  return mode === 'reference' ? 'link' : mode
}

/**
 * Build the Penpot workspace deep link.
 *
 * ## THE ROUTE WAS READ; THE REQUIRED PARAMS WERE NOT, AND THAT WAS THE BUG
 *
 * The route table is genuinely this (read from a live instance's `js/main.js`):
 *
 *     ["/workspace",                      :workspace]
 *     ["/workspace/:project-id/:file-id",  :workspace-legacy]
 *
 * and `router/resolve` is `(match->path (match-by-name router id) params)` with
 * no path params, so everything rides the QUERY STRING. All correct.
 *
 * What was WRONG was the next step: `go-to-workspace` call sites in the bundle
 * pass `file-id` alone, and that was read as "file-id is sufficient". Those are
 * IN-APP navigations, where `team-id` is already in the URL and is carried over.
 * A cold load from outside carries nothing, and `?file-id=` alone lands on an
 * internal error. Confirmed the hard way, by clicking one.
 *
 * The authority is Penpot's own legacy-route redirect, which exists to convert
 * `/#/workspace/<project-id>/<file-id>` into the modern form. It calls
 * `get-project {id: project-id}` **purely to look up the team id**, then
 * navigates with `{team-id, file-id, page-id, layout}`. Penpot will not open a
 * workspace without a team, which is exactly why that RPC round trip exists.
 *
 * So: `team-id` and `file-id`, both required, both stamped on the file.
 *
 * `page-id` is omitted. Penpot's own legacy redirect passes it through as nil
 * when the legacy URL had none, so the workspace must cope without one — and a
 * mirror has no way to know which page a user wants. Getting one would cost a
 * `get-file` per design.
 *
 * The base url is passed in rather than closed over so this stays pure.
 *
 * @param {string} penpotUrl  Trailing-slash-trimmed Penpot base URL.
 * @param {string} teamId     The Penpot team id (a uuid).
 * @param {string} penpotId   The Penpot file id (a uuid).
 * @return {string}  '' when any part is missing (the caller hides the action).
 */
export function buildUrl(penpotUrl, teamId, penpotId) {
  if (!penpotUrl || !teamId || !penpotId) return ''
  return `${penpotUrl}/#/workspace`
    + `?team-id=${encodeURIComponent(teamId)}`
    + `&file-id=${encodeURIComponent(penpotId)}`
}

/**
 * Is this file-action context a single mirrored Penpot file?
 *
 * Matches the custom mime OR a `.penpot` basename, and only for a single
 * selection. The basename arm is not redundant: a file written before the
 * mimetype repair step ran, or one whose filecache row has not been re-stamped
 * yet, is still ours.
 *
 * @param {{nodes?: Array<{mime?: string, basename?: string}>}} [context]
 * @return {boolean}
 */
export function isPenpotFile(context) {
  const node = context?.nodes?.[0]
  if (!node || context.nodes.length !== 1) return false
  return node.mime === PENPOT_MIME
    || (typeof node.basename === 'string' && node.basename.endsWith(PENPOT_EXT))
}
