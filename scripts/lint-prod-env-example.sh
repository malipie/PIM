#!/usr/bin/env bash
# Guard: every fail-loud `${VAR:?...}` variable referenced by a compose file
# must be documented in its matching env template. A template that misses a
# required key means the documented deploy runbook cannot pass
# `docker compose config` — exactly the drift that stranded TRUSTED_HOSTS and
# the pgBackRest S3 keys before the 2026-08 go-live prep.
#
# Covers two pairs:
#   docker-compose.prod.yml   ↔ .env.prod.example      (produkcja)
#   docker-compose.tenant.yml ↔ .env.tenant.example    (epik TNT, #2858/#2859)
#   docker-compose.platform.yml ↔ .env.platform.example (epik TNT, #2903)
#
# The tenant pair earns its place the same way: JWT_PASSPHRASE was missing from
# the tenant stack on first write, and without a fail-loud declaration every
# login in a freshly provisioned instance would have answered 500.
#
# Read-only; exits non-zero listing the missing keys.
set -euo pipefail

cd "$(dirname "$0")/.."

missing=0
checked=0

check_pair() {
    local overlay="$1"
    local template="$2"

    if [ ! -f "$overlay" ]; then
        echo "SKIP: ${overlay} not present."
        return 0
    fi
    if [ ! -f "$template" ]; then
        echo "MISSING template: ${template} (required by ${overlay})"
        missing=1
        return 0
    fi

    # Collect VAR names from ${VAR:?...} occurrences in the overlay.
    # Comment lines are skipped — they quote the `${VAR:?message}` idiom itself.
    local required
    required=$(grep -vE '^\s*#' "$overlay" | grep -oE '\$\{[A-Z0-9_]+:\?' | sed -E 's/\$\{([A-Z0-9_]+):\?/\1/' | sort -u)

    local var
    for var in $required; do
        # Documented = appears at line start as KEY= or as commented template
        # (# KEY=...) in the example file.
        if ! grep -qE "^#? ?${var}=" "$template"; then
            echo "MISSING in ${template}: ${var} (required fail-loud by ${overlay})"
            missing=1
        fi
    done

    echo "OK: $(echo "$required" | wc -l | tr -d ' ') fail-loud variables of ${overlay} are documented in ${template}."
    checked=$((checked + 1))
}

check_pair "docker-compose.prod.yml" ".env.prod.example"
check_pair "docker-compose.tenant.yml" ".env.tenant.example"
check_pair "docker-compose.platform.yml" ".env.platform.example"

if [ "$missing" -ne 0 ]; then
    echo "FAIL: an env template is out of sync with its compose file's required variables."
    exit 1
fi

echo "OK: ${checked} compose/template pair(s) in sync."
