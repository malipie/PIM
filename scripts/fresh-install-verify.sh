#!/usr/bin/env bash
# GOLIVE A5 (#2125) — fresh-install / from-zero rebuild consistency check.
#
# Verifies the three "from zero" rebuild paths a prod deploy or an external
# software house exercises on day one, and asserts cross-store consistency:
#
#   1. (--with-migrations) migrate an EMPTY database through the full history
#   2. Meilisearch reindex --purge  → index rebuilt only from Postgres
#   3. attributes_indexed reconcile → projection rebuilt from canonical values
#
# Then asserts: Meili numberOfDocuments == Postgres object count, and reports
# the residual attributes-drift count (known false-positive on select-type
# values — see #2186).
#
# Maintenance commands connect through the OWNER url (pim_app sees zero rows
# under FORCE RLS without a request GUC) — same pattern as the api entrypoint.
#
# SAFE by default: reindex --purge and reconcile rebuild PROJECTIONS from the
# canonical Postgres source; they never mutate object_values. --with-migrations
# DROPS the database and is gated behind an explicit flag + confirmation.
#
# Usage:
#   scripts/fresh-install-verify.sh                 # reindex + reconcile + assert
#   scripts/fresh-install-verify.sh --with-migrations --force   # full drop+migrate first (DESTRUCTIVE)

set -euo pipefail
cd "$(dirname "$0")/.."

TENANT_FLAG=""
WITH_MIGRATIONS=0
FORCE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-migrations) WITH_MIGRATIONS=1; shift ;;
    --force) FORCE=1; shift ;;
    --tenant) TENANT_FLAG="--tenant=$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

owner() { docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api sh -c "DATABASE_URL=\"\$DATABASE_URL_OWNER\" $1"; }
psql_q() { docker compose exec -T database psql -U "${POSTGRES_USER:-pim}" -d "${POSTGRES_DB:-pim}" -tA -c "$1"; }

if [[ "$WITH_MIGRATIONS" == "1" ]]; then
  if [[ "$FORCE" != "1" ]]; then
    echo "Refusing to drop the database without --force. This is DESTRUCTIVE." >&2
    exit 1
  fi
  echo "== [1/3] Fresh migrations from empty database =="
  docker compose stop api >/dev/null
  owner "php bin/console pim:db:reset --with-fixtures --force"
  docker compose start api >/dev/null
  sleep 8
fi

echo "== [2/3] Meilisearch reindex from zero (--purge) =="
owner "php bin/console pim:search:reindex --purge" | tail -2
sleep 3

echo "== [3/3] attributes_indexed rebuild from canonical values (--reconcile) =="
owner "php bin/console pim:catalog:detect-attributes-drift --reconcile ${TENANT_FLAG}" | tail -2

echo
echo "== Consistency assertions =="
MKEY=$(grep '^MEILI_MASTER_KEY=' .env | cut -d= -f2)
MEILI_DOCS=$(docker compose exec -T meilisearch curl -s "http://localhost:7700/indexes/objects/stats" \
  -H "Authorization: Bearer $MKEY" | python3 -c 'import json,sys; print(json.load(sys.stdin)["numberOfDocuments"])')
PG_OBJECTS=$(psql_q "SELECT count(*) FROM objects;" | tr -d '[:space:]')
EMPTY_IDX=$(psql_q "SELECT count(*) FROM objects WHERE attributes_indexed IS NULL OR attributes_indexed='{}';" | tr -d '[:space:]')

echo "meili_documents = $MEILI_DOCS"
echo "postgres_objects = $PG_OBJECTS"
echo "empty_attributes_indexed = $EMPTY_IDX"

RC=0
if [[ "$MEILI_DOCS" == "$PG_OBJECTS" ]]; then
  echo "PASS: Meili index count matches Postgres (reindex-from-zero consistent)."
else
  echo "FAIL: Meili=$MEILI_DOCS != Postgres=$PG_OBJECTS — stale/missing documents."; RC=1
fi

# Residual drift after reconcile is a known select-type false-positive (#2186);
# report it but do not fail the run on it (the projection VALUES are correct).
DRIFT=$(owner "php bin/console pim:catalog:detect-attributes-drift ${TENANT_FLAG}" 2>/dev/null | grep -oE '[0-9]+ drifted' | head -1 || true)
echo "residual_drift_after_reconcile = ${DRIFT:-0 drifted} (known false-positive on select values — #2186)"

exit $RC
