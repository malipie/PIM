# Regresja 5 CRITICAL findings audytu 2026-06 (GOLIVE #2130)

**Data:** 2026-07-04 · **Blok:** B · **Charakter:** wewnętrzny secure-SDLC (proof-of-fix na własnym systemie).

Harness: [`scripts/security/regression-5-critical.sh`](../../scripts/security/regression-5-critical.sh) — re-odtwarza oryginalny probe każdego z 5 CRITICAL findingów z `docs/audit/2026-06/` i asertuje, że fix trzyma. Exit 0 = wszystkie naprawione; non-zero = regresja (blocker go-live).

## Wynik: 5/5 PASS (żywy stack `pim.localhost`)

| Finding | Wektor | Fix (wave) | Asercja regresji | Wynik |
|---|---|---|---|---|
| **AUD-001** | Mercure SSE anonimowa subskrypcja cross-tenant | W0-1 (#1573) — per-tenant topiki + `private:true` | anon `GET /.well-known/mercure?topic=…` → **401** (był 200 event-stream) | ✅ |
| **AUD-002** | RLS martwy (pim_app superuser/BYPASSRLS) | W1-1 (#1580) — NOSUPERUSER/NOBYPASSRLS + FORCE RLS | `pim_app` super/bypass = **false/false**, FORCE RLS na **55** tabelach (≥30) | ✅ |
| **AUD-004** | Meili filter-key injection (OR-bypass tenant) | W0-2 (#1574) — `assertFilterKeys()` whitelist | `GET /api/search/products?filter[tenantId]=…` → **400** | ✅ |
| **AUD-005** | Realne sekrety w trackowanym `.env` | W0-7 (#1579) — untrack + placeholdery + guard | `scripts/lint-tracked-secrets.sh` → **clean** | ✅ |
| **AUD-007** | `token_dev_only` bezwarunkowy account takeover | W0-5 (#1577) — env-gate `%kernel.environment%` | guard `'prod' === $devTokenEnvironment` w `DevTokenExposure` + wire w services.yaml | ✅ |

## Uwagi

- **AUD-007 na dev stacku:** pole `token_dev_only` JEST w odpowiedzi (dev-convenience, świadome) — regresja testuje OBECNOŚĆ ENV-GUARDA w kodzie (`DevTokenExposure::devTokenPayload` zwraca `[]` gdy `devTokenEnvironment === 'prod'`), nie brak pola na dev. Prod/test dropują pole.
- **AUD-002:** dodatkowo potwierdzone w #2134 (izolacja tenantów na żywo, cross-read = 0).
- Harness jest idempotentny i re-uruchamialny; kandydat na okresowy smoke bezpieczeństwa przed każdym release.
