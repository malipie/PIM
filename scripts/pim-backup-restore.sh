#!/usr/bin/env bash
#
# PIM database PITR restore orchestrator (Sprint 0 stub, ticket #15).
#
# Wraps the pgBackRest restore lifecycle so an operator can do "snap back to
# timestamp T" without remembering the docker-compose dance:
#   1. Stop the api container — FrankenPHP holds long-lived connections to
#      postgres and would block postgres shutdown / refuse the restore.
#   2. Stop the database container.
#   3. Wipe $PGDATA and run `pgbackrest restore` against the existing volume
#      via `docker compose run --rm --entrypoint` so we reuse the volume mount,
#      env vars and config from docker-compose.yml — no second source of truth.
#   4. Start the database back up. archive_recovery happens transparently.
#   5. Start the api back up.
#
# Production runbook (full a11y, alerting, rollback path) lives in
# docs/runbook/restore.md and Project Plan/02-plan-projektu-pim.md §0.11.8.
#
# Usage:
#   scripts/pim-backup-restore.sh [--type latest|time|immediate] [--target "YYYY-MM-DD HH:MM:SS+00"]
#                                 [--no-confirm] [--dry-run]
#
# Examples:
#   # Snap to the most recent backup label (default)
#   scripts/pim-backup-restore.sh
#
#   # Point-in-time recovery to 2026-04-28 10:15:00 UTC
#   scripts/pim-backup-restore.sh --type time --target "2026-04-28 10:15:00+00"
#
#   # Restore but stop replay as soon as consistency is reached (fastest)
#   scripts/pim-backup-restore.sh --type immediate

set -euo pipefail

TYPE="${TYPE:-default}"
TARGET=""
CONFIRM=true
DRY_RUN=false
STANZA="${PGBACKREST_STANZA:-pim}"

usage() {
    sed -n '2,/^set -euo/p' "$0" | sed 's/^# \?//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --type) TYPE="$2"; shift 2 ;;
        --target) TARGET="$2"; shift 2 ;;
        --no-confirm) CONFIRM=false; shift ;;
        --dry-run) DRY_RUN=true; shift ;;
        -h|--help) usage 0 ;;
        *) echo "Unknown flag: $1" >&2; usage 2 ;;
    esac
done

PGBACKREST_ARGS=("--stanza=${STANZA}")
case "${TYPE}" in
    default|latest) ;;  # use the most recent backup label
    immediate) PGBACKREST_ARGS+=("--type=immediate") ;;
    time)
        if [[ -z "${TARGET}" ]]; then
            echo "--type time requires --target 'YYYY-MM-DD HH:MM:SS[+TZ]'" >&2
            exit 2
        fi
        # --target-action=promote so Postgres finishes recovery read-WRITE
        # instead of pausing in read-only hot-standby (a plain --type=time
        # leaves the cluster paused until pg_promote(); the api cannot write).
        PGBACKREST_ARGS+=("--type=time" "--target=${TARGET}" "--target-action=promote")
        ;;
    *) echo "Unsupported --type '${TYPE}' (use latest|time|immediate)" >&2; exit 2 ;;
esac
PGBACKREST_ARGS+=("restore")

cat <<EOF
==> PIM database restore plan
    Stanza:       ${STANZA}
    Type:         ${TYPE}${TARGET:+ (target: ${TARGET})}
    pgBackRest:   pgbackrest ${PGBACKREST_ARGS[*]}

    THIS WILL DESTROY THE CURRENT POSTGRES DATA DIRECTORY.
    Steps: stop api -> stop database -> wipe \$PGDATA -> pgbackrest restore -> start database -> start api
EOF

if ${CONFIRM} && ! ${DRY_RUN}; then
    read -r -p "Type 'restore' to continue: " ack
    if [[ "${ack}" != "restore" ]]; then
        echo "Aborted." >&2
        exit 1
    fi
fi

run() {
    if ${DRY_RUN}; then
        printf '   [dry-run] %s\n' "$*"
    else
        echo "==> $*"
        "$@"
    fi
}

# 1+2. Quiesce — api first (drops connections cleanly), then postgres.
run docker compose stop api
run docker compose stop database

# 3. Wipe + restore inside a one-shot container that reuses the database
#    service's image, volumes and env vars. --entrypoint "" plus a custom
#    command lets us shell in and run pgbackrest as the postgres user.
# Double-quote every pgbackrest arg. The invocation is nested inside
# `su -s /bin/sh postgres -c '...'` (single-quoted), so double quotes here are
# literal to that inner shell and keep a --target value containing a space (the
# "YYYY-MM-DD HH:MM:SS" timestamp) as ONE argument. `${ARR[*]}` string-joins and
# silently splits it, which pgBackRest rejects with "invalid command '<time>'".
QUOTED_ARGS=""
for arg in "${PGBACKREST_ARGS[@]}"; do
    QUOTED_ARGS+=" \"${arg}\""
done
RESTORE_CMD="rm -rf /var/lib/postgresql/data/* /var/lib/postgresql/data/.[!.]* 2>/dev/null; "
RESTORE_CMD+="exec su -s /bin/sh postgres -c 'pgbackrest${QUOTED_ARGS}'"

run docker compose run --rm --no-deps --entrypoint /bin/sh database -c "${RESTORE_CMD}"

# 4. Bring postgres back. archive_command resumes as soon as postgres starts.
run docker compose up -d --wait database

# 4b. Re-grant the runtime role. A physical restore can land the cluster with
# the pre-W1-1 grant state (or the app role's schema grants otherwise dropped);
# the W1-1 migration does NOT re-run on a restore, so `pim_app` would hit
# "permission denied for schema public". Idempotent — safe on every restore.
if ! ${DRY_RUN}; then
    echo "==> Re-granting pim_app (post-restore quirk)"
    docker compose exec -T database psql -U "${POSTGRES_USER:-pim}" -d "${POSTGRES_DB:-pim}" <<'SQL' || true
GRANT USAGE ON SCHEMA public TO pim_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pim_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO pim_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pim_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO pim_app;
SQL
fi

# 5. Bring api back. The --wait flag blocks until healthchecks pass.
run docker compose up -d --wait api

cat <<EOF

==> Restore complete.
    Verify with:
      docker compose exec database psql -U \${POSTGRES_USER:-pim} -d \${POSTGRES_DB:-pim} -c '\\dt'
      curl -k https://pim.localhost/api/products | head
EOF
