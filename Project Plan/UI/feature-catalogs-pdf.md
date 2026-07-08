# Plan implementacji — Katalogi PDF (web-to-print): product sheety, katalogi, cenniki (Cortex PIM)

> **Status:** szkic „grube punkty" do akceptacji (2026-07-08). Rozwijamy sekcję po sekcji po akceptacji operatora. Sekcje oznaczone ⏳ czekają na rozwinięcie.
> **Kontekst:** operator (Marcin) chce funkcji **Katalogi PDF** — narzędzia, w którym klient bez developera generuje z danych PIM firmowane dokumenty PDF (karta produktu, katalog kolekcji/kategorii, cennik), zawsze aktualne, do pobrania i udostępnienia przez stabilny URL. To domknięcie placeholdera `/catalogs-pdf` (dziś `ComingSoonPlaceholder`, menu `ref: catalogs_pdf`, ikona `FileText`).
> **To domknięcie znanej luki:** PRD eksportów (`Project Plan/PRD/PRD-PIM-exports.md`) świadomie wyciął „printable catalogs" do Fazy 2+; menu i route `/catalogs-pdf` istnieją jako zaślepka. Ten dokument projektuje MVP tej funkcji.
> **Powiązania:** `01-architektura-pim.md` §3.10 (memory FrankenPHP worker mode); `feature-konfigurator-xml-plan.md` (**siostrzany silnik — ten sam wzorzec „warstwa NAD Export"**, reuse 1:1); `src/Export/` + `src/Export/Feed/` (reuse); ADR-0023 (Export/Feed placement, cache-and-serve, token-in-URL), ADR-0018 (channel publication profile), ADR-0020 (OpenAPI custom route), ADR-0009 (ObjectType), ADR-0015 (cross-BC bare UUID); `docs/api/jsonb-schemas.md` (envelope wartości).
> **Ten dokument NIE jest jeszcze backlogiem ticketów.** Grube punkty → po akceptacji rozwijamy w architekturę + briefy (§9/§10) i dopiero potem tickety epiku **CPDF**.

---

## 0. TL;DR

Budujemy **web-to-print**, nie konektor do InDesign. Szablon HTML/CSS → silnik renderujący → PDF, generowany z danych PIM, cache'owany i serwowany przez stabilny URL (model Plytix/Pimcore). Trzy warianty dokumentu na jednym silniku szablonu: **karta produktu**, **katalog**, **cennik** — parametryzowane brandingiem (logo, kolory, dane firmy) i mapowaniem pól.

Dwie decyzje architektoniczne, które zdejmują ryzyko:

1. **Renderer za portem, nie na sztywno.** Port `PdfRenderer` z dwoma adapterami: `DompdfRenderer` (czysty PHP, in-process, **domyślny — zero nowej infry**) i `GotenbergRenderer` (headless Chromium jako sidecar, włączany tylko gdy ustawione `GOTENBERG_URL`). **Cortex instaluje się i działa bez Gotenberga.** Wysoka wierność / layouty premium = opcjonalny flip flagi na tym samym szablonie. Ten sam wzorzec co `ItemWriter`/`RowWriter` w Export i „każda integracja = adapter".
2. **Warstwa NAD Export, nie nowy silnik.** Katalog PDF nie pisze własnego selektora produktów ani odczytu wartości — reużywa `ExportBuilder` (dane), `PublicationColumnPlanner` + publication profile (scope/allow-lista), cache-and-serve + token URL (delivery, wzorzec `Export/Feed`). Dokładamy wyłącznie: silnik szablonu HTML, port renderera, model `CatalogProfile`/`CatalogRun`, i predefiniowane szablony.

Zdanie pozycjonujące: *„Firmowa karta produktu, katalog kolekcji albo cennik w PDF w 5 minut, bez developera i bez InDesigna — wybór asortymentu, szablon z Twoim logo i kolorami, podgląd na żywo, pobranie i stabilny link; ten sam silnik danych co eksporty i feedy."*

---

## 1. Cel i zakres

**Cel:** klient (lub pilot-tenant) generuje i utrzymuje dokumenty PDF z katalogu bez dotykania kodu i bez narzędzi DTP. PIM pozostaje źródłem prawdy; PDF to zawsze **read-only projekcja** danych produktowych do dokumentu.

**W zakresie (grube punkty — do potwierdzenia):**
- **Trzy warianty dokumentu:** karta produktu (1 produkt = 1 strona/kilka), katalog (N produktów: okładka + spis + siatka/lista), cennik (tabela SKU/nazwa/cena/dostępność).
- **Jeden parametryzowalny silnik szablonu** — branding (logo, paleta, dane firmy, okładka) + mapowanie pól (które atrybuty PIM → które sloty layoutu) jako parametry, nie osobne pliki szablonów (decyzja operatora 2026-07-08).
- **Renderer za portem** — Dompdf domyślnie (bez infry), Gotenberg opcjonalnie (`GOTENBERG_URL`).
- **Scope** — wybór asortymentu (filter DSL), locale, kanał / publication profile (ADR-0018).
- **Podgląd na żywo** — HTML szablonu dla kilku sample produktów przed renderem PDF.
- **Delivery** — pobranie pliku + **stabilny URL z tokenem** („live" = regeneruj → serwuj cache); bulk generate.
- **Bezpieczeństwo** — escaping/HTML-sanityzacja w szablonie, izolacja tenantów, token PDF (nie klucz tenanta), rate-limit publicznego URL.

**Poza zakresem (świadomie; hooki):**
- **Konektor InDesign / EasyCatalog / priint / Pagination** — inny obóz rynkowy (Akeneo). Nie budujemy.
- **Druk offsetowy: CMYK, spady (bleed), znaczniki cięcia, overprint, PDF/X** — Chromium/Dompdf renderują RGB. Druk profesjonalny = Faza 2 (wymaga renderera wspierającego CMYK, np. PDFreactor/prince — hook za portem `PdfRenderer`).
- **Drag-and-drop template builder** (à la Plytix) — MVP daje parametryzację, nie edytor WYSIWYG układu. Faza 2.
- **Harmonogram auto-regeneracji (cron)** — MVP: „Regeneruj teraz" + regen na żądanie. Cron = hook (reuse `CronExpressionParser` + `ScheduleDispatcherService`, jak feedy).
- **Katalog wielojęzyczny w jednym pliku** — MVP: 1 dokument = 1 locale (multi = wiele dokumentów).
- **Katalog z ObjectType innego niż product** (kategorie/zasoby jako dokument) — MVP tylko `product` (+ warianty flat).

**Relacja do sąsiednich feature'ów (żeby nie dublować):**

| Feature | Co robi | Granica względem Katalogów PDF |
|---|---|---|
| **Export (EXR)** | XLSX/CSV download + profile + sesje | Katalog PDF **reużywa** `ExportBuilder` (dane + scope). PDF to nie „format eksportu" w kreatorze — to osobny obszar dokumentowy (output = dokument, nie strumień wierszy). |
| **Konfigurator XML / Feed (XMLF)** | Feedy XML + cache-and-serve + token URL | **Bliźniak wzorca dostarczania.** Katalog PDF kalkuje delivery feedu (`Export/Feed` → `Export/Catalog`): regen → MinIO → publiczny URL z tokenem. Różnica: output binarny (PDF) zamiast strumienia XML. |
| **Channel + PublicationProfile (ADR-0018)** | Per-kanał allow-lista atrybutów/locale | Katalog **reużywa** publication profile jako scope + zalążek mapowania pól. |
| **Asset / DAM** | Zdjęcia produktów, storage | Szablon PDF osadza `image_link` z DAM (główne zdjęcie + galeria). Reuse resolvera URL assetu z `ValueSerializer`. |

---

## 2. Decyzje produktowe

**Zatwierdzone (operator, 2026-07-08):**

| # | Decyzja | Wybór | Konsekwencja architektoniczna |
|---|---------|-------|-------------------------------|
| 1 | Obóz rynkowy | **Web-to-print (HTML/CSS → renderer → PDF)**, nie konektor InDesign | Silnik szablonu HTML + port renderera. DTP/CMYK poza MVP. |
| 2 | Renderer | **Dompdf domyślny (in-process, bez infry) + port `PdfRenderer` + Gotenberg opcjonalny** (`GOTENBERG_URL`) | Instalacja bez Gotenberga = pełny MVP. Wierność/premium = flip flagi. Odrzucone: mpdf (GPL + łamanie layoutu), PDFreactor (licencja ~$1.9k/rok — kandydat na 3. adapter pod CMYK w Fazie 2). |
| 3 | Szablony | **Jeden parametryzowalny silnik** (brand tokens + mapowanie pól), nie zestaw sztywnych plików | Layout = archetyp (sheet/grid/pricelist); branding i wybór pól = parametry `CatalogProfile`. Drag-drop builder = hook. |
| 4 | Model dostarczania | **Pull: cache-and-serve** (regen → PDF w MinIO → URL z tokenem) | Nie renderujemy na każde uderzenie. Reuse wzorca `Export/Feed` (MinIO + ETag + token). |

**Otwarte (do decyzji przy rozwijaniu):**

| # | Pytanie | Opcje | Rekomendacja wstępna |
|---|---------|-------|----------------------|
| A | Duży katalog na Dompdf | Dompdf ładuje cały dokument do pamięci (ryzyko OOM w worker mode przy setkach produktów) | MVP: cap produktów per dokument na Dompdf (np. ~150) + komunikat „dla większych włącz Gotenberg"; albo chunk→merge. Rozstrzygamy po benchmarku (wzorzec `pim:export:benchmark`). |
| B | Osadzanie zdjęć | Inline base64 vs URL (renderer pobiera z MinIO) | Gotenberg: URL (widzi sieć). Dompdf: strumień z Flysystem → base64/temp. Ujednolicić w porcie. |
| C | „Live URL" semantyka | Zawsze-świeży (regen przy każdym pull) vs cache + „Regeneruj" | Cache + regen-on-demand (jak feed); pull zwraca ostatni wygenerowany. |

**Decyzje domyślne (wynikają z architektury repo, bez pytania):**
- **Umiejscowienie:** nowy pod-obszar `src/Export/Catalog/` (kalka `src/Export/Feed/`) — nie nowy bounded context. Silnik reużywa Export w całości; Channel/Catalog wyłącznie przez `*\Contracts\*` (Deptrac).
- **Reuse Export zamiast własnego selektora** — analogicznie do „outbound reużywa Export engine".
- **Custom trasy w OpenAPI** (ADR-0020) — generate/preview/pull/regenerate w `v0.json`, inaczej czerwona bramka „OpenAPI spec drift".

---

## 3. Trzy warianty dokumentu

Jeden silnik szablonu, trzy archetypy layoutu. Różnią się gęstością danych i strukturą, nie mechaniką generowania.

| | **Karta produktu** | **Katalog** | **Cennik** |
|---|---|---|---|
| Po co | Sprzedażowy one-pager / data sheet | Kolekcja/kategoria do przeglądania | Lista handlowa z cenami |
| Zakres | 1–kilka produktów | N produktów (dziesiątki–setki) | N produktów, gęsto |
| Struktura | Duże zdjęcie + opis + specyfikacja | Okładka + spis + siatka kart / lista | Tabela: SKU, nazwa, cena, dostępność |
| Ryzyko renderera | niskie (Dompdf OK) | **średnie/wysokie** (paginacja, pamięć → Gotenberg dla premium) | niskie/średnie (tabela wielostronicowa) |
| Branding | logo + paleta + dane firmy | + okładka + stopka z paginacją | + nagłówek/stopka powtarzalna |

**Wspólny rdzeń:** wszystkie trzy wołają `ExportBuilder` → dostają dane produktów ze scope (locale/kanał, uprawnienia atrybutów) → wiążą do szablonu HTML (Twig) wg mapowania pól → `PdfRenderer` → PDF do MinIO. MVP może wystartować od **karty produktu + cennika** (niskie ryzyko renderera), katalog-siatka jako drugi krok.

---

## 4. Stan obecny (as-is) — co reużywamy

Zbadane w kodzie 2026-07-08. **Nie budujemy od zera — montujemy na klockach silnika Export i wzorca Feed (XMLF).**

| Klocek | Lokalizacja | Rola w Katalogach PDF |
|--------|-------------|----------------------|
| **`ExportBuilder`** | `Export/Application/Builder/ExportBuilder.php` | **Rdzeń danych.** Generator `array<string,string>` per produkt ze scope + `EntityManager::clear()` po stronie wywołującego → memory-bounded. Szablon PDF konsumuje ten sam strumień. |
| **`ColumnResolver` + `ValueSerializer`** | `Export/Application/Builder/` | Atrybut → klucz + serializacja JSONB→string (multi-value, blank, **URL assetu**). PDF wiąże wynik do slotów szablonu. |
| **`PublicationColumnPlanner` + `ChannelPublicationResolverInterface`** | `Export/Application/Builder/` + `Channel/Contracts/` (ADR-0018) | Scope PDF do kanału = wybór publication profile; `column_aliases` = zalążek mapowania pól. |
| **Wzorzec Feed (XMLF)** — `FeedGenerator`, `FeedProfileController`, `FeedPullController`, `FeedRegenerateController`, `FeedRunMonitorController`, `FeedTemplateCatalog`, `FeedRunStatus` | `Export/Feed/**` | **Kalka 1:1 dla `Export/Catalog/`.** Model profilu/runu, publiczny pull z tokenem, regenerate, monitor runów, katalog szablonów, enum statusu — przepisujemy pod PDF. |
| **`FeedTokenService` / `ApiKey` (Argon2id) + rate-limit** | `ApiConfigurator/Infrastructure/Security/` + Feed | Token URL dokumentu (hash, rotate, revoke, constant-time) + rate-limit publicznego endpointu. Token ≠ klucz tenanta. |
| **`ExportJobHandler` (async) + `SyncExportRunner`** | `Export/Application/{Async,Sync}/` | Ścieżki uruchomienia + progres Mercure + anulowanie. `GenerateCatalogHandler` = analogiczny handler async (batch 200 + `clear()`). |
| **MinIO / Flysystem** | `Shared/` + infra | Cache PDF: `catalogs/{tenant_id}/{catalog_id}.pdf`. Wzorzec `feeds/{tenant_id}/…`. |
| **Mercure SSE** | infra | Progres renderu (jak `feed-runs.{run_id}`). |
| **Advanced filter DSL** | FE `useFilterDslState` + `AdvancedFilterPanel`; BE `filter_snapshot` JSONB | Wybór asortymentu dokumentu = ten sam DSL co lista/eksport/feed. Persystowany w `CatalogProfile.filter`. |
| **`CronExpressionParser` + `ScheduleDispatcherService`** | `Import/Application/Service/` | Tylko dla hooka „harmonogram auto-regeneracji". MVP manualny. |
| **security.yaml `PUBLIC_ACCESS`** | `apps/api/config/packages/security.yaml` | Publiczny URL PDF bez sesji (tenant z tokenu → RLS/TenantFilter). |
| **`CustomRouteOpenApiFactory` (ADR-0020)** | `Shared/OpenApi/` | Custom trasy PDF → `docs/api-spec/v0.json`. |
| **Catalog: `ObjectValue` envelope + `attributes_indexed` GIN + `Provenance`** | `Catalog/Domain/` + `docs/api/jsonb-schemas.md` | Źródło wartości (przez ExportBuilder). PDF = read-only. |
| **Front: route + menu + i18n placeholder** | `apps/admin/src/features/catalogs-pdf/index.tsx`, `App.tsx` (`/catalogs-pdf`), `sidebar-nav.tsx` (`ref: catalogs_pdf`, `icon: FileText`), `locales/{pl,en}.json` (`catalogs_pdf.*`, `nav.catalogsPdf`) | Punkt zaczepienia FE gotowy — wymieniamy `ComingSoonPlaceholder` na realny obszar. |

**Nowego kodu domenowego realnie niewiele:** port `PdfRenderer` + 2 adaptery + silnik szablonu HTML (Twig + brand tokens) + `CatalogProfile`/`CatalogRun` + delivery (kalka Feed) + 1–3 seedy szablonów. Reszta to spięcie istniejących silników — poprawki w Export/Feed automatycznie poprawiają katalogi PDF.

---

## 5. Research → zasady projektowe

Źródła w §13. Z każdego jedna zasada.

1. **Dwa obozy rynkowe.** Akeneo nie renderuje PDF sam — spina się z InDesign przez płatne konektory (Pagination, priint, EasyCatalog, Pim2catalog): pełna kontrola DTP/CMYK, ale zewnętrzne i ciężkie. Pimcore i Plytix idą web-to-print. → **Zasada:** celujemy w web-to-print (self-service, auto-aktualne, tańsze wdrożenie); InDesign to świadome „poza MVP".
2. **Pimcore = HTML→renderer.** Pimcore renderuje print: dokument → HTML → Twig → PDF przez zewnętrzny renderer (PDFreactor lub Gotenberg); PrintContainer składa strony w katalog ze spisem treści. → **Zasada:** nasz pipeline identyczny (Twig HTML → `PdfRenderer`); „katalog" = kontener składający sekcje/strony.
3. **Plytix = branded PDF + live URL + bulk.** Product Data Sheets: PDF generowany w PIM, auto-aktualny, udostępniany przez live URL; branding (logo + tekst); bulk generate dla kolekcji/kategorii. → **Zasada:** MVP musi mieć branding, live URL (cache-and-serve) i bulk generate — bez tego „gorszy niż Plytix".
4. **Renderery — trade-off wierność vs infra.** Dompdf/mpdf: czysty PHP, zero infry, ale CSS 2.1 (bez grid/flex), słaba paginacja tabel. Headless Chromium (Gotenberg): pełne CSS3, ale kontener ~1.5 GB i ~85–200 MB RAM/render. PDFreactor: najlepsze CSS Paged Media (running headers/footers, margin boxes) + CMYK, ale płatny. → **Zasada:** port `PdfRenderer` z Dompdf jako default i Gotenberg jako opcja; szablony w podzbiorze CSS działającym na obu; PDFreactor = przyszły adapter pod CMYK.
5. **CSS Paged Media dla dokumentu.** Nagłówki/stopki powtarzalne, numeracja stron, spis treści, okładka — to `@page`, `running()`, `target-counter()`. Dompdf wspiera podstawy `@page`; pełne margin boxes dopiero Gotenberg/Chromium/PDFreactor. → **Zasada:** okładka + paginacja + stopka w szablonie przez CSS Paged Media; layouty wymagające pełnych margin boxes gejtujemy do Gotenberga.
6. **Cache-and-serve dla skali.** Katalog 50k SKU renderowany na każde pobranie = DoS na własny worker. → **Zasada:** regen wg żądania/harmonogramu → statyczny PDF w MinIO → serwowanie z cache + ETag + Content-Disposition; „Regeneruj teraz" odświeża.
7. **HTML/eskejp w danych (analog CSV/XML injection).** Wartości produktowe w szablonie HTML muszą być auto-escapowane (Twig `autoescape`), a HTML w opisach kontrolowany (whitelist/sanitizacja), by nie rozbić layoutu ani nie wstrzyknąć treści. → **Zasada:** Twig autoescape ON, sanityzacja HTML opisów per-slot, twardy test „render nie wywala się na śmieciowych danych".

---

## 6. Architektura docelowa (grube punkty)

### 6.1 Umiejscowienie
Nowy pod-obszar **`src/Export/Catalog/`** (kalka `src/Export/Feed/`, uzasadnienie jak ADR-0023: PDF to „projekcja katalogu na wyjście", ta sama domena Export). Port `PdfRenderer` w `Export/Contracts/`. Publiczny pull + CRUD/preview/regenerate w `Export/Catalog/Presentation/`. Deptrac: `Export/Catalog` sięga `Export/*`; Channel/Catalog tylko przez `Contracts`.

### 6.2 Port renderera (decyzja #2 — rozpisana)
```
Export/Contracts/PdfRenderer               (interfejs: render(html, options): binary/stream)
Export/Catalog/Infrastructure/Renderer/
  ├── DompdfRenderer      (in-process, domyślny; brak zależności infry)
  └── GotenbergRenderer   (HTTP do sidecara; aktywny gdy GOTENBERG_URL ustawione)
```
Wybór adaptera: config/env (`CATALOG_PDF_RENDERER=dompdf|gotenberg`, auto-fallback do dompdf gdy brak `GOTENBERG_URL`). Instalacja bez Gotenberga = MVP na Dompdf. Szablony w „bezpiecznym" CSS; layouty premium (grid/flex/pełne margin boxes) walidowane tylko dla Gotenberga i gejtowane feature-flagą.

### 6.3 Model domenowy ⏳ *(pełne DDL do rozwinięcia)*
- **`catalog_profiles`** — definicja dokumentu: `template_kind` (sheet/grid/pricelist), `object_type_id` (bare ref, MVP=product), `branding JSONB` (logo, paleta, dane firmy, okładka), `field_mappings JSONB` (slot → atrybut/transform), `filter JSONB` (DSL), `channel_id`/`publication_channel`, `locale`, `renderer` (auto/dompdf/gotenberg), `token_hash`. Kalka `feed_profiles`.
- **`catalog_runs`** — historia generacji: `status` (enum jak `FeedRunStatus`), `file_path` (MinIO), `page_count`, `byte_size`, `error`, timestamps. Kalka `feed_runs`.
- Wszystko `TenantScoped` + RLS + GUC w workerach.

### 6.4 Silnik szablonu (decyzja #3)
Jeden silnik: **Twig HTML + brand tokens (CSS variables) + mapowanie pól**. Archetyp layoutu (`sheet|grid|pricelist`) wybiera bazowy template; `branding` wstrzykuje `--brand-color`, logo, dane firmy; `field_mappings` decyduje które atrybuty PIM lądują w slotach. Seed 1–3 built-in archetypów (`CatalogTemplateCatalog`, kalka `FeedTemplateCatalog`). Podgląd = ten sam HTML dla ~3 sample produktów, renderowany w iframe zanim pójdzie do `PdfRenderer`.

### 6.5 Delivery ⏳
Cache-and-serve (kalka Feed): regen → `catalogs/{tenant}/{id}.pdf` w MinIO → publiczny `GET /catalogs/{token}.pdf` (bez sesji, tenant z tokenu, RLS) + ETag + `Content-Disposition`. „Live URL" = zawsze ostatni wygenerowany; „Regeneruj teraz" odświeża.

### 6.6 Async / worker discipline ⏳
`GenerateCatalogHandler` (Messenger, kalka `ExportJobHandler`): iteruje produkty batchami 200 z `EntityManager::clear()` (§3.10 architektury — inaczej OOM w worker mode), buduje HTML inkrementalnie, oddaje do `PdfRenderer`, upload do MinIO, progres przez Mercure. **Uwaga pamięciowa (otwarta decyzja A):** Dompdf trzyma cały dokument w pamięci → cap/chunk dla dużych katalogów.

### 6.7 Powierzchnia API ⏳
- `CatalogProfile` CRUD — API Platform (byt zasobowy).
- Proceduralne custom `#[Route]`: `POST /api/catalogs/{id}/generate` (async), `GET /api/catalogs/runs/{id}` (status/SSE), `POST /api/catalogs/preview` (HTML sample), `GET /catalogs/{token}.pdf` (publiczny pull). Wszystko w OpenAPI (ADR-0020).

### 6.8 RBAC ⏳
Nowy moduł `catalogs_pdf` (zgodny z menu `ref`), akcje `read`/`create`/`admin`. `#[RequiresPermission(module: 'catalogs_pdf', action: 'create')]` na generate. Dziś menu `protected: false` → dodajemy gate (jak reszta obszarów).

---

## 7. Zakres MVP: IN / OUT (jednym spojrzeniem)

**IN:** RGB PDF ekranowy · 3 archetypy (sheet/grid/pricelist, start od sheet+pricelist) · jeden parametryzowalny szablon (branding + mapowanie pól) · scope (filtr/locale/kanał) · podgląd HTML · Dompdf default + port + Gotenberg opcjonalny · cache-and-serve + token URL · bulk generate · RBAC `catalogs_pdf` · async + Mercure progres.

**OUT (hooki):** InDesign/EasyCatalog · CMYK/spady/crop/PDF-X/druk offsetowy · drag-drop template builder · cron auto-regen · katalog wielojęzyczny w jednym pliku · ObjectType ≠ product · dowolne transformacje/skrypty w mapowaniu.

---

## 8. Ryzyka i otwarte decyzje
- **R1 — pamięć Dompdf przy dużym katalogu** (otwarta decyzja A). Mitygacja: benchmark + cap/chunk + rekomendacja Gotenberg.
- **R2 — rozjazd wierności Dompdf vs Gotenberg** na tym samym szablonie. Mitygacja: podzbiór CSS + snapshot-testy renderu na obu adapterach; premium gejtowane do Gotenberga.
- **R3 — Gotenberg jako nowy serwis** (jeśli włączony): +kontener, +RAM. Mitygacja: opcjonalny, poza domyślną instalacją.
- **R4 — CMYK/druk** oczekiwany przez część klientów. Mitygacja: jawne „poza MVP" + hook (3. adapter PDFreactor/prince za portem).

---

## 9. Brief dla agenta UI ⏳
*(Do rozwinięcia po akceptacji.)* Obszar `/catalogs-pdf` w patternie Exports/Konfigurator (PillTabs: Katalogi / Szablony), kreator *scope → archetyp → branding → mapowanie → podgląd → generuj*, lista runów (download/regeneruj/share-link/delete). Reuse `SelectableCardGroup`, `Stepper`, `DataTable`, `AdvancedFilterPanel`, `ComingSoonPlaceholder` → wymiana.

## 10. Brief dla agenta ticketów — proponowany epik CPDF ⏳
*(Do rozwinięcia po akceptacji.)* Wstępne milestone'y:
- **M0** — port `PdfRenderer` + `DompdfRenderer` + POC render statycznego HTML→PDF.
- **M1** — model `CatalogProfile`/`CatalogRun` + schema + MinIO bucket (kalka Feed).
- **M2** — silnik szablonu (Twig + brand tokens) + 1 archetyp (sheet) + seed.
- **M3** — `GenerateCatalogHandler` async + Mercure + delivery (token URL cache-and-serve).
- **M4** — API + OpenAPI + RBAC `catalogs_pdf`.
- **M5** — FE obszar + kreator + podgląd + lista runów.
- **M6** — archetypy grid + pricelist + `GotenbergRenderer` adapter + benchmark (decyzja A).

## 11. Integracja z istniejącym kodem (potwierdzone punkty zaczepienia)
- Route: `App.tsx` → `<Route path="/catalogs-pdf" element={<CatalogsPdfPage />} />`, `lazyPage(() => import('@/features/catalogs-pdf'), 'CatalogsPdfPage')`.
- Feature: `apps/admin/src/features/catalogs-pdf/index.tsx` — dziś `ComingSoonPlaceholder`, i18n `catalogs_pdf.page_title|placeholder_title|placeholder_description`.
- Menu: `sidebar-nav.tsx` → `id: system:catalogs_pdf`, `ref: catalogs_pdf`, `labelKey: nav.catalogsPdf`, `icon: FileText`, `route: /catalogs-pdf`, `protected: false` → do zmiany na gate.
- Backend: nowy `src/Export/Catalog/` obok istniejącego `src/Export/Feed/`.

## 12. Mini-ADR szkic — ADR-0025 (do dopisania po akceptacji) ⏳
„PDF renderer za portem `PdfRenderer`; silnik katalogów w `Export/Catalog/` (kalka Feed); Dompdf default in-process, Gotenberg opcjonalny sidecar, PDFreactor przyszły adapter CMYK. Web-to-print, nie konektor InDesign." Kontekst/alternatywy/konsekwencje jak MADR 4.0.

## 13. Źródła (research 2026-07-08)
- Akeneo App Store — konektory print/InDesign: https://apps.akeneo.com/search?category=print
- Pagination (Akeneo↔InDesign): https://pagination.com/akeneo-indesign/
- Pimcore Web-to-Print (dokumentacja): https://docs.pimcore.com/platform/Web_To_Print/
- PDFreactor + Pimcore, katalog 680 stron: https://www.pdfreactor.com/production-680-page-product-catalog-web-print-pimcore-pim-pdfreactor/
- Plytix Product Data Sheets: https://www.plytix.com/product-data-sheets/
- Plytix Branded PDFs: https://www.plytix.com/branded-pdfs
- Catsy print catalog: https://catsy.com/print-catalog-software
- Gotenberg — HTML to PDF (headless Chromium): https://gotenberg.dev/docs/convert-with-chromium/convert-html-to-pdf
- SensioLabs GotenbergBundle (Symfony): https://fsck.sh/en/blog/gotenberg-bundle-symfony-pdf-generation/
