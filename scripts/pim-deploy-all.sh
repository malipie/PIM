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
#                                         się PRZED wypuszczeniem kodu,
#                                         niezależnie od tego, co pokazuje diff
#                                         bieżącej partii
#   4. stop usług aplikacyjnych         — patrz „Dlaczego stop przed cache" niżej
#   5. cache:clear w JEDNORAZOWYM       — `run --rm --no-deps`; kontener DI jest
#      kontenerze, osobno per usługa      przebudowywany, gdy nikt go nie czyta
#   6. up -d --force-recreate           — nowe procesy z nowym obrazem i świeżym
#                                         cache; wolumen /app/var przesłania DI
#                                         z obrazu, więc sam nowy obraz nie
#                                         wystarcza
#   7. smoke                            — instancja odpowiada, zanim ruszy kolejna
#
# ── Dlaczego stop PRZED cache:clear (#2991) ────────────────────────────────
#
# Do 859db25e krok 5 wyglądał tak: `exec api cache:clear` + `exec worker
# cache:clear`, a dopiero potem `restart`. `cache:clear` kasuje pliki
# skompilowanego kontenera DI, z których DZIAŁAJĄCE procesy nadal korzystają —
# FrankenPHP w trybie worker i konsument Messengera ładują usługi leniwie.
# Wdrożenie 2026-08-23 pokazało to wprost: workery `harmon` i `trzeci-tenant`
# zalogowały CRITICAL `Failed opening required
# /app/var/cache/prod/ContainerNfSihWw/getCatalogIndexFlushSubscriberService.php`
# dokładnie w oknie między czyszczeniem a restartem. Restart naprawiał proces
# chwilę później, ale wiadomość przetwarzana w tym oknie trafiała do kolejki
# failed i po naprawie wykonywała się DRUGI RAZ.
#
# Świadomy koszt: między krokiem 4 a 6 instancja nie obsługuje ruchu (kilka do
# kilkudziesięciu sekund). Poprzednia kolejność i tak kończyła się restartem,
# więc przerwa nie jest nowa — nowa jest gwarancja, że żaden proces nie
# odwołuje się do skasowanego kontenera DI.
#
# ── Dlaczego liczymy kolejkę failed przez JSON, a nie przez grep tabeli ─────
#
# Poprzednia wersja liczyła `messenger:failed:show | grep -cE '^ [0-9]+'`.
# Przy incydencie #2989 kolejka miała trzy wiadomości, a skrypt zameldował
# brak problemu: parser zależał od wyglądu tabeli konsolowej. Teraz liczy
# `messenger:stats failed --format=json` (kontrakt maszynowy Symfony), a gdy
# odczyt się nie powiedzie, mówi to wprost zamiast raportować zero.
#
# FAIL-FAST: błąd na pierwszej instancji zatrzymuje przebieg. Zła migracja ma
# paść na jednym kliencie, nie na wszystkich po kolei.
#
# KODY WYJŚCIA:
#   0   wszystkie instancje wdrożone, smoke czysty
#   70  wszystkie instancje wdrożone, ale smoke zgłosił OSTRZEŻENIA (niepusta
#       kolejka failed / błędy krytyczne w oknie wdrożenia / niepoliczalna
#       kolejka). Kod jest niezerowy świadomie: „7/7 OK" nie może być
#       odpowiedzią, gdy w logach wdrożenia są CRITICAL-e.
#   20/30/40/50/60  przerwane wdrożenie — patrz komunikat błędu.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.tenant.yml"

# Kod wyjścia dla „wdrożone, ale smoke ma zastrzeżenia".
EXIT_Z_OSTRZEZENIAMI=70

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

Kody wyjścia:
  0    wdrożone, smoke czysty
  70   wdrożone, ale smoke zgłosił ostrzeżenia (kolejka failed, błędy
       krytyczne w oknie wdrożenia, niepoliczalna kolejka)
  inne wdrożenie przerwane — komunikat wskazuje krok
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
  4. stop usług aplikacyjnych (nikt nie może czytać kasowanego cache)
  5. cache:clear w jednorazowym kontenerze, osobno per usługa
  6. up -d --force-recreate
  7. smoke (HTTP, 401, kolejka failed, logi krytyczne od startu wdrożenia)
Przerwanie na pierwszym błędzie.
EOF
    exit 0
fi

# Ostrzeżenia zebrane ze smoke'u wszystkich instancji. Niepuste = kod 70.
ostrzezenia=()

deploy_one() {
    local tenant="$1"
    local env_file=".env.tenant.${tenant}"
    local project="pim-${tenant}"
    local compose_file="$COMPOSE_FILE"

    # Usługi, które biorą udział we wdrożeniu. NIE są takie same w obu stackach
    # i to jest istota różnicy, nie szczegół: instancja platformowa **nie ma
    # workera** (nie przetwarza katalogu ani importów), za to ma provisionera.
    # Zaszyte `api worker` wywracało wdrożenie na kroku 2 komunikatem
    # `no such service: worker`.
    local do_budowy="api worker"      # obrazy do przebudowania
    local z_cache="api worker"        # usługi Symfony — mają kernel.cache_dir
    local aplikacyjne="api worker"    # procesy zatrzymywane i wznawiane

    if [ "$tenant" = platform ]; then
        env_file=".env.platform"
        project="pim-platform"
        compose_file="docker-compose.platform.yml"
        do_budowy="api provisioner"
        # Provisioner to kontener Pythona bez Symfony — `cache:clear` nie ma
        # tam czego wyczyścić, a `run --rm provisioner php …` padłby na braku
        # binarki. Zatrzymywany i wznawiany jest mimo to: niesie nowy obraz.
        z_cache="api"
        aplikacyjne="api provisioner"
    fi

    dc() { docker compose -p "$project" --env-file "$env_file" -f "$compose_file" "$@"; }

    echo "── ${tenant} ──────────────────────────────────────────"

    # Początek okna wdrożenia — smoke czyta logi OD TEGO momentu, nie „od
    # ostatnich 5 minut". Błędy krytyczne z kroków 4–6 mają się w nim zmieścić
    # (#2991: CRITICAL workera wypadał poza arbitralne okno).
    local okno_od
    okno_od="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if [ "$skip_dump" = true ]; then
        echo "  [1/7] zrzut POMINIĘTY"
    else
        echo "  [1/7] zrzut przed wdrożeniem"
        # `--env-file` i `--compose` przekazywane jawnie: instancja platformowa
        # nie trzyma środowiska pod nazwą `.env.tenant.<kod>`, więc bez tego
        # zrzut szukałby pliku, którego nie ma, i wdrożenie padało na kroku 1.
        bash scripts/pim-tenant-dump.sh --code "$tenant" --label pre-deploy --tag "$tag" \
            --env-file "$env_file" --compose "$compose_file" >/dev/null || {
            echo "  BŁĄD: zrzut się nie powiódł — wdrożenie na ${tenant} PRZERWANE." >&2
            return 20
        }
    fi

    echo "  [2/7] build ${do_budowy}"
    # shellcheck disable=SC2086 # lista usług ma się rozwinąć na osobne argumenty
    dc build $do_budowy >/dev/null 2>&1 || { echo "  BŁĄD: build nie powiódł się." >&2; return 30; }

    # Migracje z NOWEGO obrazu, zanim nowe kontenery zaczną obsługiwać ruch.
    echo "  [3/7] migracje (nowy obraz, przed wypuszczeniem kodu)"
    dc run --rm --no-deps api php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null 2>&1 \
        || { echo "  BŁĄD: migracje nie przeszły." >&2; return 40; }

    # KROK 4 i 5 są nierozłączne: cache wolno czyścić tylko wtedy, gdy żaden
    # proces nie trzyma skompilowanego kontenera DI (#2991).
    echo "  [4/7] stop ${aplikacyjne} (przed czyszczeniem cache)"
    # shellcheck disable=SC2086 # jw.
    dc stop $aplikacyjne >/dev/null 2>&1 || { echo "  BŁĄD: zatrzymanie usług nie powiodło się." >&2; return 30; }

    # Każdy kontener aplikacji ma własny kernel.cache_dir (api /app/var/cache,
    # worker /app/var/cache-worker, o ile ma APP_CACHE_DIR), więc czyszczenie
    # tylko w jednym zostawia drugi na kontenerze DI sprzed zmiany. Katalog
    # bywa też WSPÓLNY — wtedy drugie wywołanie jest tylko kosztem czasu.
    # `run --rm --no-deps` zamiast `exec`: usługi są zatrzymane, a jednorazowy
    # kontener ma ten sam wolumen /app/var, to samo środowisko i NOWY obraz.
    echo "  [5/7] cache:clear (jednorazowy kontener) w: ${z_cache}"
    for usluga in $z_cache; do
        dc run --rm --no-deps "$usluga" php bin/console cache:clear >/dev/null 2>&1 \
            || { echo "  BŁĄD: cache:clear dla ${usluga} nie powiódł się." >&2; return 50; }
    done

    echo "  [6/7] up -d --force-recreate ${aplikacyjne}"
    # Logi STAREGO kontenera zbierane tu, a nie w smoke'u: `--force-recreate`
    # kasuje kontener razem z jego logami, więc po tym kroku `docker compose
    # logs` nie pamięta już okna kroków 1–5. Dokładnie w tym oknie padły
    # workery przy wdrożeniu 2026-08-23 (#2991), więc smoke, który go nie
    # widzi, nie jest smoke'em.
    local logi_sprzed_odtworzenia
    # shellcheck disable=SC2086 # jw.
    logi_sprzed_odtworzenia="$(dc logs --no-color --since "$okno_od" $aplikacyjne 2>/dev/null || true)"

    # --force-recreate: kontener zatrzymany w kroku 4 wstałby na SWOIM starym
    # obrazie, gdyby Compose uznał konfigurację za niezmienioną. Wymuszone
    # odtworzenie gwarantuje proces z obrazu z kroku 2.
    # shellcheck disable=SC2086 # jw.
    dc up -d --force-recreate $aplikacyjne >/dev/null 2>&1 \
        || { echo "  BŁĄD: usługi aplikacyjne nie wstały." >&2; return 30; }
    # Konwergencja reszty stacku: nowa usługa dołożona w tej partii (albo
    # zatrzymana ręcznie przy diagnostyce) ma wstać razem z wdrożeniem.
    dc up -d >/dev/null 2>&1 || { echo "  BŁĄD: kontenery nie wstały." >&2; return 30; }

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

    # ── Kolejka failed ──────────────────────────────────────────────────────
    #
    # Wiadomości, które padły w oknie wdrożenia, zostają w kolejce failed i po
    # naprawie WYKONAŁYBY SIĘ PONOWNIE (na produkcji był to powtórny import).
    # Format maszynowy: {"transports":{"failed":{"count":N}}}.
    local failed_json failed_count
    failed_json="$(dc exec -T api php bin/console messenger:stats failed --format=json 2>/dev/null || true)"
    failed_count="$(printf '%s' "$failed_json" | tr -d ' \t\r\n' | sed -n 's/.*"failed":{"count":\([0-9][0-9]*\)}.*/\1/p')"

    if [ -z "$failed_count" ]; then
        # Świadomie ostrzeżenie, nie cisza: milczące „0" przy niepustej kolejce
        # to dokładnie ta luka, przez którą #2989 wyglądał na czysty deploy.
        echo "  UWAGA: nie udało się odczytać liczby wiadomości w kolejce failed" >&2
        echo "         (messenger:stats failed --format=json nie zwrócił licznika)" >&2
        ostrzezenia+=("${tenant}: kolejka failed nie do policzenia")
    elif [ "$failed_count" != "0" ]; then
        echo "  UWAGA: ${failed_count} wiadomości w kolejce failed — sprawdź przed ponowieniem" >&2
        echo "         (messenger:failed:show / :remove; ponowione wykonają się drugi raz)" >&2
        ostrzezenia+=("${tenant}: ${failed_count} wiadomości w kolejce failed")
    else
        echo "        kolejka failed = 0"
    fi

    # ── Błędy krytyczne w oknie wdrożenia ───────────────────────────────────
    #
    # #2881: odmowa uprawnień loguje się jako `Uncaught PHP Exception
    # AccessDeniedHttpException` — to normalna praca RBAC, nie awaria, więc
    # jest odfiltrowana. Reszta (CRITICAL/EMERGENCY/Fatal/Uncaught) to sygnał,
    # że coś w oknie wdrożenia padło, nawet jeśli usługi są teraz zdrowe.
    local krytyczne logi_okna
    # shellcheck disable=SC2086 # jw.
    logi_okna="${logi_sprzed_odtworzenia}
$(dc logs --no-color --since "$okno_od" $aplikacyjne 2>/dev/null || true)"
    krytyczne="$(printf '%s' "$logi_okna" \
        | grep -iE 'critical|emergency|fatal|uncaught' \
        | grep -vi 'AccessDenied' \
        | head -20 || true)"
    if [ -n "$krytyczne" ]; then
        echo "  UWAGA: błędy krytyczne w logach od ${okno_od}:" >&2
        printf '         %s\n' "$krytyczne" >&2
        ostrzezenia+=("${tenant}: błędy krytyczne w oknie wdrożenia (logi od ${okno_od})")
    else
        echo "        brak błędów krytycznych w logach od ${okno_od}"
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

if [ "${#ostrzezenia[@]}" -gt 0 ]; then
    echo "" >&2
    echo "SMOKE ZGŁOSIŁ OSTRZEŻENIA (kod ${EXIT_Z_OSTRZEZENIAMI}) — kod jest wdrożony, ale nie melduj „wszystko czyste”:" >&2
    printf '  - %s\n' "${ostrzezenia[@]}" >&2
    exit "$EXIT_Z_OSTRZEZENIAMI"
fi
