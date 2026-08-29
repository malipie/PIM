#!/usr/bin/env bash
# AUD-OBS-002 (#3026) — versionable, redacted pg_stat_statements snapshot.
#
# Output is Markdown containing queryid and numeric counters only. SQL text is
# intentionally excluded: normalisation hides literals, but comments and
# dynamically assembled statements can still carry customer data or secrets.
set -euo pipefail

limit=20
reset=0
compose_args=()
container=""
database=""

usage() {
    cat <<'USAGE'
Usage: scripts/postgres-query-stats.sh [options]

Options:
  --compose-file FILE  Compose file (repeatable; default: docker-compose.yml)
  --env-file FILE      Compose env file, e.g. .env.tenant.acme
  --project NAME       Compose project, e.g. pim-acme
  --container NAME     Read a database container directly (diagnostics/tests)
  --database NAME      Database inside the selected cluster (default: POSTGRES_DB)
  --limit N            Number of fingerprints, 1..100 (default: 20)
  --reset              Reset counters for a clean before/after window
  -h, --help           Show this help

Examples:
  scripts/postgres-query-stats.sh > before.md
  scripts/postgres-query-stats.sh --reset
  scripts/postgres-query-stats.sh --project pim-acme \
    --compose-file docker-compose.tenant.yml \
    --env-file .env.tenant.acme > acme-before.md
USAGE
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --compose-file)
            [ "$#" -ge 2 ] || { echo "--compose-file requires a value" >&2; exit 2; }
            compose_args+=(--file "$2")
            shift 2
            ;;
        --env-file)
            [ "$#" -ge 2 ] || { echo "--env-file requires a value" >&2; exit 2; }
            compose_args+=(--env-file "$2")
            shift 2
            ;;
        --project)
            [ "$#" -ge 2 ] || { echo "--project requires a value" >&2; exit 2; }
            compose_args+=(--project-name "$2")
            shift 2
            ;;
        --container)
            [ "$#" -ge 2 ] || { echo "--container requires a value" >&2; exit 2; }
            container="$2"
            shift 2
            ;;
        --database)
            [ "$#" -ge 2 ] || { echo "--database requires a value" >&2; exit 2; }
            database="$2"
            shift 2
            ;;
        --limit)
            [ "$#" -ge 2 ] || { echo "--limit requires a value" >&2; exit 2; }
            limit="$2"
            shift 2
            ;;
        --reset)
            reset=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

case "$limit" in
    ''|*[!0-9]*) echo "--limit must be an integer from 1 to 100" >&2; exit 2 ;;
esac
if [ "$limit" -lt 1 ] || [ "$limit" -gt 100 ]; then
    echo "--limit must be an integer from 1 to 100" >&2
    exit 2
fi
if [ -n "$database" ] && [[ ! "$database" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "--database must be a PostgreSQL identifier" >&2
    exit 2
fi

if [ -n "$container" ] && [ "${#compose_args[@]}" -gt 0 ]; then
    echo "--container cannot be combined with compose selection options" >&2
    exit 2
fi

if [ -n "$container" ]; then
    db_exec=(docker exec -i "$container")
else
    db_exec=(docker compose "${compose_args[@]}" exec -T database)
fi

if [ "$reset" -eq 1 ]; then
    "${db_exec[@]}" sh -s -- "$database" <<'SH'
set -eu
database="${1:-$POSTGRES_DB}"
psql --no-psqlrc -v ON_ERROR_STOP=1 -qAt \
    -U "$POSTGRES_USER" -d "$database" <<'SQL'
SET pg_stat_statements.track = 'none';
WITH reset AS (
    SELECT pg_stat_statements_reset()
)
SELECT 'reset_at=' || stats_reset
FROM pg_stat_statements_info, reset;
SQL
SH
    exit 0
fi

"${db_exec[@]}" sh -s -- "$limit" "$database" <<'SH'
set -eu
limit="$1"
database="${2:-$POSTGRES_DB}"

metadata="$(psql --no-psqlrc -v ON_ERROR_STOP=1 -qAt -F '|' \
    -U "$POSTGRES_USER" -d "$database" \
    -c "SELECT current_database(), stats_reset, clock_timestamp() FROM pg_stat_statements_info;")"
IFS='|' read -r database stats_reset captured_at <<EOF
$metadata
EOF

printf '# PostgreSQL query-statements top %s\n\n' "$limit"
printf -- '- database: `%s`\n' "$database"
printf -- '- stats reset: `%s`\n' "$stats_reset"
printf -- '- captured at: `%s`\n' "$captured_at"
printf -- '- SQL text: omitted by policy; correlate through `queryid`\n\n'
printf '| queryid | calls | total exec ms | mean exec ms | rows | total share |\n'
printf '|---:|---:|---:|---:|---:|---:|\n'

psql --no-psqlrc -v ON_ERROR_STOP=1 -v report_limit="$limit" -qAt \
    -U "$POSTGRES_USER" -d "$database" <<'SQL'
SET pg_stat_statements.track = 'none';
WITH eligible AS (
    SELECT queryid,
           calls,
           total_exec_time,
           mean_exec_time,
           rows
    FROM pg_stat_statements
    WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
      AND queryid IS NOT NULL
      AND query NOT ILIKE '%pg_stat_statements%'
), ranked AS (
    SELECT *,
           sum(total_exec_time) OVER () AS all_exec_time
    FROM eligible
)
SELECT format(
           '| `%s` | %s | %s | %s | %s | %s%% |',
           queryid,
           calls,
           round(total_exec_time::numeric, 3),
           round(mean_exec_time::numeric, 3),
           rows,
           round(100 * total_exec_time::numeric / NULLIF(all_exec_time::numeric, 0), 2)
       )
FROM ranked
ORDER BY total_exec_time DESC
LIMIT :report_limit;
SQL
SH
