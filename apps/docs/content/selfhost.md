# Self-hosting — wymagania

Harmon PIM instaluje się na **jednym serwerze**, przez Docker Compose. Cały stos
(baza, wyszukiwarka, magazyn plików, hub SSE, monitoring) uruchamia się
w kontenerach — nie instalujesz ręcznie żadnego z tych komponentów. Nie jest
potrzebny Kubernetes ani zewnętrzna baza zarządzana.

Poniżej wszystko, co musisz mieć **przed** startem instalacji. Warunki prawne
wdrożenia na własnej infrastrukturze opisuje [licencja](/licence) — self-host
jest nieodpłatny w ramach Własnej Instancji.

## Serwer

| Zasób | Minimum |
| --- | --- |
| RAM | 8 GB |
| CPU | 4 vCPU |
| Dysk | 80 GB SSD |

Punktem odniesienia dla tego minimum jest katalog rzędu **50 000 SKU**. Zużycie
rośnie przede wszystkim z liczbą i wagą zdjęć produktowych (MinIO), rozmiarem
indeksu wyszukiwarki oraz liczbą równoległych workerów.

Wolumeny danych (Postgres, MinIO, Meilisearch, Prometheus) żyją w
`/var/lib/docker/volumes` — zaplanuj monitoring zapełnienia dysku. Metryki mają
retencję 15 dni, natomiast magazyn assetów rośnie bez górnego ograniczenia.

## System operacyjny i Docker

- **OS**: Ubuntu LTS albo Debian stable, z włączonymi `unattended-upgrades`.
- **Docker Engine + wtyczka compose** z oficjalnego repozytorium Dockera,
  `docker compose version` **≥ 2.24**.
- **Rotacja logów kontenerów** — ustaw limity w `/etc/docker/daemon.json`
  (`max-size=50m`, `max-file=5`). Logi zawierają adresy e-mail, więc ich retencja
  jest kwestią RODO, nie tylko miejsca na dysku.
- **SSH na kluczach** (`PasswordAuthentication no`), opcjonalnie fail2ban.

Serwisy startują z `restart: unless-stopped`, więc po reboocie hosta stos wstaje
samodzielnie.

## Domena, sieć i TLS

- **Publiczny adres IP** oraz rekord **`A`/`AAAA` wskazujący na host — założony
  zanim uruchomisz stos**. Certyfikat Let's Encrypt wystawia się automatycznie
  przy pierwszym starcie i wymaga rozwiązywalnej domeny.
- **Porty do otwarcia**: `80/tcp`, `443/tcp` oraz `443/udp` (HTTP/3). Dostęp SSH
  najlepiej ograniczyć do adresów operatora. Resztę zamknij — na zewnątrz
  publikowany jest wyłącznie edge (Caddy), pozostałe serwisy zostają w sieci
  wewnętrznej Dockera.
- **Firewall dostawcy to osobna warstwa.** Jeżeli Twój hosting ma własny firewall
  w panelu, musi on przepuszczać 80 i 443 niezależnie od `ufw` na hoście — samo
  otwarcie portów w systemie nie wystarczy.
- **Zewnętrzny monitor dostępności** (np. UptimeRobot, healthchecks.io). Wbudowany
  blackbox-exporter działa na tym samym hoście, więc awarię całej maszyny zobaczy
  wyłącznie monitor spoza niej.

## Poczta wychodząca

Wymagany jest **działający relay SMTP** — bez niego zaproszenia użytkowników
i reset hasła przestają działać po cichu (instancja produkcyjna nigdy nie
pokazuje tokenów zastępczych).

Na domenie nadawcy skonfiguruj **SPF, DKIM i DMARC**, inaczej wiadomości będą
lądować w spamie.

## Zasoby poza hostem

Cztery rzeczy trzeba zapewnić z zewnątrz — instalator ich nie utworzy:

1. **Domena i DNS** — jak wyżej.
2. **Relay SMTP** — konto i dane logowania.
3. **Miejsce na kopie zapasowe** — magazyn zgodny z S3 na repozytorium
   pgBackRest (WAL + backupy bazy). Powinien to być magazyn **oddzielny od
   instancji** — backup na tym samym dysku co baza nie chroni przed awarią hosta.
4. **Odbiorca alertów** — webhook PagerDuty / Slack / Opsgenie. Jest obowiązkowy:
   bez niego walidacja konfiguracji kończy się błędem, bo alert, którego nikt nie
   odbiera, nie jest alertem.

Opcjonalnie:

- **Druga lokalizacja S3** do replikacji zdjęć produktowych (profil `dr`).
- **Klucz API Anthropic** — jeżeli ma działać agent AI. Klucz jest wprowadzany
  per organizacja (BYOK) i pozostaje Twój.

## Narzędzia do zbudowania wydania

Na hoście (albo na maszynie budującej) potrzebne są:

- **git** — wdrożenie to `git clone` na wskazanym tagu.
- **Node ≥ 22** i **pnpm ≥ 10** (przez `corepack`) — do zbudowania bundla panelu
  administracyjnego, który następnie serwuje Caddy.

Obrazy backendu budują się przez `docker compose build` — poza Dockerem nic
więcej nie jest wymagane.

## Sekrety

Konfiguracja opiera się na jednym pliku `.env.prod`, tworzonym z dołączonego
szablonu. Szablon **nie zawiera żadnych wartości domyślnych** i jest fail-loud:
brakujący sekret przerywa uruchomienie z komunikatem wskazującym konkretny klucz,
zamiast wystartować na znanym publicznie haśle.

Przed instalacją przygotuj generator losowych sekretów (`openssl`) i menedżer
haseł. Wygenerować trzeba m.in.:

- sekret aplikacji Symfony,
- dwa hasła PostgreSQL — właściciela schematu oraz roli runtime, na której
  wymuszane jest RLS,
- klucz główny szyfrowania at-rest (AES-256-GCM) chroniący sekrety SSO, TOTP
  i klucze integracji,
- hasło do pary kluczy JWT (sama para generowana jest raz, na hoście —
  środowisko produkcyjne nigdy nie tworzy jej automatycznie),
- sekret podpisujący JWT huba SSE,
- klucz główny Meilisearch,
- dane dostępowe MinIO,
- klucze do magazynu kopii zapasowych.

## Co uruchamia się w kontenerach

Dla orientacji — czego nie musisz instalować ani utrzymywać osobno:

| Warstwa | Komponent |
| --- | --- |
| Edge / TLS | Caddy 2 (auto-HTTPS, HTTP/3) |
| Aplikacja | FrankenPHP + PHP 8.4 |
| Zadania w tle | workery kolejki (skalowalne) |
| Baza danych | PostgreSQL 16 + PgBouncer, kopie przez pgBackRest |
| Cache i blokady | Redis 7 |
| Wyszukiwarka | Meilisearch 1.13 |
| Pliki i zdjęcia | MinIO (API zgodne z S3) |
| Aktualizacje na żywo | Mercure (SSE) |
| Monitoring | Prometheus, Alertmanager, blackbox-exporter |

## Czego wymaga utrzymanie

Self-host to nie tylko instalacja. Zaplanuj, kto po stronie Twojej organizacji
odpowiada za:

- **próbne odtworzenie kopii zapasowej** — wykonane **zanim** do instancji trafią
  realne dane; backup niesprawdzony w odtworzeniu nie jest backupem,
- **aktualizacje** do kolejnych wydań (przełączenie tagu, przebudowa obrazów,
  migracje bazy) — procedura jest opisana krok po kroku w materiałach dołączonych
  do wydania,
- **rotację sekretów** i reakcję na alerty.

## Instrukcja instalacji

Szczegółowy przebieg — od czystego hosta, przez migracje bazy, utworzenie
pierwszej organizacji i konta właściciela, po smoke-test go-live — dołączamy do
wydania self-host.

Pytania przed wdrożeniem albo wątpliwość, czy Twój scenariusz mieści się
w licencji: [kontakt](https://harmonpim.pl).
