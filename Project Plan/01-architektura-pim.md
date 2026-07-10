# Architektura systemu PIM — dokumentacja techniczna

**Wersja:** 1.0 (faza koncepcyjna)
**Data:** 2026-04-26
**Autor architektury:** Marcin Lipiec (we współpracy z Claude jako senior architektem IT)
**Status:** zatwierdzona do realizacji

---

## 1. Streszczenie wykonawcze

Dokument opisuje architekturę nowego systemu PIM (Product Information Management), który ma być konkurencyjny względem PIMcore i Akeneo na rynku wdrożeń klasy enterprise w Polsce i regionie. System startuje jako MVP obsługujący 50 000 produktów, z architekturą gotową na skalowanie do 200 000+ SKU bez zmian fundamentów. Pierwsze wdrożenia pilotażowe planowane są w modelu single-tenant z usługą wdrożeniową, z opcją późniejszego przekształcenia w SaaS multi-tenant.

Kluczowe wyróżniki produktu: pełne API-first od dnia pierwszego (REST + GraphQL + JSON-LD), agentic-first admin panel (chat jako pełnoprawna metoda interakcji, nie dolepiony widget), elastyczny model atrybutów modyfikowalny przez naturalną konwersację z agentem AI, integracje out-of-the-box z **BaseLinker i Shopify w MVP** oraz **Magento i IdoSell w fazie 1**.

Stack technologiczny opiera się wyłącznie na otwartym oprogramowaniu z licencjami przyjaznymi komercjalizacji (MIT, BSD, Apache 2.0, PostgreSQL License). Wszystkie komponenty z licencjami copyleft (AGPL) działają w trybie demonów-towarzyszy, nie linkowanych do kodu aplikacji.

## 2. Cele biznesowe i wymagania

### 2.1 Cele biznesowe

Produkt ma być sprzedawalny, rozwijalny i wydajny w perspektywie 10-letniej. Oznacza to:

- Stack rozpoznawalny przez działy IT klientów enterprise — żaden komponent eksperymentalny lub niszowy.
- Zgodność z dobrymi praktykami branży PIM (Akeneo, PIMcore, Ergonode jako referencje architektoniczne).
- Performance i bezpieczeństwo na poziomie umożliwiającym zwycięstwo w bezpośrednim porównaniu z liderami rynku.
- Architektura otwarta na rozszerzenia w postaci wtyczek, customowych workflow i białych etykiet (white-label).
- Migracje technologiczne zaplanowane z wyprzedzeniem (LTS releases, deprecation paths).

### 2.2 Wymagania funkcjonalne MVP

System w wersji MVP obejmuje pełnoprawne zarządzanie produktami z elastycznym modelem atrybutów, panel administracyjny gotowy do pracy z agentem AI, dwa kierunki integracji (**BaseLinker i Shopify** — Magento przesunięty do fazy 1, sekcja 7) z możliwością łatwego dodania kolejnych, oraz publiczne API do syndykacji danych produktowych do zewnętrznych konsumentów (sklepy, porównywarki, frontend).

### 2.3 Wymagania niefunkcjonalne

- Skala startowa: 50 000 SKU, 200+ atrybutów, 5 kanałów sprzedaży, 3 lokalizacje językowe, 100k+ zasobów medialnych.
- Skala docelowa (bez przepisywania): 200 000+ SKU, 500+ atrybutów, 20 kanałów, 10+ lokalizacji.
- Dostępność: 99.5% w MVP, 99.9% w wersji produkcyjnej.
- Performance: p95 < 200ms dla list produktów, p95 < 500ms dla full-text search po 200k rekordach.
- Bezpieczeństwo: pełne RBAC granularne, audit log wszystkich zmian, szyfrowanie at-rest i in-transit, gotowość do audytu typu ISO/SOC2 w fazie 3.

## 3. Stack technologiczny

### 3.1 Backend

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Język | PHP 8.4+ | Branżowy standard w PIM, dojrzały ekosystem, nowoczesne typy i performance |
| Framework | Symfony 7.x LTS | Najmocniejszy framework PHP do złożonego domain modeling, używany przez Akeneo/PIMcore/Ergonode, formalne LTS-y co 2 lata |
| API | API Platform 4 | Auto-generowanie REST + GraphQL + JSON-LD + Hydra + OpenAPI 3 z encji; najmocniejszy framework API-first w ekosystemie PHP |
| Runtime | FrankenPHP 2.x (worker mode) | Najnowocześniejszy runtime PHP w 2026, oparty o Caddy (HTTP/3, TLS automatyczne), worker mode dla maksymalnej wydajności. **Wymaga rygoru memory management — patrz sekcja 3.10** |
| ORM | Doctrine ORM 3.x | Wbudowany w Symfony, najmocniejszy ORM PHP, identity map, second-level cache, custom DBAL types |
| Walidacja | Symfony Validator | Constraint-based, integracja z API Platform |
| Serializacja | Symfony Serializer | Grupy serializacji per-context, integracja z API Platform |

### 3.2 Baza danych i przechowywanie

| Element | Wybór | Uzasadnienie |
|---|---|---|
| RDBMS | PostgreSQL 16 | JSONB z indeksami GIN dla atrybutów elastycznych, `ltree` dla hierarchii kategorii, partycjonowanie tabel, Row-Level Security dla multi-tenancy, replikacja logiczna |
| Search engine | Meilisearch | <50ms na 200k SKU, typo tolerance, faceted search, prostsze do operacji niż Elasticsearch, MIT |
| Cache i queue broker | Redis 7 | Cache aplikacyjny, broker dla Symfony Messenger, sesje, rate limiting |
| Object storage | MinIO (self-hosted) lub AWS S3 | DAM, S3-compatible API, swap chmura/on-prem bez zmian w kodzie |
| File abstraction | Flysystem | Adapter na różne backendy storage |

### 3.3 Komunikacja asynchroniczna i real-time

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Async messaging | Symfony Messenger | Native, transport Redis/Doctrine/AMQP wymienne, retry z backoff, scheduled messages |
| Real-time push | Mercure | SSE-based, natywny komponent Symfony, prostszy operacyjnie niż WebSocket; powiadomienia, streaming odpowiedzi agenta, live updates list |
| Cron / scheduler | Symfony Scheduler | Wbudowany w Symfony 6.4+, deklaratywne cron jobs |

### 3.4 Frontend administracyjny

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Język | TypeScript 5.x | Type safety, lepszy DX dla LLM-driven development |
| Framework | React 19 | Najszerszy ekosystem, dojrzałe wzorce, najlepsze wsparcie Claude Code |
| Build tool | Vite 6 | Szybsze od Next.js, nie potrzebujemy SSR w admin |
| Admin framework | Refine.dev | Headless framework — daje hooki na dane, auth, RBAC, routing; oszczędza ~40% pracy nad CRUD-ami; MIT |
| Komponenty UI | shadcn/ui (Radix + Tailwind) | Kod komponentów lokalny w repo (nie zaszyty w `node_modules`), pełna kontrola, najnowszy standard estetyki, MIT |
| Routing | React Router 7 | Standard React |
| Forms | React Hook Form + Zod | Wydajne formy z walidacją client-side |
| Real-time | EventSource API + Mercure SDK | Streaming agenta, live updates |

### 3.5 Agent layer

| Element | Wybór | Uzasadnienie |
|---|---|---|
| LLM provider | Anthropic Claude (claude-opus-4-6 dla schema-ops, claude-sonnet-4-6 dla data-ops) | Najlepsze wyniki dla zadań agentowych, dojrzałe tool-use API |
| SDK | anthropic-sdk-php | Oficjalne SDK PHP, integracja z Symfony service container |
| Architektura | Symfony service wbudowany w main backend (faza 0) → mikroserwis (faza 2) | Jeden deployment w MVP, separacja gdy zwiększą się wymagania na latency i skalowanie |
| Wzorzec | Tool-use pattern z typowanymi narzędziami | Każde narzędzie agenta ma JSON Schema, walidację argumentów, audit log |
| Approval flow | Pending changes queue + UI inbox | Operacje destrukcyjne wymagają człowieka w MVP |

### 3.6 Bezpieczeństwo

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Authentication API | LexikJWTAuthenticationBundle | JWT z RS256, refresh tokens, standard branżowy |
| OAuth2 server | thephpleague/oauth2-server (przez bundle) | Dla integracji enterprise, custom grants |
| Authentication UI | Symfony Security + form login | Sesyjny dla admin UI |
| RBAC | Symfony Voters + custom Permission entity | Granularność per-resource, per-field, per-action |
| 2FA | scheb/2fa-bundle | TOTP + backup codes |
| Audit log | DoctrineAuditBundle | Automatyczne logowanie wszystkich CRUD na encjach domenowych |
| Rate limiting | Symfony RateLimiter | Per-user, per-IP, per-endpoint |
| Secrets management | Symfony Vault + ENV vars | Dla MVP, opcjonalnie HashiCorp Vault w fazie 3 |
| TLS | FrankenPHP / Caddy (Let's Encrypt) | Automatyczne odnawianie |

### 3.7 Observability

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Tracing | OpenTelemetry SDK PHP | Rozproszony tracing przez wszystkie komponenty |
| Metrics | Prometheus exporter | Standard metryczny |
| Dashboards | Grafana | Wizualizacja metryk i tracingów |
| Logs | Monolog → JSON → Loki / OpenSearch | Strukturalne logi |
| Error tracking | Sentry (samohostowane lub SaaS) | Stacktrace'y, alertowanie |
| APM (faza 1) | Tideways lub Blackfire | PHP profiling produkcyjny |

### 3.8 Infrastructure i deployment

| Element | Wybór | Uzasadnienie |
|---|---|---|
| Containerization | Docker + Docker Compose (MVP) → Kubernetes (faza 2) | Standard, łatwy deployment u klienta |
| Reverse proxy | Caddy (wbudowany w FrankenPHP) | TLS auto, HTTP/3, prosta konfiguracja. **Dev env: jednoporotwy reverse proxy — `/api/*` do FrankenPHP/Symfony, reszta do Vite dev server** (sekcja 3.10a). Eliminuje cały setup CORS w MVP. |
| CI/CD | GitHub Actions + GitHub Container Registry | Standard, integracja z repo |
| IaC | Terraform (cloud) lub Ansible (on-prem) | Reprodukowalna infrastruktura |
| Backup | **pgBackRest + WAL archiving od dnia 1** (decyzja po review DeepSeek) | RPO < 5 min, RTO < 30 min nawet w MVP — różnica wartości dla pierwszego pilota jest ogromna |

### 3.10 Dyscyplina runtime: FrankenPHP worker mode + Doctrine

**Krytyczne (zgłoszone jako luka w trzeciej rundzie review — Gemini):**
W FrankenPHP worker mode aplikacja Symfony żyje w pamięci między requestami. Doctrine ORM domyślnie trzyma w Identity Map każdy załadowany obiekt. Bez świadomego czyszczenia pamięci, każdy long-running worker (sync 50k SKU z BaseLinkera, masowy import, Messenger consumer) zje cały RAM i zabije proces w najgorszym możliwym momencie.

**Twarde wytyczne architektoniczne (egzekwowane przez review automatyczne, nie ludzki code review):**

1. **Każdy Symfony Messenger handler musi wywołać `$entityManager->clear()` po `flush()` w pętli batch.** Wzorzec referencyjny w pliku `src/Messaging/AbstractBatchHandler.php` — każdy nowy handler dziedziczy z tej klasy lub wymaga ekspresowego review.
2. **Bulk import/export** używa Doctrine `iterate()` zamiast `findAll()` + `clear()` co N rekordów (N=200 default).
3. **SQL logger wyłączony w produkcji** (`doctrine.dbal.logging: false`) — w worker mode logger akumuluje historię zapytań w pamięci.
4. **Walidacja monitoringowa:** Prometheus metric `frankenphp_worker_memory_bytes` z alertem powyżej 256 MB per worker — wykrywa wycieki w runtime, nie czeka na OOM.
5. **CI gate:** PHPStan custom rule blokująca handlery Messenger, które flushują w pętli bez `clear()` — automatyczna detekcja wzorca.
6. **`opcache.preload` + JIT** włączone — odbierz boot-time amortyzację, ale `opcache.preload_user=www-data` (nie root).

Te punkty są nienegocjowalne — wpisane jako system prompt do Claude Code dla każdego ticketu dotyczącego workerów lub Messenger handlerów (patrz `02-plan-projektu-pim.md`, sekcja "Claude Code system prompt — twarde wytyczne").

**Plan upgrade:** Symfony 7.4 LTS ma wsparcie bugfix do listopada 2028 i security do listopada 2029. Migracja na kolejny LTS (prawdopodobnie Symfony 8.x lub 9.x w 2028) jest planowanym zadaniem fazy 3. FrankenPHP 2.x wprowadziło breaking changes w worker API względem 1.x — od początku używamy 2.x API, pisząc Caddyfile + `frankenphp.go` zgodnie z aktualną dokumentacją (test kompatybilności w Sprint 0).

### 3.10a Sieć dev environment: single-origin przez Caddy (nie dual-port + CORS)

**Krytyczne dla pętli pracy non-coder + Claude Code (zgłoszone w finalnej polerce review):**
W monorepo Turborepo + docker-compose typowy domyślny setup wystawia frontend (Vite dev server) pod `localhost:5173` i backend (FrankenPHP/Symfony) pod `localhost:8000` lub `api.localhost`. To natychmiast generuje błędy CORS przy każdym fetchu z admina do API. Claude Code potrafi się w to zapętlić na godziny: dodaje nagłówki, zmienia konfigurację `nelmio_cors`, znowu nie działa, zmienia origin w Vite, itd.

**Decyzja architektoniczna:** w dev (i prod) cały ruch idzie przez **jeden origin obsługiwany przez Caddy** wbudowany w FrankenPHP:
- `https://pim.localhost/api/*` → FrankenPHP (Symfony / API Platform).
- `https://pim.localhost/.well-known/mercure` → Mercure hub.
- `https://pim.localhost/*` (cała reszta) → reverse proxy do kontenera Vite dev server (HMR działa przez WebSocket upgrade).

**Skutki:**
- Brak `Access-Control-Allow-Origin` do konfiguracji w MVP — frontend i backend są tym samym originem.
- HMR Vite działa przez WebSocket upgrade w Caddy (jedna linia w Caddyfile).
- Cookies httpOnly z JWT nie wymagają cross-origin gymnastyki.
- Konfiguracja produkcyjna jest **identyczna pod względem topologii** — Caddy ma `pim.example.com` zamiast `pim.localhost`, ale routing `/api/*` vs `/*` jest ten sam. Brak dryfu dev → prod.

**Wymagane w Sprint 0 (sekcja 0.0.1):** docker-compose definiuje jeden serwis `frankenphp` z Caddyfile zawierającym oba `handle_path /api/*` i `reverse_proxy vite:5173`. Vite uruchomiony w trybie `--host 0.0.0.0`. Nie próbujemy CORS — nie ma czego konfigurować.

### 3.9 Macierz licencji

Wszystkie komponenty wybrane do stacku mają licencje przyjazne komercjalizacji, white-label i odsprzedaży. Zero komponentów na BSL, zero copyleft w warstwie aplikacyjnej.

| Komponent | Licencja | Implikacje |
|---|---|---|
| PHP, Symfony, API Platform, Doctrine, FrankenPHP | MIT | Pełna swoboda |
| PostgreSQL | PostgreSQL License (MIT-like) | Pełna swoboda |
| Meilisearch | MIT | Pełna swoboda |
| Redis | RSAL (Redis 7+), wracamy do BSD od Redis 8 | Akceptowalna; alternatywa: Valkey (BSD) jeśli RSAL stanie się problematyczna |
| MinIO server | AGPL v3 | Działa jako osobny demon, nie linkowany — bezpieczne dla white-label; alternatywa: AWS S3 jeśli preferowane |
| Mercure hub | AGPL v3 | Podobnie — osobny demon |
| Refine.dev, shadcn/ui, React, Vite, Tailwind | MIT | Pełna swoboda |
| Sentry self-hosted | FSL (BSL-derivative dla SaaS) | Self-hosted bezpieczne; alternatywa: GlitchTip (MIT) |

## 4. Architektura logiczna

### 4.1 Diagram wysokopoziomowy

```
┌─────────────────────────────────────────────────────────────────┐
│  Klienci API: sklepy, frontend, syndykacja, integracje partnera │
└─────────────────────────┬───────────────────────────────────────┘
                          │ HTTPS (REST + GraphQL + SSE)
                          ▼
                ┌──────────────────┐
                │ Caddy / FrankenPHP│  ← TLS, HTTP/3, rate limit edge
                └────────┬─────────┘
                         │
        ┌────────────────┼─────────────────┐
        ▼                ▼                 ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Backend API │  │ Admin frontend│  │  Mercure hub │
│ Symfony 7 +  │  │ Refine + React│  │   (SSE)      │
│ API Platform │  │   (statyczne) │  │              │
└──────┬───────┘  └──────┬───────┘  └──────▲───────┘
       │                 │                  │
       │                 └──────────────────┤ subscriptions
       │                                    │
       ▼                                    │
┌─────────────────────────────────────────┐ │
│        Doctrine ORM + Repositories      │ │
└──────┬──────────┬───────────────┬───────┘ │
       │          │               │         │
       ▼          ▼               ▼         │
┌──────────┐ ┌──────────┐  ┌─────────────┐  │
│PostgreSQL│ │Meilisearch│ │   Redis     │  │
│   16     │ │           │ │ cache+queue │  │
└──────────┘ └──────────┘  └──────┬──────┘  │
                                  │         │
                                  ▼         │
                        ┌──────────────────┐│
                        │ Symfony Messenger ││
                        │   (workers)       ├┘ publish
                        └────────┬──────────┘
                                 │
            ┌────────────────────┼─────────────────────┐
            ▼                    ▼                     ▼
    ┌──────────────┐    ┌──────────────┐      ┌──────────────────┐
    │ BaseLinker   │    │   Shopify    │      │ Magento/IdoSell  │
    │ Integration  │    │  Integration │      │   (faza 1)       │
    │   Worker     │    │   Worker     │      │                  │
    │   (MVP)      │    │   (MVP)      │      │                  │
    └──────┬───────┘    └──────┬───────┘      └──────┬───────────┘
           │                    │                    │
           ▼                    ▼                    ▼
      BaseLinker API     Shopify GraphQL Admin  Magento REST/GQL,
                         (Exponential Backoff   IdoSell API
                          w MVP, Leaky Bucket
                          + Bulk Ops w fazie 1)

           ┌─────────────────────────────┐
           │      Agent Service          │
           │  (Symfony service in core)  │
           │   Anthropic SDK PHP         │
           └─────────┬───────────────────┘
                     │ tool-use
                     ▼
           ┌─────────────────────────────┐
           │  Domain APIs (internal)     │
           │  - SchemaService            │
           │  - ProductService           │
           │  - AttributeService         │
           │  - ApprovalService          │
           └─────────────────────────────┘

           ┌─────────────────────────────┐
           │  MinIO / S3 (DAM)           │
           └─────────────────────────────┘
```

### 4.2 Główne moduły domenowe

System dzieli się na cztery główne konteksty domenowe (Bounded Contexts wg DDD):

**Catalog Context** — typy obiektów domenowych (`ObjectType`), atrybuty, grupy atrybutów, instancje obiektów (produkty, kategorie, asocjacje), warianty. Rdzeń PIM po ADR-009. Encje: `ObjectType`, `ObjectTypeAttribute`, `Attribute`, `AttributeGroup`, `AttributeOption`, `Object` (poly: kind=`product`/`category`/`asset`/`custom`), `ObjectValue`, `ObjectVariant`, `Association`. Sub-context **Predefined Types** enkapsuluje predefiniowane fixture'y `Product`/`Category`/`Asset` (`is_built_in=true`) z dedykowanymi UX flow w admin UI i sugar paths w API. Pojęcie „Family" z poprzedniej iteracji jest deprecated — `ObjectType` przejmuje jego rolę i rozszerza ją na wszystkie byty domenowe.

**Channel Context** — kanały sprzedaży, lokale, mappingi atrybutów per-kanał i per-`ObjectType`, completeness rules, publikacje. Encje: Channel, Locale, Currency, `ChannelObjectTypeMapping` (poly per `kind`), CompletenessRule, `ChannelPublicationProfile` (per-channel attribute/locale allow-list, ADR-0018).

**Asset Context** — zarządzanie mediami (DAM), wersjonowanie, transformacje, metadane. Encje: Asset (predefined `ObjectType kind='asset'` + odrębna tabela storage), AssetVariant, AssetMetadata. User-defined metadata Asseta idzie przez `ObjectValue` (jednolity model atrybutów), storage szczegóły zostają w dedykowanych kolumnach `assets`.

**Integration Context** — konfiguracja integracji, mapowania (per `ObjectType`), historia synchronizacji, rejestr błędów. Encje: IntegrationProfile, AttributeMapping, SyncJob, SyncJobLog.

Pomocnicze konteksty: Identity (użytkownicy, role, permissions, audit), Agent (sesje agenta, tool calls, pending approvals — z toolem `create_object_type` zarezerwowanym pod Fazę 2), API Configurator (konfiguracja endpointów publikujących, kluczy, webhooków, profile filtrowane per `object_type_id`).

## 5. Model danych — kluczowe encje

### 5.1 Filozofia modelowania

Model danych łączy dwa podejścia, zaczerpnięte odpowiednio od Akeneo (struktura atrybutów) i PIMcore (elastyczność JSONB), z generalizacją typu obiektu po ADR-009:

- **`ObjectType` jako encja pierwszej klasy** (po ADR-009). Każdy byt domenowy (`Product`, `Category`, `Asset`, w Fazie 2/3 — `Customer`, `Supplier`, `PriceList`) jest instancją jednego mechanizmu. Predefiniowane typy (`product`, `category`, `asset`) seedowane z `is_built_in=true` i blokowane przed deletion. Custom kindy odblokowane w Fazie 2/3.
- **Atrybut jako encja pierwszej klasy.** Każdy atrybut ma typ, opcje, zasięg (`scopable` per-channel, `localizable` per-locale), reguły walidacji, metadane UI. Atrybuty wiążą się z `ObjectType` przez junction `object_type_attributes` (jeden atrybut może być przypisany do wielu typów — np. `name` dla każdego kindu, `seo_title` dla `product` i `category`).
- **Wartości atrybutów w osobnej tabeli** (`object_values`, było `product_values` przed ADR-009) z polem `value JSONB`, indeksowanym GIN-em. To pozwala na dowolny typ wartości (string, number, date, array, relation, asset reference) bez migracji DDL przy dodawaniu nowych atrybutów ani nowych typów obiektów.
- **Generowane kolumny** dla najczęściej używanych atrybutów (np. `name`, `sku`, `gtin`) — Postgres `GENERATED ALWAYS AS` z JSONB, dla wydajnych zapytań i indeksów BTree. Kolumny parametryzowane per `kind` przez `CASE WHEN kind='...' THEN ... END` lub partial functional indexes (np. `path` ltree tylko dla `kind='category'`).
- **Hierarchia kategorii w `ltree`** — typ Postgres dla efektywnych zapytań "wszystkie produkty w kategorii X i jej podkategoriach". Aktywna tylko dla obiektów `kind='category'`, walidowana przez listener.
- **Multi-tenancy przez `tenant_id`** w każdej tabeli domenowej + Postgres Row-Level Security jako drugi pas bezpieczeństwa.

### 5.2 Główne tabele (uproszczone)

```sql
-- Multi-tenancy fundament
CREATE TABLE tenants (
    id UUID PRIMARY KEY,
    code VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    config JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Atrybuty (po ADR-009: nadal pierwsza klasa, niezależne od ObjectType — wiązane przez junction)
CREATE TABLE attributes (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code VARCHAR(128) NOT NULL,
    type VARCHAR(32) NOT NULL,  -- text, number, select, multiselect, date, boolean, asset, relation, price, ...
    scopable BOOLEAN NOT NULL DEFAULT false,  -- per-channel
    localizable BOOLEAN NOT NULL DEFAULT false,  -- per-locale
    required BOOLEAN NOT NULL DEFAULT false,
    unique_value BOOLEAN NOT NULL DEFAULT false,
    validation_rules JSONB NOT NULL DEFAULT '{}',  -- min, max, regex, ...
    ui_config JSONB NOT NULL DEFAULT '{}',  -- label translations, help text, icon, ...
    attribute_group_id UUID REFERENCES attribute_groups(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, code)
);

-- Typy obiektów domenowych (po ADR-009: zastępują families, generic dla każdego bytu)
CREATE TABLE object_types (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code VARCHAR(128) NOT NULL,                    -- 'product', 'category', 'asset', 'electronics', 'shoes', 'customer' (Faza 2)
    kind VARCHAR(32) NOT NULL,                     -- 'product' | 'category' | 'asset' | 'custom'
    is_built_in BOOLEAN NOT NULL DEFAULT false,    -- TRUE = predefined seed, deletion blocked at service + RLS layer
    label JSONB NOT NULL,                          -- {"en": "Product", "pl": "Produkt"}
    label_attribute_id UUID REFERENCES attributes(id),  -- które pole jest "display name" (np. "name")
    image_attribute_id UUID REFERENCES attributes(id),  -- które pole jest "main image"
    completeness_rules JSONB NOT NULL DEFAULT '{}',     -- reguły completeness per ObjectType
    schema_version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_object_types_kind ON object_types(tenant_id, kind);

-- Junction: przypisanie atrybutów do typu obiektu (zastępuje family_attributes)
CREATE TABLE object_type_attributes (
    object_type_id UUID NOT NULL REFERENCES object_types(id) ON DELETE CASCADE,
    attribute_id UUID NOT NULL REFERENCES attributes(id),
    required_for_completeness BOOLEAN NOT NULL DEFAULT false,
    sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (object_type_id, attribute_id)
);

-- Obiekty domenowe (po ADR-009: jedna tabela dla product/category/custom, polimorfizm przez kind)
-- Predefiniowane object_types mają dedykowane sugar paths /api/products, /api/categories,
-- pod spodem wszystkie operacje na tej tabeli.
CREATE TABLE objects (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    object_type_id UUID NOT NULL REFERENCES object_types(id),
    kind VARCHAR(32) NOT NULL,                     -- denormalizowany z object_types.kind do filterów/query
    code VARCHAR(128) NOT NULL,                    -- sku dla product, category code dla category, asset code dla asset
    parent_id UUID REFERENCES objects(id),         -- variants (dla product), drzewo kategorii (dla category)
    enabled BOOLEAN NOT NULL DEFAULT true,
    -- Generated columns parametryzowane per kind:
    name_pl TEXT GENERATED ALWAYS AS (attributes_indexed->'name'->>'pl') STORED,
    -- ltree path tylko dla kind='category' (NULL dla pozostałych) — walidowane przez Doctrine listener:
    path LTREE,
    completeness_pct INTEGER NOT NULL DEFAULT 0,
    attributes_indexed JSONB NOT NULL DEFAULT '{}',  -- denormalizowany cache do search
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, kind, code),                  -- np. dwa product+sku=ABC w różnych tenantach OK; dwa kind=product+code=ABC w jednym tenancie nie
    CHECK (kind IN ('product', 'category', 'asset', 'custom'))
);
CREATE INDEX idx_objects_type ON objects(tenant_id, object_type_id);
CREATE INDEX idx_objects_kind ON objects(tenant_id, kind);
CREATE INDEX idx_objects_attributes_gin ON objects USING GIN (attributes_indexed);
-- Partial indexes dla ltree (tylko categories):
CREATE INDEX idx_objects_path_gist ON objects USING GIST (path) WHERE kind = 'category';
CREATE INDEX idx_objects_path_btree ON objects USING BTREE (path) WHERE kind = 'category';

-- Wartości atrybutów (po ADR-009: object_values zamiast product_values, wszystkie kindy używają tej samej tabeli)
CREATE TABLE object_values (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    object_id UUID NOT NULL REFERENCES objects(id) ON DELETE CASCADE,
    attribute_id UUID NOT NULL REFERENCES attributes(id),
    locale VARCHAR(8),  -- NULL jeśli atrybut nielocalizable
    channel VARCHAR(64),  -- NULL jeśli atrybut nieskopable
    value JSONB NOT NULL,
    provenance VARCHAR(32) NOT NULL DEFAULT 'manual',  -- manual, import, agent, integration
    provenance_meta JSONB,  -- np. agent run id, integration source
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, object_id, attribute_id, locale, channel)
);
CREATE INDEX idx_object_values_lookup ON object_values(object_id, attribute_id);

-- Kategoryzacja (object → object, gdzie target ma kind='category')
-- Po ADR-009: była product_categories; teraz generic (Customer może być w drzewie kategorii klientów w Fazie 2).
CREATE TABLE object_categories (
    object_id UUID NOT NULL REFERENCES objects(id) ON DELETE CASCADE,
    category_id UUID NOT NULL REFERENCES objects(id) ON DELETE CASCADE,
    PRIMARY KEY (object_id, category_id)
);

-- Asocjacje object-object (po ADR-009: generic, nie tylko product-product)
CREATE TABLE object_associations (
    object_id UUID NOT NULL REFERENCES objects(id) ON DELETE CASCADE,
    associated_object_id UUID NOT NULL REFERENCES objects(id) ON DELETE CASCADE,
    type VARCHAR(32) NOT NULL,  -- cross_sell, up_sell, related, alternative, accessory
    PRIMARY KEY (object_id, associated_object_id, type)
);

-- Kanały i lokale
CREATE TABLE channels (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code VARCHAR(64) NOT NULL,
    name JSONB NOT NULL,
    locales JSONB NOT NULL,  -- ["pl_PL", "en_US"]
    currencies JSONB NOT NULL,  -- ["PLN", "EUR"]
    -- Po ADR-009: kategorie żyją w `objects` z `kind='category'`. FK do `objects(id)`,
    -- target kind enforce'owany przez listener (`ChannelCategoryRootValidator`) — nie CHECK constraint,
    -- bo Postgres nie wspiera FK z dodatkowymi predykatami na kolumnie target.
    category_tree_root_object_id UUID REFERENCES objects(id),
    UNIQUE (tenant_id, code)
);

-- Aktywa (DAM) — po ADR-009: dedykowana tabela dla storage szczegółów,
-- ale Asset jest też reprezentowany jako Object kind='asset' dla user-defined metadata przez object_values.
-- object_id wskazuje na powiązany Object kind='asset' (1:1) — schema atrybutów w object_type_attributes,
-- storage details (path, mime, size) zostają tutaj (DAM ma własny lifecycle, transformacje, variants).
CREATE TABLE assets (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    object_id UUID UNIQUE REFERENCES objects(id) ON DELETE CASCADE,  -- NULL podczas migracji starych danych; NOT NULL po MVP-Alpha
    code VARCHAR(128) NOT NULL,
    type VARCHAR(32) NOT NULL,  -- image, video, document, 3d_model
    storage_path TEXT NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    size_bytes BIGINT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',  -- techniczne (EXIF, dimensions); user-defined idzie do object_values
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_assets_object ON assets(object_id);

-- Integracje
CREATE TABLE integration_profiles (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL,  -- baselinker, magento, idosell, custom
    name VARCHAR(255) NOT NULL,
    config JSONB NOT NULL,  -- credentials, endpoints, mapping rules
    enabled BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (tenant_id, code)
);

CREATE TABLE sync_jobs (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    integration_profile_id UUID NOT NULL REFERENCES integration_profiles(id),
    type VARCHAR(32) NOT NULL,  -- export, import, webhook
    status VARCHAR(32) NOT NULL,  -- pending, running, success, failed, partial
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    stats JSONB NOT NULL DEFAULT '{}',
    error_message TEXT
);

-- Agent runs
CREATE TABLE agent_runs (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    user_id UUID NOT NULL REFERENCES users(id),
    prompt TEXT NOT NULL,
    plan JSONB,  -- proposed actions
    status VARCHAR(32) NOT NULL,  -- planning, awaiting_approval, executing, success, failed, cancelled
    tool_calls JSONB NOT NULL DEFAULT '[]',
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);
```

### 5.3 Strategia indeksowania

- BTree indexy na klucze obce, kolumny generowane, najczęstsze filtry (tenant_id, object_type_id, kind, code, enabled).
- GIN indexy na JSONB (attributes_indexed, value w object_values, config) dla zapytań typu "produkty z atrybutem `marka` = `Nike`".
- GIST indexy na ltree (kategorie hierarchiczne) — partial: `WHERE kind = 'category'`.
- Funkcjonalne indexy na kolumnach generowanych (np. `LOWER(code)` dla case-insensitive lookup).
- Partycjonowanie tabeli `object_values` po `tenant_id` (hash partitioning) gdy wejdziemy w multi-tenant.

### 5.4 Strategia indeksowania w Meilisearch

Po ADR-009 — **jeden indeks per `kind`**, indexer parametryzuje się o `object_type_id`. W MVP: `products`, `categories`. Asset DAM dochodzi w Fazie 1+.

Indeks `products` (objects.kind='product'):
- searchable attributes: `name`, `description`, `sku`, `brand`, `category_names`
- filterable attributes: `object_type_code`, `enabled`, `category_ids`, `attributes.brand`, `attributes.color`, `price.amount`, `completeness_pct`
- sortable attributes: `created_at`, `updated_at`, `price.amount`, `name`

Indeks `categories` (objects.kind='category'):
- searchable attributes: `name`, `path` (jako string), `seo_title`, `seo_description`
- filterable attributes: `object_type_code`, `enabled`, `parent_id`, `attributes.is_visible`
- sortable attributes: `created_at`, `updated_at`, `name`, `path`

Synchronizacja: Doctrine event listener (postPersist, postUpdate, postRemove) parametryzowany o `kind` → Symfony Messenger message (`ObjectIndexed(objectId, kind)`) → worker pisze do odpowiedniego indeksu Meilisearch. Custom kindy w Fazie 2/3 dostają własne indeksy automatycznie (settings template per `ObjectType`).

## 6. API — kontrakty zewnętrzne

### 6.1 Filozofia API

API jest produktem pierwszej klasy, nie dodatkiem do admina. Wszystkie operacje admina używają tego samego API co integratorzy zewnętrzni — żadnych prywatnych endpointów. To gwarantuje spójność i pełnię funkcjonalną API publicznego.

### 6.2 Format

API Platform 4 udostępnia każdą encję domenową w trzech formatach jednocześnie:

- **REST + JSON-LD** (domyślny) — endpointy `/api/products`, `/api/attributes`, `/api/families` z hipermediami Hydra.
- **REST + JSON** — wersja "uproszczona" dla integratorów, którzy nie chcą JSON-LD.
- **GraphQL** — endpoint `/api/graphql` z auto-generowanym schematem.

Każdy endpoint:
- jest opisany w OpenAPI 3.1 (auto-generowane przez API Platform, dostępne na `/api/docs.json` — to jest źródło prawdy, nie ręcznie utrzymywany plik YAML)
- ma rate limiting (Symfony RateLimiter)
- waliduje wejście (Symfony Validator)
- respektuje grupy serializacji per-context (admin, integration, public)
- używa cursor-based pagination dla list (`pageBefore`, `pageAfter`)
- zwraca standardowe błędy w formacie RFC 7807 Problem Details

**Dokumentacja API:** spec OpenAPI generowana na żywo z metadanych API Platform; nie tworzymy ręcznie utrzymywanego `03-api-spec.openapi.yaml` (zmiana po review DeepSeek — taki plik szybko desynchronizowałby się z rzeczywistym kodem). Jeśli klient wymaga wersjonowanego pliku do governance, mamy CI step który eksportuje `/api/docs.json` przy każdym tagu release i commitje do `docs/api-spec/v{version}.json`.

### 6.3 Konfigurator API

Panel administracyjny ma sekcję "API Configurator", w której administrator może:

- Tworzyć **API Profiles** (np. "Storefront Magento", "Mobile App", "Partner X").
- Definiować dla profilu które atrybuty są publikowane, w jakim formacie, dla jakich kanałów/locale.
- Generować klucze API z scoupes (read-only, write-only, full).
- Konfigurować webhooks (event → URL z retry policy).
- Podglądać metryki użycia API per profile.

Pod spodem: każdy API Profile mapuje na customowy serializer context + voter. Konfigurator generuje rekordy w tabeli `api_profiles`, które są wczytywane przy autentykacji.

**Doprecyzowanie po review DeepSeek — implementacja API Profile:**
- **Endpointy:** wszystkie profile używają tych samych canonical endpointów (`/api/products`, `/api/categories` etc.) — profil filtruje **co** jest zwracane, nie **gdzie**. Klient autentykuje się kluczem profilu, profil `Authorization` decyduje o widocznych polach. To upraszcza routing i caching.
- **Filtrowanie:** profil to lista atrybutów + serializer groups (np. `['public:read', 'profile:storefront-shopify:read']`). API Platform respektuje dynamicznie ustawione grupy w `Subscriber`'rze, który czyta klucz API z requestu.
- **Caching:** Symfony HTTP Cache + Redis tag-based cache; klucz cache zawiera `profile_id` w wariancie, więc dwa profile mają osobne cache entries dla tego samego URL. Inwalidacja po zmianie produktu inwaliduje wszystkie warianty profile dla tego produktu (tag `product:{id}`).
- **Rate limiting per-profile:** osobny `RateLimiter` policy per `api_profile_id` (np. partner X — 1000 req/h, partner Y — 100 req/h). Klucz policy: `api:{profile_id}:rate`. Konfigurowalne w UI Configuratora.
- **Webhooks per-profile:** osobne URL'e i polityka retry per profil; webhook secret per profil dla HMAC verification.

### 6.4 Wersjonowanie API

API używa header-based versioning: `Accept: application/ld+json; version=1.0`. Domyślna wersja zawsze najnowsza, stara wersja wspierana przez minimum 12 miesięcy po wydaniu nowej.

## 7. Integracje

### 7.1 Architektura warstwy integracji

Każda integracja to osobny **Symfony bundle** w katalogu `src/Integration/{Name}/`, z:
- `Adapter` — mapowanie domain ↔ external format
- `Client` — HTTP client (Symfony HttpClient z retry, circuit breaker)
- `MessageHandler` — handler dla Symfony Messenger (pull/push)
- `Webhook` — endpoint przyjmujący webhook od zewnętrznego systemu
- `ConfigForm` — formularz konfiguracji w admin UI

Integracje są opcjonalnymi bundle'ami — można wyłączyć co nieużywane, można dodać nowe bez modyfikacji core.

### 7.2 BaseLinker

Kierunek MVP: **PIM → BaseLinker** (export). Pełen sync produktów, kategorii, magazynu (jeśli źródłem prawdy stanu jest PIM — w MVP nie jest, więc magazyn pomijamy).

Mechanizm:
- Bulk export: command `pim:integration:baselinker:full-sync` lub uruchamiane z UI.
- Incremental: Doctrine event listener → kolejka → worker pushuje zmiany.
- Mapowanie atrybutów: konfigurowane w admin UI (pole `marka` PIM → pole `Producent` BaseLinker).
- Retry: Symfony Messenger z exponential backoff, max 5 prób.
- Audit: wszystkie wywołania API logowane w `sync_jobs` i `sync_job_logs`.

API BaseLinker: REST (HTTP POST z JSON), klucz API w header. Wykorzystujemy istniejące biblioteki PHP jako referencję (mnastalski/baselinker-php), ale piszemy własny lekki client w Symfony HttpClient — uniknięcie zewnętrznych zależności.

### 7.3 Shopify

**Decyzja produktowa po drugiej rundzie review:** Shopify zamiast Magento jako druga integracja MVP. Powody: większy globalny rynek (~4.6M sklepów Shopify vs ~150k Magento), silniejszy ekosystem D2C i mid-market w PL/EU, niższy próg wejścia dla klientów PIM, atrakcyjny vector wzrostu (Shopify Plus dla enterprise). Magento przesuwa się do fazy 1.

Kierunek MVP: **PIM → Shopify** (export, jednokierunkowy).

Mechanizm:
- **Shopify Admin GraphQL API** jako preferowany w 2026 (Shopify oficjalnie deprecuje REST Admin API stopniowo na rzecz GraphQL).
- Auth: OAuth 2.0 dla Shopify Partners apps (gdy budujemy app w marketplace) **lub** Custom App access token (dla enterprise klientów z dedykowaną instancją PIM).
- Mapowanie atrybut PIM → **Shopify Metafield** z namespace per tenant (np. `pim_main.color`, `pim_main.weight_packaging`). Standardowe pola produktowe (title, descriptionHtml, vendor, productType, tags) mapujemy bezpośrednio.
- Mapowanie wariantów: PIM `family_variants` → Shopify `ProductVariant` z opcjami i wartościami. Mapowanie axes (rozmiar, kolor, etc.) konfigurowalne w admin UI.
- Mapowanie kategorii: PIM `ltree` → Shopify `Collection` (smart lub manual collections, do wyboru per profil integracji).
- **Strategia bulk sync — decyzja po review:** w **MVP używamy zwykłych mutacji GraphQL paczkami po 250 elementów** z **prostym Exponential Backoff** (sekcja niżej). Bulk Operations API + Leaky Bucket odkładamy do **fazy 1**, gdy benchmark wskaże taką potrzebę. **Powód:** Bulk Operations to async flow z generowaniem JSONL, polling status, download URL, parsowanie streamingowe — to dodatkowe 6-8h implementacji i 3-4× trudniejszy debug, niewspółmierne do skali MVP (50k SKU). Zwykłe GraphQL batch 250 daje pełen sync 50k SKU w ~45-90 min, co jest akceptowalne dla nightly job i pozwala startować szybciej.
- **Throttling MVP — Exponential Backoff (polerka po finalnym review Gemini, decyzja świadoma):** zamiast pełnego algorytmu Leaky Bucket z liczeniem `extensions.cost.throttleStatus.currentlyAvailable` × leak rate × cost-per-query × shared state w Redis między workerami, w MVP implementujemy najprostszy działający mechanizm: **wyślij request → na 429 lub `errors[].extensions.code === 'THROTTLED'` przeczytaj `Retry-After` z odpowiedzi (lub fallback `2^retry_count` sekund, max 60s), `sleep`, retry; max 5 prób, potem dead-letter queue**. **Powód świadomej redukcji złożoności:**
   - Algorytmy współdzielonego stanu rozproszonego (Redis-backed bucket sterowany przez kilku workerów Messengera) to klasa problemów, na których LLM stale się zacina (race conditions, off-by-one w obliczaniu czasu regeneracji, źle obsłużone partial response).
   - Exponential backoff jest deterministyczny, samoreparujący się, mieści się w 30 liniach kodu i nie wymaga współdzielenia stanu między workerami.
   - Kosztem jest sub-optymalne wykorzystanie rate limit'u Shopify — przy backoff niektóre sloty są marnowane między retry. Dla 50k SKU różnica to dodatkowe ~15-30 min do nightly sync, akceptowalne.
- **Logowanie obciążenia bucket'u (na potrzeby decyzji w fazie 1):** każdy response zapisuje `extensions.cost.throttleStatus.currentlyAvailable` i `actualQueryCost` w `sync_job_logs`. To pasywny zbiór danych, nie aktywne sterowanie. Pozwala w fazie 1 podjąć decyzję o migracji na Leaky Bucket na podstawie realnej telemetrii: jeśli >20% requestów odbywa się przy `currentlyAvailable < 100` (dla standard bucket 1000) → backoff dławi przepustowość, opłaca się migracja.
- Webhooks (opcjonalne w MVP, pełne w fazie 1): `products/update`, `inventory_levels/update`, `app/uninstalled` — przyjmowane na endpoint `/webhook/shopify/{tenant_code}` z weryfikacją HMAC-SHA256.

Wyzwania:
- Shopify Metafields mają limity: 200 metafields per produkt, 10MB per metafield value, namespace-key max 64 znaki. Nasz adapter waliduje przed wysłaniem.
- Wariant cap: 100 variants per product. Dla SKU z >100 wariantami trzeba split na osobne produkty z wskazaniem na siebie.
- Multi-currency: Shopify Markets dla locale-specific cen — mapowanie 1:1 z naszymi kanałami i locale.

Performance:
- MVP (zwykłe GraphQL, batch 250 + Exponential Backoff): pełen sync 50k SKU = ~60-120 minut (zależnie od bucket Shopify Plus vs standard, +15-30 min względem teoretycznego minimum bo backoff marnuje sloty). Akceptowalne jako nightly job.
- Faza 1 (Bulk Operations API + Leaky Bucket): pełen sync 50k SKU = ~15-30 minut. Wartość dodana gdy klienci będą oczekiwać częstszego full-sync lub urośnie skala do 200k+ SKU.

### 7.4 IdoSell (faza 1)

Kierunek: **PIM → IdoSell** (export). API IdoSell to SOAP + REST hybrid; my używamy REST gdzie się da.

Mechanizm: analogicznie do BaseLinker, z mapowaniami specyficznymi dla IdoSell (kategorie, atrybuty, warianty). IdoSell ma specyficzny model wariantów oparty na rozmiarze/kolorze — wymaga adaptera mapującego variant axes.

**Pierwotnie planowane w MVP, przesunięte do fazy 1** — w MVP koncentrujemy się na BaseLinker (PL market) + Shopify (global market), żeby pokryć dwa różne segmenty od razu.

### 7.4b Magento (faza 1)

Kierunek: **PIM → Magento 2** (export). REST API Magento 2 lub GraphQL.

Mechanizm:
- OAuth2 lub Token-based auth.
- Mapowanie atrybut PIM → Magento attribute (z attribute set options dla typów `select`).
- Mapowanie kategorii (PIM ltree → Magento tree).
- Synchronizacja w blokach po 100 produktów.
- Webhook PIM ← Magento (faza 2) dla bidirectional sync.

Wyzwania:
- Magento używa EAV → wymaga mapowania typów atrybutów (PIM `select` → Magento attribute set option).
- Multi-store mapping — każdy kanał PIM mapuje na jeden lub wiele storeView Magento.
- Performance: pełen sync 50k produktów = ~2-3h przez REST API; akceptowalne dla nightly job, niewystarczające dla real-time.

**Przesunięte z MVP do fazy 1** — Magento ma mniejszy total addressable market niż Shopify, więc kolejność integracji odzwierciedla priorytety go-to-market.

### 7.5 Wzorce dla nowych integracji

System jest projektowany pod łatwe dodawanie nowych integracji. Każdy bundle integracji implementuje interface'y:

```php
interface IntegrationAdapter {
    public function exportProduct(Product $product, IntegrationProfile $profile): ExportResult;
    public function importProduct(array $external, IntegrationProfile $profile): Product;
}

interface IntegrationClient {
    public function send(string $endpoint, array $payload): Response;
}

interface AttributeMapper {
    public function mapToExternal(ProductValue $value, MappingRule $rule): mixed;
    public function mapFromExternal(mixed $external, MappingRule $rule): ProductValue;
}
```

Kolejne integracje (Allegro, Shopify, WooCommerce, Shoper, Ceneo, Kaufland) implementują te interface'y. Czas dodania nowej integracji w fazie 1+: ~30-60h (zależnie od złożoności API).

## 8. Agent Layer

### 8.1 Architektura w MVP

Agent layer jest **wbudowany w main backend** jako Symfony service. Powody: jeden deployment, prostsza autoryzacja, mniej stron do failować w MVP. Wydzielony mikroserwis pojawi się w fazie 2 gdy obciążenie agenta wzrośnie.

### 8.2 Capabilities w MVP

Agent w MVP wykonuje wyłącznie operacje **schema-extending** (po ADR-009 słownik mówi językiem `ObjectType`):

- Dodanie nowego atrybutu do tenant (z opcjonalnym przypisaniem do `ObjectType`).
- Modyfikacja metadanych atrybutu (label translations, help text, validation).
- Dodanie nowej grupy atrybutów.
- Przypisanie atrybutów do istniejącego `ObjectType` (predefined product/category/asset). Tworzenie własnych `ObjectType` (`kind='custom'`) — zarezerwowane do Fazy 2/3.
- Tworzenie nowej kategorii w drzewie (sugar: instancja `Object` z `kind='category'`).

**Wszystkie operacje destrukcyjne** (usuwanie atrybutu, usuwanie `ObjectType`, modyfikacja typu istniejącego atrybutu) **są poza scope MVP**. W MVP: agent może tylko dodawać. Predefiniowane `ObjectType` (`is_built_in=true`) są dodatkowo blokowane przed deletion na poziomie service'u.

W fazie 2 dochodzą **data-ops capabilities**:

- Bulk update wartości atrybutów ("dla wszystkich produktów Nike, ustaw kategorię na X").
- Generowanie opisów z atrybutów (LLM tekstowy z constrained output).
- Automatyczne mapowania importów (zaproponuj mapping kolumn CSV na atrybuty).
- Translation memory (przetłumacz nazwy produktów na wszystkie locale).

### 8.3 Tool-use pattern

Agent używa Anthropic Tool Use. Lista narzędzi w Fazie 2 (po ADR-009; każde jest typowane JSON Schemą):

```
- search_attributes(query: string) → list of attributes
- search_object_types(query: string) → list of object types          (post ADR-009)
- create_attribute(code, type, label_translations, scopable, localizable, required) → AttributePending
- create_attribute_group(code, label_translations) → AttributeGroupPending
- assign_attribute_to_object_type(object_type_code, attribute_code, required_for_completeness) → AssignmentPending  (post ADR-009; było: assign_attribute_to_family)
- create_object_type(kind, code, label_translations, schema_strict) → ObjectTypePending  (post ADR-009; reserved — pełna obsługa custom kindów dochodzi w Fazie 2/3)
- create_category(parent_path, code, label_translations) → ObjectPending  (sugar — wewnętrznie tworzy Object kind='category')
- preview_changes() → DiffSummary
```

Po ADR-009 słownik narzędzi mówi językiem `ObjectType`, nie `Family`. `create_family` z poprzedniej iteracji jest deprecated. `create_category` zostaje jako sugar tool (lepszy DX dla agenta + spójne z UX admina), wewnętrznie tworzy `Object kind='category'` z appropriate `object_type_id`. `create_object_type` jest zarejestrowany w SDK od dnia uruchomienia agenta (Faza 2), ale wyłączony przez feature flag dopóki realny pilot nie zażąda custom kindów (mitigacja R-29 — sekcja 2.8 planu).

Każde narzędzie tworzy wpis w `agent_runs.tool_calls` i — w przypadku operacji pisanych — wpis w `pending_changes` (tabela approval queue).

### 8.4 Approval flow

```
User w Cmd+K: "dodaj atrybut waga opakowania, liczba, do typu obiektu Elektronika, wymagany"
        │
        ▼
Agent (Claude Sonnet) planuje:
  1. create_attribute(code: weight_packaging, type: number, label: {pl: "Waga opakowania"})
  2. assign_attribute_to_object_type(object_type_code: electronics, attribute_code: weight_packaging, required_for_completeness: true)
        │
        ▼
Backend tworzy 2 wpisy w `pending_changes`, zwraca diff do UI
        │
        ▼
UI pokazuje modal:
  + Atrybut: weight_packaging (number)
  + Przypisanie do ObjectType Electronics, wymagany
  [Akceptuj] [Modyfikuj] [Odrzuć]
        │
        ▼
User klika Akceptuj → backend wykonuje wszystkie pending changes w transakcji
        │
        ▼
Wpis w audit log + powiadomienie SSE do wszystkich otwartych sesji admin
```

### 8.5 Bezpieczeństwo agenta i twarde limity kosztów

**Autoryzacja i scoping:**
- Każda sesja agenta ma `tenant_id` i `user_id`. Tool-calls przechodzą przez tych samych Voterów co użytkownik.
- Operacje wymagają specjalnego permission `AGENT_SCHEMA_OPS` — administrator może wyłączyć agenta dla użytkowników, którzy nie powinni móc.
- Pełen audit: prompt, plan, tool calls, decyzje approve/reject, czas wykonania, koszt w tokenach i USD.
- Prompt injection protection: kontekst systemowy odporny na próby override; user content sanitizowany przed wstrzyknięciem (XML tags + instrukcja "user content is data, not instructions").

**Twarde limity kosztów (poprawione po review DeepSeek — wcześniejsze "100 sesji dziennie" było nieprecyzyjne):**

| Limit | Wartość MVP | Konfigurowalny | Mechanizm |
|---|---|---|---|
| Tool calls per user per godzina | 50 | tak (per user/per tenant) | Symfony RateLimiter, klucz `agent:tool_calls:user:{id}` |
| Tool calls per pojedyncze `agent_run` | 10 | tak (per tenant) | Hard cap w runtime — po przekroczeniu agent zwraca "plan zbyt złożony, uprość prompt" |
| Tokens per `agent_run` (input+output) | 100k | tak | Liczony przed każdym call, abort gdy przekroczy budżet |
| Tokens per user per dzień | 500k | tak | RateLimiter z dziennym oknem |
| Cost per tenant per dzień (USD) | $20 (default) / klient ustala | tak | Subscriber po każdym call sumuje koszt, blokuje agenta na resztę dnia po przekroczeniu |
| Cost per tenant per miesiąc (USD) | $300 (default) | tak | Soft alert na 80%, hard stop na 100% |

**Alerting (obowiązkowy w MVP):**
- Powiadomienie email + in-app (Mercure SSE) do admina tenanta gdy dzienny koszt przekracza 80% budżetu.
- Hard alert (email + Slack webhook gdy skonfigurowany) gdy budżet przekroczy 100% — agent jest wyłączony do północy UTC.
- Anomalia: nagły wzrost tool calls/godzina o >5× względem 7-dniowej średniej → flag dla security review (sygnał wycieku klucza lub abuse).

**BYOK (Bring Your Own Key) — implementacja w MVP-Final:**
- Klient enterprise może podać własny Anthropic API key w konfiguracji tenanta (szyfrowany w bazie, AES-256-GCM).
- Wtedy koszty są naliczane na konto klienta, nie na nasze.
- Mitiguje główne ryzyko biznesowe (kompromitacja klucza dostawcy → faktura w tysiącach USD).
- Sprzedażowo: opcja BYOK znacząco upraszcza pricing dla enterprise (klient płaci za swoje LLM, my za platformę).

**Defense in depth — co jeśli mimo limitów coś pójdzie nie tak:**
- Anthropic API key Anthropic provider ma osobny klucz per environment (dev/staging/prod).
- W Anthropic Console ustawiony **org-level monthly cap** ($1000 dla MVP-prod) — twardy hardstop niezależnie od logiki aplikacyjnej.
- Klucz w Vault (Symfony Secrets w MVP, HashiCorp Vault w fazie 2) z rotacją co 90 dni.
- Compromise response runbook (`05-runbook.md`): rotate key, audit `agent_runs` za ostatnie 7 dni, kontakt z Anthropic Trust & Safety jeśli wykryto abuse.

## 9. Bezpieczeństwo

### 9.1 Authentication

- API zewnętrzne: JWT (LexikJWTAuthenticationBundle) z refresh tokens, RS256.
- Integracje partnerskie: OAuth2 (thephpleague/oauth2-server) z client_credentials grant.
- Admin UI: sesyjny + 2FA (scheb/2fa-bundle, TOTP).
- Service-to-service (workery): API key krótko-żyjący, generowany przy starcie.

### 9.2 Authorization

Granularność na czterech poziomach:
- **Resource-level** (np. "dostęp do produktów"): ROLE_*
- **Action-level** (np. "tworzenie", "edycja", "usuwanie"): Voter sprawdza akcję
- **Field-level** (np. "ten użytkownik nie może edytować ceny"): Voter na atrybucie
- **Row-level** (np. "ten użytkownik widzi tylko produkty marki X"): query filter w Doctrine listener

Wszystko wyrażone przez Symfony Voters. Permissions ustawiane w admin UI, persystowane w `permissions` table.

### 9.3 Audit

- DoctrineAuditBundle automatycznie loguje każdą zmianę encji domenowej (kto, co, kiedy, stary stan, nowy stan) do tabel `audit_*`.
- Dodatkowy audit log dla operacji agenta (z reasoning trace).
- Logi audytowe są niemodyfikowalne (append-only, brak DELETE w polityce RBAC bazy).

### 9.4 Szyfrowanie

- TLS 1.3 obowiązkowy (FrankenPHP wymusza).
- Wrażliwe pola w bazie (np. `integration_profiles.config` z kluczami API) szyfrowane przy zapisie (Symfony Doctrine Encrypt Bundle, AES-256-GCM, klucz z ENV/Vault).
- Hasła użytkowników: Argon2id.

### 9.5 OWASP Top 10 — przegląd

| Ryzyko OWASP | Mitygacja |
|---|---|
| A01 Broken Access Control | Voters + RLS + audit |
| A02 Cryptographic Failures | TLS 1.3, AES-256-GCM, Argon2id |
| A03 Injection | Doctrine ORM (parametryzowane query), Symfony Validator, content sanitization w admin UI |
| A04 Insecure Design | DDD bounded contexts, threat modeling per release |
| A05 Security Misconfiguration | Konfiguracja jako kod (Symfony env), security headers (Caddy) |
| A06 Vulnerable Components | composer audit + npm audit w CI, dependabot |
| A07 Authentication Failures | 2FA, rate limit logowania, lockout, password policy |
| A08 Software & Data Integrity | Composer vendor signed, image signing w GHCR |
| A09 Logging & Monitoring | OpenTelemetry, structured logs, Sentry, audit |
| A10 SSRF | Symfony HttpClient z URL validation, deny private IPs w integration clients |

## 10. Wydajność i skalowalność

### 10.1 Cele performance

| Operacja | Cel p95 | Cel p99 |
|---|---|---|
| GET /api/products?page=1 (50 items) | < 200ms | < 400ms |
| GET /api/products/{id} (z atrybutami) | < 100ms | < 200ms |
| POST /api/products (create) | < 300ms | < 500ms |
| PATCH /api/products/{id} (partial update) | < 200ms | < 400ms |
| Full-text search (200k SKU) | < 300ms | < 500ms |
| Bulk export do BaseLinker (1000 SKU) | < 60s | < 120s |
| Agent schema-add (1 atrybut) | < 5s end-to-end | < 10s |

### 10.2 Strategie performance

- **FrankenPHP worker mode** — eliminacja overheadu boot Symfony per-request.
- **Symfony Cache** wielowarstwowy — adapter Redis dla shared, adapter PHP-Files dla local hot cache.
- **Doctrine second-level cache** — Redis dla query cache, in-memory L1 dla identity map.
- **HTTP cache** — API Platform z ETag i Cache-Control, Caddy jako reverse proxy z cache layer.
- **Read replicas Postgres** w fazie 1 dla rozdzielenia obciążenia read/write.
- **Materializowane widoki** dla heavy aggregations (np. completeness per family).
- **Bulk ops** w workerach — batch inserts (1000 rows/transaction), COPY zamiast INSERT dla importów.

### 10.3 Pojemność

Profiling testowy (do walidacji w fazie 1):

| Skala | DB size | RAM Postgres | RAM app | Throughput API |
|---|---|---|---|---|
| 50k SKU, 200 atrybutów | ~5 GB | 4 GB | 2 GB | 500 req/s |
| 200k SKU, 500 atrybutów | ~30 GB | 16 GB | 4 GB | 1000 req/s |
| 1M SKU (faza 3) | ~150 GB | 64 GB | 8 GB | 2000 req/s |

### 10.4 Skalowanie horyzontalne

- App tier (FrankenPHP): bezstanowy, skaluje się horyzontalnie za load balancerem.
- Workery (Symfony Messenger): skalują się przez liczbę procesów konsumentów na kolejce.
- Postgres: pionowo do ~64 GB RAM, potem read replicas + partitioning po `tenant_id`.
- Meilisearch: pionowo do ~32 GB RAM dla 200k SKU; sharding od fazy 3.
- Redis: cluster mode od fazy 2.

## 11. Multi-tenancy

### 11.1 Strategia

**Multi-tenant ready, single-tenant deployed** — decyzja zatwierdzona w fazie koncepcyjnej.

Implementacja:
- Każda tabela domenowa ma kolumnę `tenant_id UUID NOT NULL`.
- **Podstawowy mechanizm izolacji: Doctrine filter** (`TenantFilter`) automatycznie dokleja `WHERE tenant_id = :current_tenant` do każdego query, plus `tenant_id` ustawiany na save w listenerze `TenantAssignmentListener`.
- **Postgres Row-Level Security jako drugi pas (defense in depth) — aktywowany dopiero przed multi-tenant w fazie 2.** W MVP single-tenant (jeden tenant `main`) RLS jest zbędny — pomijamy do czasu, gdy faktycznie będziemy mieć >1 tenanta w jednej bazie.
- Tenant identyfikowany w request: dla admin UI z sesji, dla API z JWT claim, dla webhooks z URL prefix `/webhook/{tenant_code}/`.

W MVP: jeden tenant `main`, wszystko zachowuje się jak single-tenant. Koszt overheadu: znikomy (<1% perf), bonus: gotowość do SaaS od dnia 0.

### 11.1a Pułapki RLS — co warto wiedzieć przed aktywacją (zgłoszone w review DeepSeek)

RLS w Postgres to potężne narzędzie, ale ma subtelne pułapki, które trzeba zaadresować przed produkcyjną aktywacją:

| Pułapka | Konsekwencja | Mitigacja |
|---|---|---|
| `COPY` (bulk insert/export) ignoruje RLS | Wycieki przy importach/exportach | Wyłączać RLS przed `COPY` (jako superuser) i włączać po; alternatywnie używać `INSERT ... SELECT` które respektuje RLS |
| Performance overhead 2-5% | Odczuwalne przy 200k+ SKU i complex queries | Benchmark przed produkcją; indeksy z `WHERE tenant_id = ...` jako partial indexes |
| Polityki RLS pisane w SQL | Trudniejsze do testowania niż logika PHP | Dedykowany test suite (testy izolacji R-09): tworzymy dwa tenanty, próbujemy odczytać dane drugiego, oczekujemy 0 wierszy |
| Superuser/`BYPASSRLS` omija RLS | Każdy z bezpośrednim dostępem do bazy widzi wszystko | App user nigdy nie ma `BYPASSRLS`; superuser tylko dla migracji i operacji administracyjnych |
| RLS nie chroni przed SQL injection | Jeśli ktoś wstrzyknie złośliwy SQL, RLS mu nie przeszkodzi | Doctrine ORM z parametryzowanymi query + Symfony Validator |
| `SET ROLE` w runtime | Connection pool może mylić sesje | `SET LOCAL` zamiast `SET`, current tenant ustawiany via `SET LOCAL pim.current_tenant_id = :id` na starcie każdej transakcji |

**Plan aktywacji RLS przed multi-tenant (faza 2, ~16-24h pracy):**
1. Implementacja polityk RLS dla każdej tabeli z `tenant_id` (~6-8h, generowane skryptem z metadanych Doctrine).
2. Comprehensive test suite — testy izolacji w CI, dwa tenanty, 100% pokrycie tabel domenowych (~6-8h).
3. Pen-test izolacji — niezależny audytor zewnętrzny próbuje cross-tenant access (~4-8h+ zewnętrznie).
4. Migracja: w jednym oknie maintenance aktywujemy `ENABLE ROW LEVEL SECURITY` na wszystkich tabelach, potem włączamy multi-tenant flag.

**Po ADR-009 — uwaga o granularności per `kind`:** RLS w MVP/Faza 1 operuje wyłącznie na `tenant_id`. Jeśli klient enterprise w Fazie 2/3 zażąda izolacji per typ obiektu (np. „dział marketingu widzi tylko `kind='asset'`, dział katalogu tylko `kind='product'`"), polityka RLS może zostać rozszerzona o predykat `object_type_id IN (SELECT object_type_id FROM tenant_user_object_type_grants WHERE user_id = current_setting('pim.current_user_id'))`. Rzadkie wymaganie, ale wspierane przez generalizację — w MVP nie implementujemy.

**W Sprint 0** zawieramy tylko prosty smoke-test izolacji (tworzymy 2 tenanty, sprawdzamy że Doctrine filter działa) — pełen RLS pen-test odkładamy do fazy 2.

### 11.2 Aktywacja multi-tenancy w fazie 2 (SaaS)

Zmiana flagi `MULTI_TENANT_MODE=true` aktywuje:
- Signup flow (admin tworzy tenanta + pierwszego użytkownika).
- Tenant-aware billing (osobne stripe customers).
- Subdomeny per tenant (`tenant1.pim.example.com`).
- Limity per tenant (liczba SKU, użytkowników, integracji) z RateLimiter.

## 12. Deployment i operacje

### 12.1 Topologia produkcyjna (single-tenant on-prem klienta)

```
[Internet]
    │
    ▼
[Caddy / FrankenPHP] (instancja n=2 za HAProxy/keepalived)
    │
    ├─── [PostgreSQL primary] + [PostgreSQL replica]
    ├─── [Redis] (instancja master + replica)
    ├─── [Meilisearch] (instancja n=1)
    ├─── [MinIO cluster] (n=4 dla erasure coding)
    ├─── [Mercure hub] (n=1)
    └─── [Workers Messenger] (n=2-4 procesy)

[Backup target — S3-compatible]
    ▲
    │
[pgBackRest + WAL archiving co 5 min, MinIO replication, Meilisearch snapshots co 24h]
```

### 12.2 Topologia faza 2 (Kubernetes, multi-tenant SaaS)

Migracja z Docker Compose na Kubernetes:
- Helm chart z wszystkimi komponentami.
- Postgres Operator (CrunchyData lub Zalando) z auto-failover.
- Redis Operator (Redis Cluster mode).
- Meilisearch StatefulSet.
- MinIO Operator.
- Workers jako Kubernetes Deployments z HPA.

### 12.3 CI/CD

GitHub Actions pipeline:
1. Push do PR → unit testy (**PHPUnit**, Vitest), static analysis (PHPStan max, Psalm), code style (PHP-CS-Fixer, Biome), security audit (composer audit, npm audit).
2. Merge do main → integration tests (**PHPUnit + API Platform `ApiTestCase`**, Playwright E2E), build images, push do GHCR.
3. Tag release → deploy do staging (auto), deploy do production (manual approval).

### 12.3a Backup i disaster recovery — od dnia 1 (zmiana po review)

**Decyzja:** w MVP od dnia 1 wdrażamy pełen pgBackRest + WAL archiving zamiast prostego pg_dump.

**Powód:** pierwszy klient pilotażowy nie wybaczy utraty dnia pracy. Różnica między pg_dump (RPO=24h) a pgBackRest+WAL (RPO=5min) jest niewspółmierna do nakładu pracy (2-4h konfiguracji w docker-compose).

**Konfiguracja MVP:**
- pgBackRest stub (sidecar container w docker-compose) z repo na MinIO bucket `pim-backups` (lub S3 jeśli klient preferuje cloud).
- Pełen backup co tydzień (niedziela 02:00 UTC), differential codziennie, WAL archiving co 5 minut.
- Retencja: 4 tygodnie pełne, 30 dni differential, 7 dni WAL.
- **Test restore** — automatyczny, raz w tygodniu na osobnym kontenerze, weryfikuje że restore działa i baza się podnosi (test rzucany do Sentry/Slack jeśli fail).

**Runbook DR (`05-runbook.md`):** procedura PITR (point-in-time recovery), kontakty, decision tree dla różnych scenariuszy (data corruption, hardware failure, human error).

### 12.4 Monitoring i alerting

- Metryki: Prometheus + Grafana, dashboard "PIM Health" (latency p50/p95/p99 per endpoint, error rate, queue depth, DB connections, Meilisearch indexing lag).
- Logi: structured JSON, Loki lub OpenSearch.
- Tracing: OpenTelemetry → Tempo lub Jaeger.
- Errors: Sentry self-hosted (lub GlitchTip jako MIT-friendly alternatywa).
- Alerty: Grafana Alerting → email/Slack/PagerDuty.

## 13. Architecture Decision Records (ADR)

### ADR-001: Wybór języka i frameworka backendu

**Status:** Zaakceptowany
**Kontekst:** Wybór stacku technologicznego dla PIM konkurencyjnego z PIMcore/Akeneo.
**Rozważane opcje:** Node.js + Directus, PHP + Laravel + Filament, PHP + Symfony + API Platform, .NET 9 + ABP.
**Decyzja:** PHP 8.4 + Symfony 7.x LTS + API Platform 4.
**Uzasadnienie:** Branżowa zgodność (Akeneo, PIMcore, Ergonode wszyscy Symfony), najmocniejszy framework do złożonego domain modeling (Doctrine), API Platform jako najlepsza implementacja API-first w PHP, formalny LTS co 2 lata, rozpoznawalność stacku w działach IT klientów enterprise.
**Konsekwencje:** Większy boilerplate niż Laravel, dłuższy MVP niż z Directus, ale stack na 10 lat.

### ADR-002: Odrzucenie Directus jako podstawy admina

**Status:** Zaakceptowany
**Kontekst:** Directus oferowałby najszybszą drogę do MVP z runtime schema modyfikacji (idealny dla agentic CMS).
**Decyzja:** Nie używać Directus.
**Uzasadnienie:** Directus 11+ przeszedł na BSL 1.1 (Business Source License). Mimo że dla małej skali licencja jest darmowa, BSL nie spełnia definicji open source wg OSI i wprowadza niepewność prawną przy rozwoju komercyjnym (>5M USD ARR przesuwa do paid tier). Hard constraint klienta: zero ryzyka licencyjnego.
**Konsekwencje:** +20-30h pracy nad emulacją runtime schema w Symfony (przez metadata + JSONB + dynamic forms), w zamian pełna własność i swoboda komercjalizacji.

### ADR-003: Multi-tenant ready, single-tenant deployed

**Status:** Zaakceptowany
**Kontekst:** Niejednoznaczność modelu biznesowego — enterprise wdrożenia (single-tenant) vs SaaS (multi-tenant).
**Decyzja:** Architektura zaprojektowana multi-tenant od dnia 0, wdrożenia MVP w trybie single-tenant.
**Uzasadnienie:** Koszt overheadu w MVP: 2-3h pracy (kolumna tenant_id, listener, RLS). Koszt dodania post-factum: 40-60h plus migracje danych. Asymetria zysków uzasadnia decyzję.
**Konsekwencje:** Wszystkie tabele mają `tenant_id` od dnia 1, wszystkie zapytania filtrowane przez Doctrine listener (mechanizm pierwszej warstwy). RLS Postgres odkładamy do fazy 2 jako defense in depth — w MVP single-tenant deployment jest realną pierwszą linią obrony, RLS dochodzi przed pierwszym multi-tenant deploymentem (sekcja 11.1a — plan aktywacji 16-24h).

### ADR-004: Meilisearch zamiast Elasticsearch

**Status:** Zaakceptowany
**Kontekst:** PIM potrzebuje full-text search dla 200k+ SKU.
**Rozważane opcje:** Elasticsearch (przemysłowy standard, używany przez Akeneo), Meilisearch (młodszy, prostszy), Typesense.
**Decyzja:** Meilisearch jako default, z abstrakcją umożliwiającą swap na ES w fazie 2 jeśli wymagania urosną.
**Uzasadnienie:** Meilisearch jest 10x prostszy operacyjnie (jeden binarny plik, brak JVM, brak skomplikowanych mappings), szybszy out-of-the-box dla naszej skali, MIT. Elasticsearch ma lepsze możliwości aggregations i analytics, ale na 200k SKU to nadmiar.
**Konsekwencje:** Mniej kompetencji wymaganych w zespole operacyjnym; w fazie 3 można dodać ES jako dodatkowy indeks dla zaawansowanych analytics, zachowując Meilisearch dla user-facing search.

### ADR-005: Refine.dev + shadcn/ui jako frontend admina

**Status:** Zaakceptowany
**Kontekst:** Admin musi być agentic-first (Cmd+K, streaming, inline AI buttons, schema diff preview).
**Rozważane opcje:** EasyAdmin (Symfony Twig), API Platform Admin (React + MUI), Refine + shadcn (React), custom Vue 3 + shadcn-vue (jak Ergonode).
**Decyzja:** Refine.dev + shadcn/ui + Vite + React 19.
**Uzasadnienie:** EasyAdmin/Sonata to server-rendered, niedopasowane do streaming UX. API Platform Admin używa Material UI, którego customizacja jest bolesna dla custom UX patternów. Refine to headless framework z hookami na CRUD/auth/RBAC oszczędzający 40% pracy nad standardowymi widokami; shadcn daje lokalne, ownable komponenty. Custom Vue byłby najszybszym development-wise long-term, ale 2-3x dłuższy w MVP.
**Konsekwencje:** Backend (Symfony) i frontend (React) w **jednym monorepo (Turborepo)** z dwoma apps i wspólnym pipeline CI/CD. Ścisły kontrakt API jest pozytywem (wymusza dyscyplinę). Frontend dev musi znać React + TypeScript.

**Disclaimer — koszt poznawczy (cognitive load) dla non-codera używającego Claude Code:**
Wybór Refine + shadcn oznacza realnie **dwa repozytoria do utrzymania (Symfony backend + React admin frontend) i dwa języki w stacku (PHP + TypeScript)**. To jest mental overhead, którego nie ma w monolitach typu Laravel + Filament czy EasyAdmin (gdzie wszystko jest w PHP/Twig). Dla osoby, która nie programuje samodzielnie i polega na Claude Code jako głównym wykonawcy, jest to świadoma decyzja-kompromis.

**Dlaczego mimo to ta decyzja jest właściwa w 2026 roku:**
1. **Claude Code w 2026 świetnie radzi sobie z cross-stack development** — przeskakiwanie między PHP a TypeScriptem w jednym kontekście jest dla agenta naturalne; "jeden język = mniejszy ból" było argumentem 2023, dziś znaczenie tego argumentu spadło o ~60-70%.
2. **Agentic-first UX to twardy wymóg biznesowy i kluczowy differentiator sprzedażowy** — Cmd+K palette, streaming odpowiedzi LLM, inline AI buttons, schema diff preview, real-time collaboration cues — to są wzorce, które w Material UI (API Platform Admin) wymagają walki z frameworkiem; w shadcn (komponenty są kopiowane do repo i w pełni edytowalne) są naturalne.
3. **Alternatywa (API Platform Admin + MUI) kompromituje obie kluczowe rzeczy jednocześnie** — i demo wow-factor (MUI wygląda jak każdy admin sprzed 5 lat), i możliwość customizacji nowych UX patternów (wrap'y wokół MUI są kruche i pracochłonne).
4. **shadcn === ownership komponentów** — w razie problemu z biblioteką nie czekamy na patch upstream; mamy lokalny kod do edycji. Dla 10-letniego horyzontu produktu to istotne.
5. **Mitigacja overhead'u — decyzja dopełniająca po review:** **monorepo z Turborepo** (jedno repo Git, struktura `apps/api` + `apps/admin` + `packages/shared-types`), wspólny CI/CD pipeline w GitHub Actions, OpenAPI-generated TypeScript types z API Platform przez `openapi-typescript` build step (eliminuje ręczne synchronizowanie typów backendu i frontendu). Wybór Turborepo > Nx: Turborepo jest prostszy, wystarczający dla 2 apps + 1-2 shared packages, MIT, lepiej zintegrowany z Vercel-style workflows; Nx ma więcej możliwości ale wprowadza własną filozofię, która jest overkill dla naszej skali. Dla non-codera + Claude Code: jeden `git clone`, jedno `pnpm install` (lub równoważnik), jeden plik PR review, jeden CI run.

To jest świadomy wybór: **akceptujemy 15-20% więcej kompleksowości stacku w zamian za demo-grade UX i 10-letnią rozwijalność**. W modelu "non-coder + Claude Code" ta dodatkowa kompleksowość nie spada na użytkownika — spada na agenta, który ją obsłuży.

### ADR-006: PostgreSQL z JSONB zamiast EAV w czystej formie

**Status:** Zaakceptowany
**Kontekst:** PIM potrzebuje elastycznego modelu atrybutów, gdzie atrybuty mogą być dodawane w runtime przez admina/agenta.
**Rozważane opcje:** Czysty EAV (osobna tabela values), JSONB (wszystko w kolumnie), hybrid.
**Decyzja:** Hybrid: tabela `product_values` z kolumną `value JSONB` (klasyczny EAV ale z JSON jako bag) + denormalizowany `attributes_indexed JSONB` w `products` dla szybkich queries i indexów GIN.
**Uzasadnienie:** Czysty EAV jest okropny dla performance przy queries cross-attribute. Czysty JSONB w jednej kolumnie traci informacje o scope/locale. Hybrid daje czytelność modelu i performance z denormalizacją.
**Konsekwencje:** Indeksy GIN na obu reprezentacjach. Trochę więcej kodu, znacznie lepsza wydajność queries.

**Strategia utrzymania denormalizacji (poprawione po review Gemini):**
Pojedyncza edycja produktu z palca → Doctrine event listener po zmianie `product_values` (synchroniczny, prosty, ~5ms overhead). To jest happy path dla pracy admina przez UI.

**Bulk path** (import 50k SKU z BaseLinkera/Shopify, masowa edycja przez agenta, migracja danych) wymaga **innej ścieżki, bo synchroniczny listener × 50k = killer**:
- W bulk handlerach Messengera wyłączamy listener przez `EntityManager::getEventManager()->removeEventListener()` lub flagę kontekstową `BulkContext::isBulk()`.
- Po zakończeniu batchu publikujemy event `ProductValuesChanged(productIds: [...])` na kolejkę Redis.
- Dedykowany worker `attributes-indexed-rebuild` czyta event i przelicza `attributes_indexed` paczkami (np. 1000 produktów per transakcja, z `EntityManager::clear()` po każdym chunku).
- Alternatywa techniczna do rozważenia w fazie 1 (jeśli benchmark pokaże gain): **trigger PL/pgSQL** w PostgreSQL przeliczający `attributes_indexed` deklaratywnie w bazie. Plus: brak zależności od warstwy aplikacji. Minus: trudniejszy debug, logika domenowa w SQL. Decyzja po profilingu w fazie 1.

**Walidacja:** Sprint 0 zawiera benchmark "import 5k SKU end-to-end" jako gate decision — jeśli synchroniczny listener × 5k przekracza 60s, od razu przechodzimy na async pattern dla bulk (już w MVP-Alpha).

### ADR-007: Agent layer wbudowany w MVP, mikroserwis w fazie 2

**Status:** Zaakceptowany
**Kontekst:** Agent layer może być częścią main backendu lub osobnym mikroserwisem.
**Decyzja:** W MVP — Symfony service w main backendzie. W fazie 2 — wydzielony mikroserwis (Node.js lub PHP) z własnym deploymentem.
**Uzasadnienie:** W MVP priorytetem jest prostota deploymentu i jeden artefakt. Agent layer w MVP jest też prostszy (tylko schema-add). W fazie 2, gdy agent ma data-ops capabilities i obciążenie LLM API rośnie, separacja pomaga w skalowaniu i izolacji błędów.
**Konsekwencje:** W MVP agent dziedziczy całą autoryzację i sesję z main app — łatwiej. W fazie 2 będzie migracja z service-to-microservice — nieduża, ale realna.

### ADR-008: API Platform 4 zamiast custom REST/GraphQL

**Status:** Zaakceptowany
**Kontekst:** Wybór warstwy API.
**Rozważane opcje:** Custom REST (FOSRestBundle lub natywne kontrolery Symfony), API Platform 4, GraphQL-only (overblog/GraphQLBundle).
**Decyzja:** API Platform 4.
**Uzasadnienie:** API Platform jest najmocniejszą biblioteką API-first w PHP — auto-generuje REST, GraphQL, JSON-LD, Hydra, OpenAPI z encji Doctrine. Oszczędza 40-60h boilerplate. Aktywnie rozwijany, MIT, używany przez setki firm. Custom REST dawałby więcej kontroli ale za cenę 5-10x większej pracy.
**Konsekwencje:** Konwencje API Platform (filterszczki, paginacja, serializacja przez grupy) trzeba poznać i przestrzegać. Trochę "magic" — debugowanie wymaga znajomości frameworka.

### ADR-009: Generic `ObjectType` z predefiniowanymi typami Product/Category/Asset

**Status:** Zaakceptowany (2026-04-27)

**Kontekst:**
Pierwotny model PIM-u traktował `Product` i `Category` jako odrębne encje pierwszej klasy z asymetrycznym modelem atrybutów: `Product` ma `Family` + `FamilyAttribute` + `ProductValue` (pełen EAV-z-JSONB), `Category` ma tylko `code` / `path` (ltree) / `name`. Trzy obserwacje wymusiły rewizję:

1. **Klienci pilotażowi (B2B technical, archetyp z `03-funkcjonalnosci-mvp.md`) zarządzają nie tylko produktami.** Kategorie, dostawcy, listy cenowe, oferty mają własne user-defined atrybuty. PIMCore-style elastyczność jest wymogiem rynku, nie nice-to-have.
2. **Eksport `Zrodla/PIMCore/masowy_eksport_konfiguracji.json`** (faktyczna obecna konfiguracja klienta Ideo) pokazuje klasę `Kategoria` z własnymi polami SEO (`metaTitle` + `metaDesc`) + obrazem (`main image`). W obecnym modelu PIM nie ma na to miejsca — `Category` ma tylko trzy pola twarde.
3. **PIMCore osiąga elastyczność przez 4 niezależne mechanizmy** (Classification Store + Object Bricks + Field Collections + Localized Fields — `Zrodla/PIMCore/objects-pimcore.md` sekcje 5.1–5.4). Ten rozdrobniony model jest jednym z głównych powodów, dla których PIMCore „wymaga miesięcy konfiguracji". My redukujemy go do **jednego** mechanizmu (`attributes` + `*_values JSONB` + `attributes_indexed`), parametryzowanego o typ obiektu.

**Rozważane opcje:**
- **(a)** Hard-coded `Product` + `Category` z asymetrycznym modelem (status quo): `Category` nie ma EAV, dodanie SEO do kategorii = migracja DDL.
- **(b)** Pełna generalizacja każdego bytu domenowego do `ObjectType` (jak PIMCore Class Definition): admin/agent definiuje wszystkie typy w runtime, brak twardych encji. Maksymalna elastyczność, ale UX dla MVP się rozjeżdża (admin musi sam zdefiniować „produkt" przed pierwszym użyciem) i blokuje konkretną optymalizację per kind (ltree dla kategorii, storage_path dla asset).
- **(c)** Generic `ObjectType` w bazie z **predefiniowanymi `Product`/`Category`/`Asset` siedzącymi jako built-in instancje** (`is_built_in=true`) + UX zoptymalizowany pod te trzy w admin UI + custom kindy (`Customer`, `Supplier`, `PriceList`) odblokowane dla Fazy 2/3.

**Decyzja:** Opcja (c).

**Uzasadnienie:**
Rdzeń elastyczności już istnieje w ADR-006 (`attributes` + EAV `*_values JSONB` + `attributes_indexed JSONB`). Generalizacja parametryzuje go o `object_type_id` — koszt minimalny na poziomie modelu danych, zysk maksymalny na poziomie zakresu domain modelu. UX dla MVP zostaje predefiniowany („Produkty", „Kategorie", „Zasoby" w głównej nawigacji + dedykowane sugar paths `/api/products`, `/api/categories`, `/api/assets`), ale silnik pod spodem pozwala adminowi/agentowi w Fazie 2/3 zdefiniować własny `Customer`, `Supplier`, `PriceList` bez migracji DDL. Asymetria jakości obu PR-paczek jest taka sama jak ADR-003 (multi-tenant ready, single-tenant deployed) — sprawdzony pattern.

**Konsekwencje:**
- **Sekcja 5.2 modelu danych:** `families` → `object_types` (+ `kind`, `is_built_in`); `family_attributes` → `object_type_attributes`; `products` + `categories` → wspólna `objects` z `object_type_id` + denormalizowanym `kind` (do filterów/query). `product_values` → `object_values`. `assets` zostaje osobną tabelą (DAM ma własny lifecycle storage/variants), dochodzi opcjonalny `object_type_id` jako FK do reguł schematu — user-defined metadata przez `object_values`.
- **Predefiniowane `object_types`** (`product`, `category`, `asset`) seedowane jako fixture przy migracji multi-tenant init i **zablokowane przed deletion** (flag `is_built_in=true` egzekwowana w service'ach + RLS gdy aktywne). Klient/agent w Fazie 2/3 dodaje własne kindy (`kind='custom'`).
- **Generated columns parametryzowane per `kind`:** `path` (ltree) tylko dla `kind='category'` — Doctrine listener walidujący path tylko dla tego kind. Generic kolumny (`name_pl`, `sku` jako `name_pl_for_product`) jako PostgreSQL `GENERATED ALWAYS AS (CASE WHEN kind='product' THEN ... END)` lub partial functional indexes.
- **API Platform 4:** sugar paths `/api/products`, `/api/categories`, `/api/assets` jako predefiniowane ApiResource per kind (dla DX integratorów + zgodności z mental modelem REST). Pod spodem wspólny `ObjectController` + serializer context per `kind`. Custom kindy w Fazie 2/3 pójdą przez unified `/api/objects?kind=custom_xxx`.
- **Doctrine listenery** (`attributes_indexed` sync, `completeness_pct` rebuild) parametryzują się o `object_type_id`. Reguły completeness czyta z `ObjectType.completeness_rules`, nie z hard-coded `Family`.
- **Multi-tenant filter** zostaje + dochodzi opcjonalny `ObjectTypeFilter` na poziomie ApiResource (Symfony Voter + serializer context per `kind`).
- **Migracja schematu:** w MVP-Alpha nie ma legacy data — predefiniowane `object_types` jako pierwsza migracja. Import z PIMCore (Faza 1+) mapuje klasy PIMCore → custom `ObjectType`.
- **Koszt:** **+16–25h w MVP-Alpha epik 0.3** (rewrite encji + ObjectType-aware listenery + sugar API paths + szkielet `kind='custom'`). Finansowane ze zwolnionego budżetu epiku 0.7 (przeniesionego do Fazy 2 — rewizja 2026-04-27, `06-sprint-0-findings.md` §2). Top-line MVP-Alpha się trzyma.
- **Słownik domenowy:** w nowym kodzie używamy „ObjectType" wszędzie — pojęcie „Family" jest deprecated. Old-aware: ApiResource path `/api/products` (nazwa user-facing), code path `App\Catalog\Domain\Entity\Object` (klasa generic).

**Referencje:**
- `Zrodla/PIMCore/objects-pimcore.md` sekcje 5.1–5.4 (Classification Store / Bricks / Field Collections / Localized Fields) + 9.1–9.3 (mapowanie wzorców PIMCore na nasz model — które adaptujemy, które odrzucamy).
- `Zrodla/PIMCore/masowy_eksport_konfiguracji.json` — realna konfiguracja klienta Ideo, klasa `Kategoria` z SEO + image jako proof of need.
- ADR-003 (multi-tenant ready, single-tenant deployed) — analogiczna asymetria gotowość/deployment.
- ADR-006 (hybrid attribute model — `attributes` + `*_values JSONB` + `attributes_indexed`) — generalizacja respektuje, nie zmienia.
- `06-sprint-0-findings.md` §2 (rewizja zakresu MVP 2026-04-27) — finansowanie kosztu generalizacji ze zwolnionego budżetu epiku 0.7.

**Konsekwencja UX/UI (epik UI-08 ULV, 2026-05-25):** widok listy instancji jest uniwersalny per ObjectType — jeden komponent `ObjectListView` sparametryzowany `objectTypeId` zastępuje per-kind admin pages. `/products` / `/categories` / `/assets` zostają jako aliasy/sugar paths obok generycznego `/objects/{slug}` (slug = `ObjectType.code`). Backend strona: `GET /api/objects?objectType={id}` (poly-kind GetCollection + ULV-03 ObjectTypeFilter), `GET /api/object_types/{id}/list-schema` (system + show_in_list kolumn), `POST /api/objects/bulk` (delete; pozostałe akcje follow-up), pojedynczy Meilisearch indeks `objects` z facetem `object_type_id`. Pełna spec: [`Project Plan/UI/feature-universal-object-list.md`](UI/feature-universal-object-list.md) — 13 ticketów ULV-01..ULV-12 (z 04 split na 04a/04b), milestone [Epik UI-08 Universal Object List View](https://github.com/malipie/PIM/milestone/16). RBAC dochodzi w wariantach: legacy per-kind voters dalej działają, nowy generic `ObjectScopedVoter` (ULV-04a) + 3-state attribute permissions reader (ULV-04b) layer'ują się ponad nimi bez breaking changes. Custom kindy (`kind='custom'`) są od dnia ULV indeksowane w Meilisearch i renderowane przez ObjectListView (pre-ULV były skipowane w MVP per pierwotny scope ADR-009).

### ADR-012: Attribute Group as first-class entity for cross-objecttype data modeling

**Status:** Zaakceptowany (2026-05-01)

**Kontekst:**
ADR-009 wprowadził generic `ObjectType` z parametryzowanym modelem atrybutów (`object_type_attributes` junction). Każdy ObjectType ma swój zestaw atrybutów. To wystarcza dla podstawowego CRUD-a, ale nie rozwiązuje trzech rzeczywistych problemów modelowania, które wyłaniają się przy projektowaniu pierwszej zakładki „Modelowanie" (epik UI-08, `Project Plan/UI/epik-08-modelowanie.md`):

1. **Powielanie sekcji formularzy między ObjectType.** Sekcja „Marketing" (short_description + long_description + tags) ma sens w `Product`, ale również w `Service`, `Subscription`, `Bundle`. W obecnym modelu trzeba albo dodać te 3 atrybuty osobno do każdego ObjectType (powielanie metadanych), albo uznać że to ograniczenie i klient sobie poradzi (UX cierpi).
2. **Dziedziczenie metadanych po drzewie kategorii.** Realna potrzeba z PRD: kategoria *„Lekarz"* deklaruje grupę „Wymagania medyczne" dla obiektów typu `Service`. Podkategoria *„Chirurg"* dziedziczy + dodaje „Chirurgia szczegóły". Podkategoria *„Ortopeda"* dziedziczy łącznie 5 grup (system Audit + 1 globalna z Service + 3 z Lekarz + 1 z Chirurg) + dodaje 1 własną. PIMCore i Akeneo tego nie robią natywnie — wymaga ręcznego skopiowania atrybutów per kategoria.
3. **Wymienność grupy jako jednostka.** Migracja grupy „Wymagania medyczne" z ObjectType `Service` do ObjectType `Equipment` (np. firma rozszerza ofertę o sprzęt medyczny) musi być JEDNĄ operacją, nie *„skopiuj 4 atrybuty + zmodyfikuj 7 kategorii + sprawdź czy żadnego nie pominęliśmy"*.

**Rozważane opcje:**
- **(a)** Status quo: `attribute_groups` jako lekka tabela (już istnieje z `code` + `label` + `position`), `Attribute.group_id` 1:N — atrybut należy do ≤1 grupy, grupy są attribute-tag'em, nie samodzielnym bytem. **Problem:** brak rozwiązania dla problemów 1-3.
- **(b)** Hard-coded sekcje per ObjectType (jak Akeneo): grupa to enumerator `'identification' | 'marketing' | 'technical_specs'` w `Attribute`, sekcje generowane w UI per ObjectType. **Problem:** brak custom grup w runtime, brak dziedziczenia po drzewie, klient bez admina nie może zmienić sekcji.
- **(c)** Pełny first-class `AttributeGroup` z M:N attribute ↔ group (junction `attribute_group_attributes`) + M:N obj_type ↔ group (junction `object_type_attribute_groups`) + dziedziczenie po drzewie kategorii (junction `category_attribute_groups`) + opcjonalna `visible_when` reguła per attribute w grupie. Grupa jest wymienną jednostką, ma własny URL (`/modeling/attribute-groups/medical-requirements`), audit log, kontrolę dostępu.

**Decyzja:** Opcja (c).

**Uzasadnienie:**
Asymetria zysków vs koszt jest podobna jak w ADR-003 (multi-tenant ready, single-tenant deployed) i ADR-009 (generalizacja ObjectType). Koszt schematu: **+1 nowa tabela rozszerzająca `attribute_groups` (description, icon, color, is_system_group, auto_attached) + 3 junction tables (attribute_group_attributes, object_type_attribute_groups, category_attribute_groups)** + listener `EffectiveAttributeGroupResolver` (~80 linii kodu). Zysk: rozwiązuje 3 problemy modelowania, których inne PIM-y nie rozwiązują, daje *killer feature* (inheritance preview w UI Modelowania). Akeneo i Pimcore nie mają tego natywnie — klient pisze SQL skrypty albo modyfikuje 50 podkategorii ręcznie.

**Konsekwencje:**
- **`attribute_groups` rozszerzona:** istniejące pola (`code`, `label`, `position`) zachowane (back-compat z 0.3.X). Dochodzą: `description JSONB` (multi-locale), `icon VARCHAR(64)`, `color VARCHAR(16)`, `is_system_group BOOLEAN` (true dla grupy „Audit" auto-attached do każdego ObjectType), `auto_attached BOOLEAN` (true gdy grupa dołączana automatycznie do nowego ObjectType — w MVP tylko Audit).
- **Junction `attribute_group_attributes` (M:N Attribute ↔ AttributeGroup)** — `attribute_group_id, attribute_id, position, is_required_in_group, visible_when JSONB` (PK na (group, attribute)). **Pozostawia istniejący `Attribute.group_id` (1:N) jako deprecated path** — migracja danych do M:N w follow-up po `#UI-08.5` (gdy admin UI obsłuży multi-group attribute attachment). W UI-08.1 oba paths koegzystują (additive only).
- **Junction `object_type_attribute_groups` (M:N ObjectType ↔ AttributeGroup)** — `object_type_id, attribute_group_id, position` (PK).
- **Junction `category_attribute_groups`** — `category_object_id, target_object_type_id, attribute_group_id, position` (PK). Katergoria deklaruje która grupa atrybutów ma się pojawić *dla obiektów typu X* w tej kategorii i jej podkategoriach.
- **Domain service `EffectiveAttributeGroupResolver`** (Catalog/Domain/Service, `#UI-08.4`) — dla danej pary (object_id, category_path) zwraca efektywną listę grup z dziedziczeniem: (1) system auto-attached (Audit), (2) globalne dla ObjectType, (3) dziedziczone po drzewie kategorii od root do leaf, (4) per-object ad-hoc (Faza 1+ nullable). Cache Redis 5min TTL z invalidation hooks.
- **`visible_when` JSONB** w `attribute_group_attributes` — w MVP tylko `{field, operator: 'equals', value}` (`#UI-08.8`). Faza 1 dochodzi `not_equals`, `in`, `not_in` + composite AND/OR.
- **Listener `ObjectFormSchemaListener`** — endpoint `GET /api/objects/{id}/form-schema` używa resolvera, frontend renderuje formularz dynamicznie.
- **Brand jako 4-ty built-in ObjectType** (`#UI-08.2`) — niezależna od ADR-012, ale dochodzi razem z rozszerzeniem `object_types` o `is_built_in/code_immutable/deletable/icon/color`.
- **Migracja istniejących danych:** w MVP-Final brak legacy AttributeGroup (~5 grup z seedera 0.3.X — 'identification', 'marketing', etc.), więc migracja DDL jest puro additive — tabele istniejące zostają, dochodzą nowe kolumny + 3 junction tables. Migracja `Attribute.group_id` → `attribute_group_attributes` zaplanowana po `#UI-08.5` (gdy admin UI ma drag-drop dla multi-group).
- **Koszt:** **~30-40h backend (`#UI-08.1` do `#UI-08.8`) + ~30-40h frontend (`#UI-08.9` do `#UI-08.15`)**. Total **~60-80h** w epiku 0.12 / UI-08, post-MVP-Final (epik 0.11), pre-Faza 1 (`Project Plan/02-plan-projektu-pim.md` §3.6).
- **Słownik domenowy:** „Attribute Group" (a-g w UI) zawsze. Old-aware: w 0.3.X używaliśmy „grupa atrybutów" jako lekkiego konceptu UI; teraz to first-class entity.

**Referencje:**
- `Project Plan/UI/epik-08-modelowanie.md` §3.5 (motywacja), §3.8 (pełny model encji + DDL), §12 (dependency na backend).
- ADR-009 (generic ObjectType) — ADR-012 rozszerza, nie zastępuje.
- ADR-006 (hybrid attribute model) — ADR-012 respektuje (`object_values JSONB` zostaje source of truth dla wartości; AttributeGroup jest tylko o organizacji formularzy + dziedziczeniu).
- `02-plan-projektu-pim.md` §3.6 (epik 0.12 / UI-08 sequencing).

### ADR-013: Role-Based Access Control od dnia 1 w MVP-Alpha

**Status:** Zaakceptowany (2026-05-18)

**Kontekst:**
Pierwotna wersja `PRD-PIM-rbac.md` (v1) zakładała *„Faza 1 cuts"* — minimalny RBAC w MVP (4-5 ról immutable, brak builder-a, brak field-level, brak workflow gating) z pełnym scope dopiero w Fazie 1. Decyzja była motywowana presją czasu MVP-Alpha i założeniem że RBAC to *„cross-cutting tax"*, który można dopisać post-factum. Pięć sygnałów wymusiło reverse podczas planowania pilotów (zsyntetyzowane w PRD-PIM-rbac §6.1, v2):

1. **API tokens scopes muszą dojrzeć przed pierwszym integratorem** — token bez scope = total access = ryzyko leak'u (BaseLinker / Shopify side-channel). Pilot z integratorem bez per-scope tokenów to incident waiting to happen.
2. **Cmd+K agent rate limits + cost ceilings** — agent z runaway billing potencjalnie generuje $1000+/dzień bez gatingu (sekcja 8.5 architektury — twarde limity). Implementacja tych limitów wymaga User → Role → Permission resolwera od dnia 1.
3. **Audit log compliance** — RODO + roadmap SOC 2 (Faza 3) wymagają *„kto kiedy co zmienił"* z `permission_check_result` per akcji. Retrofit audit logu na działającym systemie = re-write ~60 endpointów + brak historycznych entries.
4. **Field-level secrets** — `attributes.integration_visible` flag oraz role-based scrubbing JSONB fields (credentials, internal margins, supplier notes) — bez 3-state attribute permissions (restricted/view/edit) Marketing widzi marżę kosztową, Translator widzi credentialé BaseLinkera.
5. **Cross-tenant isolation defence in depth** — przed pierwszym multi-tenant deployment (Faza 1 SaaS), Doctrine TenantFilter + Postgres RLS + Voters + audit musi być battle-tested. Retrofit po pierwszym leak'u = reputation damage.

**Rozważane opcje:**
- **(a)** Minimal RBAC w MVP + refactor w Fazie 1 — odrzucony. Koszt refactoru *„na żywym organizmie"* (przed pierwszym pilotem ~60 endpointów do retrofitu + breaking changes API + downtime migracji + przepisanie testów) szacowany na 80-120h, plus ryzyko regresji w produkcji.
- **(b)** Hybrid (proper schema od dnia 1 + 5 templates immutable + role builder Faza 1) — odrzucony jako *„suboptymalny middle"*. Schema cost = pełen schema cost, ale UX cost = brak custom role builder przez 8-12 tygodni Fazy 1 = pilot musi się zmieścić w 5 templates, co nie pasuje do realiów polskiego rynku (Marcin: *„Marketing PL ma inne uprawnienia niż Marketing EN; Translator chińskiego nie powinien widzieć ceny zakupu"*).
- **(c)** Pełen RBAC w MVP-Alpha — wybrany. Wszystkie 10 ról (Super Admin + 9 tenant) + builder + field-level + workflow + per-locale/channel scope + per-attribute 3-state + Cmd+K integration + Super Admin operator panel + break-glass + audit `permission_check_result`.

**Decyzja:** Opcja (c). Pełen RBAC w MVP-Alpha zgodnie ze scope [PRD-PIM-rbac §3.2 macierz uprawnień](PRD/PRD-PIM-rbac.md) jako autoritative source of truth. 10 ról (1 platform-level: Super Admin; 9 tenant-level: Owner, Admin, Editor, Reviewer, Marketing, Translator, Integrator, Viewer, Auditor) + custom role builder + per-attribute permissions (3-state restricted/view/edit z resolution order attribute → group → role default — PRD §3.5) + per-locale + per-channel scope + workflow-state policy (Symfony Workflow integration) + ownership check (own vs all) + field-level serializer filtering + Super Admin cross-tenant bypass z audit + break-glass CLI. Implementacja w 7 phase'ach (89 ticketów, milestones #9-#15, ~330-445h).

**Uzasadnienie:**
Asymetria zysków vs koszt jest klasyczna i sprawdzona w ADR-003 (multi-tenant ready, single-tenant deployed) i ADR-009 (generic ObjectType z built-in Product/Category/Asset): *„zaprojektować jak na enterprise, deployować jak na MVP"*. Cross-cutting concerns aplikowane od dnia 1 nie mają technical debt; aplikowane post-factum mają debt × N gdzie N = liczba endpointów / komponentów dotkniętych przez retrofit. Dla RBAC w MVP-Alpha N ≈ 60 endpointów + ~40 widoków admin UI = retrofit cost wykładniczy. Plus: zero refactor risk, zero breaking changes dla integratorów, zero downtime, zero re-write testów. Cena: +330-445h jednorazowo w MVP-Alpha (8-12 tygodni Marcin solo dev tempo per PRD §7).

**Konsekwencje:**
- **Koszt:** **+330-445h** rozbite na 7 phase'ów (PRD §7 v2; backlog: `08-rbac-tickets-phase-1.md` do `14-rbac-tickets-phase-7.md`, 89 ticketów). v1 zakładał 0h w MVP — różnica to świadomie zaakceptowany koszt by uniknąć Fazy 1 refactor.
- **Schema:** 10 nowych tabel (`super_admins`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `api_tokens`, `invitations`, `user_tenant_memberships`, `sso_providers`) + delta `attributes.integration_visible BOOLEAN` + delta `role_attribute_permissions` + delta `role_attribute_group_permissions` + delta `audit_logs.permission_check_result/special_flags`. Migracje w Phase 1 (`#643`, `#644`).
- **Egzekucja patternu od dnia 1:** każdy nowy endpoint musi mieć `#[RequiresPermission]` attribute (egzekwowane przez custom PHPStan rule — `#649`). Każdy nowy frontend komponent musi być wrapped w `<PermissionGate>` lub `useCanI()` hook (egzekwowane przez code review + ewentualnie ESLint custom rule). Brak grace period.
- **Faza 6 (Refactor + Hardening, milestone #14):** retrofit `#[RequiresPermission]` do ~60 endpointów stworzonych pre-RBAC (epiki 0.1–0.6) — ticket #714–#717. Skala refactoru ograniczona bo Voters + policy infrastructure już istnieje z Phase 3.
- **Faza 7 (Pentest + Launch, milestone #15):** obligatoryjny manual red-team Marcina (15-point checklist, `#723`) + opcjonalny external pentest (`#724–#726`) przed soft launch z design partners (`#728`). Bez red-team pass — no launch.
- **CLAUDE.md update:** sekcja *„Priorytety implementacyjne"* musi zaktualizować ADR-013 z Faza 1 → MVP-Alpha (Phase 1-3 z 7 phase'ów RBAC) + Phase 4-7 jako wymóg pre-launch (ticket #642 / RBAC-P1-003).
- **Wszystkie wcześniejsze *„Faza 1 candidate"*** w `Project Plan/UI/feature-list-advanced.md` i `Project Plan/UI/feature-exports.md` dotykające RBAC → przeniesione do MVP (PRD §6.1).
- **Cross-tenant isolation test suite** jako obligatoryjny CI gate od Phase 1 (`#648` testcontainers Postgres) — bez 10+ scenarios cross-tenant pass nie merge'ujemy do main (Layer 3 z `07-rbac-implementation-plan.md` §2).

**Referencje:**
- [`PRD/PRD-PIM-rbac.md`](PRD/PRD-PIM-rbac.md) (v2.1, 2026-05-16) — autoritative scope: §3.2 macierz uprawnień (10 ról × ~50 permissions), §3.5 3-state attribute permissions resolution order, §6.1 *„co świadomie zawiera MVP vs v1 Faza 1 cuts"*, §7 estymacja 330-445h.
- [`07-rbac-implementation-plan.md`](07-rbac-implementation-plan.md) (v3.1, 2026-05-16) — strategia operacyjna: 7 phase'ów, testing strategy (4 layers), security tooling (Infection / Semgrep / OWASP ZAP / TruffleHog), red-team checklist.
- Backlog: `08-rbac-tickets-phase-1.md` (Foundation, 10 tickets) → `14-rbac-tickets-phase-7.md` (Pentest + Launch, 6 tickets). 89 ticketów, milestones #9–#15.
- ADR-003 (multi-tenant ready, single-tenant deployed) — analogiczna asymetria *„ready od dnia 1 / deploy minimalnie"*.
- ADR-009 (generic ObjectType z built-in Product/Category/Asset) — sprawdzony pattern *„infrastruktura full-scope, UX zoptymalizowany pod MVP"*.
- ADR-006 (hybrid attribute model) — RBAC field-level filtering operuje na `object_values JSONB` zgodnie z ADR-006; per-attribute 3-state permissions parametryzują serialization context.

### ADR-014: Model dystrybucji atrybutów (primary category overlay) + relacje obiekt↔obiekt

**Status:** Zaakceptowany (2026-05-23)

**Rewiduje:** ADR-012 w trzech punktach (patrz Konsekwencje). Reszta ADR-012 (AttributeGroup jako first-class entity, M:N junctions, `EffectiveAttributeGroupResolver`) pozostaje w mocy.

**Kontekst:**
Przy ~60% ukończenia systemu, w sesji burzy mózgów (2026-05-23) wykryto cztery nieścisłości w module Modelowanie, które okazały się **jednym problemem architektonicznym** — niespójnym modelem dystrybucji atrybutów:

1. **Marka jako built-in ObjectType bez uzasadnienia.** ADR-012 (Konsekwencje, *„Brand jako 4-ty built-in ObjectType"*) wprowadził Brand do puli built-in obok Product/Category/Asset. W praktyce: Brand nie renderuje się w widoku edycji Produktu (brak wpięcia jako atrybut ani relacja), a built-in status nie ma uzasadnienia — marka jest decyzją biznesową tenanta (jeden chce `select`, inny osobny ObjectType).
2. **Brak modelu relacji obiekt↔obiekt.** Tenant tworzy ObjectType (np. Up-Selling, Marka) i chce powiązać go z innym obiektem (Pimcore-style). Brak UX, brak logiki, brak typu atrybutu obsługującego referencję.
3. **ObjectType-Category ma zdefiniowane atrybuty, ale formularz instancji ich nie renderuje** (objaw zgłoszony jako #3-#28). Root cause: form renderer szuka atrybutów *przez kategorię obiektu*, a Category nie ma „swojej kategorii" → atrybuty bazowe ObjectType są ignorowane.
4. **Niejasność czy każdy ObjectType jest kategoryzowany.** Select „Object Type" w `/modeling/categories` zakłada że każdy ObjectType wiąże się z kategorią. Nie ma to sensu dla ObjectType nie-kategoryzowanych (Brand, Asset).

Root cause: system zahardkodował **kategorię jako jedyny mechanizm dystrybucji atrybutów**. Działa dla Produktu (kategoryzowany), łamie się dla wszystkiego innego. ADR-012 model (`EffectiveAttributeGroupResolver` z 4 źródłami) był poprawny kierunkowo, ale implementacja go nie realizuje, a sam ADR-012 nie rozróżniał kategorii primary vs secondary i nie adresował relacji.

**Rozważane opcje (model dystrybucji atrybutów):**
- **(X) Czysty ObjectType-driven (Pimcore Class).** ObjectType definiuje wszystkie atrybuty, kategoria = wyłącznie drzewo klasyfikacji, zero wpływu na atrybuty. Odrzucony — eliminuje *„różne atrybuty dla różnych grup produktów"* (telewizor `przekątna` vs pralka `pojemność_bębna`), co jest realnym wymaganiem PIM mid-market.
- **(Y) Hybrid: ObjectType base + primary category overlay (cumulative po drzewie).** ObjectType daje bazę atrybutów (zawsze, każda instancja). ObjectType z `is_categorizable=true` ma dodatkowo **jedną kategorię główną (primary)**, która przez swoją ścieżkę w drzewie kategorii dodaje kontekstowe atrybuty kumulatywnie (root→leaf). Pozostałe kategorie (secondary, N) służą wyłącznie klasyfikacji. **Wybrany.**
- **(Z) Family-based (Akeneo).** Osobny byt `Family` jako attribute-driver, Category czysto klasyfikacyjna. Odrzucony jako zbyt ciężki — dodatkowy byt domenowy, dodatkowy CRUD, dodatkowy concept dla operatora. Primary category osiąga 90% wartości Family bez nowego bytu.

**Decyzja:** Opcja (Y) + sześć doprecyzowań:

1. **Capability flags na `ObjectType`:** `expose_to_main_menu` (czy ObjectType ma pozycję w sidebar — istniejąca kolumna z VIEW-08 / #427, reused; w mini-specu i wcześniejszych draftach pojawiała się jako `show_in_main_menu`) i `is_categorizable` (czy instancje mają kategorię główną przydzielającą atrybuty — dodane w MOD-01 / #893). Brak flagi `is_relation_target` — **każdy ObjectType może być celem relacji domyślnie**.
2. **Primary + secondary categories.** Instancja kategoryzowalnego ObjectType ma dokładnie 1 kategorię główną (`is_primary=true` w junction obiekt↔kategoria) + N kategorii dodatkowych. Tylko primary uczestniczy w dystrybucji atrybutów; secondary = klasyfikacja/nawigacja.
3. **Cumulative resolution po ścieżce drzewa.** Produkt z primary category liść `Elektronika > RTV > Telewizory` dostaje sumę grup atrybutów przypisanych do każdego węzła ścieżki (`Elektronika` + `RTV` + `Telewizory`). Wspólne atrybuty definiowane wysoko w drzewie, specyficzne nisko. `EffectiveAttributeGroupResolver` (ADR-012) zachowany, ale parametryzowany **primary category path**, nie zbiorem wszystkich kategorii obiektu.
4. **Relacja = typ atrybutu `relation`.** Atrybut typu `relation` z konfiguracją: `target_object_type` (jeden lub wiele), `cardinality` (one/many), `advanced` (relacja z metadanymi — własne pola na powiązaniu). Reverse relations auto-generowane (obiekt-target widzi read-only sekcję *„powiązania zwrotne"*). Brak osobnego bytu „Association" — relacja mieści się w istniejącym modelu atrybutów. Typ `asset` pozostaje osobny (specjalizowany UX: DAM picker, miniaturka, kadrowanie) — nie ujednolicany z `relation`.
5. **Kody atrybutów globalnie unikalne w obrębie ObjectType.** Atrybut jest albo bazowy (ObjectType), albo kontekstowy (kategoria) — nigdy oba. Walidacja w Modelowaniu blokuje duplikat kodu. Konwencja nazewnicza dla atrybutów kontekstowych: sufiks kategorii (`opis_telewizory`, `opis_buty`) — zapobiega kolizjom i czyni model czytelnym.
6. **Brand NIE jest built-in.** Built-in ObjectType zostają wyłącznie `Product`, `Category`, `Asset` (zgodnie z pierwotnym ADR-009). Brand → custom ObjectType lub atrybut `select`, decyzja tenanta.

**Uzasadnienie:**
Opcja (Y) zachowuje killer feature ADR-012 (dziedziczenie grup atrybutów po drzewie kategorii — czego Akeneo/Pimcore nie mają natywnie), ale naprawia jego trzy luki: brak rozróżnienia primary/secondary (produkt w wielu kategoriach miał ambiguous attribute set), brak modelu relacji, błędny built-in Brand. Primary category jako attribute-driver świadomie *„miesza dwie role"* kategorii (klasyfikacja + dystrybucja atrybutów) — to zaakceptowany trade-off: prostota (brak bytu Family) kosztem czystości (reorganizacja drzewa ma side-effect na atrybuty produktów; mityguje to ostrzeżenie UI + orphaned values handling). Relacja jako typ atrybutu (nie osobny byt Association) trzyma spójność z ADR-006/009 — wszystko jest atrybutem na `object_values JSONB`.

**Konsekwencje:**

*Rewizja ADR-012:*
- **REVERT „Brand jako 4-ty built-in ObjectType"** (ADR-012 Konsekwencje, linia o `#UI-08.2`). Built-in = Product/Category/Asset. Rozszerzenie `object_types` o `is_built_in/code_immutable/deletable/icon/color` zostaje, ale Brand nie jest seedowany jako built-in.
- **`category_attribute_groups` (ADR-012)** — semantyka doprecyzowana: junction działa tylko dla **primary category** obiektu, nie dla zbioru wszystkich kategorii. `EffectiveAttributeGroupResolver` źródło (3) *„dziedziczone po drzewie root→leaf"* parametryzowane primary category path.
- **`EffectiveAttributeGroupResolver` źródło (2) *„globalne dla ObjectType"*** musi zwracać atrybuty bazowe **dla każdego ObjectType, niezależnie od kategoryzacji** — to naprawia objaw #3-#28 (Category-jako-ObjectType renderuje swoje atrybuty bazowe).

*Nowe elementy schematu:*
- `object_types` + kolumna `is_categorizable BOOLEAN` (MOD-01 / #893). `expose_to_main_menu BOOLEAN` (ekwiwalent semantyczny `show_in_main_menu`) już istniał od VIEW-08 / #427 — reused, brak rename.
- Junction obiekt↔kategoria (`object_categories`) + kolumna `is_primary BOOLEAN`; partial unique index gwarantuje dokładnie 1 primary per obiekt kategoryzowalny. *(Już istnieje od PCAT-01 / `Version20260510221123` — MOD-01 nie dotyka schematu junction'a.)*
- `attributes` + obsługa typu `relation`: kolumny `relation_target_object_type_ids JSONB`, `relation_cardinality VARCHAR(8) CHECK IN ('one','many')`, `relation_advanced BOOLEAN` (MOD-01 / #893). `AttributeType::Relation` enum case już istniał — brak migracji enum.
- Tabela powiązań `object_relations` (`source_object_id`, `target_object_id`, `attribute_id`, `position`, `metadata JSONB` dla advanced) — **zastępuje** `object_associations` z ADR-009 (MOD-02 / #894). Hardcoded enum typów ADR-009 (`cross_sell`, `up_sell`, `related`, `alternative`, `accessory`) był MVP-placeholderem; ADR-014 go generalizuje: każdy typ asocjacji staje się seedowanym built-in atrybutem typu `relation` na ObjectType Product. Migracja: 5 typów → 5 atrybutów `relation`, przepisanie wierszy `object_associations.type` → `object_relations.attribute_id`, DROP `object_associations`.
- Orphaned values: wartości atrybutu który zniknął z modelu (zmiana primary category) **pozostają w `object_values`** ukryte i nieedytowalne; powrót do kategorii przywraca widoczność.

*Naprawa objawów:*
- #1 (Marka) — usunięcie Brand z built-in + migracja istniejącej „Marki" (custom ObjectType lub atrybut, per stan tenanta).
- #2 (relacje) — typ atrybutu `relation` + zakładka „Powiązania" (AttributeGroup) + reverse relations.
- #3-#28 (Category nie renderuje atrybutów) — fix wiring `EffectiveAttributeGroupResolver` źródło (2).
- #4 (każdy ObjectType kategoryzowany) — flaga `is_categorizable`; `/modeling/categories` select „Object Type" filtruje tylko do ObjectType z `is_categorizable=true`.

*Sequencing:* Rewizja Modelowania jest blokująca dla dalszych prac w epiku UI-08. Capability flags + primary category + relacje wchodzą przed dalszym CRUD-em obiektów. Szczegółowy kontrakt: `Project Plan/UI/feature-modeling-data-model.md`.

**Referencje:**
- `Project Plan/UI/feature-modeling-data-model.md` — mini-spec implementacyjny (model, schema delta, UX, migracja, API, user stories, estymacja).
- `Project Plan/UI/feature-modeling-relations-ux-tickets.md` — batch ticketów MODR-01..11 (#923..#933) rozstrzygający Opcję 2 §3.5.
- ADR-012 (AttributeGroup first-class) — ADR-014 rewiduje 3 punkty, zachowuje resztę.
- ADR-009 (generic ObjectType) — ADR-014 respektuje; built-in = Product/Category/Asset zgodnie z pierwotnym ADR-009.
- ADR-006 (hybrid attribute model) — relacja jako typ atrybutu operuje na `object_values JSONB` zgodnie z ADR-006.
- `Project Plan/UI/epik-08-modelowanie.md` — epik UI-08, do aktualizacji o capability flags + relacje + primary category.

**Uzupełnienie 2026-05-24 (Opcja 2, batch MODR-01..11):** Placement atrybutów na karcie obiektu rozstrzygnięty przez `display_mode` na junction `object_type_attribute_groups` (`'tab'|'stacked'`, default `'tab'`, MODR-01 #923). Relacja jest **zwykłym atrybutem** — placement po grupie, widget po typie. Multimedia i Powiązania to seedowane `is_system_group=true` AttributeGroups (MODR-02 #924) — niczym nieuprzywilejowane wobec innych grup poza built-in flagą. Audit pozostaje `display_mode='stacked'` po data-migration (MODR-03 #925). Zakładka „Powiązania" widoczna gdy grupa ma atrybuty LUB obiekt ma reverse links (MODR-06 #928 → `GET /api/objects/{id}/relations/reverse/count`). Rich preview card + inline expand/edit operują przez `POST /api/objects/summaries` (batch fetch z `version`) i `PATCH /api/{kind-path}/{id}` z `expectedVersion` na `objects.version` (Doctrine `@Version`, MODR-08/MODR-10 #930/#932). Świadomie odrzucone alternatywy udokumentowane w `feature-modeling-data-model.md` §12.1.

**Uzupełnienie 2026-05-28 (Option Y, batch MODRC-01..05 — supersedes MODR-02/06/07):** Seedowana grupa „Powiązania" wycofana razem z audit (#1074 / Version20260527100000 → #1080 / Version20260528100000). 5 built-in atrybutów typu `relation` (`cross_sell`, `up_sell`, `related`, `alternative`, `accessory`) pozostaje seedowanych na Product ObjectType **jako loose `ObjectTypeAttribute`** (bez grupy). Operator świadomie tworzy grupę dowolnego code'u i `display_mode`, jeśli chce zakładkę lub sekcję inline. Forward Relations tab detection w `product-detail-page.tsx` przechodzi z `code === 'relations'` na detection po typie atrybutu (`g.attributes.some(a => a.type === 'relation')`). Reverse relations renderują się w **systemowej sekcji „Powiązania zwrotne"** (MODRC-03 #1082) — jedyna wirtualna zakładka, auto-generowana gdy obiekt jest celem. Inline relation editor w `attr-row.tsx` (MODRC-05 #1084) zapewnia parytę z innymi atrybutami niezależnie od `display_mode` grupy. Świadomie odrzucone alternatywy (flaga `has_relations`, magiczna widoczność, seed grupy) w `feature-modeling-data-model.md` §12.0.

### ADR-015: Drzewa kategorii per ObjectType (rewizja modelu kategorii z ADR-014)

**Status:** Accepted (2026-05-30).

**Kontekst.** ADR-014 zakładał JEDNO współdzielone drzewo kategorii per tenant (`objects` kind='category', ltree `path`); `objects.object_type_id` wskazywał wbudowany typ „Category", a dystrybucja grup atrybutów szła przez `category_attribute_groups.target_object_type_id`. Select „Object Type" na `/modeling/categories` zmieniał tylko target dystrybucji, nie samo drzewo, i obsługiwał wyłącznie built-in categorizable OT (kontrakt API po `kind`). Operator potrzebuje, by każdy kategoryzowalny ObjectType miał **własne, niezależne drzewo kategorii**, wybierane selektorem.

**Decyzja.**
- Drzewo kategorii jest **partycjonowane per docelowy ObjectType**. Nowa kolumna `objects.category_target_object_type_id` (UUID, NULL dla nie-kategorii, ustawiana na create dla `kind='category'`, FK→`object_types` ON DELETE RESTRICT) wyznacza, do którego drzewa należy kategoria.
- Unikalność kodu kategorii jest **per-drzewo**: `objects_tenant_kind_code_uniq` zastąpione dwoma partial unique indexami — `(tenant_id, kind, code) WHERE kind <> 'category'` oraz `(tenant_id, category_target_object_type_id, code) WHERE kind = 'category'`. Ten sam `code` (np. „elektronika") może istnieć w wielu drzewach.
- **`is_categorizable` bramkuje** posiadanie drzewa: semantyka flagi = „instancje tego ObjectType mogą być przypisane do drzewa (i dziedziczyć atrybuty z kategorii)". Select listuje wszystkie OT z `is_categorizable=true` (built-in **i custom**); kontrakt API kategorii przechodzi z `objectTypeKind` (enum) na `objectTypeId` (UUID).
- Instancja OT=X może być przypisana tylko do kategorii z drzewa X (walidacja w handlerze przypisania → 422). Dziecko-kategoria musi należeć do tego samego drzewa co rodzic.
- `category_attribute_groups.target_object_type_id` dla danej kategorii równy jej `category_target_object_type_id` (target wynika z drzewa); junction zostaje dla wielu grup per kategoria.

**Migracja (Version20260530120000, expand-contract).** Krok expand: dodanie kolumny + FK + index, backfill istniejących kategorii do built-in Product OT per tenant (Product był jedynym categorizable; stare drzewo obsługiwało Product), swap unique index. Pozostałe ObjectType startują **bez drzewa** (puste) — drzewo powstaje gdy operator włączy `is_categorizable` i utworzy pierwszą kategorię. Built-in „Category" OT (`objects.object_type_id`) bez zmian.

**Konsekwencje.** (+) Czytelny mental model „osobne drzewa, wybierz które edytujesz"; custom OT w pełni wspierane. (−) Tracimy współdzielenie jednej kategorii przez wiele OT (dziś nieużywane). (−) Wysoki blast radius — zmiana unikalności + scope ltree dotyka każdego odczytu kategorii; mitygacja: expand-contract + partial unique + pełen ApiTestCase/cross-tenant/Playwright per faza (PR-A schema, PR-B API+resolver, PR-C FE).

**Alternatywy odrzucone:** (a) status quo „jedno drzewo + multi-target" — nie spełnia żądania; (b) osobny category-kind ObjectType per drzewo — mnoży byty infrastrukturalne; (c) zachowanie współdzielenia kategorii między OT — niewykorzystane, komplikuje UX i unikalność ścieżek.

**Referencje:** ADR-014 (rewidowany w zakresie scope drzewa; reszta — primary category overlay, cumulative resolution, EffectiveAttributeGroupResolver — w mocy). Plan implementacji: PR-A (#1118) schema+encja+create, PR-B API+resolver+walidacja przypisania, PR-C FE (dropdown all-categorizable + objectTypeId, list/new/show per drzewo, reword etykiety `is_categorizable` → „czy obiekty mogą być przypisane do drzewa").

### ADR-0021: Asset i Category jako closed system kinds (amend ADR-009)

**Status:** Accepted (2026-06-23).

**Kontekst.** ADR-009 traktował `Product`, `Category`, `Asset` jako równorzędne built-in ObjectType, każdy w pełni attribute-modelable (własne `object_type_attributes`). Demo seeder przypinał do `asset` atrybuty `name`/`alt_text`/`caption`, a do `category` — `name`/`seo_title`/`seo_description`/`main_image`, „na dowód", że Category to first-class typ z własną schemą. W praktyce produkt poszedł w innym kierunku: assety edytują wyłącznie code/tags + metadane pliku (dedykowany `AssetEditDialog` + DAM), kategorie mają własny formularz (path/hierarchia), a UI Modelowania **przekierowuje** `kind ∈ {asset, category}` z detalu typu na listę — więc przypiętych atrybutów **nie da się odpiąć przez UI**. To pozostawiło martwy stan: atrybuty zablokowane do usunięcia („attached to 1 object type"), choć żaden user-facing ekran ich nie edytuje.

**Decyzja.** `Asset` i `Category` to **closed system kinds**: pozostają first-class ObjectType (built-in, sugar paths `/api/assets`, `/api/categories`), ale **nie są attribute-modelable** — zero wierszy w `object_type_attributes`, zero grup atrybutów. Ich pola wewnętrzne są platform-managed: `name` jako display label (FK `label_attribute_id`, nie junction), asset code/tags/metadane pliku przez Asset BC, category path/hierarchia przez ltree. Tylko `Product` (i przyszłe `Custom` kindy) są attribute-modelable. Źródło prawdy: `ObjectKind::isAttributeModelable()` (false dla Asset/Category).

**Egzekwowanie.** (a) `AttachObjectTypeAttributeController` (attach + bulk) i `AttachObjectTypeAttributeGroupController` zwracają 422 dla closed kinds; (b) UI Modelowania już przekierowuje detal asset/category (customization niedostępna); (c) `DemoCatalogSeeder` przypina atrybuty wyłącznie do product; (d) migracja `Version20260623120000` czyści istniejące dane (detach + drop osieroconych `alt_text`/`caption`/`seo_title`/`seo_description`; `name`/`main_image` zostają — używa product).

**Konsekwencje.** (+) Spójność: martwy stan zniknął, model asset/category odpowiada realnemu UX. (+) Atrybuty wyłącznie tam, gdzie są edytowalne. (−) Cofa fragment ADR-009 (Category jako pełny attribute-modelable typ) — gdyby przyszły pilot wymagał user-defined pól na kategorii/asset, trzeba odblokować per-junction (flaga `is_system` na `ObjectTypeAttribute` + widoczne-ale-nieedytowalne renderowanie). (−) `name`/`main_image` zostają jako atrybuty product mimo czyszczenia (świadomie — to ich właściwy dom).

**Referencje:** ADR-009 (rewidowany w zakresie modelability asset/category; generic ObjectType, sugar paths, is_built_in — w mocy). ADR-012 (grupy atrybutów — closed kinds ich nie używają).

### ADR-0016, ADR-0017, ADR-0018, ADR-0019 (per-file MADR)

Decyzje ADR-0016 (format kluczy API + Argon2id), ADR-0017 (BYOK AES-256-GCM) i ADR-0018 (ChannelPublicationProfile — per-channel attribute/locale allow-list, `?publication=<channel>` oddzielne od `?channel=`) są zarchiwizowane w plikach per-file MADR w `docs/adr/`. Streszczenie ADR-0018:

- Encja `ChannelPublicationProfile` w Channel BC (nie Catalog/Export); `published_attribute_codes=NULL` = publish-all (default).
- Param `?publication=<channel>` dedykowany dla filtrowania atrybutów do profilu — NIE przeciążamy `?channel=` (który nadal znaczy overlay wartości).
- Cross-BC dostęp przez `Channel\Contracts\ChannelPublicationResolverInterface` (Deptrac-safe).
- Bare UUID refs (`channel_id`, `object_type_id`) zgodnie z ADR-015.

Streszczenie ADR-0019 (Import v2 — kontrakty silnika, epik IMP2 #1460–#1498): tryby importu `CREATE/UPDATE/UPSERT` (default upsert) z matchem po SKU lub atrybucie `identifier` per profil; semantyka komórek „pusta = nie ruszaj" + `clear_if_empty` opt-in per kolumna; kanon shape'ów JSONB `object_values.value` per typ (z migracją legacy `{value}`-selectów w #1464); gramatyka kolumn `code[.locale][.channel]` z rejestrami tenanta i precedencją locale; `import_session_id` wyłącznie jako marker created-by (rollback upsertów przez undo-log); mapping v2 kluczowany indeksem kolumny; transport Messenger `import` z workerem w dev i prod; reguły normalizacji golden testu (wersjonowane). Pełny plik: `docs/adr/0019-import-v2-engine-contracts.md`.

Streszczenie ADR-0020 (powierzchnia API: API Platform + custom controllers, audyt AUD-043/054 #1600): audyt wykazał odwrócenie reguły „wszystko przez API Platform" bez ADR — ~228+ tras `/api/*` w routerze vs 31 ścieżek w eksporcie OpenAPI; 117 plików `#[Route]` vs 2 `#[ApiResource]`. Decyzja: świadoma hybryda — API Platform dla zasobów (CatalogObject, Attribute, Channel, Asset, ApiKey…), custom `#[Route]` dla operacji proceduralnych (auth, MFA, reset, invitation, bulk-edit, import/export lifecycle, asset binary, super-admin, agent — CQRS, ADR-0012); oba to publiczny kontrakt. Aby OpenAPI był kompletny/uczciwy (nie 31 z 228), `App\Shared\OpenApi\CustomRouteOpenApiFactory` (dekorator `api_platform.openapi.factory`) automatycznie i deterministycznie dorzuca custom trasy `/api/*` do eksportu (tagi, security bearer, path params, `200`/`401`, extension `x-pim-source: custom-route`) — preferowane nad ryzykownym retrofitem 117 kontrolerów na ApiResource. CLAUDE.md pkt 3 skorygowany do stanu faktycznego. Dług: pełne schematy request/response custom operacji on-demand. Pełny plik: `docs/adr/0020-openapi-custom-route-documentation.md`.

Streszczenie ADR-0021 (frontend data-fetching): zarchiwizowane w `docs/adr/0021-frontend-data-fetching.md`.

Streszczenie ADR-0023 (Konfigurator XML — umiejscowienie, model serializacji i autoryzacja feedu, epik XMLF): operator chce feedów produktowych XML (Google Shopping / Ceneo / Meta / custom B2B) + XML jako formatu eksportu ad-hoc. Silnik Export istnieje (`ExportBuilder` → `Generator<array<string,string>>`, writery CSV/XLSX flat), ale brak serializacji zagnieżdżonego XML i modelu feedu (URL/harmonogram/cache/token). Decyzja (7 punktów): (1) silnik XML w `src/Export/` (NIE nowy BC), feedy w pod-obszarze `src/Export/Feed/`; (2) nowy **asocjacyjny** kontrakt `ItemWriter` zamiast rozszerzania **pozycyjnego** `RowWriter` — oba konsumują ten sam `Generator<array<string,string>>` z `ExportBuilder`; `GenericXmlWriter` (ad-hoc) i `XmlFeedWriter` (feed, descriptor-driven) implementują `ItemWriter`; (3) reuse `ExportBuilder` jako źródła itemów (brak duplikacji selekcji/odczytu), cross-BC do Channel/Catalog tylko przez `*\Contracts\*`, rdzeń Export przez seam `Export/Contracts` (Deptrac `Export_Feed_Internals` → `Export_Contracts`); (4) feed = deklaratywny descriptor JSONB + zamknięta lista transformacji (default/price/number/date/enum_map/template/strip_html/truncate), 3 predef jako seedy `is_built_in` klonowane do `FeedProfile.descriptor`, `FeedDescriptor` VO = jedyne źródło prawdy kształtu; (5) delivery **pull cache-and-serve** — regeneracja (cron/manual/first_publish) → MinIO → publiczny URL serwuje **cache** (nigdy nie generuje na żądanie), `ETag`/`304`/gzip, push do FTP/S3 = hook; (6) autoryzacja **token-in-URL** (≥128-bit, hash wzorca `ApiKey` Argon2id, pokazany raz + rotate/revoke, token ≠ klucz tenanta, read-only per feed) + opcjonalny HTTP Basic (`AesGcmEncryptionService`), publiczny endpoint przez `PUBLIC_ACCESS`, tenant z tokenu → RLS, cross-tenant miss = 404; (7) scope przez publication profile (ADR-0018) — reuse `?publication=<channel>` allow-listy + `column_aliases` jako zalążek mapowania. Hooki §7 (import XML + XXE, push FTP/S3, dowolne transformacje, Allegro predef, non-product feeds, AI-mapping, adaptive scheduling, jitter tenantów) odłożone. Backlog: `Project Plan/feature-konfigurator-xml-tickets.md` (epik XMLF, 38 ticketów, milestone'y M0–M6). Pełny plik: `docs/adr/0023-konfigurator-xml-placement.md`.

Streszczenie ADR-0022 (granica Konsument/Producent + umiejscowienie generycznego konektora API, epik APIC): operator chce uniwersalnego Konfiguratora API (mapowanie PIM ↔ dowolne REST/JSON API, oba kierunki, harmonogram). Dwa oblicza, łatwe do pomylenia: **producent** (`src/ApiConfigurator/`, cudzy system woła API PIM — PIM serwer, wydaje klucze hashowane Argon2id) i **konsument** (greenfield w `src/Integration/`, PIM woła cudze API — PIM klient, szyfruje cudze credentiale odwracalnie). Decyzja: (1) osobne bounded contexts, wspólny shell UI; (2) generyczny konektor w `src/Integration/Generic/` (NIE w `ApiConfigurator`), wystawia `Integration/Generic_Contracts`, cross-BC tylko przez Contracts (Deptrac); (3) konfigurator = warstwa transport+mapowanie+harmonogram NAD Import/Export — inbound deleguje upsert do `ValueWriteCore`/`ObjectResolver` (`provenance=Integration`), outbound reużywa `ExportBuilder`/`ColumnResolver`/`ValueSerializer`, brak duplikacji zapisu/odczytu; (4) dwa mechanizmy sekretów świadomie współistnieją (hash producent / szyfr AES-256-GCM konsument); (5) mapowanie 1:1 w MVP (ostrzeżenie przy mismatch typu, transform engine = hook `ValueTransformerInterface`); (6) konflikt bidirectional LWW + `pim_wins`/`remote_wins` per binding, anti-loop przez provenance; (7) bezpieczeństwo: `GenericRestClient` SSRF-safe (`NoPrivateNetworkHttpClient`+`SsrfGuard` per redirect), walidacja descriptora, TenantScoped+RLS, rate-limit per Connection/tenant, audyt w `SyncRunLog`. Hooki §7 (transform engine, GraphQL/SOAP, full OAuth2, inbound webhooks, AI auto-mapping, adaptive polling, source-priority conflict, connector-pack marketplace) odłożone. Backlog: `Project Plan/feature-api-configurator-tickets.md` (epik APIC, 48 MVP + 8 hooków). Pełny plik: `docs/adr/0022-api-configurator-consumer-producer-boundary.md`.

Streszczenie ADR-0024 (Agent layer — usuwalny bounded context, rejestr narzędzi, single approval gate, epik 0.7): agent AI wykonujący operacje na katalogu i schemacie z approvalem operatora to differentiator produktu, ale niesie trzy twarde wymogi: wydzielalność open-core (PIM open source BEZ agenta, agent proprietary), zgodność z Deptrac (agent nie sięga internals innych BC) i jeden punkt zatwierdzenia. Decyzja (3 rozstrzygnięcia): (a) **`src/Agent/` jako w pełni usuwalny BC** — zero zależności core→Agent (fail-closed: warstwa `Agent` nieobecna w cudzych rulesetach Deptrac), huki współdzielone (`pending_changes`, `Provenance::Agent`, `EntityChanged`) żyją w core niezależnie od agenta, test wydzielalności (`rm -rf src/Agent` + FE flag off → build + core suite zielone) jest bramką DoD i jobem CI; (b) **rejestr narzędzi + kontrakt „narzędzie = port `*\Contracts\*` w BC-gospodarzu + cienki adapter w `src/Agent/`"** — każde narzędzie deklaruje name/description/schemat/permission/kind, model dostaje wyłącznie narzędzia RBAC-dozwolone dla zalogowanego usera, każdy tool-call przechodzi voter jak ręczna akcja (twarda granica anty-prompt-injection), wybór modelu per kind (`schema` → Opus, reszta → Sonnet), klucz z `ByokKeyManager` (BYOK, ADR-0017); dodanie narzędzia = port + adapter + wpis w rejestrze, zero zmian w pętli; (c) **single approval gate przez `pending_changes`** — agent materializuje plan jako konkretne diffy (plan == diff, brak dwóch gate'ów), commit po akcepcie człowieka przez istniejący bulk-path (`BatchValueWriter`, `Provenance::Agent`), rollback przez istniejący undo-log (`BulkRollbackHandler`), MVP all-or-nothing, 1 aktywny run/user. Huki PRD (`pending_changes`/`EntityChanged`/`Provenance::Agent`) NIE istniały w kodzie — dorabiają je tickety M0 (P0-03/04/05). Backlog: `Project Plan/feature-agent-tickets.md` (epik 0.7, 49 ticketów, milestone'y M0–M9). Pełny plik: `docs/adr/0024-agent-removable-bc-and-tool-registry.md`.

Streszczenie ADR-0025 (Walidacja krzyżowa pól + conditional required na poziomie ObjectType, epik DP, #2037): silnik walidacji per-atrybut (`Attribute.validation_rules` + `TypeValidator/*`) nie mieści reguł odnoszących się do wielu pól naraz (`weight_net ≤ weight_gross`, „`max_sd_card_gb` wymagane gdy `expandable_storage = true`"). Decyzja: reguły cross-field żyją na `ObjectType` (`object_types.validation_rules` JSONB, analogicznie do `completeness_rules`) — typy `compare` (CompareRule) i `require_when` (RequireWhenRule reużywa semantykę VisibleWhenRule z UI-08.8). `CrossFieldRulesValidator` egzekwuje je w OBU write-pathach (single-edit i bulk przez ValueWriteCore), upserter 3-fazowy zapewnia atomowe 422-przed-zapisem. Builder reguł na detail ObjectType. Pełny plik: `docs/adr/0025-object-type-cross-field-validation.md`.

Streszczenie ADR-0027 (Katalogi PDF — renderer za portem, umiejscowienie silnika i model dostarczania, epik CPDF, #2282): operator chce funkcji web-to-print — klient bez developera generuje z danych PIM firmowane PDF-y (karta produktu / katalog / cennik), zawsze aktualne, do pobrania i udostępnienia stabilnym URL-em. Silnik Export + wzorzec dostarczania `Export/Feed` (cache-and-serve + token URL, ADR-0023) istnieją, ale brak renderowania PDF i modelu dokumentu. Decyzja: (1) web-to-print (Twig HTML → renderer → PDF), NIE konektor InDesign; (2) **renderer za portem `PdfRenderer`** (`src/Export/Contracts/`) — `DompdfRenderer` (czysty PHP, in-process, domyślny, zero nowej infry) + `GotenbergRenderer` (headless Chromium jako opcjonalny sidecar, aktywny gdy `GOTENBERG_URL`), PDFreactor = przyszły 3. adapter CMYK; instalacja bez Gotenberga = pełny MVP; (3) silnik w `src/Export/Catalog/` (kalka `Export/Feed`, NIE nowy BC), cross-BC tylko przez `*\Contracts\*`; (4) reuse `ExportBuilder` jako źródła danych, scope przez publication profile (ADR-0018); (5) jeden parametryzowalny silnik szablonu (archetyp sheet/grid/pricelist + brand tokens + mapowanie pól), drag-drop builder = hook; (6) delivery pull cache-and-serve — regen → MinIO (`catalogs/{tenant}/{id}.pdf`) → publiczny URL serwuje cache (`ETag`/`304`), token-in-URL ≠ klucz tenanta; (7) MVP RGB ekranowy, `product` (+ warianty flat). Otwarte (M6): pamięć Dompdf (benchmark + cap/chunk), wierność Dompdf↔Gotenberg (snapshot parity). Hooki §7: konektor InDesign, CMYK/druk offsetowy, drag-drop builder, cron auto-regen, katalog wielojęzyczny, non-product. Backlog: `Project Plan/feature-cpdf-tickets.md` (epik CPDF, 27 ticketów, milestone'y M0–M6). Pełny plik: `docs/adr/0027-catalog-pdf-renderer-port.md`.

Streszczenie ADR-0029 (Workflow engine — symfony/workflow, guardy RBAC, nowy BC Workflow, epik WFL, #2409): status edytorski obiektu jest dziś polem bez maszyny stanów (`transitionTo()` przyjmuje wszystko, brak stanu `review`), a warstwa RBAC ma uśpioną politykę stanów (`WorkflowStatePolicy` #674, zero call sites) i zaseedowane permissiony `workflow.*`; PRD RBAC §3.8 specyfikuje pełną semantykę i wprost zakłada Symfony Workflow. Decyzja: (1) **`symfony/workflow` type `state_machine`**, definicja `object_editorial` (draft → review → published → archived; + skrót `publish` draft→published dla ról z approve), marking store = istniejąca kolumna `objects.status`; (2) **nowy BC `src/Workflow/`** (definicje, guard listener, log przejść `workflow_transitions`, zadania `workflow_tasks`), marking zostaje w Catalog, enforcement przez `*\Contracts\*` (Deptrac); (3) **guardy = mapa transition→permission RBAC jako DANE** (nie expressions w configu — lekcja Pimcore: builder per tenant w M5 bez deployu), odmowa = TransitionBlocker z kodem permission (409 RFC 7807); (4) log przejść z komentarzami zamiast wersjonowania (wzorzec „notes & events"); (5) gate completeness na publish/approve per ObjectType (`workflow_publish_gate` JSONB), default OFF; (6) definicje DB-driven per tenant = M5 za feature flagiem, odcinalne do Fazy 2; (7) import/integration piszą niezależnie od stanu (integracja nie utyka na review), agent przez własny gate `pending_changes` (hook auto-review = `[DEF]`); (8) breaking change: `status` w PATCH przestaje być wolnym polem (niezgodny z przejściem → 409). Known limitation MVP: self-approve dozwolony (four-eyes = Faza 2). Backlog: `Project Plan/feature-workflow-tickets.md` (epik WFL, 28 ticketów, milestone'y M0–M6, pakiety PR A–G). Pełny plik: `docs/adr/0029-workflow-engine-and-placement.md`.

## 14. Roadmap rozwoju

> **⚠️ Rewizja faz (2026-05-18 + późniejsze):** roadmapa poniżej odzwierciedla stan koncepcyjny z 2026-04-26 i w zakresie faz JEST NIEAKTUALNA — gate Sprintu 0 i ADR-013 przesunęły: integracje **BaseLinker/Shopify z MVP do Fazy 1**, **agenta (epik 0.7) do Fazy 2**, a **pełen RBAC i aktywację RLS z Fazy 1 do MVP-Alpha**. Aktualne źródło prawdy o fazach: `Project Plan/06-sprint-0-findings.md` (sekcja 2) + `CLAUDE.md` §„Priorytety implementacyjne". Sekcji poniżej celowo nie przepisujemy — dokumentuje pierwotny zamysł (#2193).

Roadmap fazowa, wysokopoziomowa. Szczegółowy backlog i estymacje w dokumencie `02-plan-projektu-pim.md`.

**Faza 0 — MVP (170-235h pełny / 156-216h okrojony, po rewizji 2026-04-27 i ADR-009; Sprint 0 40-55h + MVP Core 130-180h pełny)**
Sprint 0 vertical slice + domain model PIM **z generic `ObjectType`** (predefined Product/Category/Asset, custom kindy dla Fazy 2/3 — ADR-009), admin Refine z core CRUD, **BaseLinker + Shopify przeniesione do Fazy 1**, **agent layer przeniesiony do Fazy 2** (rewizja 2026-04-27, `06-sprint-0-findings.md` §2). API publiczne z konfiguratorem, hardening + WCAG AA + analytics dashboard + pgBackRest production + BYOK. Cel: pierwszy klient pilotażowy z działającym katalogiem (produkty + kategorie z user-defined atrybutami) i niezawodnym importem/eksportem. Szczegóły, sub-fazy i milestones: `02-plan-projektu-pim.md`.

**Faza 1 — Production-ready integracje (+100-140h)**
**BaseLinker + Shopify** integracje (epiki 0.8 + 0.9 — przeniesione z MVP-Final w rewizji 2026-04-27), hardening security, testy obciążeniowe, monitoring full stack, dokumentacja API publiczna, **PHPUnit + ApiTestCase + Playwright** coverage, **Postgres RLS aktywacja** (sekcja 11.1a, ~16-24h przed multi-tenantem), pierwsze 2-3 wdrożenia produkcyjne. Opcjonalnie: migracja Shopify na Bulk Operations (sekcja 7.3) jeśli benchmarks tego wymagają.

**Faza 2 — Agent layer + Agentic Pro + dodatkowe konektory (+150-250h)**
**Agent layer (epik 0.7 Beta-Min + Beta-Full, przeniesione z MVP w rewizji 2026-04-27)**, agent data-ops (bulk operations, generowanie opisów, mapowania importów), **odblokowanie custom `ObjectType` przez agenta** (tool `create_object_type` aktywny z feature flag, ADR-009), workflow engine, DAM advanced (transformacje, AI metadata extraction), **multi-tenant SaaS aktywacja** (z RLS już aktywnym z Fazy 1), dashboard analytics zaawansowane, marketplace integracji v1 (Magento, IdoSell, Allegro, WooCommerce).

**Faza 3 — Enterprise (+300h+)**
SSO/SAML, white-label, compliance gotowość (ISO 27001 / SOC 2), customer portal, advanced syndication (printable catalogs, PDF datasheets), **Symfony LTS upgrade** (R-28, ~40-60h gdy zbliża się EOL Symfony 7.4), partner program. ERP enterprise integracje na zamówienie (SAP, Dynamics, Netsuite, Comarch — każda 40-80h).

## 15. Zarządzanie ryzykiem

Pełen rejestr ryzyk z analizą prawdopodobieństwa, wpływu i mitygacji znajduje się w dokumencie `02-plan-projektu-pim.md`. Najważniejsze ryzyka strategiczne:

- Skalowanie agenta (koszty LLM API rosnące szybciej niż przychody) — mitygacja: limity per-tenant, model routing (Sonnet domyślnie, Opus tylko dla schema-ops).
- Konkurencja (Akeneo Cloud, PIMcore-as-a-Service) — mitygacja: agentic-first jako wyróżnik, polski customer success, niższa cena.
- Wycofanie wsparcia jednego z core komponentów (np. Meilisearch zmienia licencję) — mitygacja: abstrakcje w warstwie infrastructure, możliwość swap na ES/Typesense.
- Lock-in na Anthropic — mitygacja: warstwa abstrakcji LLM (interface `LLMProvider`), możliwość dodania OpenAI/Mistral w fazie 2.

## 16. Załączniki

- `02-plan-projektu-pim.md` — plan projektu fazy 0 i 1, backlog, estymacje, milestones.
- **Dokumentacja API**: nie ręcznie utrzymywany plik, lecz auto-generowany endpoint `/api/docs.json` (OpenAPI 3.1) + `/api/docs` (Swagger UI). CI eksportuje wersjonowane snapshoty do `docs/api-spec/v{version}.json` przy każdym release tag.
- (do uzupełnienia) `04-data-dictionary.md` — słownik danych domenowych.
- (do uzupełnienia) `05-runbook.md` — procedury operacyjne: deployment, backup/restore (PITR przez pgBackRest), incident response, rotacja kluczy LLM, BYOK provisioning.
- (do uzupełnienia po Sprincie 0) `06-sprint-0-findings.md` — wnioski z prototypu walidacyjnego, ewentualne korekty do ADR-ów (np. zachowanie FrankenPHP 2.x worker mode pod realnym obciążeniem).

---

*Koniec dokumentu architektury. Dokument żyjący — aktualizowany przy zmianach wpływających na architekturę.*
