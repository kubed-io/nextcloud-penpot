/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest config, kept separate from vite.config.js (which is a lib/IIFE *build*
 * config, not a test config). Node environment is enough for the DOM-free
 * helpers this app's JS will need. Mirrors the nextcloud-libraries convention
 * (dedicated vitest.config) and the sibling apps' setup.
 *
 * tests/js/ now holds the helper suite (files-helpers.test.js), so `npm test`
 * no longer needs `--passWithNoTests`.
 */
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/js/**/*.test.js'],
  },
})
