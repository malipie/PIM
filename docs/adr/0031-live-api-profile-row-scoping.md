# 0031. Live API — ApiProfile stosuje `filters` + `objectTypeIds` jako selekcję wierszy

- **Status:** accepted
- **Date:** 2026-07-12
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> Zaakceptowany w tickecie #2527 (kontynuacja #2526 — status w filtrze zaawansowanym). Rozszerza kontrakt konsument/producent z ADR-0022 (API Configurator) i ADR-009 (parametryzacja profilu na `objectTypeIds`). Numer: `ls docs/adr/` → max `0030`, następny wolny **0031**.

## Context and Problem Statement

`ApiProfile` (ADR-0022, epik APIC) definiuje, co partner-integrator widzi przez live API: `includedAttributes` (allow-lista pól), `objectTypeIds` (zakres typów) i `filters` (kanoniczny dict parametrów zapytania, np. `{"status":"published","completeness":{"gte":80}}`).

**Problem:** z tych trzech tylko `includedAttributes` był stosowany — `ApiProfileSerializerContextBuilder` (#94) prunuje **pola** przy serializacji. `filters` i `objectTypeIds` były **przechowywane, ale nigdy niestosowane** do selekcji wierszy: trafiały wyłącznie do metadanych OpenAPI (`x-pim-filters`). Efekt: integrator z profilem `status = published` i tak dostawał drafty/archived. To sprzeczne z intuicją operatora („profil = to, co widzi integrator") i niebezpieczne (wyciek treści roboczej na zewnątrz).

Powiązane: #2526 dorejestrowało `status` do słownika filtra zaawansowanego (kolumna `co.status`), obejmując listy/eksporty/feedy/katalogi PDF — ale **nie** live-API integratora. Ten ADR domyka live-API.

## Decision Drivers

- **Reuse, nie re-implementacja.** `filters` mają kształt *query-param* — dokładnie ten, który konsumują istniejące `FilterInterface` (StatusFilter, CompletenessFilter, AttributeFilter, ObjectTypeFilter). Klucze ≠ zawsze atrybuty (`completeness` → kolumna `completeness_pct`); tę wiedzę już mają te filtry.
- **Deptrac.** `ApiConfigurator` ma dostęp tylko do `Shared` + `*_Contracts` (deptrac.yaml) — NIE do `Catalog_Application`/`_Internals`. Rozwiązanie nie może importować `FilterDslResolver` ani encji Catalog z ApiConfigurator.
- **Wydajność.** Live-API jest paginowane i gorące; unikać materializacji pełnej listy ID (`id IN (…50k…)`).
- **Backward-compat.** Ścieżka admina (JWT, brak profilu) i profil bez filtrów muszą zwracać pełne kolekcje bez regresji.

## Considered Options

1. **`QueryCollectionExtension` czytający profil z `context`** (propozycja z pierwotnego szkicu #2527). **Odrzucone:** `context` ekstensji query-time NIE zawiera kluczy `api_profile_*` — te ustawia `SerializerContextBuilder` przy *normalizacji* (po zapytaniu). Ekstensja by ich nie zobaczyła.
2. **Contracts port + konwersja query-param → FilterDsl → `id IN`.** Działa i jest deptrac-clean, ale: (a) materializuje ID (wydajność), (b) wymaga własnej konwersji query-param→attr, dublując wiedzę filtrów (`completeness`→`completeness_pct`).
3. **Request-listener scalający `filters` w query + dedykowane ekstensje Catalog czytające query (WYBRANE).** Listener w `ApiConfigurator` (kernel.request, po firewallu) wstrzykuje `profile->getFilters()` + `objectTypeIds` do `$request->query` i oznacza request atrybutem-markerem. Ekstensje Catalog czytają **live query** i stosują zawężenie jako DQL.

   **Uwaga empiryczna (weryfikacja ApiTest, #2530 run 1):** pierwotnie zakładaliśmy, że wystarczy scalić do query, a **istniejący** łańcuch `FilterInterface` (StatusFilter…) je podchwyci z `$context['filters']`. Test pokazał inaczej: `$context['filters']` jest **snapshotowany zanim** listener zadziała (item-GET czytający `RequestStack` widział scalony `status`, ale kolekcyjny StatusFilter nie). Stąd `filters`-context reuse **nie działa** — trzeba czytać live query przez `RequestStack` (jak `AssetCollectionFilterExtension`).

## Decision

**Wariant 3, pod-wariant „dedykowany applier czytający `RequestStack`".** Cztery elementy, komunikacja wyłącznie przez query-params + marker (zero zależności cross-BC):

1. **`ApiConfigurator\…\ApiProfileFilterRequestListener`** (`kernel.request`, priorytet 6 — po firewallu 8): resolvuje profil (`ApiProfileResolver`), ustawia atrybut requestu `_pim_profile_scoped=true` (marker, string — bez typu Catalog), scala `getFilters()` (**profil wygrywa** — nadpisuje param integratora; hard scope) + `objectTypeIds` do `$request->query`. No-op bez profilu (ścieżka admina). Czysta mutacja requestu — **brak importu z Catalog**.
2. **`Catalog\…\State\ProfileScopeApplier`** (współdzielony): gdy marker ustawiony, czyta live query (`status`, `enabled`, `completeness[op]`, `objectTypeIds`) i stosuje kanoniczne, kolumnowe zawężenia jako DQL (`o.status`, `o.enabled`, `o.completenessPct`, `IDENTITY(o.objectType) IN`).
3. **`ProfileScopeCollectionExtension`** (`QueryCollectionExtensionInterface`) — kolekcja przez applier.
4. **`ProfileScopeItemExtension`** (`QueryItemExtensionInterface`) — item przez ten sam applier → obiekt spoza zakresu **404** (jak `KindItemExtension`). Bez tego integrator obszedłby scope przez `GET /api/products/{draftId}`.

Marker gwarantuje, że scoping dotyczy tylko requestów profilu (API-key) — admin/JWT nietknięty. Kolekcja i item stosują **identyczny** scope (jeden applier). Zakres v1 = **kanoniczne pola kolumnowe** (status/enabled/completeness/objectTypeIds); filtry po wartości atrybutu (JSONB) w profilu = świadome odejście (follow-up) — zarówno na kolekcji, jak i item.

## Consequences

- **Pozytywne:** zero nowego portu, zero `skip_violations`, deptrac 0. DQL `andWhere` (bez materializacji ID) — wydajne. Kolekcja + item = jeden applier (spójność). Marker izoluje scoping do requestów profilu → admin/JWT bez regresji; profil bez filtrów = pełna kolekcja.
- **Negatywne / ryzyka:** (a) listener mutuje `$request->query` + ustawia marker — wzorzec „magiczny", udokumentowany tu i w klasach; (b) applier duplikuje minimalną logikę istniejących filtrów (status/enabled/completeness) — bo `$context['filters']` snapshot nie widzi scalonych paramów; świadomy koszt reużycia wykluczony empirycznie; (c) scope v1 = kanoniczne pola kolumnowe (status/enabled/completeness/objectTypeIds) na kolekcji **i** item; filtry po wartości atrybutu (JSONB) w profilu = follow-up; (d) nieobsługiwany klucz filtra profilu jest cicho ignorowany — walidacja kształtu profilu = przyszły guard.
- **Zależności:** #2526 (status jako kolumna w słowniku filtra) poprzedza koncepcyjnie; tu applier stosuje `o.status` bezpośrednio jako DQL.
- **Test:** `ApiProfileFilterScopeApiTest` — profil `status=published` przez X-API-Key → kolekcja tylko published; draft item → 404; profil bez filtrów → pełna kolekcja.
