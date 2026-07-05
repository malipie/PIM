# ADR-0026: Kontekst Dashboard — read-model agregatów pulpitu

- **Status:** accepted
- **Data:** 2026-07-05
- **Kontekst:** epik DASH (uinteraktywnienie dashboardu), tickety DASH-04 (#2255), DASH-05, DASH-07, DASH-09
- **Powiązane:** ADR-0013 (Deptrac jako strażnik granic), ADR-0014 (Shared kernel), `docs/api/jsonb-schemas.md` §3 (completeness), `Project Plan/UI/dashboard-brief-makiety.md`, `Project Plan/UI/Wdrozenie_grafiki/dashboard-do-oprogramowania.md`

## Kontekst

Dashboard (Pulpit) potrzebuje agregatów, które przecinają bounded contexty:

- **KPI + kompletność** — Catalog (`objects.completeness_pct`, `completeness->per_channel`),
- **aktywność zespołu** — Identity (`audit_logs`) + Catalog (`objects.created_at`),
- **Centrum akcji** — Integration (`integration_sync_runs`), Import (`import_sessions`), Export.Feed (`feed_runs`), ApiConfigurator (`api_webhook_deliveries`),
- **delty/trendy** — historyczne snapshoty, których nikt dziś nie utrzymuje.

Żaden istniejący kontekst nie jest właścicielem takiego przekroju. `Shared` jest w Deptrac
leafem (ADR-0014) i nie może zależeć od kontraktów innych BC. Rozproszenie endpointów
dashboardowych po kontekstach źródłowych (po jednym na BC) rozbiłoby publiczny kontrakt
pulpitu na 4-5 miejsc i wymusiłoby na FE ponowną agregację.

Dodatkowe decyzje operatora (2026-07-04, sesja planistyczna epiku DASH):

1. **Alerty Centrum akcji = agregator on-the-fly**, NIE materializowana encja `Alert`
   (żadnych event subscriberów w 4 BC; źródła są tanimi, indeksowanymi zapytaniami po
   statusach). Stan „przeczytane" trzyma mała tabela ack-ów po deterministycznym
   fingerprincie.
2. **Delty/trendy = dzienne snapshoty agregatów** (tabela `dashboard_snapshots` + job w
   istniejącym schedulerze maintenance). Dopóki snapshot na horyzoncie (7d/30d) nie
   istnieje — kafle renderują się bez delty (reguła NUI-02: zero fabrykowanych trendów).

## Decyzja

### Nowy, cienki kontekst `src/Dashboard` (read-model)

- **Charakter:** wyłącznie odczytowy widok agregujący + dwie małe encje własne
  (`DashboardSnapshot` — DASH-05, `DashboardAlertAck` — DASH-09). Kontekst NIE jest
  właścicielem żadnych danych domenowych innych BC i nie wystawia zapisu do nich.
- **Powierzchnia HTTP:** custom `#[Route]` kontrolery `GET /api/dashboard/*` (wzorzec
  CQRS/ADR-0012, przykład: `ImportThroughputController`), gate'owane
  `#[RequiresPermission]`, widoczne w OpenAPI przez `CustomRouteOpenApiFactory`.
- **Deptrac:** nowa warstwa `Dashboard` (collector `src/Dashboard/.*`) z rulesetem
  `[Catalog_Contracts, Channel_Contracts, Identity_Contracts, Shared, Vendor]`.

### Polityka odczytu danych innych BC

1. **Preferencja: port w `<BC>\Contracts`** — gdy seam istnieje lub jest tani
   (np. `Catalog\Contracts\Query\ChannelCompletenessPort`, DASH-03 #2254).
2. **Dopuszczalny: surowy DBAL SQL z jawnym predykatem `tenant_id`** — dla tabel BC,
   które nie mają użytecznych Contracts (`integration_sync_runs`, `import_sessions`,
   `feed_runs`, `api_webhook_deliveries`, `audit_logs`). Precedens:
   `SqlCompletenessReport` („raw SQL bypasses the Doctrine TenantFilter; RLS is the
   backstop") oraz `ChannelNodeMappingController` (query cross-BC po `objects`).
   Deptrac pilnuje zależności klasowych — read-model NIE importuje encji ani serwisów
   Internals innych BC; czyta tabele. RLS pozostaje twardym backstopem izolacji.
3. **Zakaz:** zapis do tabel innych BC, import klas `*_Internals`, logika domenowa
   w kontekście Dashboard.

### Snapshoty dzienne (`dashboard_snapshots`, DASH-05)

Wiersz per tenant per dzień: `products_total`, `publish_ready_count`,
`avg_completeness_pct`, `per_channel JSONB`; UNIQUE(tenant_id, snapshot_date).
Wypełniany komendą `pim:dashboard:snapshot` (iteracja po tenantach, upsert) wpiętą
w `MaintenanceSchedule` (transport `scheduler_maintenance`). Delty liczone jako
`bieżąca wartość − snapshot(horyzont)`; brak snapshotu ⇒ `null` ⇒ UI bez delty.
Snapshoty są też punktem odniesienia detektora „spadek kompletności poniżej progu"
w Centrum akcji (DASH-09).

### Alerty (DASH-09)

`AlertFeedAggregator` — UNION czterech źródeł statusowych w oknie czasowym
+ detektor spadków ze snapshotów + LEFT JOIN `dashboard_alert_acks`
(UNIQUE(tenant_id, fingerprint); fingerprinty deterministyczne, np.
`sync_run:{uuid}`, `webhook:{profileId}:{eventType}:{yyyy-mm-dd}`). Okno czasowe
zastępuje TTL; brak dryfu storage'u względem źródeł prawdy.

## Konsekwencje

- (+) Jeden publiczny kontrakt pulpitu (`/api/dashboard/*`) zamiast N endpointów po BC;
  FE przestaje re-agregować (9 requestów → 1 na widget-grupę).
- (+) Zero nowych seamów zdarzeniowych w 4 BC; koszt utrzymania ograniczony do SQL-i
  odczytowych chronionych testami kernelowymi na realnym Postgresie.
- (+) Snapshoty odblokowują uczciwe delty (brak fabrykowanych trendów) i detektor spadków.
- (−) Read-model zna nazwy tabel innych BC — akceptowane świadomie (pkt 2 polityki);
  zmiany schematów tych tabel muszą uwzględnić dashboard (testy kernelowe łapią drift).
- (−) Snapshoty zaczynają się od wdrożenia — delty 30d pojawią się po 30 dniach.

## Alternatywy odrzucone

- **Endpointy per-BC** — rozbicie kontraktu, N×CORS-of-concerns w FE, brak miejsca na
  przekroje (alerty łączą 4 BC w jednym feedzie).
- **Materializowana encja `Alert` + event subscribery** — wymaga wymyślenia seamów
  zdarzeń w Import/Export/Integration/ApiConfigurator (dziś ich brak), dubluje stan
  źródłowy i wprowadza dryf; odrzucona decyzją operatora 2026-07-04.
- **Rozszerzenie `Shared`** — złamanie „Shared jest leafem" (ADR-0014) i degradacja
  kernela do worka na wszystko.
