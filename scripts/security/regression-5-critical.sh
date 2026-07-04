#!/usr/bin/env bash
# GOLIVE #2130 — regression harness for the 5 CRITICAL findings of the
# 2026-06 pre-SaaS audit (docs/audit/2026-06/). Re-runs each original probe
# against the live stack and asserts the fix still holds. Internal
# secure-SDLC verification of our OWN system — proof-of-fix, not offence.
#
#   AUD-001  Mercure anonymous cross-tenant SSE leak      (W0-1 / #1573)
#   AUD-002  RLS dead — pim_app superuser/BYPASSRLS       (W1-1 / #1580)
#   AUD-004  Meilisearch filter-key injection cross-read  (W0-2 / #1574)
#   AUD-005  real secrets in tracked .env                 (W0-7 / #1579)
#   AUD-007  token_dev_only unconditional account takeover(W0-5 / #1577)
#
# Usage: scripts/security/regression-5-critical.sh
# Exit 0 = all five still fixed; non-zero = a regression (go-live blocker).
set -uo pipefail

BASE="${PIM_BASE_URL:-https://pim.localhost}"
CURL=(curl -sk --max-time 15)
PASS=0
FAIL=0
RESULTS=()

pass() { RESULTS+=("PASS  $1"); PASS=$((PASS + 1)); }
fail() { RESULTS+=("FAIL  $1"); FAIL=$((FAIL + 1)); }

login() {
    "${CURL[@]}" -X POST "$BASE/api/auth/login" \
        -H 'content-type: application/json' \
        -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
        | python3 -c "import sys,json;print(json.load(sys.stdin).get('token',''))" 2>/dev/null
}

echo "== #2130 regression: 5 CRITICAL findings =="
DEMO_TOKEN="$(login admin@demo.localhost changeme)"
ACME_TOKEN="$(login admin@acme.localhost changeme)"
[ -n "$DEMO_TOKEN" ] || { echo "FATAL: demo login failed (rate limit? run: docker compose exec api bin/console cache:pool:clear cache.rate_limiter)"; exit 2; }

# ── AUD-001 — Mercure anonymous subscribe must be rejected ──────────────
# Original leak: anon GET /.well-known/mercure?topic=.../objects → 200
# text/event-stream. Fix: private updates + per-tenant topics; the hub
# refuses an anonymous subscriber (401) instead of streaming.
MERCURE_CODE="$("${CURL[@]}" -o /dev/null -w '%{http_code}' \
    "$BASE/.well-known/mercure?topic=$BASE/tenant/x/objects")"
if [ "$MERCURE_CODE" = "401" ]; then
    pass "AUD-001 Mercure anon subscribe → 401 (was 200 event-stream)"
else
    fail "AUD-001 Mercure anon subscribe → $MERCURE_CODE (expected 401)"
fi

# ── AUD-002 — RLS is real: pim_app non-super, non-bypass + FORCE RLS ─────
ROLE_OK="$(docker compose exec -T database psql -U pim -d pim -tA -c \
    "SELECT rolsuper||'/'||rolbypassrls FROM pg_roles WHERE rolname='pim_app';" 2>/dev/null | tr -d '[:space:]')"
FORCE_CNT="$(docker compose exec -T database psql -U pim -d pim -tA -c \
    "SELECT count(*) FROM pg_class WHERE relrowsecurity AND relforcerowsecurity;" 2>/dev/null | tr -d '[:space:]')"
# Boolean columns cast to 'false'/'true' inside the `||` expression.
if { [ "$ROLE_OK" = "false/false" ] || [ "$ROLE_OK" = "f/f" ]; } && [ "${FORCE_CNT:-0}" -ge 30 ]; then
    pass "AUD-002 pim_app super/bypass=$ROLE_OK, FORCE RLS on $FORCE_CNT tables"
else
    fail "AUD-002 pim_app=$ROLE_OK, FORCE RLS tables=$FORCE_CNT (expected f/f + ≥30)"
fi

# ── AUD-004 — Meilisearch filter-key injection rejected ─────────────────
# Original: an arbitrary `filter` key hit the shared Meili index and read
# another tenant's rows via an injected low-precedence OR. Fix:
# CatalogSearchService::assertFilterKeys() whitelist on /api/search/* → a
# non-filterable key is a 400, not a cross-tenant read.
INJ_CODE="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -G \
    -H "Authorization: Bearer $DEMO_TOKEN" \
    --data-urlencode 'filter[tenantId]=019ebfbb-e486-7d5e' \
    "$BASE/api/search/products")"
if [ "$INJ_CODE" = "400" ] || [ "$INJ_CODE" = "422" ]; then
    pass "AUD-004 Meili filter-key injection → $INJ_CODE (rejected)"
else
    fail "AUD-004 Meili filter-key injection → $INJ_CODE (expected 400/422)"
fi

# ── AUD-005 — no real secrets in tracked files ──────────────────────────
if bash scripts/lint-tracked-secrets.sh >/dev/null 2>&1; then
    pass "AUD-005 lint-tracked-secrets → clean (placeholders only)"
else
    fail "AUD-005 lint-tracked-secrets → FAIL (real secret tracked)"
fi

# ── AUD-007 — token_dev_only must be env-gated (absent outside dev) ──────
# The dev stack legitimately returns the token for local convenience, so we
# assert the ENV GUARD exists in code (prod/test drop the field). A missing
# guard is the account-takeover regression.
RESET_BODY="$("${CURL[@]}" -X POST "$BASE/api/auth/password-reset/request" \
    -H 'content-type: application/json' -d '{"email":"admin@demo.localhost"}')"
# The env gate lives in the shared DevTokenExposure trait ('prod' ===
# devTokenEnvironment drops the field), wired from %kernel.environment%.
GUARD_PRESENT="$(grep -c "'prod' === \$this->devTokenEnvironment" apps/api/src/Identity/Presentation/Controller/DevTokenExposure.php 2>/dev/null | tr -d ' ')"
WIRED="$(grep -c '\$devTokenEnvironment.*kernel.environment' apps/api/config/services.yaml 2>/dev/null | tr -d ' ')"
DEV_TOKEN_PRESENT="$(echo "$RESET_BODY" | python3 -c "import sys,json;print('token_dev_only' in json.load(sys.stdin))" 2>/dev/null)"
if [ "$GUARD_PRESENT" = "1" ] && [ "${WIRED:-0}" -ge 1 ]; then
    pass "AUD-007 token_dev_only env-gated in code (dev exposes=$DEV_TOKEN_PRESENT by design)"
else
    fail "AUD-007 token_dev_only has NO env guard — unconditional account takeover"
fi

echo
printf '%s\n' "${RESULTS[@]}"
echo "----"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
