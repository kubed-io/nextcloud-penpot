/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Files-app integration for penpot_sync — the app's first browser-side code.
 *
 * It registers exactly ONE file action: "Open in Penpot", promoted to the
 * default click for `application/vnd.penpot`, so clicking a mirrored design row
 * lands you in the live design.
 *
 * ## WHAT IS DELIBERATELY ABSENT, AND WHY IT IS THE POINT
 *
 * Both sibling apps register a second opener — "Open with text editor" — and
 * make it the default click for the modes that hold editable JSON. This app has
 * no such action, in any mode, for any file, ever (open-with.feature; saga
 * §6.1). A `.penpot` archive is an opaque ZIP of nested design-shape JSON: there
 * is no coherent hand-edit, and no way to re-import one if there were. Offering
 * a text editor would be offering a round-trip that does not exist.
 *
 * That single omission is why this file is a third of the size of its siblings'
 * — no editor modal, no save path, no injected styles, no `NodeWrittenEvent`.
 * The read-only architecture is not a limitation being worked around here; it is
 * the reason the surface is this small.
 *
 * ## GETTING THE ID TO THE CLICK HANDLER — TWO TIERS, NO CUSTOM ENDPOINT
 *
 *   1. PRIMARY (zero extra calls): registerDavProperty() adds
 *      `metadata-penpot_id` to the Files app's directory PROPFIND, so it rides
 *      the listing and lands on `node.attributes`. Covers every navigation.
 *   2. FALLBACK (one call, rare): on the very first folder after a full page
 *      load, this script can register a beat after core's first PROPFIND, so
 *      that one listing misses the prop. When it does, we stat the single node
 *      through the built-in @nextcloud/files WebDAV client. No bespoke
 *      controller and no bespoke route — the same authenticated DAV core uses.
 */
import { registerFileAction, DefaultType } from '@nextcloud/files'
import { registerDavProperty, getDefaultPropfind, getClient, getRootPath } from '@nextcloud/files/dav'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { getPenpotId, getPenpotMode, buildUrl, isPenpotFile, canOpenInPenpot } from './files-helpers.js'
// The mark lives as a real SVG under img/ (the single source of truth for the
// app's glyphs) and Vite inlines it at build time via ?raw, so nothing is
// hand-pasted here. It is the SAME file RegisterMimetype copies into
// core/img/filetypes/, so the row icon and this menu entry are one mark.
import penpotMarkIcon from '../img/penpot.svg?raw'

const APP_ID = 'penpot_sync'

// Register our metadata keys as DAV properties so they ride the directory
// PROPFIND (this writes to the shared scope store core's PROPFIND reads). `nc`
// is a default namespace, so the bare prefixed name is enough.
registerDavProperty('nc:metadata-penpot_id')
// The mode does NOT decide the opener here (see canOpenInPenpot) — it is
// registered because the action's enabled() reads it to spot the one state that
// has an id but no live design behind it.
registerDavProperty('nc:metadata-penpot_mode')

// Base URL of the Penpot instance, server-rendered into Initial State by
// LoadFilesScriptListener. Empty until an admin configures it — the action hides
// in that case rather than offering a click that goes nowhere.
const penpotUrl = (() => {
  try {
    return String(loadState(APP_ID, 'penpot_url') || '').replace(/\/+$/, '')
  } catch {
    return ''
  }
})()

/**
 * Fallback for the first-load race: ask the built-in WebDAV endpoint for just
 * this node's properties. getDefaultPropfind() now includes our registered
 * props, so the single-node stat returns `metadata-penpot_id`.
 *
 * @param {object} node
 * @return {Promise<string>}
 */
async function propfindPenpotId(node) {
  if (!node?.path) return ''
  try {
    const res = await getClient().stat(getRootPath() + node.path, {
      details: true,
      data: getDefaultPropfind(),
    })
    return res?.data?.props?.['metadata-penpot_id'] || ''
  } catch (e) {
    console.warn('[penpot_sync] metadata PROPFIND failed', e)
    return ''
  }
}

/**
 * Node → Penpot deep link: node attributes first (free), else a one-shot
 * PROPFIND.
 *
 * @param {object} node
 * @return {Promise<string>}
 */
async function resolveUrl(node) {
  return buildUrl(penpotUrl, getPenpotId(node)) || buildUrl(penpotUrl, await propfindPenpotId(node))
}

// @nextcloud/files v4: registerFileAction takes a plain IFileAction object;
// enabled()/exec() receive a single context `{ nodes, view, folder, contents }`.
registerFileAction({
  id: 'penpot_sync.open',
  displayName: () => t(APP_ID, 'Open in Penpot'),
  iconSvgInline: () => penpotMarkIcon,

  // Three gates, cheapest first: an instance to open, a file of ours, and a
  // design still living behind the id. Notably NOT gated on sync-vs-link —
  // both modes point at the same design (saga §6.22).
  enabled: (context) => !!penpotUrl
    && isPenpotFile(context)
    && canOpenInPenpot(getPenpotMode(context?.nodes?.[0])),

  async exec(context) {
    const url = await resolveUrl(context?.nodes?.[0])
    // null = silent no-op rather than an error toast: reaching here with no id
    // means the file is ours but untracked, which is a state, not a failure.
    if (!url) return null
    window.open(url, '_blank', 'noopener,noreferrer')
    return true
  },

  // THE default click, with no competitor. The siblings need an `order` to win a
  // priority fight against a text-editor opener; there is nothing to outrank
  // here, because a .penpot file has no other registered opener.
  default: DefaultType.DEFAULT,
  order: -50,
})

console.info('[penpot_sync] files integration loaded — one action: Open in Penpot (no text editor, by design)')
