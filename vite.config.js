/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Plain vite config (no @nextcloud preset).
 *
 * Why not the preset? `@nextcloud/vite-config`'s app preset hard-wipes the
 * entire `js/` directory before each build (its `EmptyJSDirPlugin` calls
 * `rmSync('js', recursive: true)`). The preset is designed for apps where
 * every JS file is vite-built, but hand-written admin-settings scripts in
 * `js/` are meant to stay unbundled. So this ships a minimal IIFE bundle
 * instead, same as the sibling apps.
 *
 * Output: `dist/penpot_sync-files.js` (single self-contained file, no chunks).
 * Loaded by `Listener\LoadFilesScriptListener` via `Util::addScript('penpot_sync',
 * '../dist/penpot_sync-files')` — NC's loader appends `js/<file>.js` to the app
 * root, so the `../dist/` prefix walks back out of `js/` and into `dist/`. All
 * generated artefacts stay under `dist/` (gitignored).
 */
import { defineConfig } from 'vite'

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: true,
    target: 'es2020',
    // Vite 8 bundles with Rolldown and no longer ships esbuild; its default
    // minifier is Oxc. Leaving minify at the default ('oxc') keeps esbuild out
    // of the dependency tree entirely (which also avoids the esbuild dev-server
    // advisory that prompted this bump). 'esbuild' is deprecated in Vite 8.
    minify: 'oxc',
    lib: {
      // IIFE so the bundle adds nothing to the global scope and runs
      // inline at <script> load time — no module loader plumbing needed.
      entry: 'src/files.js',
      name: 'penpotSyncFiles',
      formats: ['iife'],
      fileName: () => 'penpot_sync-files.js',
    },
  },
})
