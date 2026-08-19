#!/usr/bin/env bash
#
# Wdrożenie kodu na WSZYSTKIE instancje tenantów (epik TNT, #2863).
#
# Kolejność nie jest dowolna — to zapis kolejności, która sprawdziła się na
# produkcji, wraz z pułapkami, które ją wymusiły:
#
#   1. zrzut przed wdrożeniem (#2865)   — jedyna rzecz, która cofa złą migrację
#   2. build api + worker               — nowy obraz
#   3. migracje z NOWEGO obrazu         — `run --rm --no-deps`, zanim nowy kod
#                                         zacznie obsługiwać ruch. Zasada z
#                                         wdrożenia 2026-08-13: migrację robi
#                                         się PRZED `up -d`, niezależnie od
#                                         tego, co pokazuje diff bieżącej partii
#   4. up -d                            — nowe kontenery
#   5. cache:clear OSOBNO w api I worker — mają RÓŻNE katalogi cache
#                                         (/app/var/cache vs /app/var/cache-worker).
#                                         Pominięcie workera kosztowało 40 minut
#                                         przy #2821: konsument startował na
#                                         kontenerze DI sprzed wdrożenia
#   6. restart api worker               — wolumen /app/var przesłania DI z obrazu
#   7. smoke                            — instancja odpowiada, zanim ruszy kolejna
#
# FAIL-FAST: błąd na pierwszej instancji zatrzymuje przebieg. Zła migracja ma
# paść na jednym kliencie, nie na wszystkich po kolei.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.tenant.yml"

only=""
skip_dump=false
dry_run=false
tag=""

usage() {
    cat <<'USAGE'
Wdraża bieżący kod na wszystkie instancje tenantów.

Opcje:
  --only <kod>    Tylko ta instancja (do ponowienia po awarii).
  --tag <tekst>   Znacznik kopii; domyślnie skrót bieżącego commita.
  --skip-dump     Pomiń zrzut przed wdrożeniem (WYŁĄCZNIE do diagnostyki —
                  bez kopii nie ma czym cofnąć złej migracji).
  --dry-run       Wypisz plan i listę instancji, nic nie zmieniaj.
  -h, --help      Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --only) only="${2:-}"; shift 2 ;;
        --tag) tag="${2:-}"; shift 2 ;;
        --skip-dump) skip_dump=true; shift ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[ -n "$tag" ] || tag="$(git rev-parse --short HEAD 2>/dev/null || echo nogit)"

# ── Lista instancji ─────────────────────────────────────────────────────────
#
# Instancja PLATFORMOWA jedzie razem z klientami, choć ma własny plik środowiska
# i własny plik Compose. Wykrywanie po samym `.env.tenant.*` cicho ją pomijało:
# wdrożenie meldowało sukces, klienci dostawali nowy kod, a panel operatora
# i provisioner zostawały na starym — bez żadnego sygnału. Ta sama klasa
# przeoczenia, co konfiguracja edge poza repozytorium (#2952).
tenants=()
if [ -n "$only" ]; then
    if [ "$only" = platform ]; then
        [ -f ".env.platform" ] || { echo "BŁĄD: brak .env.platform." >&2; exit 2; }
    else
        [ -f ".env.tenant.${only}" ] || { echo "BŁĄD: brak .env.tenant.${only}." >&2; exit 2; }
    fi
    tenants=("$only")
else
    # Platforma PIERWSZA: prowadzi rejestr i zleca provisioning, więc ma być na
    # nowym kodzie, zanim klienci zaczną się wdrażać.
    [ -f ".env.platform" ] && tenants+=("platform")
    for f in .env.tenant.*; do
        [ -f "$f" ] || continue
        case "$f" in *.example) continue ;; esac
        tenants+=("${f#.env.tenant.}")
    done
fi

if [ "${#tenants[@]}" -eq 0 ]; then
    echo "Brak instancji do wdrożenia (ani .env.platform, ani .env.tenant.*)." >&2
    exit 2
fi

echo "Wdrożenie ${tag} na ${#tenants[@]} instancji: ${tenants[*]}"
echo ""

if [ "$dry_run" = true ]; then
    cat <<EOF
PLAN (nic nie zostało zmienione). Dla każdej instancji, po kolei:
  1. zrzut przed wdrożeniem $([ "$skip_dump" = true ] && echo '(POMINIĘTY — --skip-dump)')
  2. build api worker
  3. migracje z nowego obrazu (run --rm --no-deps)
  4. up -d
  5. cache:clear w api ORAZ w worker
  6. restart api worker
  7. smoke
Przerwanie na pierwszym błędzie.
EOF
    exit 0
fi

deploy_one() {
    local tenant="$1"
    local env_file=".env.tenant.${tenant}"
    local project="pim-${tenant}"
    local compose_file="$COMPOSE_FILE"

    # Platforma ma własny plik Compose (mniejszy stack, za to z provisionerem)
    # i własny plik środowiska. Poza tym przebieg jest identyczny, więc nie ma
    # powodu na drugą funkcję, która zaraz rozjedzie się z tą.
    if [ "$tenant" = platform ]; then
        env_file=".env.platform"
        project="pim-platform"
        compose_file="docker-compose.platform.yml"
    fi

    dc() { docker compose -p "$project" --env-file "$env_file" -f "$compose_file" "$@"; }

    echo "── ${tenant} ──────────────────────────────────────────"

    if [ "$skip_dump" = true ]; then
        echo "  [1/7] zrzut POMINIĘTY"
    else
        echo "  [1/7] zrzut przed wdrożeniem"
        bash scripts/pim-tenant-dump.sh --code "$tenant" --label pre-deploy --tag "$tag" >/dev/null || {
            echo "  BŁĄD: zrzut się nie powiódł — wdrożenie na ${tenant} PRZERWANE." >&2
            return 20
        }
    fi

    echo "  [2/7] build api worker"
    dc build api worker >/dev/null 2>&1 || { echo "  BŁĄD: build nie powiódł się." >&2; return 30; }

    # Migracje z NOWEGO obrazu, zanim nowe kontenery zaczną obsługiwać ruch.
    echo "  [3/7] migracje (nowy obraz, przed up -d)"
    dc run --rm --no-deps api php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null 2>&1 \
        || { echo "  BŁĄD: migracje nie przeszły." >&2; return 40; }

    echo "  [4/7] up -d"
    dc up -d >/dev/null 2>&1 || { echo "  BŁĄD: kontenery nie wstały." >&2; return 30; }

    # OBA kontenery — mają różne kernel.cache_dir.
    echo "  [5/7] cache:clear w api i w worker"
    dc exec -T api php bin/console cache:clear >/dev/null 2>&1 \
        || { echo "  BŁĄD: cache:clear w api nie powiódł się." >&2; return 50; }
    dc exec -T worker php bin/console cache:clear >/dev/null 2>&1 \
        || { echo "  BŁĄD: cache:clear w worker nie powiódł się." >&2; return 50; }

    echo "  [6/7] restart api worker"
    dc restart api worker >/dev/null 2>&1 || { echo "  BŁĄD: restart nie powiódł się." >&2; return 30; }

    echo "  [7/7] smoke"
    local tries=30 status=""
    while [ "$tries" -gt 0 ]; do
        status="$(dc exec -T api curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/api 2>/dev/null || true)"
        [ "$status" = "200" ] && break
        tries=$((tries - 1))
        sleep 2
    done
    if [ "$status" != "200" ]; then
        echo "  BŁĄD: GET /api zwróciło '${status}' zamiast 200." >&2
        return 60
    fi

    # Endpoint domenowy bez tokenu MUSI odmówić. Gdyby wdrożenie rozstroiło
    # security, zwróciłby 200 — i wyglądałoby to na sukces.
    local unauth
    unauth="$(dc exec -T api curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/api/objects 2>/dev/null || true)"
    if [ "$unauth" != "401" ]; then
        echo "  BŁĄD: /api/objects bez tokenu zwróciło '${unauth}' zamiast 401." >&2
        return 60
    fi
    echo "        GET /api = 200, /api/objects bez tokenu = 401"

    # Wiadomości, które padły w oknie wdrożenia, zostają w kolejce failed i po
    # naprawie WYKONAŁYBY SIĘ PONOWNIE (na produkcji był to powtórny import).
    local failed_count
    failed_count="$(dc exec -T api php bin/console messenger:failed:show 2>/dev/null | grep -cE '^ [0-9]+' || true)"
    if [ -n "$failed_count" ] && [ "$failed_count" != "0" ]; then
        echo "  UWAGA: ${failed_count} wiadomości w kolejce failed — sprawdź przed ponowieniem"
        echo "         (messenger:failed:show / :remove; ponowione wykonają się drugi raz)"
    fi

    echo "  OK"
    return 0
}

for tenant in "${tenants[@]}"; do
    # `if ! deploy_one` NIE nadaje się tutaj: wewnątrz takiego bloku `$?` jest
    # statusem NEGACJI (czyli 0, gdy funkcja zawiodła), więc orkiestrator
    # kończył się kodem 0 mimo przerwanego wdrożenia — meldował sukces po
    # nieudanym zrzucie. Kod trzeba przechwycić z samego wywołania.
    rc=0
    deploy_one "$tenant" || rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "" >&2
        echo "WDROŻENIE PRZERWANE na instancji '${tenant}' (kod ${rc})." >&2
        echo "Pozostałe instancje NIE zostały ruszone — to jest zamierzone." >&2
        echo "Po naprawie ponów wyłącznie tę instancję: scripts/pim-deploy-all.sh --only ${tenant}" >&2
        exit "$rc"
    fi
done

echo ""
echo "Wdrożono ${tag} na ${#tenants[@]} instancji."
echo "Bundel SPA jest współdzielony i aktualizuje się osobno — pamiętaj o oknie,"
echo "w którym nowy front rozmawia jeszcze ze starym API."
