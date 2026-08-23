#!/usr/bin/env bash
#
# Zrzut logiczny bazy tenanta (epik TNT, #2865).
#
# Uzupełnia pgBackRest (#2864), nie zastępuje go. Podział ról:
#   - pgBackRest  → odtworzenie do dowolnej sekundy (PITR), operacja na klastrze;
#   - ten zrzut   → „wróć do stanu sprzed wdrożenia" dla JEDNEGO tenanta,
#                    liczone w minutach i niezależne od stanu klastra.
#
# To jest ochrona przed błędną migracją. Separacja instancji sama w sobie
# niczego nie cofa — cofa kopia zrobiona, zanim migracja ruszyła.
#
# Użycie:
#   scripts/pim-tenant-dump.sh --code acme
#   scripts/pim-tenant-dump.sh --code acme --label pre-deploy --tag 8f3c1a9
#   scripts/pim-tenant-dump.sh --all --label pre-deploy --tag "$(git rev-parse --short HEAD)"
#   scripts/pim-tenant-dump.sh --code acme --print-path   # tylko ścieżka na stdout
#
# ── Retencja liczy się CZASEM, nie nazwą (#2993) ────────────────────────────
#
# Do 1075215c retencja robiła `find … | sort -r | tail -n +N+1`, czyli
# porządkowała po NAZWIE pliku. Nazwa to `<tenant>-<tag>-<stamp>.dump`, więc
# o kolejności decydował **skrót commita**, a nie czas. Przy wdrożeniu
# 1075215c skrót sortował się niżej niż wszystkie dziesięć zachowanych, więc
# świeżo zrobiony zrzut pre-deploy trafiał na pozycję 11 i był kasowany
# sekundy po utworzeniu — na wszystkich trzech instancjach naraz i w ciszy,
# bo orkiestrator wdrożenia kierował wyjście tego skryptu do /dev/null.
#
# Trzy zabezpieczenia, każde niezależne od pozostałych:
#   1. porządek po czasie modyfikacji (`ls -t`), nie po nazwie;
#   2. zrzut utworzony w TYM przebiegu nie jest kandydatem do usunięcia,
#      niezależnie od tego, co pokaże sortowanie;
#   3. kandydat należący do tenanta o dłuższym kodzie z tym samym początkiem
#      (`trzeci` vs `trzeci-tenant`) jest pomijany — glob `${tenant}-*` sam
#      z siebie ich nie rozróżnia.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.tenant.yml"
BACKUP_ROOT="backups"
MIN_DUMP_BYTES=1024

code=""
all=false
env_file_override=""
label="manual"
tag=""
keep=10
print_path=false

usage() {
    cat <<'USAGE'
Zrzut logiczny bazy tenanta.

Opcje:
  --code <kod>     Kod tenanta (albo --all).
  --all            Wszystkie instancje z plikami .env.tenant.*
  --env-file <plik> Plik środowiska; domyślnie .env.tenant.<kod>. Potrzebne dla
                   instancji, które nie trzymają go pod tą nazwą — na przykład
                   platformowej (.env.platform).
  --compose <plik> Plik Compose; domyślnie docker-compose.tenant.yml.
  --label <etyk.>  Podkatalog kopii; domyślnie manual (np. pre-deploy).
  --tag <tekst>    Znacznik w nazwie pliku, zwykle skrót commita.
  --keep <N>       Ile ostatnich kopii zachować w danej etykiecie; domyślnie 10.
  --print-path     Na stdout wyłącznie ścieżka powstałego zrzutu (po jednej na
                   linię przy --all); komunikaty dla człowieka idą na stderr.
                   Kontrakt dla skryptów: `[ -s "$(… --print-path)" ]` zamiast
                   parsowania tekstu.
  -h, --help       Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --code) code="${2:-}"; shift 2 ;;
        --all) all=true; shift ;;
        --env-file) env_file_override="${2:-}"; shift 2 ;;
        --compose) COMPOSE_FILE="${2:-}"; shift 2 ;;
        --label) label="${2:-}"; shift 2 ;;
        --tag) tag="${2:-}"; shift 2 ;;
        --keep) keep="${2:-}"; shift 2 ;;
        --print-path) print_path=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -z "$code" ] && [ "$all" != true ]; then
    echo "BŁĄD: podaj --code <kod> albo --all." >&2
    exit 2
fi

dump_one() {
    local tenant="$1"
    local env_file="${env_file_override:-.env.tenant.${tenant}}"
    local project="pim-${tenant}"

    if [ ! -f "$env_file" ]; then
        echo "BŁĄD [${tenant}]: brak ${env_file}." >&2
        return 2
    fi

    local db_name db_user
    db_name="$(grep -E '^POSTGRES_DB=' "$env_file" | tail -1 | cut -d= -f2- || true)"
    db_user="$(grep -E '^POSTGRES_USER=' "$env_file" | tail -1 | cut -d= -f2- || true)"
    [ -n "$db_name" ] || { echo "BŁĄD [${tenant}]: POSTGRES_DB nieustawione." >&2; return 2; }
    [ -n "$db_user" ] || db_user="pim"

    local dir="${BACKUP_ROOT}/${label}"
    mkdir -p "$dir"

    local stamp name path
    stamp="$(date +%Y%m%d-%H%M%S)"
    name="${tenant}-${stamp}"
    [ -n "$tag" ] && name="${tenant}-${tag}-${stamp}"
    path="${dir}/${name}.dump"

    if ! docker compose -p "$project" --env-file "$env_file" -f "$COMPOSE_FILE" \
            exec -T database pg_isready -U "$db_user" -d "$db_name" >/dev/null 2>&1; then
        echo "BŁĄD [${tenant}]: baza nie odpowiada — zrzut NIE powstał." >&2
        return 10
    fi

    if ! docker compose -p "$project" --env-file "$env_file" -f "$COMPOSE_FILE" \
            exec -T database pg_dump -U "$db_user" -d "$db_name" -Fc > "$path" 2>/dev/null; then
        rm -f "$path"
        echo "BŁĄD [${tenant}]: pg_dump nie powiódł się." >&2
        return 10
    fi

    # Pusty albo skrajnie mały plik to najgorszy możliwy wynik: wygląda jak
    # kopia, a nią nie jest. Lepiej zgłosić brak kopii niż ją udawać.
    local size
    size="$(wc -c < "$path" | tr -d ' ')"
    if [ "$size" -lt "$MIN_DUMP_BYTES" ]; then
        rm -f "$path"
        echo "BŁĄD [${tenant}]: zrzut miał ${size} B — usunięty, żeby nie udawał kopii." >&2
        return 10
    fi

    komunikat "OK [${tenant}]: ${path} (${size} B)"
    [ "$print_path" = true ] && printf '%s\n' "$path"

    retencja "$dir" "$tenant" "$path"
}

# Wypisuje komunikat dla człowieka. Przy --print-path idzie na stderr, żeby
# stdout niósł WYŁĄCZNIE ścieżki — wywołujący ma czytać kontrakt, nie tekst.
komunikat() {
    if [ "$print_path" = true ]; then
        printf '%s\n' "$1" >&2
    else
        printf '%s\n' "$1"
    fi
}

# Kody instancji dłuższe od podanego, ale zaczynające się tak samo
# (`trzeci` → `trzeci-tenant`). Ich zrzuty pasują do globa `${tenant}-*.dump`
# i bez tej listy retencja jednego tenanta kasowałaby kopie drugiego.
kody_kolidujace() {
    local tenant="$1" f kod
    for f in .env.platform .env.tenant.*; do
        [ -f "$f" ] || continue
        case "$f" in *.example) continue ;; esac
        case "$f" in
            .env.platform) kod="platform" ;;
            *) kod="${f#.env.tenant.}" ;;
        esac
        [ "$kod" = "$tenant" ] && continue
        case "$kod" in "${tenant}-"*) printf '%s\n' "$kod" ;; esac
    done
}

# Zostawia `keep` najnowszych zrzutów TEGO tenanta w tej etykiecie.
#
# Porządek bierze się z `ls -t` (czas modyfikacji), bo nazwa zaczyna się od
# skrótu commita i sortowana leksykograficznie kłamie o wieku pliku (#2993).
retencja() {
    local dir="$1" tenant="$2" swiezy="$3"
    local kolidujace zachowane=0 kandydat baza obcy kod lista

    kolidujace="$(kody_kolidujace "$tenant")"

    # `ls -t` na globie: najnowsze pierwsze. Brak dopasowań kończy się pustą
    # listą, nie błędem (kody tenantów nie zawierają spacji — patrz walidacja
    # kodu w pim-tenant-new.sh).
    lista="$(ls -t "$dir"/"${tenant}"-*.dump 2>/dev/null || true)"
    [ -n "$lista" ] || return 0

    while IFS= read -r kandydat; do
        [ -n "$kandydat" ] || continue

        # Zabezpieczenie 2: zrzut z tego przebiegu NIGDY nie jest kandydatem
        # do usunięcia. Nawet gdyby sortowanie kiedykolwiek znowu skłamało,
        # ta linia sama w sobie wyklucza powtórkę #2993.
        if [ "$kandydat" = "$swiezy" ]; then
            zachowane=$((zachowane + 1))
            continue
        fi

        # Zabezpieczenie 3: to zrzut innego tenanta o dłuższym kodzie.
        baza="$(basename "$kandydat")"
        obcy=false
        while IFS= read -r kod; do
            [ -n "$kod" ] || continue
            case "$baza" in "${kod}-"*) obcy=true ;; esac
        done <<KOLIZJE
${kolidujace}
KOLIZJE
        [ "$obcy" = true ] && continue

        zachowane=$((zachowane + 1))
        if [ "$zachowane" -gt "$keep" ]; then
            rm -f "$kandydat"
            komunikat "   retencja: usunięto ${baza}"
        fi
    done <<LISTA
${lista}
LISTA
}

failed=0
if [ "$all" = true ]; then
    found=false
    for f in .env.tenant.*; do
        [ -f "$f" ] || continue
        case "$f" in *.example) continue ;; esac
        found=true
        tenant="${f#.env.tenant.}"
        dump_one "$tenant" || failed=1
    done
    if [ "$found" != true ]; then
        echo "Brak plików .env.tenant.* — żadnej instancji do zrzucenia." >&2
        exit 2
    fi
else
    dump_one "$code" || failed=1
fi

exit "$failed"
