# GOLIVE #2128 — sesja load 50k SKU + endurance

> Jednorazowa sesja pomiarowa (Blok B, plan `Project Plan/15-plan-testow-przedprodukcyjnych.md` §4).
> Data: 2026-07-04. Środowisko: **lokalne** (Docker Desktop, macOS, single host — DB + api + Meili + k6 współdzielą CPU). Wykonano zgodnie z dyrektywą operatora „wszystko lokalnie, bez hostingu".

## Zastrzeżenie metodyczne (z planu)

Gate **p95 < 300 ms** jest **bezwzględny tylko na sprzęcie prod-podobnym**. Lokalnie wszystkie komponenty rywalizują o te same rdzenie, więc bezwzględne wartości p95 przy wyższej współbieżności to **proxy**, nie werdykt prod. Sesja mierzy więc dwie rzeczy: (1) **kształt** latencji per endpoint (który endpoint jest algorytmicznie cięższy — to przenosi się na prod), (2) **stabilność pamięci** workera pod długim ruchem (klasa ryzyka #1 worker mode wg CLAUDE.md §3.10).

## Katalog testowy

- **50 000 produktów** (`pim:load:seed`, SKU `load-6320a1-NNNNNN`), 200 atrybutów schematu, **1 680 000 wierszy `object_values`** (kanoniczne, 3 locale).
- Seeder: **peak 71 MiB, płaski** — worker-mode memory-safe potwierdzony na 50k zapisów.
- Projekcje `attributes_indexed` (50000/50000, 19 globalnych kluczy/obiekt) + **Meili reindex 50118 docs** (101 batchy).

## Wyniki p95 per endpoint

Dwa przebiegi: **20 VU** (nasycenie — pokazuje kontencję lokalną) i **5 VU** (realistyczny single-tenant admin load — izoluje latencję algorytmiczną). Każdy 45–60 s, 0% błędów, 100% checków (status 200 + kształt body) w obu.

| Endpoint | p95 @20 VU | p95 @5 VU | median @5 VU | Gate 300 ms @5 VU | Uwaga |
|---|---|---|---|---|---|
| `POST /api/products/bulk-actions/preview` | 259 ms | **71 ms** | 35 ms | ✅ | najlżejszy |
| `GET /api/search/products` (Meili) | 347 ms | **92 ms** | 63 ms | ✅ | Meili niesie read |
| `POST /api/exports/preflight` (COUNT 50k + RBAC) | 388 ms | **95 ms** | 68 ms | ✅ | COUNT na 50k tani |
| `GET /api/products` (keyset walk, JSON-LD) | 1279 ms | **481 ms** | 331 ms | ❌ | **odstający 5×** |

### Interpretacja

- **3 z 4 endpointów**: p95 spada z >300 ms (20 VU) do **<100 ms (5 VU)** — nadmiar przy 20 VU to **czysta kontencja CPU** na jednym hoście, znika przy realistycznej współbieżności. Na prod-podobnym HW spełnią gate z zapasem.
- **`GET /api/products` odstaje**: p95 **481 ms nawet przy 5 VU**, median 331 ms — to **algorytmiczne**, nie kontencja (nie spada z współbieżnością jak pozostałe). Endpoint listy serializuje 30 pełnych produktów JSON-LD/strona na katalogu 50k. To **jedyny realny finding wydajnościowy** sesji → ticket #2234 (kandydat: N+1 na atrybutach / ciężar serializacji hydra, keyset OK bo median płaski niezależnie od głębokości).

## Endurance (proxy pamięciowy)

- Scenariusz `mixed-endurance` (55% list / 30% search / 10% preflight / 5% bulk-preview), **6 VU × 15 min**, obserwacja `frankenphp_worker_memory_bytes` co 60 s.
- **Ruch: 17 735 iteracji, 0% błędów, 100% checków (status 200).** Mix p95 = 578 ms — zdominowany przez 55%-owy udział ciężkiego `GET /api/products` (patrz #2234); pozostałe endpointy w mixie < 100 ms.
- **Pamięć workera:** baseline 40 MiB → pasmo **40–90 MiB**, plateau **86–90 MiB** od ~4 min do końca. **Brak liniowego wzrostu** przez 15 min ciągłego ruchu = brak wycieku Identity Map. Uwaga metodyczna: każdy scrape `/api/metrics` trafia **losowy worker z puli** (LB w FrankenPHP), stąd oscylacja próbek — trend, nie pojedyncza próbka, jest sygnałem.
- **Werdykt: ✅ pamięć płaska.** Max 90 MiB — daleko pod early-warning 192 MiB i twardym sufitem 256 MiB (#2222). Worker-mode memory-safe pod długim mieszanym ruchem na katalogu 50k.

## Findingi sesji

1. **#2231** — `pim:catalog:detect-attributes-drift --reconcile` OOM na 50k (256 MiB): `toIterable()` buforuje pełny result-set + per-obiekt `findBy` (~1.7M zapytań). Projekcje zapisane, ale exit≠0. Fix: keyset pagination + batch-load wartości.
2. **#2234** (nowy) — `GET /api/products` p95 481 ms @5 VU na 50k (algorytmiczne). Kandydat: serializacja listy / N+1 atrybutów.

## Werdykt

- **p95 lokalny = proxy.** 3/4 endpointów zdrowe (kontencja znika przy realistycznej współbieżności); `GET /api/products` wymaga optymalizacji serializacji przed twardym gate na prod.
- **Bezwzględny gate <300 ms** potwierdzić na prod-podobnym HW (Blok C, po wyborze hostingu) — szczególnie dla `GET /api/products` po fixie #2234.
- **Pamięć workera**: ✅ płaska (40–90 MiB, plateau ~88, brak wycieku) przez 15 min / 17.7k iteracji.
- **Seeder + reindex memory-safe** na 50k (71 MiB / batche).

## Reprodukcja

```bash
scripts/load/run-load-session.sh --seed 50000          # seed 50k (kanoniczne wartości)
# projekcje: pim:catalog:detect-attributes-drift --reconcile  (uwaga OOM #2231 — 1GB limit)
# pim:search:reindex
K6_VUS=5  K6_DURATION=45s scripts/load/run-load-session.sh   # p95 baseline (realistyczny)
K6_VUS=20 K6_DURATION=60s scripts/load/run-load-session.sh   # p95 pod nasyceniem
K6_VUS=6  K6_DURATION=15m scripts/load/run-load-session.sh --scenario mixed-endurance
scripts/load/run-load-session.sh --seed 0 # (albo: pim:load:seed --purge) sprzątanie
```
