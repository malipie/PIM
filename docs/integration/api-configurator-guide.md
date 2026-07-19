# Konfigurator API — przewodnik konsumenta + producenta

> ADR-0022 (`docs/adr/0022-api-configurator-consumer-producer-boundary.md`),
> epik APIC. Konfigurator API ma **dwa oblicza** pod jednym shellem
> (`/integrations/api-configurator`):
>
> - **Konsument** — PIM pobiera/wysyła dane z/do **dowolnego zewnętrznego API**
>   (Połączenia, mapowanie, harmonogram, monitor).
> - **Producent** — PIM **wystawia własne API** partnerom (profile read-only,
>   klucze, webhooki).
>
> Shell przełącza się zakładkami **Połączenia / Moje API / Monitor**
> (`KonfiguratorApiLayout`).

---

## Część A — Konsument (PIM ↔ zewnętrzne API)

### A.1 Utworzenie połączenia

- **UI**: zakładka *Połączenia* → *Nowe połączenie* (kreator,
  `/integrations/api-configurator/connections`). Krok 1–2: base URL + typ
  uwierzytelnienia (`none` / `api_key` / `basic` / `bearer` / `oauth2_token`)
  i sekret.
- **API**: `POST /api/connections` (API Platform). Credentiale są **szyfrowane
  odwracalnie at-rest** (BYOK AES-256-GCM) — nigdy nie wracają w odpowiedzi API
  (maskowane przy serializacji).
- Base URL przechodzi walidację deskryptora (`DescriptorValidator`): tylko
  absolutne `http(s)` z hostem; bez wstrzyknięć schematu/hosta w szablonach
  ścieżek.

### A.2 Test połączenia

- **UI**: przycisk *Testuj połączenie* w kreatorze / detalu.
- **API**: `POST /api/connections/{id}/test` — strzela health/auth-check przez
  **SSRF-safe klienta**; zwraca status + komunikat. 2xx = OK, 4xx/5xx = błąd do
  poprawy przed dalszą konfiguracją.

### A.3 Odkrycie schematu (endpointy + pola)

- **UI**: kroki kreatora 3–4 (endpointy + discovery).
- **API**: `POST /api/connections/{id}/discover` — pobiera próbkę, spłaszcza
  JSON i proponuje `RemoteField`-y. Endpointy (`RemoteEndpoint`) mają rolę
  (`read_list` / `write_create` / `write_update`), metodę HTTP, szablon ścieżki,
  selektor rekordów (np. `$.results`) i strategię paginacji
  (`none`/`offset`/`page`/`cursor`/`link_header`).

### A.4 Mapowanie pól 1:1

- **UI**: ekran *Mapowanie* (`MappingScreen`).
- **API**: `FieldMapping` (API Platform) + walidacja
  `POST /api/connections/{id}/mappings/validate` — ostrzega przy niezgodności
  typów. Mapowanie ma kierunek (`inbound` / `outbound` / `both`) i flagę
  `matchKey` (po czym dopasowujemy istniejący obiekt przy upsercie).
- **Scope per endpoint (#2634)**: mapowanie może być opcjonalnie przypięte do
  jednego endpointu (`endpoint` w input/patch; pusty string w PATCH czyści
  scope). Bez scope'u mapowanie dotyczy wszystkich endpointów połączenia.
  Potrzebne dla API typu RPC, gdzie każda operacja (metoda) ma inny kształt
  payloadu — silniki sync filtrują mapowania po endpoincie wiązania. Usunięcie
  endpointu usuwa jego mapowania (DB cascade) — osierocony scope nie może po
  cichu rozszerzyć się na wszystkie operacje.

### A.4a API typu RPC (BaseLinker) — form body + statyczna koperta (#2634)

Endpointy zapisu mają `requestFormat` (`json` — domyślny surowy JSON, lub
`form` — `application/x-www-form-urlencoded`, gdzie zagnieżdżone obiekty są
wysyłane jako JSON-stringi) oraz `requestBodyTemplate` — statyczną kopertę
scalaną z payloadem z mapowań (`array_replace_recursive`; mapowanie wygrywa
kolizję klucza). Odpowiedzi `HTTP 200` z `{"status":"ERROR",…}` są
klasyfikowane jako błąd rekordu (`RemoteResponseInspector`).

Przepis dla BaseLinkera (`https://api.baselinker.com`, auth `api_key`
z nagłówkiem `X-BLToken`):

1. **Endpoint zapisu**: rola `write_create`, `POST /connector.php`,
   format `form`, szablon
   `{"method":"addInventoryProduct","parameters":{"inventory_id":<ID>}}`.
2. **Mapowania** (kierunek outbound, scope na ten endpoint lub bez scope'u):
   `sku → $.parameters.sku` (match key),
   `name → $.parameters.text_fields.name`, itd.
3. **Wiązanie**: kierunek Outbound (push), endpoint zapisu z pkt 1, filtr
   wysyłki wg potrzeb.
4. Na drucie wychodzi `method=addInventoryProduct&parameters={…}` — dokładnie
   kontrakt connector.php.

**Parowanie zdalnych ID (#2636)** — bez tego `addInventoryProduct` bez
`product_id` zawsze tworzy nowy rekord i ponowny push duplikuje katalog:

1. Utwórz w PIM zwykły atrybut na zdalne ID (np. `base_product_id`, typ text).
2. Na endpoincie zapisu ustaw **Parowanie ID**: selector `$.product_id` +
   atrybut `base_product_id`. Po udanym pushu silnik wyciąga ID z odpowiedzi
   (ten sam `RecordSelector` co przy odczycie) i zapisuje je do atrybutu
   z `provenance = integration`.
3. Dodaj mapowanie outbound `base_product_id → $.parameters.product_id`
   (scope na ten endpoint). Silnik pomija puste wartości, więc pierwszy push
   naturalnie robi create, kolejne — update (rekord liczony jako `updated`).
4. Ręczne parowanie / import istniejącego katalogu: wpisz lub zaimportuj ID do
   atrybutu jak każdą inną wartość. „Rozłączenie" = wyczyszczenie pola.

Anty-pętla: zapisy katalogowe wykonywane przez run synchronizacji (inbound
upserty, capture ID) nie re-enqueue'ują bindingów **tego samego** połączenia
(`SyncRunScope`); bindingi innych połączeń triggerują się normalnie.

### A.5 Synchronizacja: powiązanie + harmonogram

- **UI**: ekran *Synchronizacja* (`SyncConfigScreen`).
- **API**: `SyncBinding` (API Platform) — wiąże połączenie + `ObjectType` +
  kierunek; akcje:
  - `POST /api/sync_bindings/{id}/run` — odpal teraz,
  - `POST /api/sync_bindings/{id}/pause` / `…/resume` — wstrzymaj/wznów.
- **Harmonogram**: wyrażenie cron + jitter; due-bindings odpala planowo komenda
  `integration:sync:dispatch-due` (Symfony Scheduler → transport `import`).
- **Konflikt bidirectional**: polityka per binding —
  `last_write_wins` / `pim_wins` / `remote_wins`; anti-loop po `provenance`
  (zapisy z integracji oznaczone `Provenance::Integration`).
- **Delta sync**: kursor monotoniczny, crash-safe (advance raz na stronę);
  re-run pobiera tylko okno od ostatniego kursora (upsert idempotentny).

### A.6 Monitoring

- **UI**: zakładka *Monitor* (`SyncMonitorScreen`) — KPI + lista przebiegów +
  drill-down per rekord.
- **API**: `GET /api/sync_runs` (filtry connection/binding) i
  `GET /api/sync_run_logs` (per run). Każdy przebieg = `SyncRun` z licznikami
  created/updated/skipped/failed; każdy rekord = `SyncRunLog` (akcja, match key,
  komunikat).

---

## Część B — Producent (PIM jako API dla partnerów)

### B.1 Profile API

- **UI**: zakładka *Moje API* → builder profilu
  (`/integrations/api-configurator/create`, `ProfileBuilderPage`). Wybierasz
  `ObjectType`-y + atrybuty + filtry; profil jest **projekcją read-only** w MVP.
- **API**: `ApiProfile` (API Platform). Pula atrybutów do buildera:
  `GET /api/profiles/builder_options`.

### B.2 Per-profile OpenAPI

- `GET /api/docs/profile/{id}.jsonopenapi` — wycinek OpenAPI zawężony do
  ścieżek `/api/(products|categories|assets|objects)` + metadane `x-pim-*`
  konkretnego profilu (partner dostaje kontrakt tylko dla swojego zakresu).

### B.3 Klucze API

- Klucze są **hashowane Argon2id** (PIM tylko weryfikuje przychodzący klucz —
  nie musi go odszyfrować, w przeciwieństwie do credentiali konsumenta).
- Zakładka *Klucze* w producer hubie — tworzenie/rotacja.

### B.4 Webhooki

- **Test**: `POST /api/api_profiles/{id}/test_webhook` — wysyła testowy fan-out
  (HMAC-podpisany).
- **Rotacja sekretu**: `POST /api/api_profiles/{id}/rotate_webhook_secret`.
- **Dostawy + retry**: `WebhookDelivery` z historią dostaw; nieudane dostawy
  retry'owane przez transport `async` (5× exponential → dead-letter). Historia:
  `GET /api/webhook_deliveries` (filtr per profil).

---

## Część C — Noty bezpieczeństwa

### C.1 SSRF (konsument)

Każde wyjście HTTP konsumenta idzie przez dwie warstwy obrony:

1. **`SsrfGuard`** (pre-filter) — odrzuca prywatne (RFC1918), loopback,
   link-local/metadata (`169.254.169.254`), IPv6 ULA/link-local, schematy inne
   niż `http(s)`, hostnamy rozwiązujące się w przestrzeń prywatną, URL bez hosta.
2. **`NoPrivateNetworkHttpClient`** (`generic.ssrf_safe_http_client`) —
   re-walidacja peer-IP per-redirect (zamyka DNS-rebinding + redirect-to-private).

Zestaw wektorów zweryfikowany adversarialnie w `SsrfAdversarialTest` (APIC-P5-02).

### C.2 Sekrety

- **Konsument**: credentiale szyfrowane odwracalnie (BYOK AES-256-GCM,
  wersjonowane). Rotacja klucza: zob.
  `docs/operations/connection-credentials-rotation-runbook.md` (komenda
  `integration:credentials:rotate`).
- **Producent**: klucze hashowane Argon2id (jednokierunkowo).
- Oba mechanizmy współistnieją świadomie (ADR-0022).

### C.3 Rate-limit / backoff

- **Wychodzące** żądania konsumenta retry'owane z **exponential backoff**
  (`BackoffRestClient`): HTTP 429 / `Retry-After` → sleep → retry, max prób →
  dead-letter (zgodnie z polityką throttlingu w architekturze §7.3).
- Limiter `integration_sync` (10/h/tenant, `framework.yaml`) jest skonfigurowany
  i zarezerwowany dla endpointów sync Fazy 1 (BaseLinker/Shopify).

### C.4 Multi-tenancy

Wszystkie encje konsumenta (`Connection`, `RemoteEndpoint`, `RemoteField`,
`FieldMapping`, `SyncBinding`, `SyncRun`, `SyncRunLog`) mają `tenant_id` +
Postgres FORCE RLS + Doctrine `TenantFilter`. Izolacja cross-tenant = 0
zweryfikowana w `CrossTenantIsolationTest` (APIC-P5-01). Worker sync ustawia GUC
`app.current_tenant` per wiadomość (RLS dla ścieżki async).

### C.5 Wydajność

Silnik sync czyści unit of work między batchami (O(batch), nie O(rekord)) —
profil pamięci + EXPLAIN ANALYZE + cele p95 w `docs/perf/sync-engine-benchmark.md`
(APIC-P5-04).

---

## Powiązane dokumenty

- ADR-0022 — granica konsument/producent, umiejscowienie generycznego konektora.
- `docs/operations/connection-credentials-rotation-runbook.md` — rotacja BYOK.
- `docs/perf/sync-engine-benchmark.md` — profil wydajności sync.
- `docs/api/jsonb-schemas.md` — kontrakt envelope wartości (`provenance`).
- `Project Plan/feature-api-configurator-tickets.md` — backlog epiku APIC.
