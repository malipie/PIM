# Audyt struktury vs deklaracja + inwentaryzacja długu — raport (#2120, epik GOLIVE)

**Data:** 2026-07-04 · **Blok:** A (plan `Project Plan/15-plan-testow-przedprodukcyjnych.md`, sekcja A1)
**Cel:** ocena gotowości do przejęcia — zgodność kodu z deklarowaną architekturą + inwentaryzacja długu.

## 1. Bounded Contexts: kod vs deklaracja

CLAUDE.md §„Reguły implementacyjne" pkt 1 deklaruje 7 kontekstów: `Catalog, Channel, Asset, Integration, Identity, Agent, ApiConfigurator`.

Faktyczne katalogi `apps/api/src/`: **wszystkie 7 obecne** ✅ + dodatkowe konteksty niezdeklarowane w CLAUDE.md: `Backup`, `Export` (XMLF/feedy, ADR-0023), `Import` (IMP2, ADR-0019), `Search` (Meili, epik 0.5), `Shared` (kernel cross-BC), oraz tooling: `Benchmark`, `DataFixtures`, `Story`, `PHPStan`.

- **Deptrac (`deptrac.yaml`) pokrywa całą faktyczną strukturę** — nazwy warstw zgodne, granice egzekwowane w CI. To bounded-context integrity jest pilnowana maszynowo; drift jest **wyłącznie dokumentacyjny** (CLAUDE.md lista BC nie nadążyła za Export/Import/Search/Backup dodanymi po core MVP).
- **Rekomendacja:** rozszerzyć CLAUDE.md pkt 1 o Export/Import/Search/Backup (+Shared jako kernel) z odnośnikami do ADR. → ticket **[#2191](../../../issues/2191)**.

**Werdykt:** brak systemowej naruszki architektury; Deptrac = źródło prawdy, dokumentacja do odświeżenia.

## 2. Dead code (deprecated `families`/`family_attributes`/`products`/`product_values`)

- **0 aktywnych referencji** w `apps/api/src` i `apps/admin/src` (grep bez migracji/komentarzy = pusty).
- **Brak encji `Product.php`** — `CatalogObject` (kind='product') w pełni ją zastąpił (ADR-009).
- Jedyne pozostałości: (a) pliki migracji (trwały, niezmienialny zapis historii), (b) docstringi wyjaśniające ADR-009, (c) permission-code stringi RBAC (`products.view` — funkcjonalne nazwy, nie tabele).

**Rekomendacja: brak akcji.** Migracja domeny kompletna; artefakty migracyjne zostają jako zapis historii. ✅

## 3. TODO / FIXME / HACK

- **`apps/api/src`: 4 markery. `apps/admin/src`: 0.** Bardzo czysto.

| Plik | Marker | Klasyfikacja |
|---|---|---|
| `Catalog/…/AssetLinkSubscriber.php:27` | `TODO(RF-19/epic-0.5)` | deferred do epiku 0.5 (scoped) |
| `Export/…/CleanupExportsCommand.php:30` | `TODO` | deferred do scheduled-jobs infra |
| `Agent/…/CreateAttributesFromSchemaTool.php:33` | `TODO` w docstring | benign (sugestia lokalizacji Contracts) |
| `Import/…/ValidateDryRunController.php:176` | `XXX` w komentarzu | benign (wzorzec temp-file PHP, nie dług) |

**Blockerów: 0. Actionable: 2** (oba świadomie deferred do przyszłych epików). Rekomendacja: zostawić — dobrze oznaczone i scoped.

## 4. Rozmiar plików (guard max-lines)

**ADR-0021** dotyczy wzorca data-fetchingu FE, ale limit rozmiaru egzekwuje `scripts/lint-admin-max-lines.sh` (próg **500 linii**, baseline zamrożony, nie-rosnący).

- **Frontend:** 21 plików >500 linii, wszystkie w zamrożonym baseline (guard blokuje wzrost). Największe: `attributes/show.tsx` 1178, `universal-list-page.tsx` 1137, `object-types/show.tsx` 1013.
- **Backend: 10 plików >500 linii, BRAK guarda** (PHP tylko PHPStan/Deptrac/CS). Największe: **`Import/…/ImportRunHandler.php` 1913** (2× kolejny), `FilterDslResolver.php` 789, `BulkActionsController.php` 650, `ImportSession.php` 623, `StartImportController.php` 592.
- **Drift:** FE ma egzekucję CI, BE nie. **Rekomendacja:** dodać PHP max-lines guard analogiczny do FE (próg ~800, baseline z obecnych 10, cel zejść do 5; priorytet `ImportRunHandler`). → ticket **[#2192](../../../issues/2192)**.

## 5. ADR

- **17 plików ADR** w `docs/adr/`: `0000, 0010–0025` (ciągłe 0010–0025). Numery 0001–0009 to **legacy inline** w `01-architektura-pim.md §13` (dwuseriowa numeracja — 4-cyfrowe pliki vs 3-cyfrowe inline, znany quirk).
- Modern range (0010–0025) kompletny, bez brakujących plików. Wszystkie aktywnie referowane (Deptrac 0013, IMP2 0019, OpenAPI 0020, agent 0024, cross-field 0025).
- Drobne: ADR-0025 nieujęty w streszczeniu §13 doc architektury (dodany po zapisie). → uwzględnione w #2193.

## 6. Świeżość `01-architektura-pim.md`

- **Model (ObjectType/Attribute/Channel/Asset, DDD BC): świeży** — zgodny z kodem.
- **Roadmapa: nieaktualna** — dokument (v1.0, 2026-04-26, „frozen snapshot") wciąż lokuje integracje BaseLinker/Shopify i agenta (epik 0.7) w MVP, podczas gdy `06-sprint-0-findings.md` (2026-05-01) + ADR-013 (2026-05-18) przesunęły je do Fazy 1/2. RLS aktywacja przeniesiona do MVP-Alpha.
- **Rekomendacja:** przypis w §1 / wpis CHANGELOG odsyłający do `06-sprint-0-findings.md` + CLAUDE.md §„Priorytety" jako aktualnego źródła faz. → ticket **[#2193](../../../issues/2193)**.

## Werdykt handover (struktura + dług)

✅ **PASS.** Drift jest opóźnieniem dokumentacji, nie naruszeniem architektury: Deptrac egzekwuje granice BC, deprecated encje w pełni usunięte, dług minimalny i scoped. Trzy tickety odświeżające dokumentację/guardy (#2191/#2192/#2193) — żaden nie jest blokerem go-live.

## Mapa ticketów

[#2191](../../../issues/2191) CLAUDE.md lista BC · [#2192](../../../issues/2192) PHP max-lines guard · [#2193](../../../issues/2193) roadmapa arch doc + ADR-0025 w §13
