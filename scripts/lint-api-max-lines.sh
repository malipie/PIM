#!/usr/bin/env bash
# GOLIVE #2192 — guard-rail against new backend source monoliths.
#
# The structure audit (#2120) found 10 api files over 500 lines with
# ImportRunHandler.php at 1913 (2× the runner-up). The admin side has had
# lint-admin-max-lines.sh since AUD-057; this is the PHP twin.
#
# Same policy: count src/ .php files whose length exceeds THRESHOLD and
# fail if the count exceeds a frozen baseline. Existing offenders are
# tolerated (WARN-style), any NEW file over the line — or a borderline
# file crossing it — turns CI red. Splitting a monolith below the line →
# lower the baseline. The number may only shrink.
#
# THRESHOLD is 800 (not the admin 500): idiomatic Symfony handlers and
# API Platform providers legitimately run longer than React components,
# and 800 still catches the monolith class the audit flagged. Baseline 1
# = ImportRunHandler.php — its split is tracked in #2192 and pending a
# dedicated refactor window (post-GOLIVE; not bundled with a guard).
set -eu

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
SRC="${ROOT}/apps/api/src"

THRESHOLD="${API_MAX_LINES_THRESHOLD:-800}"
BASELINE="${API_MAX_LINES_BASELINE:-1}"

if [ ! -d "$SRC" ]; then
    echo "lint-api-max-lines: source dir $SRC not found" >&2
    exit 1
fi

count=0
offenders=""
while IFS= read -r f; do
    lines=$(wc -l < "$f")
    if [ "$lines" -gt "$THRESHOLD" ]; then
        count=$((count + 1))
        offenders="$offenders\n  $lines  ${f#"$ROOT"/}"
    fi
done <<EOF
$(find "$SRC" -name '*.php' -type f)
EOF

if [ "$count" -gt "$BASELINE" ]; then
    echo "lint-api-max-lines: FAIL — $count files exceed ${THRESHOLD} lines (baseline $BASELINE)." >&2
    cat >&2 <<TXT

A PHP file grew past ${THRESHOLD} lines (or a new one shipped over it).
Split it into focused collaborators — extract a service, a value object,
or a dedicated step class — so the entry point only composes them.
TXT
    printf 'Files over %s lines:%b\n' "$THRESHOLD" "$offenders" | sort -rn >&2
    exit 1
fi

if [ "$count" -lt "$BASELINE" ]; then
    echo "lint-api-max-lines: $count files exceed ${THRESHOLD} lines — below baseline $BASELINE."
    echo "  Nice — lower BASELINE in this script to $count to lock in the win."
    exit 0
fi

echo "lint-api-max-lines: $count files exceed ${THRESHOLD} lines — at baseline $BASELINE. No regression."
exit 0
