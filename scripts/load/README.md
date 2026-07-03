# Load testing (GOLIVE A4, #2124)

Przygotowanie do jednorazowej sesji load z Bloku B (#2128): skrypty k6 + seed 50k SKU.
Gate z planu testów: **p95 < 300 ms per endpoint** przy 50k SKU (lokalnie = proxy;
bezwzględny wynik potwierdzamy na prod-podobnym sprzęcie).

## Wymagania

- Działający stack (`pnpm stack:up`), świeży token nie jest potrzebny — orkiestrator mintuje sam.
- Serwis `k6` w composie (profil `perf`, nie startuje z `stack:up`); skrypty montowane jako `/load`.

## Seed 50k (jednorazowo przed sesją)

```bash
scripts/load/run-load-session.sh --seed 50000
```

Robi po kolei: `pim:load:seed` (50k produktów × 200 atrybutów schematu × wartości
w 3 locale, **kanoniczne `object_values`**, provenance=import, SKU `load-<hex>-NNNNNN`)
→ `pim:catalog:detect-attributes-drift --reconcile` (projekcja `attributes_indexed`
realnym rebuilerem) → `pim:search:reindex` (Meili). Seed 50k trwa kilkanaście–kilkadziesiąt
minut; parametry (`--products`, `--attributes`, `--locales`, `--values-per-product`)
— patrz `bin/console pim:load:seed --help`. Sprzątanie: `pim:load:seed --purge`.

> Seed pisze do bazy dev tenanta `demo`. Przed sesją na danych operatora zrób
> backup (`pnpm backup:run`) — patrz OSTRZEŻENIE o `pim:db:reset` w README.

## Scenariusze

| Skrypt | Endpoint | Charakter |
|---|---|---|
| `products-list.k6.js` | `GET /api/products` (keyset walk) | load |
| `search-products.k6.js` | `GET /api/search/products?q=` | load (Meili) |
| `export-preflight.k6.js` | `POST /api/exports/preflight` (scope=all) | load (COUNT 50k + RBAC) |
| `bulk-preview.k6.js` | `POST /api/products/bulk-actions/preview` | load (diff bez mutacji) |
| `mixed-endurance.k6.js` | mix 55/30/10/5 powyższych | endurance 2–4h (B2/B7) |
| `feed-pull.k6.js` | publiczny URL feedu XML | capped (limiter 120/h/feed) |
| `auth-login.k6.js` | `POST /api/auth/login` | smoke ×4 (limiter 5/IP/15min) |
| `import-start.k6.js` | `POST /api/import-sessions` (3-wierszowy CSV) | smoke ×1 (limiter 20/h/tenant) |

Świadome decyzje: apply bulk-edita, pełny eksport i duży import **nie są** młócone
k6 (mutują dane / MinIO / kolejkę) — B2 mierzy je pojedynczym realnym przebiegiem,
a k6 w tym czasie utrzymuje tło `mixed-endurance`. Login/import/feed są ograniczone
limiterami — scenariusze celowo asertują brak 429 zamiast go wywoływać.

## Uruchomienie

```bash
# domyślny zestaw read-heavy (list, search, preflight, bulk-preview)
scripts/load/run-load-session.sh

# pojedynczy scenariusz, własna skala
scripts/load/run-load-session.sh --scenario products-list --vus 50 --duration 120s

# endurance (obserwuj frankenphp_worker_memory_bytes w Prometheusie)
scripts/load/run-load-session.sh --scenario mixed-endurance --vus 10 --duration 3h

# feed pull (URL z ekranu szczegółów feedu)
FEED_PULL_URL='https://pim.localhost/api/feeds/pull/<tenant>/<token>.xml' \
  scripts/load/run-load-session.sh --scenario feed-pull
```

Wyniki (`--summary-export`) lądują w `scripts/load/results/*.json` (gitignore).
Raport sesji B2 → `docs/perf/` (wzór: `docs/perf/sync-engine-benchmark.md`).

## Znane pułapki

- Po serii loginów/refreshy limiter potrafi blokować kolejne minty: `docker compose restart redis`.
- `mixed-endurance` z dev-profilerem kłamie o pamięci — worker w dev jest pinowany do `APP_DEBUG=0`, ale komendy seed odpalaj z `APP_ENV=prod APP_DEBUG=0` (jak orkiestrator).
- k6 działa w sieci Caddy (`network_mode: service:caddy`) — `https://pim.localhost` z self-signed certem, stąd `insecureSkipTLSVerify`.
