# Backlog — Katalogi PDF: product sheety, katalogi, cenniki (epik CPDF)

> **Status:** ✅ **ZREALIZOWANY W CAŁOŚCI** (2026-07-10) — 27/27 ticketów merged, milestone'y M0–M6 (#52–#58) zamknięte, każde issue z live-smoke proofem w komencie zamknięcia. Podsumowanie: `agent/current_status.md` (sekcja 2026-07-10). Utworzony 2026-07-08.
> **Źródło architektury:** [`UI/feature-catalogs-pdf.md`](UI/feature-catalogs-pdf.md) (§2 decyzje, §4 as-is/reuse, §6 architektura, §7 IN/OUT, §9 brief UI, §10 fazy, §12 mini-ADR).
> **Decyzja architektoniczna:** ADR-0027 (`docs/adr/0027-catalog-pdf-renderer-port.md`) — finalizowany w CPDF-P0-01. *(Numer potwierdzony `ls docs/adr/`: 0025/0026 zajęte; 0027 wolny.)*
> **Designy UI:** do dostarczenia w briefie §9 planu (pattern Exports/Konfigurator XML — `Zrodla/Front_Claude_Design/**`). Do czasu handoffu FE klonuje layout Exports/Feeds.
> **Epik label:** `epik-CPDF`. Prefix ID: `CPDF`, format `CPDF-P{faza}-{nn}`.
> **Milestone'y:** M0 Fundament (ADR + Deptrac + port + sanityzacja) · M1 Model + schema + CRUD · M2 Silnik szablonu + archetyp sheet · M3 Async + delivery · M4 Preview + OpenAPI + RBAC · M5 UI · M6 Archetypy grid/pricelist + Gotenberg + benchmark.

Ten plik to **single source of truth** backlogu. GitHub Issues są lustrem (skondensowane body + link tutaj). Tracking faktyczny w Issues + milestone'ach.

**27 ticketów, ~245–335h.** Katalogi PDF to warstwa „szablon HTML + branding + mapowanie pól + renderer + delivery" **NAD** istniejącym silnikiem Export (reuse `ExportBuilder`) i wzorcem `Export/Feed` (delivery). Nowego kodu domenowego realnie niewiele: port `PdfRenderer` + 2 adaptery + silnik szablonu Twig + `CatalogProfile`/`CatalogRun` + delivery (kalka Feed) + seedy szablonów. Reszta to spięcie istniejących silników — poprawki w Export/Feed propagują się do katalogów.

---

## Mapa GitHub Issues

_Uzupełniana po `gh issue create` — odwrotny indeks ID → numer._

| ID | Issue | ID | Issue | ID | Issue |
|---|---|---|---|---|---|
| CPDF-P0-01 | #2282 | CPDF-P0-02 | #2283 | CPDF-P0-03 | #2284 |
| CPDF-P0-04 | #2285 | CPDF-P1-01 | #2286 | CPDF-P1-02 | #2287 |
| CPDF-P1-03 | #2288 | CPDF-P1-04 | #2289 | CPDF-P2-01 | #2290 |
| CPDF-P2-02 | #2291 | CPDF-P2-03 | #2292 | CPDF-P3-01 | #2293 |
| CPDF-P3-02 | #2294 | CPDF-P3-03 | #2295 | CPDF-P3-04 | #2296 |
| CPDF-P4-01 | #2297 | CPDF-P4-02 | #2298 | CPDF-P5-01 | #2299 |
| CPDF-P5-02 | #2300 | CPDF-P5-03 | #2301 | CPDF-P5-04 | #2302 |
| CPDF-P5-05 | #2303 | CPDF-P6-01 | #2304 | CPDF-P6-02 | #2305 |
| CPDF-P6-03 | #2306 | CPDF-P6-04 | #2307 | CPDF-P6-05 | #2308 |

---

## Konwencje

- **Cls:** `BE` · `FE` · `SEC` (security-first, failing-test-first) · `DOCS`.
- **[PM]:** ticket wymaga Plan Mode — cross-context, decyzja architektoniczna, lub nowa zależność core.
- **[SEC]:** ticket bezpieczeństwa, failing-test-first.
- **[DEF]:** hook §7 planu, świadomie odłożony poza MVP (nie ma issue na starcie epiku).
- **Bounded context:** silnik katalogów PDF → pod-obszar `apps/api/src/Export/Catalog/` (kalka `src/Export/Feed/`). Port renderera → `apps/api/src/Export/Contracts/`. Cross-BC do Channel/Catalog/Asset tylko przez `*\Contracts\*` (Deptrac); rdzeń Export przez `Export/Contracts`.
- **Tytuł Issue:** angielski Conventional Commit `{feat|docs|chore|test|perf}(scope): subject`. Body + AC po polsku. Kod po angielsku.

### Standard DoD (każdy ticket, o ile nie zaznaczono inaczej)

- [ ] Acceptance criteria spełnione.
- [ ] **PHPStan max**: 0 errors (BE).
- [ ] **Deptrac**: 0 violations (cross-BC tylko przez Contracts).
- [ ] **PHP-CS-Fixer**: czysto (BE).
- [ ] **Biome strict** + **tsc --noEmit**: 0 errors (FE).
- [ ] **PHPUnit** ≥80% nowej logiki domenowej; **ApiTestCase** dla każdego endpointu (401 + 403 + 404 + walidacja + happy path).
- [ ] **Playwright E2E**: happy path + ≥1 edge case (FE z widoczną zmianą).
- [ ] **axe-core**: 0 violations serious/critical (FE).
- [ ] **Multi-tenancy**: cross-tenant read = 0 wyników (encje TenantScoped, RLS + TenantFilter).
- [ ] **composer audit + pnpm audit**: 0 high/critical.
- [ ] **OpenAPI snapshot** `docs/api-spec/v0.json` zaktualizowany (nowe custom trasy — ADR-0020).
- [ ] **PDF smoke:** publiczny URL / generate zwraca 200 + poprawny PDF (`pdfinfo`/`file` → `PDF document`); manual smoke 5 min na `pim.localhost`; PR opis nie używa „działa" bez smoke testu (SMOKE TEST RULE).
- [ ] CI green; PR merged do main.

### Reuse (potwierdzone sygnatury as-is, 2026-07-08)

| Klocek | Ścieżka | Rola w Katalogach PDF |
|---|---|---|
| `ExportBuilder` (`build(iterable<CatalogObject>, ExportSession): Generator<int, array<string,string>>`, caller owns chunk+`clear()`) | `apps/api/src/Export/Application/Builder/ExportBuilder.php` | Rdzeń danych — te same wiersze wiązane do slotów szablonu PDF |
| `ColumnResolver` / `ValueSerializer` (`MULTI_VALUE_GLUE='|'`, null→'', asset→URL) / `ColumnDefinition` | `apps/api/src/Export/Application/Builder/` | Atrybut→klucz + serializacja JSONB→string + resolver URL assetu do slotów |
| `PublicationColumnPlanner` + `ChannelPublicationResolverInterface` (ADR-0018) | `apps/api/src/Export/Application/Builder/`, `apps/api/src/Channel/Contracts/` | Scope do kanału = publication profile; `column_aliases` = zalążek mapowania pól |
| Wzorzec Feed: `FeedProfile`/`FeedRun`/`FeedRunLog`/enumy · `FeedGenerator`/`FeedRegenerator` · `FeedRunHandler` · `FeedProfileController`/`FeedPullController`/`FeedRegenerateController`/`FeedRunMonitorController`/`FeedPreviewController`/`FeedTemplatesController` · `FeedTemplateCatalog` · Doctrine repo | `apps/api/src/Export/Feed/**` | **Kalka 1:1** dla `Export/Catalog/**` — model, async, delivery, monitor, preview, katalog szablonów |
| `FeedTokenService` (`mint`/`hash` HMAC-SHA256/`matches` const-time) + rate-limit + `security.yaml` PUBLIC_ACCESS | `apps/api/src/Export/Feed/Application/Delivery/`, `apps/api/config/packages/security.yaml` | Token URL dokumentu (hash, nie klucz tenanta) + publiczny endpoint |
| `FeedCacheStorage` / `FlysystemFeedCacheStorage` / `FeedEtag` | `apps/api/src/Export/Feed/{Application,Infrastructure}/Delivery/` | Cache PDF `catalogs/{tenant}/{id}.pdf` + ETag/304 |
| `AbstractBatchHandler` + Messenger `import` transport + Mercure | `apps/api/src/Shared/`, infra | `GenerateCatalogHandler` (batch 200 + `clear()`), progres `catalog-runs.{id}` |
| Advanced filter DSL (`FilterDslResolver` / `useFilterDslState` / `AdvancedFilterPanel`; `filter_snapshot` JSONB) | `apps/api/src/Catalog/Application/Filter/`, `apps/admin/src/lib/filters/`, `apps/admin/src/components/catalog/advanced-filter-panel.tsx` | Wybór asortymentu → `CatalogProfile.filter` |
| `CustomRouteOpenApiFactory` (ADR-0020) | `apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php` | Custom trasy PDF → `docs/api-spec/v0.json` |
| `#[RequiresPermission(module, action)]` + `EndpointGuardListener` + `RequiresPermissionAnnotationRule` | `apps/api/src/Identity/**` | Nowy moduł RBAC `catalogs_pdf` |
| FE shell/kreator/lista: `ExportsLayout` · `ExportWizardPage`/`FeedWizardPage` · `FeedsHubPage` · `HistoryTable` · `useFeedRunsStream`/`ExportsLiveBridge` | `apps/admin/src/features/exports/**`, `apps/admin/src/features/api-configurator/feeds/**` | Kalka obszaru FE |
| `PillTabs` · `WizardStepper` · `SelectableCardGroup` · `StatusPill` · `EmptyState` · `ComingSoonPlaceholder` | `apps/admin/src/components/ui-v2/`, `apps/admin/src/features/settings/ComingSoonPlaceholder.tsx` | Prymitywy UI |
| Route + menu + i18n placeholder | `apps/admin/src/App.tsx` (`/catalogs-pdf`), `apps/admin/src/layout/sidebar-nav.tsx` (`ref: catalogs_pdf`, `protected:false`), `apps/admin/src/features/catalogs-pdf/index.tsx`, `locales/{pl,en}.json` (`catalogs_pdf.*`, `nav.catalogsPdf`) | Punkt zaczepienia FE — wymiana `ComingSoonPlaceholder` |

---

# M0 — Fundament: ADR + Deptrac + port renderera + sanityzacja

### CPDF-P0-01: docs(architecture): add ADR-0027 for Catalog PDF renderer port and placement
- **Typ:** `docs` · **Cls:** DOCS · **Milestone:** M0 · **Est:** 3-5h · **Risk:** low · `[PM]`
- **Blocked by:** — · **Blocks:** CPDF-P0-02, CPDF-P0-03
- **Po co:** Katalogi PDF dotykają >1 bounded context (Export jako gospodarz silnika + Channel/Catalog/Asset przez Contracts jako źródła danych + publiczny endpoint jako nowa powierzchnia ataku) i wprowadzają nową kategorię zależności (renderer PDF). Zanim powstanie kod, potrzebna jest jedna autorytatywna decyzja architektoniczna, żeby kolejne tickety (M2 silnik, M3 delivery, M6 Gotenberg) nie renegocjowały umiejscowienia ani wyboru renderera. Plan §12 zawiera szkic mini-ADR — ten ticket go finalizuje jako ADR-0027.
- **Stan obecny:** Plan `feature-catalogs-pdf.md` §12 ma szkic mini-ADR. Najwyższe zajęte numery ADR to 0025 (`0025-object-type-cross-field-validation.md`) i 0026 (`0026-dashboard-read-model.md`) — potwierdzone `ls docs/adr/`, następny wolny to **0027** (plan błędnie zakładał 0025). Istnieje `docs/adr/0023-konfigurator-xml-placement.md` jako wzorzec sąsiedniego „placement + delivery" ADR. Brak jakiegokolwiek kodu renderowania PDF w repo.
- **Zakres:**
  - Utworzyć `docs/adr/0027-catalog-pdf-renderer-port.md` wg `docs/adr/adr-template.md` (status Accepted, data 2026-07-08).
  - Sfinalizować decyzje z planu §2/§6/§12: (1) **web-to-print** (HTML/CSS→renderer→PDF), nie konektor InDesign; (2) **port `PdfRenderer`** w `Export/Contracts/` z adapterami `DompdfRenderer` (in-process, default, zero infry) i `GotenbergRenderer` (opcjonalny sidecar gdy `GOTENBERG_URL`); PDFreactor/prince = przyszły 3. adapter pod CMYK w Fazie 2; (3) silnik katalogów w pod-obszarze `Export/Catalog/` (kalka Feed, uzasadnienie jak ADR-0023 — projekcja katalogu na wyjście, ta sama domena Export), NIE nowy BC; (4) reuse `ExportBuilder` jako źródła danych, cross-BC tylko przez `*\Contracts\*`; (5) jeden parametryzowalny silnik szablonu (brand tokens + mapowanie pól), archetypy sheet/grid/pricelist, drag-drop builder = hook; (6) delivery pull cache-and-serve (regen→MinIO→publiczny URL z tokenem, ETag/304), token ≠ klucz tenanta; (7) MVP = RGB ekranowy; CMYK/spady/PDF-X = hook za portem.
  - Udokumentować konsekwencje: reuse silnika Export → poprawki propagują się do katalogów; instalacja bez Gotenberga = pełny MVP na Dompdf; ryzyko pamięci Dompdf (decyzja A) i rozjazdu wierności (R2) jako otwarte, adresowane w M6.
  - Wypisać powiązane ADR (0023 Feed placement/cache-and-serve/token, 0018 publication profile, 0015 bare UUID cross-BC, 0020 OpenAPI custom route, 0009 ObjectType).
  - Dopisać wpis do `docs/adr/README.md` (indeks ADR) jeśli README utrzymuje listę.
- **Poza zakresem:**
  - Implementacja jakiegokolwiek kodu (port, adaptery, silnik) — osobne tickety M0/M2.
  - Zmiany w `deptrac.yaml` — CPDF-P0-02.
  - Schema `catalog_profiles`/`catalog_runs` — M1 (ADR wskazuje kierunek, nie zamraża kolumn).
  - Wybór/instalacja konkretnej wersji `dompdf/dompdf` — CPDF-P0-03.
- **AC:**
  - [ ] Plik `docs/adr/0027-catalog-pdf-renderer-port.md` istnieje, status Accepted, zgodny z `adr-template.md`.
  - [ ] ADR jednoznacznie stwierdza: renderer za portem `PdfRenderer` (nie na sztywno), Dompdf default in-process + Gotenberg opcjonalny sidecar.
  - [ ] ADR jednoznacznie stwierdza: silnik żyje w `src/Export/Catalog/`, NIE w nowym bounded context; cross-BC tylko przez `*\Contracts\*`.
  - [ ] ADR jednoznacznie stwierdza: web-to-print, InDesign/EasyCatalog i CMYK/druk offsetowy poza MVP (hooki).
  - [ ] ADR jednoznacznie stwierdza: delivery cache-and-serve; token-in-URL ≠ klucz tenanta (kalka ADR-0023).
  - [ ] Sekcja „Powiązane ADR" linkuje 0009/0015/0018/0020/0023 (istniejące pliki).
  - [ ] Numer 0027 nie koliduje: `ls docs/adr/` nie pokazuje istniejącego 0027.
- **Smoke:** Otworzyć ADR i zweryfikować, że wszystkie decyzje z §12 planu są rozstrzygnięte (nie „proponowane"); potwierdzić linki do 0009/0015/0018/0020/0023 wskazują istniejące pliki; potwierdzić spójność z `deptrac.yaml` (Export sięga tylko `*_Contracts` + Shared).
- **Reuse:** `docs/adr/adr-template.md` · `docs/adr/0023-konfigurator-xml-placement.md` (wzorzec placement/delivery/token) · `docs/adr/0018-channel-publication-profile.md` · `docs/adr/0020-openapi-custom-route-documentation.md` · `docs/adr/0015-cross-bc-fk-policy.md`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §12, §6.1, §2 · `docs/adr/adr-template.md`
- **DoD:** standard (docs-only — bez bramek kodowych).

### CPDF-P0-02: chore(deptrac): add Export_Catalog layers reaching Export_Contracts seam
- **Typ:** `chore` · **Cls:** BE · **Milestone:** M0 · **Est:** 3-5h · **Risk:** medium
- **Blocked by:** CPDF-P0-01 · **Blocks:** CPDF-P0-03, CPDF-P1-01
- **Po co:** ADR-0027 ustala, że katalogi PDF żyją w `src/Export/Catalog/` i sięgają Channel/Catalog/Asset wyłącznie przez `*\Contracts\*`, a port `PdfRenderer` w `Export/Contracts/`. Deptrac musi egzekwować tę granicę CI-owo od dnia 1 — inaczej pierwszy ticket M1/M2 przypadkiem sięgnie do `Catalog\Domain` i utrwali dług. Ten ticket kładzie szkielet architektoniczny dla całego epiku BE.
- **Stan obecny:** `deptrac.yaml` ma warstwę Export (płaska lub z pod-warstwami Feed po XMLF-P0-02: `Export_Feed_Internals`/`_Contracts` + `Export_Contracts`). Reuse istniejącego wzorca warstw Feed. `src/Export/Catalog/` jeszcze nie istnieje.
- **Zakres:**
  - Dodać warstwy `Export_Catalog_Internals` (collector directory `src/Export/Catalog/{Domain,Application,Infrastructure,Presentation}/.*`) oraz `Export_Catalog_Contracts` (`src/Export/Catalog/Contracts/.*` jeśli powstanie).
  - Potwierdzić/rozszerzyć warstwę `Export_Contracts` (`src/Export/Contracts/.*`) jako seam dla portu `PdfRenderer` (jeśli nie dodana przy XMLF-P0-02).
  - Przepiąć płaską warstwę Export tak, by NIE łapała `src/Export/Catalog/*` ani `src/Export/Contracts/*` (bool must/must_not, analogicznie do Feed).
  - Ruleset: `Export_Catalog_Internals` → [`Export_Catalog_Contracts`, `Export_Contracts`, `Channel_Contracts`, `Catalog_Contracts`, `Asset_Contracts`, `Identity_Contracts`, `Shared`, `Vendor`]. `Export_Contracts` → [`Shared`, `Vendor`].
  - Uruchomić deptrac i potwierdzić 0 nowych naruszeń; nie dodawać wpisów do `skip_violations`.
- **Poza zakresem:** Implementacja klas w `Export/Catalog` / `Export/Contracts` (poza ewentualnym placeholderem) — M0/M1/M2. Usuwanie istniejących baseline `skip_violations`.
- **AC:**
  - [ ] `deptrac.yaml` zawiera `Export_Catalog_Internals` (+`_Contracts` jeśli potrzebny) z poprawnymi collectorami.
  - [ ] Ruleset: `Export_Catalog_Internals` może sięgać `Export_Contracts` + `{Channel,Catalog,Asset,Identity}_Contracts` + Shared + Vendor; NIE może sięgać `Catalog_Internals`/`Channel_Internals`.
  - [ ] Płaska warstwa Export nie łapie już `src/Export/Catalog/*` ani `src/Export/Contracts/*`.
  - [ ] `deptrac analyse` zwraca 0 violations (dokładnie 0); brak nowych `skip_violations`.
- **Smoke:** `vendor/bin/deptrac analyse` → „no violations found"; `debug:layer Export_Catalog_Internals` potwierdza dozwolone tylko `*_Contracts` + Shared + Vendor; import `App\Catalog\Domain\Entity\CatalogObject` z klasy w `src/Export/Catalog/` dałby violation.
- **Reuse:** `apps/api/deptrac.yaml` — warstwy Feed (`Export_Feed_Internals`/`_Contracts`) jako wzorzec bool must/must_not do skopiowania · Export ruleset (dozwolenia `*_Contracts` + Shared + Vendor)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.1 · `docs/adr/0027-catalog-pdf-renderer-port.md` · `docs/adr/0013-deptrac-rollout.md`
- **DoD:** standard.

### CPDF-P0-03: feat(export): add PdfRenderer port with DompdfRenderer adapter and HTML→PDF POC
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 8-12h · **Risk:** high · `[PM]`
- **Blocked by:** CPDF-P0-01, CPDF-P0-02 · **Blocks:** CPDF-P2-03, CPDF-P6-03
- **Po co:** To jest fundament całej funkcji: pojedynczy port `PdfRenderer`, przez który przechodzi każdy bajt PDF (wszystkie archetypy, oba adaptery). Instalacja Cortexa ma działać bez żadnej nowej infry (decyzja #2) — dlatego domyślny adapter to czysto-PHP Dompdf, in-process, zero zależności runtime. POC statycznego HTML→PDF waliduje kontrakt portu i nową zależność end-to-end, zanim M2 zbuduje na nim silnik szablonu. `[PM]` bo dodaje nową zależność core (`dompdf/dompdf`).
- **Stan obecny:** Brak jakiegokolwiek renderowania PDF w repo. `composer.json` nie ma `dompdf/dompdf`. Deptrac ma już warstwy `Export_Catalog_*` + `Export_Contracts` (CPDF-P0-02). Wzorzec „port + adaptery" istnieje w repo (np. writery Export, `FeedCacheStorage`+`FlysystemFeedCacheStorage`).
- **Zakres:**
  - Dodać zależność `dompdf/dompdf` (najnowsza stabilna, ścisły `composer.lock`; komentarz z powodem tylko jeśli pin do starszej).
  - Zdefiniować port `App\Export\Contracts\PdfRenderer` w `src/Export/Contracts/`: `render(string $html, PdfRenderOptions $options): resource|string` (strumień/binary), gdzie `PdfRenderOptions` (format A4/Letter, orientacja, marginesy, base path dla assetów, DPI) to lekki VO w Contracts. Zdefiniować `PdfRenderException`.
  - Zaimplementować `App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer implements PdfRenderer` — in-process, konfiguracja Dompdf (isRemoteEnabled wg decyzji B — osadzanie zdjęć, chroot bezpieczny), memory-bounded na tyle na ile Dompdf pozwala.
  - Wybór adaptera przez config/env: `CATALOG_PDF_RENDERER=dompdf|gotenberg` (default `dompdf`), auto-fallback do dompdf gdy brak `GOTENBERG_URL`. Rejestracja DI (services yaml — sprawdzić po squashach, lekcja #2084).
  - POC: mała komenda/test renderująca statyczny HTML (`<h1>` + tabela + obrazek z base64) → PDF do `/tmp` — potwierdza że port + Dompdf działają.
  - Testy: `render()` zwraca poprawny PDF (nagłówek `%PDF-`), `PdfRenderException` dla niepoprawnego wejścia; smoke `pdfinfo`.
- **Poza zakresem:** `GotenbergRenderer` — CPDF-P6-03. Silnik szablonu Twig / brand tokens — M2. Sanityzacja HTML — CPDF-P0-04. Delivery/MinIO — M3.
- **AC:**
  - [ ] `dompdf/dompdf` w `composer.json` + `composer.lock` (najnowsza stabilna).
  - [ ] Port `PdfRenderer` istnieje w `src/Export/Contracts/`; `DompdfRenderer` w `src/Export/Catalog/Infrastructure/Renderer/` implementuje go.
  - [ ] `DompdfRenderer` jest jedyną klasą dotykającą `\Dompdf\Dompdf`.
  - [ ] Wybór adaptera przez `CATALOG_PDF_RENDERER` env; brak `GOTENBERG_URL` → auto-dompdf (bez błędu).
  - [ ] `render()` na statycznym HTML zwraca binary zaczynający się od `%PDF-`; `pdfinfo` parsuje bez błędu.
  - [ ] PHPStan max, Deptrac 0 (DompdfRenderer nie sięga Catalog/Channel Internals), PHPUnit ≥80%, `composer audit` 0 high/critical.
- **Smoke:** Uruchomić POC/test renderujący statyczny HTML → `/tmp/cpdf-poc.pdf`; `pdfinfo /tmp/cpdf-poc.pdf` (lub `file`) → `PDF document`, ≥1 strona; brak błędów w logu.
- **Reuse:** `apps/api/src/Export/Feed/Application/Delivery/FeedCacheStorage.php` + `Infrastructure/Delivery/FlysystemFeedCacheStorage.php` — wzorzec „seam w Application + impl w Infrastructure" · `apps/api/src/Export/Infrastructure/Writer/` — styl writerów Export · `apps/api/config/services*.yaml` — rejestracja DI adaptera
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §2 (#2), §6.2 · `docs/adr/0027-catalog-pdf-renderer-port.md` (decyzja port + adaptery)
- **DoD:** standard.

### CPDF-P0-04: feat(export): add HtmlValueSanitizer for product values in PDF templates
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 6-9h · **Risk:** high · `[SEC]`
- **Blocked by:** CPDF-P0-01 · **Blocks:** CPDF-P2-03
- **Po co:** Wartości produktowe (nazwa z `<script>`, opis z niedomkniętym `<div>`, znak `\x0B`) wchodzą do szablonu HTML, z którego renderuje się PDF. Bez sanityzacji mogą rozbić layout albo wstrzyknąć treść (analog CSV/XML injection — lekcja IMP2 #1552 / XmlWriterCore). Twig autoescape chroni wartości tekstowe, ale opisy z dozwolonym HTML wymagają whitelisty. To bezpieczeństwo-krytyczny, współdzielony rdzeń wszystkich archetypów — dlatego failing-test-first.
- **Stan obecny:** Brak sanityzacji HTML w kontekście renderu. Twig jest w projekcie (używany gdzie indziej). `ValueSerializer` zwraca stringi z JSONB (może zawierać HTML w opisach). Brak dedykowanej biblioteki sanityzacji — do wyboru (np. `symfony/html-sanitizer`, najnowsza stabilna) lub whitelist własny.
- **Zakres:**
  - Zdefiniować `App\Export\Catalog\Domain\HtmlValueSanitizer` z polityką per-slot: `escape` (default — pełny escape, dla nazw/cen/SKU), `richtext` (whitelist tagów: `p,br,strong,em,ul,ol,li,a[href]` — dla opisów), `strip` (usuń wszystkie tagi).
  - Usuwanie nielegalnych znaków sterujących (spójne z `XmlWriterCore` sanitizer: `\x00-\x08,\x0B,\x0C,\x0E-\x1F`), zachowanie tab/newline.
  - Potwierdzić/skonfigurować Twig `autoescape` ON dla szablonów katalogów (M2 na tym polega).
  - Failing-test-first (SEC): najpierw testy że dla śmieciowych inputów (`<script>alert(1)</script>`, niedomknięty tag, `javascript:` w `href`, `\x0B`, emoji) output jest bezpieczny i nie łamie struktury HTML — DOPIERO potem implementacja.
- **Poza zakresem:** Silnik szablonu / bindowanie slotów — M2 (CPDF-P2-02/03). Renderer PDF — CPDF-P0-03.
- **AC:**
  - [ ] `HtmlValueSanitizer` istnieje z politykami `escape`/`richtext`/`strip` wybieralnymi per-slot (nie hardkod).
  - [ ] Polityka `richtext` przepuszcza tylko whitelistę tagów; `<script>`, `onerror=`, `javascript:href` usunięte/zneutralizowane.
  - [ ] Nielegalne control-chars usunięte, tab/newline zachowane.
  - [ ] Test property/fuzz: dla zestawu śmieciowych stringów output nie zawiera wykonywalnej/łamiącej treści; test napisany PRZED implementacją (widoczny failing-first w historii commitów).
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%, `composer audit` czysto.
- **Smoke:** Skrypt/test renderujący `HtmlValueSanitizer` na `'Kabel <script>alert(1)</script> <b>HDMI</b> \x0B'` + opisie z `<a href="javascript:...">` — output nie zawiera `<script>` ani `javascript:`; legalne `<b>`/`<a href="https">` zachowane wg polityki.
- **Reuse:** `apps/api/src/Export/Infrastructure/Writer/XmlWriterCore.php` — wzorzec sanitizera control-chars + polityka per-wywołanie · `apps/api/src/Export/Application/Builder/ValueSerializer.php` — źródło stringów (null→'', multi-value `|`)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §5 pkt 7, §1 (bezpieczeństwo) · `agent/lessons.md` — IMP2-2.8 #1552 CSV formula injection (analog)
- **DoD:** standard.

---

# M1 — Model CatalogProfile/CatalogRun + schema + RLS + MinIO + CRUD

### CPDF-P1-01: feat(export): add CatalogProfile and CatalogRun entities with tenant RLS
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** CPDF-P0-02 · **Blocks:** CPDF-P1-02, CPDF-P1-03
- **Po co:** Model domenowy dokumentu (definicja + historia generacji) to fundament CRUD, async i delivery. Kalka `FeedProfile`/`FeedRun` — ta sama mechanika (tenant scoped, RLS, cache pointer, enum statusu), inne pola (branding + mapowanie pól + renderer zamiast descriptora XML).
- **Stan obecny:** `src/Export/Feed/Domain/Entity/FeedProfile.php`, `FeedRun.php`, `FeedRunLog.php` + enumy (`FeedRunStatus` Pending/Running/Done/Error/Cancelled, `FeedRunTrigger` Manual/Scheduled/Agent, `FeedStatus`, `FeedTemplateKind`) jako wzorzec. Migracje w `apps/api/migrations/VersionYYYYMMDDHHmmss.php` z RLS policies (`tenant_isolation_*`, `super_admin_bypass_*`). `src/Export/Catalog/` jeszcze nie istnieje (warstwy Deptrac z CPDF-P0-02).
- **Zakres:**
  - `CatalogProfile` (aggregate root) w `src/Export/Catalog/Domain/Entity/`: `code`, `name`, `templateKind` (enum sheet/grid/pricelist), `objectTypeId` (bare UUID ref, ADR-0015, MVP=product), `branding` JSONB (logo, paleta, dane firmy, okładka), `fieldMappings` JSONB (slot→atrybut/transform), `filter` JSONB (DSL), `channelId`/`publicationChannel`, `locale`, `renderer` (auto/dompdf/gotenberg), `tokenHash`, cache pointer (`cachedFilePath`/`cachedFileSize`/`cachedPageCount`/`cachedAt`), `status`, timestamps.
  - `CatalogRun`: `catalogProfileId`, `trigger`, `status`, `pageCount`, `byteSize`, `itemCount`, `durationMs`, `errorMessage`, timestamps. Opcjonalnie `CatalogRunLog` (health trail per produkt) jeśli potrzebny przy walidacji renderu.
  - Enumy `CatalogRunStatus` (kalka `FeedRunStatus`, `isTerminal()`), `CatalogRunTrigger` (Manual/Scheduled/Agent), `CatalogTemplateKind` (Sheet/Grid/Pricelist), `CatalogStatus` (Active/Paused/Error).
  - Migracja `catalog_profiles` + `catalog_runs` (+`catalog_run_logs` jeśli): `tenant_id UUID NOT NULL`, UNIQUE(tenant_id, code), indeksy (tenant_id, updated_at) / (catalog_profile_id, started_at), RLS policies (`tenant_isolation_*` + `super_admin_bypass_*`).
  - Doctrine ORM mapping (XML w Infrastructure — ADR-0011), TenantScoped (TenantFilter + `TenantAssignmentListener`).
- **Poza zakresem:** Repozytoria — CPDF-P1-02. CRUD controller — CPDF-P1-03. Silnik szablonu / renderer wiring — M2. `field_mappings`/`branding` walidacja kształtu — M2 (guard).
- **AC:**
  - [ ] `CatalogProfile`/`CatalogRun` (+enumy) istnieją w `src/Export/Catalog/Domain/`; kolumny wg planu §6.3.
  - [ ] Migracja tworzy `catalog_profiles`/`catalog_runs` z `tenant_id NOT NULL`, UNIQUE(tenant_id, code), RLS policies (tenant_isolation + super_admin_bypass).
  - [ ] `object_type_id`/`channel_id` jako bare UUID (ADR-0015), nie FK cross-BC.
  - [ ] Multi-tenant: cross-tenant read = 0 wyników (RLS + TenantFilter); test izolacji.
  - [ ] `CatalogRunStatus::isTerminal()` dla Done/Error/Cancelled.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%, `doctrine:schema:validate` zielony.
- **Smoke:** Uruchomić migrację na dev DB; `psql` potwierdza tabele + RLS policies; utworzyć 2 tenantów, wstawić profil w tenant A, próba read z kontekstu tenant B = 0 wyników.
- **Reuse:** `apps/api/src/Export/Feed/Domain/Entity/FeedProfile.php`/`FeedRun.php`/`FeedRunLog.php` + `Domain/Enum/Feed*.php` — kalka struktury · `apps/api/migrations/Version20260701120000.php` (feed_profiles) / `Version20260701130000.php` (feed_runs) — wzorzec DDL + RLS · `apps/api/src/Shared/**` TenantScoped/TenantAssignmentListener
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.3 · `docs/adr/0015-cross-bc-fk-policy.md`, `docs/adr/0011`, `docs/adr/0027`
- **DoD:** standard.

### CPDF-P1-02: feat(export): add Doctrine repositories for catalog profiles and runs
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 5-7h · **Risk:** low
- **Blocked by:** CPDF-P1-01 · **Blocks:** CPDF-P1-03, CPDF-P3-01
- **Po co:** CRUD, delivery (lookup po token hash), monitor (keyset pagination + KPI 24h) potrzebują repozytoriów. Kalka Doctrine repo Feed.
- **Stan obecny:** `DoctrineFeedProfileRepository` (`findByTokenHash`, `findByTenantAndCode`, `findByTenant`, `save`/`remove`) + `DoctrineFeedRunRepository` (`findPage` keyset na `id DESC` UUIDv7, `kpi24h`) + interfejsy w `Domain/Repository/` jako wzorzec.
- **Zakres:**
  - Interfejsy `CatalogProfileRepositoryInterface` (`save`/`remove`/`findById`/`findByTokenHash`/`findByTenantAndCode`/`findByTenant`) i `CatalogRunRepositoryInterface` (`save`/`findById`/`findByCatalogProfile`/`findPage`/`kpi24h`) w `Domain/Repository/`.
  - Implementacje Doctrine w `Infrastructure/Doctrine/Repository/` (extend `ServiceEntityRepository`); `findPage` keyset na `id DESC` (UUIDv7 = started_at order); `kpi24h` agregacja 24h (regeneracje/errory/last_error/pages).
  - Rejestracja DI.
- **Poza zakresem:** Pull stats telemetria — CPDF-P3-03. Controllery — CPDF-P1-03/M3.
- **AC:**
  - [ ] Interfejsy + implementacje Doctrine istnieją; `findByTokenHash` zwraca profil po hashu, brak enumeration signal.
  - [ ] `findPage` keyset-paginated (cursor + limit), stabilna kolejność `id DESC`.
  - [ ] `kpi24h` zwraca agregaty (regeneracje/errory/last_error) dla tenanta w oknie 24h.
  - [ ] Multi-tenant: repo respektuje RLS/TenantFilter (cross-tenant = 0).
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80% (Integration test na realnym Postgres — no-mock).
- **Smoke:** Integration test: zapis profilu + run, `findPage` zwraca run, `kpi24h` liczy poprawnie; `findByTokenHash` dla złego hasha = null.
- **Reuse:** `apps/api/src/Export/Feed/Infrastructure/Doctrine/Repository/DoctrineFeedProfileRepository.php`/`DoctrineFeedRunRepository.php` · `apps/api/src/Export/Feed/Domain/Repository/*Interface.php`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.3 · CPDF-P1-01
- **DoD:** standard.

### CPDF-P1-03: feat(export): add CatalogProfile CRUD controller with catalogs_pdf RBAC module
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** CPDF-P1-01, CPDF-P1-02 · **Blocks:** CPDF-P4-02, CPDF-P5-02
- **Po co:** Klient definiuje dokument przez API (CRUD profilu) — pierwsza publiczna powierzchnia funkcji. Wprowadza nowy moduł RBAC `catalogs_pdf` (zgodny z menu `ref`), na którym opierają się wszystkie kolejne endpointy.
- **Stan obecny:** `FeedProfileController` (`GET/POST/PATCH/DELETE /api/feeds` + pause/resume) z `#[RequiresPermission(module: 'exports'|'integration', action: ...)]` jako wzorzec. RBAC moduły deklarowane atrybutem + walidowane `RequiresPermissionAnnotationRule`; enforcement `EndpointGuardListener`. Menu `catalogs_pdf` istnieje (`protected:false`).
- **Zakres:**
  - `CatalogProfileController` (custom `#[Route]` `/api/catalogs`): `list`/`create` (z template kind + branding + field_mappings + filter + scope)/`get`/`patch`/`delete` (+ opcjonalnie pause/resume). Serializacja bez `token_hash` w response.
  - Nowy moduł RBAC `catalogs_pdf` z akcjami `read`/`create`/`admin` (rejestracja w rejestrze modułów/uprawnień zgodnie z RBAC PRD). `#[RequiresPermission(module: 'catalogs_pdf', action: 'read')]` na GET, `create` na POST, `admin` na PATCH/DELETE.
  - DTO/walidacja wejścia (RFC 7807 Problem Details dla błędów).
  - ApiTestCase: 401 (brak auth) / 403 (brak permisji) / 404 / walidacja / happy path dla każdej metody.
- **Poza zakresem:** Generate/preview/pull — M3/M4. Walidacja kształtu `branding`/`field_mappings` względem archetypu — M2 guard. FE — M5.
- **AC:**
  - [ ] `CatalogProfileController` obsługuje list/create/get/patch/delete pod `/api/catalogs`.
  - [ ] Moduł RBAC `catalogs_pdf` (read/create/admin) zarejestrowany; endpointy mają `#[RequiresPermission]`.
  - [ ] Response nie zawiera `token_hash`; błędy w formacie RFC 7807.
  - [ ] ApiTestCase pokrywa 401/403/404/walidację/happy path dla każdej metody.
  - [ ] Multi-tenant: user z tenant A nie widzi profili tenant B.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** `pim.localhost` login → `POST /api/catalogs` (template sheet) → 201; `GET /api/catalogs` zawiera profil; restricted user (dev-token bez `catalogs_pdf.create`) → 403 (lekcja bulk-endpoint permission).
- **Reuse:** `apps/api/src/Export/Feed/Presentation/Controller/FeedProfileController.php` — kalka CRUD + serializacja · `apps/api/src/Identity/**` — rejestracja modułu/permisji + `#[RequiresPermission]` + `EndpointGuardListener`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.7, §6.8 · `Project Plan/PRD/PRD-PIM-rbac.md` (macierz modułów) · ADR-0020
- **DoD:** standard.

### CPDF-P1-04: feat(export): add MinIO cache storage seam for generated catalogs
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 4-6h · **Risk:** low
- **Blocked by:** CPDF-P1-01 · **Blocks:** CPDF-P3-01, CPDF-P3-02
- **Po co:** Wygenerowany PDF trafia do MinIO (`catalogs/{tenant}/{id}.pdf`), skąd publiczny URL go serwuje (cache-and-serve). Kalka `FeedCacheStorage`/`FlysystemFeedCacheStorage` — seam żeby async handler i pull controller nie znały Flysystem bezpośrednio.
- **Stan obecny:** `FeedCacheStorage` (Application seam: `exists`/`put`/`read`) + `FlysystemFeedCacheStorage` (Infrastructure impl) + `flysystem.yaml` z bucket feedów (`feeds/{tenant}/…`). MinIO gotowe. Lekcja: MinIO degraded → restart heals.
- **Zakres:**
  - `CatalogCacheStorage` (Application seam): `exists(key)`/`put(key, localPath)`/`read(key): resource`/`delete(key)`.
  - `FlysystemCatalogCacheStorage` (Infrastructure) → bucket/prefix `catalogs/{tenant_id}/{catalog_id}.pdf`; konfiguracja w `flysystem.yaml`.
  - `CatalogEtag` (kalka `FeedEtag::forCache(cachedAt, fileSize, pageCount)`) dla warunkowej rewalidacji (M3).
  - Rejestracja DI.
- **Poza zakresem:** Publiczny pull controller — CPDF-P3-02. Generate handler — CPDF-P3-01.
- **AC:**
  - [ ] `CatalogCacheStorage` seam + `FlysystemCatalogCacheStorage` impl istnieją; klucz `catalogs/{tenant}/{id}.pdf`.
  - [ ] `flysystem.yaml` ma storage katalogów; `put`/`read`/`exists`/`delete` działają na MinIO.
  - [ ] `CatalogEtag::forCache(...)` deterministyczny.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80% (Integration test na realnym MinIO — no-mock storage).
- **Smoke:** Integration test: `put` pliku PDF → `exists` true → `read` zwraca ten sam content; klucz zawiera tenant + catalog id.
- **Reuse:** `apps/api/src/Export/Feed/Application/Delivery/FeedCacheStorage.php` + `Infrastructure/Delivery/FlysystemFeedCacheStorage.php` + `FeedEtag.php` · `apps/api/config/packages/flysystem.yaml`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §4, §6.5 · `docs/adr/0023` (cache-and-serve)
- **DoD:** standard.

---

# M2 — Silnik szablonu (Twig + brand tokens) + archetyp sheet + seed

### CPDF-P2-01: feat(export): add CatalogTemplateCatalog with built-in sheet archetype and brand tokens
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** CPDF-P1-01 · **Blocks:** CPDF-P2-02, CPDF-P2-03
- **Po co:** Jeden parametryzowalny silnik szablonu (decyzja #3): archetyp layoutu = bazowy template Twig, branding = wstrzykiwane brand tokens (CSS variables), mapowanie pól = które atrybuty w slotach. Ten ticket dostarcza katalog szablonów + pierwszy archetyp `sheet` (karta produktu — najniższe ryzyko renderera). Kalka `FeedTemplateCatalog` (in-memory, built-in).
- **Stan obecny:** `FeedTemplateCatalog` (singleton, `get(kind)`/`all()`, hardkodowane descriptory + default mappings, `is_built_in`) jako wzorzec. Twig w projekcie. Brak jakichkolwiek szablonów PDF.
- **Zakres:**
  - `CatalogTemplateCatalog` (in-memory): `get(CatalogTemplateKind)`/`all()`; wpis dla `sheet` z: bazowym szablonem Twig, listą slotów (nazwa/typ/format/required/maxLength), default field mappings.
  - Bazowy szablon Twig `sheet` w `Infrastructure/Template/` (lub `templates/catalog/`): duże zdjęcie + tytuł + opis + tabela specyfikacji + stopka; `autoescape` ON (CPDF-P0-04).
  - Brand tokens: CSS variables (`--brand-color`, `--brand-logo`, dane firmy) wstrzykiwane z `CatalogProfile.branding`; sensowne defaulty gdy branding pusty.
  - Guard kształtu `branding`/`field_mappings` (kalka `FeedDescriptorGuard`): walidacja że sloty archetypu istnieją, kolory to hex, logo to URL/asset ref.
  - Testy: `get('sheet')` zwraca template + sloty; guard odrzuca zły branding.
- **Poza zakresem:** Bindowanie danych do slotów — CPDF-P2-02. Render do PDF — CPDF-P2-03. Archetypy grid/pricelist — M6.
- **AC:**
  - [ ] `CatalogTemplateCatalog::get(Sheet)` zwraca template z listą slotów + default mappings; `all()` listuje archetypy built-in.
  - [ ] Szablon Twig `sheet` istnieje, `autoescape` ON, brand tokens jako CSS variables.
  - [ ] Guard waliduje branding (hex kolory, logo ref) + field_mappings (sloty istnieją); zły input → wyjątek/violations.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** Test: `get('sheet')` + wstrzyknięcie sample branding → wyrenderowany HTML (bez PDF) zawiera `--brand-color` i sloty; guard na `branding={color:'notahex'}` → violation.
- **Reuse:** `apps/api/src/Export/Feed/Domain/Template/FeedTemplateCatalog.php`/`FeedTemplate.php` — kalka katalogu · `apps/api/src/Export/Feed/Application/Descriptor/FeedDescriptorGuard.php` — wzorzec guard kształtu
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §3, §6.4, §2 (#3)
- **DoD:** standard.

### CPDF-P2-02: feat(export): bind ExportBuilder rows to Twig template slots via field_mappings
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** CPDF-P2-01, CPDF-P1-02 · **Blocks:** CPDF-P2-03
- **Po co:** Szablon musi dostać dane produktów. Reuse `ExportBuilder` (który już zwraca `Generator<array<string,string>>` ze scope + `clear()`) — katalog PDF nie pisze własnego selektora ani odczytu wartości (decyzja §2/§4). Ten ticket to warstwa mapowania: wiersz `ExportBuilder` → sloty szablonu wg `field_mappings`, z resolverem URL assetu dla zdjęć.
- **Stan obecny:** `ExportBuilder::build(iterable<CatalogObject>, ExportSession): Generator<int, array<string,string>>`; `ColumnResolver` (klucze `sku`/`name.pl`/`name.shopify`); `ValueSerializer` (multi-value `|`, null→'', asset→URL); `PublicationColumnPlanner` (scope z publication profile). `FeedItemMapper`/`FeedFieldMapping` jako wzorzec mapowania w Feed. Adapter `FeedProductValues`/`ExportBuilderFeedValues` (seam Export→scope) jako wzorzec.
- **Zakres:**
  - `CatalogItemMapper` w `Domain/Mapping/`: bierze wiersz `array<string,string>` z ExportBuilder + `field_mappings` z profilu → mapa `slot → wartość` gotowa do szablonu.
  - Wykorzystać scope: `objectTypeId` + `filter` (DSL) + `locale` + `channel`/publication profile → parametry dla ExportBuilder (przez seam w `Export/Contracts` jeśli potrzebny, jak `FeedProductScope`/`FeedProductValues`).
  - Resolver URL assetu dla slotów obrazkowych (główne zdjęcie + galeria) — reuse logiki `ValueSerializer` asset→URL; decyzja B (inline base64 vs URL) sparametryzowana na porcie renderera (Dompdf: base64/temp, Gotenberg: URL) — tu zwracamy URL/ref, adapter decyduje w M2/M6.
  - Multi-value (galeria) → lista wartości dla slotu powtarzalnego.
  - Testy: mapowanie klucz→slot, brakujący atrybut → pusty slot, multi-value → lista, asset → URL.
- **Poza zakresem:** Render Twig→PDF — CPDF-P2-03. Transformacje (price format/enum map) — poza MVP (hook §7). Osadzanie base64 w Dompdf — CPDF-P2-03/decyzja B.
- **AC:**
  - [ ] `CatalogItemMapper` mapuje wiersz ExportBuilder → sloty wg `field_mappings`; brakujący atrybut → pusty/pominięty slot (spójnie, udokumentowane).
  - [ ] Scope (objectType + filter DSL + locale + kanał/publication) steruje ExportBuilder; reuse `PublicationColumnPlanner`.
  - [ ] Sloty obrazkowe dostają URL/ref assetu (reuse ValueSerializer); multi-value → lista.
  - [ ] Cross-BC do Catalog/Channel/Asset tylko przez Contracts (Deptrac 0).
  - [ ] PHPStan max, PHPUnit ≥80%.
- **Smoke:** Test: profil z `field_mappings` (sku→slot title, description.pl→slot desc, image_link→slot image) na sample produkcie → mapa slotów ma poprawne wartości + URL zdjęcia.
- **Reuse:** `apps/api/src/Export/Application/Builder/ExportBuilder.php`/`ColumnResolver.php`/`ValueSerializer.php`/`PublicationColumnPlanner.php` · `apps/api/src/Export/Feed/Domain/Mapping/FeedItemMapper.php`/`FeedFieldMapping.php` — wzorzec mapowania · `apps/api/src/Export/Contracts/` (`FeedProductScope`/`FeedProductValues` jako wzorzec seam)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §3, §4, §6.4, §8 (decyzja B)
- **DoD:** standard.

### CPDF-P2-03: feat(export): add CatalogRenderService rendering sheet HTML to PDF
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** CPDF-P0-03, CPDF-P0-04, CPDF-P2-01, CPDF-P2-02 · **Blocks:** CPDF-P3-01, CPDF-P4-01
- **Po co:** To spina wszystko z M0/M2 w jeden serwis: szablon + dane → HTML (sanityzowany) → `PdfRenderer` → PDF. Pierwszy archetyp (`sheet`) end-to-end, gotowy do wpięcia w async (M3) i preview (M4). Analog `FeedGenerator` (który składa descriptor + dane + writer).
- **Stan obecny:** `PdfRenderer` port + `DompdfRenderer` (CPDF-P0-03); `HtmlValueSanitizer` (CPDF-P0-04); `CatalogTemplateCatalog` + szablon sheet (CPDF-P2-01); `CatalogItemMapper` + ExportBuilder scope (CPDF-P2-02). `FeedGenerator::generate(profile, targetPath, trigger, run, onChunk)` jako wzorzec orkiestracji.
- **Zakres:**
  - `CatalogRenderService` w `Application/`: dla `CatalogProfile` iteruje produkty ze scope (ExportBuilder, chunk 200 + `clear()`), mapuje do slotów (`CatalogItemMapper`), sanityzuje (`HtmlValueSanitizer`), renderuje Twig HTML (brand tokens z profilu), oddaje do `PdfRenderer` → binary/strumień do `targetPath`.
  - Dla archetypu `sheet`: 1 produkt = 1 karta/strona; wielu produktów = kolejne strony (paginacja przez `@page`/CSS Paged Media — podstawy Dompdf).
  - Osadzanie zdjęć wg decyzji B: Dompdf → strumień z Flysystem → base64/temp; parametr na porcie.
  - Zwraca metadane (page_count, byte_size, item_count) do zapisu w `CatalogRun`.
  - Testy: render sheet dla 1 i N produktów → poprawny PDF (`%PDF-`, ≥N stron); śmieciowe dane nie wywalają renderu (delegacja do sanitizera).
- **Poza zakresem:** Async worker + Mercure — CPDF-P3-01. Delivery/MinIO upload — CPDF-P3-01/P1-04. Preview HTML — CPDF-P4-01. Archetypy grid/pricelist — M6.
- **AC:**
  - [ ] `CatalogRenderService` renderuje archetyp `sheet` end-to-end: profil → PDF (`%PDF-`, `pdfinfo` OK).
  - [ ] Iteracja produktów chunkami 200 z `EntityManager::clear()` (memory-bounded, §3.10) — nie ładuje wszystkiego naraz do Doctrine.
  - [ ] Śmieciowe dane produktowe (HTML w opisie, control-chars) → render nie wywala się, PDF well-formed.
  - [ ] Zwraca page_count/byte_size/item_count.
  - [ ] PHPStan max, Deptrac 0 (tylko przez Contracts), PHPUnit ≥80%.
- **Smoke:** Test/skrypt: profil sheet + 3 produkty (jeden z `<script>` w opisie i galerią 2 zdjęć) → `/tmp/cpdf-sheet.pdf`; `pdfinfo` → ≥3 strony, `PDF document`; brak `<script>` w treści.
- **Reuse:** `apps/api/src/Export/Feed/Application/Generator/FeedGenerator.php` — wzorzec orkiestracji (iteruj+mapuj+waliduj+serializuj+onChunk 200) · `apps/api/src/Export/Contracts/PdfRenderer` (CPDF-P0-03) · `CatalogTemplateCatalog`/`CatalogItemMapper`/`HtmlValueSanitizer`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §3, §6.4, §6.6, §5 (Paged Media) · `docs/adr/0027`
- **DoD:** standard.

---

# M3 — Async generate + Mercure + delivery (token URL, cache-and-serve)

### CPDF-P3-01: feat(export): add GenerateCatalogHandler async worker with Mercure progress
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 8-12h · **Risk:** high
- **Blocked by:** CPDF-P2-03, CPDF-P1-04, CPDF-P1-02 · **Blocks:** CPDF-P3-03
- **Po co:** Render katalogu (setki produktów, Dompdf trzyma dokument w pamięci) musi iść asynchronicznie z progresem i anulowaniem, memory-bounded pod FrankenPHP worker mode (§3.10 — inaczej OOM). Kalka `FeedRunHandler`+`FeedRegenerator`: message handler → render → upload MinIO → aktualizacja cache pointer → progres Mercure.
- **Stan obecny:** `FeedRunHandler` (`#[AsMessageHandler]` na `RunFeedMessage`, extends `AbstractBatchHandler`, idempotencja przez pending guard, TenantContext, progres+cancel callback, Mercure terminal status) + `FeedRegenerator` (temp file → MinIO → cache pointer) jako wzorzec. Messenger `import` transport (dev worker bierze `import`+`scheduler_maintenance` — lekcja export-async). Mercure topic `feed-runs.{id}`.
- **Zakres:**
  - `RunCatalogMessage` (`catalogRunId`, `tenantId`) + routing na `import` transport (`TenantAwareMessage`).
  - `GenerateCatalogHandler` (`#[AsMessageHandler]`, extends `AbstractBatchHandler`): load run, idempotency guard (Pending), set TenantContext (GUC/RLS), wywołanie `CatalogRenderService` z callbackiem progresu (co chunk 200, publish Mercure `catalog-runs.{id}`, poll cancel), upload PDF do MinIO (`CatalogCacheStorage`), atomowa aktualizacja cache pointer (`cachedFilePath`/`cachedPageCount`/`cachedAt`), publish terminal status. Bez re-throw (stan zapisany w run).
  - `CatalogProgressPublisher` + `CatalogCancelledException` (kalka Feed).
  - Idempotencja: duplikat dostawy = no-op.
  - Restart worker/api po zmianie klasy message (lekcja export-async).
- **Poza zakresem:** Regenerate endpoint (dispatch) — CPDF-P3-03. Public pull — CPDF-P3-02. Bulk — CPDF-P3-04. Cap/chunk dla dużych (decyzja A) — CPDF-P6-04.
- **AC:**
  - [ ] `GenerateCatalogHandler` konsumuje `RunCatalogMessage`, renderuje przez `CatalogRenderService`, uploaduje PDF do MinIO, aktualizuje cache pointer.
  - [ ] Batch 200 + `EntityManager::clear()`; brak OOM na średnim katalogu (worker memory płaska).
  - [ ] Progres publikowany przez Mercure `catalog-runs.{id}`; anulowanie działa (`CatalogCancelledException`).
  - [ ] Idempotencja: powtórna dostawa message dla terminalnego run = no-op.
  - [ ] Terminal status (Done/Error) zapisany w `CatalogRun` + opublikowany; bez re-throw.
  - [ ] PHPStan max, Deptrac 0, PHPUnit/Integration ≥80%.
- **Smoke:** `pim.localhost`: dispatch generate (przez test/console) dla profilu sheet z ~10 produktami → worker przetwarza → `CatalogRun` status Done, `cachedFilePath` ustawiony, PDF w MinIO (`docker exec` `mc ls` / psql); Mercure event widoczny.
- **Reuse:** `apps/api/src/Export/Feed/Application/Async/FeedRunHandler.php`/`FeedProgressPublisher.php`/`FeedCancelledException.php` · `apps/api/src/Export/Feed/Application/Generator/FeedRegenerator.php` — temp→MinIO→cache pointer · `apps/api/src/Shared/**AbstractBatchHandler` · `CatalogRenderService`/`CatalogCacheStorage`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.6, §6.5 · `01-architektura-pim.md` §3.10 · `agent/lessons.md` (export-async / clear())
- **DoD:** standard.

### CPDF-P3-02: feat(export): add public catalog pull with token URL and cache-and-serve
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 8-12h · **Risk:** high · `[SEC]`
- **Blocked by:** CPDF-P1-01, CPDF-P1-04 · **Blocks:** CPDF-P5-03
- **Po co:** „Stabilny URL z tokenem" (model Plytix live URL) — klient udostępnia dokument bez sesji, crawler/partner pobiera cache'owany PDF. Token w URL ≠ klucz tenanta; tenant z tokenu → RLS. Kalka `FeedPullController`+`FeedTokenService`. Failing-test-first (publiczny endpoint = powierzchnia ataku).
- **Stan obecny:** `FeedTokenService` (`mint` 24B→base64url, `hash` HMAC-SHA256, `matches` const-time, tylko hash persystowany) + `FeedPullController` (`GET /api/feeds/pull/{tenantId}/{token}.xml`, `#[NoPermissionRequired]`, GUC tenant → RLS → lookup po token hash → const-time compare → rate-limit → stream cache, ETag/304). `security.yaml` PUBLIC_ACCESS regex dla feed pull. Lekcja: auth rate-limit; live-smoke psql owner omija RLS.
- **Zakres:**
  - `CatalogTokenService` (kalka Feed): `mint`/`rotate`/`revoke`/`hash`/`matches`; tylko hash w `token_hash`.
  - `CatalogPullController`: `GET /catalogs/pull/{tenantId}/{token}.pdf`, `#[NoPermissionRequired]`; parse tenantId → set GUC `app.current_tenant` → TenantFilter → `findByTokenHash` → const-time `matches` → rate-limit per token → stream cache PDF (`CatalogCacheStorage::read`), `Content-Type: application/pdf`, `Content-Disposition`, ETag (`CatalogEtag`)/304. NIGDY nie generuje na pull (tylko cache).
  - `security.yaml`: dodać PUBLIC_ACCESS regex dla `^/catalogs/pull/[0-9a-fA-F-]{36}/[A-Za-z0-9_-]+\.pdf$`.
  - Token controller (`/api/catalogs/{id}/token` mint/rotate/revoke) pod `catalogs_pdf.admin`.
  - Failing-test-first (SEC): 404 dla złego tokenu bez enumeration signal, const-time compare, rate-limit, cross-tenant token nie działa, brak cache → 404/409 (nie generuje).
- **Poza zakresem:** Regenerate/monitor — CPDF-P3-03. FE share-link — CPDF-P5-03.
- **AC:**
  - [ ] `GET /catalogs/pull/{tenantId}/{token}.pdf` serwuje cache'owany PDF (200 + `application/pdf`), NIGDY nie generuje.
  - [ ] Tylko `token_hash` persystowany; `matches` const-time (`hash_equals`); zły token → 404 bez enumeration signal.
  - [ ] `security.yaml` PUBLIC_ACCESS regex dla trasy pull; endpoint dostępny bez sesji, tenant z URL → RLS.
  - [ ] ETag/304 działa; rate-limit per token (DoS mitigation) po weryfikacji, przed odczytem storage.
  - [ ] Token mint/rotate/revoke pod `catalogs_pdf.admin`; testy SEC napisane failing-first.
  - [ ] PHPStan max, Deptrac 0, `composer audit` czysto, PHPUnit ≥80%.
- **Smoke:** `pim.localhost`: wygenerować katalog (CPDF-P3-01) → mint token → `curl -I https://pim.localhost/catalogs/pull/{tenant}/{token}.pdf` → 200 `application/pdf` + ETag; `curl` z If-None-Match → 304; zły token → 404; skopiować HTTP code + nagłówki do issue.
- **Reuse:** `apps/api/src/Export/Feed/Application/Delivery/FeedTokenService.php` · `apps/api/src/Export/Feed/Presentation/Controller/FeedPullController.php`/`FeedTokenController.php` · `apps/api/config/packages/security.yaml` (feed pull PUBLIC_ACCESS regex) · `CatalogCacheStorage`/`CatalogEtag`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.5, §1 (bezpieczeństwo), §5 (cache-and-serve) · `docs/adr/0023` (token-in-URL)
- **DoD:** standard.

### CPDF-P3-03: feat(export): add catalog regenerate, run monitor and KPI endpoints
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** CPDF-P3-01, CPDF-P1-02 · **Blocks:** CPDF-P5-03, CPDF-P5-04
- **Po co:** „Regeneruj teraz" (odświeżenie cache) + historia runów + KPI zasilają FE (lista runów, progres, dashboard). Kalka `FeedRegenerateController`+`FeedRunMonitorController`.
- **Stan obecny:** `FeedRegenerateController` (`POST /api/feeds/{id}/regenerate` → tworzy run, dispatch `RunFeedMessage`, 202 + Mercure topic) + `FeedRunMonitorController` (`GET /api/feed-runs` keyset + status filter, `GET /api/feed-runs/kpi`) + `FeedRunHistoryController`/`FeedRunCancelController`. `#[RequiresPermission]`.
- **Zakres:**
  - `CatalogRegenerateController`: `POST /api/catalogs/{id}/generate` (`catalogs_pdf.create`) → tworzy `CatalogRun` + dispatch `RunCatalogMessage`, zwraca 202 + Mercure topic.
  - `CatalogRunMonitorController`: `GET /api/catalog-runs?status=&cursor=&limit=` (keyset, `catalogs_pdf.read`), `GET /api/catalog-runs/kpi` (regeneracje/errory/last_error/pages 24h), `GET /api/catalogs/{id}/runs` (historia per katalog), cancel running.
  - ApiTestCase: 202 dla generate, keyset pagination, KPI agregacja, 401/403/404.
- **Poza zakresem:** Bulk generate — CPDF-P3-04. Public pull — CPDF-P3-02. Pull-stats telemetria (opcjonalna) — w tym tickecie jako KPI źródło jeśli proste, inaczej hook.
- **AC:**
  - [ ] `POST /api/catalogs/{id}/generate` tworzy run + dispatch async, zwraca 202 + Mercure topic.
  - [ ] `GET /api/catalog-runs` keyset-paginated z filtrem statusu; `GET /api/catalog-runs/kpi` zwraca agregaty 24h.
  - [ ] Cancel running run działa; `#[RequiresPermission(catalogs_pdf)]` na wszystkich.
  - [ ] ApiTestCase pokrywa 202/keyset/KPI/401/403/404; multi-tenant izolacja.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** `pim.localhost`: `POST /api/catalogs/{id}/generate` → 202; `GET /api/catalog-runs` pokazuje run; po zakończeniu KPI liczy +1 regenerację; skopiować HTTP codes do issue.
- **Reuse:** `apps/api/src/Export/Feed/Presentation/Controller/FeedRegenerateController.php`/`FeedRunMonitorController.php`/`FeedRunHistoryController.php`/`FeedRunCancelController.php`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.7, §9 · CPDF-P3-01
- **DoD:** standard.

### CPDF-P3-04: feat(export): add bulk catalog generate
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** CPDF-P3-01 · **Blocks:** —
- **Po co:** Plytix parity: bulk generate dla kolekcji/kategorii (wiele dokumentów jednym ruchem) — bez tego „gorszy niż Plytix" (research §5 pkt 3). Np. karta produktu per SKU dla całej kategorii, albo regeneracja wielu profili naraz.
- **Stan obecny:** Generate async pojedynczego katalogu (CPDF-P3-01). Wzorzec bulk w repo (bulk-edit) + lekcja bulk-endpoint permission escalation (gating per-action).
- **Zakres:**
  - Endpoint bulk generate: `POST /api/catalogs/bulk-generate` z listą profili / scope (np. „karta per produkt w kategorii X") → dispatch N `RunCatalogMessage`, zwraca 202 + lista run id / batch id.
  - Gating: `catalogs_pdf.create`, sprawdzić per-item authz (nie ominąć per-action, lekcja #bulk-endpoint).
  - Progres batcha przez Mercure (agregat) lub per-run.
  - ApiTestCase: bulk 202, N runów utworzonych, restricted user 403.
- **Poza zakresem:** UI bulk (M5). Cron auto-regen (hook §7).
- **AC:**
  - [ ] `POST /api/catalogs/bulk-generate` tworzy N runów + dispatch, zwraca 202 + identyfikatory.
  - [ ] Gating `catalogs_pdf.create` + per-item authz (bulk nie omija single-item permission).
  - [ ] ApiTestCase: happy path + restricted user 403 + walidacja.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** `POST /api/catalogs/bulk-generate` dla 3 profili → 202 + 3 run id; wszystkie kończą się Done; restricted user → 403.
- **Reuse:** `apps/api/src/Export/Feed/Presentation/Controller/FeedRegenerateController.php` (dispatch wzorzec) · bulk-edit controller pattern · CPDF-P3-01 message/handler
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §5 (pkt 3 bulk), §7 (IN: bulk generate) · `agent/lessons.md` (bulk-endpoint permission escalation)
- **DoD:** standard.

---

# M4 — Preview + OpenAPI + RBAC lockdown

### CPDF-P4-01: feat(export): add catalog HTML preview endpoint for sample products
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 5-7h · **Risk:** low
- **Blocked by:** CPDF-P2-03, CPDF-P1-03 · **Blocks:** CPDF-P5-04
- **Po co:** Podgląd na żywo (§1, §6.4) — użytkownik widzi HTML szablonu dla kilku sample produktów zanim odpali render PDF (szybki feedback bez kosztu renderu). Kalka `FeedPreviewService`/`FeedPreviewController`.
- **Stan obecny:** `FeedPreviewService`/`FeedPreviewController` (sample generation na descriptor change) jako wzorzec. `CatalogRenderService` (CPDF-P2-03) renderuje HTML przed PDF — preview reużywa etap HTML.
- **Zakres:**
  - `CatalogPreviewService`: dla profilu (lub draft profilu z body) bierze ~3 sample produkty ze scope, mapuje do slotów, renderuje Twig HTML (bez PdfRenderer) — zwraca HTML string.
  - `CatalogPreviewController`: `POST /api/catalogs/preview` (`catalogs_pdf.read`) z body (template kind + branding + field_mappings + filter) → HTML sample; obsługa draftu (profil jeszcze niezapisany).
  - Sanityzacja (CPDF-P0-04) w preview też.
  - ApiTestCase: 200 HTML dla poprawnego body, 400 walidacja, 403.
- **Poza zakresem:** FE iframe render — CPDF-P5-04. Pełny render PDF — CPDF-P3-01.
- **AC:**
  - [ ] `POST /api/catalogs/preview` zwraca HTML dla ~3 sample produktów; działa dla draftu (niezapisany profil).
  - [ ] HTML sanityzowany; brand tokens zastosowane; brak PdfRenderer w ścieżce (szybki).
  - [ ] `#[RequiresPermission(catalogs_pdf, read)]`; ApiTestCase 200/400/403.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** `POST /api/catalogs/preview` z sample body → 200, response zawiera HTML ze slotami + `--brand-color`; DevTools: brak błędu.
- **Reuse:** `apps/api/src/Export/Feed/Application/Preview/FeedPreviewService.php` + `Presentation/Controller/FeedPreviewController.php` · `CatalogRenderService` (etap HTML) · `HtmlValueSanitizer`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §1, §6.4, §9
- **DoD:** standard.

### CPDF-P4-02: feat(export): register catalog custom routes in OpenAPI and lock RBAC
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 4-6h · **Risk:** medium
- **Blocked by:** CPDF-P3-02, CPDF-P3-03, CPDF-P4-01 · **Blocks:** —
- **Po co:** Wszystkie custom trasy PDF (generate/preview/pull/regenerate/monitor/token/bulk) muszą trafić do `docs/api-spec/v0.json` (ADR-0020, inaczej czerwona bramka „OpenAPI spec drift") i mieć komplet `#[RequiresPermission(module: 'catalogs_pdf')]` (poza publicznym pull). API-first: admin używa tych samych endpointów co integratorzy.
- **Stan obecny:** `CustomRouteOpenApiFactory` auto-dorzuca custom `/api/*` trasy do OpenAPI. `docs/api-spec/v0.json` snapshot. Endpointy z M1-M4 mają już `#[RequiresPermission]` — ten ticket to audyt kompletności + regeneracja spec.
- **Zakres:**
  - Audyt: każda custom trasa katalogów w routerze ma `#[RequiresPermission(catalogs_pdf, ...)]` (poza `/catalogs/pull/...` = `#[NoPermissionRequired]`).
  - Potwierdzić że `CustomRouteOpenApiFactory` łapie trasy katalogów (prefix nie jest wykluczony); trasy widoczne w OpenAPI z `x-pim-source: custom-route`.
  - Zregenerować `docs/api-spec/v0.json`; bramka drift zielona.
  - Rejestracja modułu `catalogs_pdf` w rejestrze RBAC kompletna (read/create/admin) — spójność z FE gate (M5).
  - Test: spec zawiera trasy katalogów; `RequiresPermissionAnnotationRule` (PHPStan) zielony dla wszystkich kontrolerów.
- **Poza zakresem:** Nowe endpointy. FE gate — CPDF-P5-05.
- **AC:**
  - [ ] Wszystkie custom trasy katalogów w `docs/api-spec/v0.json`; bramka „OpenAPI spec drift" zielona.
  - [ ] Każdy endpoint (poza publicznym pull) ma `#[RequiresPermission(catalogs_pdf, ...)]`; pull ma `#[NoPermissionRequired]`.
  - [ ] Moduł `catalogs_pdf` (read/create/admin) w rejestrze RBAC.
  - [ ] `RequiresPermissionAnnotationRule` PHPStan zielony; PHPStan max, Deptrac 0.
- **Smoke:** `git diff docs/api-spec/v0.json` pokazuje trasy `/api/catalogs*`; `grep` potwierdza `catalogs_pdf` permisje w spec/kodzie; CI drift gate zielony.
- **Reuse:** `apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php` · `apps/api/src/Identity/**` (rejestr modułów RBAC) · `docs/api-spec/v0.json`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §2 (custom trasy w OpenAPI), §6.7, §6.8 · `docs/adr/0020`
- **DoD:** standard.

---

# M5 — FE: obszar + kreator + podgląd + lista runów + gate

### CPDF-P5-01: feat(catalogs-pdf): replace placeholder with area shell and pill tabs
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 5-7h · **Risk:** low
- **Blocked by:** CPDF-P1-03 · **Blocks:** CPDF-P5-02, CPDF-P5-03
- **Po co:** Wymiana `ComingSoonPlaceholder` na realny obszar `/catalogs-pdf` w patternie Exports/Konfigurator (PillTabs: Katalogi / Szablony) — szkielet, na którym wiszą kreator i lista runów. Domknięcie placeholdera.
- **Stan obecny:** `apps/admin/src/features/catalogs-pdf/index.tsx` renderuje `ComingSoonPlaceholder` (i18n `catalogs_pdf.page_title|placeholder_title|placeholder_description`). Route `/catalogs-pdf` w `App.tsx` (`lazyPage`), menu `sidebar-nav.tsx` (`ref: catalogs_pdf`, `FileText`, `protected:false`). `ExportsLayout` (PillTabs) jako wzorzec.
- **Zakres:**
  - Zastąpić `ComingSoonPlaceholder` shellem z `PillTabs` (Katalogi / Szablony), kalka `ExportsLayout`.
  - Zakładka „Katalogi" = lista/hub (placeholder pod CPDF-P5-03), „Szablony" = lista archetypów built-in (z `GET /api/catalogs/templates` lub statycznie).
  - i18n: rozszerzyć `catalogs_pdf.*` (tab labels, nagłówki) w `pl.json`/`en.json`; kod kluczy angielski, seed `i18nextLng=pl` w testach (lekcja Playwright).
  - Playwright: login → `/catalogs-pdf` → PillTabs widoczne, przełączanie tabów; axe 0 serious/critical.
- **Poza zakresem:** Kreator — CPDF-P5-02. Lista runów z danymi — CPDF-P5-03. Menu gate — CPDF-P5-05.
- **AC:**
  - [ ] `/catalogs-pdf` renderuje shell z PillTabs (Katalogi / Szablony) zamiast `ComingSoonPlaceholder`.
  - [ ] i18n `catalogs_pdf.*` rozszerzone (pl+en); brak surowych literałów.
  - [ ] Playwright: taby widoczne + przełączanie; axe 0 serious/critical.
  - [ ] Biome + tsc czysto.
- **Smoke:** `pim.localhost` login → `/catalogs-pdf` → widać PillTabs Katalogi/Szablony; brak „Coming soon"; DevTools Console bez błędów.
- **Reuse:** `apps/admin/src/features/exports/layout/ExportsLayout.tsx` — kalka shell+PillTabs · `apps/admin/src/components/ui-v2/pill-tabs.tsx` · `apps/admin/src/features/catalogs-pdf/index.tsx` (wymiana)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §9, §11
- **DoD:** standard.

### CPDF-P5-02: feat(catalogs-pdf): add generation wizard (scope→archetype→branding→mapping→preview→generate)
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 12-16h · **Risk:** high
- **Blocked by:** CPDF-P5-01, CPDF-P4-01 · **Blocks:** —
- **Po co:** Sedno self-service: kreator, w którym klient bez developera składa dokument (wybór asortymentu → archetyp → branding → mapowanie pól → podgląd → generuj). Kalka `ExportWizardPage`/`FeedWizardPage` (WizardStepper + SelectableCardGroup + AdvancedFilterPanel).
- **Stan obecny:** `ExportWizardPage` (4-krokowy Stepper) + `FeedWizardPage` (5-krokowy) jako wzorce; `WizardStepper`, `SelectableCardGroup`, `AdvancedFilterPanel`+`useFilterDslState` gotowe. Endpointy: `POST /api/catalogs` (create), `POST /api/catalogs/preview` (CPDF-P4-01), `POST /api/catalogs/{id}/generate` (CPDF-P3-03).
- **Zakres:**
  - Kreator 6-krokowy: (1) **scope** (AdvancedFilterPanel + locale/kanał), (2) **archetyp** (SelectableCardGroup: sheet/pricelist/grid — grid disabled do M6), (3) **branding** (logo upload/URL, paleta, dane firmy), (4) **mapowanie pól** (slot → atrybut), (5) **podgląd** (iframe z `POST /api/catalogs/preview` — pełny render iframe w CPDF-P5-04), (6) **generuj** (`POST /api/catalogs` + `/generate`).
  - Wizard store (kalka `wizard-store`), footer nawigacja, walidacja per-krok.
  - API hook `useCreateCatalog`/`useGenerateCatalog` (jsonFetch, 202) + `useCatalogPreview`.
  - Payload shape zgodny z BE (lekcja UI-02 #337 CreateWizard payload shape) — smoke test że dochodzi.
  - Playwright: pełny happy path kreatora → generate → run pojawia się; axe.
- **Poza zakresem:** Iframe live preview render — CPDF-P5-04 (tu placeholder/statyczny). Grid archetyp — M6.
- **AC:**
  - [ ] Kreator 6-krokowy działa (WizardStepper); archetyp sheet+pricelist wybieralne, grid disabled.
  - [ ] Branding (logo, paleta, dane firmy) + mapowanie pól (slot→atrybut) zbierane i wysyłane w payload.
  - [ ] `POST /api/catalogs` + `/generate` — payload dochodzi (weryfikacja Network 201/202), run startuje.
  - [ ] Playwright happy path + ≥1 edge (walidacja pustego scope); axe 0 serious/critical.
  - [ ] Biome + tsc czysto.
- **Smoke:** `pim.localhost`: przejść kreator (scope=kategoria, archetyp sheet, branding, mapowanie) → Generuj → Network 201/202, run widoczny; DevTools Console bez błędów; wynikowy PDF pobieralny.
- **Reuse:** `apps/admin/src/features/exports/wizard/ExportWizardPage.tsx`/`wizard-store.tsx`/`WizardFooter.tsx` · `apps/admin/src/features/api-configurator/feeds/wizard/FeedWizardPage.tsx` · `components/ui-v2/wizard-stepper.tsx`/`selectable-card.tsx` · `components/catalog/advanced-filter-panel.tsx`+`lib/filters/use-filter-dsl-state.ts`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §9 · `agent/lessons.md` (UI-02 CreateWizard payload shape)
- **DoD:** standard.

### CPDF-P5-03: feat(catalogs-pdf): add runs list with download, regenerate, share-link, delete
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** CPDF-P5-01, CPDF-P3-02, CPDF-P3-03 · **Blocks:** CPDF-P5-04
- **Po co:** Klient zarządza dokumentami: lista katalogów/runów z akcjami download / regeneruj / kopiuj share-link (token URL) / usuń + KPI. Kalka `HistoryTable`/`FeedsHubPage`.
- **Stan obecny:** `HistoryTable` (exports) + `FeedsHubPage` (grid + KPI) jako wzorce; `useFeeds`/`useRegenerateFeed`/`useRotateToken` API hooks. Endpointy: `GET /api/catalogs`, `GET /api/catalog-runs`, `/generate`, `/token`, pull URL.
- **Zakres:**
  - Lista katalogów (karty/tabela) + KPI strip (regeneracje/pages/errory 24h); lista runów per katalog.
  - Akcje: **download** (ostatni PDF / run), **regeneruj** (`POST /generate`), **share-link** (mint/rotate token → kopiuj publiczny `/catalogs/pull/...pdf`), **delete** (profil).
  - API hooks (`useCatalogs`/`useCatalogRuns`/`useRegenerateCatalog`/`useCatalogToken`).
  - StatusPill dla statusów runów; EmptyState gdy brak.
  - Playwright: lista renderuje, regeneruj → run, share-link kopiuje URL; axe.
- **Poza zakresem:** Live progress stream — CPDF-P5-04. Bulk UI — (hook / follow-up).
- **AC:**
  - [ ] Lista katalogów + runów z KPI; akcje download/regeneruj/share-link/delete działają (Network 2xx).
  - [ ] Share-link kopiuje publiczny token URL; regeneruj tworzy run.
  - [ ] StatusPill + EmptyState; Playwright happy + edge; axe 0 serious/critical.
  - [ ] Biome + tsc czysto.
- **Smoke:** `pim.localhost`: lista pokazuje katalog; „Regeneruj" → run Running→Done; „Kopiuj link" → wklejony URL zwraca PDF; „Pobierz" → PDF; Console bez błędów.
- **Reuse:** `apps/admin/src/features/exports/sessions/HistoryTable.tsx`/`KpiStrip.tsx` · `apps/admin/src/features/api-configurator/feeds/hub/FeedsHubPage.tsx`/`FeedCard.tsx`/`FeedKpiStrip.tsx` · `feeds/api/feeds.ts` (useRegenerateFeed/useRotateToken) · `components/ui-v2/status-pill.tsx`/`empty-state.tsx`
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §9 (lista runów), §5 (pkt 3)
- **DoD:** standard.

### CPDF-P5-04: feat(catalogs-pdf): add live HTML preview and Mercure run progress
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** CPDF-P5-02, CPDF-P5-03, CPDF-P4-01 · **Blocks:** —
- **Po co:** Podgląd na żywo (HTML w iframe przed renderem PDF) + progres renderu w czasie rzeczywistym (Mercure) — feedback jak w Exports/Feeds. Kalka `useFeedRunsStream`/`ExportsLiveBridge`.
- **Stan obecny:** `useFeedRunsStream` (Mercure `FeedRunEvent`, `{connected, lastEvent}`) + `ExportsLiveBridge` (shell subscriber, invalidate cache) jako wzorce. `POST /api/catalogs/preview` (HTML) + Mercure `catalog-runs.{id}` (CPDF-P3-01).
- **Zakres:**
  - Krok „podgląd" kreatora (CPDF-P5-02): iframe renderujący HTML z `POST /api/catalogs/preview` dla ~3 sample; odświeżanie przy zmianie branding/mapowania.
  - `useCatalogRunsStream` (kalka `useFeedRunsStream`): subskrypcja `catalog-runs.{id}`, progres bar + status w liście runów; refetch na terminal event.
  - `CatalogsLiveBridge` (opcjonalnie) w shellu — invalidate listy na event.
  - Playwright: preview iframe renderuje; progres aktualizuje się (lub graceful fallback polling).
- **Poza zakresem:** Backend preview/stream (gotowe M3/M4).
- **AC:**
  - [ ] Krok podglądu renderuje HTML sample w iframe; zmiana branding/mapowania odświeża.
  - [ ] `useCatalogRunsStream` konsumuje Mercure `catalog-runs.{id}`; progres bar + status na żywo; refetch na terminal.
  - [ ] Graceful fallback gdy EventSource niedostępny (polling).
  - [ ] Playwright happy path; axe 0 serious/critical; Biome + tsc czysto.
- **Smoke:** `pim.localhost`: w kreatorze krok podgląd pokazuje kartę produktu w iframe; odpalić Generuj → progres bar rośnie do Done; Console bez błędów.
- **Reuse:** `apps/admin/src/features/api-configurator/feeds/api/runs-stream.ts` (`useFeedRunsStream`) · `apps/admin/src/features/exports/hooks/ExportsLiveBridge.tsx` · `feeds/monitor/RunDrilldown.tsx` (progres UI)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §1, §6.4, §9
- **DoD:** standard.

### CPDF-P5-05: feat(catalogs-pdf): gate menu entry and route behind catalogs_pdf permission
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 3-5h · **Risk:** medium
- **Blocked by:** CPDF-P4-02, CPDF-P5-01 · **Blocks:** —
- **Po co:** Dziś menu `catalogs_pdf` jest `protected:false` (widoczne dla wszystkich). Po dostarczeniu RBAC modułu (CPDF-P1-03/P4-02) obszar musi być gejtowany jak reszta — widoczny/dostępny tylko z permisją `catalogs_pdf.read`.
- **Stan obecny:** `sidebar-nav.tsx` wpis `catalogs_pdf` `protected:false`; `App.tsx` route bez `PermissionRoute`; `isMenuRefVisible()` filtruje wpisy `protected`. Wzorzec gejtowanych obszarów (Settings `protected:true` + `PermissionRoute anyOf`).
- **Zakres:**
  - `sidebar-nav.tsx`: `catalogs_pdf` `protected: false` → `true`.
  - `App.tsx`: owinąć route `/catalogs-pdf` w `PermissionRoute anyOf={['catalogs_pdf.read', ...]}`.
  - Potwierdzić `isMenuRefVisible('catalogs_pdf')` respektuje permisję; user bez permisji nie widzi menu i dostaje redirect/403 na route.
  - Playwright: admin widzi menu; user bez `catalogs_pdf.read` (dev-token restricted) nie widzi wpisu i nie wchodzi na route.
- **Poza zakresem:** Definicja permisji BE (CPDF-P1-03/P4-02).
- **AC:**
  - [ ] `catalogs_pdf` menu `protected:true`; route za `PermissionRoute`.
  - [ ] User bez `catalogs_pdf.read` nie widzi menu ani nie wchodzi na `/catalogs-pdf` (redirect/403).
  - [ ] Admin z permisją widzi i wchodzi normalnie.
  - [ ] Playwright oba przypadki; Biome + tsc czysto.
- **Smoke:** `pim.localhost`: admin widzi „Katalogi PDF" w menu i wchodzi; restricted user (invitation dev-token bez catalogs_pdf) nie widzi wpisu; skopiować obserwację do issue.
- **Reuse:** `apps/admin/src/layout/sidebar-nav.tsx` (wpis catalogs_pdf) · `apps/admin/src/App.tsx` (`PermissionRoute` wzorzec Settings) · `apps/admin/src/lib/identity.ts` (`isMenuRefVisible`)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §6.8, §11
- **DoD:** standard.

---

# M6 — Archetypy grid + pricelist + Gotenberg + benchmark

### CPDF-P6-01: feat(export): add pricelist archetype template
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M6 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** CPDF-P2-03 · **Blocks:** —
- **Po co:** Cennik = drugi archetyp niskiego ryzyka renderera (tabela SKU/nazwa/cena/dostępność, wielostronicowa). Plan zaleca start od sheet+pricelist (§3). Domyka drugi z trzech wariantów dokumentu.
- **Stan obecny:** Silnik szablonu + archetyp sheet (M2). `CatalogTemplateKind::Pricelist` enum istnieje (CPDF-P1-01). `CatalogRenderService` renderuje archetyp wg kind.
- **Zakres:**
  - Wpis `pricelist` w `CatalogTemplateCatalog`: szablon Twig tabeli (SKU, nazwa, cena, dostępność), powtarzalny nagłówek/stopka (CSS Paged Media), paginacja wielostronicowa.
  - Domyślne mapowanie pól cennika (sku/name/price/availability).
  - Brand tokens (nagłówek z logo, stopka z paginacją).
  - Testy: render pricelist dla N produktów → wielostronicowy PDF z powtarzalnym nagłówkiem.
- **Poza zakresem:** Grid — CPDF-P6-02. Waluty/formatowanie ceny zaawansowane (hook).
- **AC:**
  - [ ] Archetyp `pricelist` renderuje wielostronicową tabelę (SKU/nazwa/cena/dostępność) → poprawny PDF.
  - [ ] Powtarzalny nagłówek/stopka + paginacja (CSS Paged Media, Dompdf basics).
  - [ ] Domyślne mapowanie cennika; brand tokens zastosowane.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** Test: pricelist dla ~60 produktów → `/tmp/cpdf-pricelist.pdf`, `pdfinfo` wielostronicowy, nagłówek powtórzony; brand color obecny.
- **Reuse:** `CatalogTemplateCatalog`/`CatalogRenderService` (M2) · szablon sheet jako wzorzec Twig+brand tokens
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §3 (cennik), §5 (Paged Media)
- **DoD:** standard.

### CPDF-P6-02: feat(export): add grid catalog archetype with cover and TOC
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M6 · **Est:** 10-14h · **Risk:** high
- **Blocked by:** CPDF-P2-03 · **Blocks:** CPDF-P6-05
- **Po co:** Katalog-siatka (okładka + spis + siatka kart) = trzeci, najbardziej wymagający archetyp (paginacja, pamięć — plan §3 „średnie/wysokie ryzyko renderera"). Domyka pełną trójkę wariantów; layouty premium mogą wymagać Gotenberga (gejtowane).
- **Stan obecny:** sheet+pricelist (M2/CPDF-P6-01). CSS Paged Media basics w Dompdf; pełne margin boxes dopiero Gotenberg (CPDF-P6-03). `CatalogTemplateKind::Grid`.
- **Zakres:**
  - Wpis `grid` w `CatalogTemplateCatalog`: okładka (branding, tytuł kolekcji), spis treści (`target-counter()` gdzie wspierane), siatka kart produktów (N na stronę), stopka z paginacją.
  - Kontener składający sekcje/strony (analog Pimcore PrintContainer, research §5 pkt 2).
  - Feature-flag: layouty wymagające pełnych margin boxes gejtowane do Gotenberga (fallback uproszczony na Dompdf).
  - Testy: render grid → okładka + siatka + paginacja; degradacja na Dompdf bez pełnych margin boxes.
- **Poza zakresem:** Gotenberg adapter — CPDF-P6-03. Snapshot parity — CPDF-P6-05.
- **AC:**
  - [ ] Archetyp `grid` renderuje okładkę + siatkę kart + paginację → poprawny PDF.
  - [ ] Spis treści gdzie renderer wspiera (`target-counter`); graceful degradacja na Dompdf.
  - [ ] Layouty premium (pełne margin boxes) gejtowane feature-flagą do Gotenberga.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** Test: grid dla ~30 produktów → `/tmp/cpdf-grid.pdf`, okładka + siatka widoczne, paginacja; `pdfinfo` OK.
- **Reuse:** `CatalogTemplateCatalog`/`CatalogRenderService` · szablony sheet/pricelist
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §3 (katalog), §5 (pkt 2 PrintContainer, pkt 5 Paged Media)
- **DoD:** standard.

### CPDF-P6-03: feat(export): add GotenbergRenderer adapter behind PdfRenderer port
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M6 · **Est:** 8-12h · **Risk:** high · `[PM]`
- **Blocked by:** CPDF-P0-03 · **Blocks:** CPDF-P6-05
- **Po co:** Drugi adapter renderera (headless Chromium) dla wysokiej wierności / layoutów premium (pełne CSS3, margin boxes). Opcjonalny — aktywny tylko gdy `GOTENBERG_URL` (Cortex działa bez niego). `[PM]` bo dodaje opcjonalny serwis/kontener do topologii (R3).
- **Stan obecny:** Port `PdfRenderer` + `DompdfRenderer` (CPDF-P0-03), wybór adaptera przez `CATALOG_PDF_RENDERER`. Gotenberg = HTTP do sidecara (SensioLabs GotenbergBundle jako opcja, research §13). Brak Gotenberga w domyślnym compose.
- **Zakres:**
  - `GotenbergRenderer implements PdfRenderer` w `Infrastructure/Renderer/`: HTTP POST HTML do Gotenberg (`GOTENBERG_URL`), opcje (format, marginesy, running headers/footers), zwraca binary.
  - Aktywacja: `CATALOG_PDF_RENDERER=gotenberg` + `GOTENBERG_URL` ustawione; auto-fallback do Dompdf gdy brak URL.
  - Osadzanie zdjęć: URL (Gotenberg widzi sieć/MinIO) — decyzja B, druga gałąź.
  - Opcjonalny wpis w docker-compose (profile `gotenberg`, poza domyślnym) + dokumentacja włączenia.
  - Retry/timeout (exponential backoff wzorzec) na HTTP do sidecara.
  - Testy: gdy `GOTENBERG_URL` set — render przez Gotenberg; gdy brak — fallback Dompdf bez błędu. (Integration warunkowy: skip gdy brak sidecara w CI.)
- **Poza zakresem:** PDFreactor/CMYK (Faza 2). Snapshot parity — CPDF-P6-05.
- **AC:**
  - [ ] `GotenbergRenderer` implementuje `PdfRenderer`; aktywny gdy `CATALOG_PDF_RENDERER=gotenberg` + `GOTENBERG_URL`.
  - [ ] Brak `GOTENBERG_URL` → auto-fallback Dompdf (Cortex działa bez Gotenberga).
  - [ ] Zdjęcia przez URL; retry/timeout na HTTP; jest jedyną klasą dotykającą Gotenberg API.
  - [ ] docker-compose profile `gotenberg` (poza domyślnym) + doc; Integration test warunkowy.
  - [ ] PHPStan max, Deptrac 0, PHPUnit ≥80%.
- **Smoke:** Z uruchomionym Gotenberg sidecar (`GOTENBERG_URL` set): render sheet przez Gotenberg → PDF; bez URL: ten sam profil renderuje przez Dompdf (log potwierdza adapter).
- **Reuse:** `apps/api/src/Export/Contracts/PdfRenderer` + `DompdfRenderer` (wzorzec adaptera) · `apps/api/src/Export/Feed/Infrastructure/Delivery/FlysystemFeedCacheStorage.php` (styl HTTP/infra) · Shopify backoff wzorzec (retry)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §2 (#2), §6.2, §8 (R3), §13 (Gotenberg/GotenbergBundle) · `docs/adr/0027`
- **DoD:** standard.

### CPDF-P6-04: perf(export): benchmark Dompdf memory and add product cap or chunking
- **Typ:** `perf` · **Cls:** BE · **Milestone:** M6 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** CPDF-P2-03 · **Blocks:** —
- **Po co:** Rozstrzygnięcie otwartej decyzji A: Dompdf ładuje cały dokument do pamięci → ryzyko OOM w worker mode przy setkach produktów (§8 R1). Benchmark ustala próg; mitygacja = cap produktów per dokument na Dompdf + komunikat „dla większych włącz Gotenberg", albo chunk→merge.
- **Stan obecny:** `CatalogRenderService` + async handler (M2/M3). Wzorzec benchmarku `pim:export:benchmark`. Prometheus alert `frankenphp_worker_memory_bytes > 256MB` (§3.10).
- **Zakres:**
  - Benchmark: render sheet/pricelist/grid dla rosnącej liczby produktów (50/100/150/300/500) na Dompdf, pomiar peak memory + czas (kalka `pim:export:benchmark`).
  - Ustalić cap (np. ~150) i zaimplementować: przekroczenie na Dompdf → jasny błąd/komunikat „przekroczono limit, włącz Gotenberg" LUB chunk→merge PDF (jeśli wykonalne w budżecie).
  - Telemetria peak memory per run do `CatalogRun` (lub log) dla decyzji Fazy 2.
  - Test: render powyżej capu → kontrolowany błąd/degradacja, nie OOM.
- **Poza zakresem:** Gotenberg (CPDF-P6-03). Cron (hook).
- **AC:**
  - [ ] Benchmark udokumentowany (peak memory/czas vs liczba produktów per archetyp na Dompdf).
  - [ ] Cap produktów na Dompdf wyegzekwowany: przekroczenie → jasny komunikat/degradacja (nie OOM/crash worker).
  - [ ] Decyzja A rozstrzygnięta i zapisana (cap vs chunk→merge) w tickecie/lessons.
  - [ ] PHPStan max, PHPUnit ≥80%.
- **Smoke:** Uruchomić benchmark na dev → tabela memory/czas; render 300 produktów na Dompdf → kontrolowany komunikat, worker nie pada (Prometheus/log memory poniżej alertu).
- **Reuse:** komenda wzorca `pim:export:benchmark` · `CatalogRenderService` · Prometheus worker memory alert (§3.10)
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §8 (R1, decyzja A), §2 (otwarte A) · `01-architektura-pim.md` §3.10
- **DoD:** standard.

### CPDF-P6-05: test(export): snapshot parity for Dompdf vs Gotenberg rendering
- **Typ:** `test` · **Cls:** BE · **Milestone:** M6 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** CPDF-P6-03, CPDF-P6-02 · **Blocks:** —
- **Po co:** Mitygacja R2: ten sam szablon renderowany na Dompdf i Gotenberg może się rozjechać. Snapshot-testy renderu na obu adapterach + zdefiniowany podzbiór „bezpiecznego" CSS chronią przed cichym dryfem; layouty premium jawnie gejtowane do Gotenberga.
- **Stan obecny:** Oba adaptery (CPDF-P0-03 + CPDF-P6-03), trzy archetypy (M2/M6). Playwright snapshot wzorzec w repo (FE).
- **Zakres:**
  - Zdefiniować „bezpieczny" podzbiór CSS (bez grid/flex tam gdzie Dompdf nie wspiera) jako kontrakt szablonów; test lint/guard że szablony trzymają się podzbioru dla ścieżki Dompdf.
  - Snapshot-testy: render sheet/pricelist na obu adapterach (Gotenberg warunkowo gdy dostępny) → porównanie kluczowych właściwości (liczba stron, obecność slotów, brak pustych stron); premium (grid full margin boxes) tylko Gotenberg.
  - Udokumentować które layouty są parity-safe, a które Gotenberg-only.
- **Poza zakresem:** Pixel-perfect diff (poza budżetem MVP). CMYK.
- **AC:**
  - [ ] Podzbiór „bezpiecznego" CSS zdefiniowany + guard/test dla szablonów ścieżki Dompdf.
  - [ ] Snapshot-testy sheet/pricelist na obu adapterach (Gotenberg warunkowo); kluczowe właściwości zgodne.
  - [ ] Layouty premium jawnie oznaczone Gotenberg-only; dokumentacja parity.
  - [ ] PHPStan max, PHPUnit ≥80%.
- **Smoke:** Uruchomić snapshot-testy: sheet/pricelist parity zielone na Dompdf; z Gotenberg — te same właściwości; grid oznaczony Gotenberg-only.
- **Reuse:** oba adaptery `PdfRenderer` · szablony archetypów · wzorzec snapshot testów
- **Referencje:** `Project Plan/UI/feature-catalogs-pdf.md` §8 (R2), §2 (#2), §5 (pkt 4) · `docs/adr/0027`
- **DoD:** standard.

---

## Świadome odejścia / hooki (bez issue na starcie — §7 planu)

- **InDesign / EasyCatalog / priint / Pagination** — inny obóz rynkowy, nie budujemy.
- **CMYK / spady / crop marks / PDF-X / druk offsetowy** — Faza 2, 3. adapter PDFreactor/prince za portem `PdfRenderer`.
- **Drag-and-drop template builder (WYSIWYG)** — MVP daje parametryzację, nie edytor układu. Faza 2.
- **Harmonogram auto-regeneracji (cron)** — reuse `CronExpressionParser`+`ScheduleDispatcherService` (jak feedy) gdy dojdzie; MVP manualny „Regeneruj teraz".
- **Katalog wielojęzyczny w jednym pliku** — MVP 1 dokument = 1 locale.
- **ObjectType ≠ product** (kategorie/zasoby jako dokument) — MVP tylko product (+ warianty flat).
- **Transformacje/skrypty w mapowaniu pól** — MVP mapowanie proste slot→atrybut.
