#!/usr/bin/env bash
# Runtime contract for AUD-OBS-002 (#3026).
#
# Builds the database image and uses a unique container + volume, so running
# this test never restarts the developer stack. It proves preload, every-boot
# extension bootstrap, counter collection and save=on persistence across a
# real PostgreSQL restart.
set -euo pipefail

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
suffix="$$"
image="pim-database:pgstats-test-${suffix}"
container="pim-pgstats-test-${suffix}"
volume="pim_pgstats_test_${suffix}"

cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    docker image rm "$image" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

start_database() {
    docker run -d --name "$container" \
        -e POSTGRES_DB=pim_stats \
        -e POSTGRES_USER=pim \
        -e POSTGRES_PASSWORD=test-password \
        -e APP_DB_USER=pim_app \
        -e APP_DB_PASSWORD=app-test-password \
        -v "${volume}:/var/lib/postgresql/data" \
        "$image" postgres \
        -c shared_preload_libraries=pg_stat_statements \
        -c pg_stat_statements.max=10000 \
        -c pg_stat_statements.track=top \
        -c pg_stat_statements.track_utility=off \
        -c pg_stat_statements.save=on >/dev/null

    for _ in $(seq 1 60); do
        if docker exec "$container" /usr/local/bin/pim-init-query-stats.sh --check >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    docker logs "$container" >&2 || true
    echo "test-postgres-query-stats: database did not satisfy the query-statistics health contract" >&2
    return 1
}

docker build -q -t "$image" "$ROOT/docker/postgres" >/dev/null
docker volume create "$volume" >/dev/null
start_database

settings="$(docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
    "SELECT current_setting('pg_stat_statements.max') || '|' ||
            current_setting('pg_stat_statements.track') || '|' ||
            current_setting('pg_stat_statements.track_utility') || '|' ||
            current_setting('pg_stat_statements.save');")"
[ "$settings" = '10000|top|off|on' ] \
    || { echo "test-postgres-query-stats: unexpected settings: $settings" >&2; exit 1; }

reset_output="$("$ROOT/scripts/postgres-query-stats.sh" --container "$container" --database pim_stats --reset)"
case "$reset_output" in
    reset_at=*) ;;
    *) echo "test-postgres-query-stats: reset did not return its timestamp" >&2; exit 1 ;;
esac

reset_before="$(docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
    'SELECT stats_reset FROM pg_stat_statements_info;')"

for _ in 1 2 3; do
    docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
        'SELECT count(*) FROM generate_series(1, 1000) AS n;' >/dev/null
done

calls_before="$(docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
    "SELECT calls FROM pg_stat_statements
     WHERE query LIKE 'SELECT count(*) FROM generate_series%'
     ORDER BY calls DESC LIMIT 1;")"
[ "$calls_before" = 3 ] \
    || { echo "test-postgres-query-stats: expected 3 calls before restart, got ${calls_before:-none}" >&2; exit 1; }

report="$("$ROOT/scripts/postgres-query-stats.sh" --container "$container" --database pim_stats --limit 5)"
printf '%s\n' "$report" | grep -Fq '| queryid | calls | total exec ms | mean exec ms | rows | total share |' \
    || { echo "test-postgres-query-stats: report header is incomplete" >&2; exit 1; }
printf '%s\n' "$report" | grep -Fq '| `'

docker stop "$container" >/dev/null
docker rm "$container" >/dev/null
start_database

reset_after="$(docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
    'SELECT stats_reset FROM pg_stat_statements_info;')"
calls_after="$(docker exec "$container" psql -U pim -d pim_stats -X -qAtc \
    "SELECT calls FROM pg_stat_statements
     WHERE query LIKE 'SELECT count(*) FROM generate_series%'
     ORDER BY calls DESC LIMIT 1;")"

[ "$reset_after" = "$reset_before" ] \
    || { echo "test-postgres-query-stats: stats_reset changed across restart" >&2; exit 1; }
[ "$calls_after" = 3 ] \
    || { echo "test-postgres-query-stats: counters did not survive restart (got ${calls_after:-none})" >&2; exit 1; }

echo "test-postgres-query-stats: preload, extension bootstrap and 3-call counter survived restart. Clean."
