#!/usr/bin/env bash
# Guard: every fail-loud `${VAR:?...}` variable referenced by the prod compose
# overlay must be documented in .env.prod.example. A template that misses a
# required key means the documented deploy runbook cannot pass
# `docker compose config` — exactly the drift that stranded TRUSTED_HOSTS and
# the pgBackRest S3 keys before the 2026-08 go-live prep.
#
# Read-only; exits non-zero listing the missing keys.
set -euo pipefail

cd "$(dirname "$0")/.."

overlay="docker-compose.prod.yml"
template=".env.prod.example"

# Collect VAR names from ${VAR:?...} occurrences in the overlay.
# Comment lines are skipped — they quote the `${VAR:?message}` idiom itself.
required=$(grep -vE '^\s*#' "$overlay" | grep -oE '\$\{[A-Z0-9_]+:\?' | sed -E 's/\$\{([A-Z0-9_]+):\?/\1/' | sort -u)

missing=0
for var in $required; do
    # Documented = appears at line start as KEY= or as commented template
    # (# KEY=...) in the example file.
    if ! grep -qE "^#? ?${var}=" "$template"; then
        echo "MISSING in ${template}: ${var} (required fail-loud by ${overlay})"
        missing=1
    fi
done

if [ "$missing" -ne 0 ]; then
    echo "FAIL: .env.prod.example is out of sync with the prod overlay's required variables."
    exit 1
fi

echo "OK: all $(echo "$required" | wc -l | tr -d ' ') fail-loud prod variables are documented in ${template}."
