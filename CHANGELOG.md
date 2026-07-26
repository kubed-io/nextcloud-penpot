# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

### Added

- Project scaffolding: docs, tooling config, and CI for the read-only Penpot → Nextcloud mirror app, ahead of any application code (see `saga/Chapter_1_First_Contact.md`).
- Admin setting for the Penpot base URL, with `occ penpot_sync:set-url` and `occ penpot_sync:show-config` — the app can now be pointed at a Penpot instance entirely headlessly.
- Unit suite covering the URL setting and its CLI, plus a Behat integration suite that installs the app on a real Nextcloud and drives those commands.
- `tests/integration/bin/mint-penpot-token.sh`: mints a Penpot access token headlessly for CI, so the integration suite needs no repository secret.
- Saga Chapter 1: the full design record for the Penpot integration, every API claim verified against a live instance.
