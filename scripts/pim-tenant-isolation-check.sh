#!/usr/bin/env bash
#
# Wykonywalny dowód izolacji MIĘDZY INSTANCJAMI tenantów (epik TNT, #2868).
#
# Nie jest to test jednorazowy „przy wdrożeniu epiku", tylko narzędzie do
# uruchomienia po KAŻDYM dołożeniu klienta. Izolacja, której nikt nie sprawdza,
# jest założeniem, nie właściwością.
#
# Każdy test negatywny ma swoją kontrolę pozytywną. Bez niej zepsuta instancja
# (np. z martwym API) „przechodziłaby" wszystkie odmowy i test meldowałby
# sukces, nie sprawdziwszy niczego.
#
# Użycie:
#   scripts/pim-tenant-isolation-check.sh --a acme --b beta
#   scripts/pim-tenant-isolation-check.sh --a acme --b beta \
#       --a-user owner@acme.pl --a-password-env ACME_PW

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

COMPOSE_FILE="docker-compose.tenant.yml"

a=""
b=""
a_user=""
a_password_env=""

usage() {
    cat <<'USAGE'
Sprawdza, czy instancje dwóch tenantów są od siebie odcięte.

Opcje:
  --a <kod>              Instancja źródłowa (wymagana).
  --b <kod>              Instancja, do której próbujemy się dostać (wymagana).
  --a-user <email>       Użytkownik instancji A (do testu logowania i tokenu).
  --a-password-env <VAR> Zmienna z hasłem tego użytkownika.
  -h, --help             Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --a) a="${2:-}"; shift 2 ;;
        --b) b="${2:-}"; shift 2 ;;
        --a-user) a_user="${2:-}"; shift 2 ;;
        --a-password-env) a_password_env="${2:-}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[ -n "$a" ] && [ -n "$b" ] || { echo "BŁĄD: --a i --b są wymagane." >&2; exit 2; }
[ "$a" != "$b" ] || { echo "BŁĄD: --a i --b muszą być różne." >&2; exit 2; }
for t in "$a" "$b"; do
    [ -f ".env.tenant.${t}" ] || { echo "BŁĄD: brak .env.tenant.${t}." >&2; exit 2; }
done

dca() { docker compose -p "pim-${a}" --env-file ".env.tenant.${a}" -f "$COMPOSE_FILE" "$@"; }
dcb() { docker compose -p "pim-${b}" --env-file ".env.tenant.${b}" -f "$COMPOSE_FILE" "$@"; }

read_env() { grep -E "^$2=" ".env.tenant.$1" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

passed=0
failed=0
skipped=0

ok()   { printf '  [OK]   %s\n' "$1"; passed=$((passed + 1)); }
bad()  { printf '  [FAIL] %s\n' "$1"; failed=$((failed + 1)); }
skip() { printf '  [SKIP] %s (%s)\n' "$1" "$2"; skipped=$((skipped + 1)); }

echo "Izolacja instancji: ${a} → ${b}"
echo ""

# ── 1. Token JWT instancji A wobec API instancji B ──────────────────────────
#
# Każda instancja ma własną parę kluczy (#2861), więc podpis z A nie może być
# uznany przez B. To jest właściwość, którą ADR-0035 przypisuje osobnym
# instancjom — i którą zapieczone w obrazie klucze JWT kiedyś by zniosły.
echo "[1] Token JWT A użyty wobec API B"
token=""
if [ -n "$a_user" ] && [ -n "$a_password_env" ] && [ -n "${!a_password_env:-}" ]; then
    token="$(dca exec -T api sh -c "curl -sS -X POST http://127.0.0.1/api/auth/login \
        -H 'Content-Type: application/json' \
        -d '{\"email\":\"${a_user}\",\"password\":\"${!a_password_env}\"}'" 2>/dev/null \
        | sed -n 's/.*\"token\":\"\([^\"]*\)\".*/\1/p')"
fi

if [ -z "$token" ]; then
    skip "token A odrzucony przez B" "brak --a-user/--a-password-env albo logowanie w A nie powiodło się"
    skip "kontrola pozytywna: token A działa w A" "jw."
else
    # Kontrola pozytywna NAJPIERW — bez niej odmowa niczego nie dowodzi.
    self="$(dca exec -T api sh -c "curl -sS -o /dev/null -w '%{http_code}' \
        http://127.0.0.1/api/workspaces/current -H 'Authorization: Bearer ${token}'" 2>/dev/null)"
    if [ "$self" = "200" ]; then
        ok "kontrola pozytywna: token A działa w A (200)"
    else
        bad "kontrola pozytywna ZAWIODŁA: token A w A zwrócił ${self} zamiast 200 — dalsze odmowy nic nie dowodzą"
    fi

    cross="$(dcb exec -T api sh -c "curl -sS -o /dev/null -w '%{http_code}' \
        http://127.0.0.1/api/workspaces/current -H 'Authorization: Bearer ${token}'" 2>/dev/null)"
    if [ "$cross" = "401" ]; then
        ok "token A wobec B odrzucony (401)"
    else
        bad "token A wobec B zwrócił ${cross} — oczekiwano 401"
    fi

    # ── 2. Logowanie użytkownika A na instancji B ───────────────────────────
    echo "[2] Logowanie użytkownika A na instancji B"
    login_b="$(dcb exec -T api sh -c "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST http://127.0.0.1/api/auth/login -H 'Content-Type: application/json' \
        -d '{\"email\":\"${a_user}\",\"password\":\"${!a_password_env}\"}'" 2>/dev/null)"
    case "$login_b" in
        401|403|422) ok "użytkownik A nie loguje się w B (${login_b})" ;;
        200) bad "użytkownik A ZALOGOWAŁ SIĘ w instancji B — bazy użytkowników nie są rozdzielone" ;;
        *) bad "logowanie A w B zwróciło ${login_b} — oczekiwano odmowy" ;;
    esac
fi

# ── 3. Poświadczenia MinIO instancji A wobec bucketów B ─────────────────────
echo "[3] Poświadczenia MinIO A wobec bucketów B"
a_key="$(read_env "$a" AWS_ASSETS_KEY)"
a_secret="$(read_env "$a" AWS_ASSETS_SECRET)"
a_bucket="$(read_env "$a" AWS_ASSETS_BUCKET)"
b_bucket="$(read_env "$b" AWS_ASSETS_BUCKET)"
network="$(read_env "$a" EDGE_NETWORK)"
[ -n "$network" ] || network="pim_default"

if [ -z "$a_key" ] || [ -z "$a_secret" ]; then
    skip "MinIO: A nie sięga bucketu B" "brak AWS_ASSETS_KEY/SECRET w pliku instancji A"
else
    own="$(docker run --rm --network "$network" -e MC_HOST_x="http://${a_key}:${a_secret}@minio:9000" \
        --entrypoint sh minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 -c "mc ls x/${a_bucket} >/dev/null 2>&1 && echo OK || echo DENIED" 2>/dev/null | tail -1)"
    if [ "$own" = "OK" ]; then
        ok "kontrola pozytywna: A widzi własny bucket"
    else
        bad "kontrola pozytywna ZAWIODŁA: A nie widzi własnego bucketu (${own})"
    fi

    foreign="$(docker run --rm --network "$network" -e MC_HOST_x="http://${a_key}:${a_secret}@minio:9000" \
        --entrypoint sh minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 -c "mc ls x/${b_bucket} >/dev/null 2>&1 && echo OK || echo DENIED" 2>/dev/null | tail -1)"
    if [ "$foreign" = "DENIED" ]; then
        ok "A nie sięga bucketu B (odmowa)"
    else
        bad "A SIĘGNĄŁ bucketu B — polityka MinIO nie ogranicza tenanta"
    fi
fi

# ── 4. Poświadczenia bazy A wobec bazy B ────────────────────────────────────
#
# Od #2864 baza tenanta jest w sieci wspólnej (pgBackRest musi wypychać kopie),
# więc jest osiągalna po nazwie. Granicą są osobne hasła — ten test tego pilnuje.
echo "[4] Poświadczenia bazy A wobec bazy B"
a_db_pass="$(read_env "$a" APP_DB_PASSWORD)"
b_db="$(read_env "$b" POSTGRES_DB)"
b_host="pim-${b}-database-1"
if [ -z "$a_db_pass" ]; then
    skip "baza: A nie łączy się do B" "brak APP_DB_PASSWORD w pliku instancji A"
else
    # Kontrola osiągalności: bez niej „odmowa" mogłaby oznaczać po prostu brak
    # trasy sieciowej, a test niczego by nie dowodził. Hasłem B połączenie MUSI
    # przejść — dopiero wtedy odmowa dla hasła A jest odmową uwierzytelnienia.
    b_db_pass="$(read_env "$b" APP_DB_PASSWORD)"
    reach="$(dca exec -T -e PGPASSWORD="$b_db_pass" database sh -c \
        "psql -h ${b_host} -U pim_app -d ${b_db} -tAc 'select 1' >/dev/null 2>&1 && echo OK || echo DENIED" 2>/dev/null | tail -1)"
    if [ "$reach" = "OK" ]; then
        ok "kontrola osiągalności: baza B odpowiada z kontenera A (własnym hasłem)"
    else
        bad "kontrola osiągalności ZAWIODŁA: baza B nieosiągalna — odmowa dla hasła A niczego nie dowodzi"
    fi

    conn="$(dca exec -T -e PGPASSWORD="$a_db_pass" database sh -c \
        "psql -h ${b_host} -U pim_app -d ${b_db} -tAc 'select 1' >/dev/null 2>&1 && echo OK || echo DENIED" 2>/dev/null | tail -1)"
    if [ "$conn" = "DENIED" ]; then
        ok "hasło bazy A nie otwiera bazy B (odmowa)"
    else
        bad "hasło bazy A OTWORZYŁO bazę B — instancje dzielą poświadczenia"
    fi
fi

# ── 5. Wyszukiwarka — jedyne miejsce z granicą w warstwie aplikacji ─────────
#
# Meilisearch jest współdzielony, a indeks `objects` wspólny (stała
# IndexSettingsTemplate::INDEX_NAME). Rozdziela je filtr `tenantId`
# w CatalogSearchService, więc tu izolacja NIE ma drugiej linii obrony
# i test jest obowiązkowy.
echo "[5] Wyszukiwarka: wyniki w A nie zawierają dokumentów B"
if [ -z "$token" ]; then
    skip "wyszukiwarka: brak przecieku B do A" "brak tokenu instancji A"
else
    b_tenant_id="$(dcb exec -T database psql -U pim -d "$b_db" -tAc "select id from tenants limit 1;" 2>/dev/null | tr -d '\r ')"
    hits="$(dca exec -T api sh -c "curl -sS 'http://127.0.0.1/api/products/quick-search?q=a&limit=50' \
        -H 'Authorization: Bearer ${token}'" 2>/dev/null)"
    if [ -z "$hits" ]; then
        skip "wyszukiwarka: brak przecieku B do A" "endpoint wyszukiwania nie zwrócił odpowiedzi"
    elif [ -z "$b_tenant_id" ]; then
        skip "wyszukiwarka: brak przecieku B do A" "nie udało się odczytać identyfikatora tenanta B"
    elif printf '%s' "$hits" | grep -q "$b_tenant_id"; then
        bad "wyniki wyszukiwania w A zawierają identyfikator tenanta B — filtr tenantId nie działa"
    else
        ok "wyniki w A nie zawierają identyfikatora tenanta B"
    fi
fi

echo ""
echo "Wynik: ${passed} zaliczonych, ${failed} niezaliczonych, ${skipped} pominiętych."
if [ "$failed" -gt 0 ]; then
    echo "IZOLACJA NARUSZONA — nie dokładaj kolejnego klienta, dopóki to nie jest zielone." >&2
    exit 1
fi
if [ "$skipped" -gt 0 ]; then
    echo "UWAGA: część testów pominięto — to NIE jest pełny dowód izolacji." >&2
    exit 3
fi
exit 0
