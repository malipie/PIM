#!/usr/bin/env bash
#
# GOLIVE A2 (#2122) — pgBackRest point-in-time recovery (PITR) drill.
#
# Proves the backup is not just configured but RESTORABLE to a precise instant,
# measures RTO, and exercises the post-restore `pim_app` re-grant quirk
# (memory: db-restore-regrant-pim-app — after a physical restore the runtime
# role can lose schema grants; the W1-1 migration does NOT re-run).
#
# PITR precision test:
#   1. Take a backup (the restore base).
#   2. Insert a BEFORE marker; force a WAL switch; record T = now().
#   3. Insert an AFTER marker; force a WAL switch.
#   4. Restore --type=time --target=T (replays WAL up to T only).
#   5. Re-grant pim_app defensively.
#   6. Assert BEFORE marker present AND AFTER marker absent → PITR landed
#      exactly at T. Report RTO (restore wall-clock).
#
# Uses the canonical `objects` table (NOT the deprecated `products` table the
# Sprint-0 test-pgbackrest-restore.sh still targets — post-ObjectType that
# DELETE is a no-op, making that test falsely green).
#
# DESTRUCTIVE: wipes + restores the Postgres data dir. Everything committed
# AFTER T is intentionally lost (here: only the AFTER marker). Run on a dev DB.
#
# Usage: scripts/restore-drill.sh [--force]   (--force skips the confirmation)

set -euo pipefail
cd "$(dirname "$0")/.."

FORCE=0
[[ "${1:-}" == "--force" ]] && FORCE=1

PGUSER="${POSTGRES_USER:-pim}"
PGDB="${POSTGRES_DB:-pim}"
STAMP=$(date -u +%s)
BEFORE="drill-before-${STAMP}"
AFTER="drill-after-${STAMP}"

psql_owner() {
  docker compose exec -T database psql -U "$PGUSER" -d "$PGDB" -tA -c "$1"
}

if [[ "$FORCE" != "1" ]]; then
  echo "This runs a DESTRUCTIVE PITR restore of the dev database. Type 'drill' to continue:"
  read -r ack
  [[ "$ack" == "drill" ]] || { echo "Aborted."; exit 1; }
fi

# Resolve the built-in product ObjectType id + a tenant to attach markers to.
OTID=$(psql_owner "SELECT id FROM object_types WHERE kind='product' AND is_built_in=true LIMIT 1;")
TENANT_ID=$(psql_owner "SELECT tenant_id FROM object_types WHERE id='${OTID}';")
[[ -n "$OTID" && -n "$TENANT_ID" ]] || { echo "No built-in product ObjectType found." >&2; exit 1; }

insert_marker() {
  local code="$1"
  # tenant-safe: drill-only marker, explicit tenant_id + object_type_id.
  psql_owner "INSERT INTO objects (id, tenant_id, object_type_id, kind, code, status, attributes_indexed, created_at, updated_at)
    VALUES (gen_random_uuid(), '${TENANT_ID}', '${OTID}', 'product', '${code}', 'draft', '{}', now(), now());" >/dev/null
}
count_marker() { psql_owner "SELECT count(*) FROM objects WHERE code='${1}';"; }

echo "==> [1/6] Baseline backup (restore base)"
docker compose exec -T database su -s /bin/sh postgres -c "pgbackrest --stanza=pim --type=incr backup" 2>&1 | tail -1

echo "==> [2/6] Insert BEFORE marker + WAL switch, then record PITR target T"
insert_marker "$BEFORE"
psql_owner "SELECT pg_switch_wal();" >/dev/null
sleep 1
TARGET=$(psql_owner "SELECT now();")
echo "    T = ${TARGET}"
sleep 2

echo "==> [3/6] Insert AFTER marker (must NOT survive PITR to T) + WAL switch"
insert_marker "$AFTER"
psql_owner "SELECT pg_switch_wal();" >/dev/null
# Give pgBackRest's async archiver a moment to ship the segments.
sleep 3

echo "    pre-restore: BEFORE=$(count_marker "$BEFORE") AFTER=$(count_marker "$AFTER") (expect 1 / 1)"

echo "==> [4/6] PITR restore to T (measuring RTO)"
# The wrapper restores read-write (--target-action=promote) and re-grants
# pim_app itself; RTO = full stop -> wipe -> restore -> re-grant -> api-up.
RESTORE_START=$(date +%s)
./scripts/pim-backup-restore.sh --type time --target "${TARGET}" --no-confirm
RESTORE_END=$(date +%s)
RTO=$((RESTORE_END - RESTORE_START))

echo "==> [5/6] (promote + re-grant handled by the restore wrapper)"

echo "==> [6/6] Verify PITR precision"
B=$(count_marker "$BEFORE")
A=$(count_marker "$AFTER")
echo "    post-restore: BEFORE=${B} AFTER=${A} (expect 1 / 0)"
echo "    RTO = ${RTO}s"

# Confirm the app role can QUERY through the restored grants. Under FORCE RLS
# with no request-scoped tenant GUC, pim_app legitimately sees 0 rows — so the
# check is "query succeeds" (a missing grant would be 'permission denied for
# table objects'), NOT "a row comes back".
APP_READ=$(docker compose exec -T database psql -U pim_app -d "$PGDB" -tA -c "SELECT count(*) FROM objects;" 2>&1 || true)

RC=0
if [[ "$B" == "1" && "$A" == "0" ]]; then
  echo "==> PITR DRILL PASSED — recovered to T exactly (BEFORE kept, AFTER dropped)."
else
  echo "==> PITR DRILL FAILED — expected BEFORE=1/AFTER=0, got ${B}/${A}." >&2; RC=1
fi
if [[ "$APP_READ" =~ ^[0-9]+$ ]]; then
  echo "    pim_app grants OK after re-grant (query succeeded; RLS shows ${APP_READ} rows without a tenant GUC)."
else
  echo "    WARNING: pim_app query failed after re-grant: ${APP_READ}" >&2; RC=1
fi

# Clean up the surviving BEFORE marker so the drill leaves no residue.
psql_owner "DELETE FROM objects WHERE code IN ('${BEFORE}','${AFTER}');" >/dev/null 2>&1 || true

exit $RC
