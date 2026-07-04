# Chaos dry-run + weryfikacja alertingu (GOLIVE #2137)

**Data:** 2026-07-04 · **Blok:** B. Harness: [`scripts/chaos/dependency-failure-dryrun.sh`](../../../scripts/chaos/dependency-failure-dryrun.sh) — zatrzymuje każdą zależność po kolei, sonduje zależny endpoint, restartuje. Stack nigdy nie zostaje zepsuty (restart + wait-healthy po każdej próbie).

## Wyniki degradacji

| Awaria | Endpoint | Zachowanie | Werdykt |
|---|---|---|---|
| **Meilisearch DOWN** | `GET /api/search/products` | **503 `application/problem+json`** | ✅ łagodna (RFC 7807) |
| Meilisearch DOWN | `GET /api/products` (REST) | 200 | ✅ ścieżka Postgres niezależna |
| **Redis DOWN** | `GET /api/products` + `/api/auth/me` | 200 / 200 | ✅ nie na krytycznej ścieżce read |
| **MinIO DOWN** | `GET /api/assets` | 200 | ✅ reads niezależne |
| **MinIO DOWN** | `POST /api/import-sessions/parse-preview` (upload) | **500 `text/html`** | ❌ **FINDING → #2221** (nie RFC 7807) |
| **Mercure DOWN** | `PATCH product` (publikuje SSE) | 200 | ✅ SSE best-effort |

**Restart-all:** wszystkie usługi wróciły `healthy` po restarcie (restart policies + healthchecki działają).

## Findingi (luki → tickety)

1. **#2221** — MinIO down podczas uploadu → **500 HTML** zamiast 503 RFC 7807. Jedyna nie-łagodna degradacja; write-path do S3 nie łapie niedostępności backendu w problem-details.
2. **#2222** — **brak alertów Prometheus na `up==0`** dla Meili/MinIO/Redis/Mercure. `alerts.yml` ma tylko 3 (worker-memory ×2 + db-p99); awaria zależności jest niewidoczna dla alertingu. Dodatkowo `prometheus.yml` scrape'uje tylko `pim-api` + `prometheus` — brak targetów zależności.

## Dev-quirki vs runbooki prod (kryterium done pkt 4)

- **MinIO degraded → restart** (`SlowDownWrite`/`inconsistent drive`) — udokumentowane w README Troubleshooting (#2179) + memory; prod: pgBackRest/MinIO anti-SPOF (W1-6).
- **FrankenPHP cache corruption** — root-fix #2179 (role-scoped cache dir) + README heal; nie dotyczy prod (cache warmowany w build-time).
- **auth rate-limit** — `cache:pool:clear cache.rate_limiter`; prod: limiter działa jako zabezpieczenie (świadome).

## Poza zakresem lokalnym

- **Test „dysk pełny" na wolumenie WAL/MinIO** — wymaga manipulacji quota/tmpfs na hoście (destrukcyjne dla wolumenów operatora); odłożone do prod-podobnego środowiska staging (Blok C infra).
