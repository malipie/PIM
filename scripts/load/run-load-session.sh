#!/usr/bin/env bash
# GOLIVE #2124 — load-session orchestrator.
#
# Mints a JWT, resolves the product ObjectType id, and runs the selected k6
# scenarios from scripts/load/ against the running stack. Seeding is a
# separate, explicit step (see --seed) because 50k x 24 values is a
# multi-minute write. Summaries land in scripts/load/results/ (gitignored).
#
# Usage:
#   scripts/load/run-load-session.sh [--seed N] [--scenario NAME ...] [--vus N] [--duration D]
#
# Examples:
#   scripts/load/run-load-session.sh --seed 50000            # seed only
#   scripts/load/run-load-session.sh                          # all default scenarios
#   scripts/load/run-load-session.sh --scenario products-list --vus 50 --duration 120s
#   FEED_PULL_URL=https://pim.localhost/api/feeds/pull/<t>/<token>.xml \
#     scripts/load/run-load-session.sh --scenario feed-pull

set -euo pipefail
cd "$(dirname "$0")/../.."

BASE_URL="${K6_BASE_URL:-https://pim.localhost}"
EMAIL="${K6_LOGIN_EMAIL:-admin@demo.localhost}"
PASSWORD="${K6_LOGIN_PASSWORD:-changeme}"
TENANT="${LOAD_TENANT:-demo}"
VUS="${K6_VUS:-20}"
DURATION="${K6_DURATION:-60s}"
SEED=""
SCENARIOS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --seed) SEED="$2"; shift 2 ;;
    --scenario) SCENARIOS+=("$2"); shift 2 ;;
    --vus) VUS="$2"; shift 2 ;;
    --duration) DURATION="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

if [[ -n "$SEED" ]]; then
  # Console runs connect as pim_app (FORCE RLS, no request-scoped GUC) and see
  # zero rows — maintenance writes go through the owner connection, the same
  # pattern the api entrypoint uses for pim:dev:ensure-seeded.
  echo "== Seeding $SEED products for tenant $TENANT (canonical values) =="
  docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api \
    sh -c "DATABASE_URL=\"\$DATABASE_URL_OWNER\" php bin/console pim:load:seed --products=$SEED --tenant=$TENANT"
  echo "== Rebuilding attributes_indexed from canonical values =="
  docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api \
    sh -c "DATABASE_URL=\"\$DATABASE_URL_OWNER\" php bin/console pim:catalog:detect-attributes-drift --reconcile --tenant=$TENANT --kind=product"
  echo "== Reindexing Meilisearch =="
  docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api \
    sh -c "DATABASE_URL=\"\$DATABASE_URL_OWNER\" php bin/console pim:search:reindex"
  exit 0
fi

if [[ ${#SCENARIOS[@]} -eq 0 ]]; then
  # Default read-heavy set. Rate-limited smokes (auth-login, import-start)
  # and feed-pull (needs FEED_PULL_URL) run only when named explicitly.
  SCENARIOS=(products-list search-products export-preflight bulk-preview)
fi

echo "== Minting JWT for $EMAIL =="
TOKEN=$(curl -sk -X POST "$BASE_URL/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" | python3 -c 'import json,sys; print(json.load(sys.stdin)["token"])')

TARGET_OBJECT_TYPE_ID=""
if [[ " ${SCENARIOS[*]} " == *" import-start "* ]]; then
  TARGET_OBJECT_TYPE_ID=$(curl -sk "$BASE_URL/api/object_types?kind=product" -H "Authorization: Bearer $TOKEN" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); m=d.get("hydra:member") or d.get("member") or []; print(m[0]["@id"].split("/")[-1] if m else "")')
fi

mkdir -p scripts/load/results
STAMP=$(date +%Y%m%d-%H%M%S)

for name in "${SCENARIOS[@]}"; do
  echo "== k6: $name (vus=$VUS duration=$DURATION) =="
  # K6_VUS / K6_DURATION are NATIVE k6 option overrides — they must reach ONLY
  # the load scenarios. The rate-limited smokes (auth-login, import-start,
  # feed-pull) pin their own fixed shape (iterations / arrival-rate); passing
  # the native overrides would replace it and blast through the limiter.
  case "$name" in
    feed-pull)
      SIZE_ARGS=(-e "FEED_PULL_DURATION=${FEED_PULL_DURATION:-5m}" -e "FEED_RATE_PER_MINUTE=${FEED_RATE_PER_MINUTE:-1}") ;;
    auth-login|import-start)
      # Fixed iteration/vus shape lives in the script; pass a harmless marker
      # so the array is never empty (set -u + bash < 4.4 unbound-var guard).
      SIZE_ARGS=(-e "LOAD_SMOKE=1") ;;
    *)
      SIZE_ARGS=(-e "K6_VUS=$VUS" -e "K6_DURATION=$DURATION") ;;
  esac
  docker compose --profile perf run --rm \
    -e API_TOKEN="$TOKEN" \
    -e K6_BASE_URL="$BASE_URL" \
    "${SIZE_ARGS[@]}" \
    -e K6_P95_MS="${K6_P95_MS:-300}" \
    -e LOAD_P95_MS="${K6_P95_MS:-300}" \
    -e FEED_PULL_URL="${FEED_PULL_URL:-}" \
    -e TARGET_OBJECT_TYPE_ID="$TARGET_OBJECT_TYPE_ID" \
    k6 run "--summary-export=/load/results/${STAMP}-${name}.json" "/load/${name}.k6.js" \
    || echo "!! scenario $name FAILED (thresholds or errors) — see summary above"
done

echo "== Summaries in scripts/load/results/${STAMP}-*.json =="
