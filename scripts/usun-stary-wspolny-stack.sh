#!/usr/bin/env bash
#
# Usunięcie wolumenów wspólnego stacku po migracji na instancje per tenant
# (2026-08-19). Jednorazowe, uruchamiane z crona, samo się wyłącza po wykonaniu.
#
# ── Po co to istnieje ───────────────────────────────────────────────────────
#
# Przy migracji zatrzymaliśmy `api`, `worker`, `database` i `pgbouncer` wspólnego
# stacku, ale ZOSTAWILIŚMY ich wolumeny jako siatkę bezpieczeństwa: dopóki są,
# powrót do stanu sprzed migracji zajmuje minuty. Decyzja operatora: usunąć po
# siedmiu dniach, czyli od 2026-08-27.
#
# Skrypt jest napisany tak, żeby wykonać tę decyzję BEZ przypominania sobie
# o niej — i żeby odmówić wykonania, jeśli świat wygląda inaczej, niż zakładała.
#
# ── Jak z tego zrezygnować ──────────────────────────────────────────────────
#
#     touch /opt/pim/ZATRZYMAJ-USUWANIE-STAREGO-STACKU
#
# Tyle. Skrypt to zobaczy, zapisze w logu, wypnie własnego crona i nic nie ruszy.
#
# ── Czego NIE usuwa ─────────────────────────────────────────────────────────
#
# Wyłącznie trzy wolumeny wymienione z nazwy, i tylko gdy nie używa ich żaden
# kontener. Meilisearch, MinIO, Caddy, Prometheus, Mercure i Redis są usługami
# WSPÓLNYMI dla wszystkich instancji (ADR-0035) i pracują dalej — ich wolumeny
# nie są tu nawet wymienione, żeby pomyłka w edycji nie mogła ich objąć.

set -uo pipefail

DATA_WYKONANIA="2026-08-27"
STOP_FILE="/opt/pim/ZATRZYMAJ-USUWANIE-STAREGO-STACKU"
LOG="/opt/pim/backups/usuniecie-starego-stacku.log"
CRON="/etc/cron.d/pim-usun-stary-stack"

# Wolumeny wspólnego stacku pozostałe po zatrzymanych api/worker/database.
# Lista JAWNA — bez wzorców, bez glob. Wzorzec `pim_*` objąłby MinIO
# i Meilisearch, czyli magazyn plików i indeks WSZYSTKICH klientów.
WOLUMENY=(pim_postgres_data pim_api_var pim_api_jwt)

# Instancje, które muszą być zdrowe, zanim siatka bezpieczeństwa zniknie.
INSTANCJE=(pim-platform pim-harmon pim-trzeci-tenant)

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" | tee -a "$LOG"; }

wypnij_crona() {
    rm -f "$CRON"
    log "Cron wypięty ($CRON) — skrypt nie uruchomi się ponownie."
}

mkdir -p "$(dirname "$LOG")"

# ── 1. Rezygnacja operatora ────────────────────────────────────────────────
if [ -f "$STOP_FILE" ]; then
    log "WSTRZYMANE: istnieje ${STOP_FILE}. Nic nie usunięto."
    wypnij_crona
    exit 0
fi

# ── 2. Termin ──────────────────────────────────────────────────────────────
if [ "$(date +%Y-%m-%d)" \< "$DATA_WYKONANIA" ]; then
    exit 0   # jeszcze nie czas; cicho, żeby nie zaśmiecać logu codziennie
fi

log "── Usuwanie wolumenów wspólnego stacku ─────────────────────────────"

# ── 3. Czy nowy świat działa ───────────────────────────────────────────────
#
# Sedno zabezpieczenia: siatkę bezpieczeństwa wolno zdjąć TYLKO wtedy, gdy to,
# co ją zastąpiło, faktycznie działa. Gdyby któraś instancja była chora,
# usunięcie kopii sprzed migracji byłoby najgorszym możliwym ruchem.
niezdrowe=""
for inst in "${INSTANCJE[@]}"; do
    stan="$(docker inspect "${inst}-api-1" --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' 2>/dev/null || echo brak)"
    [ "$stan" = healthy ] || [ "$stan" = running ] || niezdrowe="${niezdrowe} ${inst}(${stan})"
done

if [ -n "$niezdrowe" ]; then
    log "ODMOWA: instancje nie są zdrowe:${niezdrowe}"
    log "Wolumeny ZOSTAJĄ. Napraw instancje albo zrezygnuj: touch ${STOP_FILE}"
    exit 1   # cron zostaje, spróbuje jutro
fi
log "Instancje zdrowe: ${INSTANCJE[*]}"

# ── 4. Czy dane sprzed migracji są gdzieś indziej ──────────────────────────
#
# Wolumen nie jest jedyną kopią i to musi być sprawdzone, a nie założone.
zrzut="$(ls -t /opt/pim/backups/migracja/*.dump 2>/dev/null | head -1)"
if [ -z "$zrzut" ] || [ ! -s "$zrzut" ]; then
    log "ODMOWA: nie widzę zrzutu logicznego sprzed migracji w backups/migracja/."
    log "Wolumeny ZOSTAJĄ — to była jedyna kopia poza nimi."
    exit 1
fi
log "Zrzut sprzed migracji: ${zrzut} ($(du -h "$zrzut" | cut -f1))"

# ── 5. Czy na pewno nikt ich nie używa ─────────────────────────────────────
uzywane=""
for v in "${WOLUMENY[@]}"; do
    kto="$(docker ps -a --format '{{.Names}}' --filter "volume=$v" | tr '\n' ' ')"
    [ -z "$kto" ] || uzywane="${uzywane} ${v}→${kto}"
done

if [ -n "$uzywane" ]; then
    log "ODMOWA: wolumeny są przypięte do kontenerów:${uzywane}"
    log "Usuń najpierw kontenery wspólnego stacku (docker compose ... rm -f api worker database pgbouncer)."
    exit 1
fi

# ── 6. Usunięcie ───────────────────────────────────────────────────────────
usuniete=0
for v in "${WOLUMENY[@]}"; do
    if ! docker volume inspect "$v" >/dev/null 2>&1; then
        log "  ${v}: już nie istnieje"
        continue
    fi
    rozmiar="$(docker system df -v --format '{{json .}}' 2>/dev/null | grep -o "\"Name\":\"${v}\"[^}]*" | grep -o '"Size":"[^"]*"' | cut -d'"' -f4)"
    if docker volume rm "$v" >/dev/null 2>&1; then
        log "  ${v}: USUNIĘTY ${rozmiar:+($rozmiar)}"
        usuniete=$((usuniete + 1))
    else
        log "  ${v}: BŁĄD usuwania — zostaje"
    fi
done

log "Usunięto ${usuniete} z ${#WOLUMENY[@]} wolumenów."
log "Powrót do stanu sprzed migracji jest od teraz możliwy WYŁĄCZNIE ze zrzutu"
log "logicznego (${zrzut}) albo z pgBackRest (stanza pim, kopia 20260819-111945F)."
wypnij_crona
