# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One SHORT line per entry, written for a user —
  never a paragraph. Say what someone can now do, not how it was built. Only
  **BREAKING:** may stretch. Internal work — CI, refactors, types, tests —
  usually earns no line at all, and never more than a terse one. Deeper detail
  lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->


## [Unreleased]

The first release. Everything is new, which is why there is no *Changed* or
*Fixed* below — both are relative to a version somebody is running, and there
isn't one. The build history is in [`saga/`](saga/).

### Added

- **Map a Penpot team to a Nextcloud folder.** Its projects arrive as folders and its designs as `.penpot` files, with the Penpot icon and the design's own dates. Backed by a Team Folder or a plain shared folder.

- **The folder is the project.** A folder becomes a Penpot project when a design lands in it, named by its path — so a project called `Brand/2026` is two nested folders. Drafts is the mapped folder's root rather than a folder of its own.

- **Every file gesture reaches Penpot**: create, rename, move between projects or teams, copy, delete, restore and purge.

- **Two trashes.** Trashing a design puts it in Penpot's trash; restoring brings it back with its id, revision and history. Only emptying your Nextcloud trash destroys anything, and only a design still in Penpot's trash.

- **Restore a project folder and the project comes back.** Its designs come back with it, keeping the project's id when Penpot still has them and rebuilding it from your archives when it does not.

- **A project that comes back in Penpot reclaims its folder** from your Nextcloud trash instead of leaving a second one beside it.

- **Emptying the trash of a project folder destroys its designs in Penpot too**, finishing the delete the way emptying the trash of a single design already did.


- **Sync or Link, chosen per mapping.** `sync` keeps the real exported archive, so the folder doubles as a backup you can open offline; `link` keeps a pointer that costs nothing.

- **Sync to Penpot** turns `.penpot` archives Penpot has never seen into real designs. A design Penpot already has is never overwritten.

- **Open in Penpot** is the default click on any mirrored design.

- **Scheduled pulls**, one-shot buttons for either direction, and a connection test.


- **Every admin action has an `occ` command**, so the app can be configured headlessly.

- Penpot ids are published over WebDAV and indexed for search.

