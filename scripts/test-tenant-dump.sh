#!/usr/bin/env bash
# Testy skryptu zrzutów `scripts/pim-tenant-dump.sh` (#2993).
#
# Dlaczego to istnieje: retencja kopii zapasowych jest kodem, który **kasuje
# pliki**, a jedyną informacją zwrotną z jej pomyłki jest brak pliku, którego
# nikt nie szuka, dopóki nie jest potrzebny. Tak przepadły zrzuty pre-deploy
# z wdrożenia 1075215c: sortowanie po nazwie (a nazwa zaczyna się od skrótu
# commita) uznało świeżo zrobiony zrzut za najstarszy i skasowało go sekundy
# po utworzeniu, na trzech instancjach naraz.
#
# Test uruchamia PRAWDZIWY skrypt w atrapie drzewa repozytorium, z atrapą
# `docker`, która odgrywa `pg_isready` i `pg_dump`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SKRYPT="${ROOT}/scripts/pim-tenant-dump.sh"

[ -f "$SKRYPT" ] || { echo "test-tenant-dump: brak ${SKRYPT}" >&2; exit 1; }

niepowodzenia=0
biezacy=""

zaczyna() { biezacy="$1"; }
ok()   { printf '  ✓ %s\n' "$1"; }
blad() { printf '  ✗ %s\n     %s\n' "$biezacy" "$1" >&2; niepowodzenia=$((niepowodzenia + 1)); }

przygotuj_drzewo() {
    local dir="$1"
    mkdir -p "${dir}/scripts" "${dir}/bin" "${dir}/backups/pre-deploy"
    cp "$SKRYPT" "${dir}/scripts/pim-tenant-dump.sh"
    : > "${dir}/docker-compose.tenant.yml"
    printf 'POSTGRES_DB=pim_acme\nPOSTGRES_USER=pim\n' > "${dir}/.env.tenant.acme"

    # Atrapa dockera: `pg_isready` przechodzi, `pg_dump` wypisuje na stdout
    # tyle bajtów, żeby przejść próg MIN_DUMP_BYTES (1024). ATRAPA_PGDUMP_FAIL
    # odgrywa nieudany zrzut, ATRAPA_PGDUMP_PUSTY — zrzut zbyt mały.
    cat > "${dir}/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
argumenty="$*"
case "$argumenty" in
    *pg_isready*) exit 0 ;;
    *pg_dump*)
        [ "${ATRAPA_PGDUMP_FAIL:-0}" = "1" ] && exit 1
        if [ "${ATRAPA_PGDUMP_PUSTY:-0}" = "1" ]; then
            printf 'x'
        else
            head -c 2048 /dev/zero | tr '\0' 'x'
        fi
        exit 0 ;;
esac
exit 0
DOCKER
    chmod +x "${dir}/bin/docker"
}

uruchom() {
    local dir="$1"; shift
    local rc=0
    (
        cd "$dir"
        PATH="${dir}/bin:${PATH}" bash scripts/pim-tenant-dump.sh "$@" \
            >"${dir}/out.txt" 2>"${dir}/err.txt"
    ) || rc=$?
    return "$rc"
}

# Plik zrzutu z zadanym wiekiem (minuty wstecz) — nazwa jak w produkcji.
podloz() {
    local dir="$1" nazwa="$2" minut="$3"
    local plik="${dir}/backups/pre-deploy/${nazwa}"
    head -c 2048 /dev/zero | tr '\0' 'x' > "$plik"
    # -d '-N minutes' jest GNU; BSD chce -v. Test ma działać na obu.
    if ! touch -d "-${minut} minutes" "$plik" 2>/dev/null; then
        touch -t "$(date -v-"${minut}"M +%Y%m%d%H%M 2>/dev/null || date +%Y%m%d%H%M)" "$plik"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════
# 1. Regresja #2993: świeży zrzut przeżywa retencję, choć jego tag sortuje się
#    najniżej ze wszystkich
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "świeży zrzut nie jest kasowany przez własną retencję"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"

# Dziesięć kopii z tagami, które leksykograficznie sortują się WYŻEJ niż
# `1075215c` — dokładnie układ z produkcji 2026-08-23.
i=0
for tag_stary in 1b1f8ca2 453237b6 73e23aa2 859db25e a039a6dd a9d71fe9 b4c7497a ea3f3905 f407370d nogit; do
    i=$((i + 1))
    podloz "$tmp" "acme-${tag_stary}-2026082${i}-100000.dump" $((1000 - i * 10))
done

rc=0; uruchom "$tmp" --code acme --label pre-deploy --tag 1075215c || rc=$?
[ "$rc" -eq 0 ] && ok "zrzut kończy się kodem 0" || blad "zrzut zwrócił ${rc} ($(cat "${tmp}/err.txt"))"

swiezy="$( { ls "${tmp}"/backups/pre-deploy/acme-1075215c-*.dump 2>/dev/null || true; } | head -1)"
if [ -n "$swiezy" ] && [ -s "$swiezy" ]; then
    ok "świeży zrzut istnieje po retencji (tag sortujący się najniżej)"
else
    blad "świeży zrzut zniknął — regresja #2993"
fi

liczba="$(ls "${tmp}"/backups/pre-deploy/acme-*.dump | wc -l | tr -d ' ')"
[ "$liczba" = "10" ] && ok "retencja utrzymała limit (10 plików)" \
    || blad "po retencji jest ${liczba} plików zamiast 10"

# Usunięty ma być NAJSTARSZY CZASEM (`1b1f8ca2`, 990 min temu), mimo że
# alfabetycznie jest wysoko. Gdyby retencja nadal patrzyła na nazwę, przetrwałby
# on, a zniknąłby świeży `1075215c`.
if ls "${tmp}"/backups/pre-deploy/acme-1b1f8ca2-*.dump >/dev/null 2>&1; then
    blad "najstarszy czasem (1b1f8ca2, 990 min) przetrwał — retencja nadal nie patrzy na czas"
else
    ok "usunięty został najstarszy czasem, nie nazwą"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 2. Limit `--keep` liczony po czasie
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "--keep zostawia N najnowszych"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"
podloz "$tmp" "acme-zzz-20260801-100000.dump" 500   # najstarszy, nazwa najwyższa
podloz "$tmp" "acme-aaa-20260802-100000.dump" 400
podloz "$tmp" "acme-mmm-20260803-100000.dump" 300
rc=0; uruchom "$tmp" --code acme --label pre-deploy --tag now --keep 2 || rc=$?
[ "$rc" -eq 0 ] || blad "zrzut zwrócił ${rc}"
liczba="$(ls "${tmp}"/backups/pre-deploy/acme-*.dump | wc -l | tr -d ' ')"
[ "$liczba" = "2" ] && ok "zostały dwa pliki" || blad "zostało ${liczba} plików zamiast 2"
ls "${tmp}"/backups/pre-deploy/acme-now-*.dump >/dev/null 2>&1 \
    && ok "świeży wśród zachowanych" || blad "świeży skasowany mimo --keep 2"
if ls "${tmp}"/backups/pre-deploy/acme-zzz-*.dump >/dev/null 2>&1; then
    blad "najstarszy (zzz, 500 min) przetrwał mimo --keep 2"
else
    ok "najstarsze usunięte"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 3. Kod tenanta będący przedrostkiem innego nie kasuje cudzych kopii
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "retencja nie rusza zrzutów tenanta o dłuższym kodzie"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"
printf 'POSTGRES_DB=pim_trzeci\nPOSTGRES_USER=pim\n' > "${tmp}/.env.tenant.trzeci"
printf 'POSTGRES_DB=pim_trzeci_tenant\nPOSTGRES_USER=pim\n' > "${tmp}/.env.tenant.trzeci-tenant"
for n in 1 2 3; do
    podloz "$tmp" "trzeci-tenant-tag${n}-2026080${n}-100000.dump" $((100 + n))
done
rc=0; uruchom "$tmp" --code trzeci --label pre-deploy --tag now --keep 1 || rc=$?
[ "$rc" -eq 0 ] || blad "zrzut zwrócił ${rc} ($(cat "${tmp}/err.txt"))"
liczba="$( { ls "${tmp}"/backups/pre-deploy/trzeci-tenant-*.dump 2>/dev/null || true; } | wc -l | tr -d ' ')"
[ "$liczba" = "3" ] && ok "trzy kopie 'trzeci-tenant' nietknięte przy retencji 'trzeci'" \
    || blad "retencja tenanta 'trzeci' zostawiła ${liczba} z 3 kopii 'trzeci-tenant'"
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 4. Kontrakt --print-path
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "--print-path daje na stdout wyłącznie ścieżkę"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"
rc=0; uruchom "$tmp" --code acme --label pre-deploy --tag abc --print-path || rc=$?
[ "$rc" -eq 0 ] || blad "zrzut zwrócił ${rc}"
sciezka="$(cat "${tmp}/out.txt")"
liczba_linii="$(wc -l < "${tmp}/out.txt" | tr -d ' ')"
[ "$liczba_linii" = "1" ] && ok "stdout ma dokładnie jedną linię" \
    || blad "stdout ma ${liczba_linii} linii — kontrakt to sama ścieżka"
if [ -n "$sciezka" ] && [ -s "${tmp}/${sciezka}" ]; then
    ok "wskazany plik istnieje i jest niepusty"
else
    blad "ścieżka '${sciezka}' nie wskazuje istniejącego pliku"
fi
grep -q 'OK \[acme\]' "${tmp}/err.txt" \
    && ok "komunikat dla człowieka poszedł na stderr" \
    || blad "komunikat 'OK [acme]' nie trafił na stderr"
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 5. Nieudany i podejrzanie mały zrzut nie zostawiają pliku udającego kopię
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "nieudany pg_dump nie zostawia pliku"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"
rc=0; ATRAPA_PGDUMP_FAIL=1 uruchom "$tmp" --code acme --label pre-deploy --tag abc || rc=$?
[ "$rc" -ne 0 ] && ok "nieudany zrzut → kod niezerowy" || blad "nieudany zrzut zwrócił 0"
liczba="$( { ls "${tmp}"/backups/pre-deploy/*.dump 2>/dev/null || true; } | wc -l | tr -d ' ')"
[ "$liczba" = "0" ] && ok "nie został żaden plik" || blad "został ${liczba} plik(ów) po nieudanym zrzucie"
rm -rf "$tmp"

zaczyna "zrzut poniżej progu jest usuwany"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp"
rc=0; ATRAPA_PGDUMP_PUSTY=1 uruchom "$tmp" --code acme --label pre-deploy --tag abc || rc=$?
[ "$rc" -ne 0 ] && ok "zrzut 1 B → kod niezerowy" || blad "zrzut 1 B zwrócił 0"
liczba="$( { ls "${tmp}"/backups/pre-deploy/*.dump 2>/dev/null || true; } | wc -l | tr -d ' ')"
[ "$liczba" = "0" ] && ok "plik udający kopię usunięty" || blad "został plik ${liczba} udający kopię"
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
if [ "$niepowodzenia" -gt 0 ]; then
    echo "" >&2
    echo "test-tenant-dump: ${niepowodzenia} niepowodzeń." >&2
    exit 1
fi
echo ""
echo "test-tenant-dump: wszystkie asercje przeszły."
