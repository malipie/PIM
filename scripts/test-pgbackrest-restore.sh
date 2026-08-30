#!/usr/bin/env bash
#
# Sprint 0 ticket #15 — DoD acceptance test for pgBackRest restore.
#
# Scenario (mirrors the issue checklist):
#   1. Count baseline products in the demo tenant.
#   2. Insert N marker products via the API (so the data has a known shape).
#   3. Trigger an on-demand pgBackRest backup (skips the 1h cron wait).
#   4. Drop those marker products to simulate data loss.
#   5. Restore to the end of that backup (before the deletion WAL).
#   6. Re-count products. Pass = post-restore count == baseline + N (markers
#      came back from the backup).
#
# This script is the executable form of the runbook test. Its output is what
# we attach to the ticket as "restore test passing".

set -euo pipefail

TENANT="${TENANT:-demo}"
BASE_URL="${BASE_URL:-https://pim.localhost}"
ADMIN_EMAIL="admin@${TENANT}.localhost"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-changeme}"
MARKER_COUNT="${MARKER_COUNT:-3}"
MARKER_PREFIX="restore-test-$(date -u +%s)"

mint_token() {
    curl --silent --insecure --fail \
        --request POST "${BASE_URL}/api/auth/login" \
        --header 'content-type: application/json' \
        --data "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}" \
        | python3 -c "import sys, json; print(json.load(sys.stdin)['token'])"
}

product_count() {
    local token="$1"
    curl --silent --insecure --fail \
        --header "authorization: Bearer ${token}" \
        --header 'accept: application/ld+json' \
        "${BASE_URL}/api/products?page=1" \
        | python3 -c "import sys, json; print(json.load(sys.stdin).get('totalItems', 0))"
}

product_object_type_id() {
    local token="$1"
    curl --silent --show-error --insecure --fail \
        --header "authorization: Bearer ${token}" \
        --header 'accept: application/ld+json' \
        "${BASE_URL}/api/products?page=1&itemsPerPage=1" \
        | python3 -c "import sys, json; data=json.load(sys.stdin); members=data.get('member', data.get('hydra:member', [])); print(members[0]['objectType']['id'])"
}

create_product() {
    local token="$1" code="$2" object_type_id="$3"
    local response
    response=$(curl --silent --show-error --insecure --fail \
        --request POST "${BASE_URL}/api/products" \
        --header "authorization: Bearer ${token}" \
        --header 'content-type: application/ld+json' \
        --data "{\"code\":\"${code}\",\"objectTypeId\":\"${object_type_id}\",\"attributes\":{}}")

    python3 -c "import sys, json; expected=sys.argv[1]; actual=json.load(sys.stdin).get('code'); assert actual == expected, f'Expected created code {expected!r}, got {actual!r}'" "${code}" <<< "${response}"
}

echo "==> Logging in as ${ADMIN_EMAIL}"
TOKEN=$(mint_token)
[[ -n "${TOKEN}" ]] || { echo "Failed to mint JWT" >&2; exit 1; }

BASELINE=$(product_count "${TOKEN}")
echo "==> Baseline product count for tenant '${TENANT}': ${BASELINE}"

OBJECT_TYPE_ID=$(product_object_type_id "${TOKEN}")
[[ -n "${OBJECT_TYPE_ID}" ]] || { echo "Failed to resolve a product object type" >&2; exit 1; }

echo "==> Inserting ${MARKER_COUNT} marker products with prefix '${MARKER_PREFIX}'"
for i in $(seq 1 "${MARKER_COUNT}"); do
    create_product "${TOKEN}" "${MARKER_PREFIX}-${i}" "${OBJECT_TYPE_ID}"
done

PRE_BACKUP=$(product_count "${TOKEN}")
echo "==> Post-insert product count: ${PRE_BACKUP} (expected ${BASELINE} + ${MARKER_COUNT})"
if [[ "${PRE_BACKUP}" != "$((BASELINE + MARKER_COUNT))" ]]; then
    echo "Insert step failed — count mismatch. Aborting." >&2
    exit 1
fi

echo "==> Triggering on-demand pgBackRest backup (skipping cron wait)"
docker compose exec -T database \
    su -s /bin/sh postgres -c "pgbackrest --stanza=pim --type=incr backup"

# Wait briefly so the backup is fully visible in the repo before we destroy
# the markers — pgbackrest's archive-async worker may still be flushing WAL.
sleep 2

echo "==> Forcing a WAL switch so the backup label segment is archived"
docker compose exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-ChangeMeInDev}" database \
    psql -U "${POSTGRES_USER:-pim}" -d "${POSTGRES_DB:-pim}" -c "SELECT pg_switch_wal();" >/dev/null

echo "==> Simulating data loss: deleting marker products"
docker compose exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-ChangeMeInDev}" database \
    psql -U "${POSTGRES_USER:-pim}" -d "${POSTGRES_DB:-pim}" \
    -c "DELETE FROM objects WHERE kind = 'product' AND code LIKE '${MARKER_PREFIX}-%';"

POST_DELETE=$(product_count "${TOKEN}")
echo "==> Post-delete product count: ${POST_DELETE} (expected ${BASELINE})"
if [[ "${POST_DELETE}" != "${BASELINE}" ]]; then
    echo "Delete step failed — markers not removed. Aborting." >&2
    exit 1
fi

echo "==> Running scripts/pim-backup-restore.sh --type immediate --no-confirm"
bash "$(dirname "$0")/pim-backup-restore.sh" --type immediate --no-confirm

echo "==> Waiting for api to settle"
for _ in $(seq 1 30); do
    if curl --silent --insecure --fail "${BASE_URL}/api" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

echo "==> Re-authenticating"
TOKEN=$(mint_token)

POST_RESTORE=$(product_count "${TOKEN}")
echo "==> Post-restore product count: ${POST_RESTORE} (expected ${PRE_BACKUP})"

if [[ "${POST_RESTORE}" == "${PRE_BACKUP}" ]]; then
    echo
    echo "==> RESTORE TEST PASSED — markers '${MARKER_PREFIX}-*' came back from the backup."
    exit 0
else
    echo
    echo "==> RESTORE TEST FAILED — expected ${PRE_BACKUP}, got ${POST_RESTORE}." >&2
    exit 1
fi
