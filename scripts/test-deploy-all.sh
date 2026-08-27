#!/usr/bin/env bash
# Testy orkiestratora wdrożeń `scripts/pim-deploy-all.sh` (#2991).
#
# Dlaczego to w ogóle istnieje: skrypt wdrożeniowy jest jedynym kodem w tym
# repozytorium, którego błąd widać dopiero na produkcji KLIENTA i który nie ma
# żadnej innej bramki. Dwa defekty z 2026-08-23 były dokładnie tej klasy:
#
#   1. `cache:clear` wykonywany na DZIAŁAJĄCYM procesie (workery zalogowały
#      CRITICAL `Failed opening required …/getCatalogIndexFlushSubscriberService.php`),
#   2. licznik kolejki failed oparty na wyglądzie tabeli konsolowej — przy
#      #2989 raportował „czysto" mimo trzech wiadomości.
#
# Test uruchamia PRAWDZIWY skrypt w atrapie drzewa repozytorium, z atrapą
# `docker` w PATH, która zapisuje każde wywołanie. Asercje dotyczą KOLEJNOŚCI
# wywołań i kontraktu kodów wyjścia — nie treści komunikatów.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SKRYPT="${ROOT}/scripts/pim-deploy-all.sh"

[ -f "$SKRYPT" ] || { echo "test-deploy-all: brak ${SKRYPT}" >&2; exit 1; }

niepowodzenia=0
biezacy=""

zaczyna() { biezacy="$1"; }
ok()   { printf '  ✓ %s\n' "$1"; }
blad() { printf '  ✗ %s\n     %s\n' "$biezacy" "$1" >&2; niepowodzenia=$((niepowodzenia + 1)); }

# ── Atrapa środowiska ───────────────────────────────────────────────────────
#
# Kopia skryptu w katalogu tymczasowym: skrypt robi `cd "$(dirname "$0")/.."`,
# więc jego „korzeń repozytorium" to katalog nadrzędny kopii. Dzięki temu
# `.env.tenant.*` atrapy nie mieszają się z prawdziwym drzewem.
przygotuj_drzewo() {
    local dir="$1" topologia="$2"

    mkdir -p "${dir}/scripts" "${dir}/bin"
    cp "$SKRYPT" "${dir}/scripts/pim-deploy-all.sh"

    # Zrzut przed wdrożeniem — atrapa spełniająca kontrakt `--print-path`:
    # tworzy plik i wypisuje jego ścieżkę. ATRAPA_ZRZUT_PUSTY odgrywa defekt
    # z #2993 — kod wyjścia 0, a pliku nie ma (retencja skasowała go zaraz po
    # utworzeniu).
    cat > "${dir}/scripts/pim-tenant-dump.sh" <<'DUMP'
#!/usr/bin/env bash
kod=""
while [ $# -gt 0 ]; do
    case "$1" in
        --code) kod="${2:-}"; shift 2 ;;
        *) shift ;;
    esac
done
[ "${ATRAPA_ZRZUT_PUSTY:-0}" = "1" ] && exit 0
mkdir -p backups/pre-deploy
plik="backups/pre-deploy/${kod}-test.dump"
head -c 2048 /dev/zero | tr '\0' 'x' > "$plik"
printf '%s\n' "$plik"
DUMP
    chmod +x "${dir}/scripts/pim-tenant-dump.sh"

    : > "${dir}/docker-compose.tenant.yml"
    : > "${dir}/docker-compose.platform.yml"

    case "$topologia" in
        tenant)   printf 'TENANT_CODE=acme\n' > "${dir}/.env.tenant.acme" ;;
        platform) printf 'TENANT_CODE=platform\n' > "${dir}/.env.platform" ;;
        *) echo "nieznana topologia: ${topologia}" >&2; return 1 ;;
    esac

    # Atrapa dockera: loguje wywołania i odpowiada tak, jak zdrowy stack.
    cat > "${dir}/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
argumenty="$*"
printf '%s\n' "$argumenty" >> "$DOCKER_LOG"

case "$argumenty" in
    *"/api/objects"*) echo "401"; exit 0 ;;
    *"curl"*)         echo "200"; exit 0 ;;
    *"messenger:stats"*)
        # ATRAPA_FAILED_JSON pozwala odegrać zarówno pustą kolejkę, jak
        # i odpowiedź, z której nie da się odczytać licznika.
        printf '%s\n' "${ATRAPA_FAILED_JSON:-{\"transports\":{\"failed\":{\"count\":0}}\}}"
        exit 0 ;;
    *" logs "*)
        printf '%s' "${ATRAPA_LOGI:-}"
        exit 0 ;;
esac
exit 0
DOCKER
    chmod +x "${dir}/bin/docker"
}

uruchom() {
    # $1 katalog, reszta: argumenty skryptu. Zwraca kod wyjścia skryptu.
    local dir="$1"; shift
    local rc=0
    (
        cd "$dir"
        PATH="${dir}/bin:${PATH}" DOCKER_LOG="${dir}/docker.log" \
            bash scripts/pim-deploy-all.sh "$@" >"${dir}/out.txt" 2>"${dir}/err.txt"
    ) || rc=$?
    return "$rc"
}

# Numer pierwszej linii logu pasującej do wzorca (0 = brak).
linia() {
    local plik="$1" wzorzec="$2"
    grep -n -- "$wzorzec" "$plik" 2>/dev/null | head -1 | cut -d: -f1 || true
}

wymagaj_kolejnosc() {
    local plik="$1" pierwszy="$2" drugi="$3" opis="$4"
    local a b
    a="$(linia "$plik" "$pierwszy")"
    b="$(linia "$plik" "$drugi")"
    if [ -z "$a" ]; then blad "${opis}: brak wywołania pasującego do '${pierwszy}'"; return; fi
    if [ -z "$b" ]; then blad "${opis}: brak wywołania pasującego do '${drugi}'"; return; fi
    if [ "$a" -ge "$b" ]; then
        blad "${opis}: '${pierwszy}' (linia ${a}) miało wyprzedzić '${drugi}' (linia ${b})"
        return
    fi
    ok "$opis"
}

# ═══════════════════════════════════════════════════════════════════════════
# 1. Topologia tenanta: api + worker + agent-worker
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "topologia tenanta"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0; uruchom "$tmp" --tag test --skip-dump || rc=$?
log="${tmp}/docker.log"

[ "$rc" -eq 0 ] && ok "czysty przebieg kończy się kodem 0" \
    || blad "czysty przebieg zwrócił ${rc} zamiast 0 ($(cat "${tmp}/err.txt"))"

# Sedno #2991: żadnego czyszczenia cache na działającym procesie.
if grep -q 'exec .*cache:clear' "$log"; then
    blad "cache:clear poszedł przez 'exec' — czyści kontener DI pod działającym procesem (#2991)"
else
    ok "cache:clear nie używa 'exec' na działającej usłudze"
fi

for usluga in api worker; do
    if grep -q "run --rm --no-deps ${usluga} php bin/console cache:clear" "$log"; then
        ok "cache:clear dla '${usluga}' w jednorazowym kontenerze"
    else
        blad "brak cache:clear dla usługi '${usluga}'"
    fi
done

wymagaj_kolejnosc "$log" 'stop api worker agent-worker' 'cache:clear' \
    "stop usług wyprzedza cache:clear"
wymagaj_kolejnosc "$log" 'cache:clear' 'up -d --force-recreate' \
    "cache:clear wyprzedza wznowienie usług"
wymagaj_kolejnosc "$log" 'doctrine:migrations:migrate' 'stop api worker agent-worker' \
    "migracje idą przed zatrzymaniem usług"
wymagaj_kolejnosc "$log" 'doctrine:migrations:migrate' 'pim:db:schema:validate' \
    "read-only kontrakt schematu idzie po migracjach"
wymagaj_kolejnosc "$log" 'pim:db:schema:validate' 'stop api worker agent-worker' \
    "kontrakt schematu blokuje wypuszczenie kodu"
wymagaj_kolejnosc "$log" 'build api worker' 'doctrine:migrations:migrate' \
    "build wyprzedza migracje"
wymagaj_kolejnosc "$log" 'up -d --force-recreate' 'curl' \
    "smoke dopiero po wznowieniu usług"

# Stary, kruchy licznik nie może wrócić.
if grep -q 'messenger:failed:show' "$log"; then
    blad "licznik kolejki failed nadal parsuje tabelę 'messenger:failed:show' (#2989)"
else
    ok "kolejka failed liczona formatem maszynowym"
fi
if grep -q 'messenger:stats failed --format=json' "$log"; then
    ok "użyto 'messenger:stats failed --format=json'"
else
    blad "brak wywołania 'messenger:stats failed --format=json'"
fi

# Okno logów zaczyna się w chwili startu wdrożenia, nie „ostatnie 5 minut".
if grep -qE 'logs --no-color --since [0-9]{4}-[0-9]{2}-[0-9]{2}T' "$log"; then
    ok "logi czytane od znacznika czasu startu wdrożenia"
else
    blad "smoke nie czyta logów od początku okna wdrożenia"
fi

# `--force-recreate` kasuje kontener razem z jego logami, więc okno sprzed
# odtworzenia musi zostać odczytane WCZEŚNIEJ — inaczej CRITICAL z dokładnie
# tego okna (incydent #2991) przepada, a smoke melduje czysto.
wymagaj_kolejnosc "$log" 'logs --no-color --since' 'up -d --force-recreate' \
    "logi starego kontenera odczytane przed jego odtworzeniem"
odczyty_logow="$(grep -c 'logs --no-color --since' "$log" || true)"
if [ "${odczyty_logow:-0}" -ge 2 ]; then
    ok "logi czytane po obu stronach odtworzenia kontenerów"
else
    blad "tylko ${odczyty_logow} odczyt logów — okno sprzed odtworzenia albo po starcie zostaje nieprzejrzane"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 2. Topologia platformy: api + provisioner, BEZ workera
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "topologia platformy"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" platform
rc=0; uruchom "$tmp" --tag test --skip-dump || rc=$?
log="${tmp}/docker.log"

[ "$rc" -eq 0 ] && ok "czysty przebieg kończy się kodem 0" \
    || blad "czysty przebieg zwrócił ${rc} zamiast 0 ($(cat "${tmp}/err.txt"))"

if grep -q 'worker' "$log"; then
    blad "platforma nie ma usługi 'worker', a skrypt ją wywołał"
else
    ok "żadne wywołanie nie dotyczy nieistniejącej usługi 'worker'"
fi
if grep -q 'run --rm --no-deps api php bin/console cache:clear' "$log"; then
    ok "cache:clear dla 'api'"
else
    blad "brak cache:clear dla 'api'"
fi
if grep -q 'no-deps provisioner php' "$log"; then
    blad "provisioner nie ma PHP — cache:clear tam nie ma czego czyścić"
else
    ok "provisioner nie dostaje poleceń Symfony"
fi
wymagaj_kolejnosc "$log" 'stop api provisioner' 'cache:clear' \
    "stop usług wyprzedza cache:clear"
wymagaj_kolejnosc "$log" 'cache:clear' 'up -d --force-recreate api provisioner' \
    "cache:clear wyprzedza wznowienie usług"
if grep -q -- '-f docker-compose.platform.yml' "$log"; then
    ok "użyto pliku Compose platformy"
else
    blad "platforma wdrażana nie tym plikiem Compose"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 3. Regresja #2989: niepusta kolejka failed MUSI dać ostrzeżenie i kod 70
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "niepusta kolejka failed"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
ATRAPA_FAILED_JSON='{"transports":{"failed":{"count":3}}}' uruchom "$tmp" --tag test --skip-dump || rc=$?

[ "$rc" -eq 70 ] && ok "trzy wiadomości w kolejce → kod 70" \
    || blad "trzy wiadomości w kolejce dały kod ${rc}, oczekiwano 70"
if grep -q '3 wiadomości w kolejce failed' "${tmp}/err.txt"; then
    ok "ostrzeżenie wymienia liczbę wiadomości"
else
    blad "brak ostrzeżenia o kolejce failed w wyjściu błędów"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 4. Nieodczytana kolejka to ostrzeżenie, a NIE ciche zero
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "kolejka failed nie do policzenia"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
ATRAPA_FAILED_JSON='Warning: transport unavailable' uruchom "$tmp" --tag test --skip-dump || rc=$?

[ "$rc" -eq 70 ] && ok "brak licznika → kod 70" \
    || blad "brak licznika dał kod ${rc}, oczekiwano 70"
if grep -q 'nie do policzenia\|nie udało się odczytać' "${tmp}/err.txt"; then
    ok "ostrzeżenie mówi wprost, że licznika nie odczytano"
else
    blad "nieodczytany licznik przeszedł bez śladu w wyjściu"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 5. CRITICAL w oknie wdrożenia → ostrzeżenie; sama odmowa RBAC → cisza
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "błędy krytyczne w oknie wdrożenia"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
ATRAPA_LOGI='worker-1  | [critical] Uncaught Error: Failed opening required /app/var/cache/prod/ContainerX/getFooService.php' \
    uruchom "$tmp" --tag test --skip-dump || rc=$?
[ "$rc" -eq 70 ] && ok "CRITICAL workera → kod 70" \
    || blad "CRITICAL w logach dał kod ${rc}, oczekiwano 70"
grep -q 'błędy krytyczne' "${tmp}/err.txt" && ok "ostrzeżenie cytuje logi" \
    || blad "brak ostrzeżenia o błędach krytycznych"
rm -rf "$tmp"

zaczyna "błąd 4xx nie jest awarią"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
# #2881 — RBAC loguje odmowę jako `Uncaught PHP Exception AccessDenied…`.
# 404 od pierwszego lepszego bota wygląda w logu identycznie; gdyby oba
# podnosiły ostrzeżenie, kod 70 świeciłby po każdym wdrożeniu i przestałby
# cokolwiek znaczyć.
ATRAPA_LOGI='api-1  | [warning] Uncaught PHP Exception AccessDeniedHttpException: "Access Denied."
api-1  | [warning] Uncaught PHP Exception NotFoundHttpException: "No route found for GET /wp-login.php"' \
    uruchom "$tmp" --tag test --skip-dump || rc=$?
[ "$rc" -eq 0 ] && ok "odmowa RBAC i 404 nie podnoszą ostrzeżenia" \
    || blad "błąd 4xx policzony jako awaria (kod ${rc})"
rm -rf "$tmp"

# Wykluczenie 4xx nie może zjeść prawdziwego fatala, który przypadkiem mówi
# „not found" — stąd wykluczenie wrażliwe na wielkość liter (nazwy klas).
zaczyna "fatal mówiący 'not found' nadal jest awarią"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
ATRAPA_LOGI='worker-1  | PHP Fatal error: Uncaught Error: Failed opening required /app/var/cache/prod/ContainerX/getFooService.php (include_path=...): file not found' \
    uruchom "$tmp" --tag test --skip-dump || rc=$?
[ "$rc" -eq 70 ] && ok "fatal z 'not found' w treści → kod 70" \
    || blad "fatal zjedzony przez filtr 4xx (kod ${rc})"
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
# 6. Krok 1 ufa PLIKOWI, nie kodowi wyjścia skryptu zrzutu (#2993)
# ═══════════════════════════════════════════════════════════════════════════
zaczyna "zrzut przed wdrożeniem"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0; uruchom "$tmp" --tag test || rc=$?
[ "$rc" -eq 0 ] && ok "istniejący plik zrzutu przepuszcza wdrożenie" \
    || blad "wdrożenie z poprawnym zrzutem zwróciło ${rc} ($(cat "${tmp}/err.txt"))"
grep -q 'kopia: backups/pre-deploy/acme-test.dump' "${tmp}/out.txt" \
    && ok "ścieżka i rozmiar kopii wypisane w przebiegu" \
    || blad "przebieg nie pokazuje, gdzie wylądowała kopia"
rm -rf "$tmp"

zaczyna "zrzut kończy się zerem, ale pliku nie ma"
tmp="$(mktemp -d)"
przygotuj_drzewo "$tmp" tenant
rc=0
ATRAPA_ZRZUT_PUSTY=1 uruchom "$tmp" --tag test || rc=$?
[ "$rc" -eq 20 ] && ok "brak pliku kopii przerywa wdrożenie (kod 20)" \
    || blad "wdrożenie ruszyło bez kopii (kod ${rc}) — dokładnie defekt #2993"
if grep -q 'docker' "${tmp}/docker.log" 2>/dev/null; then
    blad "wdrożenie zdążyło dotknąć dockera mimo braku kopii"
else
    ok "nic nie zostało zbudowane ani zmigrowane bez kopii"
fi
rm -rf "$tmp"

# ═══════════════════════════════════════════════════════════════════════════
if [ "$niepowodzenia" -gt 0 ]; then
    echo "" >&2
    echo "test-deploy-all: ${niepowodzenia} niepowodzeń." >&2
    exit 1
fi
echo ""
echo "test-deploy-all: wszystkie asercje przeszły."
