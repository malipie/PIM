#!/usr/bin/env bash
# Vitest gate: a green suite must also keep stderr empty. React warnings such
# as "not wrapped in act(...)" do not change Vitest's exit code on their own.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
stdout_log="$(mktemp)"
stderr_log="$(mktemp)"
trap 'rm -f "$stdout_log" "$stderr_log"' EXIT

if (( $# > 0 )); then
    command=("$@")
else
    command=(pnpm --filter @pim/admin test)
fi

set +e
(
    cd "$repo_root"
    "${command[@]}"
) >"$stdout_log" 2>"$stderr_log"
status=$?
set -e

cat "$stdout_log"
cat "$stderr_log" >&2

if (( status != 0 )); then
    exit "$status"
fi

if [[ -s "$stderr_log" ]]; then
    echo "test-frontend-stderr: testy przeszly, ale stderr nie jest pusty." >&2
    echo "Usun warning albo napraw brakujace act(...); nie dodawaj allowlisty." >&2
    exit 1
fi

echo "test-frontend-stderr: Vitest green, stderr empty. Clean."
