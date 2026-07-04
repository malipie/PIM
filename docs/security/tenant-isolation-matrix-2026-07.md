# Matryca izolacji tenantów na żywo (GOLIVE #2134)

**Data:** 2026-07-04 · **Blok:** B · **Charakter:** wewnętrzny secure-SDLC (2 tenanty na własnym stacku).

Harness: [`scripts/security/tenant-isolation-matrix.sh`](../../scripts/security/tenant-isolation-matrix.sh) — próbuje cross-tenant read/write z tokenem demo na zasoby acme (i odwrotnie) przez KAŻDĄ powierzchnię osobno, nie tylko REST CRUD. Exit 0 = wszystkie izolują.

## Wynik: 7/7 PASS (żywy stack, tenanty demo=100 produktów / acme=3)

| # | Powierzchnia | Próba cross-tenant | Wynik |
|---|---|---|---|
| 1 | REST read by id | demo token → `GET /api/products/{acme}` | **404** ✅ |
| 2 | REST write | demo token → `PATCH /api/products/{acme}` | **404** ✅ |
| 3 | REST collection | liczności `/api/products` per token | demo=100, acme=3 (rozłączne) ✅ |
| 4 | Meili search | acme token szuka demo-only kodu `RPT` | **0 hits** ✅ |
| 5 | Postgres RLS | `pim_app` z GUC acme liczy demo objects | **0** ✅ |
| 6 | Asset binary | acme token → `GET /api/assets/{demo}/preview` | **403** ✅ |
| 7 | Feed public URL | nieznany token pod acme tenantId | **404** (nie 403 — brak existence-oracle) ✅ |

## Uwagi

- **Powierzchnia 5 (RLS)** to defence-in-depth POZA Doctrine TenantFilter — nawet gdyby filter zawiódł, FORCE RLS zwraca 0 wierszy cudzego tenanta dla roli `pim_app` (NOBYPASSRLS).
- **Powierzchnia 6:** `PreviewAssetController` wyłącza tenant_filter i izoluje by-UUID; 403 pochodzi z warstwy podpisanego URL (W0-4 #1576) — cross-tenant asset niedostępny bez ważnego podpisu.
- **Powierzchnia 7:** feed serwuje tylko cache'owany artefakt po 192-bitowym tokenie; ścieżkowy tenantId scopuje RLS przed jakimkolwiek lookupem, a 404 (nie 403) nie zdradza istnienia feedu.
- Komplementarne do #2130 (AUD-002 RLS) i #2131-2133 (deep audit).
