# Plan projektu PIM — fazy, milestones, backlog, ryzyka

**Wersja:** 1.0 (faza koncepcyjna)
**Data:** 2026-04-26
**Powiązany dokument:** `01-architektura-pim.md`
**Status:** zatwierdzony do realizacji

---

## 1. Streszczenie planu

Projekt PIM jest realizowany w czterech fazach. Każda faza ma zdefiniowane cele biznesowe, deliverables, milestones i kryteria zakończenia. Plan zakłada developmen iteracyjny z Claude Code jako głównym narzędziem produkcji kodu, z udziałem nie-eksperta programistycznie jako product ownera, code reviewera i operatora.

**Faza 0 — MVP** to minimalna wersja umożliwiająca pierwsze wdrożenie pilotażowe. **Po rewizji 2026-04-27** (epiki 0.7 Agent → Faza 2; 0.8 BaseLinker + 0.9 Shopify → Faza 1) **i ADR-009** (generic `ObjectType` z predefiniowanymi Product/Category/Asset, +16-25h w epiku 0.3): Faza 0 = Sprint 0 (40-55h) + MVP Core (130-180h) = **170-235 realnych roboczogodzin pracy człowieka** dla pełnej wersji, **156-216h** dla okrojonej (bez analytics dashboard, BYOK, ograniczenie API Configurator do 1 profile). Tabela porównawcza w sekcji 7.

**Faza 1 — Production-ready** to hardening, dodanie Magento i IdoSell, pierwsze wdrożenia produkcyjne. Cel: dodatkowe 100-140 godzin.

**Faza 2 — Agentic Pro** dodaje data-ops capabilities agenta, multi-tenant SaaS i marketplace integracji. Cel: dodatkowe 150-200 godzin.

**Faza 3 — Enterprise** to compliance, white-label, SSO i partner program. Cel: 300+ godzin.

## 2. Założenia metodologiczne

### 2.1 Model pracy

Praca prowadzona w trybie pair-programming człowiek + Claude Code, gdzie:

- **Claude Code** pisze kod (~80% wolumenu), generuje testy, proponuje refactory, dokumentuje.
- **Człowiek** specyfikuje wymagania per ticket, **definiuje gate'y akceptacji wykonywane przez automatyzację** (PHPStan max, Playwright E2E, benchmark perf, axe-core a11y), testuje manualnie kluczowe ścieżki, deployuje, podejmuje decyzje architektoniczne i biznesowe.

**Mnożnik produktywności (poprawiony po review DeepSeek):**

Przyjmujemy że jedna realna roboczogodzina człowieka = ok. 3-5 roboczogodzin tradycyjnego developmentu **dla osoby z podstawową umiejętnością czytania kodu** (PHP/TypeScript) i rozpoznawania typowych klas błędów LLM (halucynacje API, brak obsługi transakcji, niezamknięte zasoby, N+1).

**Disclaimer dla pełnego non-codera:**
Jeśli operator nie czyta kodu w ogóle, mnożnik spada do **1.5-2x** względem tradycyjnego developmentu, bo:
- LLM-generated code często ma subtelne błędy widoczne dopiero w runtime (memory leak w workerze, źle ustawione transakcje, nieprawidłowy index na JSONB).
- Bez podstawowej weryfikacji kodu, błędy pojawią się jako bug reports z pilota — i debugging wymaga albo eksperta (zlecone), albo długiego cyklu z LLM "co się tu psuje".
- **Realne implikacje czasowe: dolicz +30-50% buforu** do wszystkich estymacji w tym dokumencie jeśli operator jest pełnym non-coderem bez doświadczenia ani mentora.

**Mitigacja braku review przez non-codera (kluczowe — Gemini point 3):**
"Code review" wykonywany przez automatyzację, nie przez ludzkie oczy. Twardy zestaw bramek (sekcja 2.2) zastępuje subiektywne ludzkie review. Człowiek-non-coder weryfikuje **deliverable user-facing**: czy E2E test świeci się na zielono, czy demo działa, czy pilot widzi to co trzeba.

**Rekomendacja praktyczna:** dla ścieżek krytycznych (architektura, multi-tenant migracja, agent layer, security hardening) — zrezerwować 20-30 godzin mentora technicznego (zewnętrzny senior PHP/Symfony) jako safety net. Mentor nie pisze kodu, tylko robi review w kluczowych momentach. Koszt: ~5-10k PLN, zwrot: zminimalizowanie ryzyka subtelnych błędów które wybuchają w runtime.

### 2.2 Definicja "Done" per ticket — automation-first (zmiana po review Gemini)

Ticket uznajemy za zakończony gdy spełnione są **automatyczne gate'y** (nie ludzki "sprawdziłem i wygląda ok"):

**Bramki obowiązkowe (CI blocker — bez nich PR nie merge'uje się):**
- ✅ Kod kompiluje się i przechodzi static analysis: **PHPStan level max (8) + Psalm strict + Biome strict**.
- ✅ Unit testy istnieją dla nowej logiki domenowej (coverage tej logiki ≥80%, mierzony **PHPUnit** — bez Pest).
- ✅ Integration testy istnieją dla nowych endpointów API (**API Platform `ApiTestCase`** zamiast Behat — wbudowany w API Platform 4, oparty na PHPUnit, pełen DSL do asercji JSON-LD/Hydra).
- ✅ **Playwright E2E test istnieje dla każdej widocznej user-facing zmiany** (od dnia 1, nie odkładany na epik 0.11) — to jest twoja jedyna ludzka warstwa walidacji. Bez E2E, ticket nie jest done.
- ✅ Audit log działa (jeśli zmiana dotyka encji domenowej).
- ✅ Dokumentacja inline (PHPDoc / TSDoc) — wymagane na publicznych metodach service'ów.
- ✅ Composer audit + npm audit pass (brak nowych vulnerable dependencies).
- ✅ Manual smoke przez operatora — **5 minut: kliknij happy path w admin, sprawdź czy działa to co miało**. Nie udawaj review kodu — udawaj usera.

**Bramki kontekstowe (jeśli applicable):**
- a11y axe-core scan zielone (dla ticketów dotykających UI).
- Benchmark perf (dla ticketów dotykających queries / endpointów krytycznych).
- Multi-tenant izolacja test (dla ticketów dotykających zapytań domenowych).
- Memory benchmark FrankenPHP worker (dla ticketów dotykających Messenger handlerów lub batch operations).

**Co NIE jest definicją done:**
- ❌ "Operator przeczytał kod i wydaje się ok" — review LLM-generated kodu przez non-codera to fikcja, która uśpi czujność. Nie polegamy na tym.

**Świadomy minimalizm narzędzi testowych (polerka po finalnym review Gemini):**
Stack testowy w MVP to **dwa narzędzia, nie cztery**:

1. **PHPUnit** — testy jednostkowe (logika domenowa, walidatory, value objects) **i** testy integracyjne API (przez `ApiPlatform\Symfony\Bundle\Test\ApiTestCase`, który zastępuje Behat — daje DSL do asercji JSON-LD, Hydra, OpenAPI, autoryzacji, kodów odpowiedzi).
2. **Playwright** — testy E2E (klikanie po Refine.dev w prawdziwej przeglądarce).

**Świadomie odrzucone:**
- **Pest** — drugi runner unit testów obok PHPUnit. Daje ładniejszą składnię, ale dla LLM-generated kodu to dodatkowe narzędzie do nauki, kolejny config, kolejny CI step. Korzyść kosmetyczna, koszt poznawczy realny.
- **Behat** — Gherkin-based BDD framework. Świetny gdy product manager pisze scenariusze, **bezsensowny gdy operator pracuje z agentem LLM**. `ApiTestCase` z API Platform pokrywa 100% przypadków integracyjnych z lepszym lock-inem do framework'u.

Powód redukcji: każde dodatkowe narzędzie to kolejny kontekst, w którym Claude Code może się zaciąć generując boilerplate. Mniej rozproszenia, mniej konfliktów wersji w composer, krótszy CI run, jeden styl testów dla całego backendu.

### 2.3 Definicja "Done" per faza

Faza uznawana za zakończoną gdy:
- Wszystkie tickety w backlogu fazy są zamknięte.
- Aplikacja deployowalna jednym poleceniem (`docker-compose up`).
- Smoke test E2E przechodzi (Playwright dla admin, **PHPUnit + ApiTestCase** dla API).
- Dokumentacja architektury i runbook zaktualizowane.
- Retrospektywa fazy przeprowadzona z dokumentowaniem nauczek.

## 3. Faza 0 — MVP

> ### REWIZJA ZAKRESU MVP (2026-04-27, po zamknięciu #5)
>
> **Decyzja operatora:** "agentic management jest całkowicie jako dodatek, musimy zrobić pełną bazę, dobry UX frontu, więc to wypadnie finalnie jeszcze dalej". W praktyce oznacza to:
>
> - **Cały epik 0.7 (Agent layer — schema-add, Beta-Min + Beta-Full)** → Faza 2. Hooks runtime (`pending_changes` table, `provenance` enum, lifecycle events) zostają w MVP — sam agent dochodzi po stabilnym pilocie z prawdziwymi user stories.
> - **Epiki 0.8 (BaseLinker) + 0.9 (Shopify)** → Faza 1. Pierwszy klient produkcyjny dostaje BaseLinker + Shopify razem po stabilizacji katalogu w MVP, nie jako część gate-decision'u.
> - **Tickety Sprint 0 #6 (Agent endpoint), #7 (Cmd+K placeholder), #8 (Shopify GraphQL stub)** → przeniesione do Fazy 1/2. Sprint 0 zamykamy 6 pozostałymi ticketami: #9 demo, #10 Playwright, #13 benchmark FrankenPHP, #14 profilowanie, #15 pgBackRest, #16 audit + findings.
> - **Layout admina #54** rewriten — Cmd+K placeholder usunięty z scope, sam shell layoutu (już startowy ze #5) doczołguje się do pełni.
> - **Provenance badge #61** — wariant `agent` (purple) odłożony do Fazy 2; MVP pokazuje tylko `manual` / `import` / `integration`.
>
> **Powód:** pierwszy pilot (B2B technical, 50 MLN GMV/rok per `Project Plan/03-funkcjonalnosci-mvp.md`) ocenia "działający katalog 5k SKU + niezawodny sync" wyżej niż "rozmawiaj z systemem". Materiał na pilot szybciej, ryzyka R-25/R-27/R-28 wychodzą z MVP. Warunek: `provenance` w `product_values` od dnia 1 i `pending_changes` jako pusta tabela w bazie — koszt 4-6h, oszczędza 30-40h migracji w Fazie 2.
>
> **Nowa kolejność wykonania po Sprincie 0:**
> 1. MVP-Alpha epiki 0.1, 0.2, 0.3 (fundament)
> 2. (decyzja) Epik 0.3a — Categories / taxonomy (kandydat do dodania w MVP)
> 3. Epik 0.4 + 0.5 (API extensions + Meilisearch)
> 4. Epik 0.6 (Admin UI core CRUD — atrybuty + dynamiczny formularz produktu)
> 5. Epik 0.10 + 0.11 (API Configurator + hardening / a11y / analytics / backup / BYOK)
> 6. Demo pilot → decyzja gate-decision o gotowości
> 7. **Faza 1**: BaseLinker + Shopify
> 8. **Faza 2**: Agent layer (epik 0.7) + Magento + IdoSell

### 3.0 Sprint 0 — Vertical Slice (walidacja architektury, ~40-55h)

Przed wejściem w pełen backlog MVP wykonujemy **wąski plaster pionowy** całej architektury dla jednej encji end-to-end. Cel: zwalidować że stack faktycznie działa razem zanim zainwestujemy ~200h w pełen scope. Sprint 0 to ubezpieczyciel projektu — inspirowane sugestiami z drugiej i trzeciej rundy review.

**Tryb realizacji (kluczowa decyzja po review Gemini):**
Sprint 0 musi być wykonany w trybie **maksymalnego skupienia: pełen tydzień lub dwa intensywnej pracy**, nie 4-5 tygodni dorywczo po 10h. Powód: stack jest złożony (FrankenPHP + Symfony + API Platform + Refine + Mercure + Anthropic SDK + Shopify GraphQL + Postgres JSONB + Meilisearch — 9 ruchomych części). Rozłożenie na miesiąc oznacza że co tydzień traci się i odbudowuje kontekst, a w międzyczasie aktualizują się pakiety npm/composer. **Zarezerwuj urlop lub blok 2 tygodni jeśli to możliwe — to inwestycja, która zwraca się w fazie MVP.**

**Zakres Sprintu 0 (rozszerzony po review Gemini/DeepSeek/Grok):**

Bazowy stack:
- 0.0.1 Setup **monorepo Turborepo** (`apps/api`, `apps/admin`, `packages/shared-types`) + docker-compose w minimalnej formie (FrankenPHP 2.x + Symfony 7.4 + Postgres 16 + Redis 7 + Meilisearch + MinIO + Mercure + Mailpit). **Krytyczne — Caddy single-origin proxy (sekcja 3.10a architektury):** Caddyfile w FrankenPHP routuje `/api/*` do Symfony i całą resztę do `vite:5173` jako reverse proxy. **Nie konfigurujemy CORS, nie wystawiamy frontend pod osobnym portem na host.** To eliminuje godzinną pętlę debugowania CORS gdy Claude Code próbuje pogodzić `localhost:5173` z `api.localhost`.
- 0.0.2 Jedna encja: `Product` z kilkoma core polami (sku, name, description, brand) — bez pełnego modelu atrybutów scopable/localizable, ale **z** kolumną `tenant_id` i prostym Doctrine filterem (przygotowanie pod multi-tenant test).
- 0.0.3 ApiResource w API Platform — endpoint `/api/products` działa (list, get, post, patch) z OpenAPI auto-generowanym.
- 0.0.4 Authentication minimalny (jeden statyczny user + JWT przez LexikJWT).
- 0.0.5 Admin Refine + shadcn — jeden ekran: lista produktów + create/edit z dynamicznym formularzem.
- ~~0.0.6 Agent endpoint `/api/agent/run`~~ → **przeniesione do Fazy 2** (rewizja 2026-04-27).
- ~~0.0.7 Cmd+K w admin~~ → **przeniesione do Fazy 2** (rewizja 2026-04-27).
- ~~0.0.8 Najprostszy klient Shopify GraphQL Admin API~~ → **przeniesione do Fazy 1** razem z całym epikem 0.9 (rewizja 2026-04-27).
- 0.0.9 Manualny test end-to-end: utwórz produkt w admin → edytuj → sprawdź izolację multi-tenant. (Bez agenta i Shopify w demo Sprint 0 po rewizji.)

Walidacja jakości i bezpieczeństwa (nowe, kluczowe — bez tego Sprint 0 jest niekompletny):
- 0.0.10 **Playwright E2E test od dnia 1**: jeden test happy path "user loguje się → tworzy produkt → wysyła do Shopify". Każdy nowy ticket w MVP **musi** dostać E2E test razem z kodem (Gemini point: review przez non-codera = automatyzacja, nie ręczne czytanie).
- 0.0.11 **PHPStan max level (8)** + Psalm strict mode skonfigurowane w CI od dnia 1, żeby od początku wymuszać dyscyplinę typów.
- 0.0.12 **Test izolacji multi-tenant** (smoke): tworzymy 2 tenanty, próbujemy odczytać produkty drugiego, oczekujemy 0 wyników. Walidacja że Doctrine filter działa (RLS odkładamy do fazy 2).
- 0.0.13 ✅ **Benchmark FrankenPHP worker memory**: `pim:benchmark:bulk-import` waliduje pattern `EntityManager::clear()` w `AbstractBatchHandler`. **Wynik (prod env):** 5 000 produktów = 14 MiB peak, 50 000 = 14 MiB peak FLAT (próg 256 MiB → headroom 18×). Bez clear: 50 000 = 150 MiB rosnąco + 6× wolniej. Prometheus endpoint `GET /api/metrics` w MVP. Custom PHPStan rule (flush bez clear) → follow-up #123 (epik 0.11).
- 0.0.14 ✅ **Profilowanie perf** — `pnpm perf:list` (k6 + `pim:benchmark:bulk-import --keep` seed) + EXPLAIN ANALYZE głównego query. **Wynik (prod env, 1005 produktów):** single-user p95 = 18.7 ms, 10 VUs p95 = 105 ms ✅ (próg 200 ms), 30 VUs p95 = 365 ms, 100 VUs p95 = 997 ms (limited by FrankenPHP `num_threads: 17` pool, multi-worker w fazie 2). Top 5 hot paths: Serializer JSON-LD ~3-4ms, Doctrine hydration ~3-4ms, Security firewall ~2-3ms, API Platform metadata ~1-2ms, Caddy/TLS ~1-2ms — brak dominującego bottleneck'a. Blackfire/Tideways → epik 0.11 (#103-#105) gdy będzie ROI licencji.
- 0.0.15 **pgBackRest + WAL stub w docker-compose** — backup co 1h podczas Sprintu 0, jeden test restore przed końcem.
- 0.0.16 **Claude Code system prompt** — dokument `.claude/CLAUDE.md` z twardymi wytycznymi (memory management FrankenPHP, batch handler pattern, RLS test obligatoryjny, E2E coverage każdego ticketu, PHPStan max, **single-origin przez Caddy — nigdy nie konfigurujemy CORS**, **testowanie tylko PHPUnit + Playwright** — sekcja 2.2). Pisany ręcznie przed pierwszym ticketem MVP — to jest "konstytucja" projektu.

**Definicja sukcesu Sprintu 0 (gate decision):**

| Walidacja | Próg sukcesu |
|---|---|
| End-to-end happy path działa | Tak/Nie |
| FrankenPHP worker memory | < 256 MB po 5000 produktów import |
| Latency `/api/products?page=1` | p95 < 200ms na 1000 produktów |
| Multi-tenant izolacja smoke test | 0 cross-tenant leak |
| Playwright E2E test | passing on CI |
| PHPStan max 8 + Psalm strict | passing on CI |
| pgBackRest restore test | passing |
| Anthropic agent jeden tool call | działa, koszt < $0.05 |
| Shopify create test product | działa, throttling respektowany |

**Decyzja po Sprincie 0:** kontynuujemy pełen backlog MVP **lub** korygujemy architekturę zanim zainwestujemy więcej. Wnioski zapisujemy w `06-sprint-0-findings.md` jako załącznik do dokumentacji architektury (potencjalne korekty ADR-ów).

**Co Sprint 0 świadomie NIE obejmuje:**

- Pełen model atrybutów (`ObjectType` po ADR-009, scope, locale, completeness).
- Pełen agentic UX (streaming SSE, schema diff modal, approval inbox, provenance badges).
- Drugi integrator (BaseLinker odkładamy na Epic 0.8).
- Pełny multi-tenancy (smoke test izolacji wystarcza, RLS w fazie 2).
- RBAC granularny (jedna rola — admin).
- Audit log (DoctrineAuditBundle dochodzi w MVP).

**Estymacja:** 40-55 realnych godzin pracy człowieka (rozszerzone z 30-40h po dodaniu walidacji jakości — Playwright, benchmark memory, profilowanie, backup test, system prompt). Wynik: zielony lub czerwony. Jeśli czerwony — wracamy do fazy koncepcyjnej z konkretnymi danymi co nie działa, korygujemy stack lub scope, dopiero potem do pełnego MVP.

### 3.1 Cele biznesowe

Pierwsza wdrażalna wersja systemu PIM, zdolna do pilota u jednego klienta. System ma poprawnie obsłużyć 50 000 produktów z trzema poziomami atrybutów (`ObjectType` po ADR-009, attribute groups, atrybuty), syndykować dane do **BaseLinker i Shopify** (Magento przesunięty do fazy 1 — sekcja 4.2), oraz mieć agentic admin pozwalający na rozszerzanie schematu przez chat.

### 3.2 Out of scope dla MVP

Aby utrzymać Fazę 0 w realnym budżecie (156-235h, sekcja 7 — okrojony / pełny), świadomie wykluczamy z MVP:

- IdoSell (przesunięte na fazę 1).
- Workflow engine (manualne stany, bez state machine).
- Wersjonowanie produktów (tylko aktualna wersja).
- DAM zaawansowane (transformacje, AI metadata) — w MVP plain upload.
- Bidirectional sync z integracjami (tylko PIM → external).
- Data-ops agenta (tylko schema-add).
- Multi-tenant deployment (single tenant, ale model gotowy).
- White-label / theming admin UI.
- Public docs portal (OpenAPI udostępnione, ale bez dedykowanego portalu).

### 3.3 Backlog MVP — Epiki i tickety

#### Epik 0.1: Infrastructure i fundamenty (16-22h)

- 0.1.1 Setup repo (mono lub multi-repo decyzja), .gitignore, README, CONTRIBUTING.
- 0.1.2 Docker Compose: FrankenPHP, PostgreSQL 16, Redis 7, Meilisearch, MinIO, Mailpit (dev mail).
- 0.1.3 Skeleton Symfony 7 + API Platform 4, podstawowy bundle layout (Catalog, Channel, Asset, Integration, Identity, Agent).
- 0.1.4 Skeleton frontend admin: Vite + React 19 + TypeScript + Refine + shadcn/ui + routing.
- 0.1.5 GitHub Actions CI: PHPStan, Psalm, PHP-CS-Fixer, PHPUnit, Biome dla frontendu.
- 0.1.6 Pre-commit hooks (husky / pre-commit framework).
- 0.1.7 Doctrine migrations baseline + initial schema.

#### Epik 0.2: Identity & Access (10-14h)

- 0.2.1 Encja User, Role, Permission, Tenant.
- 0.2.2 Symfony Security z JWT (LexikJWT) + form login dla admin UI.
- 0.2.3 Voters dla głównych zasobów (Object, Attribute, ObjectType, Channel) — po ADR-009: Voters operują na generic `Object` z scope per `kind`, nie hard-coded `Product`/`Family`.
- 0.2.4 RBAC seeder z domyślnymi rolami (super_admin, catalog_manager, integration_manager, viewer).
- 0.2.5 Endpoint /api/auth/login, /api/auth/refresh, /api/auth/me.
- 0.2.6 Auth provider w Refine + przechowywanie tokenów (httpOnly cookie).
- 0.2.7 Multi-tenant fundament: kolumna tenant_id wszędzie, Doctrine listener filtrujący query, Postgres RLS policies.

#### Epik 0.3: Domain model — Catalog (36-50h, **rewrite po ADR-009**)

> **Rewrite 2026-04-27 (ADR-009):** epik został rozszerzony o generic `ObjectType` + predefiniowane Product/Category/Asset jako built-in instancje. Pojęcie „Family" jest deprecated. Wzrost estymacji **+16-25h** (z 16-20h do 36-50h) finansowany ze zwolnionego budżetu epiku 0.7 (przeniesionego do Fazy 2 — `06-sprint-0-findings.md` §2). Top-line MVP-Alpha się trzyma.

- 0.3.1 Encje Attribute + AttributeGroup + AttributeOption (z enum typów: text, number, select, multiselect, date, boolean, asset, relation, price, metric). Atrybuty wiązane z `ObjectType` przez junction `object_type_attributes` (jeden atrybut może być reused przez wiele typów: `name` dla każdego, `seo_title` dla `product` i `category`). [GH #31]
- 0.3.2 Encje **`ObjectType` + `ObjectTypeAttribute`** (zastępują `Family` + `FamilyAttribute`): pole `kind` (`product` | `category` | `asset` | `custom`), flag `is_built_in` (TRUE dla predefined seed), `completeness_rules` JSONB, `label_attribute_id` + `image_attribute_id`. Service `ObjectTypeService` blokuje deletion gdy `is_built_in=true`. Slownik domeny: w nowym kodzie używamy „ObjectType" wszędzie — „Family" deprecated. [GH #32]
- 0.3.3 **Predefiniowane `ObjectType` fixtures + custom logika `kind='category'`** (po ADR-009 zlepione w jeden ticket — fixtures dla wszystkich 3 kindów + ltree validator dla category razem):
  - Fixtures `is_built_in=true`: `product` (kind, schema bazowa: `name`, `sku`), `category` (kind, schema bazowa: `code`, `path`, `name` + opcjonalnie user-defined `seo_title`, `seo_description`, `main_image`), `asset` (kind, schema bazowa: `code`, `storage_path`, `mime_type`). Fixture odpalany w `tenant.create` flow + pierwszej migracji multi-tenant init.
  - Custom logika `kind='category'`: Doctrine custom type `LtreeType`, listener `CategoryPathValidator` walidujący `path` tylko dla obiektów z `kind='category'` (NULL wymuszony dla pozostałych). Partial GIN/GIST index `WHERE kind = 'category'`.
  - [GH #33]
- 0.3.4 Encja **`Object` + `ObjectValue`** (zastępują `Product` + `ProductValue`): jedna tabela `objects` z polimorfizmem przez `kind`, `attributes_indexed JSONB+GIN` parametryzowany per `object_type_id`, `parent_id` self-reference (variants dla `kind='product'`, drzewo dla `kind='category'`). `object_values` tabela faktów (zastępuje `product_values`) z `provenance` + `provenance_meta`. [GH #34]
- 0.3.5 Encja Association (cross/up-sell/related/accessory) — działa **generycznie na `Object`**, nie tylko Product. Po ADR-009: `object_associations` tabela (zastępuje `product_associations`). [GH #35]
- 0.3.6 Encje Channel + Locale + Currency + **`ChannelObjectTypeMapping`** (zastępuje `ChannelAttributeMapping` — mapping atrybutów per `object_type_id`, poly per `kind`). Channel publikuje obiekty wybranych kindów (np. storefront publikuje `product`+`category`, ale nie `asset` bezpośrednio). [GH #36]
- 0.3.7 Encja Asset + AssetVariant z Flysystem do MinIO. **Po ADR-009:** Asset reprezentowany jako `Object kind='asset'` (dla user-defined metadata przez `object_values`) + dedykowana tabela `assets` (storage_path, mime_type, size_bytes, transformacje, variants). Powiązanie 1:1 przez `assets.object_id`. DAM zachowuje swój lifecycle. [GH #37]
- 0.3.8 Doctrine event listenery: `AttributesIndexedSyncListener` parametryzowany per `object_type_id` (synchroniczny dla single-edit, async messenger `attributes-indexed-rebuild` dla bulk path), `CompletenessRecalculator` czyta reguły z `ObjectType.completeness_rules`. Listener `BulkContext` aware (sekcja 3.10 architektury). [GH #38]
- 0.3.9 Symfony Validator constraints per typ atrybutu (10 validators) — bez zmiany scope, tylko parametryzacja per `ObjectType` w `AttributeValidationCompiler`. [GH #39]
- 0.3.10 Migracje + seeders example data (rozszerzone): **100 produktów z `kind='product'` + 5 kategorii z `kind='category'` (z własnymi atrybutami: `seo_title`, `seo_description`, `main_image`) + 10 assetów** w 1 tenancie. Demo dataset dowodzi że kategorie mają own user-defined fields (proof of ADR-009). [GH #40]
- 0.3.11 **Hooks pod `kind='custom'` na poziomie ApiResource** (NOWY ticket po ADR-009): factory `ObjectTypeAwareApiResource` + serializer context per `kind`. W MVP wystarczy szkielet — pełna obsługa custom kindów (UI builder, runtime schema editor) dochodzi w Fazie 2/3 razem z odblokowaniem agent toola `create_object_type`. Mitigacja R-29 (over-engineering): w MVP `kind='custom'` jest CHECK-allowed w bazie ale wyłączony przez feature flag w service'ach. [GH #128]

#### Epik 0.4: API Platform — exposing entities (10-14h)

> **Po ADR-009:** ApiResource jest na encji `Object`, ale eksponowany jako sugar paths per `kind`: `/api/products`, `/api/categories`, `/api/assets` (predefiniowane, lepszy DX integratorów). Jeden controller, trzy paths z serializer context per `kind`. Custom kindy (Faza 2/3) pójdą przez unified `/api/objects?kind=...`. Generalizacja jest po stronie modelu — AP4 widzi już abstrakcyjną encję, estymacja epika bez zmian.

- 0.4.1 ApiResource adnotacje: jeden `Object` z trzema sugar paths-aware ApiResource declarations (`#[ApiResource(uriTemplate: '/products', extraProperties: ['kind' => 'product'])]` etc.) + `Attribute`, `ObjectType`, `Channel`, `Asset` (osobna ApiResource dla storage szczegółów), `Association`.
- 0.4.2 Grupy serializacji per-context (admin, integration, public) — context aware o `kind`.
- 0.4.3 Custom filtry (search po code/sku, filtry po atrybutach, filtry po kategorii z descendants, filter po `object_type_id`).
- 0.4.4 Custom paginator (cursor-based dla list >1000).
- 0.4.5 Custom data transformers — `ObjectDenormalizer/Normalizer` (atrybuty ↔ object_values), parametryzowany per `object_type_id`.
- 0.4.6 OpenAPI customization (przykłady w dokumentacji per kind, security schemes).
- 0.4.7 Mercure publisher dla zdarzeń domenowych (`object.created.product`, `object.updated.category`, `attribute.created`, `sync_job.*`).
- 0.4.8 Rate limiter per-endpoint (Symfony RateLimiter).

#### Epik 0.5: Search — Meilisearch (6-8h)

> **Po ADR-009:** indeksy Meilisearch podzielone per `kind` (`products`, `categories`); indexer parametryzuje się o `object_type_id`. Estymacja bez zmian — settings template per ObjectType jest tanim dodatkiem.

- 0.5.1 Bundle do indeksowania (services, configuration); settings template per ObjectType (searchable/filterable/sortable z `object_type.search_config` JSONB albo z konwencji).
- 0.5.2 Doctrine event listener → Symfony Messenger message (`ObjectIndexed(objectId, kind)`) → worker pisze do indeksu odpowiadającego `kind`.
- 0.5.3 Initial reindex command `pim:search:reindex --kind=product|category|all` (memory safe z `EntityManager::clear()` co N=200).
- 0.5.4 Endpoints `/api/products/search` + `/api/categories/search` z facetingiem + tenant isolation.
- 0.5.5 UI w Refine: search box z autocomplete + faceted filtry (per resource).

#### Epik 0.6: Admin UI — core CRUD (20-26h)

> **Po ADR-009:** sidebar pokazuje predefiniowane sekcje pierwszej klasy (Produkty, Kategorie, Zasoby, Atrybuty, ObjectTypes, Channels). Resource ObjectTypes (zastępuje "Resource Families") obsługuje tylko predefiniowane jako locked + sekcję "Custom" oznaczoną jako Faza 2/3. Categories dostają dynamiczny edytor atrybutów (ten sam form engine co Products, parametryzowany o `kind='category'`). Estymacja bez zmian — generic UI builder dla custom kindów dochodzi w Fazie 2/3.

- 0.6.1 Layout admina (sidebar z fixed sekcjami: Produkty / Kategorie / Zasoby / Atrybuty / ObjectTypes / Channels; top bar; content area). **Cmd+K placeholder usunięty z scope** (rewizja 2026-04-27 — Cmd+K dochodzi w Fazie 2 razem z agentem).
- 0.6.2 Resource Products: list (table + filters + bulk actions), show (detail), create, edit (z dynamicznym formularzem parametryzowanym o `object_type_id`).
- 0.6.3 Resource Attributes: list, show, create, edit + przypisanie do grup + filtr `applies_to_object_type` (Product / Category / Asset / All).
- 0.6.4 **Resource ObjectTypes** (nazwa po ADR-009 zamiast "Resource Families"): list, show, create, edit + przypisanie atrybutów (`object_type_attributes`). UI pokazuje predefiniowane (`is_built_in=true`) jako locked (read-only, deletion blocked); sekcja "Custom" widoczna ale disabled z badge "Faza 2".
- 0.6.5 Resource Categories: tree view (drag-and-drop), create, edit + **dynamiczny edytor atrybutów dla `kind='category'`** używa tego samego form engine co Products (parametryzowany o object_type_id kategorii). User-defined SEO/image atrybuty edytowalne z poziomu drzewa.
- 0.6.6 Resource Channels: list, show, create, edit + mapping atrybutów per `object_type_id` (`ChannelObjectTypeMapping`).
- 0.6.7 Resource Assets: list (z preview), upload (drag-and-drop), edit metadata (powiązany Object kind='asset' z user-defined atrybutami w tym samym edytorze).
- 0.6.8 Provenance badges na polach (manual / import / integration; `agent` zarezerwowany do Fazy 2).
- 0.6.9 i18n (pl + en).

#### Epik 0.7: Agent layer — schema-add (25-35h, podzielony na MVP-Alpha minimum + MVP-Beta extras)

**Reestymacja po review Grok:** poprzednio 16-22h było zaniżone o ~30-50% — pełen agentic UX z streaming SSE, schema diff modal, approval inbox, provenance i obsługą stanów błędów to realnie 25-35h. Aby nie rozdąć fazy 0, dzielimy epik na **MVP-Beta-Min** (musi być w MVP) i **MVP-Beta-Full** (pełen agentic UX, opcjonalnie do MVP-Final).

**MVP-Beta-Min — minimum agentic UX dla MVP (12-16h):**
- 0.7.1 Bundle Agent + service AgentSession + AgentRun encja.
- 0.7.2 Anthropic SDK PHP integration + system prompt dla schema-ops + **twarde limity kosztów (sekcja 8.5 architektury): rate limit, hard cap tool calls, dziennie/miesięcznie budget cap z alertami**.
- 0.7.3 Tool definitions (po ADR-009): `search_attributes`, `search_object_types`, `create_attribute`, `create_attribute_group`, `assign_attribute_to_object_type` (rename z `assign_attribute_to_family`), `create_object_type` (reserved, wyłączony feature flagiem `enable_custom_object_types` — odblokowany w Fazie 2/3), `create_category` (sugar — wewnętrznie tworzy `Object kind='category'`), `preview_changes`. Słownik mówi językiem `ObjectType`, nie `Family`.
- 0.7.4 Tool execution layer z walidacją argumentów + autoryzacją przez Voters.
- 0.7.5 Pending changes queue (encja PendingChange) + mechanizm approve/reject.
- 0.7.7 UI: Cmd+K command bar w admin layout.
- 0.7.8a UI: prosty chat panel (Sheet w shadcn) — non-streaming, klient czeka na pełną odpowiedź (akceptowalny UX dla MVP).
- 0.7.9a UI: prosty preview-changes modal (lista zmian + accept/reject, bez bogatego schema diff).
- 0.7.11 Audit logging wszystkich akcji agenta.

**MVP-Beta-Full — pełen agentic UX (13-19h, opcjonalnie do MVP-Final, decyzja po MVP-Beta-Min):**
- 0.7.6 SSE streaming odpowiedzi przez Mercure.
- 0.7.8b UI: chat panel ze streaming responses (token-by-token rendering).
- 0.7.9b UI: bogaty schema diff modal z accept/modify/reject, pokazuje przed/po dla każdej zmiany, kolorowanie semantyczne.
- 0.7.10 UI: agent inbox z pending changes (queue widget z aktywnymi zatwierdzeniami).
- 0.7.12 UI: provenance badges przy polach które ostatnio zmienił agent (z tooltipem "zmienione przez agent X dniu Y").

**Powód podziału (Grok point):** ryzyko że pełen agentic UX rozpłaszczy MVP — klient pilotażowy potrzebuje dowodu że agent działa, nie potrzebuje od razu pixel-perfect demo wszystkich pattern'ów. MVP-Beta-Min wystarczy do walidacji koncepcji; MVP-Beta-Full robimy gdy MVP-Final ma 1-2 wolne tygodnie zanim pójdzie do pilota.

#### Epik 0.8: Integracja BaseLinker (12-16h)

- 0.8.1 Bundle Integration\BaseLinker.
- 0.8.2 Client (Symfony HttpClient) z retry, circuit breaker.
- 0.8.3 Adapter mapujący Product PIM → BaseLinker product format.
- 0.8.4 Konfiguracja w admin UI (klucz API, mapowania atrybutów, mapowania kategorii).
- 0.8.5 Command full-sync + incremental sync via Doctrine event listener.
- 0.8.6 Sync_jobs UI: lista, status, podgląd błędów, retry per-item.
- 0.8.7 Testy integracyjne z BaseLinker sandbox.

#### Epik 0.9: Integracja Shopify (14-18h)

**Decyzja produktowa:** Shopify zamiast Magento w MVP — Shopify to większy rynek (~4.6M sklepów globalnie vs ~150k Magento), znacząco niższy próg wejścia dla SMB/mid-market klientów, silny ekosystem D2C i międzynarodowy rozwój marek polskich. Magento przesuwamy do fazy 1 (lub fazy 2 jeśli pipeline nie pokaże popytu).

- 0.9.1 Bundle Integration\Shopify.
- 0.9.2 Klient Shopify Admin GraphQL API (preferowany w 2026 nad REST) z OAuth 2.0 dla Shopify Partners apps lub Custom App access token dla enterprise.
- 0.9.3 Adapter PIM → Shopify mapujący atrybuty na **metafields** (z namespace per tenant), warianty na Shopify Variants, kategorie na Shopify Collections.
- 0.9.4 Multi-store support: Shopify Markets dla międzynarodowych wariantów cen/locale; Shopify Plus stores jako osobne profile integracyjne.
- 0.9.5 Konfiguracja w admin UI: token, sklep, mapowania atrybut→metafield (z namespace), mapowania kategorii→collections.
- 0.9.6 **Bulk sync paczkami po 250 elementów przez zwykłe mutacje GraphQL** (decyzja po review Gemini/DeepSeek — Bulk Operations API odkładamy do fazy 1). Implementacja: Symfony Messenger handler bierze paczki po 250 produktów z bazy, woła `productCreate`/`productUpdate` mutations w pętli z `EntityManager::clear()` po każdej paczce. **Throttling — najprostszy działający algorytm: Exponential Backoff (sekcja 7.3 architektury, polerka po finalnym review Gemini)** — bez współdzielonego stanu Redis, bez własnej matematyki na `extensions.cost.throttleStatus`, bez Symfony RateLimiter z custom strategy. Pętla: wyślij request, jeśli 429 / `THROTTLED` → `sleep(Retry-After ?? 2 * 2^retry_count)`, retry max 5 razy.
- 0.9.7 Sync_jobs UI dla Shopify ze statusem (running / paused / completed / failed) + per-product errors + retry button.
- 0.9.8 Webhooks (opcjonalnie w MVP, pełne w fazie 1): `products/update`, `inventory_levels/update` — nasłuchiwane na endpoint `/webhook/shopify/{tenant_code}` z weryfikacją HMAC-SHA256.
- 0.9.9 **Exponential Backoff jako jedyny mechanizm throttlingu w MVP (polerka Gemini)** — w `Integration\Shopify\GraphQLClient`: po wysłaniu mutacji jeśli odpowiedź to HTTP 429 lub `errors[].extensions.code === 'THROTTLED'`, pobieramy `Retry-After` z nagłówka odpowiedzi (lub fallback `2^retry_count` sekund, max 60s), `sleep`, retry. Max 5 prób, potem ticket idzie do dead-letter queue z błędem widocznym w UI sync_jobs. **Świadome odejście od Leaky Bucket + Redis shared state** — algorytm bucket'a wymaga liczenia tokenów rate × cost per query × współdzielenia stanu między workerami — to skomplikowana matematyka stanu rozproszonego, na której LLM się zacina. Exponential backoff jest 5-liniowy, deterministyczny, samoreparujący się i wystarczy dla 50k SKU. **Punkt powrotu do Leaky Bucket:** w fazie 1 razem z migracją na Bulk Operations, gdy benchmark pokaże że backoff marnuje >20% slot'ów dostępnego rate limitu Shopify (sekcja 0.9.11 i sekcja 7.3 architektury).
- 0.9.10 Testy integracyjne z Shopify Partners development store (free) — happy path + scenariusze błędów (throttling, niewłaściwy token, metafield over limit).
- 0.9.11 **Punkty rozważenia migracji na Bulk Operations + Leaky Bucket w fazie 1**: (a) 90-percentyl czasu pełnego sync 50k SKU przekroczy 60 min, (b) klient enterprise zażąda <30 min full sync, lub (c) backoff marnuje >20% dostępnego rate limitu Shopify (mierzone przez `extensions.cost.throttleStatus.currentlyAvailable` zalogowane w sync_jobs po każdym requeście — proste zliczenie bez aktywnego sterowania).

#### Epik 0.10: API Configurator (8-12h) — ROZSZERZONY do Uniwersalnego Konfiguratora API (epik APIC, ~316-448h, ADR-0022)

> **Rewizja 2026-06-26 (ADR-0022):** pierwotny scope 0.10 (poniżej, tylko strona **producenta** — ApiProfile/ApiKey/webhooki) został wchłonięty przez większy epik **Uniwersalny Konfigurator API** dodający stronę **konsumenta** (PIM woła dowolne zewnętrzne REST/JSON API, oba kierunki, mapowanie 1:1, harmonogram synchronizacji). Konsument = greenfield w `src/Integration/Generic/`; producent = domknięcie zalążka `src/ApiConfigurator/`. Pełen backlog (48 ticketów MVP + 8 hooków, prefix `APIC`, milestone'y M0–M5): **`feature-api-configurator-tickets.md`**. Architektura: `feature-api-configurator-uniwersalny-plan.md`. Decyzja: ADR-0022 (`docs/adr/0022-...md`). Designy UI: `Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/integracje/api-*.jsx`. Pozycje 0.10.1–0.10.6 poniżej mapują się na tickety producenta M4 (APIC-P4-03..08).

> **Po ADR-009:** API Profile filtruje per `object_type_id` + per atrybut. Klient B2B może mieć profil "Storefront" widzący tylko `kind='product'` z wybranymi atrybutami; profil "SiteMap" widzący tylko `kind='category'` z polami SEO. Estymacja bez zmian.

- 0.10.1 Encja ApiProfile + ApiKey z scopes; `ApiProfile.object_types` JSONB z listą `object_type_id` widocznych przez ten profil.
- 0.10.2 UI w admin: lista profiles, create, edit; wybór ObjectType do publikacji per profile.
- 0.10.3 UI: wybór atrybutów do publikacji per profile (filtrowanych do tych przypisanych do wybranych ObjectType) + format output.
- 0.10.4 UI: webhook configuration (event → URL); eventy filtrowane per ObjectType.
- 0.10.5 Backend: ApiProfileVoter wpinający się w API Platform serializer context, filtruje response per `object_type_id` + per atrybut.
- 0.10.6 Endpoint testowy /api/profiles/{code}/test pokazujący przykładową odpowiedź dla każdego ObjectType w profilu.

#### Epik 0.11: Hardening, a11y, analityka i testy (24-34h, rozszerzony po review DeepSeek)

- 0.11.1 2FA dla admin (TOTP via scheb/2fa-bundle).
- 0.11.2 Rate limiting na auth endpoints (anti-bruteforce).
- 0.11.3 Security headers via Caddy (CSP, HSTS, X-Frame-Options, etc.).
- 0.11.4 Audit log MVP (DoctrineAuditBundle aktywny dla wszystkich obiektów `Object` — produkty, kategorie, custom kindy w Fazie 2; nie hardcoded `Product`). Dla `ObjectType` i `Attribute` audit dodatkowy (zmiany schematu są szczególnie krytyczne).
- 0.11.5 Pełna suite Playwright E2E (uzupełniona o nowe ścieżki — bazowy E2E test rusza już w Sprint 0): login, create product, edit attribute, **edycja kategorii z atrybutami niestandardowymi (SEO, image)** (proof of ADR-009), multi-tenant izolacja smoke. **Sync to BaseLinker / Shopify w Fazie 1** (rewizja 2026-04-27). **Run agent w Fazie 2** (rewizja 2026-04-27).
- 0.11.6 **PHPUnit + `ApiTestCase`** testy dla głównych endpointów API (auth, products CRUD, search, agent run, integration sync) + property-based tests dla logiki domenowej krytycznej (completeness rules).
- 0.11.7 Composer audit + npm audit w CI + dependabot config.
- 0.11.8 README, **runbook DR (PITR przez pgBackRest, rotacja kluczy LLM, BYOK provisioning)**, CONTRIBUTING.
- **0.11.9 (NOWE) WCAG 2.1 AA accessibility review głównych ścieżek admin (4-6h):** keyboard navigation full coverage, kontrast wszystkich elementów, aria labels na ikonach, focus management w modalach (Cmd+K, schema diff, approval). shadcn/ui na Radix daje solidną bazę za darmo, ale customowe komponenty (dynamiczne formy atrybutów, agent panel) wymagają walidacji axe-core w CI + manualnego sprawdzenia z czytnikiem (NVDA na Windows, VoiceOver na macOS).
- **0.11.10 (NOWE) Prosty dashboard analityczny dla operatora (6-8h):** strona startowa admina pokazuje: liczba produktów / atrybutów / rodzin / kategorii (live z DB), status ostatnich 5 synchronizacji per integration (BaseLinker, Shopify) z kolorowym statusem, prosty wykres "produkty dodane/zmodyfikowane w ostatnich 30 dniach" (Recharts lub Chart.js), liczba pending changes w agent inbox, status backupów (last successful, RPO indicator). To znacząco podnosi wartość demo i odpowiada na pytanie pilota "skąd wiem co się dzieje?".
- **0.11.11 (NOWE) pgBackRest + WAL archiving production setup (4-6h):** już w Sprint 0 zrobiliśmy stub w docker-compose (sekcja 0.0.15) — tutaj dochodzą: konfiguracja pełnych backupów co tygodnia + differential codziennie + WAL co 5 min, retention policy, automatyczny weekly restore test (cron job + Slack alert), runbook PITR. Dla pierwszego pilota różnica RPO 24h → 5min jest argumentem sprzedażowym.
- **0.11.12 (NOWE) BYOK (Bring Your Own Key) implementacja dla agenta (4-6h):** pole "Anthropic API Key" w konfiguracji tenanta, szyfrowane AES-256-GCM przy zapisie, runtime resolver wybiera klucz tenanta (jeśli BYOK włączony) lub klucz platformy (default). UI dla admina z testem klucza ("zrób próbny call do Anthropic, pokaż balans tokenów"). Mitiguje główne ryzyko biznesowe (R-27 — wyciek/abuse klucza platformy → kosztownej faktury).

### 3.4 Milestones MVP

**Uwaga (poprawione po review DeepSeek):** "Faza 0" obejmuje **Sprint 0 (40-55h) + MVP Core (epiki 0.1–0.11)**. W tabeli poniżej cumulative h dotyczy MVP Core (od końca Sprintu 0). Total Faza 0 = Sprint 0 + ostatni wiersz tabeli.

| ID | Milestone | Kryterium odbioru | Cumulative h (MVP Core) |
|---|---|---|---|
| M0.A | Infrastructure ready (z monorepo Turborepo) | `pnpm dev` startuje cały stack, baseline CI green | 16-22 |
| M0.B | Auth + tenants | Można zalogować się, użytkownik widzi swój tenant, smoke test izolacji passing | 26-36 |
| M0.C | Domain model + API CRUD (po ADR-009: ObjectType + Object + ObjectValue) | Wszystkie encje Catalog mają działające API CRUD; predefiniowane ObjectType fixtures aktywne; categories mają user-defined SEO atrybuty | 72-100 |
| M0.D | Search działa (per-kind indeksy) | Endpoint /search/products i /search/categories zwracają wyniki w <500ms na 50k testowych SKU | 78-108 |
| M0.E | Admin UI core | Można w UI: utworzyć ObjectType (predefined locked + custom locked-Faza2), atrybut, produkt, kategorię z user-defined polami; przypisać kategorie | 98-134 |
| M0.F | (Faza 2 — agent schema-add) | przeniesione do Fazy 2 (rewizja 2026-04-27) | — |
| M0.G | (Faza 1 — BaseLinker) | przeniesione do Fazy 1 (rewizja 2026-04-27) | — |
| M0.H | (Faza 1 — Shopify) | przeniesione do Fazy 1 (rewizja 2026-04-27) | — |
| M0.I | API Configurator | Klient zewnętrzny może zalogować się kluczem i pobrać dane (per-profile filter z `object_type_id` + cache + rate limit) | 106-146 |
| M0.J | (Faza 2 — agent UX full) | przeniesione do Fazy 2 (rewizja 2026-04-27) | — |
| M0.K | Hardening + a11y + analytics + backup | E2E green, WCAG AA pass, dashboard działa, pgBackRest restore test pass | 132-180 |
| M0.L | MVP Done | Wszystkie milestone'y zaliczone, deployment u pilota możliwy | 132-180 |

**Estymacje (po rewizji 2026-04-27 + ADR-009; **single source of truth — sumy epików §3.3 + milestone tabela §3.4**):**
- **Sprint 0:** 40-55h (rozszerzony o Playwright od dnia 1, RLS smoke, perf profile, monorepo, FrankenPHP 2.x walidacja)
- **MVP-Alpha (epiki 0.1+0.2+0.3+0.4+0.5+0.6):** 16-22 + 10-14 + 36-50 + 10-14 + 6-8 + 20-26 = **98-134h**
- **MVP-Final (epiki 0.10+0.11):** 8-12 + 24-34 = **32-46h**
- **MVP Core (MVP-Alpha + MVP-Final):** **130-180h**
- **Faza 0 total (Sprint 0 + MVP Core):** **170-235h**

Skąd zmiana względem poprzedniej estymacji 201-274h:
- Epiki 0.7 + 0.8 + 0.9 przeniesione do Fazy 1/2: **-51-69h** (rewizja 2026-04-27 — 0.7 25-35h + 0.8 12-16h + 0.9 14-18h)
- Epik 0.3 rozszerzony o ADR-009 (generic ObjectType): **+20-30h** (z 16-20h do 36-50h; różnica netto)
- Epik 0.6 ticket #54 trim (Cmd+K placeholder usunięty): **~ -1h** (w granicach noise estymaty epika)
- Saldo netto: **-31 do -39h** względem 201-274h, wynik: **170-235h**

**Tryb okrojonego MVP** (jeśli budżet czasu jest twardszy niż jakość):

| Skrót | Oszczędność |
|---|---|
| Epic 0.10 API Configurator do 1 profile testowy, bez webhooków | -4h |
| Epic 0.11 bez analytics dashboard | -6-8h |
| Epic 0.11 bez BYOK (zostaje w fazie 1) | -4-6h |
| ~~Bez generic ObjectType (hard-coded Product/Category/Asset)~~ | **NIEAKCEPTOWALNE** |

**Wariant „bez generic ObjectType" — odradzany.** Pozorna oszczędność -16-25h w epiku 0.3 jest złudna: tracimy wszystkie korzyści ADR-009 (kategorie z own user-defined atrybutami, blokowanie importu z PIMCore, możliwość dodania `Customer`/`Supplier` w Fazie 2 bez migracji DDL). Klient pilotażowy z `03-funkcjonalnosci-mvp.md` (B2B technical, kategorie hierarchiczne z SEO i obrazami) nie zaakceptuje hard-coded `Category` z trzema polami. Każdy custom kind dorobiony post-factum to 8-12h migracji DDL + dataport — szybko zjada zaoszczędzone godziny. **Nie skracamy tutaj.**

Tak okrojony MVP: **116-161h** MVP Core (130-180 minus 14-19h skrótów) + Sprint 0 (40-55h) = **156-216h** total dla fazy 0. Akceptowalne dla pierwszego pilota; pełen MVP-Final z analytics + a11y to wersja "dla demo i sprzedaży".

#### Grupowanie w sub-fazy MVP — Alpha / Final (2 sub-fazy po rewizji 2026-04-27)

> **Po rewizji 2026-04-27** (`06-sprint-0-findings.md` §2): epik 0.7 (Agent layer Beta-Min + Beta-Full) przeniesiony do Fazy 2, epiki 0.8 (BaseLinker) + 0.9 (Shopify) przeniesione do Fazy 1. MVP redukuje się do **2 sub-faz**: Alpha (backend + admin) i Final (API config + hardening). Gate decision po każdej.

**MVP-Alpha — Backend + API + admin core CRUD (epiki 0.1–0.6, 98-134h po ADR-009)**
Cel: działający backend z pełnym domain modelem **opartym o generic `ObjectType`** (predefiniowane Product/Category/Asset jako built-in fixtures), REST/GraphQL API z sugar paths `/api/products`, `/api/categories`, `/api/assets`, podstawowy admin Refine z CRUD dla wszystkich predefined kindów (kategorie z user-defined SEO/image atrybutami). Gate: API responses < cele wydajnościowe na 50k SKU testowych, admin pozwala na pełen workflow operatora dla produktów + kategorii bez agenta.

**MVP-Final — API Configurator + hardening + a11y + analytics (epiki 0.10–0.11, 32-46h)**
Cel: API Configurator z filtrowaniem per `object_type_id`, smoke testy E2E green, WCAG AA pass, dashboard, pgBackRest production, BYOK reservation (sam mechanizm szyfrowania klucza, konsumpcja klucza dochodzi w Fazie 2). Gate: deployment u pilota możliwy.

Każda sub-faza powinna kończyć się **5-minutowym screencastem demo** dla samego siebie/inwestorów. Nawet jeśli jest jeden odbiorca — dyscyplinowane demo na koniec sub-fazy wymusza realne ukończenie.

### 3.5 Sequencing zalecony

```
Tydzień 1-2:  Epik 0.1, 0.2 (infrastructure + auth)
Tydzień 3-5:  Epik 0.3, 0.4 (domain model + API)
Tydzień 5-6:  Epik 0.5 (search)
Tydzień 6-9:  Epik 0.6 (admin UI core)
Tydzień 9-11: Epik 0.7 (agent layer)
Tydzień 11-13: Epik 0.8, 0.9 (integracje BaseLinker + Shopify)
Tydzień 13-14: Epik 0.10, 0.11 (API config + hardening)
Tydzień 14-16: Epik 0.12 / UI-08 (Modelowanie — patrz §3.6)
Tydzień 16-19: Epik 0.13 / UI-09 (Imports MVP — patrz §3.7)
```

Założenie: 10h pracy człowieka tygodniowo (część etatu). Przy pełnym etacie (40h/tydzień) → MVP w 4-5 tygodni.

### 3.6 Epik 0.12 / UI-08 — Modelowanie (post-MVP-Final, pre-Faza 1) — DODANY 2026-05-01

Pierwszy epik napędzany **planem UI** (`Project Plan/UI/`) zamiast backend roadmapy. Definiuje zakładkę „Modelowanie" w admin UI (Object Types / Attributes / Attribute Groups / Categories — 4 sub-taby) + wprowadza **Attribute Group jako first-class entity** (ADR-012) i `EffectiveAttributeGroupResolver` z dziedziczeniem po drzewie kategorii — funkcjonalność, której Akeneo i Pimcore nie mają natywnie.

**Tracking:** GitHub label `epik-UI-08` + cross-cutting tag `UI`. Plan szczegółowy: [`Project Plan/UI/epik-08-modelowanie.md`](UI/epik-08-modelowanie.md) (~960 linii).

**Sequencing:** wchodzi **po MVP-Final (epik 0.11)**, **przed Fazą 1**. Spójne z notą `Project Plan/UI/epik-08-modelowanie.md` §15 (+42-56h impact na Faza 0). Total estymacja epiku: **60-80h** (różnica vs §15 wynika z atomowego ujęcia całości w jeden epik zamiast rozproszenia po 0.3/0.6).

**Backlog (16 issues):**

| # | Ticket | Tagi | Estymacja |
|---|---|---|---|
| **#255** | META — reorganizacja sidebar (zwijana sekcja „Modelowanie") | UI, frontend, must-have | 2-4h |
| **#256** | UI-08.1 ADR-012 + migracje DDL + Doctrine entities | UI, backend, blocker, docs, must-have | 4-6h |
| **#257** | UI-08.2 ObjectType built-in flags + Brand seed (4-ty built-in) | UI, backend, must-have | 2-3h |
| ✅ **#258** | UI-08.3 System attributes + auto-attach Audit group | UI, backend, must-have | 2-3h |
| ✅ **#259** | UI-08.4 EffectiveAttributeGroupResolver + form-schema endpoint + Redis cache | UI, backend, must-have | 4-6h |
| ✅ **#260** | UI-08.5 ApiResource AttributeGroup CRUD + CQRS handlers + voter | UI, backend, must-have | 3-4h |
| ✅ **#261** | UI-08.6 Attribute migrate-type endpoint (mapping plan + dry-run) | UI, backend, must-have | 4-5h |
| ✅ **#262** | UI-08.7 Where-used endpoints (attributes / groups / object_types) | UI, backend, must-have | 2-3h |
| ✅ **#263** | UI-08.8 visible_when storage + evaluator (MVP: equals) | UI, backend, must-have | 3-4h |
| ✅ **#264** | UI-08.9 Modeling layout shell + 4-tab routing + back-compat redirects | UI, frontend, must-have | 2-3h |
| ✅ **#265** | UI-08.10 Sub-tab Object Types — list + detail + Create wizard | UI, frontend, must-have | 6-8h |
| ✅ **#266** | UI-08.11 Sub-tab Attributes — enhanced list + detail + Where-used | UI, frontend, must-have | 4-5h |
| ✅ **#267** | UI-08.12 Migration impact analyzer modal | UI, frontend, must-have | 4-5h |
| ✅ **#268** | UI-08.13 Sub-tab Attribute Groups — drag-drop + VisibleWhen editor | UI, frontend, must-have | 5-7h |
| ✅ **#269** | UI-08.14 Sub-tab Categories modeling — tree + inheritance preview | UI, frontend, must-have | 5-7h |
| ⏭️ **#270** | UI-08.15 Bulk import atrybutów z CSV (US-MOD-008) — *deferred Faza 1* | UI, frontend, optional | 3-4h |

**Dependency graph:** wszystkie sub-tickety blokowane przez `#256` (UI-08.1 ADR-012 + migracje DDL). Frontend tickety dodatkowo blokowane przez `#264` (UI-08.9 layout shell), który wymaga `#259` (form-schema endpoint).

**Persona główna:** Adam (NEW) — Architekt informacji, 35-45 lat, używa raz na 1-2 tygodnie. W mniejszych firmach Marcin/Kasia są w roli Adama. **MVP: brak role gating** (każdy zalogowany user ma full access do Modelowania), permissions deferred do Fazy 1 (kandydat ADR-013).

**Zaktualizowana wycena (sekcja 7):** total Faza 0 + Faza 1 + Faza 2 dochodzi 60-80h (epik 0.12 / UI-08 dodatkowo do baseline).

### 3.7 Epik 0.13 / UI-09 — Imports MVP (post-Modelowanie, pre-Faza 1) — DODANY 2026-05-06

Self-service import produktów z plików **Excel/CSV** + opcjonalnie zdjęcia (linki HTTP lub ZIP). 4-step wizard (upload → mapping → walidacja → confirm) z rules-based dictionary PL/EN auto-mapping (~30 atrybutów × 5-10 synonimów), async via Symfony Messenger z progress przez Mercure SSE, opcjonalny manual pgBackRest snapshot, soft rollback w 24h window, profile importu (smart memory) per user. **Out of scope MVP:** UPDATE istniejących produktów, recurring imports, AI auto-mapping, multi-locale w jednym pliku, variants z wide format.

**Tracking:** GitHub label `epik-UI-09` + cross-cutting tag `UI`. Plan szczegółowy: [`Project Plan/UI/feature-imports.md`](UI/feature-imports.md) (~780 linii).

**Sequencing:** wchodzi **po Modelowaniu (epik 0.12 / UI-08)**, **przed Fazą 1**. Spec szacuje +78-111h, plan rozkłada na **15 atomowych ticketów / ~124-167h** (bufor na shadcn primitives Stepper/Combobox/DataTable, pgBackRest CLI integration, dogfooding 2k SKU IdoSell, deep-link preserved-state do Modelowania). **Bez nowego ADR** — ADR-006 (AbstractBatchHandler) + ADR-009 (ObjectType `kind=product`) wystarczają.

**Persona główna:** Kasia (Catalog Manager, 32) — uruchamia importy 1-3× w tygodniu (nowy dostawca / nowa kolekcja). Secondary: Magda (Marketing) — opisy SEO. **Dogfooding US-IMP-005:** Marcin migruje katalog IdoSell (~2k SKU) jako pierwszy real-world test (gate przed deklaracją "imports gotowe").

**Backlog (15 issues):**

| # | Ticket | Tagi | Estymacja |
|---|---|---|---|
| **#442** | IMP-01 — Schema + dependencies + entities (Imports MVP) | UI, backend, blocker, must-have | 10-14h |
| **#443** | IMP-02 — File parsing + dictionary auto-mapping service | UI, backend, must-have | 12-16h |
| **#444** | IMP-03 — Validate-dry-run + sync small import (<50 rows) | UI, backend, testing, must-have | 10-14h |
| **#445** | IMP-04 — Async ImportRunHandler + image download + ZIP extract | UI, backend, must-have | 14-18h |
| **#446** | IMP-05 — Rollback + report CSV download | UI, backend, must-have | 6-8h |
| **#447** | IMP-06 — pgBackRest manual snapshot integration | UI, backend, infra, must-have | 6-8h |
| **#448** | IMP-07 — Import profiles CRUD | UI, backend, must-have | 4-6h |
| **#449** | IMP-08 — Frontend foundation: Refine resources + i18n + shadcn primitives | UI, frontend, must-have | 8-10h |
| **#450** | IMP-09 — Imports list view (5.1) + sub-tab Publikacje | UI, frontend, a11y, must-have | 8-10h |
| **#451** | IMP-10 — Step 1 (Upload) + Step 2 (Mapping) | UI, frontend, a11y, must-have | 14-18h |
| **#452** | IMP-11 — Step 3 (Validation) + Step 4 (Confirm) + Backup | UI, frontend, a11y, must-have | 10-12h |
| **#453** | IMP-12 — Import in progress + results + rollback UI | UI, frontend, a11y, must-have | 8-12h |
| **#454** | IMP-13 — Profile manager modal | UI, frontend, a11y, must-have | 4-6h |
| **#455** | IMP-14 — E2E suite + smoke + dogfooding (US-IMP-005 IdoSell) | UI, testing, must-have | 8-12h |
| **#456** | IMP-15 — Plan/PRD updates + R-30 ryzyko + epik 04 link | UI, docs | 2-3h |

**Dependency graph:** IMP-01 (#442) blokuje wszystko. IMP-02..03 (#443-#444) blokują IMP-04 (#445). IMP-04 + IMP-07 (#445, #448) blokują frontend foundation IMP-08 (#449). IMP-09..13 (#450-#454) idą po IMP-08 (#449) — można w 2-3 równoległych workstreamach. IMP-14 (#455) wymaga IMP-12 (#453) zamknięte. IMP-15 (#456) idzie ostatnia (reflectuje dogfooding findings).

**Acceptance gate epiku:** smoke test pełnego flow na żywym backendzie (login → /publications/imports → upload festo-q2-2026.xlsx → mapping → validation → confirm + backup → progress live → results → rollback w window → 0 produktów). Console clean + Network 200/201 + axe-core green na każdym z 8 ekranów. Performance: import 5000 rows fixture → `frankenphp_worker_memory_bytes` peak < 256 MB w Prometheus. Bez tego epik **nie jest done** w sensie CLAUDE.md SMOKE TEST RULE.

**Wpływ na wycenę (sekcja 7):** total Faza 0 dochodzi **+124-167h** (epik 0.13 / UI-09 dodatkowo do baseline 60-80h epiku 0.12). Operator akceptuje wzrost scope (PRD §12.1 dopuszcza +50-80h nadwyżki) — ale realna nadwyżka nad budżetem MVP-Final to ~20-50h, blisko limitu. Mitigacja: rozważyć przesunięcie advanced features (recurring imports, AI auto-mapping, custom validation cross-attribute) na Fazę 1 — patrz **R-30**.

**Status epiku — 2026-05-07: ZAMKNIĘTY.** 15 ticketów IMP-01..IMP-15 (#442-#456) zmergowanych do `main` w marathon mode 2026-05-06/07. Pełen delivery snapshot: [`Project Plan/UI/feature-imports.md`](UI/feature-imports.md) §13. **Świadome odejścia** (follow-up'y, nie blokują zamknięcia epiku):
- **IMP-04** — image download + ZIP extract handler dispatchowane, ale realnego download'u nie wykonują (6-8h follow-up).
- **IMP-14** — dogfooding US-IMP-005 (~2k SKU IdoSell) odsunięte; gate przed *„imports gotowe na pierwszy real-world"* otwarty.
- **IMP-14** — Playwright suite trzymana jako 1 smoke spec; rozbudowa do 6 spec'ów (z planu) razem z dogfooding'iem.
- **IMP-14** — performance benchmark 5k rows < 256 MB pomijany bez 5k fixture.

**Maintenance ticket due** (per `CLAUDE.md` "Zarządzanie zależnościami" — co 2 epiki): epik 0.12 / UI-08 (Modelowanie) → epik 0.13 / UI-09 (Imports) zamknięte → następny `composer outdated` + `pnpm outdated` patch run zalecany przed startem kolejnego epiku.

### 3.8 Epik UI-11 — Importy redesign (post-MVP UX hardening) — DOMKNIĘTY 2026-05-12

**Tracking:** GitHub label `epik-UI-11` + cross-cutting tag `UI`, `view-first`. Plan szczegółowy: [`~/.claude/plans/nifty-exploring-dolphin.md`](../.claude/plans/nifty-exploring-dolphin.md) (plan-mode artifact) + pliki ticketów `Project Plan/UI/Wdrozenie_grafiki/ticket-VIEW-IMP-NN-*.md`.

**Cel:** Przeprojektowanie zakładki Importu w sekcji Integracje na **tabbed hub z 4 zakładkami** (Sesje, Profile, Źródła, Harmonogram) wg designu `Zrodla/Front_Claude_Design/PIM-nowoczesny/integracje/` + refactor wizardu `Nowy import`. Dodatkowo: nowa funkcjonalność BE — ImportSource (manual + folder probe) + ImportSchedule (cron parsing + manual run-now).

**Bloker (osobny PR):**
- [x] **IMP-16** ([#494](https://github.com/malipie/PIM/pull/494) → `2a4b99c`) — category assignment from import (`__category__` mapping target).

**Zakres:**
- [x] **VIEW-IMP-00** ([#495](https://github.com/malipie/PIM/pull/495) → `b63e1f3`) — foundation: tab container + 10 reusable prymitywów (ModeBadge, StatusPill, SourceIcon, Sparkline, ResultBar, ProgressBar, StagePipeline, HealthDot, FormatPill, TinyKpi).
- [x] **VIEW-IMP-01** ([#497](https://github.com/malipie/PIM/pull/497) → `07fa5a5`) — Sesje overhaul: KpiStrip + LiveSessionCard + HistoryTable. BE: `GET /api/import-sessions` (Hydra) + `/throughput`.
- [x] **VIEW-IMP-02** ([#499](https://github.com/malipie/PIM/pull/499) → `f2865f0`) — Profile overhaul: grid/list toggle + CRUD + duplicate/export/import JSON. BE: migracja `code`+`mode` + 3 custom controllers.
- [x] **VIEW-IMP-03** ([#501](https://github.com/malipie/PIM/pull/501) → `16261e9`) — Źródła: nowa encja `ImportSource` + 7 transports + Folder real probe + Stub dla pozostałych.
- [x] **VIEW-IMP-04** ([#503](https://github.com/malipie/PIM/pull/503) → `54daaae`) — Harmonogram: nowa encja `ImportSchedule` + cron parser (dragonmantank) + manual run-now + NextRunsTimeline UI.
- [x] **VIEW-IMP-05** ([#505](https://github.com/malipie/PIM/pull/505) → `67eeccf`) — Wizard refactor: WizardStepper z 4 pills + header eyebrow.
- [x] **VIEW-IMP-AUDIT** (#506) — lessons.md update + plan checkboxy + PR summary.

**Świadome odejścia (kandydaci na follow-up):**
- **VIEW-IMP-03.1** — polling daemon dla `ImportSource` (Symfony Scheduler + Messenger handler auto-pickup plików match'ujących `filePattern`) + real SFTP/FTP/HTTP/webhook probes.
- **VIEW-IMP-04.1** — cron worker daemon dla `ImportSchedule` (Symfony Scheduler tick co 60s) + real notification transport (Slack webhook + Email via Mailer + generic webhook).
- **Webhook receiver endpoint** dla `type=webhook` w `ImportSource`.
- **Webhook HMAC signing** dla notification webhooks.
- **modeling-shell.spec.ts flaky root cause** (osobny maintenance ticket) — failowało w 4/6 PR-ach epiku, niezwiązane z V01..V05.

**Wpływ na harmonogram:** ~24h faktyczne marathon vs ~118h estymata mid → 5x szybsze niż plan. Spore polerowanie UX bez wpływu na MVP-Final scope. Follow-up V03.1/V04.1 mogą wejść do Fazy 1 razem z pierwszymi integracjami real-world.

**Maintenance ticket due** (per `CLAUDE.md` "Zarządzanie zależnościami" — co 2 epiki): epik 0.13 / UI-09 (Imports MVP) + 0.14 / UI-10 (PCAT) + UI-11 (Importy redesign) zamknięte → następny `composer outdated` + `pnpm outdated` patch run **należny** przed startem Fazy 1.

## 4. Faza 1 — Integracje (BaseLinker + Shopify) + production-ready

> **Rewizja 2026-04-27:** w nowej kolejności (post-#5) Faza 1 zaczyna się od **integracji BaseLinker (epik 0.8) i Shopify (epik 0.9)** — przeniesionych z MVP. Pełen hardening / RLS / monitoring zostaje w Fazie 1 jako równoległy track. Magento i IdoSell przesunięte do Fazy 2 razem z agentem.

### 4.1 Cele

Pierwsza integracja produkcyjna: katalog z MVP łączy się z BaseLinker i Shopify. Hardening do poziomu produkcyjnego, aktywacja Postgres RLS przed multi-tenantem, pierwsze 2-3 wdrożenia u realnych klientów, monitoring full stack, dokumentacja API publiczna.

### 4.2 Backlog (high-level)

**Track A — Integracje (epiki 0.8 + 0.9, ~100-140h, przeniesione z MVP):**
- **Epik 0.8 BaseLinker (#72-#78)** — bundle, klient HTTP + retry + circuit breaker, adapter PIM→BaseLinker, UI konfiguracji, full-sync CLI + incremental Messenger handler, sync_jobs UI z Mercure live, testy integracyjne na sandbox.
- **Epik 0.9 Shopify (#79-#89)** — bundle (rozszerzenie #8), klient GraphQL Admin API + OAuth, adapter PIM→Shopify (metafields/variants/collections), multi-store (Markets + Plus), UI konfiguracji, bulk sync 250-element batch z AbstractBatchHandler memory safe, sync_jobs UI z `currentlyAvailable` telemetry, webhooks + HMAC verification, **Exponential Backoff jako jedyny throttling** (sekcja 7.3 architektury), testy integracyjne na Partners dev store, telemetria do decyzji o migracji na Bulk Operations + Leaky Bucket.

**Track B — Hardening + RLS + observability (~80-110h):**
- **Postgres RLS aktywacja przed multi-tenantem (16-24h, sekcja 11.1a architektury):** generowanie polityk RLS ze schematów Doctrine, app user bez `BYPASSRLS`, COPY guard, dedykowany pen-test izolacji.
- Bidirectional sync z BaseLinker i Shopify (12-18h).
- DAM transformacje obrazów (Imagick, presety) i lazy loading w UI (8-12h).
- Workflow engine (Symfony Workflow z stanami: draft, review, approved, published) (10-14h).
- Wersjonowanie produktów (history view, restore) (6-10h).
- Public OpenAPI portal (Swagger UI lub Stoplight Elements) (4-6h).
- Performance tuning po profilingu (Tideways/Blackfire) (8-12h).
- Pełna observability stack (Prometheus + Grafana + Loki + Tempo + Sentry) (8-12h).
- Backup automation hardening (rozszerzenie pgBackRest na MinIO replication, multi-region) (4-6h).
- Bezpieczeństwo: penetration test (zewnętrzny, koszt) + remediation (8-12h human time).
- **PHPUnit + ApiTestCase** full coverage głównych user stories (8-12h).
- Documentation: customer-facing docs, integrator handbook (6-10h).
- **Opcjonalnie:** Shopify Bulk Operations migration (jeśli telemetry z MVP wymagają, 4-8h).

### 4.3 Milestones fazy 1

- M1.A: BaseLinker + Shopify w produkcji, sync stabilny dla pierwszego klienta
- M1.B: Bidirectional sync (przynajmniej dla atrybutów konfigurowanych jako bidirectional)
- M1.C: Pełen monitoring stack uruchomiony w środowisku staging
- M1.D: Pierwszy klient produkcyjny żyje w systemie (50k SKU, 5 atrybutów konfiguracji)
- M1.E: Pen-test pozytywny, raport bez krytycznych ryzyk
- M1.F: Public API docs portal dostępny, integratorzy mogą pracować bez kontaktu z developerem
- M1.G: RLS aktywne, audyt izolacji multi-tenant przed wdrożeniami SaaS

## 5. Faza 2 — Agent layer + dodatkowe konektory

> **Rewizja 2026-04-27:** Faza 2 obejmuje teraz całość agentic features (epik 0.7 Beta-Min + Beta-Full przeniesione z MVP) plus konektory **Magento + IdoSell** (też przeniesione z Fazy 1). Hooks runtime (`pending_changes` table, `provenance` enum, lifecycle events) są w MVP, więc kod agenta dochodzi bez migracji danych.

### 5.1 Cele

Dojrzałość agenta — od "schema-add" przez "asystent" do "operator danych". Dodatkowe konektory (Magento, IdoSell) dla klientów spoza ekosystemu BaseLinker/Shopify. Multi-tenant SaaS jako opcja deploymentowa. Pierwsze 10+ wdrożeń.

### 5.2 Backlog (+200-260h)

**Track A — Agent layer baseline (epik 0.7 Beta-Min, ~50-70h, przeniesione z MVP):**
- **#63 Bundle Agent + AgentSession + AgentRun** — orchestration warstwy z lifecycle (start / step / end / error), cost guards inline.
- **#64 Anthropic SDK PHP integration** — system prompt, twarde limity 8.5 (50 tool calls/h/user, 100k tokens/run, $20/dzień/tenant), BudgetService z hardstop'em.
- **#65 Tool definitions** (po ADR-009) — `search_attributes`, `search_object_types`, `create_attribute`, `create_attribute_group`, `assign_attribute_to_object_type` (rename z `assign_attribute_to_family`), `create_object_type` (reserved Faza 2/3, gated feature flagiem), `create_category` (sugar — `Object kind='category'`), `preview_changes` (read-only). `create_family` z poprzedniej iteracji deprecated.
- **#66 Tool execution layer** — walidacja args, Voters per tool, handlers idempotentne, audit events.
- **#67 Pending changes queue** — endpoints approve/reject + TTL 24h + Mercure live updates.
- **#68 UI Cmd+K command bar** — pełna integracja w admin layout (rozszerzenie #54).
- **#69 UI prosty chat panel (Sheet)** — non-streaming, history persistowana.
- **#70 UI prosty preview-changes modal** — accept all / reject all / per-item.
- **#71 Audit logging wszystkich akcji agenta** — append-only, retention 1y.

**Track B — Agent layer rich UX (epik 0.7 Beta-Full, ~13-19h, przeniesione z MVP):**
- **#108 SSE streaming odpowiedzi przez Mercure** (token-by-token).
- **#109 UI chat panel ze streaming + cancel + reconnect**.
- **#110 UI bogaty schema diff modal** — before/after / modify / accept selected.
- **#111 UI agent inbox** — list pending changes + bulk approve + Mercure live.
- **#112 UI provenance badge `agent`** — purple variant + link do agent run + recent changes filter (rozszerzenie #61).

**Track C — Dodatkowe konektory (~30-50h, przeniesione z Fazy 1):**
- **Magento 2 integracja (10-14h)** — REST/GraphQL, attribute set mapping (sekcja 7.4b architektury).
- **IdoSell integration (12-16h)**.
- Marketplace integracje v1 (20-30h): Allegro, WooCommerce.

**Track D — Agent data-ops (~40-60h):**
- Bulk update atrybutów z preview ("dla wszystkich Nike, ustaw kategorię główną").
- Generowanie opisów produktów z atrybutów (LLM tekst).
- Mapowanie kolumn CSV/Excel przy imporcie.
- Translation memory (auto-tłumaczenie nazw na locale).
- Anomaly detection (atrybut wygląda nietypowo dla rodziny).

**Track E — SaaS aktywacja + advanced features (~50-70h):**
- Workflow engine advanced (16-22h): customowe workflow per tenant, approval chains, SLA tracking.
- DAM advanced (16-24h): AI metadata extraction (vision), variants generation, asset library z folder structure.
- Multi-tenant SaaS aktywacja (20-30h): signup flow, tenant subdomeny, billing (Stripe), limity per plan, onboarding wizard.
- Dashboard analytics (12-16h): completeness charts, sync status overview, agent usage stats, top edited products.

### 5.3 Milestones fazy 2

- M2.A: Agent baseline (epik 0.7 Beta-Min) działa — schema-add przez chat z approval flow
- M2.B: Magento + IdoSell w produkcji
- M2.C: Agent data-ops dostępne, prowadzi bulk update z approval
- M2.D: SaaS signup uruchomiony, pierwszy multi-tenant klient
- M2.C: 3 nowe integracje w marketplace
- M2.D: Analytics dashboard z 5+ wykresami

## 6. Faza 3 — Enterprise

### 6.1 Cele

Gotowość do sprzedaży klientom enterprise (>1B PLN obrót), białe etykiety dla partnerów wdrożeniowych, compliance w kierunku ISO/SOC 2.

### 6.2 Backlog (+300h+)

- SSO/SAML 2.0 + LDAP/AD (24-32h)
- White-label theme system + custom domeny (16-24h)
- Audit log advanced (export, retention policies, immutable storage) (8-12h)
- Data residency options (EU, US, custom) (12-18h)
- Compliance documentation (ISO 27001 readiness, SOC 2 Type 1) (40-60h głównie human-time, audit ekspert + remediations)
- Customer portal (kontrakty, faktury, support tickets) (24-32h)
- Advanced syndication (8-12h):
  - PDF datasheets per product
  - Printable catalogs
  - Spec sheets w formatach klienta
- Partner program (16-24h):
  - Partner portal
  - Klucze API z provisioning
  - Revenue sharing
- Performance enterprise tier (16-24h):
  - Read replicas Postgres
  - Postgres partitioning po tenant_id
  - Meilisearch sharding
  - K8s HPA + autoscaling
- Enterprise integrations (na zamówienie):
  - SAP, Microsoft Dynamics, Oracle Netsuite, Comarch ERP — każda 40-80h

## 7. Wycena (wisienka na torcie)

Estymacja godzinowa, jako orientacyjny budżet — nie zobowiązanie. Po rewizji 2026-04-27 (epiki 0.7/0.8/0.9 do Fazy 1/2) i ADR-009 (generic ObjectType, +16-25h w epiku 0.3):

| Faza | Estymacja realnych roboczogodzin człowieka | Kalendarzowo (przy 10h/tydz) | Kalendarzowo (full-time, intensywnie) |
|---|---|---|---|
| Sprint 0 — Vertical Slice (rozszerzony) | 40-55 h | **trzeba zrobić w 1-2 tygodniach urlopu/skupienia, nie dorywczo** | 1-2 tygodnie |
| MVP Core okrojony (116-161h, po ADR-009) | 116-161 h | 12-16 tygodni | 3-4 tygodnie |
| MVP Core pełny (130-180h, po ADR-009) | 130-180 h | 13-18 tygodni | 4-5 tygodni |
| **Faza 0 — okrojony MVP z Sprintem 0** | **156-216 h** | **16-22 tygodnie (4-5 mies. dorywczo)** | **5-6 tygodni full-time** |
| **Faza 0 — pełny MVP z Sprintem 0** | **170-235 h** | **17-24 tygodnie (5-6 mies. dorywczo)** | **5-6 tygodni full-time** |
| Faza 1 — Production-ready (BaseLinker + Shopify + RLS aktywacja + hardening) | 100-140 h | 10-14 tygodni | 3 tygodnie |
| Faza 2 — Agent layer + Magento + IdoSell + Agentic Pro + custom ObjectType odblokowany | 200-260 h | 20-26 tygodni | 5-7 tygodni |
| Faza 3 — Enterprise (do v1) | 300+ h | 30+ tygodni | 8+ tygodni |

**Disclaimer mnożnika produktywności (sekcja 2.1):** powyższe estymacje zakładają, że operator ma **podstawową umiejętność czytania kodu** (PHP/TypeScript) i potrafi rozpoznać typowe klasy błędów LLM. Dla pełnego non-codera bez doświadczenia ani mentora — dolicz **+30-50% buforu** do wszystkich pozycji.

**Tryb realizacji:** mocno rekomendowane jest, aby Sprint 0 był wykonany w **trybie skupienia 1-2 tygodni intensywnej pracy** (urlop, blok kalendarzowy), nie dorywczo. Reszta MVP może być rozłożona, ale długi timeline (5-7 miesięcy dorywczo) niesie ryzyko stack drift (R-26) — dlatego co 2 epiki zalecany "maintenance ticket" (1-2h) aktualizujący patche.

**Wartość biznesowa po każdej fazie:**

- Po MVP: produkt do demo i pilotażu — można pokazać, ale nie sprzedać enterprise.
- Po fazie 1: sprzedawalny SMB i mid-market, można oferować jako "Pimcore-light".
- Po fazie 2: konkurencyjny z Akeneo SaaS dla firm ~50-500 SKU.
- Po fazie 3: sprzedawalny do enterprise, white-label dla partnerów wdrożeniowych.

## 8. Rejestr ryzyk

### 8.1 Ryzyka projektowe (faza 0-1)

| ID | Ryzyko | Prawdopodobieństwo | Wpływ | Mitygacja |
|---|---|---|---|---|
| R-01 | Zakres MVP rozdęty, niedotrzymanie 156-235h Fazy 0 | Wysokie | Średni | Twardy backlog cut z opcją okrojonego MVP (116-161h MVP Core), epik 0.7 Agent przeniesiony do Fazy 2, epiki 0.8 BaseLinker + 0.9 Shopify do Fazy 1 (rewizja 2026-04-27), gate decision po Sprincie 0 z konkretnymi metrykami (sekcja 3.0), 2 sub-fazy MVP-Alpha/Final z możliwością early stopping |
| R-02 | Claude Code generuje kod niskiej jakości w wybranym stacku | Niskie | Wysoki | Code review każdego PR, PHPStan max + Psalm w CI, refactoring sessions co 2-3 epiki |
| R-03 | Człowiek (non-expert) nie radzi sobie z review LLM-generated code | Średnie | Wysoki | Zaczynamy od epików prostszych (infra, auth), escalujemy złożoność stopniowo; w razie blokad — pair-programming z mentorem (10-20h sponsor zewnętrzny) |
| R-04 | API Platform za mało elastyczne dla customowych endpointów PIM | Niskie | Średni | Backup plan: dodać "warstwę kontrolerów" obok ApiResource; kontrolery klasyczne Symfony zawsze możliwe |
| R-05 | Meilisearch nieadekwatny dla 200k+ SKU z complex faceting | Średnie | Średni | Abstrakcja w warstwie SearchProvider, fallback do Elasticsearch w fazie 1 jeśli benchmark wskaże |
| R-06 | Wybór Refine.dev okazuje się ograniczeniem dla custom UX agenta | Niskie | Średni | shadcn/ui jest niezależny od Refine; w razie potrzeby można porzucić Refine zachowując UI components |
| R-07 | Performance < oczekiwań na 200k SKU | Średnie | Wysoki | Profiling early (faza 1), FrankenPHP worker mode od początku, second-level cache Doctrine, plan optymalizacji w fazie 1 |
| R-08 | Bezpieczeństwo niespełniające standardów enterprise | Średnie | Wysoki | Pen-test w fazie 1, lista kontrolna OWASP Top 10 weryfikowana per release, security audit zewnętrzny przed pierwszym enterprise klientem |
| R-09 | Postgres RLS źle skonfigurowany przez kod generowany przez LLM → ryzyko cross-tenant data leak po aktywacji multi-tenancy | Średnie | **Krytyczny** | (a) **Mandatorne testy izolacji RLS w CI** — każda migracja dodająca tabelę z `tenant_id` musi mieć integration test sprawdzający izolację (utwórz dane jako tenant A, zaloguj jako tenant B, oczekuj zero wyników). (b) W MVP single-tenant deployment jest pierwszą linią obrony, RLS to defence in depth — realne ryzyko wycieku w MVP znikome (jeden tenant, jedno wdrożenie). (c) Przed aktywacją multi-tenant w fazie 2 — dedykowany pen-test na izolację tenantów. (d) Code review każdej migracji RLS przez człowieka, nie tylko LLM-generated diff. |
| R-25 | **FrankenPHP worker mode + Doctrine memory leaks** — brak `EntityManager::clear()` w workerach Messengera prowadzi do OOM przy bulk imporcie 50k SKU | Wysokie (gdy Claude pisze handlery bez jawnej dyscypliny) | **Krytyczny** | (a) Sekcja 3.10 architektury z twardymi wytycznymi memory management. (b) `AbstractBatchHandler` jako baza dla wszystkich Messenger handlerów — wymusza `clear()`. (c) Custom PHPStan rule blokująca handlery flushujące w pętli bez `clear()`. (d) Prometheus alert na `frankenphp_worker_memory_bytes > 256MB`. (e) Benchmark memory w Sprincie 0 (sekcja 0.0.13). (f) System prompt Claude Code wymusza pattern. |
| R-26 | **Stack drift przy długim timeline MVP** — przy 10h/tydzień MVP zajmie 5-7 miesięcy; w międzyczasie aktualizują się Refine, API Platform, Anthropic SDK, Symfony patches → ciągłe rozjeżdżanie kontekstu i konflikty wersji | Wysokie | Średni | (a) Sprint 0 wykonany w trybie skupienia 1-2 tygodnie urlopu (sekcja 3.0). (b) Lockfiles ścisłe (composer.lock, pnpm-lock.yaml) z polityką "nie aktualizujemy w trakcie fazy bez powodu". (c) Co 2 epiki: dedykowany "maintenance ticket" (1-2h) który aktualizuje patch versions i sprawdza CI. (d) Renovate / Dependabot z automergem patch tylko, manual review minor/major. (e) Snapshot-based dev environment (Docker images z pinned tags) — łatwy "powrót do tego co działało". |
| R-27 | **Agent cost runaway** — kompromitacja klucza API Anthropic, agent w pętli, abuse usera → faktura $1000-10000 | Niskie | **Krytyczny** | (a) Twarde limity sekcja 8.5 architektury (tool calls/godz, tokens/run, $/dzień, $/miesiąc). (b) BYOK opcja dla enterprise (ticket 0.11.12) — klient płaci za swoje LLM. (c) Org-level monthly cap w Anthropic Console ($1000 dla MVP-prod) — niezależny hardstop. (d) Klucz w Vault z rotacją 90 dni. (e) Anomaly detection (5× wzrost tool calls/h vs średnia tygodniowa → flag). (f) Compromise response runbook. |
| R-28 | **Symfony 7.4 LTS upgrade** — wsparcie do listopada 2028/2029, wymuszony upgrade na kolejny LTS (8.x lub 9.x) | Pewne (w 2-3 lata) | Średni | (a) Already factored — przewidziane w roadmapie fazy 3. (b) API Platform jest stabilny i zwykle podąża za Symfony LTS w 1-2 release. (c) Test upgrade na branch w Q3 2028, full migration ~40-60h. (d) FrankenPHP 2.x worker API śledzimy w Sprintach maintenance. |
| R-29 | **Over-engineering generic ObjectType w MVP** — generalizacja ADR-009 wprowadza pojęcie `kind='custom'` na poziomie modelu, kuszące do rozbudowy w MVP zanim realny pilot tego potrzebuje. Ryzyko: przepalenie 20-40h na UI builder dla custom kindów, schema editor, agent tool `create_object_type` zanim ktoś tego zażąda | Średnie | Średni | (a) **Predefined fixed UX dla Product/Category/Asset w MVP** — sidebar admin pokazuje te trzy jako pierwszej klasy, sekcja "Custom" jest disabled z badge "Faza 2". (b) **Feature flag `enable_custom_object_types`** wyłączony w MVP — service `ObjectTypeService::create()` rzuca `DisabledFeatureException` dla `kind='custom'`. (c) **Tool agenta `create_object_type` zarejestrowany ale wyłączony** w SDK do Fazy 2. (d) **Benchmark `attributes_indexed`** w MVP-Alpha na 10k obiektów × 200 atrybutów × 3 kindach (proof że generic model nie zwalnia query path). (e) **Dyscyplina:** w MVP nie używamy `kind='custom'` nawet gdy silnik to wspiera. (f) **Custom kindy odblokowane w Fazie 2** dopiero gdy realny pilot zażąda (`Customer`, `Supplier`, `PriceList`). |
| R-30 | **Imports MVP scope creep** — feature self-service import (epik 0.13 / UI-09, `Project Plan/UI/feature-imports.md`) szacowany na +78-111h w spec'u, rozłożony na 15 atomowych ticketów ~124-167h. Realna nadwyżka nad budżetem MVP-Final ~20-50h, blisko limitu PRD §12.1 (+50-80h). Ryzyko: rozszerzanie zakresu w trakcie implementacji (recurring imports, AI auto-mapping, custom cross-attribute validation, multi-locale w jednym pliku) zanim Kasia/Marcin użyją MVP wersji w realnym scenariuszu | Średnie | Średni | (a) **Atomowe tickety IMP-01..IMP-15** z explicit DoD per ticket — żaden nie idzie do main bez smoke test'u 5 min na żywym backendzie + axe-core green. (b) **Dogfooding US-IMP-005 jako gate**: Marcin migruje katalog IdoSell (~2k SKU) w ramach IMP-14 (#455) — bez tego dogfooding'u epik nie jest done; bugi z dogfooding'u idą jako follow-up sub-issues, nie w-trakcie expansion. (c) **Out of scope listą explicit w `feature-imports.md` §1**: UPDATE istniejących produktów, recurring imports, AI auto-mapping, multi-locale w jednym pliku, variants z wide format — wszystkie kandydaci do Fazy 1 z planu, zarezerwowane jako *„advanced features"*. (d) **Rules-based dictionary** zamiast AI auto-mapping (decyzja brainstorming 2026-05-03) — deterministic, free, fast, kandydat na hybrid AI w Fazie 2. (e) **Rolewane scope reviews** po IMP-04 (#445 — backend async core gotowy) i IMP-12 (#453 — frontend full flow gotowy): operator decyduje czy odsuwamy IMP-13 profile manager / IMP-06 pgBackRest UI do Fazy 1 jeśli budżet się kończy. (f) **Sub-tickety z `optional` label** dla advanced features — nie blokują merge'u epiku, idą do Fazy 1 backlog'a (kandydaci: webhook integration, per-tenant dictionary extension, recurring cron). |

### 8.2 Ryzyka biznesowe (faza 2+)

| ID | Ryzyko | Prawdopodobieństwo | Wpływ | Mitygacja |
|---|---|---|---|---|
| R-10 | Konkurencja: Akeneo Cloud / PIMcore SaaS oferuje agresywne pricing | Wysokie | Wysoki | Wyróżnik agentic-first, skupienie na rynku PL/CEE z lokalnym customer success, niższa cena startowa |
| R-11 | Koszty LLM API rosną szybciej niż przychody | Średnie | Wysoki | Limity per-tenant w MVP, model routing (Sonnet domyślnie, Opus tylko dla schema-ops), opcja BYOK (klient dostarcza własny klucz API) |
| R-12 | Anthropic zmienia ceny / wycofuje model | Średnie | Średni | Abstrakcja LLMProvider z fallbackiem na OpenAI/Mistral w fazie 2 |
| R-13 | Klient enterprise wymaga on-prem deployment z auditem kodu | Wysokie | Średni | Stack 100% open source, brak BSL, on-prem deployment od dnia 0 jest scenariuszem podstawowym |
| R-14 | Wymagania ISO/SOC 2 odsuwają sprzedaż enterprise o 6-12 miesięcy | Wysokie | Wysoki | Faza 3 zaplanowana z compliance w scope, czas + budżet na audyt zewnętrzny |
| R-15 | Brak partnerów wdrożeniowych = wąskie gardło sprzedażowe | Wysokie | Wysoki | Partner program w fazie 3, dokumentacja jak dla zewnętrznych developerów od fazy 1, certyfikacje partnerów |

### 8.3 Ryzyka techniczne długoterminowe

| ID | Ryzyko | Prawdopodobieństwo | Wpływ | Mitygacja |
|---|---|---|---|---|
| R-20 | Symfony 7 wycofany na rzecz Symfony 8/9 — nadciąga wymuszony upgrade | Wysokie (w 5-7 lat) | Średni | LTS migrations co 2 lata, plan budżetu na 1-2 sprinty migracji co LTS |
| R-21 | PostgreSQL przestaje wspierać używaną wersję | Średnie (5+ lat) | Średni | Plan upgrade Postgres co 3-5 lat, backups/restore testowane przed każdą migracją |
| R-22 | Meilisearch zmienia licencję na BSL/SSPL | Niskie | Średni | Abstrakcja SearchProvider, możliwość swap na Typesense (MIT) lub Elasticsearch |
| R-23 | shadcn/ui przestaje być utrzymywane | Niskie | Niski | Komponenty są w naszym repo (kopiowane, nie importowane), utrzymujemy samodzielnie jeśli trzeba |
| R-24 | Refine.dev rozwija się w niepożądanym kierunku | Średnie | Niski | Refine to cienka warstwa hooków; w ostateczności zastąpimy własnymi hookami (~30-40h pracy) |

## 9. Wymagania zespołu

### 9.1 Faza 0 — MVP

- 1 product owner / operator (autor: Marcin) — pełna alokacja w czasie pracy nad MVP, ~10-40h/tydz
- Claude Code jako virtual senior developer (instance subscriber, Claude Code IDE integration)
- (Opcjonalnie) Mentor zewnętrzny / sparring partner — 10-20h support na pair-programming i decyzje techniczne, gdy product owner natrafia na blokady
- (Opcjonalnie) UX designer — 10h dla pierwszego wireframe agentic-first UX i system designu w shadcn

### 9.2 Faza 1 — Production-ready

- Powyższy zespół + 1 senior PHP developer (Symfony) na 0.25 etatu — code review, performance tuning, security
- Pen-tester zewnętrzny (kontraktowy, 1 audit)
- DevOps support — provider chmury / firma hostingowa (np. ovh, hetzner, mikr.us)

### 9.3 Faza 2-3

- Pełnoetatowy backend developer
- Pełnoetatowy frontend developer
- Designer (kontraktowy lub na pół etatu)
- Sales / customer success (gdy będzie pierwsza paczka klientów SaaS)
- (W fazie 3) Compliance officer / konsultant ISO/SOC 2

## 10. Plan komunikacji i zarządzania

### 10.1 Sprinty

Praca w 2-tygodniowych sprintach. Każdy sprint zamyka 2-3 ticketów z backlogu MVP. Demo na koniec sprintu (nawet do "samego siebie") — nagranie 5-10min screencast pokazujący co zostało zrobione.

### 10.2 Decyzje architektoniczne

Każda decyzja, która wpływa na architekturę (ADR-eligible), jest dokumentowana jako nowy wpis w ADR sekcji `01-architektura-pim.md`. Format: kontekst, opcje, decyzja, konsekwencje.

### 10.3 Backlog grooming

Co tydzień przegląd backlogu: re-priorytetyzacja, doprecyzowanie ticketów, identyfikacja blokad. Przy zmianach scope — aktualizacja tego dokumentu.

### 10.4 Stakeholder updates

Po każdej fazie — krótki update (1-2 strony) dla potencjalnych inwestorów / partnerów / pierwszych klientów: co zrobiono, co dalej, kiedy demo.

## 11. Lista deliverables fazy koncepcyjnej (zamknięcie)

Faza koncepcyjna kończy się dwoma dokumentami:

1. **`01-architektura-pim.md`** — pełna architektura, stack, model danych, integracje, agent, security, performance, ADR.
2. **`02-plan-projektu-pim.md`** — ten dokument: fazy, milestones, backlog, ryzyka, wycena.

Następne deliverables (faza implementacji, w trakcie produkcji):

- `03-api-spec.openapi.yaml` — formalna specyfikacja API generowana z API Platform.
- `04-data-dictionary.md` — słownik pól domenowych z opisami biznesowymi.
- `05-runbook.md` — procedury operacyjne (deploy, backup, restore, troubleshooting).
- `06-customer-handbook.md` — dokumentacja dla klientów wdrożeniowych.
- `07-integrator-handbook.md` — dokumentacja dla developerów integracji.

## 12. Kryteria sukcesu projektu

Projekt uznajemy za sukces, jeśli:

**Po fazie 0 (MVP):**
- Działa i jest deployowalny u klienta pilotażowego.
- Agent layer zachwyca w demo (Cmd+K → "dodaj atrybut" → diff → approve → działa).
- 2 integracje sprawnie syndykują dane.

**Po fazie 1:**
- 2-3 klientów produkcyjnych korzysta dziennie.
- p95 latency < celów z dokumentu architektury.
- Pen-test bez krytycznych ryzyk.

**Po fazie 2:**
- 10+ klientów (mix on-prem i SaaS).
- Pierwszy partner wdrożeniowy zewnętrzny.
- Agentic-first jest tym co kupujący wymieniają jako powód wyboru.

**Po fazie 3:**
- Pierwszy enterprise klient (>500k SKU lub >1B PLN obrotu).
- ISO 27001 lub SOC 2 Type 1 certyfikat lub plan na 12 miesięcy.
- Marketplace integracji 10+ pozycji, kilka od partnerów zewnętrznych.

---

## Epik EXP — Eksport produktów (MVP-Final, 2026-05-15)

PRD: [`PRD/PRD-PIM-exports.md`](PRD/PRD-PIM-exports.md). Feature notatka: [`UI/feature-exports.md`](UI/feature-exports.md).

**Cel:** dwa entry points (kontekstowy z listy + centralny `/integrations/exports/new`) z worker engine + 4-section konfigurator + saved profiles per user + Recent exports history + manual bridge reimport przez `/integrations/imports`.

**Status:** wszystkie 16 ticketów zamknięte single-session marathonem (PR #597..#619).

- [x] **EXP-01 (#580)** — Schema + entities + MinIO bucket. *Implemented via PR #578.*
- [x] **EXP-02 (#581)** — POC IMP kontrakt audit (4/4 FAIL → IMP-16..19).
- [x] **EXP-03 (#582)** — ExportBuilder + ColumnResolver + ValueSerializer.
- [x] **EXP-04 (#583)** — POC `pim:export:benchmark` Console.
- [x] **EXP-05 (#584)** — Sync export endpoint + OpenSpout + CSV.
- [x] **EXP-06 (#585)** — Async ExportJobHandler + Mercure SSE.
- [x] **EXP-07 (#586)** — Profiles CRUD API (5 endpoints).
- [x] **EXP-08 (#587)** — Sessions API + run-from-profile + download (7 endpoints).
- [x] **EXP-09 (#588)** — FE foundation (Refine + routes + tab layout).
- [x] **EXP-10 (#589)** — Two-pane ColumnPicker MVP.
- [x] **EXP-11 (#590)** — Modal kontekstowy z 4 sekcjami.
- [x] **EXP-12 (#591)** — Full-page form reusing ExportModal.
- [x] **EXP-13 (#592)** — Recent exports grid + 5s polling + actions.
- [x] **EXP-14 (#593)** — Saved profiles grid + Run-now + Delete.
- [x] **EXP-15 (#594)** — Hub smoke + dogfooding follow-up plan.
- [x] **EXP-16 (#595)** — Plan / PRD / lessons docs update.

**Plus fix #607** — TenantAuditCommand whitelist `export_logs` (unblock PHPUnit post-EXP-01).

**Follow-up tickety utworzone:**
- IMP-16 (#602) — Variants flat parent_sku w imporcie.
- IMP-17 (#603) — Multi-value pipe-separated parser.
- IMP-18 (#604) — Asset URL → asset_id resolution.
- IMP-19 (#605) — Multi-locale columns (attribute.locale notation).

**Świadome odejścia (marathon trade-offs):**
- ~~BulkActionsToolbar integration dla modalu~~ — zaimplementowane jeszcze w maratonie (#591/#595).
- ~~"Save as profile" submit handler~~ — EXP-18 (#630, PR #635).
- ~~Mercure SSE FE wiring (EXP-13)~~ — EXP-17 (#629, PR #634).
- ~~dnd-kit drag-drop w pickerze (EXP-10)~~ — EXP-19 (#631, PR #636).
- ~~`target_scope=filter` — backend 501~~ — EXP-20 (#632, PR #637).
- Locale + channel sub-toggles w modalu — **Faza 1** (wymaga `/api/tenant/locales` + per-attribute scope dropdown).
- Pełne 5 scenariuszy E2E — 4 z 5 pokryte w EXP-21 (#633), round-trip = **EXP-22** blocked by IMP-16..19.
- Cross-user audit (R-45) + presigned URLs (§11.6) — **Faza 1**.

### 0.10b. Mini-epik EXP-17..21 — Świadome odejścia po maratonie #580-#595 (2026-05-15)

Status: 5/5 zamknięte (PR #634..#638).

- [x] **EXP-17 (#629, PR #634)** — Mercure SSE FE wiring dla Recent exports grid (`useExportSessionsStream`).
- [x] **EXP-18 (#630, PR #635)** — Save-as-profile FE submit (checkbox + name + POST przed eksportem).
- [x] **EXP-19 (#631, PR #636)** — dnd-kit drag-drop reorder w ColumnPicker (↑↓ buttons zachowane jako a11y fallback).
- [x] **EXP-20 (#632, PR #637)** — `target_scope=filter` + FilterDslResolver SQL integration + raw JSON textarea w ExportNewPage.
- [x] **EXP-21 (#633, PR #638)** — E2E Playwright (4/5 scenariuszy: sync from list + save-as-profile + bulk + async mocked) + docstring cleanup + plan/lessons update.

**Follow-up:** EXP-22 round-trip E2E — blocked by IMP-16 (#602), IMP-17 (#603), IMP-18 (#604), IMP-19 (#605).

---

## Epik XMLF — Konfigurator XML (feedy produktowe, Faza 1 — 2026-07-01)

Domknięcie luki zapowiadanej w epik-04 „Feeds" i epiku 0.10 („XML feeds + Google Shopping predef"); PRD eksportów (§6.3) i uniwersalny konfigurator API (§7) świadomie wycięły XML do Fazy 1. Ten epik to realizacja.

**Co:** feedy produktowe XML (Google Shopping / Ceneo / Meta / custom B2B) — szablon + mapowanie atrybutów PIM na węzły XML + filtr asortymentu + scope (locale/kanał/waluta) + harmonogram regeneracji (cron) + stabilny URL z tokenem + monitoring runów; **oraz** XML jako format eksportu ad-hoc (obok XLSX/CSV). Architektura: warstwa „szablon + mapowanie + transformacje + delivery" NAD silnikiem Export (reuse `ExportBuilder`; nowy asocjacyjny `ItemWriter`; pod-obszar `src/Export/Feed/`; delivery pull cache-and-serve przez MinIO + publiczny URL z tokenem).

**Tracking:** GitHub label `epik-XMLF` + milestone'y M0–M6 (#34–#40) + 38 issues #1902–#1939.
- Plan/architektura: [`Project Plan/feature-konfigurator-xml-plan.md`](feature-konfigurator-xml-plan.md).
- Backlog (single source of truth): [`Project Plan/feature-konfigurator-xml-tickets.md`](feature-konfigurator-xml-tickets.md) (38 ticketów, prefix `XMLF`).
- Decyzja: **ADR-0023** (`docs/adr/0023-konfigurator-xml-placement.md`, draft → finalizacja w XMLF-P0-01).
- Designy UI: `Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/Feedy.html` + `feedy/*.jsx`.

**Estymacja:** ~340–470h (7 milestone'ów: M0 fundament + ad-hoc XML · M1 model feedu · M2 descriptor/szablony/silnik · M3 mapowanie/scope/delivery · M4 harmonogram/monitor/preview · M5 UI · M6 hardening). Tryb 2 (ad-hoc XML, M0) dostarcza wartość niezależnie od reszty.

**Sequencing:** Faza 1 (razem z integracjami / po MVP-Final). Bez zależności od agenta (0.7). Reuse silników Export/Channel/Import(schedule)/ApiConfigurator(token) — realnie nowego kodu domenowego niewiele.

---

## Epik WFL — Workflow: maszyna stanów, review, zadania (Faza 1 — 2026-07-10)

Realizacja rezerwacji „Workflow engine (Symfony Workflow z stanami: draft, review, approved, published)" (§4.2 Faza 1 Track B) w pełnym zakresie DoD + częściowe zaciągnięcie Fazy 2 Track E (definicje per tenant za feature flagiem). Domknięcie uśpionej warstwy RBAC: `WorkflowStatePolicy` (#674) + permissiony `workflow.*` czekają podpięte od Phase 3 — ten epik je egzekwuje.

**Co:** komponent `symfony/workflow` (state machine `object_editorial`: `draft → review → published → archived`, marking na `objects.status`) + guardy mapowane na RBAC (PRD §3.8: macierz per-rola, auto-unpublish z audit flagą) + log przejść z komentarzami + gate completeness na publish (per ObjectType, default OFF) + bulk transitions przez maszynę + persystentne notyfikacje (dzwonek) + kolejka „Do przeglądu" (sidebar Workflow przestaje być `comingSoon`) + zadania z przypisaniem/due date („Moje zadania" + widget dashboardu) + definicje DB-driven per tenant z builderem w Settings (M5, feature flag, odcinalne do Fazy 2). Benchmark: Pimcore (silnik, dialogi przejść), Akeneo (taski, kryteria completeness), inRiver (inbox zadań), Salsify (hook AI→review, [DEF]).

**Tracking:** GitHub label `epik-WFL` + milestone'y M0–M6 (#74–#80) + 27 issues #2409–#2436 (bez #2411).
- Backlog (single source of truth): [`Project Plan/feature-workflow-tickets.md`](feature-workflow-tickets.md) (28 ticketów: 27 issues + 1 [DEF], prefix `WFL`).
- Decyzja: **ADR-0029** (`docs/adr/0029-workflow-engine-and-placement.md`, finalizacja w WFL-P0-01).
- Spec źródłowa: [`PRD/PRD-PIM-rbac.md`](PRD/PRD-PIM-rbac.md) §3.8 (workflow-state policy) + benchmark w nagłówku backlogu.

**Estymacja:** ~210–300h (M0 silnik+ADR · M1 egzekwowanie+RBAC · M2 zdarzenia+notyfikacje · M3 UI · M4 zadania · M5 definicje/builder · M6 hardening+E2E+demo). Świadome odcięcia do Fazy 2: per-attribute proposals (substrat `pending_changes` istnieje), stany per locale/kanał, SLA/aging analytics, wersjonowanie produktów (osobny item Track B), email digest.

**Sequencing:** po epiku CPDF. Zależności: RBAC (gotowe), completeness read-model (gotowe), Mercure (gotowe). Nowy BC `src/Workflow/` + mini-BC `src/Notification/` (infra generyczna pod przyszłe epiki).

---

*Koniec planu projektu. Dokument żyjący — aktualizowany co fazę.*
