#!/usr/bin/env bash
#
# Tworzy zasoby MinIO pojedynczego tenanta (epik TNT, #2860).
#
# MinIO jest w modelu z ADR-0035 usługą WSPÓLNĄ — osobna instancja nie dodałaby
# izolacji ponad to, co daje polityka dostępu. Granicą jest więc użytkownik per
# tenant, którego polityka wpuszcza WYŁĄCZNIE do trzech bucketów tego klienta.
#
# Aplikacja nigdy nie dostaje poświadczeń root: te są zarezerwowane dla
# pgBackRest (repozytorium kopii bazy). Wyciek klucza aplikacyjnego jednego
# tenanta nie może dawać dostępu do plików pozostałych.
#
# Użycie:
#   scripts/pim-tenant-minio.sh --code acme --env-file .env.tenant.acme
#   scripts/pim-tenant-minio.sh --code acme --env-file .env.tenant.acme --dry-run
#
# Idempotentny: ponowne uruchomienie na istniejącym tenancie aktualizuje
# politykę i sekret, nie kasując danych.

set -euo pipefail

cd "$(dirname "$0")/.."

code=""
env_file=""
endpoint="http://minio:9000"
network=""
dry_run=false

usage() {
    cat <<'USAGE'
Tworzy buckety i zawężonego użytkownika MinIO dla instancji tenanta.

Opcje:
  --code <kod>        Kod tenanta (wymagany).
  --env-file <plik>   Plik środowiska tenanta; domyślnie .env.tenant.<kod>.
                      Źródło AWS_*_BUCKET, AWS_ASSETS_KEY/SECRET oraz
                      MINIO_ROOT_USER/PASSWORD.
  --endpoint <url>    Endpoint MinIO; domyślnie http://minio:9000
  --network <sieć>    Sieć dockerowa, w której widoczny jest MinIO;
                      domyślnie EDGE_NETWORK z pliku env lub pim_default.
  --dry-run           Pokaż, co zostałoby zrobione, i nic nie zmieniaj.
  -h, --help          Ta pomoc.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --code) code="${2:-}"; shift 2 ;;
        --env-file) env_file="${2:-}"; shift 2 ;;
        --endpoint) endpoint="${2:-}"; shift 2 ;;
        --network) network="${2:-}"; shift 2 ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -z "$code" ]; then
    echo "BŁĄD: --code jest wymagany." >&2
    exit 2
fi

[ -n "$env_file" ] || env_file=".env.tenant.${code}"

if [ ! -f "$env_file" ]; then
    echo "BŁĄD: brak pliku środowiska ${env_file}. Wygeneruj go najpierw: scripts/pim-tenant-env.sh --code ${code}" >&2
    exit 2
fi

# Odczyt tekstowy, bez `source` — plik env z podstawieniem polecenia nie może
# wykonać się przy okazji odczytu.
read_env() {
    grep -E "^$1=" "$env_file" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

assets_bucket="$(read_env AWS_ASSETS_BUCKET)"
imports_bucket="$(read_env AWS_IMPORTS_BUCKET)"
exports_bucket="$(read_env AWS_EXPORTS_BUCKET)"
access_key="$(read_env AWS_ASSETS_KEY)"
secret_key="$(read_env AWS_ASSETS_SECRET)"
root_user="$(read_env MINIO_ROOT_USER)"
root_password="$(read_env MINIO_ROOT_PASSWORD)"
[ -n "$network" ] || network="$(read_env EDGE_NETWORK)"
[ -n "$network" ] || network="pim_default"

for pair in "AWS_ASSETS_BUCKET:$assets_bucket" "AWS_IMPORTS_BUCKET:$imports_bucket" \
            "AWS_EXPORTS_BUCKET:$exports_bucket" "AWS_ASSETS_KEY:$access_key" \
            "AWS_ASSETS_SECRET:$secret_key" "MINIO_ROOT_USER:$root_user" \
            "MINIO_ROOT_PASSWORD:$root_password"; do
    if [ -z "${pair#*:}" ]; then
        echo "BŁĄD: ${pair%%:*} jest pusty w ${env_file}." >&2
        exit 2
    fi
done

if [ "$access_key" = "$root_user" ]; then
    echo "BŁĄD: AWS_ASSETS_KEY jest równy MINIO_ROOT_USER — aplikacja dostałaby uprawnienia root do wszystkich bucketów." >&2
    exit 2
fi

echo "Tenant       : ${code}"
echo "Endpoint     : ${endpoint} (sieć ${network})"
echo "Buckety      : ${assets_bucket}, ${imports_bucket}, ${exports_bucket}"
echo "Użytkownik   : ${access_key}"

if [ "$dry_run" = true ]; then
    echo ""
    echo "[dry-run] Nie wykonano żadnej zmiany."
    exit 0
fi

# Polityka wpuszcza wyłącznie do trzech bucketów tenanta. Brak wpisu na
# `arn:aws:s3:::*` jest tu istotą rzeczy: MinIO odmawia wszystkiego, czego
# polityka nie dopuszcza wprost, więc listowanie cudzych bucketów nie przejdzie.
# Polityka jest przekazywana ZMIENNĄ ŚRODOWISKOWĄ, nie montowanym plikiem.
#
# Powód: skrypt bywa uruchamiany z wnętrza kontenera (provisioner, #2905),
# a demon Dockera rozwiązuje źródła montowań na HOŚCIE. Katalog z `mktemp`
# istnieje wtedy tylko w kontenerze wywołującym, więc `-v` dawał pusty montaż
# i `mc` kończył się „open /work/policy.json: no such file or directory".
# Zmienna środowiskowa jest widoczna niezależnie od tego, gdzie skrypt działa.
policy_json="$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:ListBucket", "s3:GetBucketLocation"],
      "Resource": [
        "arn:aws:s3:::${assets_bucket}",
        "arn:aws:s3:::${imports_bucket}",
        "arn:aws:s3:::${exports_bucket}"
      ]
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": [
        "arn:aws:s3:::${assets_bucket}/*",
        "arn:aws:s3:::${imports_bucket}/*",
        "arn:aws:s3:::${exports_bucket}/*"
      ]
    }
  ]
}
EOF
)"

policy_name="pim-tenant-${code}"

# `|| true` przy tworzeniu i przypinaniu: skrypt ma być idempotentny, a
# „już istnieje" / „już przypięte" nie są błędami. Zamiast ufać kodom wyjścia
# (różnią się między wersjami `mc`), skrypt na końcu WERYFIKUJE stan faktyczny
# — cicho nieprzypięta polityka oznaczałaby użytkownika bez ograniczeń, czyli
# dokładnie odwrotność celu tego ticketu.
# `--entrypoint sh`: obraz minio/mc ma `mc` jako entrypoint, więc bez tego
# powłoka trafiłaby do niego jako nieznana podkomenda.
docker run --rm --network "$network" \
    -e MC_HOST_tenant="http://${root_user}:${root_password}@${endpoint#http://}" \
    -e POLICY_JSON="$policy_json" \
    --entrypoint sh \
    minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 -c "
        printf '%s' \"\$POLICY_JSON\" > /tmp/policy.json
        mc mb -p tenant/${assets_bucket} || true
        mc mb -p tenant/${imports_bucket} || true
        mc mb -p tenant/${exports_bucket} || true
        mc anonymous set none tenant/${assets_bucket} || true
        mc version enable tenant/${assets_bucket} || true
        mc version enable tenant/${exports_bucket} || true
        mc ilm rule add --expire-days 7 tenant/${imports_bucket} || true
        mc admin user add tenant '${access_key}' '${secret_key}' || true
        mc admin policy create tenant ${policy_name} /tmp/policy.json || true
        mc admin policy attach tenant ${policy_name} --user '${access_key}' || true
    "

# Weryfikacja: użytkownik istnieje i ma przypiętą DOKŁADNIE tę politykę.
attached=$(docker run --rm --network "$network" \
    -e MC_HOST_tenant="http://${root_user}:${root_password}@${endpoint#http://}" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 admin user info tenant "$access_key" 2>/dev/null | grep -i "policy" || true)

if ! printf '%s' "$attached" | grep -q "$policy_name"; then
    echo "BŁĄD: polityka ${policy_name} nie jest przypięta do użytkownika ${access_key}." >&2
    echo "Stan zgłoszony przez MinIO: ${attached:-<brak>}" >&2
    echo "Użytkownik bez polityki nie ma dostępu do niczego albo ma za dużo — nie zostawiaj tego tak." >&2
    exit 1
fi

echo ""
echo "Gotowe. Polityka ${policy_name} przypięta do użytkownika ${access_key} (zweryfikowane)."
echo "Test negatywny (powinien ODMÓWIĆ dostępu do cudzego bucketu) jest częścią #2868."
