#!/usr/bin/env bash
#
# Provisioning pojedynczej instancji tenanta od zera (epik TNT, #2861).
#
# Jedna komenda: stack → migracje → klucze JWT → storage → tenant i właściciel
# → worker → smoke test. Kończy się błędem, jeśli smoke nie przejdzie —
# „utworzono" bez dowodu, że instancja odpowiada, jest gorsze niż jawna
# porażka, bo operator dowiaduje się o niej dopiero od klienta.
#
# Skrypt jest interfejsem MASZYNOWYM: nie zadaje pytań, a postęp raportuje
# liniami `STEP|<krok>|<status>|<szczegół>`, żeby panel operatora (#2907) mógł
# je pokazać na żywo. Kody wyjścia rozróżniają fazę, w której coś padło —
# „coś poszło nie tak" nie nadaje się do pokazania człowiekowi.
#
#   2  — walidacja argumentów / brak wymaganych narzędzi
#   10 — stack nie wstał
#   20 — migracje
#   30 — klucze JWT
#   40 — storage (MinIO)
#   50 — bootstrap tenanta / właściciela
#   60 — smoke test
#
# Idempotentny: ponowne uruchomienie na istniejącej instancji dokłada tylko to,
# czego brakuje. To normalna ścieżka po nieudanym przebiegu (#2911), nie wyjątek.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.tenant.yml"

code=""
name=""
subdomain=""
owner_email=""
owner_password_env=""
domain_base="app.harmonpim.pl"
shared_env=""
locale_default="pl_PL"
locale_secondary=""
skip_smoke=false
invite_owner=false

usage() {
    cat <<'USAGE'
Zakłada instancję tenanta od zera.

Wymagane:
  --code <kod>                 Kod tenanta ([a-z0-9][a-z0-9-]{1,30}[a-z0-9]).
  --owner-email <email>        Adres właściciela instancji.
  --owner-password-env <VAR>   Nazwa zmiennej środowiskowej z hasłem właściciela
                               (min. 12 znaków). Hasła NIE podaje się w argumentach —
                               trafiłoby do historii powłoki i listy procesów.

Opcjonalne:
  --name <nazwa>               Nazwa wyświetlana; domyślnie kod.
  --subdomain <nazwa>          Subdomena; domyślnie kod.
  --domain-base <host>         Domena bazowa; domyślnie app.harmonpim.pl
  --shared-env <plik>          Plik z wartościami wspólnymi; domyślnie .env.prod
  --locale-default <kod>       Domyślny język; domyślnie pl_PL (pełny kod, jak w tabeli locales)
  --locale-secondary <kod>     Dodatkowy język.
  --invite-owner               Po smoke teście wyślij właścicielowi zaproszenie
                               do ustawienia własnego hasła (ścieżka panelowa:
                               hasła tymczasowego nikt nie ogląda).
  --skip-smoke                 Pomiń smoke test (WYŁĄCZNIE do diagnostyki).
  -h, --help                   Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --code) code="${2:-}"; shift 2 ;;
        --name) name="${2:-}"; shift 2 ;;
        --subdomain) subdomain="${2:-}"; shift 2 ;;
        --owner-email) owner_email="${2:-}"; shift 2 ;;
        --owner-password-env) owner_password_env="${2:-}"; shift 2 ;;
        --domain-base) domain_base="${2:-}"; shift 2 ;;
        --shared-env) shared_env="${2:-}"; shift 2 ;;
        --locale-default) locale_default="${2:-}"; shift 2 ;;
        --locale-secondary) locale_secondary="${2:-}"; shift 2 ;;
        --invite-owner) invite_owner=true; shift ;;
        --skip-smoke) skip_smoke=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

step() { printf 'STEP|%s|%s|%s\n' "$1" "$2" "${3:-}"; }

fail() {
    local phase="$1" exit_code="$2" detail="$3"
    step "$phase" "failed" "$detail"
    echo "" >&2
    echo "PROVISIONING PRZERWANY na kroku '${phase}': ${detail}" >&2
    echo "Instancja może być w stanie częściowym. Ponowne uruchomienie tego skryptu jest bezpieczne" >&2
    echo "i dokłada wyłącznie brakujące elementy (patrz runbook, #2911)." >&2
    exit "$exit_code"
}

[ -n "$code" ] || { echo "BŁĄD: --code jest wymagany." >&2; exit 2; }
[ -n "$owner_email" ] || { echo "BŁĄD: --owner-email jest wymagany." >&2; exit 2; }
[ -n "$owner_password_env" ] || { echo "BŁĄD: --owner-password-env jest wymagany." >&2; exit 2; }

if [ -z "${!owner_password_env:-}" ]; then
    echo "BŁĄD: zmienna ${owner_password_env} jest pusta — nie ma hasła właściciela." >&2
    exit 2
fi
if [ "$(printf '%s' "${!owner_password_env}" | wc -c)" -lt 12 ]; then
    echo "BŁĄD: hasło właściciela musi mieć min. 12 znaków (wymóg pim:tenant:bootstrap)." >&2
    exit 2
fi

[ -n "$name" ] || name="$code"
[ -n "$subdomain" ] || subdomain="$code"

command -v docker >/dev/null 2>&1 || { echo "BŁĄD: brak dockera w PATH." >&2; exit 2; }
[ -f "$COMPOSE_FILE" ] || { echo "BŁĄD: brak ${COMPOSE_FILE} — uruchamiaj z katalogu repozytorium." >&2; exit 2; }

project="pim-${code}"
env_file=".env.tenant.${code}"
fqdn="${subdomain}.${domain_base}"

dc() {
    docker compose -p "$project" --env-file "$env_file" -f "$COMPOSE_FILE" "$@"
}

echo "Instancja tenanta '${code}' → https://${fqdn}"
echo ""

# ── 1. Środowisko ───────────────────────────────────────────────────────────
step "env" "running" "$env_file"
if [ -f "$env_file" ]; then
    step "env" "ok" "plik już istnieje — zachowany (nadpisanie zrotowałoby hasła bazy)"
else
    env_args=(--code "$code" --subdomain "$subdomain" --domain-base "$domain_base" --out "$env_file")
    [ -n "$shared_env" ] && env_args+=(--shared-env "$shared_env")
    bash scripts/pim-tenant-env.sh "${env_args[@]}" >/dev/null \
        || fail "env" 2 "nie udało się wygenerować ${env_file}"
    step "env" "ok" "wygenerowano ${env_file}"
fi

# ── 2. Baza i cache ─────────────────────────────────────────────────────────
step "stack-data" "running" "database, redis"
dc up -d database redis >/dev/null 2>&1 || fail "stack-data" 10 "docker compose up nie powiódł się"

wait_healthy() {
    local service="$1" tries="${2:-60}" cid state
    while [ "$tries" -gt 0 ]; do
        cid="$(dc ps -q "$service" 2>/dev/null || true)"
        if [ -n "$cid" ]; then
            state="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo unknown)"
            case "$state" in
                healthy|running) return 0 ;;
                exited|dead) return 1 ;;
            esac
        fi
        tries=$((tries - 1))
        sleep 2
    done
    return 1
}

# Kontener „running" to nie to samo co „healthy": api NIE MOŻE przejść
# healthchecku przed migracjami, bo ten uderza w GET /api, a listener audytu
# próbuje wtedy zapisać wiersz do audit_logs — tabeli, której jeszcze nie ma.
# Do wykonania `exec` wystarczy stan running.
wait_running() {
    local service="$1" tries="${2:-60}" cid state
    while [ "$tries" -gt 0 ]; do
        cid="$(dc ps -q "$service" 2>/dev/null || true)"
        if [ -n "$cid" ]; then
            state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || echo unknown)"
            case "$state" in
                running) return 0 ;;
                exited|dead) return 1 ;;
            esac
        fi
        tries=$((tries - 1))
        sleep 2
    done
    return 1
}

wait_healthy database || fail "stack-data" 10 "kontener bazy nie osiągnął stanu healthy"
wait_healthy redis || fail "stack-data" 10 "kontener redis nie osiągnął stanu healthy"
step "stack-data" "ok" "baza i redis healthy"

# ── 3. API ──────────────────────────────────────────────────────────────────
step "stack-api" "running" "budowanie obrazu i start api"
dc up -d --build api >/dev/null 2>&1 || fail "stack-api" 10 "api nie wstało"
wait_running api 180 || fail "stack-api" 10 "kontener api nie wystartował"
step "stack-api" "ok" "api uruchomione (healthcheck dopiero po migracjach — patrz niżej)"

# ── 4. Klucze JWT ───────────────────────────────────────────────────────────
#
# Generowane JEDNYM, uporządkowanym wywołaniem przed wpuszczeniem ruchu.
# Autogeneracja w entrypoincie jest przeznaczona dla dev/test, a api i worker
# dzielą tu wolumen api_jwt — równoległa generacja przy pierwszym starcie
# mogłaby dać niedopasowaną parę i logowanie kończące się błędem 500.
step "jwt" "running" "para kluczy dla tej instancji"

# `--skip-if-exists` NIE wystarczy. Wolumen api_jwt jest przy pierwszym
# montowaniu zasiewany zawartością obrazu, a obraz do #2861 zapiekał klucze
# z dysku budującego (naprawione w apps/api/.dockerignore). Skrypt sprawdza
# więc stan faktyczny: czy klucz prywatny otwiera się hasłem TEJ instancji.
# Jeśli nie — para pochodzi skądinąd i musi zostać nadpisana, inaczej
# logowanie kończy się błędem 500 („bad decrypt"), a w gorszym wariancie
# instancje różnych klientów podpisują tokeny wspólnym kluczem.
key_ok=false
# shellcheck disable=SC2016 # $JWT_PASSPHRASE ma rozwinąć się W KONTENERZE
if dc exec -T api sh -c 'test -f /app/config/jwt/private.pem \
        && openssl pkey -in /app/config/jwt/private.pem -passin pass:"$JWT_PASSPHRASE" -noout' >/dev/null 2>&1; then
    key_ok=true
fi

if [ "$key_ok" = true ]; then
    step "jwt" "ok" "para kluczy już pasuje do hasła tej instancji"
else
    dc exec -T api php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction >/dev/null 2>&1 \
        || fail "jwt" 30 "nie udało się wygenerować pary kluczy JWT"
    # shellcheck disable=SC2016 # jw. — rozwinięcie po stronie kontenera
    dc exec -T api sh -c 'openssl pkey -in /app/config/jwt/private.pem -passin pass:"$JWT_PASSPHRASE" -noout' >/dev/null 2>&1 \
        || fail "jwt" 30 "wygenerowana para kluczy nie otwiera się hasłem JWT_PASSPHRASE"
    step "jwt" "ok" "wygenerowano parę kluczy dla tej instancji"
fi

# ── 5. Migracje ─────────────────────────────────────────────────────────────
#
# Idą połączeniem właściciela tabel (DATABASE_URL_OWNER): runtime `pim_app`
# jest NOBYPASSRLS i nie ma praw DDL, więc ENABLE/FORCE RLS i CREATE POLICY
# mogłyby się tylko wywrócić.
step "migrations" "running" "doctrine:migrations:migrate"
dc exec -T api php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null 2>&1 \
    || fail "migrations" 20 "migracje nie przeszły"
step "migrations" "ok" "schemat aktualny"

# Dopiero ze schematem w bazie healthcheck ma szansę przejść.
step "api-health" "running" "oczekiwanie na healthy po migracjach"
wait_healthy api 90 || fail "api-health" 10 "api nie osiągnęło stanu healthy mimo wykonanych migracji"
step "api-health" "ok" "api healthy"

# ── 6. Storage ──────────────────────────────────────────────────────────────
step "storage" "running" "buckety i użytkownik MinIO"
bash scripts/pim-tenant-minio.sh --code "$code" --env-file "$env_file" >/dev/null 2>&1 \
    || fail "storage" 40 "provisioning MinIO nie powiódł się"
step "storage" "ok" "buckety i zawężona polityka gotowe"

# ── 7. Tenant i właściciel ──────────────────────────────────────────────────
step "tenant" "running" "pim:tenant:bootstrap"
bootstrap_args=(pim:tenant:bootstrap --code "$code" --name "$name"
    --owner-email "$owner_email" --owner-password-env "$owner_password_env"
    --locale-default "$locale_default" --no-interaction)
[ -n "$locale_secondary" ] && bootstrap_args+=(--locale-secondary "$locale_secondary")

dc exec -T -e "${owner_password_env}=${!owner_password_env}" api php bin/console "${bootstrap_args[@]}" >/dev/null 2>&1 \
    || fail "tenant" 50 "bootstrap tenanta/właściciela nie powiódł się"
step "tenant" "ok" "tenant '${code}' i właściciel ${owner_email}"

# ── 8. Worker ───────────────────────────────────────────────────────────────
step "stack-worker" "running" "start workera"
dc up -d worker mercure >/dev/null 2>&1 || fail "stack-worker" 10 "worker/mercure nie wstały"
step "stack-worker" "ok" "worker i mercure działają"

# ── 8b. Target Prometheusa ──────────────────────────────────────────────────
#
# Plik jest generowany, nie dopisywany ręcznie — ręczna lista rozjeżdża się
# z rzeczywistością przy pierwszym kliencie założonym w pośpiechu. Prometheus
# czyta katalog przez file_sd i przeładowuje bez restartu, więc pozostałe
# instancje nie tracą ciągłości metryk (#2866).
step "monitoring" "running" "target Prometheusa"
targets_dir="docker/prometheus/targets"
if [ -d "$targets_dir" ]; then
    cat > "${targets_dir}/pim-${code}.yml" <<EOF
# Generowane przez scripts/pim-tenant-new.sh — nie edytuj ręcznie.
- targets: ["pim-${code}-api:80"]
  labels:
    tenant: "${code}"
    service: api
    tier: web
EOF
    step "monitoring" "ok" "${targets_dir}/pim-${code}.yml"
else
    step "monitoring" "skipped" "brak katalogu ${targets_dir} — pomijam"
fi

# ── 9. Smoke test ───────────────────────────────────────────────────────────
#
# Nie „kontener działa", tylko „właściciel się loguje i dostaje dane swojej
# instancji". Bez tego kroku skrypt potrafiłby zameldować sukces dla instancji,
# do której nikt nie może się zalogować.
if [ "$skip_smoke" = true ]; then
    step "smoke" "skipped" "pominięty na żądanie (--skip-smoke)"
else
    step "smoke" "running" "login właściciela + /api/workspaces/current"
    smoke_out="$(dc exec -T api sh -c "
        set -e
        token=\$(curl -sS -X POST http://127.0.0.1/api/auth/login \
            -H 'Content-Type: application/json' \
            -d '{\"email\":\"${owner_email}\",\"password\":\"${!owner_password_env}\"}' \
            | sed -n 's/.*\"token\":\"\([^\"]*\)\".*/\1/p')
        [ -n \"\$token\" ] || { echo 'BRAK_TOKENU'; exit 1; }
        curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/api/workspaces/current \
            -H \"Authorization: Bearer \$token\"
    " 2>/dev/null || true)"

    case "$smoke_out" in
        *200*) step "smoke" "ok" "login i /api/workspaces/current zwracają 200" ;;
        *BRAK_TOKENU*) fail "smoke" 60 "logowanie właściciela nie zwróciło tokenu" ;;
        *) fail "smoke" 60 "smoke test zwrócił '${smoke_out}' zamiast 200" ;;
    esac
fi

# ── 9b. Zaproszenie właściciela ─────────────────────────────────────────────
#
# Wysyłane przez TĘ instancję, nie przez platformę: link budowany jest
# z `APP_BASE_URL` instancji klienta. Zaproszenie z platformy prowadziłoby pod
# adres panelu operatora (ADR-0036).
if [ "$invite_owner" = true ]; then
    step "invite" "running" "zaproszenie dla ${owner_email}"
    if dc exec -T api php bin/console pim:tenant:invite-owner --code "$code" --email "$owner_email" >/dev/null 2>&1; then
        step "invite" "ok" "zaproszenie wysłane"
    else
        # Nieudane zaproszenie NIE przekreśla instancji — ona działa, a właściciel
        # może dostać dostęp przez reset hasła. Zgłaszamy to jako ostrzeżenie,
        # zamiast wywracać cały provisioning.
        step "invite" "warning" "nie udało się wysłać zaproszenia — sprawdź MAILER_DSN; instancja działa"
    fi
fi

# ── 10. Kroki ręczne ────────────────────────────────────────────────────────
#
# Świadomie NIE wykonywane przez ten skrypt: routing i certyfikat są dziś
# konfiguracją edge Caddy'ego (#2856), a docelowo mają być dynamiczne (#2908).
# Rejestracja redirect URI SSO nie ma API u dostawców.
step "done" "ok" "instancja gotowa"

cat <<EOF

Instancja '${code}' gotowa.
  adres      : https://${fqdn}
  właściciel : ${owner_email}
  projekt    : ${project}
  środowisko : ${env_file}

Pozostaje do wykonania poza tym skryptem:
  1. Routing edge Caddy dla hosta ${fqdn} (#2856; dynamiczny w #2908).
  2. Redirect URI SSO dla ${fqdn} u dostawcy tożsamości, jeśli tenant korzysta z SSO.
EOF
