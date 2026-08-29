#!/usr/bin/env bash
# AUD-OBS-002 (#3026) — fail-closed wiring guard for pg_stat_statements.
#
# The extension needs two independent pieces: a server restart with the module
# in shared_preload_libraries and CREATE EXTENSION in every database. Missing
# either half leaves a system that looks configured but cannot produce query
# statistics. Keep all three deployable topologies and the every-boot bootstrap
# in one cheap CI guard.
set -eu

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$ROOT"

status=0
fail() { echo "lint-postgres-query-stats: $*" >&2; status=1; }

for compose in docker-compose.yml docker-compose.tenant.yml docker-compose.platform.yml; do
    [ -f "$compose" ] || { fail "missing topology: $compose"; continue; }

    for setting in \
        'shared_preload_libraries=pg_stat_statements' \
        'pg_stat_statements.max=10000' \
        'pg_stat_statements.track=top' \
        'pg_stat_statements.track_utility=off' \
        'pg_stat_statements.save=on'; do
        if ! grep -Fq -- "$setting" "$compose"; then
            fail "$compose does not pin $setting"
        fi
    done

    if ! grep -Fq '/usr/local/bin/pim-init-query-stats.sh --check' "$compose"; then
        fail "$compose healthcheck does not require the extension contract"
    fi
done

DOCKERFILE=docker/postgres/Dockerfile
ENTRYPOINT=docker/postgres/start-pim.sh
BOOTSTRAP=docker/postgres/pim-init-query-stats.sh
REPORT=scripts/postgres-query-stats.sh
RUNTIME_TEST=scripts/test-postgres-query-stats.sh

for file in "$DOCKERFILE" "$ENTRYPOINT" "$BOOTSTRAP" "$REPORT" "$RUNTIME_TEST"; do
    [ -f "$file" ] || fail "expected file missing: $file"
done
[ "$status" -eq 0 ] || exit 1

grep -Fq 'COPY pim-init-query-stats.sh /usr/local/bin/pim-init-query-stats.sh' "$DOCKERFILE" \
    || fail "$DOCKERFILE does not copy the bootstrap"
grep -Fq '/usr/local/bin/pim-init-query-stats.sh &' "$ENTRYPOINT" \
    || fail "$ENTRYPOINT does not run the bootstrap on every start"
grep -Eq 'CREATE[[:space:]]+EXTENSION[[:space:]]+IF[[:space:]]+NOT[[:space:]]+EXISTS[[:space:]]+pg_stat_statements' "$BOOTSTRAP" \
    || fail "$BOOTSTRAP does not idempotently create the extension"
grep -Fq 'shared_preload_libraries' "$BOOTSTRAP" \
    || fail "$BOOTSTRAP does not verify the preload before creating the extension"
grep -Fq 'pg_stat_statements.save=on' "$RUNTIME_TEST" \
    || fail "$RUNTIME_TEST does not exercise restart persistence"

for column in queryid calls total_exec_time mean_exec_time rows; do
    grep -Eq "(^|[^[:alnum:]_])${column}([^[:alnum:]_]|$)" "$REPORT" \
        || fail "$REPORT does not report $column"
done

# Query text is deliberately absent from versionable output. Normalisation
# replaces literals, but SQL comments and dynamically assembled statements can
# still contain customer data or secrets.
if grep -Eq 'left\([[:space:]]*query|query_head|SELECT.*[^[:alnum:]_]query([[:space:],]|$)' "$REPORT"; then
    fail "$REPORT exposes SQL text; snapshots must contain queryid + numeric fields only"
fi

if [ "$status" -eq 0 ]; then
    echo "lint-postgres-query-stats: preload, bootstrap, healthchecks and redacted top-20 report are wired. Clean."
fi

exit "$status"
