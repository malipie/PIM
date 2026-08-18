# ADR-0037 — Certyfikat wildcard przez DNS-01 dla subdomen tenantów

- **Status**: Accepted
- **Data**: 2026-08-18
- **Kontekst ticketu**: #2908 (TNT-P4-07), epik TNT faza M4
- **Powiązane**: ADR-0035 (instancja per tenant), ADR-0036 (instancja platformowa i provisioning), #2856 (jawna lista hostów — zastąpiona), `docs/runbook/tenant-instances.md`

## Kontekst

Każdy tenant ma własny adres `<kod>.app.harmonpim.pl` i własny stack (ADR-0035).
Do #2908 edge Caddy trzymał **jawny blok na hosta**: dołożenie klienta oznaczało
edycję konfiguracji edge i jej przeładowanie. To kolidowało z intencją całej
fazy M4 — *operator dodaje tenanta w panelu, reszta dzieje się sama* — bo
zostawiało ręczny krok dokładnie tam, gdzie pomyłka dotyka **wszystkich**
klientów naraz.

Trzeba było rozstrzygnąć dwie rzeczy: skąd edge wie, do której instancji
kierować ruch, i skąd bierze certyfikat dla adresu, którego nie było
w konfiguracji.

### Przesłanka, która okazała się błędna

Pierwotny zapis ticketu wybierał `on_demand_tls` z endpointem `ask`, bo
zakładał, że *„wildcard wymagałby DNS-01, czyli pluginu dla cyberfolks"*.
Po dołożeniu wildcard DNS w #2855 okazało się, że **domena jest na Cloudflare**,
a `caddy-dns/cloudflare` to standardowy, utrzymywany plugin. Przesłanka upadła
i decyzja wróciła do rozstrzygnięcia.

## Warianty

| | A. `on_demand_tls` + endpoint `ask` | B. Wildcard przez DNS-01 |
|---|---|---|
| Nowy tenant działa | po pierwszym wejściu (wystawienie certyfikatu) | **natychmiast** |
| Endpoint `ask` | wymagany | **nie istnieje** |
| Limity Let's Encrypt | 50 certyfikatów/tydzień na domenę | jeden certyfikat |
| Wymagania | brak dodatkowych | obraz Caddy z pluginem (`xcaddy`) + token API Cloudflare |
| Powierzchnia ryzyka | endpoint `ask` osiągalny z edge | token API Cloudflare w środowisku edge |

## Decyzja

**Wariant B — jeden certyfikat wildcard `*.app.harmonpim.pl`, wyzwaniem DNS-01
przez `caddy-dns/cloudflare`.** Decyzja operatora, 2026-08-18.

Rozstrzygnął nie komfort, tylko to, czego przy wildcardzie **nie ma**: endpointu
`ask`. Komponent, który przy każdym połączeniu TLS odpowiada na pytanie „czy ten
host istnieje", jest osiągalny z edge, musi być szybki, musi być odporny na
sondowanie i nie może ujawniać listy klientów. Wildcard usuwa go z układanki
zamiast utwardzać.

Routing: jeden blok `*.{$DOMAIN}`, kod tenanta brany z pierwszej etykiety hosta
przez `map` z wyrażeniem regularnym (**nie** przez `{labels.N}` — indeks
etykiety zależy od liczby członów domeny, więc jej zmiana po cichu przestawiłaby
routing na sąsiedni człon). Wzorzec jest ten sam, co w
`App\Shared\Domain\TenantSubdomain`, w generatorze środowiska i w provisionerze.

## Konsekwencje

### Pozytywne

- Nowa instancja jest osiągalna **bez wpisu w konfiguracji i bez przeładowania**
  edge. Znika jedyny ręczny krok stojący między panelem a działającym klientem.
- Usunięcie tenanta nie zostawia śladu w konfiguracji edge — zniknięcie
  kontenerów wystarczy.
- Limit Let's Encrypt przestaje być czynnikiem: certyfikat jest jeden,
  niezależnie od liczby klientów.
- Odnowienie certyfikatu nie zależy od tego, czy port 80 jest osiągalny
  z internetu — wyzwanie idzie przez DNS.

### Negatywne i ryzyka

- **Token API Cloudflare leży w środowisku edge.** Zawężony do jednej strefy
  i uprawnienia `Zone:DNS:Edit`, ale przejęcie kontenera edge daje kontrolę nad
  rekordami DNS tej domeny. To jest cena wybranego wariantu, przyjęta świadomie.
- **Jeden punkt awarii dla wszystkich klientów.** Wygasły token albo odebrane
  uprawnienie oznacza brak odnowienia certyfikatu dla **wszystkich** instancji
  naraz, nie dla jednej. Objaw i diagnostyka w runbooku.
- **Certyfikat obejmuje każdą nazwę w domenie**, także nieistniejące instancje.
  „Brak certyfikatu dla nieznanego hosta" przestaje być mechanizmem obronnym —
  odmowa musi paść w routingu i pada tam jako 404.
- **Nieudane połączenie z instancją jest z poziomu edge nieodróżnialne od jej
  braku.** Awaria żywego klienta pokazuje się więc jako 404, nie 502.
  Diagnostyka idzie przez `docker compose -p pim-<kod> ps` i alerty Prometheusa
  (#2866), które patrzą na instancje bezpośrednio.
- **Powłoka SPA jest wydawana pod nieznaną subdomeną, gdy klient nie prosi
  o HTML.** Żądania nawigacyjne przeglądarki są sondowane i dostają 404;
  zasoby statyczne — wspólne dla wszystkich instancji i pozbawione tożsamości
  klienta — są serwowane bez sondy, żeby nie płacić obiegiem do PHP za każdy
  plik. `curl` z `Accept: */*` dostanie więc bundel. Żadne wywołanie `/api/*`
  nie działa, więc nie wynika z tego dostęp do czegokolwiek.

### Wpływ na inne decyzje

- **#2856 (jawna lista hostów) jest zastąpione.** Snippet `pim_routes` zostaje
  — obsługuje zarówno stack współdzielony, jak i blok wildcard — ale wzorzec
  „dołóż blok hosta i przeładuj" znika z runbooka i ze skryptów.
- **ADR-0036 bez zmian.** Provisioner nadal nie dotyka konfiguracji edge; teraz
  nie ma czego dotykać.
