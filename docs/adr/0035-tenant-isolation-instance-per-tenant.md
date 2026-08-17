# ADR-0035 — Instancja per tenant: własny Postgres, wspólne wyszukiwanie i storage

- **Status**: Accepted
- **Data**: 2026-08-17
- **Kontekst ticketu**: #2854 (TNT-P0-01), epik TNT — tenanty na subdomenach + izolacja per instancja
- **Powiązane**: ADR-003 (multi-tenant ready, single-tenant deployed), `docs/multi-tenancy.md`, AUD-002/W1-1 (FORCE RLS + rola `pim_app`), #2902 (ADR instancji platformowej — osobna decyzja)

## Kontekst

PIM działa dziś jako **jedna instancja z jednym tenantem** (`harmon`) pod jednym adresem `app.harmonpim.pl`. Izolacja danych jest zbudowana dwuwarstwowo i działa: Doctrine `TenantFilter` na 76 encjach `TenantScoped` + Postgres RLS `ENABLE + FORCE` na 43 tabelach z `tenant_id`, przy połączeniu runtime jako `pim_app` (`NOSUPERUSER`, `NOBYPASSRLS`, nie-owner). Dodanie drugiego tenanta do tej instancji jest technicznie możliwe od dnia pierwszego.

Mimo to przed pierwszym wielotenantowym wdrożeniem stanęły dwa wymagania, których obecny model **nie spełnia**:

1. **Odtworzenie kopii pojedynczego tenanta.** Kopie zapasowe robi pgBackRest (`scripts/pim-backup-restore.sh`, stanza `pim`), a jego jednostką operacyjną jest **cały klaster Postgresa**. Operacja „przywróć dane tenanta X do stanu z 14:32" jest dziś nieosiągalna bez cofnięcia w czasie wszystkich pozostałych tenantów.
2. **Wydzielenie tenanta na dedykowany serwer.** Przy wspólnej bazie oznacza to logiczną migrację danych (dump wybranych wierszy + restore + weryfikacja spójności FK), czyli operację ryzykowną i trudną do przećwiczenia.

Do tego dochodzi wymaganie adresowania: każdy tenant ma być dostępny pod `<tenant>.app.harmonpim.pl`, z subdomeną wybieraną przy zakładaniu klienta.

### Co ustaliła analiza kodu (2026-08-13)

Ustalenia poniżej są przesłankami decyzji. Jeśli któreś przestanie być prawdziwe, ADR wymaga rewizji.

| Ustalenie | Dowód w kodzie | Znaczenie |
|---|---|---|
| SPA jest w pełni origin-relative | `apps/admin/src/lib/http.ts`, `apps/admin/src/lib/mercure/index.ts` (`window.location.origin`) | Jeden zbudowany bundel obsługuje każdą subdomenę — bez rebuildu i bez konfiguracji per tenant |
| Tenant rozwiązywany po zalogowanym principalu, nie po hoście | `App\Identity\Application\CurrentTenantProvider` | Instancja z jednym tenantem nie wymaga **żadnej** zmiany w kodzie rozwiązywania kontekstu |
| Jedyna zależność od hosta to `APP_BASE_URL` | `InvitationService`, `PasswordResetService`, `UserCreateService`, `SsoCallbackController` | Zmienna środowiskowa per instancja; ale każda subdomena to osobny redirect URI do rejestracji u dostawcy SSO |
| pgBackRest operuje na całym klastrze | `scripts/pim-backup-restore.sh`, `docker/postgres/pim-init-backup.sh` | **Sedno decyzji** — niezależny PITR wymaga osobnego klastra, nie osobnej bazy |
| Bucket / Meili / Mercure konfigurowane środowiskiem | `AWS_*_BUCKET`, `MEILI_URL`, `MERCURE_URL` | Rozdzielenie per tenant bez dotykania kodu |
| Nazwa indeksu Meilisearch to stała | `IndexSettingsTemplate::INDEX_NAME = 'objects'` | Osobny indeks per tenant wymagałby zmiany kodu |
| Compose nie przypina `container_name`; porty publikuje wyłącznie `caddy` | `docker-compose.yml` | Stacki per tenant z własną nazwą projektu działają bez przeróbek, wpięte w sieć edge Caddy'ego |

## Decyzja

**Każdy tenant dostaje własną instancję aplikacji z własnym klastrem Postgresa. Wyszukiwarka, storage obiektowy, edge proxy i bundel SPA pozostają współdzielone.**

| Warstwa | Per tenant | Wspólne | Uzasadnienie |
|---|---|---|---|
| `api`, `worker` | ✅ | | Izolacja procesów, własny `APP_BASE_URL`, własne klucze JWT (token jednego tenanta jest bezużyteczny w instancji drugiego) |
| `database` — Postgres + własna stanza pgBackRest | ✅ | | **Niezależny PITR**, niezależny tuning i upgrade, brak noisy-neighbour na I/O, przenośność na inny host przez skopiowanie wolumenu |
| `redis`, `mercure` | ✅ | | Łącznie ≈100 MB; usuwa pytanie o współdzielone sekrety i klucz hubu SSE |
| `minio` | bucket + użytkownik z polityką | instancja | Nazwy bucketów są w środowisku, więc rozdzielenie nic nie kosztuje; osobna instancja nie dodaje izolacji ponad politykę dostępu |
| `meilisearch` | indeks `objects` + filtr `tenantId` | instancja | Osobny indeks wymagałby zmiany kodu (stała `INDEX_NAME`); obecny filtr to stan produkcyjny, nie regresja |
| `caddy` (edge), bundel SPA, `docs`, `gotenberg`, monitoring | | ✅ | Bundel jest origin-relative, Gotenberg bezstanowy, edge musi widzieć wszystkie hosty |

**Adresowanie:** `<tenant>.app.harmonpim.pl`. Certyfikaty przez HTTP-01 per host (wildcard *certyfikat* wymagałby DNS-01, czyli budowania Caddy'ego z pluginem dla operatora DNS).

**Warstwy izolacji nie zastępują się, tylko nakładają.** `TenantFilter` i RLS **zostają bez zmian** i pozostają granicą wewnątrz instancji. Instancja jest trzecią warstwą, a nie zamiennikiem dwóch pierwszych — kod ma nadal działać poprawnie w instancji z wieloma tenantami, bo taki scenariusz obowiązuje w dev, w testach i w CI.

## Rozważane opcje

### 1. Wspólny Postgres, osobna baza (DATABASE) per tenant — odrzucone

Tańsze (~400–600 MB RAM na tenanta zamiast ~650 MB – 1,1 GB), jeden silnik do strojenia i aktualizacji. **Zawiera** błędną migrację i `DELETE` bez `WHERE` dokładnie tak samo jak osobny klaster — pod względem promienia rażenia zwykłej pomyłki jest równoważne.

Odrzucone, bo **nie realizuje wymagania nr 1**: stanza pgBackRest obejmuje klaster, więc PITR pojedynczego tenanta pociągałby za sobą wszystkich. Selektywne odtworzenie byłoby możliwe wyłącznie ze zrzutu logicznego, czyli z dokładnością do ostatniego zrzutu, nigdy do sekundy. Utrudnia też wymaganie nr 2 — wydzielenie tenanta zostaje operacją logiczną zamiast skopiowania wolumenu.

### 2. Pełny osobny stack ze wszystkim (własne Meili, MinIO, Gotenberg) — odrzucone

Maksymalna izolacja, zero współdzielenia. Odrzucone ze względu na koszt bez odpowiadającej korzyści: pełny stack to ≈2,2 GB RAM, czyli 2–3 tenanty na obecnym serwerze (CX33, 5,5 GB wolnego) wobec 3–5 przy wybranym wariancie i 15–20 na maszynie 32 GB. Osobny MinIO nie daje izolacji ponad politykę per bucket, a osobny Meilisearch wymagałby zmiany kodu.

### 3. Jedna instancja rozpoznająca tenanta po subdomenie (host → tenant) — odrzucone na tym etapie

Docelowy kształt SaaS, ale wymaga zmian w warstwie uwierzytelniania (rozwiązywanie tenanta z hosta, odrzucanie logowania użytkownika spoza tenanta danego hosta, `APP_BASE_URL` liczone per żądanie), czyli tam, gdzie błąd oznacza wyciek między tenantami. Przede wszystkim jednak **nie realizuje żadnego z dwóch wymagań** — wszyscy dalej siedzą w jednej bazie.

Wybrany wariant tej drogi nie zamyka: adresowanie subdomenami jest identyczne, więc ewentualna późniejsza konsolidacja jest dla użytkownika niewidoczna (routing zostaje na edge Caddym).

## Konsekwencje

**Pozytywne**

- Odtworzenie danych pojedynczego tenanta do dowolnego punktu w czasie, bez wpływu na pozostałych (wymaganie nr 1).
- Wydzielenie tenanta na dedykowany host sprowadza się do przeniesienia wolumenów (wymaganie nr 2, procedura w #2912).
- Awaria bazy, wyczerpanie połączeń albo kosztowna operacja utrzymaniowa jednego tenanta nie dotyka pozostałych.
- Token jednego tenanta jest bezużyteczny w instancji innego (osobne klucze JWT) — izolacja przestaje zależeć wyłącznie od poprawności kodu.
- Zero zmian w kodzie aplikacji dla samej izolacji.

**Negatywne**

- **N-krotność powierzchni operacyjnej**: major upgrade Postgresa razy liczba tenantów, N stanz pgBackRest, N procedur odtworzenia, N zestawów metryk. To musi zostać zeskryptowane **od pierwszego tenanta**, bo ręczna obsługa nie skaluje się i psuje się po cichu.
- Wdrożenie kodu przestaje być jedną operacją — mnoży się przez liczbę instancji (orkiestrator w #2863, z zatrzymaniem po pierwszym błędzie).
- ~650 MB – 1,1 GB RAM na tenanta ogranicza obecny serwer do 3–5 instancji.
- Wyszukiwarka i storage pozostają wspólnymi punktami awarii; ich niedostępność degraduje wszystkich tenantów.
- Zakładanie tenanta z panelu wymaga komponentu uprawnionego do uruchamiania kontenerów — osobna decyzja bezpieczeństwa, podejmowana w #2902.

**Czego ta decyzja NIE załatwia — zapisane wprost**

Separacja instancji **nie zastępuje kopii zapasowych ani przećwiczonej procedury odtworzenia**. Przed skutkami błędnej migracji chroni zrzut wykonany przed wdrożeniem (#2865) i to, że odtworzenie zostało raz przećwiczone na sucho (#2869) — nie sam fakt, że kontenery są osobne. Nie chroni też przed awarią całego hosta: wszystkie instancje stoją na jednej maszynie do czasu ewentualnego wydzielenia.

**Follow-upy**

- #2902 — ADR instancji platformowej i modelu orkiestracji (gdzie żyje panel operatora, kto ma prawo uruchamiać kontenery).
- #2864 — stanza pgBackRest per tenant; warunkiem zamknięcia jest dowód PITR jednego tenanta przy drugim pozostającym online.
- #2912 — procedura przeniesienia tenanta na dedykowany host, ze zmierzonym oknem niedostępności.
- #2868 — wykonywalny dowód izolacji między instancjami, uruchamiany po każdym dołożeniu tenanta.

## Linki

- Backlog epiku: `Project Plan/feature-tenant-instances-tickets.md`
- `docs/multi-tenancy.md` — warstwy `TenantFilter` i RLS, kontrakt GUC `app.current_tenant`
- ADR-003 — multi-tenant ready, single-tenant deployed
