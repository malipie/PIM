#!/usr/bin/env bash
# #2844 — the fast gate: run the tests your change can plausibly break,
# before pushing, instead of learning about it ~50 minutes into CI.
#
# CI reports its slowest shard last, so a single-file regression in the
# catalog suite surfaces after everything else has already gone green. That
# is not a throughput problem (#2843 halved the shard) — it is a *latency*
# problem, and the only way to remove the wait is to not start it.
#
# This maps changed paths onto the suites that cover them and runs those.
# It is a filter, not a replacement: it can only be wrong in one direction
# (missing something CI catches), never the other, so a green run here means
# "worth pushing", not "will pass CI".
#
# Usage:
#   pnpm ci:local              # changes vs origin/main
#   pnpm ci:local --staged     # only what is staged
#   pnpm ci:local --all        # every suite, no path mapping
#   pnpm ci:local --list       # print the plan and exit
#
# Deliberately NOT covered: Playwright (needs a seeded stack, minutes),
# Deptrac and the full PHPStan pass (whole-project, and CI's static leg is
# already the fastest thing on the board at ~2.5 min).

set -uo pipefail

cd "$(git rev-parse --show-toplevel)"

MODE="diff"
LIST_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --staged) MODE="staged" ;;
        --all) MODE="all" ;;
        --list) LIST_ONLY=1 ;;
        -h|--help) sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

case "$MODE" in
    staged) CHANGED=$(git diff --cached --name-only) ;;
    all)    CHANGED="" ;;
    diff)
        BASE=$(git merge-base HEAD origin/main 2>/dev/null || echo "")
        if [ -z "$BASE" ]; then
            echo "No origin/main to diff against — falling back to --staged." >&2
            CHANGED=$(git diff --cached --name-only)
        else
            # Committed on this branch plus whatever is still dirty: the
            # point is "what am I about to push", and uncommitted work is
            # about to become part of that.
            CHANGED=$(printf '%s\n%s\n' "$(git diff --name-only "$BASE")" "$(git diff --name-only)" | sort -u | sed '/^$/d')
        fi
        ;;
esac

# ── path → suite mapping ────────────────────────────────────────────────
# One rule per line: <regex> <tab> <phpunit paths, space separated>
# Bounded contexts follow a convention (src/<BC>/ ↔ tests/{Api,Integration}/<BC>),
# so the generic rule at the bottom catches new contexts without edits here.
# The specific rules above it exist where the convention is not enough.
PHP_SUITES=""
FRONTEND=0
MIGRATIONS=0

add_suite() {
    case " $PHP_SUITES " in
        *" $1 "*) ;;
        *) PHP_SUITES="$PHP_SUITES $1" ;;
    esac
}

if [ "$MODE" = "all" ]; then
    add_suite "tests"
    FRONTEND=1
else
    while IFS= read -r file; do
        [ -z "$file" ] && continue
        case "$file" in
            apps/admin/*) FRONTEND=1 ;;
            apps/api/migrations/*) MIGRATIONS=1; add_suite "tests/Integration" ;;

            # Permissions cut across contexts: a voter or a security
            # expression changed in Identity is routinely what breaks a
            # Catalog test. This is the exact shape that cost 50 minutes on
            # #2841 and again on #2845.
            #
            # Pulling in all of tests/Api/Catalog would be correct and far
            # too slow — that is the 9-minute shard. The cross-context risk
            # lives in the permission / access tests specifically, so those
            # are what gets added by name.
            apps/api/src/Identity/Infrastructure/Security/*|apps/api/src/Identity/Contracts/*|apps/api/src/*/Infrastructure/ApiPlatform/Resource/*)
                add_suite "tests/Api/Identity"
                add_suite "tests/Integration/Identity"
                for t in apps/api/tests/Api/*/*Permission*.php apps/api/tests/Api/*/*Access*.php apps/api/tests/Api/*/*Voter*.php; do
                    [ -f "$t" ] && add_suite "${t#apps/api/}"
                done
                ;;

            apps/api/src/*)
                bc=$(printf '%s' "$file" | sed -n 's|^apps/api/src/\([A-Za-z0-9]*\)/.*|\1|p')
                [ -n "$bc" ] && {
                    [ -d "apps/api/tests/Api/$bc" ] && add_suite "tests/Api/$bc"
                    [ -d "apps/api/tests/Integration/$bc" ] && add_suite "tests/Integration/$bc"
                }
                ;;

            apps/api/tests/*)
                # A changed test runs itself, whatever it covers.
                add_suite "${file#apps/api/}"
                ;;

            apps/api/config/*|apps/api/composer.json|apps/api/composer.lock)
                add_suite "tests/Api"
                ;;
        esac
    done <<EOF
$CHANGED
EOF
fi

PHP_SUITES=$(printf '%s' "$PHP_SUITES" | sed 's/^ *//')

echo "── plan ────────────────────────────────────────────────"
if [ -n "$PHP_SUITES" ]; then
    echo "PHPUnit : $PHP_SUITES"
else
    echo "PHPUnit : (nic — brak zmian w backendzie)"
fi
[ "$FRONTEND" -eq 1 ] && echo "Frontend: tsc --noEmit + vitest"
[ "$MIGRATIONS" -eq 1 ] && echo "Uwaga   : zmiana w migracjach — CI migruje bazę od zera, tego tu nie sprawdzam"
echo "────────────────────────────────────────────────────────"
[ "$LIST_ONLY" -eq 1 ] && exit 0

if [ -z "$PHP_SUITES" ] && [ "$FRONTEND" -eq 0 ]; then
    echo "Nic do uruchomienia."
    exit 0
fi

if ! docker compose ps api --format json 2>/dev/null | grep -q '"State":"running"'; then
    echo "Kontener api nie działa — uruchom 'pnpm stack:up'." >&2
    exit 1
fi

STATUS=0

if [ -n "$PHP_SUITES" ]; then
    echo
    echo "▶ PHPUnit"
    # `composer test` is the guarded entry point: it exports APP_ENV=test and
    # forces async transports to sync before Symfony or Foundry can boot.
    # shellcheck disable=SC2086
    docker compose exec -T api composer test -- $PHP_SUITES || STATUS=1
fi

if [ "$FRONTEND" -eq 1 ]; then
    echo
    echo "▶ typecheck"
    (cd apps/admin && pnpm exec tsc --noEmit) || STATUS=1
    echo
    echo "▶ vitest"
    (cd apps/admin && pnpm exec vitest run --passWithNoTests) || STATUS=1
fi

echo
if [ "$STATUS" -eq 0 ]; then
    echo "✅ Bramka lokalna zielona — warto pushować."
    echo "   (to nie jest obietnica zielonego CI: bez Playwrighta, Deptraca i pełnego PHPStana)"
else
    echo "❌ Bramka lokalna czerwona — napraw przed pushem."
fi
exit "$STATUS"
