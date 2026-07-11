# Benchmark: sort po wartościach atrybutów (JSONB) — GRID-P5-01 (#2397)

> Wynik wspiera [ADR-0028](../adr/0028-attribute-sort-strategy.md). Wykonany 2026-07-11 w izolowanych kontenerach (żadnego kontaktu z dev-stackiem).

## Metodologia

- **Postgres:** `postgres:16` (tmpfs data dir), tabela `objects (id uuid PK, tenant_id uuid, code text, attributes_indexed jsonb)` + GIN `jsonb_path_ops` (parytet z produkcyjnym schematem).
- **Dane:** 50 000 wierszy × ~30 kluczy atrybutowych w kanonicznych envelope'ach ADR-0019 (`{value}`, `{option_code}`, `{amount,currency}`); typy: text/number/date/boolean/select/price + 24 fillery tekstowe; ~8% braków w `weight` (test `NULLS LAST`). Rozmiar relacji: **161 MB**.
- **Pomiar:** `EXPLAIN (ANALYZE, TIMING OFF, FORMAT JSON)` → `Execution Time`; 21 przebiegów na zapytanie (pierwszy = zimny plan), raportowane p50/p95/min/max [ms]. Każde zapytanie z filtrem `tenant_id`, `NULLS LAST` i tie-breakerem `id DESC`, `LIMIT 50`.
- **Meilisearch:** `getmeili/meilisearch:latest`, te same 50k po flatten (jak `DocumentFlattener`), `sortableAttributes: [weight,name,price]`; 50 przebiegów `POST /search` z sortem, mierzony wall-clock klienta (zawiera HTTP).

## Wyniki — Postgres, faza A (bez indeksów wyrażeniowych)

| Zapytanie | p50 | p95 | min | max |
|---|---|---|---|---|
| number asc, strona 1 | 11.9 | 13.8 | 10.9 | 51.2 |
| number asc, OFFSET 5000 | 17.7 | 19.4 | 17.0 | 20.4 |
| text asc, strona 1 | 12.4 | 18.8 | 10.5 | 21.7 |
| select (option_code) asc | 11.0 | 12.8 | 9.9 | 19.6 |
| price (amount) desc | 10.7 | 11.9 | 10.3 | 21.9 |
| date desc | 14.7 | 24.9 | 10.3 | 35.2 |

## Wyniki — Postgres, faza B (btree wyrażeniowy na `weight`)

`CREATE INDEX ... ((( attributes_indexed->'weight'->>'value')::numeric) ASC NULLS LAST, id DESC)`

| Zapytanie | p50 | p95 |
|---|---|---|
| number asc, strona 1 | **0.2** | **0.3** |
| number asc, OFFSET 5000 | 6.5 | 9.9 |
| pozostałe (bez indeksu) | 10.3–11.8 | 13.0–15.8 |

## Wyniki — Meilisearch

Indeksacja 50k: ~2 s wall. Sort (limit 50, filtr tenanta): `weight:asc` p50 1.7 / p95 5.5 · `name:asc` 1.1 / 1.6 · `price:desc` 0.9 / 1.1 [ms].

## Wnioski (szczegóły i reguły → ADR-0028)

1. **Bez żadnego indeksu Postgres mieści się 20–40× poniżej budżetu 500 ms** na skali MVP — JSONB path sort wystarcza; ekstrapolacja liniowa na 200k SKU ≈ 50–100 ms p95, nadal w budżecie.
2. Indeks wyrażeniowy daje 60×+ na stronie 1 — trzymany jako **ścieżka eskalacji**, nie default (koszt utrzymania przy write'ach × liczba sortowalnych atrybutów).
3. Meilisearch jest najszybszy w izolacji, ale wymaga dynamicznych `sortableAttributes` + reindeksu przy zmianach konfiguracji i nie zdejmuje potrzeby hydracji/RBAC po stronie Postgresa — nieuzasadniony przy wynikach fazy A.
4. OFFSET 5000 (strona 100) kosztuje 17.7 ms — LIMIT/OFFSET dla sortowanych zapytań jest bezpiecznym uproszczeniem względem keyset po `(wyrażenie, id)`.

## Repro

```bash
docker run -d --name grid-bench-pg -e POSTGRES_PASSWORD=bench -p 5433:5432 \
  --tmpfs /var/lib/postgresql/data postgres:16
# seed: patrz blok SQL niżej; pomiar: pętla EXPLAIN (ANALYZE, TIMING OFF, FORMAT JSON)
```

```sql
CREATE TABLE objects (id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL, code text NOT NULL,
  attributes_indexed jsonb NOT NULL DEFAULT '{}');
INSERT INTO objects (tenant_id, code, attributes_indexed)
SELECT '11111111-1111-1111-1111-111111111111'::uuid, 'SKU-'||i,
  jsonb_build_object(
    'name',  jsonb_build_object('value','Product '||md5(i::text)),
    'brand', jsonb_build_object('option_code','brand_'||(i%50)),
    'weight',CASE WHEN i%12=0 THEN NULL
             ELSE jsonb_build_object('value', round((random()*100)::numeric,3)) END,
    'price', jsonb_build_object('amount', round((random()*1000)::numeric,2),'currency','PLN'),
    'release_date', jsonb_build_object('value',(date '2020-01-01'+(i%2000))::text),
    'active', jsonb_build_object('value',(i%3=0)))
  || (SELECT jsonb_object_agg('attr_'||k, jsonb_build_object('value', md5((i*31+k)::text)))
        FROM generate_series(1,24) k)
FROM generate_series(1,50000) i;
CREATE INDEX objects_ai_gin ON objects USING gin (attributes_indexed jsonb_path_ops);
VACUUM ANALYZE objects;
-- wzorzec zapytania:
SELECT id FROM objects WHERE tenant_id='11111111-1111-1111-1111-111111111111'
ORDER BY ((attributes_indexed->'weight'->>'value')::numeric) ASC NULLS LAST, id DESC LIMIT 50;
```
