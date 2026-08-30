#!/usr/bin/env bash
# AUD-OBS-002 (#3026) — ensure pg_stat_statements on fresh and existing DBs.
#
# shared_preload_libraries is a server-start setting supplied by compose.
# CREATE EXTENSION is database-local, so this helper waits for PostgreSQL and
# runs on every container boot. The operation is idempotent. `--check` is the
# healthcheck contract: the module must be preloaded, the extension installed,
# and its view queryable.
set -euo pipefail

DB_NAME="${POSTGRES_DB:-pim}"
SUPERUSER="${POSTGRES_USER:-postgres}"
LOG=/var/log/pgbackrest/init-query-stats.log

if [[ ! "$DB_NAME" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] \
    || [[ ! "$SUPERUSER" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "pim-init-query-stats: POSTGRES_DB/POSTGRES_USER must be PostgreSQL identifiers" >&2
    exit 2
fi

owner_psql() {
    su -s /bin/sh postgres -c "psql --no-psqlrc -v ON_ERROR_STOP=1 -U '${SUPERUSER}' -d '${DB_NAME}' $*"
}

check() {
    owner_psql "-qAtc \"SELECT 1
        FROM pg_extension
        WHERE extname = 'pg_stat_statements'
          AND 'pg_stat_statements' = ANY (
              string_to_array(replace(current_setting('shared_preload_libraries'), ' ', ''), ',')
          )
          AND (SELECT stats_reset IS NOT NULL FROM pg_stat_statements_info);\"" 2>/dev/null \
        | grep -qx '1'
}

if [ "${1:-}" = "--check" ]; then
    check
    exit $?
fi

mkdir -p "$(dirname "$LOG")"
chown postgres:postgres "$(dirname "$LOG")" || true

log() {
    printf '[%s] pim-init-query-stats: %s\n' "$(date -u +%FT%TZ)" "$*" | tee -a "$LOG"
}

log "waiting for postgres to accept connections"
for _ in $(seq 1 60); do
    if su -s /bin/sh postgres -c "pg_isready -h /var/run/postgresql -p 5432 -d '${DB_NAME}'" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! su -s /bin/sh postgres -c "pg_isready -h /var/run/postgresql -p 5432 -d '${DB_NAME}'" >/dev/null 2>&1; then
    log "postgres did not become ready within timeout"
    exit 1
fi

preloaded="$(owner_psql '-qAtc "SHOW shared_preload_libraries;"' 2>>"$LOG" || true)"
if ! owner_psql "-qAtc \"SELECT 1 WHERE 'pg_stat_statements' = ANY (
    string_to_array(replace(current_setting('shared_preload_libraries'), ' ', ''), ',')
);\"" 2>>"$LOG" | grep -qx '1'; then
    log "shared_preload_libraries does not contain pg_stat_statements (got: ${preloaded:-empty})"
    exit 1
fi

log "waiting for writable recovery state before ensuring pg_stat_statements in ${DB_NAME}"
installed=false
for _ in $(seq 1 60); do
    if owner_psql '-qAtc "SELECT NOT pg_is_in_recovery();"' 2>>"$LOG" | grep -qx 't'; then
        if owner_psql '-qc "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"' >>"$LOG" 2>&1 \
            && check; then
            installed=true
            break
        fi
    fi
    sleep 2
done

if ${installed}; then
    log "extension installed and query statistics view is usable"
else
    log "extension health contract failed after recovery wait"
    exit 1
fi
