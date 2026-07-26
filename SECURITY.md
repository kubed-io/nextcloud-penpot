# Security Policy

Thanks for taking the time to look at the security of **Penpot Sync**. This file
describes how to report vulnerabilities and what to expect after you do.

**This repo is currently pre-code** (see [README.md](README.md) and
[AGENTS.md](AGENTS.md)) — there is no `lib/` or `src/` yet, so most of the scope
below is forward-looking, describing the design in
[saga/Chapter_1_First_Contact.md](saga/Chapter_1_First_Contact.md) rather than
shipped code. Kept here now so the policy is in place before real code lands.

## Supported versions

This app is pre-1.0 (currently pre-code) and will ship fixes only on the latest
release once releases exist. Always update to the newest version before reporting
an issue — the bug may already be fixed.

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

In scope, once the corresponding code exists:

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

A handful of secrets will be required to operate or release this app once it has
code. They never live in the repo:

- **Penpot personal access token** — per the saga's still-open §6.9 fork, either
  entered by an admin in the Nextcloud admin section (one instance-wide
  credential) or entered by each Nextcloud user on their own personal-settings
  page — whichever design is ratified. Either way, stored encrypted via
  `OCP\Security\ICrypto`. Never logged.
- **Penpot webhook validation** — Penpot's `create-webhook` performs a live
  reachability check at creation time (saga §5.1); no bearer/secret is currently
  known to be part of that handshake, but this section will be updated once a
  webhook receiver is designed.
- **GitHub App private key** — used by the release workflow to bypass branch
  protection on the version-bump commit. Stored as the `GH_APP_KEY` repo secret.
  Never echoed.
- **Future Nextcloud app store signing key** — when a packaging chapter lands,
  the signing key for app-store releases will be a repo secret. The
  corresponding `.csr`/`.crt` files may be committed; the `.key` never is.

If you spot a secret committed to the repo (current or historical), treat it as
a vulnerability and report it via the private channel above. It will be
rotated.

## Network egress (deliberate, once the sync engine exists)

This app is designed to make outbound HTTP requests to **one** destination: the
Penpot instance an admin (or, per the open §6.9 fork, an individual user)
configures. Unlike a two-way integration, this app **never writes design content
back** to that destination (locked, saga §6.1) — its outbound calls are read
(`export-binfile` and related GETs) plus, if §6.2's rename fork is ever ratified,
a narrow `rename-file` call. It does not fetch arbitrary user-supplied URLs.

The eventual client is expected to follow the same pattern as the sibling apps
(`nextcloud-grafana`, `nextcloud-n8n`): Nextcloud's `IClientService` with
**`allow_local_address => true`**, because the target audience is self-hosters
whose Penpot instance typically lives at a private, in-cluster address. The same
trade-off documented in those apps' `SECURITY.md` applies here: setting the base
URL is an admin (or authenticated-user) action, so this is a trust-boundary
relaxation, not an unauthenticated SSRF — but it is real and intentional, and
will be documented precisely once the client code exists.

## Security-related CI gates

These run on every PR into `main` and on every push to `main` (see
[CONTRIBUTING.md](CONTRIBUTING.md) for what each currently has to check, given
the repo is pre-code):

- **`composer audit`** — fails on any advisory in PHP deps.
- **`npm audit --omit=dev --audit-level=high`** — fails on high-or-above JS
  deps.
- **Psalm** (PHP static analysis) — will upload SARIF to the Security tab once
  `lib/` exists; new findings block merge.
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
