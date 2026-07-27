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
- The integration workflow mints a Penpot access token headlessly per run, so the suite needs no repository secret.
- Saga Chapter 1: the full design record for the Penpot integration, every API claim verified against a live instance — including a per-team, immutable choice between two mutually-exclusive folder models.
- Penpot API client: decodes Penpot's Transit wire format and carries an explicit per-command parameter table, because Penpot uses four different parameter conventions across four commands with no rule connecting them.
- `occ penpot_sync:set-token` stores the Penpot service-account token encrypted, and `occ penpot_sync:probe` checks the connection — reporting the teams and projects that token can actually see, since Penpot visibility is always membership-scoped.
- `occ penpot_sync:show-config` now also reports whether a service-account token is stored (never its value).
- Integration suite now runs against a real Penpot container, with a token minted per run — the only place the wire format is asserted, since mocking a protocol we have misread would only encode the misreading.
- Saga Chapter 2: the build plan, in dependency order, committing to the client before the admin surface.
- Documented that `allow_local_remote_servers` must be enabled when Penpot is reached at a private or in-cluster address — otherwise Nextcloud's SSRF guard blocks every request, and `occ penpot_sync:probe` now names that case specifically.
- Admin settings for the Penpot service-account token, stored encrypted, with a field that shows whether a token is set without ever echoing it back.
- Admin team mappings: map a Penpot team to a Nextcloud folder, with a per-mapping default file mode and an immutable folder mode — plus `occ penpot_sync:list-teams`, `add-mapping`, `list-mappings`, and `remove-mapping`.
- A team can only be mapped if the service account can actually see it in Penpot, because Penpot has no instance-wide view — the app now explains that up front instead of creating a mapping that would silently mirror nothing.
- Admin "Test connection", and `occ penpot_sync:test-connection`, which tell an unset token, a rejected token, and an unreachable Penpot apart — and name the `enable-access-tokens` instance flag, whose absence otherwise looks exactly like a bad token.
- Admin settings for the scheduled pull (on/off and how often), stored now and honoured when the background job lands.
- An optional personal Penpot token per Nextcloud user, so changes made from Nextcloud are attributed to that person in Penpot's history instead of to the shared service account — with `occ penpot_sync:set-personal-token`.
- `occ penpot_sync:show-config` now also reports the configured mappings and the pull schedule.
- A mapping now names the Nextcloud folder its Penpot team is mirrored into — leave it blank and it uses the team's own name, the same way the Grafana integration defaults its folder mappings. Project folders inside it still always match their Penpot project's name exactly.
- Each mapping records the Nextcloud groups its folder is shared with, and whether it uses a Team Folder or a plain shared folder — the same controls, defaults, and meanings as the n8n and Grafana integrations.
- Two mappings can no longer target the same Nextcloud folder, and a folder name must be a single folder rather than a path.
- The admin section now matches the n8n and Grafana integrations: one **Instance** card holding the URL and the token, **Sync Settings**, **Team mappings** as one card per mapping, and a **Sync Actions** panel collecting every button at the bottom.
- **Fixed:** the "pull on a schedule" setting silently reverted after saving. A declarative checkbox cannot persist on current Nextcloud — its value reaches a string-typed setter and the save aborts with no visible error — so the control is now a two-option radio, which saves correctly.
- A mapping's team, Nextcloud folder, Team Folder setting, default mode and folder mode are all fixed once it is created — changing any of them would migrate already-mirrored content, so removing and re-adding the mapping makes that cost visible. The groups it is shared with stay editable.
- Each mapping card carries its own **Sync now** button, in the same place as in the n8n and Grafana integrations — it reports that per-team sync is not available yet rather than silently doing nothing.
- Penpot Files-Metadata keys are now registered at boot: `penpot_id` / `penpot_revision` / `penpot_mode` on mirrored files and `penpot_project_id` / `penpot_team_id` on folders — advertised over WebDAV and indexed for search, ahead of the pull that writes them.
- Membership resolver: a `.penpot` file's project and team are derived by walking up the folder tree to the nearest ancestor carrying each id, so Nextcloud can nest project folders freely while Penpot stays flat — including the team-Drafts and personal-project states, and never storing a mapping on the file itself.
- The pull: `occ penpot_sync:sync pull` mirrors a mapped Penpot team into Nextcloud — the team's projects become folders and its files become `.penpot` link pointers, each stamped with the Penpot metadata the resolver reads back; Drafts stays a state at the team root, never a folder.
- `occ penpot_sync:status <path>` reports the Penpot metadata and the resolved membership (`in_project` / `drafts` / `personal` / `none`) for any mirrored node — read-only, and the first way to run the resolver against a real folder tree.
- The pull provisions a plain admin-owned folder for the mapped team (the groupfolders Team Folder backend follows), stamps the team id on it, and re-pulls idempotently by matching folders on their project id and files on their file id rather than by name.
- Integration suite now seeds a project directly in Penpot, runs the pull, and asserts the mirrored folder tree and its resolved membership through `occ penpot_sync:status` — the first end-to-end proof of the resolver on a live Nextcloud.
