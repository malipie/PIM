# Backlog — Dopracowanie grida per ObjectType: zarządzanie kolumnami + custom columns (epik GRID)

> **Status:** backlog do realizacji. Utworzony 2026-07-09.
> **Źródło planu:** plan zaakceptowany 2026-07-09 (grube punkty M1–M8) — kontekst, benchmark Akeneo/Plytix/PIMcore, decyzje operatora.
> **Decyzja architektoniczna:** ADR-0028 (`docs/adr/0028-attribute-sort-strategy.md`) — finalizowany w GRID-P5-01. *(Numer potwierdzony `ls docs/adr/`: 0027 zajęty przez CPDF; 0028 wolny.)*
> **Epik label:** `epik-GRID`. Prefix ID: `GRID`, format `GRID-P{faza}-{nn}`.
> **Milestone'y:** M1 Dynamiczne kolumny z list-schema · M2 Column manager UI · M3 Custom columns (pełny katalog atrybutów) · M4 Saved views v2 · M5 Sort po atrybutach · M6 Inline edit (Excel view) · M7 Eksport „co widzę" · M8 Performance + E2E + hardening.

Ten plik to **single source of truth** backlogu. GitHub Issues są lustrem (skondensowane body + link tutaj). Tracking faktyczny w Issues + milestone'ach.

**23 tickety, ~135–210h.** Backend jest w ~70% gotowy: `GET /api/object_types/{id}/list-schema` (ULV-03 #984) zwraca kolumny per ObjectType z RBAC-filtrowaniem 3-state, lista zwraca pełne `attributesIndexed`, `SavedView` ma `object_type_id` + JSONB `config`. Frontend tego nie konsumuje — kolumny hardcoded w `universal-list-page.tsx` (grid: 9 stałych, Excel: 3–4 stałe). Epik = spięcie istniejących klocków + 4 realnie nowe rzeczy: column manager UI, sort po JSONB (ADR), inline edit typed editors, ad-hoc eksport z grida.

**Decyzje operatora (2026-07-09):**
- Custom columns = **dowolny atrybut jako kolumna** (standard Akeneo/Plytix). Bez silnika formuł/kolumn wyliczanych (PIMcore grid operators) — poza zakresem.
- W zakresie: sort po atrybutach, inline edit kolumn atrybutowych (Excel view), eksport „tego co widzę".
- Poza zakresem: przełącznik kontekstu locale/channel nad gridem (endpoint `?locale/?channel` istnieje — hook na przyszły epik).

**Prior art — UWAGA:** [ULV-07 #989] „dynamic columns (system + show_in_list) + Saved Views overrides" jest **CLOSED, ale artefakty nie istnieją w kodzie** (brak `resolveListColumns.ts`, `ColumnsToolbar.tsx`, cell rendererów; kolumny nadal hardcoded). Scope #989 jest re-delivered w M1/M2/M4 tego epiku — tickety linkują #989 jako prior art. Lekcja CLOSED-MEANS-CLOSED w praktyce.

---

## Mapa GitHub Issues

_Uzupełniana po `gh issue create` — odwrotny indeks ID → numer._

| ID | Issue | ID | Issue | ID | Issue |
|---|---|---|---|---|---|
| GRID-P1-01 | #2385 | GRID-P1-02 | #2386 | GRID-P1-03 | #2387 |
| GRID-P1-04 | #2388 | GRID-P2-01 | #2389 | GRID-P2-02 | #2390 |
| GRID-P2-03 | #2391 | GRID-P3-01 | #2392 | GRID-P3-02 | #2393 |
| GRID-P4-01 | #2394 | GRID-P4-02 | #2395 | GRID-P4-03 | #2396 |
| GRID-P5-01 | #2397 | GRID-P5-02 | #2398 | GRID-P5-03 | #2399 |
| GRID-P6-01 | #2400 | GRID-P6-02 | #2401 | GRID-P6-03 | #2402 |
| GRID-P7-01 | #2403 | GRID-P7-02 | #2404 | GRID-P8-01 | #2405 |
| GRID-P8-02 | #2406 | GRID-P8-03 | #2407 | | |

_Milestone'y GitHub: #66–#73 (M1–M8 — GRID). Label: `epik-GRID`._

---

## Strategia PR — łączenie ticketów (decyzja operatora 2026-07-10)

**Motywacja:** `quality-php.yml` działa na `paths-ignore` (fail-safe, AUD-061) — pełne bramki PHP (w tym shard `api-catalog` ~20 min) odpalają się na **każdym** PR dotykającym kodu, nawet czysto frontendowym. Każdy PR z kodem = stały koszt ~20–25 min CI niezależnie od rozmiaru diffu, więc łączenie sąsiadujących ticketów w jeden PR to czysta oszczędność przebiegów bez utraty pokrycia (bramki walidują diff, nie liczbę PR-ów). Komplementarny lever: rozcięcie sharda `api-catalog` — osobny ticket infra (poza epikiem).

**Mapowanie 23 tickety → 13 PR-ów:**

| PR | Tickety | Uzasadnienie sklejenia |
|---|---|---|
| 1 | P1-01 + P1-02 | model kolumn + renderery — oba czysty lib, bez widocznej zmiany UI |
| 2 | P1-03 + P1-04 | konsumpcja modelu w obu widokach — jedna zmiana koncepcyjna |
| 3 | P2-01 + P2-02 + P2-03 | cały column manager — jedna powierzchnia UI, wspólne E2E |
| 4 | P3-01 + P3-02 | endpoint `?full=1` + jego jedyny konsument — razem smoke-owalne end-to-end |
| 5 | P4-01 + P4-03 | oba BE na SavedView (walidacja + default/system protection) |
| 6 | P4-02 | osobno — zależy od PR 3 i 5 |
| 7 | P5-01 | **osobno** — ADR + benchmark, `[PM]`, nie mieszać z implementacją |
| 8 | P5-02 + P5-03 | sort BE+FE — bez nagłówków sort nie jest sensownie smoke-owalny |
| 9 | P6-01 + P6-02 | flaga `editable` bez edytorów to martwy kod |
| 10 | P6-03 | osobno — nadbudowa; PR 9 już duży (15–21h) |
| 11 | P7-01 + P7-02 | eksport end-to-end (BE endpoint + przycisk) |
| 12 | P8-01 | **osobno** — wirtualizacja = izolowane ryzyko regresji, revert chirurgiczny |
| 13 | P8-02 + P8-03 | E2E journey + closeout |

**Reguły jakościowe (nienegocjowalne przy bundlowaniu):**

- **Issues zostają 1:1** — PR ma `Closes #A, Closes #B`; AC odhaczane osobno per ticket w PR body; każde issue zamykane z **własnym** proofem smoke (CLOSED MEANS CLOSED bez zmian).
- Bundlujemy tylko tickety z **jednej spójnej powierzchni feature** (revert PR-a = spójna cofka, nie amputacja połowy mechanizmu).
- **Nie bundlujemy przez granice ryzyka:** ADR (`[PM]`) i tickety high-risk izolowane (PR 7, PR 12).
- Czerwone CI jednej części bundla → naprawa albo **rozcięcie PR-a z powrotem**; nigdy wycinanie testów/AC, żeby „przeszło".
- Bundel nie zwalnia z pełnego DoD żadnego ticketu składowego (E2E, axe, OpenAPI snapshot, i18n itd. per ticket).

---

## Konwencje

- **Cls:** `BE` · `FE` · `DOCS` · `PERF` · `E2E`.
- **[PM]:** ticket wymaga Plan Mode — decyzja architektoniczna lub nowa zależność core.
- **[DEF]:** hook świadomie odłożony (nie ma issue na starcie epiku).
- **Bounded context:** BE w `apps/api/src/Catalog/**` (list-schema, saved views, sort filter) + `apps/api/src/Export/**` (ad-hoc eksport, przez `Export/Contracts` / `Catalog/Contracts` seam — Deptrac). FE w `apps/admin/src/components/{objects,catalog}/**` + nowy `apps/admin/src/lib/grid/**`.
- **Tytuł Issue:** angielski Conventional Commit `{feat|docs|chore|test|perf}(scope): subject`. Body + AC po polsku. Kod po angielsku.

### Standard DoD (każdy ticket, o ile nie zaznaczono inaczej)

- [ ] Acceptance criteria spełnione.
- [ ] **PHPStan max**: 0 errors (BE) · **Deptrac**: 0 violations · **PHP-CS-Fixer** czysto.
- [ ] **Biome strict** + **tsc --noEmit**: 0 errors (FE).
- [ ] **PHPUnit** ≥80% nowej logiki; **ApiTestCase** dla każdego endpointu/parametru (401 + 403 + 404 + walidacja + happy path).
- [ ] **Vitest** dla pure functions FE (resolvery kolumn, serializacja config).
- [ ] **Playwright E2E**: happy path + ≥1 edge case (FE z widoczną zmianą).
- [ ] **axe-core**: 0 violations serious/critical (FE).
- [ ] **Multi-tenancy**: cross-tenant read = 0 wyników (SavedView, list-schema).
- [ ] **RBAC**: kolumny/wartości `restricted` niewidoczne (list-schema + `AttributeReadRestrictionOverlay`); edycja tylko przy `canEditAttribute`.
- [ ] **OpenAPI snapshot** `docs/api-spec/v0.json` zaktualizowany (nowe custom trasy / parametry — ADR-0020).
- [ ] **i18n**: wszystkie user-facing stringi przez `t()`, klucze `grid.*` w `locales/{pl,en}.json`.
- [ ] Manual smoke 5 min na `pim.localhost`; PR opis nie używa „działa" bez smoke testu (SMOKE TEST RULE).
- [ ] CI green; PR merged do main.

### Reuse (potwierdzone eksploracją, 2026-07-09)

| Klocek | Ścieżka | Rola w epiku GRID |
|---|---|---|
| List-schema endpoint (ULV-03 #984): kolumny system + `show_in_list=true`, RBAC 3-state filter | `apps/api/src/Catalog/Presentation/Controller/ObjectTypeListSchemaController.php` + `GetObjectTypeListSchemaHandler.php` | Źródło modelu kolumn; M3 rozszerza o pełny katalog, M6 o flagę `editable` |
| Hook FE list-schema (5 min cache, typy `ListSchemaColumn`) | `apps/admin/src/hooks/use-list-schema.ts` | Wejście `useGridColumns()` — dziś pobierany, ignorowany przez grid |
| `attributesIndexed` w każdym wierszu listy + helper unwrap | `apps/admin/src/lib/attributes-indexed.ts` | Wartości komórek atrybutowych bez zmian w list endpoint |
| `ColumnPickerV2` (dwa panele, grupy, search, @dnd-kit drag+keyboard, locked SKU) | `apps/admin/src/features/exports/components/ColumnPickerV2.tsx` | Wzorzec column managera (M2) i mapowania kolumn eksportu (M7) |
| `SavedView` entity (`object_type_id`, `config` JSONB, `is_default`, system views `user_id IS NULL`) + CRUD | `apps/api/src/Catalog/Domain/Entity/SavedView.php`, `.../Presentation/Controller/SavedViewController.php` | M4 nadaje `config` strukturę + walidację; FE round-trip |
| `SavedViewsRail` + `SaveViewModal` (deklarują `visible_columns`, nigdy nie używane) | `apps/admin/src/components/catalog/saved-views-rail.tsx`, `save-view-modal.tsx` | M4 domyka dług |
| `AttributeFilter` (JSONB `@>` + GIN `jsonb_path_ops`) | `apps/api/src/Catalog/Infrastructure/ApiPlatform/Filter/AttributeFilter.php`, migracja `Version20260617210000.php` | Wzorzec dla `AttributeOrderFilter` (M5) |
| Cursor pagination (`paginationViaCursor`, `id DESC`, RangeOnId) | `apps/api/src/Catalog/Infrastructure/ApiPlatform/Resource/CatalogObject.xml` | M5 musi zachować deterministyczny tie-breaker `id` |
| `AttributeReadRestrictionOverlay` (batch RBAC strip na `attributes_indexed`) + `AttributePermissionReader` (`canView/canEditAttribute`) | `apps/api/src/Catalog/Application/AttributeReadRestrictionOverlay.php`, `apps/api/src/Identity/**` | Read-side już gotowy; M6 używa `canEditAttribute` |
| `ExcelLikeGrid` (wirtualizacja kolumn @tanstack/react-virtual, inline edit, TSV copy/paste, props `columns: ExcelColumn[]`) | `apps/admin/src/components/catalog/excel-like-grid.tsx` | M1 podaje dynamiczne kolumny; M6 typed editors; M8 wirtualizacja wierszy |
| `ProductsGrid` (CSS grid, 9 stałych kolumn, variants tree) | `apps/admin/src/components/catalog/products-grid.tsx` | M1 dynamiczny `grid-template-columns` |
| Orkiestrator listy (view mode, paginacja, filtry, selection) | `apps/admin/src/components/objects/universal-list-page.tsx` | Punkt integracji wszystkich M1–M7 |
| Silnik Export: `ExportBuilder` + `ColumnResolver` + `ValueSerializer` | `apps/api/src/Export/Application/Builder/` | M7 ad-hoc eksport — kolumny grida → kolumny eksportu |
| Advanced filter DSL (FE `filter-dsl.ts` + BE `FilterDslResolver`) | `apps/admin/src/lib/filters/`, `apps/api/src/Catalog/Application/Filter/` | M7 przekazuje aktywne filtry do eksportu |
| Write path atrybutów (PATCH `/api/objects/{id}`, `ObjectAttributesUpserter`, provenance `manual`) | `apps/api/src/Catalog/**` | M6 commit inline edit — bez nowego endpointu |
| `CustomRouteOpenApiFactory` (ADR-0020) · `#[RequiresPermission]` + `EndpointGuardListener` | `apps/api/src/Shared/OpenApi/`, `apps/api/src/Identity/**` | Nowe trasy/parametry w OpenAPI; RBAC na endpointach |
| @tanstack/react-virtual v3 + @dnd-kit (już w `package.json`) | `apps/admin/package.json` | **Zero nowych zależności FE w całym epiku** |

---

# M1 — Dynamiczne kolumny z list-schema (fundament)

### GRID-P1-01: feat(admin): add useGridColumns hook resolving list-schema into grid column model
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M1 · **Est:** 5-8h · **Risk:** medium · `blocker`
- **Blocked by:** — · **Blocks:** GRID-P1-02, GRID-P1-03, GRID-P1-04, GRID-P2-01
- **Po co:** Jeden model kolumn dla obu widoków (grid + Excel) i dla column managera. Dziś `universal-list-page.tsx` trzyma stałe definicje; `use-list-schema.ts` pobiera schema i nikt go nie czyta. Bez tego fundamentu każdy kolejny ticket (manager, saved views, sort) nie ma na czym stanąć. Re-delivery rdzenia zamkniętego bez implementacji [ULV-07 #989] (`resolveListColumns`).
- **Stan obecny:** `useListSchema()` zwraca `{objectType, columns: ListSchemaColumn[], filterableAttributes, searchableAttributes}` (cache 5 min); kolumny listy hardcoded w `universal-list-page.tsx` (grid: 9, Excel: 3–4); RBAC już tnie kolumny `restricted` po stronie BE.
- **Zakres:**
  - Nowy moduł `apps/admin/src/lib/grid/`: typ `GridColumn` (key, source: `system|attribute`, attributeType, label i18n, sortable, width?, pinned?, hidden?, position) + pure function `resolveGridColumns(schema, userOverrides?)` (Vitest).
  - Hook `useGridColumns(objectTypeId)`: merge schema → `GridColumn[]` + user overrides z `localStorage` (`pim.objectList.{objectTypeId}.columns` — kształt gotowy pod M2/M4).
  - Kolumny systemowe zawsze dostępne (code/SKU, status, completeness, updatedAt); atrybutowe z `show_in_list=true` domyślnie widoczne.
  - Fallback: schema niedostępna (błąd/ładowanie) → dotychczasowy stały zestaw kolumn (bez regresji).
- **Poza zakresem:** Renderery komórek (P1-02), zmiany w komponentach grid/Excel (P1-03/04), UI managera (M2), persystencja w SavedView (M4).
- **AC:**
  - [ ] `resolveGridColumns()` — pure function z testami Vitest: schema-only, z overrides (hidden/reorder/width), schema pusta → fallback.
  - [ ] Hook zwraca stabilny model dla `/products` i `/objects/{slug}` (dowolny ObjectType).
  - [ ] Kolumna nieobecna w schema (RBAC restricted) nigdy nie pojawia się w modelu, nawet jeśli siedzi w localStorage overrides.
  - [ ] Zero zmian wizualnych w tym tickecie (model nieskonsumowany — konsumpcja w P1-03/04).
- **Smoke:** Vitest zielony; devtools: hook zwraca kolumny zgodne z `GET /api/object_types/{id}/list-schema` dla produktów i jednego custom ObjectType.
- **Reuse:** `use-list-schema.ts` · wzorzec localStorage per ObjectType z `universal-list-page.tsx` (`pim.objectList.{id}.viewMode`)
- **Referencje:** [ULV-07 #989] (prior art, closed-nie-dowiezione) · `Project Plan/UI/feature-universal-object-list.md` §7
- **DoD:** standard (bez Playwright — brak widocznej zmiany).

### GRID-P1-02: feat(admin): add attribute cell renderer registry for list views
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** GRID-P1-01 · **Blocks:** GRID-P1-03, GRID-P1-04
- **Po co:** Kolumna atrybutowa musi wyrenderować wartość z envelope `attributesIndexed` zgodnie z typem atrybutu — inaczej grid pokaże surowe JSON-y/kody opcji. Rejestr rendererów to warstwa współdzielona przez oba widoki i (read-only) przez przyszłe konteksty.
- **Stan obecny:** `unwrapAttributesIndexed()` w `apps/admin/src/lib/attributes-indexed.ts` rozpakowuje envelope ({value}, {option_code}, {option_codes}, {amount,currency}, mapy locale/channel). Wartości używane tylko w search hits i detail — grid nie renderuje żadnej kolumny atrybutowej.
- **Zakres:**
  - `apps/admin/src/lib/grid/cell-renderers.tsx`: rejestr per typ — text, textarea (truncate + tooltip), number (right-align + format locale), boolean (badge), date (locale-aware), single_select (label opcji, fallback kod), multi_select (chipy, +N overflow), price (amount + currency), media (miniatura 32px z asset URL), relation (label + link do detail).
  - Wartości localizable/scopable: renderuj wartość dla bieżącego locale UI (`i18n.language`), fallback pierwsza dostępna + subtelny wskaźnik locale (kontekst locale/channel nad gridem — poza zakresem epiku).
  - Źródło labelek opcji select: batch fetch opcji dla widocznych kolumn select (istniejące endpointy atrybutów) + cache (react-query, staleTime jak list-schema); fallback `option_code` gdy brak.
  - Wartość pusta → spójny em-dash `—`; wartość nieznanego typu → bezpieczny `String()` (bez crashy).
- **Poza zakresem:** Edycja (M6). Kolumny relacyjne z dociąganiem danych powiązanych obiektów per wiersz (MVP: to co w `attributesIndexed`). Kontekst locale/channel.
- **AC:**
  - [ ] Każdy typ atrybutu występujący w seedzie demo renderuje się czytelnie (bez `[object Object]`, bez surowych kodów tam, gdzie są labelki).
  - [ ] Select/multiselect pokazują labelki opcji w locale UI; brak labelki → kod.
  - [ ] Renderer nie wybucha na null/undefined/nieoczekiwanym kształcie envelope (test Vitest na garbage input).
  - [ ] Media-thumb nie powoduje layout shift (stały rozmiar, lazy loading).
- **Smoke:** Storybook-less smoke przez P1-03/04 (renderery skonsumowane tam); Vitest na unwrap+render logikę per typ.
- **Reuse:** `attributes-indexed.ts` · formatery z detail page (jeśli istnieją — sprawdzić `apps/admin/src/features/catalog/` przed pisaniem własnych) · `StatusPill`/badge z `ui-v2`
- **Referencje:** `docs/api/jsonb-schemas.md` (authoritative envelope shape — reader MUSI być zgodny)
- **DoD:** standard (Playwright w P1-03/04).

### GRID-P1-03: feat(admin): render dynamic columns in grid view (products-grid)
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M1 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** GRID-P1-01, GRID-P1-02 · **Blocks:** GRID-P2-02, GRID-P8-01
- **Po co:** Pierwszy widoczny efekt epiku: widok grid buduje kolumny z modelu `useGridColumns()` zamiast 9 stałych. Bez tego column manager (M2) nie miałby czym sterować.
- **Stan obecny:** `products-grid.tsx` — CSS grid z hardcoded `grid-template-columns` (`'44px 52px 150px minmax(260px,1.6fr) ...'`), 9 stałych kolumn, variants tree, selekcja.
- **Zakres:**
  - `grid-template-columns` generowany z modelu kolumn (szerokości domyślne per typ; szerokość z overrides gdy jest).
  - Kolumny strukturalne (checkbox selekcji, miniatura, akcje wiersza) pozostają stałe — nie podlegają managerowi; kolumny danych (SKU, nazwa, completeness, sync, atrybutowe...) z modelu.
  - Nagłówki z `label` i18n modelu; zachować variants tree (chevron w kolumnie identyfikatora), selekcję, hover, bulk toolbar bez regresji.
  - Działa dla `/products` i `/objects/{slug}` (dowolny ObjectType — kolumny per jego schema).
- **Poza zakresem:** UI zarządzania (M2), resize/pin (P2-02), sort po atrybutach (M5).
- **AC:**
  - [ ] Widok grid renderuje kolumny z modelu; domyślny zestaw = dotychczasowe kolumny + atrybuty `show_in_list=true` (wizualna parzystość dla świeżego usera).
  - [ ] Custom ObjectType bez wariantów/multimediów nie renderuje kolumn variants/miniatur (capability flags z `objectType`).
  - [ ] Playwright: `/products` renderuje kolumnę atrybutową z seedu (np. brand) z poprawną wartością; `/objects/{custom-slug}` renderuje własny zestaw.
  - [ ] Bez regresji: selekcja, variants tree, akcje wiersza, paginacja (istniejące E2E zielone).
- **Smoke:** Login → `/products` → kolumny atrybutowe widoczne z wartościami; custom ObjectType → własne kolumny; Console bez errorów.
- **Reuse:** `products-grid.tsx` (modyfikacja in-place) · P1-01/P1-02
- **DoD:** standard.

### GRID-P1-04: feat(admin): render dynamic columns in Excel view
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M1 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** GRID-P1-01, GRID-P1-02 · **Blocks:** GRID-P6-02, GRID-P8-01
- **Po co:** Excel view ma już architekturę pod dynamiczne kolumny (`columns: ExcelColumn[]` props + wirtualizacja kolumn) — hardcoded jest tylko definicja w orkiestratorze. Po tym tickecie oba widoki jedzą z jednego modelu.
- **Stan obecny:** `excel-like-grid.tsx` przyjmuje `ExcelColumn<T>[]` (key, label, type, width, readOnly); `universal-list-page.tsx` podaje stałe 3–4 kolumny; wirtualizacja horyzontalna @tanstack/react-virtual działa.
- **Zakres:**
  - Adapter `GridColumn` → `ExcelColumn` w `lib/grid/` (kolumny atrybutowe **readOnly=true do M6**; wartość przez renderer P1-02 w trybie display).
  - Wszystkie widoczne kolumny modelu trafiają do Excel view (wirtualizacja utrzymuje płynność przy 30+ kolumnach).
  - Inline edit SKU/name działa jak dotychczas (bez regresji); copy TSV obejmuje kolumny atrybutowe (read-only wartości tekstowe).
- **Poza zakresem:** Edycja kolumn atrybutowych + paste na nie (M6). Wirtualizacja wierszy (M8).
- **AC:**
  - [ ] Excel view renderuje ten sam zestaw/kolejność kolumn co widok grid (jeden model).
  - [ ] Kolumny atrybutowe read-only (wizualne rozróżnienie jak istniejące read-only cells); edycja SKU/name bez regresji.
  - [ ] Ctrl/Cmd+C na zaznaczeniu obejmującym kolumny atrybutowe daje poprawny TSV.
  - [ ] Playwright: przełączenie grid→excel zachowuje kolumny; 30 kolumn scrolluje płynnie (wirtualizacja: DOM nie zawiera wszystkich kolumn).
- **Smoke:** `/products` → toggle Excel → kolumny atrybutowe widoczne, edycja name działa, copy TSV z wartościami atrybutów.
- **Reuse:** `excel-like-grid.tsx` (props już gotowe) · P1-01/P1-02
- **DoD:** standard.

---

# M2 — Column manager UI

### GRID-P2-01: feat(admin): add column manager with show/hide and drag reorder
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M2 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** GRID-P1-03, GRID-P1-04 · **Blocks:** GRID-P3-02, GRID-P4-02, GRID-P7-02
- **Po co:** Sedno „zarządzania kolumnami" z briefu: użytkownik sam decyduje co widzi i w jakiej kolejności — parytet z Akeneo/Plytix/PIMcore. Wzorzec UI już istnieje w export wizard (`ColumnPickerV2`) — reuse, nie nowy design.
- **Stan obecny:** Zero UI zarządzania kolumnami na listach; `ColumnPickerV2` (dwa panele, grupy, search, @dnd-kit drag + keyboard, locked pierwsza kolumna) żyje tylko w export wizard. Overrides czyta już `useGridColumns()` (P1-01).
- **Zakres:**
  - Przycisk „Kolumny" w toolbarze listy (obok view-mode toggle) → popover/sheet: lista dostępnych kolumn (system + atrybutowe ze schema) z checkboxami, drag-reorder widocznych, search, „Resetuj do domyślnych".
  - Wydzielić z `ColumnPickerV2` współdzielone prymitywy (lista dnd + search + grupy) do `components/ui-v2/` lub `lib/grid/` — bez łamania export wizard (jego E2E zielone).
  - Zmiany zapisywane natychmiast do localStorage overrides (`pim.objectList.{objectTypeId}.columns`); oba widoki reagują live.
  - Kolumna identyfikatora (SKU/code) locked — zawsze widoczna, zawsze pierwsza (wzorzec locked z ColumnPickerV2).
  - A11y: drag z keyboard support (@dnd-kit sensors), focus trap w popoverze, axe-core czysto.
- **Poza zakresem:** Pełny katalog atrybutów spoza `show_in_list` (M3 — tu tylko to, co daje schema). Resize/pin/density (P2-02/03). Zapis w SavedView (M4).
- **AC:**
  - [ ] Ukrycie kolumny znika z obu widoków natychmiast; reorder zmienia kolejność w obu.
  - [ ] Stan przeżywa reload (localStorage per ObjectType) i jest niezależny per ObjectType.
  - [ ] „Resetuj" przywraca zestaw ze schema (czyści overrides).
  - [ ] Export wizard bez regresji (E2E zielone po wydzieleniu prymitywów).
  - [ ] Playwright: ukryj → reload → nadal ukryta; reorder drag → kolejność persystuje; keyboard reorder działa.
- **Smoke:** `/products` → „Kolumny" → ukryj completeness, przeciągnij brand na 2. pozycję → reload → stan zachowany; to samo na custom ObjectType niezależnie.
- **Reuse:** `ColumnPickerV2.tsx` (@dnd-kit) · `useGridColumns()` (P1-01)
- **Referencje:** [ULV-07 #989] (prior art „Toolbar Kolumny")
- **DoD:** standard.

### GRID-P2-02: feat(admin): add column resize and pinned identifier column
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M2 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** GRID-P2-01 · **Blocks:** GRID-P4-02
- **Po co:** Przy 15+ kolumnach stałe szerokości przestają działać: długie nazwy się ucinają, wąskie kolumny marnują miejsce, a scroll horyzontalny bez przypiętego identyfikatora gubi kontekst wiersza (benchmark: wszystkie 3 PIM-y mają resize + frozen first column).
- **Stan obecny:** Szerokości stałe w template (grid view) / `ExcelColumn.width` (Excel view). Brak resize, brak sticky kolumn.
- **Zakres:**
  - Uchwyt resize na krawędzi nagłówka (oba widoki; pointer events, min/max width per typ); szerokość do overrides w localStorage.
  - Kolumna identyfikatora (checkbox+SKU) sticky left przy scrollu horyzontalnym (CSS `position: sticky` + z-index + cień krawędzi).
  - Double-click na uchwycie → auto-fit do zawartości (proste heurystyki, bez pomiarów canvas).
- **Poza zakresem:** Pinning dowolnych kolumn przez użytkownika (MVP: tylko identyfikator; model `GridColumn.pinned` gotowy na przyszłość). Persist w SavedView (M4).
- **AC:**
  - [ ] Resize działa myszą i przeżywa reload; min-width zapobiega degeneracji (kolumna nie znika).
  - [ ] Przy scrollu horyzontalnym identyfikator + checkbox pozostają widoczne w obu widokach.
  - [ ] Wirtualizacja kolumn Excel view poprawnie przelicza offsety po resize.
  - [ ] Playwright: resize kolumny → szerokość po reloadzie zachowana; scroll w prawo → SKU widoczne.
- **Smoke:** 20+ kolumn na `/products` → resize brand, scroll w prawo, SKU przypięte, bez glitchy wizualnych.
- **Reuse:** overrides z P1-01 · wirtualizacja `excel-like-grid.tsx`
- **DoD:** standard.

### GRID-P2-03: feat(admin): add density toggle for list views
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M2 · **Est:** 3-5h · **Risk:** low
- **Blocked by:** GRID-P2-01 · **Blocks:** —
- **Po co:** Praca „arkuszowa" na dziesiątkach wierszy wymaga trybu compact (Akeneo/Plytix standard); domyślna gęstość zostaje komfortowa dla przeglądania.
- **Stan obecny:** Jedna gęstość (padding stały w obu widokach).
- **Zakres:**
  - Toggle compact/normal w toolbarze (obok „Kolumny"); wpływa na row height + paddingi + rozmiar miniatur w obu widokach.
  - Persist w localStorage per ObjectType (`pim.objectList.{id}.density`), kształt gotowy pod SavedView (M4).
- **Poza zakresem:** Tryb „spacious", zapis w SavedView (M4).
- **AC:**
  - [ ] Compact zmniejsza wysokość wiersza ≥25% bez łamania layoutu (miniatury, chipy, badge skalują się).
  - [ ] Stan przeżywa reload i przełączanie widoków; axe-core czysto (touch targets w compact ≥ minimalne).
  - [ ] Playwright: toggle → wysokość wierszy zmieniona → reload → zachowane.
- **Smoke:** `/products` → compact → gęstość rośnie, czytelność zachowana, Excel view też compact.
- **Reuse:** wzorce toggle z `view-mode-toggle.tsx`
- **DoD:** standard.

---

# M3 — Custom columns: dowolny atrybut ObjectType

### GRID-P3-01: feat(catalog): extend list-schema with full attribute catalogue mode
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** — · **Blocks:** GRID-P3-02
- **Po co:** Decyzja operatora: custom column = **dowolny** atrybut ObjectType, nie tylko `show_in_list=true`. Column manager potrzebuje pełnego katalogu (z grupami) do panelu „dostępne", z zachowaniem RBAC 3-state.
- **Stan obecny:** `GetObjectTypeListSchemaHandler` zwraca kolumny tylko z `show_in_list=true` (junction `object_type_attributes`, sort `list_position`), tnie `restricted` przez `AttributePermissionReader.canViewAttribute()`. Wartości WSZYSTKICH atrybutów i tak są w `attributesIndexed` listy — list endpoint bez zmian.
- **Zakres:**
  - Parametr `?full=1` na `GET /api/object_types/{id}/list-schema`: kolumny = wszystkie atrybuty ObjectType (junction), każdy z flagą `default` (`show_in_list`) + `group` ({id, code, label, position} z `AttributeGroup`) + istniejące pola (type, label, sortable, position).
  - RBAC identycznie jak dziś: `restricted` wycięte także w trybie full (test ApiTestCase z rolą ograniczoną — wzorzec ULV-04b).
  - Bez `?full` odpowiedź bajt-w-bajt jak dotychczas (backward compat, snapshot test).
  - `sortable` per atrybut wg reguł M5 (typ prosty, nielokalizowany, nieskalowany kanałem) — flaga liczona tu, egzekwowana w P5-02.
  - OpenAPI: parametr udokumentowany (`CustomRouteOpenApiFactory` — trasa już custom), regeneracja `docs/api-spec/v0.json`.
- **Poza zakresem:** Sparse fieldsets `?fields=` na liście (hook [DEF] poniżej). Zmiany w list endpoint. Flaga `editable` (GRID-P6-01).
- **AC:**
  - [ ] `?full=1` zwraca wszystkie atrybuty ObjectType z grupami; bez parametru — odpowiedź niezmieniona.
  - [ ] Restricted attribute nieobecny w `?full=1` dla roli z 3-state `restricted` (ApiTestCase).
  - [ ] Multi-tenancy: schema innego tenanta = 404/0 wyników.
  - [ ] `sortable=false` dla atrybutów localizable/scopable/typów złożonych (media, relation, multiselect).
  - [ ] OpenAPI snapshot zaktualizowany.
- **Smoke:** `curl -H auth "https://pim.localhost/api/object_types/{id}/list-schema?full=1"` → 200, pełny katalog z grupami; user marketing (restricted) nie widzi wyciętego atrybutu.
- **Reuse:** `GetObjectTypeListSchemaHandler` · `AttributePermissionReader` · `AttributeGroup`
- **DoD:** standard.

### GRID-P3-02: feat(admin): full attribute catalogue in column manager
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 5-8h · **Risk:** low
- **Blocked by:** GRID-P2-01, GRID-P3-01 · **Blocks:** —
- **Po co:** Domknięcie „custom columns": użytkownik dodaje do grida dowolny atrybut swojego ObjectType — pogrupowany, przeszukiwalny, jak w Akeneo.
- **Stan obecny:** Column manager (P2-01) pokazuje tylko kolumny ze standardowej schema; `?full=1` gotowe po P3-01.
- **Zakres:**
  - Manager pobiera `?full=1` (lazy — dopiero przy otwarciu popovera): sekcja „dostępne" grupowana po `AttributeGroup` (accordion + search po labelu/kodzie), kolumny `default` odznaczone wizualnie.
  - Dodany atrybut od razu renderuje wartości (są w `attributesIndexed`) przez renderery P1-02; trafia do overrides jak każda kolumna.
  - Empty state: atrybut bez wartości w widocznych wierszach → `—` (bez dodatkowych fetchy).
- **Poza zakresem:** Edycja tych kolumn (M6), sort (M5 — nagłówek nieaktywny gdy `sortable=false`), sparse fieldsets.
- **AC:**
  - [ ] Każdy niewycięty przez RBAC atrybut ObjectType do dodania z managera; wartości widoczne natychmiast bez reloadu.
  - [ ] Grupy + search działają przy 200+ atrybutach bez lagów (wirtualizacja listy w popoverze jeśli trzeba).
  - [ ] Playwright: dodaj atrybut spoza domyślnych → kolumna z wartością → reload → zachowana.
- **Smoke:** `/products` → „Kolumny" → search po atrybucie spoza listy → dodaj → wartości w kolumnie; user restricted nie widzi go na liście dostępnych.
- **Reuse:** P2-01 manager · P1-02 renderery · `use-list-schema.ts` (rozszerzyć o wariant full)
- **DoD:** standard.

### GRID-P3-03 `[DEF]`: perf(catalog): sparse fieldsets `?fields[attributes]=` on list endpoint
- **Hook świadomie odłożony** (bez issue na starcie epiku). Payload listy z pełnym `attributesIndexed` przy 200+ atrybutach × 200 wierszy może być ciężki (setki KB). Jeśli benchmark M8 (GRID-P8-01/02) pokaże problem transferu/parsowania — otworzyć ticket: parametr `?fields[attributes]=a,b,c` filtrujący envelope po stronie BE (po `AttributeReadRestrictionOverlay`), FE wysyła klucze widocznych kolumn. Do tego czasu: optymalizacja, nie bloker (GIN/JSONB i tak zwraca całą kolumnę z wiersza).

---

# M4 — Saved views v2 (persystencja konfiguracji grida)

### GRID-P4-01: feat(catalog): validate structured SavedView config schema
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** — · **Blocks:** GRID-P4-02, GRID-P4-03
- **Po co:** `SavedView.config` to dziś nieuwalidowany JSONB („operator responsibility") — przy round-tripie kolumn/sortu/density śmieciowy config zepsułby render listy dla całego widoku. Struktura + walidacja po stronie API zanim FE zacznie pisać.
- **Stan obecny:** `SavedView` (tenant, user_id nullable=system, slug, resource, object_type_id, config JSONB, is_default) + CRUD w `SavedViewController`; config przyjmowany as-is; FE zapisuje `{filters, variants_mode, page_size}` i deklaruje nieużywane `visible_columns`.
- **Zakres:**
  - Kanoniczny kształt config (dokumentowany w `docs/api/jsonb-schemas.md`): `{filters?, sort?: {key, dir}, columns?: [{key, width?, pinned?}], density?: 'compact'|'normal', variants_mode?, page_size?}`.
  - Walidacja na POST/PATCH: nieznane klucze top-level odrzucane (400 RFC 7807), typy sprawdzane, `columns[].key` string (istnienie atrybutu weryfikuje FE przy odczycie — klucze mogą przeżyć usunięcie atrybutu, odczyt filtruje przez schema).
  - Backward compat: istniejące configi (stary kształt) czytane bez błędu; brak migracji danych (klucze opcjonalne).
  - ApiTestCase: happy, walidacja 400, cross-tenant 404, `is_default` semantyka bez regresji.
- **Poza zakresem:** FE round-trip (P4-02), default per ObjectType + ochrona system views (P4-03), sharing per zespół (follow-up po epiku — wymaga RBAC na SavedView).
- **AC:**
  - [ ] POST/PATCH z poprawnym configiem → 2xx; z nieznanym kluczem/złym typem → 400 Problem Details z polem.
  - [ ] Stare widoki (config bez `columns`) czytają się i aktualizują bez błędu.
  - [ ] `docs/api/jsonb-schemas.md` uzupełniony o kształt SavedView.config; OpenAPI snapshot zaktualizowany.
- **Smoke:** curl POST saved-view z columns+sort+density → 200; z `{"garbage": 1}` → 400.
- **Reuse:** `SavedViewController` · wzorce walidacji Problem Details z innych kontrolerów Catalog
- **DoD:** standard.

### GRID-P4-02: feat(admin): persist and restore grid config in saved views
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M4 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** GRID-P2-01, GRID-P2-02, GRID-P4-01 · **Blocks:** —
- **Po co:** Widok zapisany = filtry **+ kolumny + sort + density** (parytet Akeneo saved views / PIMcore grid configs). Domyka dług: `visible_columns` deklarowane w SaveViewModal od miesięcy, nigdy nie zapisywane.
- **Stan obecny:** `SaveViewModal` zapisuje `{filters, variants_mode, page_size}`; `SavedViewsRail` odtwarza tylko filtry+variants_mode; overrides kolumn żyją wyłącznie w localStorage (M2).
- **Zakres:**
  - Zapis: SaveViewModal zbiera bieżące `columns` (widoczne, kolejność, szerokości), `sort`, `density` z modelu grida → config (kształt P4-01); preview w modalu pokazuje realną liczbę kolumn.
  - Odczyt: wybór widoku w rail aplikuje kolumny/sort/density (kolumny nieistniejące w schema — np. usunięty atrybut / RBAC — filtrowane cicho); przełączenie na inny widok w pełni nadpisuje stan.
  - Precedens stanu: aktywny SavedView > localStorage quick-prefs > default ze schema; modyfikacja kolumn przy aktywnym widoku = „draft" (wskaźnik zmian + „Zapisz zmiany widoku" dla widoków własnych).
  - Update istniejącego widoku (PATCH) obok „Zapisz jako nowy".
- **Poza zakresem:** Ochrona system views i default per ObjectType (P4-03), sharing.
- **AC:**
  - [ ] Zapis → reload → wybór widoku odtwarza kolumny (zestaw+kolejność+szerokości), sort, density, filtry.
  - [ ] Widok z kolumną-atrybutem usuniętym z ObjectType nie psuje renderu (kolumna pomijana).
  - [ ] Playwright: pełny round-trip (skonfiguruj → zapisz → zmień → przywróć przez rail → stan wrócił) + edge: widok z nieistniejącą kolumną.
- **Smoke:** `/products` → dodaj kolumnę, sort, compact → „Zapisz widok" → reload → wybierz widok → wszystko wraca; DevTools: POST/GET saved-views 2xx.
- **Reuse:** `save-view-modal.tsx` · `saved-views-rail.tsx` · model P1-01
- **Referencje:** [ULV-07 #989] (prior art „Persist preferencji kolumn per user w Saved View")
- **DoD:** standard.

### GRID-P4-03: feat(catalog): default view per (tenant, objectType) and system view protection
- **Typ:** `feat` · **Cls:** BE+FE · **Milestone:** M4 · **Est:** 5-8h · **Risk:** low
- **Blocked by:** GRID-P4-01 · **Blocks:** —
- **Po co:** Widok domyślny musi być per ObjectType (dziś semantyka `is_default` czyszczona per `(tenant, resource)` — legacy sprzed `object_type_id`), a seedowane widoki systemowe (`user_id IS NULL`) nie mogą być edytowalne/usuwalne przez użytkowników (deklarowane „by design", nieegzekwowane — luka z ULV-11).
- **Stan obecny:** `SavedView.object_type_id` istnieje (ULV-01 #982) z indeksem; clear-default działa po `(tenant, resource)`; DELETE/PATCH nie sprawdza `is_system`; listowanie zwraca wszystkie widoki tenanta (bez per-user separacji — MVP OK).
- **Zakres:**
  - `is_default` unikalny per `(tenant, object_type_id, user_id)` — clear-default po tej krotce; widok domyślny aplikowany przy wejściu na listę bez parametrów.
  - PATCH/DELETE na widoku systemowym → 403 (poza edycją `is_default` marking własnego defaultu); FE: brak przycisków edycji/usuwania na systemowych, badge „Systemowy".
  - Seed: widok systemowy „Domyślny" per built-in ObjectType z kolumnami = schema default (idempotentny, wzorzec istniejących seedów).
- **Poza zakresem:** Role-based sharing widoków; per-user listing separation (świadomie MVP).
- **AC:**
  - [ ] Dwa ObjectType mogą mieć niezależne defaulty; ustawienie defaultu nie kasuje defaultu innego ObjectType (ApiTestCase).
  - [ ] PATCH/DELETE system view → 403 (test); FE nie oferuje akcji.
  - [ ] Wejście na `/objects/{slug}` bez parametrów aplikuje default widok tego ObjectType.
- **Smoke:** Ustaw default na produktach i na custom OT → oba działają niezależnie; próba usunięcia „Domyślny" → brak opcji w UI, curl → 403.
- **Reuse:** `SavedViewController` · `SavedViewsRail`
- **DoD:** standard.

---

# M5 — Sort po kolumnach-atrybutach

### GRID-P5-01: docs(architecture): add ADR-0028 attribute sort strategy with 50k benchmark
- **Typ:** `docs` · **Cls:** DOCS+PERF · **Milestone:** M5 · **Est:** 5-8h · **Risk:** medium · `[PM]` · `blocker`
- **Blocked by:** — · **Blocks:** GRID-P5-02
- **Po co:** Sort po wartości atrybutu to jedyna realna decyzja architektoniczna epiku: Postgres `ORDER BY attributes_indexed->...` (bez indeksu = seq scan / sort spill przy 50k) vs. przeniesienie sortowanego browse na Meilisearch (dynamiczne sortable attributes = przebudowa settings + reindex). Zła decyzja tu = przepisywanie M5/M6. Benchmark rozstrzyga, ADR utrwala.
- **Stan obecny:** Sort tylko po polach core (`OrderById` + kolumny; cursor pagination `id DESC`). GIN `jsonb_path_ops` wspiera `@>`, NIE wspiera ORDER BY. Meilisearch: `sortable` hardcoded `[createdAt, updatedAt, name, price]` w `IndexSettingsTemplate`. Envelope per typ: `{value}` / `{option_code}` / `{amount,currency}` — path i cast różne per typ.
- **Zakres:**
  - Benchmark na seedzie 50k SKU × ~30 atrybutów: (a) `ORDER BY (attributes_indexed->'x'->>'value')::numeric NULLS LAST` + cursor, (b) z indeksem wyrażeniowym btree na 1–2 hot atrybutach, (c) Meilisearch sort na tym samym zbiorze. Mierzyć p50/p95 zimne/ciepłe; wynik do `Project Plan/` lub ADR załącznik.
  - ADR-0028 (`docs/adr/0028-attribute-sort-strategy.md`, template `adr-template.md`): rekomendacja wstępna = **Postgres JSONB path sort** z ograniczeniami MVP (typy proste: text/number/date/boolean/select po `option_code`/price po `amount`; atrybuty nielokalizowane i niescopowane; `NULLS LAST`; obowiązkowy tie-breaker `id` dla cursor pagination). Meili = eskalacja gdy p95 > 500ms; kryterium przejścia zapisane w ADR.
  - Rozstrzygnąć interakcję z cursor pagination (sort po niestabilnym kluczu wymaga keyset `(value, id)` albo świadomego przejścia na offset dla sortowanych zapytań — decyzja w ADR).
  - Wpis w `docs/adr/README.md`; powiązane ADR (0020 OpenAPI, ADR-009 ObjectType).
- **Poza zakresem:** Implementacja (P5-02). Zmiany settings Meilisearch (tylko jeśli ADR wybierze Meili — wtedy scope P5-02 się zmienia).
- **AC:**
  - [ ] ADR-0028 istnieje, status Accepted, z liczbami benchmarku (nie „szacujemy").
  - [ ] Decyzja jednoznaczna: silnik, dozwolone typy, strategia paginacji przy sorcie, kryterium eskalacji do Meili.
  - [ ] Numer 0028 nie koliduje (`ls docs/adr/`).
- **Smoke:** Benchmark odpalony na żywym stacku (docker), liczby w ADR; decyzje „Accepted", nie „Proposed".
- **Reuse:** seedy demo/benchmark z `apps/api` (istniejący seed 50k jeśli jest — sprawdzić `bin/console` przed pisaniem nowego) · `adr-template.md`
- **DoD:** standard (docs+benchmark — bez bramek kodowych poza skryptem benchmarku).

### GRID-P5-02: feat(catalog): add attribute value sorting on object list endpoint
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M5 · **Est:** 8-12h · **Risk:** high
- **Blocked by:** GRID-P5-01 · **Blocks:** GRID-P5-03, GRID-P6-02
- **Po co:** Backend dla klikalnych nagłówków: `?order[attribute.brand]=asc` na `/api/objects|/api/products`. Implementacja wg decyzji ADR-0028 (default: Postgres JSONB path + typed cast).
- **Stan obecny:** `AttributeFilter` (wzorzec custom filter na JSONB) + katalog atrybutów per tenant dostępny w BE (`AttributeReadRestrictionOverlay` go już batch-loaduje). Cursor pagination `id DESC`.
- **Zakres:**
  - `AttributeOrderFilter` (obok `AttributeFilter`): `?order[attribute.{code}]={asc|desc}` → resolve typu z katalogu atrybutów → path+cast per typ (`->>'value'` text/`::numeric`/`::date`/`->>'option_code'`/`->'amount'::numeric`) + `NULLS LAST` + tie-breaker `id` (strategia paginacji wg ADR).
  - Walidacja: atrybut nieistniejący / typ niesortowalny / localizable / scopable → 400 Problem Details (spójnie z flagą `sortable` z list-schema P3-01).
  - RBAC: sort po atrybucie `restricted` dla roli → 400/403 (nie może być kanałem wycieku istnienia wartości).
  - Jeden sort atrybutowy na raz (MVP); kombinacja z filtrami/`objectType`/search bez konfliktów (testy).
  - Indeks wyrażeniowy na hot-path atrybutach tylko jeśli ADR tak zdecydował (osobna migracja).
  - OpenAPI: parametr udokumentowany, snapshot zaktualizowany.
- **Poza zakresem:** Multi-sort, sort po localizable/scopable, sort w Meilisearch search path (search ma własny ranking; ewentualna unifikacja wg ADR).
- **AC:**
  - [ ] Sort asc/desc poprawny dla: text (collation), number (numerycznie, nie leksykalnie), date, select (option_code), price (amount); NULL-e na końcu.
  - [ ] Paginacja stabilna przy sorcie (brak duplikatów/dziur między stronami — test ApiTestCase przechodzący strony).
  - [ ] Niesortowalny/nieznany/restricted atrybut → 400/403 Problem Details.
  - [ ] p95 na seedzie benchmarkowym w granicach z ADR-0028 (test benchmark w CI suite `benchmark` jeśli tam żyją).
- **Smoke:** curl `?order[attribute.price]=desc` → malejąco po amount; strona 2 kontynuuje bez duplikatów.
- **Reuse:** `AttributeFilter` (wzorzec) · katalog atrybutów z `AttributeReadRestrictionOverlay` · `CatalogObject.xml` (rejestracja filtra)
- **DoD:** standard.

### GRID-P5-03: feat(admin): sortable attribute column headers
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 5-8h · **Risk:** low
- **Blocked by:** GRID-P5-02 · **Blocks:** —
- **Po co:** Domknięcie pętli: klik nagłówka sortuje listę (asc → desc → brak), wskaźnik kierunku, integracja ze stanem urla i saved views.
- **Stan obecny:** Nagłówki statyczne; sort po polach core niewyeksponowany w UI list; `sortable` per kolumna już w modelu (P1-01, z schema).
- **Zakres:**
  - Klikalne nagłówki (`sortable=true`): cykl asc/desc/off, ikona kierunku, aria-sort; kolumny `sortable=false` — bez affordance (tooltip „sortowanie niedostępne dla tego typu").
  - Stan sortu w URL (`?order[...]`) + w modelu grida (→ SavedView przez P4-02, jeśli M4 zmergowane; inaczej localStorage).
  - Sort działa w obu widokach (jeden stan); zmiana sortu resetuje na stronę 1.
  - Kolumny systemowe sortowalne po istniejących polach core (code, status, completeness_pct, updatedAt) — spięcie z istniejącym `order[]`.
- **Poza zakresem:** Multi-sort (shift+klik) — przyszłość.
- **AC:**
  - [ ] Klik nagłówka atrybutu → lista posortowana (dane z BE, nie sort client-side strony); wskaźnik + aria-sort poprawne.
  - [ ] Sort przeżywa reload (URL) i zapisuje się w saved view.
  - [ ] Playwright: sort po number-atrybucie → pierwsza strona rosnąco → desc → odwrócone; kolumna niesortowalna nie reaguje.
- **Smoke:** `/products` → klik „Cena" → sort po wartości; Network: `order[attribute.price]=asc`, 200.
- **Reuse:** model P1-01 · URL state z `universal-list-page.tsx`
- **DoD:** standard.

---

# M6 — Inline edit kolumn atrybutowych (Excel view)

### GRID-P6-01: feat(catalog): expose per-column editable flag in list-schema
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M6 · **Est:** 3-5h · **Risk:** low
- **Blocked by:** GRID-P3-01 · **Blocks:** GRID-P6-02
- **Po co:** FE musi wiedzieć per kolumna, czy user może edytować atrybut (3-state `edit` vs `view`), zanim wyrenderuje edytor — inaczej edycja odbije się dopiero 403-ką na PATCH.
- **Stan obecny:** `AttributePermissionReader.canEditAttribute()` istnieje (Phase 3 RBAC); list-schema zwraca tylko kolumny widoczne, bez rozróżnienia view/edit.
- **Zakres:**
  - Pole `editable: bool` per kolumna atrybutowa w list-schema (oba tryby, standard + `?full=1`): `canEditAttribute()` **oraz** typ edytowalny inline (text/textarea/number/date/boolean/single_select; media/relation/multiselect → `false` w MVP).
  - Kolumny systemowe: `editable` wg istniejących reguł (code/name jak dziś w Excel view).
  - ApiTestCase: rola z `view` (nie `edit`) na atrybucie → `editable=false`; admin → `true` dla typów prostych.
- **Poza zakresem:** Enforcement na PATCH (już istnieje w write path — potwierdzić testem, nie implementować).
- **AC:**
  - [ ] `editable` obecne per kolumna; poprawne dla ról admin/marketing (3-state) i per typ.
  - [ ] PATCH atrybutu z `editable=false` (rola view-only) → 403 (istniejący enforcement — test regresyjny).
  - [ ] OpenAPI snapshot zaktualizowany.
- **Smoke:** curl list-schema jako admin i jako restricted user → flagi się różnią zgodnie z rolą.
- **Reuse:** `GetObjectTypeListSchemaHandler` · `AttributePermissionReader`
- **DoD:** standard.

### GRID-P6-02: feat(admin): typed inline editors for attribute columns in Excel view
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M6 · **Est:** 12-16h · **Risk:** high
- **Blocked by:** GRID-P1-04, GRID-P5-02, GRID-P6-01 · **Blocks:** GRID-P6-03
- **Po co:** Plytix-owy wyróżnik: masowa praca na danych bez wchodzenia w detail. Excel view ma już mechanikę edycji (focus, commit, optymizm) — brakuje typed editors dla kolumn atrybutowych i spięcia z write path.
- **Stan obecny:** Inline edit tylko SKU/name (plain input); commit PATCH `/api/objects/{id}`; write path (`ObjectAttributesUpserter`, provenance, walidacje per typ z `validation_rules`) kompletny po stronie BE.
- **Zakres:**
  - Edytory per typ: text/textarea (input), number (numeric + walidacja), date (datepicker), boolean (toggle/checkbox), single_select (Combobox z opcjami — reuse komponentu z VariantsTab po lekcji #342). Kolumny `editable=false` → read-only jak dziś.
  - Commit: PATCH z envelope zgodnym z `docs/api/jsonb-schemas.md` (provenance `manual` nadaje BE); optymistyczna aktualizacja + rollback i toast na 4xx/5xx (błędy NIE połykane — lekcja UI-02 #339).
  - Walidacja client-side z `validation_rules` atrybutu przed wysyłką (szybki feedback), autorytatywna po stronie BE.
  - Keyboard: Enter=commit, Esc=cancel, Tab=commit+następna komórka (zachować istniejące zachowania).
  - Provenance badge/tooltip na komórce po edycji (reguła implementacyjna #5 — provenance widoczne przy polach).
- **Poza zakresem:** Edycja media/relation/multiselect inline (MVP: read-only). Edycja wartości localizable/scopable (wymaga kontekstu locale/channel — poza epikiiem). Bulk paste (P6-03). Drag-fill (przyszłość).
- **AC:**
  - [ ] Edycja każdego typu prostego zapisuje wartość (Network 200, wartość przeżywa reload); błąd walidacji BE → rollback + toast z komunikatem.
  - [ ] Komórki `editable=false` nie wchodzą w tryb edycji; wizualnie odróżnione.
  - [ ] Select edytor pokazuje labelki opcji (nie kody), wyszukiwarka opcji przy >10.
  - [ ] Playwright: edytuj number → reload → wartość jest; edytuj z niepoprawną wartością → rollback + toast; rola view-only nie może edytować.
- **Smoke:** `/products` Excel → edytuj cenę, datę, select → wartości zapisane (Network 200), Console czysta; wartość widoczna też w widoku grid i detail.
- **Reuse:** mechanika edycji `excel-like-grid.tsx` · Combobox (`ui-v2`) · `validation_rules` z atrybutu (schema) · write path BE bez zmian
- **DoD:** standard.

### GRID-P6-03: feat(admin): TSV paste onto attribute columns with type validation
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M6 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** GRID-P6-02 · **Blocks:** —
- **Po co:** Realny workflow arkuszowy: skopiuj blok z Excela → wklej w grid → wartości trafiają do właściwych atrybutów z walidacją typów. Copy/paste TSV już istnieje dla SKU/name — rozszerzenie na kolumny atrybutowe.
- **Stan obecny:** Ctrl/Cmd+V parsuje TSV i mapuje na kolumny edytowalne (SKU/name); kolumny atrybutowe read-only (przed M6) lub edytowalne pojedynczo (po P6-02).
- **Zakres:**
  - Paste mapuje komórki na kolumny atrybutowe wg pozycji; per komórka: parsowanie wg typu (number/date/boolean/select po labelu LUB kodzie opcji), niepasujące → odrzucone z zbiorczym raportem (toast „X zapisane, Y odrzucone: …").
  - Komórki `editable=false` w zakresie wklejania → pominięte (raport).
  - Commit batch: sekwencyjne PATCH-e per wiersz (MVP; bez nowego bulk endpointu) z progress feedbackiem przy >20 wierszach; rollback per komórka przy błędzie BE.
- **Poza zakresem:** Nowy bulk endpoint (istnieje `/api/objects/bulk` — użyć TYLKO jeśli wspiera wartości atrybutów per obiekt; inaczej sekwencyjnie), undo/redo, drag-fill.
- **AC:**
  - [ ] Wklejenie bloku 5×3 (number+select+text) zapisuje poprawne, odrzuca niepoprawne, raportuje zbiorczo.
  - [ ] Wartości select mapują się po labelu i po kodzie; nieznana opcja → odrzucona (bez tworzenia opcji).
  - [ ] Playwright: paste bloku → wartości w BE (reload) + toast raport.
- **Smoke:** Skopiuj 3 wiersze z arkusza → paste na kolumny cena/status/opis → raport, wartości po reloadzie.
- **Reuse:** parser TSV z `excel-like-grid.tsx` · edytory/walidacja P6-02
- **DoD:** standard.

---

# M7 — Eksport „tego co widzę"

### GRID-P7-01: feat(export): add ad-hoc grid export endpoint reusing export engine
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M7 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** — · **Blocks:** GRID-P7-02
- **Po co:** PIMcore-owy wzorzec „grid → export": to, co użytkownik widzi (kolumny+filtry+sort), wychodzi jako CSV/XLSX jednym klikiem — bez przeklikiwania wizarda eksportów. Silnik Export jest kompletny; brakuje wejścia „ad-hoc z konfiguracji grida".
- **Stan obecny:** `ExportBuilder` (iterable CatalogObject → wiersze), `ColumnResolver`/`ValueSerializer` (atrybut→kolumna, JSONB→string), async przez Messenger `import` transport, pliki na MinIO; eksporty startują z trwałych profili przez wizard. Filter DSL wspólny FE/BE (`FilterDslResolver`).
- **Zakres:**
  - Custom trasa `POST /api/objects/grid-export`: payload `{objectTypeId, columns: [keys], filter?: FilterDsl, order?: {key, dir}, format: csv|xlsx, locale?}` → walidacja (kolumny istnieją, RBAC per atrybut — restricted wycięte serwerowo, nie tylko klientowo) → async job na istniejącym silniku, **bez tworzenia trwałego ExportProfile** (sesja ad-hoc; jeśli silnik wymaga profilu — profil tymczasowy `is_adhoc` czyszczony po N dniach, decyzja w tickecie, default: bez profilu).
  - Wynik jak istniejące eksporty: job status + link do pobrania (istniejący mechanizm history/download); nagłówki kolumn = labelki w locale żądania.
  - Sort wg P5-02 (jeśli zmergowane; inaczej sort pominięty z notą w PR).
  - `#[RequiresPermission]` (moduł exports — istniejący), OpenAPI (`CustomRouteOpenApiFactory`), snapshot.
  - `AbstractBatchHandler`/`clear()` — reuse istniejącego handlera eksportu (memory rule dla 50k wierszy).
- **Poza zakresem:** PDF (to CPDF), harmonogramy, delivery na zewnątrz (feedy), XLSX styling.
- **AC:**
  - [ ] POST z konfiguracją grida → job → plik CSV z dokładnie tymi kolumnami/wierszami/kolejnością/sortem co grid.
  - [ ] Restricted attribute w `columns` → wycięty z pliku niezależnie od payloadu (ApiTestCase z rolą marketing).
  - [ ] Filter DSL z grida daje identyczny zbiór wierszy co lista (test porównawczy).
  - [ ] 50k wierszy eksportuje się bez OOM (batch + clear — test lub benchmark smoke).
- **Smoke:** curl POST grid-export (3 kolumny + filtr) → job → download CSV → kolumny/wiersze zgodne z listą na UI.
- **Reuse:** `ExportBuilder` · `ColumnResolver`/`ValueSerializer` · `FilterDslResolver` · istniejący async worker + storage + history
- **DoD:** standard.

### GRID-P7-02: feat(admin): export current view action in list toolbar
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M7 · **Est:** 5-8h · **Risk:** low
- **Blocked by:** GRID-P2-01, GRID-P7-01 · **Blocks:** —
- **Po co:** Domknięcie UX: przycisk „Eksportuj widok" nad gridem — zero konfiguracji, WYSIWYG.
- **Stan obecny:** Eksporty tylko przez wizard (`/exports`); toolbar listy ma miejsce na akcję.
- **Zakres:**
  - Akcja w toolbarze (dropdown: CSV / XLSX): zbiera bieżące kolumny (widoczne, kolejność), filtr DSL, sort, locale UI → POST grid-export → toast z progressem → link pobrania po zakończeniu (istniejący wzorzec śledzenia jobów — Mercure/polling jak w exports history).
  - Zakres wierszy: **wszystkie pasujące do filtra** (nie tylko bieżąca strona); jeśli jest selekcja — opcja „tylko zaznaczone (N)".
  - Disabled + tooltip gdy brak uprawnienia exports.
- **Poza zakresem:** Konfiguracja kolumn w momencie eksportu (od tego jest wizard), formaty poza CSV/XLSX.
- **AC:**
  - [ ] Plik zawiera dokładnie widoczne kolumny w kolejności z grida + aktywny filtr + sort.
  - [ ] Wariant „tylko zaznaczone" eksportuje selekcję (w tym cross-page dla produktów).
  - [ ] Playwright: skonfiguruj kolumny+filtr → eksport → job completes → plik ma oczekiwane nagłówki (parse CSV w teście).
- **Smoke:** `/products` z filtrem i custom kolumnami → „Eksportuj widok" → CSV otwiera się z tym samym co na ekranie.
- **Reuse:** model P1-01 · filter DSL state · wzorzec job-toast z exports/feeds (`useFeedRunsStream`/`ExportsLiveBridge`)
- **DoD:** standard.

---

# M8 — Performance + E2E + hardening

### GRID-P8-01: perf(admin): row virtualization for list views
- **Typ:** `perf` · **Cls:** FE+PERF · **Milestone:** M8 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** GRID-P1-03, GRID-P1-04 · **Blocks:** —
- **Po co:** 200 wierszy × 30+ kolumn × renderery (chipy, miniatury) = tysiące nodów DOM; bez wirtualizacji wierszy compact+200/page będzie mulić. Excel view wirtualizuje tylko kolumny.
- **Stan obecny:** @tanstack/react-virtual w deps (użyty horyzontalnie w Excel view); grid view bez żadnej wirtualizacji; page size do 200.
- **Zakres:**
  - Wirtualizacja pionowa w obu widokach (dwuosiowa w Excel — wiersze × istniejące kolumny); stabilna wysokość wiersza z density (P2-03) upraszcza measurement.
  - Zachować: variants tree expand (dynamiczne wstawianie wierszy), selekcję (checkbox poza viewportem = stan w modelu, nie w DOM), keyboard nav i zaznaczenie prostokątne Excel view, sticky kolumna (P2-02) współgra z wirtualizacją.
  - Budżet: p95 scroll frame < 16ms przy 200×30 compact (pomiar w PR — DevTools trace lub Playwright metrics).
- **Poza zakresem:** Infinite scroll (paginacja zostaje), zmiana page size limits.
- **AC:**
  - [ ] DOM zawiera tylko widoczne wiersze + overscan (assert w Playwright).
  - [ ] Zero regresji: selekcja cross-page, variants expand, edycja (M6), copy/paste, sticky identyfikator.
  - [ ] Pomiar płynności w PR body (przed/po).
- **Smoke:** 200/page × 30 kolumn compact → scroll płynny; wszystkie istniejące E2E listy zielone.
- **Reuse:** @tanstack/react-virtual (wzorzec z `excel-like-grid.tsx`)
- **DoD:** standard.

### GRID-P8-02: test(e2e): Playwright suite for grid epic
- **Typ:** `test` · **Cls:** E2E · **Milestone:** M8 · **Est:** 8-12h · **Risk:** low
- **Blocked by:** GRID-P2-01, GRID-P3-02, GRID-P4-02, GRID-P5-03, GRID-P6-02, GRID-P7-02 · **Blocks:** GRID-P8-03
- **Po co:** Tickety M1–M7 mają E2E per-feature; ten ticket dokłada scenariusze przekrojowe (user journey przez cały epik) + regresyjny baseline dla custom ObjectType — sieć bezpieczeństwa przed przyszłymi refactorami (unifikacja widoków).
- **Stan obecny:** E2E rozproszone per ticket; brak journey testu.
- **Zakres:**
  - Journey: login → `/products` → dodaj 2 custom kolumny → resize → sort po atrybucie → filtr → zapisz widok → reload → wybierz widok → edytuj komórkę w Excel → eksportuj CSV → assert nagłówków pliku.
  - To samo skrócone na custom ObjectType (`/objects/{slug}` z seedu) — parytet uniwersalności.
  - Scenariusz RBAC: user marketing (restricted attribute) nie widzi kolumny w managerze, nie może sortować po niej (URL manipulation → komunikat, nie crash), nie edytuje kolumn view-only.
  - Lokalne gotchas: `i18nextLng=pl` w addInitScript, role locators (lessons: `playwright-local-gotchas`).
- **Poza zakresem:** Testy wydajnościowe (P8-01 ma własny pomiar).
- **AC:**
  - [ ] Journey zielony na CI (products + custom OT); RBAC scenariusz zielony.
  - [ ] Suite w strukturze istniejących E2E (nazewnictwo, fixtures, apiLogin).
- **Smoke:** Pełny suite lokalnie na `pim.localhost` przed PR.
- **Reuse:** istniejące fixtures/apiLogin Playwright · seedy demo
- **DoD:** standard (test-only: bez bramek BE).

### GRID-P8-03: chore(grid): epic hardening, docs and closeout
- **Typ:** `chore` · **Cls:** FE+BE+DOCS · **Milestone:** M8 · **Est:** 3-5h · **Risk:** low
- **Blocked by:** GRID-P8-02 · **Blocks:** —
- **Po co:** Domknięcie epiku zgodnie z konstytucją: audyty przekrojowe, dokumentacja, status.
- **Zakres:**
  - axe-core przekrojowo na listach (manager, edytory, sort headers) — 0 serious/critical; poprawki drobne inline.
  - Audyt i18n: wszystkie klucze `grid.*` w `pl.json` + `en.json`, bez literałów.
  - `docs/api/jsonb-schemas.md` + OpenAPI snapshot — finalna spójność (list-schema `full`/`editable`, SavedView.config, grid-export).
  - Screencast 5 min (finał sub-fazy per konstytucja) + wpis w `agent/current_status.md` i lessons epiku w `agent/lessons.md`.
  - Przegląd hooków [DEF] (P3-03 sparse fieldsets): decyzja otworzyć/odłożyć na bazie pomiarów P8-01.
  - Manual smoke pełnego flow na `pim.localhost` z proof (HTTP codes) w issue close comments — CLOSED MEANS CLOSED.
- **AC:**
  - [ ] axe 0 serious/critical; i18n bez literałów; snapshoty aktualne.
  - [ ] `current_status.md` + `lessons.md` zaktualizowane; screencast nagrany.
  - [ ] Wszystkie issues epiku zamknięte z proof-em smoke.
- **Smoke:** = zakres ticketu.
- **DoD:** standard.

---

## Zależności i kolejność realizacji

```
M1: P1-01 → P1-02 → P1-03 + P1-04          (fundament FE, sekwencyjnie)
M2: P2-01 → P2-02 + P2-03                   (po M1)
M3: P3-01 (BE, równolegle z M2) → P3-02     (po P2-01)
M4: P4-01 (BE, równolegle) → P4-02 (po M2) + P4-03
M5: P5-01 (ADR+benchmark, równolegle od startu) → P5-02 → P5-03
M6: P6-01 → P6-02 (po P1-04 + P5-02) → P6-03
M7: P7-01 (BE, równolegle) → P7-02 (po P2-01)
M8: P8-01 (po M1) · P8-02 (po M2–M7) → P8-03
```

Ścieżka krytyczna: **P1-01 → P1-02 → P1-04 → P6-02** oraz **P5-01 → P5-02 → P6-02**. Tickety BE (P3-01, P4-01, P5-01/02, P7-01) mogą iść równolegle do frontu M1/M2.

## Otwarte kwestie (rozstrzygane w ticketach)

1. **Unifikacja grid/Excel view** — poza epikiem; P8-02 daje regresyjny baseline pod przyszły refactor. (Decyzja z planu: zostawić dwa komponenty.)
2. **Sort: Postgres vs Meilisearch** — ADR-0028 + benchmark (P5-01).
3. **Sparse fieldsets** — hook [DEF] P3-03, decyzja w P8-03 na bazie pomiarów.
4. **Widoki współdzielone per zespół/rola** — follow-up po M4 (wymaga RBAC na SavedView; dziś wszystkie widoki tenanta widoczne dla wszystkich — świadome MVP).
5. **Kontekst locale/channel nad gridem** — poza epikiem decyzją operatora; renderery P1-02 mają zdefiniowany fallback, endpoint `?locale/?channel` gotowy.
