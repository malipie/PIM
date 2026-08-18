#!/usr/bin/env bash
#
# Generuje plik środowiska pojedynczej instancji tenanta (epik TNT, #2859).
#
# Każdy tenant dostaje WŁASNE hasła bazy, własny klucz Mercure i własną parę
# JWT. Współdzielone są wyłącznie zasoby, które w modelu z ADR-0035 są wspólne
# z definicji: Meilisearch, MinIO i relay pocztowy — te wartości skrypt
# przepisuje z pliku stacku współdzielonego, zamiast kazać operatorowi
# przeklejać je ręcznie (przeklejanie jest tym, jak sekrety wyciekają do
# historii powłoki).
#
# Użycie:
#   scripts/pim-tenant-env.sh --code acme
#   scripts/pim-tenant-env.sh --code acme --subdomain acme-pl --shared-env .env.prod
#
# Wynik: plik `.env.tenant.<kod>` z uprawnieniami 600. Skrypt NIE nadpisuje
# istniejącego pliku bez `--force` — nadpisanie oznaczałoby rotację haseł bazy
# pod działającą instancją, czyli awarię.
#
# Skrypt niczego nie uruchamia i nie dotyka Dockera. Pełny provisioning
# (stack → migracje → seed → smoke) to osobny skrypt (#2861), który ten plik
# konsumuje.

set -euo pipefail

cd "$(dirname "$0")/.."

DOMAIN_BASE_DEFAULT="app.harmonpim.pl"

# Nazwy, których nie wolno użyć jako subdomeny tenanta: kolidowałyby z hostami
# platformy albo z usługami wspólnymi. Ta sama lista obowiązuje w walidacji
# po stronie API (#2904) — rozjazd między nimi oznacza, że panel przyjmie
# nazwę, której infrastruktura nie obsłuży.
RESERVED_SUBDOMAINS="app admin www api docs mail status test staging platform pim mercure minio meili"

code=""
subdomain=""
domain_base="$DOMAIN_BASE_DEFAULT"
shared_env=""
out_file=""
force=false

usage() {
    cat <<'USAGE'
Generuje plik środowiska instancji tenanta.

Opcje:
  --code <kod>          Kod tenanta (wymagany). [a-z0-9][a-z0-9-]{1,30}[a-z0-9]
  --subdomain <nazwa>   Subdomena; domyślnie równa kodowi.
  --domain-base <host>  Domena bazowa; domyślnie app.harmonpim.pl
  --shared-env <plik>   Plik z wartościami wspólnymi (MEILI_MASTER_KEY,
                        MINIO_ROOT_USER/PASSWORD, MAILER_DSN, MAILER_FROM).
                        Domyślnie .env.prod, jeśli istnieje.
  --out <plik>          Plik wynikowy; domyślnie .env.tenant.<kod>
  --force               Nadpisz istniejący plik (rotuje sekrety — patrz wyżej).
  -h, --help            Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --code) code="${2:-}"; shift 2 ;;
        --subdomain) subdomain="${2:-}"; shift 2 ;;
        --domain-base) domain_base="${2:-}"; shift 2 ;;
        --shared-env) shared_env="${2:-}"; shift 2 ;;
        --out) out_file="${2:-}"; shift 2 ;;
        --force) force=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -z "$code" ]; then
    echo "BŁĄD: --code jest wymagany." >&2
    usage >&2
    exit 2
fi

[ -n "$subdomain" ] || subdomain="$code"
[ -n "$out_file" ] || out_file=".env.tenant.${code}"

validate_label() {
    local label="$1" what="$2"
    if ! printf '%s' "$label" | grep -qE '^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$'; then
        echo "BŁĄD: ${what} '${label}' musi pasować do [a-z0-9][a-z0-9-]{1,30}[a-z0-9]." >&2
        exit 2
    fi
}

validate_label "$code" "kod tenanta"
validate_label "$subdomain" "subdomena"

for reserved in $RESERVED_SUBDOMAINS; do
    if [ "$subdomain" = "$reserved" ]; then
        echo "BŁĄD: subdomena '${subdomain}' jest zastrzeżona (kolizja z hostem platformy lub usługą wspólną)." >&2
        exit 2
    fi
done

if [ -e "$out_file" ] && [ "$force" != true ]; then
    echo "BŁĄD: ${out_file} już istnieje." >&2
    echo "Nadpisanie zrotowałoby hasła bazy pod działającą instancją. Użyj --force tylko świadomie." >&2
    exit 3
fi

if [ -z "$shared_env" ] && [ -f ".env.prod" ]; then
    shared_env=".env.prod"
fi

# Wartości wspólne: czytane z pliku stacku współdzielonego, bez wykonywania go
# (`source` na pliku env uruchamia dowolny kod, jeśli ktoś wstawi tam
# podstawienie polecenia).
read_shared() {
    local key="$1"
    [ -n "$shared_env" ] && [ -f "$shared_env" ] || return 0
    grep -E "^${key}=" "$shared_env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

meili_key="$(read_shared MEILI_MASTER_KEY)"
minio_user="$(read_shared MINIO_ROOT_USER)"
minio_password="$(read_shared MINIO_ROOT_PASSWORD)"
mailer_dsn="$(read_shared MAILER_DSN)"
mailer_from="$(read_shared MAILER_FROM)"

# Sekrety WYŁĄCZNIE heksadecymalne. Hasła bazy trafiają do DSN-ów w postaci
# URL (schemat postgresql, hasło w części poświadczeń), więc znak małpy,
# ukośnika, dwukropka albo krzyżyka z base64 rozerwałby połączenie w sposób
# trudny do zdiagnozowania. Wyjątkiem jest APP_BYOK_KEY_V1, którego aplikacja
# oczekuje w base64 i który nigdy nie ląduje w URL-u.
#
# Uwaga dla przyszłych edycji: nie wstawiaj tu przykładowego DSN-a w pełnej
# formie — skaner sekretów w CI rozpoznaje go jako poświadczenia Postgresa
# i wywraca bramkę na komentarzu.
gen_hex() { openssl rand -hex "$1"; }

app_secret="$(gen_hex 16)"
postgres_password="$(gen_hex 24)"
app_db_password="$(gen_hex 24)"
mercure_secret="$(gen_hex 32)"
jwt_passphrase="$(gen_hex 24)"
minio_tenant_secret="$(gen_hex 24)"
byok_key="$(openssl rand -base64 32)"

fqdn="${subdomain}.${domain_base}"
# Regex dla TRUSTED_HOSTS musi mieć wykropkowane kropki, inaczej `.` dopasuje
# dowolny znak i host z podmienioną literą przeszedłby walidację.
trusted_hosts="^$(printf '%s' "$fqdn" | sed 's/\./\\./g')\$"

umask 077
cat > "$out_file" <<EOF
# Instancja tenanta '${code}' — wygenerowane przez scripts/pim-tenant-env.sh
# Sekrety są unikalne dla tej instancji. Nie kopiuj tego pliku dla innego
# tenanta i nie commituj go.

TENANT_CODE=${code}
APP_BASE_URL=https://${fqdn}
TRUSTED_HOSTS=${trusted_hosts}

APP_SECRET=${app_secret}

POSTGRES_DB=pim_$(printf '%s' "$code" | tr '-' '_')
POSTGRES_USER=pim
POSTGRES_PASSWORD=${postgres_password}
APP_DB_USER=pim_app
APP_DB_PASSWORD=${app_db_password}

MERCURE_JWT_SECRET=${mercure_secret}
JWT_PASSPHRASE=${jwt_passphrase}
APP_BYOK_KEY_V1=${byok_key}

MEILI_URL=http://meilisearch:7700
MEILI_MASTER_KEY=${meili_key}

MINIO_ROOT_USER=${minio_user}
MINIO_ROOT_PASSWORD=${minio_password}

AWS_ASSETS_BUCKET=${code}-assets
AWS_IMPORTS_BUCKET=${code}-imports
AWS_EXPORTS_BUCKET=${code}-exports
AWS_ASSETS_KEY=${code}
AWS_ASSETS_SECRET=${minio_tenant_secret}

MAILER_DSN=${mailer_dsn}
MAILER_FROM=${mailer_from}
EOF
chmod 600 "$out_file"

echo "Utworzono ${out_file} (600)."
echo "  tenant     : ${code}"
echo "  adres      : https://${fqdn}"
echo "  baza       : pim_$(printf '%s' "$code" | tr '-' '_')"
echo "  buckety    : ${code}-assets, ${code}-imports, ${code}-exports"

# Braki w wartościach wspólnych są ostrzeżeniem, nie błędem: plik da się
# uzupełnić ręcznie. Milczenie w tym miejscu kosztowałoby jednak instancję,
# która wstaje i dopiero przy pierwszym zaproszeniu okazuje się bezużyteczna.
incomplete=""
[ -n "$meili_key" ] || incomplete="${incomplete} MEILI_MASTER_KEY"
[ -n "$minio_user" ] || incomplete="${incomplete} MINIO_ROOT_USER"
[ -n "$minio_password" ] || incomplete="${incomplete} MINIO_ROOT_PASSWORD"
[ -n "$mailer_dsn" ] || incomplete="${incomplete} MAILER_DSN"
[ -n "$mailer_from" ] || incomplete="${incomplete} MAILER_FROM"

if [ -n "$incomplete" ]; then
    echo ""
    echo "UWAGA: nie udało się odczytać wartości wspólnych:${incomplete}"
    if [ -n "$shared_env" ]; then
        echo "Źródło: ${shared_env}"
    else
        echo "Nie wskazano --shared-env i nie znaleziono .env.prod."
    fi
    echo "Uzupełnij je przed 'docker compose up' — compose i tak odmówi startu (fail-loud),"
    echo "a pusty MAILER_DSN oznacza martwe linki w zaproszeniach i resetach hasła."
fi
