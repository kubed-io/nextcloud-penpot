# Security Policy

Thanks for taking the time to look at the security of **Penpot Sync**. This file
describes how to report vulnerabilities and what to expect after you do.

## Supported versions

This app is pre-1.0 and ships fixes only on the latest release. Always update to
the newest version before reporting an issue — the bug may already be fixed.

| Version | Supported |
|---|---|
| Latest minor on `main` | ✅ |
| Anything older | ❌ |

The supported Nextcloud range is declared in [`appinfo/info.xml`](appinfo/info.xml)
(`dependencies/nextcloud` `min-version` / `max-version`). Versions outside that
range are out of scope.

## Reporting a vulnerability

**Do not open a public GitHub issue for a security report.**

Use [GitHub's private vulnerability reporting](https://github.com/kubed-io/nextcloud-penpot/security/advisories/new)
on this repo. That channel is encrypted, only visible to maintainers, and lets us
coordinate a fix and a release before anything goes public.

Please include:

- A short description of the issue and its impact.
- Steps to reproduce, ideally with a minimal proof-of-concept.
- The version of Penpot Sync, the Nextcloud version, the Penpot version, and the
  PHP version you tested on.
- Any relevant logs, request/response samples, or screenshots.
- Your assessment of severity (best guess is fine).

If for some reason you cannot use GitHub's private advisories, open a minimal
public issue saying "I have a security report, please contact me" with no
details, and a maintainer will reach out to set up a private channel.

## What to expect

- **Acknowledgement** of your report — usually within a few days.
- **A triage decision** (confirmed / not-a-vuln / out-of-scope / needs-info) once
  we've reproduced or investigated.
- **A coordinated fix** in a private branch when the report is confirmed.
- **A release with the fix** and a public advisory once the fix is available.
- **Credit** to you in the advisory and release notes, unless you'd rather stay
  anonymous.

This is a small, volunteer-run project — we don't have a paid security team or a
bounty program. We do take reports seriously and will work with you in good
faith.

## Scope

In scope:

- The PHP backend in `lib/` (`OCA\PenpotSync\…`).
- The JS frontend in `src/` and its built bundle in `dist/`.
- The release tarball produced by [`publish.yml`](.github/workflows/publish.yml).
- The CI workflows in [`.github/workflows/`](.github/workflows/) when they could
  leak secrets or be coerced into running untrusted code (e.g.
  `pull_request_target` misuse, unpinned actions running with elevated
  permissions).
- The `appinfo/info.xml` permissions declared by this app.

Out of scope:

- Nextcloud server itself — report those to
  [Nextcloud's security team](https://nextcloud.com/security/).
- Penpot itself — report those per
  [Penpot's own security policy](https://github.com/penpot/penpot/security).
- Vulnerabilities in third-party dependencies are tracked by Dependabot and
  `composer audit` / `npm audit`. A report is welcome if you've found one that
  is exploitable *through this app's specific usage* (i.e. not just "dep X has
  CVE Y").
- The homelab cluster this app happens to be developed in — it is not a
  production service that this project ships.

## Secrets policy

A handful of secrets are required to operate or release this app. They never live
in the repo:

- **Penpot access token** — §6.9's either/or fork closed in favour of both
  (superseded by §6.18). An instance-wide credential an admin sets in the
  Nextcloud admin section is what the sync actually authenticates with; an
  optional per-user token lets a gesture be attributed to the person who made it.
  Both are stored encrypted via `OCP\Security\ICrypto` and neither is ever logged.
  The per-user surface ships **disabled** (§D4.13): the service and its `occ`
  command exist, and their registrations are commented out.
- **No webhook secret**, because there is no webhook receiver. Penpot has
  webhooks and creating one works, but delivery has never been observed, so this
  app has no event-driven path and no inbound endpoint for Penpot to call. The
  scheduled pull is the only trigger (§6.17).
- **GitHub App private key** — used by the release workflow to bypass branch
  protection on the version-bump commit. Stored as the `GH_APP_KEY` repo secret.
  Never echoed.
- **Nextcloud app store signing key** — the private key that signs release
  tarballs for apps.nextcloud.com. It is the value of `NEXTCLOUD_STORE_KEY` in
  the **`nextcloud-store` GitHub Environment** (not a plain repo secret), is
  written to a temp file by the release job and deleted in the same step, and is
  never echoed. The local working copy lives in `.signing/`, which is
  gitignored in full — **no part of `.signing/` is ever committed**, key, CSR or
  certificate. The countersigned certificate is public and lives in
  [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests);
  that is its authoritative copy (saga §D4.10).
- **Nextcloud app store API token** — `NEXTCLOUD_STORE_TOKEN`, in the same
  environment. It authorises *uploads*; it is not the signing key and the two
  are not interchangeable.

If you spot a secret committed to the repo (current or historical), treat it as
a vulnerability and report it via the private channel above. It will be
rotated.

## Network egress (deliberate)

This app makes outbound HTTP requests to **one** destination: the Penpot instance
an admin configures. It does not fetch arbitrary user-supplied URLs.

**It writes, and considerably more than an earlier draft of this section claimed.**
That draft said the outbound calls were reads plus perhaps one narrow rename. That
stopped being true when the sync engine shipped, and understating an app's write
surface in its own security policy helps nobody assessing it. `PenpotClient`
speaks these RPC commands, and an ordinary gesture in Nextcloud can reach any of
them:

| | commands |
|---|---|
| read | `get-teams`, `get-all-projects`, `get-project-files`, `get-file-summary`, `get-team-deleted-files`, `export-binfile` |
| design writes | `create-file`, `rename-file`, `duplicate-file`, `delete-file`, `move-files`, `import-binfile` |
| project writes | `create-project`, `rename-project`, `delete-project`, `move-project` |
| trash | `restore-deleted-team-files`, `permanently-delete-team-files` |

What §6.1 locks is narrower than "read-only", and the difference is the whole
security-relevant point: this app never overwrites the CONTENT of a design Penpot
already has. It does create, rename, move, trash and destroy designs and projects
— deleting a mirror in Nextcloud really does trash the design in Penpot, and
emptying the Nextcloud trash really does destroy it. `import-binfile` writes
content only for a design being restored or rebuilt from an archive Nextcloud is
already holding.

**Local addresses.** The client is Nextcloud's `IClientService`, and this app does
**not** pass `allow_local_address`. It relies instead on the server-wide
`allow_local_remote_servers` config, which an admin has to turn on when Penpot
lives at a private, in-cluster address — the common case for the self-hosters this
is built for. `PenpotClient` names that setting in the error it raises when
Nextcloud refuses such a connection, and CI sets it for the same reason.

Setting the base URL is an admin action, so this is a trust-boundary relaxation an
admin opts into rather than an unauthenticated SSRF. It is real and intentional.

## Security-related CI gates

These run on every PR into `main` and on every push to `main` (see
[CONTRIBUTING.md](CONTRIBUTING.md) for the full gate list):

- **`composer audit`** — fails on any advisory in PHP deps.
- **`npm audit --omit=dev --audit-level=high`** — fails on high-or-above JS
  deps.
- **Psalm** (PHP static analysis) — uploads SARIF to the Security tab and
  annotates the PR inline; new findings block merge. CodeQL has no PHP support,
  so Psalm is this app's PHP code-scanning engine.
- **CodeQL** (JS / TS) — uploads to the Security tab; new findings block merge.
- **Dependabot** — alerts and version updates active for `composer`, `npm`, and
  `github-actions`.
- **Secret scanning** — enabled, GitHub-side.

If a Quality gate is failing on a PR that purports to fix a vulnerability, **fix
the gate** rather than bypassing it. The gates are how we know the fix is sound.

## Disclosure timeline

We follow standard coordinated disclosure: a public advisory is published once a
fix has shipped in a tagged release, or 90 days after confirmation, whichever
comes first. We will work with you on a tighter timeline if active exploitation
is reported.

Thanks again for helping keep this project safe.
