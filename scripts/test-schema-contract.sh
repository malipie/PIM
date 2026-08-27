#!/usr/bin/env bash

# AUD-DATA-003 (#3019) — destructive only to one explicitly named probe table
# in the disposable/local database selected by the current Compose project.
# The opt-in prevents an operator from casually running it on a real tenant.

set -euo pipefail

if [ "${PIM_ALLOW_SCHEMA_DRIFT_PROBE:-}" != "1" ]; then
    echo "Refusing controlled DDL probe. Set PIM_ALLOW_SCHEMA_DRIFT_PROBE=1 only for an isolated CI/dev database." >&2
    exit 2
fi

probe_table="stab_schema_drift_probe"
probe_log="$(mktemp -t pim-schema-drift-probe.XXXXXX)"

cleanup() {
    docker compose exec -T database psql -U pim -d pim -v ON_ERROR_STOP=1 \
        -c "DROP TABLE IF EXISTS ${probe_table}" >/dev/null
    if [ -f "$probe_log" ]; then
        rm -- "$probe_log"
    fi
}
trap cleanup EXIT

docker compose exec -T api php bin/console pim:db:schema:validate --no-interaction >/dev/null

existing="$(docker compose exec -T database psql -U pim -d pim -Atc \
    "SELECT to_regclass('public.${probe_table}') IS NOT NULL")"
if [ "$existing" != "f" ]; then
    echo "Refusing: ${probe_table} already exists before the probe." >&2
    exit 2
fi

docker compose exec -T database psql -U pim -d pim -v ON_ERROR_STOP=1 \
    -c "CREATE TABLE ${probe_table} (id integer PRIMARY KEY)" >/dev/null

set +e
docker compose exec -T api php bin/console pim:db:schema:validate --no-interaction \
    >"$probe_log" 2>&1
probe_status=$?
set -e

if [ "$probe_status" -eq 0 ]; then
    echo "Schema validator accepted a controlled unowned table." >&2
    exit 1
fi
if ! grep -q "Public tables without an owner: ${probe_table}" "$probe_log"; then
    echo "Schema validator failed, but did not identify the controlled drift." >&2
    sed -n '1,160p' "$probe_log" >&2
    exit 1
fi

cleanup
trap - EXIT

docker compose exec -T api php bin/console pim:db:schema:validate --no-interaction >/dev/null
echo "PASS: clean schema accepted, controlled drift rejected, cleanup accepted."
