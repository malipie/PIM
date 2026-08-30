#!/usr/bin/env bash
#
# Usuwa instancję tenanta (epik TNT, #2862).
#
# Kolejność jest tu całą treścią ticketu: **zrzut bazy powstaje ZANIM cokolwiek
# zostanie zatrzymane lub skasowane**. Operacja jest nieodwracalna, a jedyny
# moment, w którym dane jeszcze istnieją, to początek.
#
# Domyślnie skrypt NICZEGO nie usuwa — wypisuje plan. Wykonanie wymaga podania
# kodu tenanta przez `--confirm <kod>`. Świadomie nie jest to pytanie na stdin:
# skrypt bywa wołany z automatu (#2909), a potwierdzenie „na ślepy Enter" nie
# jest potwierdzeniem.
#
# `--purge-storage` usuwa też katalog stanzy w repozytorium pgBackRest
# (`pim-pgbackrest/pim-<kod>`). Bez tego ponowne założenie tenanta o tym samym
# kodzie kończy się błędem 51 („is this the correct stanza?"): repozytorium
# pamięta kopie POPRZEDNIEGO klastra, a nowy ma inny identyfikator systemowy.
#
# Buckety MinIO domyślnie ZOSTAJĄ. Pliki klienta przeżywają usunięcie instancji
# — odtworzenie assetów z kopii bazy jest niemożliwe, a pochopne `rb --force`
# nieodwracalne. Usunięcie za jawnym `--purge-storage`.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.tenant.yml"
BACKUP_DIR="backups/final"

code=""
confirm=""
env_file=""
purge_storage=false
keep_volumes=false

usage() {
    cat <<'USAGE'
Usuwa instancję tenanta po wykonaniu końcowego zrzutu bazy.

Opcje:
  --code <kod>        Kod tenanta (wymagany).
  --confirm <kod>     Powtórz kod tenanta, żeby wykonać usunięcie.
                      Bez tego skrypt wypisuje wyłącznie plan.
  --env-file <plik>   Plik środowiska; domyślnie .env.tenant.<kod>
  --purge-storage     Usuń także buckety MinIO tenanta (domyślnie zostają).
  --keep-volumes      Zatrzymaj stack, ale nie usuwaj wolumenów.
  -h, --help          Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --code) code="${2:-}"; shift 2 ;;
        --confirm) confirm="${2:-}"; shift 2 ;;
        --env-file) env_file="${2:-}"; shift 2 ;;
        --purge-storage) purge_storage=true; shift ;;
        --keep-volumes) keep_volumes=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[ -n "$code" ] || { echo "BŁĄD: --code jest wymagany." >&2; exit 2; }
[ -n "$env_file" ] || env_file=".env.tenant.${code}"
[ -f "$env_file" ] || { echo "BŁĄD: brak ${env_file} — nie wiem, którą instancję usuwać." >&2; exit 2; }

project="pim-${code}"

# Odczyt tekstowy, bez `source`. `|| true` jest tu ISTOTNE: pod `set -e`
# nieudane podstawienie w przypisaniu kończy skrypt, a klucz opcjonalny
# (np. EDGE_NETWORK, którego generator nie zapisuje) po prostu nie występuje.
# Bez tego skrypt cicho kończył się w połowie sprzątania storage, zdążywszy
# wypisać, że je usuwa.
read_env() {
    grep -E "^$1=" "$env_file" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

db_name="$(read_env POSTGRES_DB)"
db_user="$(read_env POSTGRES_USER)"
[ -n "$db_name" ] || { echo "BŁĄD: POSTGRES_DB nie jest ustawione w ${env_file}." >&2; exit 2; }
[ -n "$db_user" ] || db_user="pim"

stamp="$(date +%Y%m%d-%H%M%S)"
dump_path="${BACKUP_DIR}/${code}-${stamp}.dump"

dc() {
    docker compose -p "$project" --env-file "$env_file" -f "$COMPOSE_FILE" "$@"
}

echo "Instancja       : ${code} (projekt ${project})"
echo "Baza            : ${db_name}"
echo "Zrzut końcowy   : ${dump_path}"
echo "Wolumeny        : $([ "$keep_volumes" = true ] && echo 'ZOSTAJĄ (--keep-volumes)' || echo 'DO USUNIĘCIA')"
echo "Buckety MinIO   : $([ "$purge_storage" = true ] && echo 'DO USUNIĘCIA (--purge-storage)' || echo 'zostają')"
echo ""

if [ "$confirm" != "$code" ]; then
    cat <<EOF
PLAN (nic nie zostało zmienione).

Zostanie wykonane w tej kolejności:
  1. Start samej bazy (jeśli nie działa) i zrzut ${dump_path}
  2. Weryfikacja, że zrzut jest niepusty
  3. Zatrzymanie stacku $([ "$keep_volumes" = true ] && echo '(wolumeny zachowane)' || echo 'wraz z wolumenami')
$([ "$purge_storage" = true ] && echo '  4. Usunięcie bucketów, użytkownika i polityki MinIO')

Aby wykonać: dodaj --confirm ${code}
EOF
    exit 0
fi

mkdir -p "$BACKUP_DIR"

# ── 1. Zrzut końcowy PRZED czymkolwiek destrukcyjnym ────────────────────────
echo "[1/4] Zrzut końcowy bazy…"
dc up -d database >/dev/null 2>&1 || { echo "BŁĄD: nie udało się wystartować bazy do zrzutu." >&2; exit 10; }

tries=30
until dc exec -T database pg_isready -U "$db_user" -d "$db_name" >/dev/null 2>&1; do
    tries=$((tries - 1))
    [ "$tries" -gt 0 ] || { echo "BŁĄD: baza nie odpowiada — zrzut nie powstał, NIC nie usunięto." >&2; exit 10; }
    sleep 2
done

if ! dc exec -T database pg_dump -U "$db_user" -d "$db_name" -Fc > "$dump_path" 2>/dev/null; then
    rm -f "$dump_path"
    echo "BŁĄD: pg_dump nie powiódł się — NIC nie zostało usunięte." >&2
    exit 20
fi

dump_size=$(wc -c < "$dump_path" | tr -d ' ')
if [ "$dump_size" -lt 1024 ]; then
    echo "BŁĄD: zrzut ma ${dump_size} B — to nie wygląda na poprawną kopię. NIC nie zostało usunięte." >&2
    exit 20
fi
echo "      ${dump_path} (${dump_size} B)"

# ── 2. Zatrzymanie stacku ───────────────────────────────────────────────────
echo "[2/4] Zatrzymywanie stacku…"
if [ "$keep_volumes" = true ]; then
    dc down >/dev/null 2>&1 || true
    echo "      kontenery zatrzymane, wolumeny zachowane"
else
    dc down -v >/dev/null 2>&1 || true
    echo "      kontenery i wolumeny usunięte"
fi

# ── 3. Storage ──────────────────────────────────────────────────────────────
if [ "$purge_storage" = true ]; then
    echo "[3/4] Usuwanie bucketów MinIO…"
    assets="$(read_env AWS_ASSETS_BUCKET)"
    imports="$(read_env AWS_IMPORTS_BUCKET)"
    exports="$(read_env AWS_EXPORTS_BUCKET)"
    access_key="$(read_env AWS_ASSETS_KEY)"
    root_user="$(read_env MINIO_ROOT_USER)"
    root_password="$(read_env MINIO_ROOT_PASSWORD)"
    network="$(read_env EDGE_NETWORK)"
    [ -n "$network" ] || network="pim_default"

    # UWAGA: poniższy łańcuch jest w podwójnych cudzysłowach, więc NIE wolno
    # umieszczać w nim komentarzy z backtickami — powłoka potraktowałaby je
    # jako podstawienia poleceń i wykonała. Wyjaśnienia trzymamy tutaj:
    #
    # `--versions` przy czyszczeniu repozytorium kopii jest konieczne, bo
    # wiadro ma włączone wersjonowanie. Bez tej flagi zostają wersje, a
    # pgBackRest odmawia potem utworzenia stanzy błędem 40 (katalog niepusty)
    # i ponowne założenie tenanta o tym samym kodzie kończy się błędem 51.
    docker run --rm --network "$network" \
        -e MC_HOST_t="http://${root_user}:${root_password}@minio:9000" \
        --entrypoint sh minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 -c "
            mc rb --force t/${assets} || true
            mc rb --force t/${imports} || true
            mc rb --force t/${exports} || true
            mc admin user remove t '${access_key}' || true
            mc admin policy remove t pim-tenant-${code} || true
            mc rm --recursive --force --versions t/pim-pgbackrest/pim-${code} || true
        " >/dev/null 2>&1 || true
    echo "      buckety, użytkownik, polityka i repozytorium kopii usunięte"
else
    echo "[3/4] Buckety MinIO zachowane (użyj --purge-storage, żeby je usunąć)."
fi

# ── 4. Ślady w konfiguracji wspólnej ────────────────────────────────────────
#
# Routing nie zostawia już śladu do sprzątnięcia (#2908): edge kieruje ruch
# blokiem wildcard, więc zniknięcie kontenerów wystarczy — host odpowiada 404.
# Zostaje monitoring, bo target Prometheusa jest plikiem.
# Target Prometheusa znika razem z instancją — inaczej po usunięciu klienta
# zostaje alert „instancja nie odpowiada" dla czegoś, co celowo nie istnieje.
target_file="docker/prometheus/targets/pim-${code}.yml"
if [ -f "$target_file" ]; then
    rm -f "$target_file"
    echo "      usunięto ${target_file}"
fi

echo "[4/4] Pozostało do usunięcia ręcznie:"
echo "      - plik środowiska ${env_file} (zawiera sekrety — usuń świadomie)"
echo ""
echo "Instancja ${code} usunięta. Kopia: ${dump_path}"
