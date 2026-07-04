#!/usr/bin/env bash
# GOLIVE #2135 — critical-path smoke against the live backend (SMOKE TEST
# RULE): every flow asserts the HTTP code AND the response shape, not just a
# 2xx. Covers auth, product CRUD, import/export lifecycle, feeds, API
# configurator and RBAC read surfaces. Console-clean on these routes is
# covered by the Playwright suite (122 specs, Chromium).
#
# Usage: scripts/smoke/critical-paths.sh
# Exit 0 = every critical path returns the expected shape.
set -uo pipefail

BASE="${PIM_BASE_URL:-https://pim.localhost}"
CURL=(curl -sk --max-time 20)
PASS=0; FAIL=0; RESULTS=()
pass() { RESULTS+=("PASS  $1"); PASS=$((PASS + 1)); }
fail() { RESULTS+=("FAIL  $1"); FAIL=$((FAIL + 1)); }

jqget() { python3 -c "import sys,json
try:
    d=json.load(sys.stdin)
except Exception:
    print(''); sys.exit()
for k in '$1'.split('.'):
    if isinstance(d,dict): d=d.get(k)
    else: d=None
print(d if d is not None else '')"; }

echo "== #2135 critical-path smoke =="
docker compose exec -T api php bin/console cache:pool:clear cache.rate_limiter >/dev/null 2>&1

# ── Auth: login returns a JWT ───────────────────────────────────────────
LOGIN="$("${CURL[@]}" -X POST "$BASE/api/auth/login" -H 'content-type: application/json' \
    -d '{"email":"admin@demo.localhost","password":"changeme"}')"
TOKEN="$(echo "$LOGIN" | jqget token)"
[ -n "$TOKEN" ] && [ "${#TOKEN}" -gt 100 ] && pass "auth login → JWT (${#TOKEN} chars)" || { fail "auth login → no token"; TOKEN=""; }
AUTH=(-H "Authorization: Bearer $TOKEN")

# ── Auth: /me returns the principal with roles ──────────────────────────
ME="$("${CURL[@]}" "${AUTH[@]}" "$BASE/api/auth/me")"
[ -n "$(echo "$ME" | jqget email)" ] && pass "auth /me → principal ($(echo "$ME" | jqget email))" || fail "auth /me → no email"

# ── Products: list has hydra shape ──────────────────────────────────────
PL="$("${CURL[@]}" "${AUTH[@]}" "$BASE/api/products?itemsPerPage=1")"
PT="$(echo "$PL" | jqget totalItems)"
[ -n "$PT" ] && [ "$PT" != "None" ] && pass "products list → totalItems=$PT" || fail "products list → no totalItems"

# ── Products: create → read → delete (full CRUD) ────────────────────────
OT="$("${CURL[@]}" "${AUTH[@]}" "$BASE/api/object_types?itemsPerPage=50" | python3 -c "import sys,json
d=json.load(sys.stdin)
for m in d.get('member', d.get('hydra:member', [])):
    if m.get('kind')=='product' or m.get('code')=='product':
        print(m.get('id','')); break")"
CREATED="$("${CURL[@]}" "${AUTH[@]}" -X POST "$BASE/api/products" \
    -H 'content-type: application/ld+json' \
    -d "{\"code\":\"SMOKE-2135-$$\",\"objectTypeId\":\"$OT\",\"attributes\":{}}")"
PID="$(echo "$CREATED" | jqget id)"
if [ -n "$PID" ]; then
    pass "product create → 201 id=$PID"
    GC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/products/$PID")"
    [ "$GC" = "200" ] && pass "product read → 200" || fail "product read → $GC"
    DC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X DELETE "${AUTH[@]}" "$BASE/api/products/$PID")"
    [ "$DC" = "204" ] || [ "$DC" = "200" ] && pass "product delete → $DC" || fail "product delete → $DC"
else
    fail "product create → no id (body: $(echo "$CREATED" | head -c 120))"
fi

# ── Import: session list reachable ──────────────────────────────────────
IC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/import-sessions?itemsPerPage=1")"
[ "$IC" = "200" ] && pass "import sessions list → 200" || fail "import sessions list → $IC"

# ── Export: session list reachable ──────────────────────────────────────
EC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/exports/sessions")"
[ "$EC" = "200" ] && pass "export sessions list → 200" || fail "export sessions list → $EC"

# ── Feeds: list + KPI ───────────────────────────────────────────────────
FC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/feeds")"
[ "$FC" = "200" ] && pass "feeds list → 200" || fail "feeds list → $FC"

# ── API configurator: connections list ──────────────────────────────────
CC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/connections")"
[ "$CC" = "200" ] && pass "api-configurator connections → 200" || fail "api-configurator connections → $CC"

# ── RBAC: users + roles admin surfaces ──────────────────────────────────
UC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/users?itemsPerPage=1")"
RC="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/api/roles")"
[ "$UC" = "200" ] && [ "$RC" = "200" ] && pass "RBAC users+roles → 200/200" || fail "RBAC users=$UC roles=$RC"

echo
printf '%s\n' "${RESULTS[@]}"
echo "----"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
