#!/usr/bin/env bash
#
# Mint a Penpot personal access token for the integration tests — a
# PREREQUISITE, not a feature. The app under test does nothing about how a token
# is created; this only exists so the suite can act as "a user who already has a
# valid token".
#
# WHY THIS CAN EXIST AT ALL (saga §6.47). Penpot has no admin API and no service
# accounts — every token belongs to a personal account (saga §6.8). So unlike
# Grafana (where an admin mints a service account in 2 calls), we have to CREATE
# an account first. Four calls, all confirmed present on a live instance:
#
#   1. prepare-register-profile {fullname,email,password} -> a registration token
#   2. register-profile         {token}                   -> the account exists
#   3. login-with-password      {email,password}          -> an auth cookie
#   4. create-access-token      {name}                    -> the bearer token
#
# More steps than Grafana's mint, but strictly LESS privileged — and each CI run
# gets a genuinely isolated account rather than sharing one.
#
# REQUIRED PENPOT FLAGS on the instance under test:
#   enable-access-tokens        step 4 is gated behind it (off by default upstream)
#   disable-email-verification  otherwise step 2 waits on an email nobody reads
# and it must NOT carry `disable-registration` / `disable-login-with-password`.
# Penpot's defaults already allow email+password registration, so a CI container
# just needs the two flags above. (Our own production instance sets the disabling
# flags because it's OIDC/LDAP-gated — which is why this script can't be run
# against it.)
#
# Inputs (env):  PENPOT_URL, and optionally PENPOT_CI_EMAIL / PENPOT_CI_PASSWORD
# Output:        the raw token on stdout (nothing else). Exits non-zero if it
#                cannot obtain one — the pipeline must fail loud before tests.

set -euo pipefail

: "${PENPOT_URL:?PENPOT_URL is required}"

# Unique per run so repeated CI runs never collide on an existing account.
email="${PENPOT_CI_EMAIL:-ci-$(date +%s)-$$@example.test}"
password="${PENPOT_CI_PASSWORD:-Integration-Tests-1}"
rpc="$PENPOT_URL/api/rpc/command"

# Penpot speaks Transit-JSON by default; ask for plain JSON explicitly. (The
# saga's "border guards speak a strange dialect" caveat — Course 3.)
hdr=(-H 'Accept: application/json' -H 'Content-Type: application/json')

json_field() { sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p"; }

# ── 1. prepare-register-profile → a registration token ──────────────────────
reg_token=$(
	curl -fsS -X POST "$rpc/prepare-register-profile" "${hdr[@]}" \
		-d "{\"fullname\":\"CI Integration\",\"email\":\"$email\",\"password\":\"$password\"}" \
	| json_field token
)
if [ -z "$reg_token" ]; then
	echo "mint-penpot-token: prepare-register-profile returned no token." >&2
	echo "  Is registration disabled on $PENPOT_URL? See this script's header." >&2
	exit 1
fi

# ── 2. register-profile → the account now exists ────────────────────────────
# Response carries the profile; we only care that it succeeded (curl -f).
curl -fsS -X POST "$rpc/register-profile" "${hdr[@]}" \
	-d "{\"token\":\"$reg_token\"}" >/dev/null

# ── 3. login-with-password → an auth cookie ─────────────────────────────────
# Penpot authenticates this call with a session cookie, so keep a jar for step 4.
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT
curl -fsS -c "$jar" -X POST "$rpc/login-with-password" "${hdr[@]}" \
	-d "{\"email\":\"$email\",\"password\":\"$password\"}" >/dev/null

# ── 4. create-access-token → the bearer token the app will use ──────────────
token=$(
	curl -fsS -b "$jar" -X POST "$rpc/create-access-token" "${hdr[@]}" \
		-d '{"name":"integration-tests"}' \
	| json_field token
)
if [ -z "$token" ]; then
	echo "mint-penpot-token: create-access-token returned no token." >&2
	echo "  Is 'enable-access-tokens' in PENPOT_FLAGS on $PENPOT_URL?" >&2
	exit 1
fi

printf '%s' "$token"
