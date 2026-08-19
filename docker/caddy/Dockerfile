# TNT-P4-07 (#2908) — edge Caddy z pluginem DNS Cloudflare.
#
# Po co własny obraz: certyfikat wildcard `*.app.harmonpim.pl` można uzyskać
# WYŁĄCZNIE wyzwaniem DNS-01, a to wymaga, żeby Caddy umiał zapisać rekord TXT
# w strefie. Oficjalny obraz nie zawiera żadnego providera DNS — dokłada się je
# przy budowaniu przez `xcaddy`.
#
# Wybór wariantu (decyzja operatora, 2026-08-18, komentarz do #2908): wildcard
# przez DNS-01 zamiast certyfikatów per host wystawianych `on_demand_tls`.
# Rozstrzygnął nie komfort, tylko to, czego przy wildcardzie NIE MA:
#
#   * endpointu `ask`, który przy każdym połączeniu TLS odpowiadałby na pytanie
#     „czy ten host istnieje" — komponent osiągalny z edge i wymagający
#     odporności na sondowanie,
#   * ryzyka limitu Let's Encrypt (50 certyfikatów/tydzień na domenę), realnego
#     przy wielu klientach i pętli ponowień.
#
# Kosztem jest token API Cloudflare w środowisku edge. Zawężony do JEDNEJ strefy
# i uprawnienia `Zone:DNS:Edit`, przekazywany zmienną środowiskową, nigdy
# w repozytorium.
#
# Wersje pinowane świadomie: obraz edge stoi przed wszystkimi instancjami, więc
# „najnowsza stabilna" ma być decyzją, a nie skutkiem daty przebudowy.

ARG CADDY_VERSION=2.10.0

FROM caddy:${CADDY_VERSION}-builder-alpine AS builder

# Wersja pluginu pinowana tak samo jak wszystko inne w lockfile'ach projektu.
#
# MINIMUM to v0.2.4 — nie „najnowsza dla zasady". Plugin waliduje KSZTAŁT tokenu
# po swojej stronie, a Cloudflare wystawia dziś tokeny z prefiksem (`cfut_` dla
# użytkownika, `cfat_` dla konta). Wersje do v0.2.3 znały wyłącznie stary format
# 35–50 znaków bez prefiksu i odrzucały token **ważny według API Cloudflare**
# komunikatem „API token appears invalid". Objaw jest mylący: wygląda na literówkę
# w sekrecie, a jest niezgodnością wersji. Znalezione przy wdrożeniu #2908.
ARG CADDY_DNS_CLOUDFLARE_VERSION=v0.2.4

RUN xcaddy build \
    --with github.com/caddy-dns/cloudflare@${CADDY_DNS_CLOUDFLARE_VERSION}

FROM caddy:${CADDY_VERSION}-alpine

COPY --from=builder /usr/bin/caddy /usr/bin/caddy

# Dowód, że plugin faktycznie jest w binarce. Bez tego błędna wersja pluginu
# albo cicha porażka `xcaddy build` wychodziłaby dopiero przy pierwszym
# odnowieniu certyfikatu — czyli w nocy, na produkcji, u wszystkich klientów
# naraz.
RUN caddy list-modules | grep -q '^dns.providers.cloudflare$' \
    || (echo 'BŁĄD: obraz nie zawiera modułu dns.providers.cloudflare' >&2; exit 1)
