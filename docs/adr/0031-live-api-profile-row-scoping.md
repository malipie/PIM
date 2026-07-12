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
3. **Request-listener scalający `filters` w query zapytania (WYBRANE).** Listener w `ApiConfigurator` (kernel.request, po firewallu) wstrzykuje `profile->getFilters()` + `objectTypeIds` do `$request->query` **zanim** API Platform zbuduje `$context['filters']`. Istniejący łańcuch `FilterInterface` stosuje je jako właściwe DQL `andWhere` (bez materializacji ID, z pełną wiedzą o mapowaniu kluczy). Item-GET (który nie uruchamia filtrów kolekcji) obsługuje osobny `QueryItemExtension`.

## Decision

**Wariant 3.** Trzy elementy, komunikacja wyłącznie przez query-params (zero zależności cross-BC):

1. **`ApiConfigurator\…\ApiProfileFilterRequestListener`** (`kernel.request`, priorytet 6 — po firewallu 8): resolvuje profil (`ApiProfileResolver`), scala `getFilters()` (**profil wygrywa** — nadpisuje param integratora; hard scope, nie da się poszerzyć przez `?status=draft`) + `objectTypeIds` (jako `?objectTypeIds[]`) do `$request->query`. No-op bez profilu (ścieżka admina). Czysta mutacja requestu — **brak importu z Catalog**.
2. **`Catalog\…\Filter\ObjectTypeIdsFilter`** (`FilterInterface`): `?objectTypeIds[]={uuid}` → `IDENTITY(o.objectType) IN`. Liczba mnoga istniejącego `ObjectTypeFilter`.
3. **`Catalog\…\State\ProfileScopeItemExtension`** (`QueryItemExtensionInterface`): czyta `status` + `objectTypeIds` z query (wstrzyknięte przez listener) i stosuje to samo zawężenie na zapytaniu item → obiekt spoza zakresu **404** (jak `KindItemExtension`). Bez tego integrator obszedłby scope przez `GET /api/products/{draftId}`.

Kolekcje: pełne, ogólne stosowanie dowolnego filtra, który live-API już wspiera (status, completeness, enabled, atrybuty, objectType[s]). Item: kanoniczne scope'y bezpieczeństwa (`status`, `objectTypeIds`) — reszta (completeness/atrybuty na pojedynczym item) to świadome odejście (follow-up), bo item-GET wymaga integrator znał ID out-of-scope.

## Consequences

- **Pozytywne:** zero nowego portu, zero `skip_violations`, deptrac 0. Reuse całego łańcucha filtrów (spójność „filtr profilu == query param"). DQL `andWhere` (bez materializacji ID) — wydajne. Backward-compat: no-op bez profilu / bez filtrów.
- **Negatywne / ryzyka:** (a) listener mutuje `$request->query` — wzorzec „magiczny", udokumentowany tu i w klasie; (b) klucz filtra w profilu nieobsługiwany przez żaden `FilterInterface` jest cicho ignorowany (jak nieznany query-param) — walidacja kształtu profilu = przyszły guard; (c) item-scoping v1 pokrywa `status`+`objectTypeIds`, nie completeness/atrybuty.
- **Zależności:** #2526 (status jako pole DSL/filtr) poprzedza — profil `status=…` opiera się na działającym `StatusFilter` (istniał od #43; #2526 dodał go do słownika filtra zaawansowanego, tu wystarcza istniejący `StatusFilter`).
- **Test:** `ApiProfileFilterScopeApiTest` — profil `status=published` przez X-API-Key → kolekcja tylko published; draft item → 404; profil bez filtrów → pełna kolekcja.
