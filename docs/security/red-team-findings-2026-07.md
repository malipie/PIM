# Red-team RBAC — findings 2026-07 (GOLIVE #2129)

**Data:** 2026-07-04 · **Blok:** B · **Charakter:** wewnętrzny secure-SDLC (red-team WŁASNEGO systemu wg checklisty `07-rbac-implementation-plan.md` §5.3, autoryzacja właściciela, zakres = ten codebase + lokalny stack).

Harness: [`scripts/security/red-team-rbac.sh`](../../scripts/security/red-team-rbac.sh) — provisionuje throwaway usera Marketing (invitation dev-token) i wykonuje punkty checklisty automatyzowalne przez curl.

## Podsumowanie: 1 finding HIGH (naprawiony), reszta trzyma

| # | Punkt checklisty | Wynik | Uwaga |
|---|---|---|---|
| 1 | Marketing → DELETE product → 403 | ✅ | single-item voter działa |
| 2 | JWT tenant_id tamper → 401 | ✅ | podpis odrzucony |
| 4 | invitation token reuse → consumed | ✅ | 400/410 drugi raz |
| 5 | JWT exp tamper (kept sig) → 401 | ✅ | |
| **6** | **Marketing bulk-delete → 403** | **❌→✅ FINDING HIGH (naprawiony)** | patrz niżej |
| 9 | Marketing edit product → scoped | ✅ | 403 per-attribute |
| 12 | SSRF / path escape | pokryte | NoPrivateNetworkHttpClientTest + FolderPathGuard (integ.) |
| 13 | SQLi w filter JSONB → 200/400 | ✅ | parameteryzowane, brak 500 |
| 14 | open redirect return_to | pokryte | SSO callback używa APP_BASE_URL allow-list (code review) |

**Odłożone (nie curl-driven):** pkt 7 agent prompt-injection → #2136 (wymaga live LLM); pkt 3 API-token scope → Phase 5 mint UI; pkt 10 super-admin privacy boundary → #2134 (matryca izolacji, cross-read=404); pkt 11 last-admin 409 + pkt 15 race-condition → UI/integration.

## FINDING HIGH — eskalacja uprawnień przez bulk-actions (naprawiony)

**Klasa:** privilege escalation (within-tenant), authz bypass. **Severity:** HIGH.

**Opis:** `POST /api/products/bulk-actions/{actionType}` był gated wyłącznie coarse `#[RequiresPermission(module: 'products', action: 'bulk_operations')]`. Destrukcyjny `actionType=delete` NIE re-asertował `products.delete` — podczas gdy single-item `DELETE /api/products/{id}` (voter `is_granted('DELETE', object)`) poprawnie tego wymaga. Rola Marketing (Content Editor) ma `products.bulk_operations` ale NIE `products.delete`.

**Dowód (przed fixem):** Marketing user `POST /api/products/bulk-actions/delete {target_ids:[X],confirmation_count:1}` → **HTTP 200 `success_count:1`**, produkt następnie **404** (skasowany). To samo dotyczyło `duplicate` (tworzenie bez `products.add`).

**Fix:** `BulkActionsController::apply()` re-asertuje per-action permission z mapy `PER_ACTION_PERMISSION` (`delete`→`products.delete`, `duplicate`→`products.add`) przez `PermissionCheckerInterface` (Contracts, cross-BC seam) PRZED dispatchem → `AccessDeniedHttpException` (403) gdy brak grantu. `preview` nietknięty (read-only, nie może zniszczyć danych).

**Dowód (po fixie):** Marketing bulk-delete → **403 `Missing permission "products.delete"`**, produkt **200** (ocalał); admin (super_admin) bulk-delete → **200** (kontrola). Test regresyjny: `BulkActionsPerActionPermissionApiTest`.

**Bramki:** PHPStan max 0 · Deptrac 0 (Catalog→Identity Contracts dozwolone) · cs-fixer 0.
