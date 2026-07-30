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
 * Read the file's mode, translating the WIRE value back.
 *
 * `link` is stored as `reference` because the literal string `link` is
 * `is_callable()` in PHP and detonates core's PROPFIND handler — see
 * PenpotMetadata's class docblock. This is the browser end of that same
 * translation, and the only place in the frontend that knows about it.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}  '' | 'sync' | 'link' | 'unmapped'
 */
export function getPenpotMode(node) {
  const mode = readMetadata(node, 'penpot_mode')
  return mode === 'reference' ? 'link' : mode
}

/**
 * Build the Penpot deep link for a design id.
 *
 * ## THE ROUTE IS CONFIRMED, NOT GUESSED (saga §C3.4 → §C6.1)
 *
 * Course 3 deliberately refused to write this function: the workspace route had
 * never been called, and inventing a plausible one is the exact guess the saga
 * exists to refuse. It was then read out of a live instance's own route table:
 *
 *     ["/workspace",                      :workspace]
 *     ["/workspace/:project-id/:file-id",  :workspace-legacy]
 *
 * and `router/resolve` is `(match->path (match-by-name router id) params)` —
 * `match-by-name` is handed no path params, so everything reitit receives rides
 * the QUERY STRING. Hence `?file-id=`, not a path segment.
 *
 * THE NEW ROUTE NEEDS ONLY THE ID THE FILE ITSELF CARRIES. That is why the
 * legacy form is not used even though it still resolves: it needs a project id,
 * which lives on an ancestor folder, so an *unmapped* mirror (one dragged out of
 * its project folder, file-type.feature) could not build a link at all. Keying
 * on `file-id` alone means the deep link survives every move.
 *
 * `page-id` is optional — Penpot's own dashboard navigates with `file-id` alone
 * and lands on the file's first page, which is the right destination for us.
 *
 * The base url is passed in rather than closed over so this stays pure.
 *
 * @param {string} penpotUrl  Trailing-slash-trimmed Penpot base URL.
 * @param {string} penpotId   The Penpot file id (a uuid).
 * @return {string}  '' when either half is missing (the caller hides the action).
 */
export function buildUrl(penpotUrl, penpotId) {
  return penpotUrl && penpotId
    ? `${penpotUrl}/#/workspace?file-id=${encodeURIComponent(penpotId)}`
    : ''
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

/**
 * Should "Open in Penpot" be offered for a file in this mode?
 *
 * ## THE MODE AXIS DOES NOT GATE THE OPENER — THE ID DOES
 *
 * This is the sharp break from both siblings, and open-with.feature is explicit
 * about it: their modes change what a click *means*, so they have a row per
 * mode. Here `sync` and `link` differ only in whether the ARCHIVE is stored
 * locally (§6.22); both carry the same `penpot_id` and both deep-link to the
 * same live design. So mode is not consulted at all.
 *
 * What does gate it is having an id to link to. `unmapped` is the one state that
 * means "this file has a penpot_id but no live design behind it" — a mirror
 * whose design was deleted, whose id is permanently dead (§6.20). Following that
 * link would open a 404, so the action hides rather than lie.
 *
 * ## HIDING HANDS THE FILE BACK TO NEXTCLOUD, AND THAT IS THE INTENDED ENDING
 *
 * With no action registered for it, a click falls through to core's default for
 * the mimetype, which is a download. That is the right answer rather than a
 * consolation prize: there is nothing left that can open the design, and the
 * bytes on disk are all that survives it. A `sync` mirror downloads its real
 * archive — the one case where the local backup is the entire remaining value of
 * the file. Deciding to hide is therefore a decision about what a click SHOULD
 * do, not just what it should not.
 *
 * An absent mode ('' — the first-load PROPFIND race, or an untracked file) stays
 * permissive: the action shows, and resolves to nothing harmlessly if there is
 * no id behind it.
 *
 * @param {string} mode
 * @return {boolean}
 */
export function canOpenInPenpot(mode) {
  return mode !== 'unmapped'
}
