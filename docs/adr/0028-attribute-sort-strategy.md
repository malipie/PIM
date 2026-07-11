# 0028. Attribute-value sorting on object lists via Postgres JSONB path expressions

- **Status:** accepted
- **Date:** 2026-07-11
- **Deciders:** Marcin (operator) + agent, per GRID-P5-01 (#2397)

## Context and Problem Statement

Epik GRID dodaje sortowanie list obiektów po wartościach atrybutów (klik nagłówka kolumny — parytet Akeneo/Plytix/PIMcore). Wartości żyją w `objects.attributes_indexed` (JSONB, envelope per typ: `{value}` / `{option_code}` / `{amount,currency}`); istniejący indeks GIN (`jsonb_path_ops`) wspiera filtrowanie `@>`, ale **nie wspiera ORDER BY**. Browse jedzie z Postgresa (cursor pagination `id DESC`, RBAC overlay), search z Meilisearch (sortable hardcoded: `createdAt/updatedAt/name/price`). Zła decyzja tutaj wymusiłaby przepisanie M5 (sort) i M6 (inline edit na sortowanej liście).

## Decision Drivers

- Budżet: p95 < 500 ms na skali MVP (50k SKU × 200+ atrybutów); architektura ma unieść 200k+ bez przepisywania.
- Cursor pagination po `id` nie przeżywa sortu po niestabilnym kluczu — trzeba rozstrzygnąć strategię paginacji sortowanych zapytań.
- Meilisearch wymaga *dynamicznych* `sortableAttributes` (dziś hardcoded) + pełnego reindeksu przy każdej zmianie konfiguracji atrybutów; browse (RBAC overlay per wiersz, locale/channel overlay) i tak wraca do Postgresa po hydrację.
- Sortowalność musi być spójna z flagą `sortable` w list-schema (typy proste, nielokalizowane, niescopowane).

## Considered Options

1. **Postgres JSONB path sort** — `ORDER BY (attributes_indexed->'{code}'->>'{field}')::cast NULLS LAST, id DESC`, cast per typ atrybutu; bez dodatkowych indeksów w MVP.
2. **Postgres + indeksy wyrażeniowe btree** na hot-path atrybutach.
3. **Meilisearch** — przeniesienie sortowanego browse na search path z dynamicznymi `sortableAttributes`.

## Decision Outcome

Chosen option: **Opcja 1 — Postgres JSONB path sort bez indeksów wyrażeniowych**, bo benchmark na 50k × 30 atrybutów daje **p95 = 12–25 ms** (20–40× zapasu do budżetu 500 ms), zero nowej infrastruktury i zero sprzężenia z reindeksem Meili.

Benchmark (2026-07-11, kontener postgres:16, 50k wierszy, ~161 MB, 21 przebiegów/zapytanie — pełna metodologia i repro: [`docs/perf/grid-attribute-sort-benchmark.md`](../perf/grid-attribute-sort-benchmark.md)):

| Zapytanie (LIMIT 50) | bez indeksu p50/p95 [ms] | z btree wyrażeniowym p50/p95 [ms] |
|---|---|---|
| number asc (strona 1) | 11.9 / 13.8 | **0.2 / 0.3** |
| number asc (OFFSET 5000) | 17.7 / 19.4 | 6.5 / 9.9 |
| text asc | 12.4 / 18.8 | — |
| select (option_code) | 11.0 / 12.8 | — |
| price desc (amount) | 10.7 / 11.9 | — |
| date desc | 14.7 / 24.9 | — |

Meilisearch (te same 50k, flatten, sortable): sort p95 = 1.1–5.5 ms — szybszy w izolacji, ale koszt operacyjny (dynamiczne settings + reindex przy każdej zmianie atrybutów, rozjazd z RBAC/locale overlay browse) nieuzasadniony przy 25 ms w Postgresie.

### Reguły implementacyjne (wiążące dla GRID-P5-02)

1. **Sortowalne = atrybuty typów prostych** (`text`, `textarea`→text, `number`, `metric`, `date`, `datetime`, `boolean`, `select` po `option_code`, `price` po `amount`), **nielokalizowane i niescopowane**. Flaga `sortable` w list-schema (GRID-P3-01) jest źródłem prawdy; endpoint listy waliduje ją niezależnie (400 dla niesortowalnych, 400/403 dla `restricted` — sort nie może wyciekać istnienia wartości).
2. **`NULLS LAST` zawsze**; deterministyczny tie-breaker **`id DESC`** w każdym sortowanym zapytaniu.
3. **Paginacja sortowanych zapytań = LIMIT/OFFSET** (nie cursor): cursor po `id` nie koduje pozycji w porządku atrybutowym, a keyset po `(wyrażenie, id)` komplikuje kontrakt API nieproporcjonalnie do zysku — OFFSET 5000 kosztuje 17.7 ms na 50k. Zapytania bez sortu atrybutowego zostają na cursorze (bez zmian).
4. **Jeden sort atrybutowy na raz** (MVP; multi-sort = follow-up).
5. **Bez indeksów wyrażeniowych w MVP.** Ścieżka eskalacji udokumentowana niżej — nie budować na zapas.

### Kryteria eskalacji (telemetria decyduje, nie przeczucie)

- **p95 sortowanego browse > 500 ms** (Prometheus, per endpoint) na realnym tenancie → krok 1: indeksy wyrażeniowe btree na 1–3 najczęściej sortowanych atrybutach (benchmark: 60×+ przyspieszenie, koszt = utrzymanie indeksu przy write'ach); krok 2 (>500k SKU lub >10 hot atrybutów): migracja sortowanego browse na Meilisearch z dynamicznymi `sortableAttributes` (settings przebudowywane przy zmianie atrybutu, jak dziś `filterable`).
- Decyzję o kroku 2 podejmuje osobny ADR — ten dokumentuje tylko trigger.

### Consequences

- **Positive:** zero nowej infrastruktury; sort działa dla każdego atrybutu prostego od dnia 1 (bez per-atrybut provisioning); spójność z RBAC/locale overlay (jedna ścieżka Postgres); OFFSET upraszcza FE (paginacja numeryczna już istnieje w UI).
- **Negative:** seq scan rośnie liniowo z liczbą SKU (na 200k ekstrapolacja p95 ≈ 50–100 ms — nadal w budżecie); sortowane zapytania tracą stabilność cursora przy równoległych write'ach (akceptowalne dla przeglądania; strona może „drgnąć" — standard w Akeneo/PIMcore); localizable/scopable niesortowalne do czasu kontekstu locale/channel nad gridem (poza epikiem GRID decyzją operatora).

## Powiązane ADR

- [0009 — ObjectType jako koncept pierwszej klasy](../../Project%20Plan/01-architektura-pim.md) (hybrydowy model atrybutów, `attributes_indexed`)
- [0019 — silnik importu v2](0019-import-engine-v2.md) (kanoniczne envelope JSONB)
- [0020 — OpenAPI custom routes](0020-openapi-custom-route-documentation.md) (parametr `order[attribute.*]` musi trafić do snapshotu)
