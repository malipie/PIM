# Custom PHPStan rules — Cortex PIM

> Status: MVP-Alpha. Source: [RBAC-P1-010](https://github.com/malipie/PIM/issues/649) (#649).
> ADR-013 (RBAC od dnia 1) requires static enforcement of permission patterns so a missed annotation breaks CI, not production.

## Shipped rules (RBAC-P1-010)

### Rule 1 — `RequiresPermissionAnnotationRule`

**Class:** `App\PHPStan\Rules\RequiresPermissionAnnotationRule`
**Identifier:** `rbac.missingPermissionAttribute`

Every public method that carries Symfony `#[Route]` must also declare one of:

- `#[RequiresPermission(module: ..., action: ...)]` — positive permission gating
- `#[NoPermissionRequired(reason: ...)]` — explicit opt-out (public auth flows, probes, webhooks)

**Why:** the runtime [`EndpointGuardListener`](https://github.com/malipie/PIM/issues/664) (Phase 3 #664) trusts the attribute to be present. A forgotten annotation yields a silently public endpoint. Static enforcement catches the omission at PHPStan level so it breaks CI, never production.

**Allowed (example):**

```php
use App\Identity\Domain\Attribute\RequiresPermission;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController
{
    #[Route(path: '/api/products/{id}', methods: ['PATCH'])]
    #[RequiresPermission(module: 'products', action: 'edit', subject: 'product')]
    public function update(Product $product): JsonResponse { /* ... */ }
}
```

```php
use App\Identity\Domain\Attribute\NoPermissionRequired;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route(path: '/api/health', methods: ['GET'])]
    #[NoPermissionRequired(reason: 'Public probe — no authentication required by infra')]
    public function status(): JsonResponse { /* ... */ }
}
```

**Disallowed:**

```php
final class ProductController
{
    #[Route(path: '/api/products/{id}', methods: ['PATCH'])]
    public function update(Product $product): JsonResponse { /* ... */ }
    // PHPStan: rbac.missingPermissionAttribute — add #[RequiresPermission] or #[NoPermissionRequired]
}
```

**Baseline policy:** the 132 pre-RBAC controllers are grandfathered in `apps/api/phpstan-baseline.neon` so this rule does not block the PR introducing it. Phase 6 ([#714](https://github.com/malipie/PIM/issues/714) — `add #[RequiresPermission] to existing Product endpoints`, [#715](https://github.com/malipie/PIM/issues/715), [#716](https://github.com/malipie/PIM/issues/716), [#717](https://github.com/malipie/PIM/issues/717)) walks each baseline entry, adds the proper attribute, and the rule then catches new regressions.

### Rule 3 — `HardcodedRoleCheckRule`

**Class:** `App\PHPStan\Rules\HardcodedRoleCheckRule`
**Identifier:** `rbac.hardcodedRoleCheck`

Forbids direct role-membership checks anywhere outside Voter / Rbac / RbacSeeder code. These shortcuts bypass the Voter pipeline and ignore `UserRole` scope (locale / channel / attribute_group).

**Forbidden patterns:**

- `$user->hasRole('ROLE_ADMIN')`
- `$user->isAdmin()` / `$user->isOwner()` / `$user->isSuperAdmin()`
- `in_array('admin', $user->getRoles(), true)`

**Required pattern (route through the Voter graph):**

```php
// Controller / service / handler:
if ($this->security->isGranted('products.edit', $product)) { /* ... */ }

// Or declaratively at the method level:
#[IsGranted('products.edit', subject: 'product')]
public function update(Product $product) { /* ... */ }
```

**Exempt locations (allowed, by design):**

- `src/Identity/Infrastructure/Security/` — Voter implementations legitimately read role membership; they are the bottom of the pipeline
- `src/Identity/Domain/Rbac/` — `RbacMatrix`, `RoleDefinition`, `PermissionDefinition` consume roles when computing permission sets
- `src/Identity/Application/RbacSeeder` — seeder reads role catalogue when materialising templates
- `tests/`, `DataFixtures/` — fixtures and assertions legitimately introspect roles

### `LegacyPermissionCodeRule` (#2881)

**Co blokuje:** `#[RequiresPermission]` nazywający zasób z siatki legacy (`RbacMatrix::legacyResources()`) **bez** ani jednego kodu PRD w `anyOf`.

**Dlaczego:** uprawnienia żyją w PIM w dwóch równoległych katalogach — legacy `{zasób}.{akcja}` (`object.write`, `channel.read`) i PRD §3.2 `{moduł}.{akcja}` (`products.add`, `publications.view`). **Role tworzone przez panel niosą wyłącznie kody PRD.** Endpoint pytający o sam kod legacy jest więc zamknięty dla każdej roli, którą tenant potrafi u siebie utworzyć: użytkownik ma uprawnienie, panel pokazuje, że je ma, a serwer odmawia.

Ten defekt naprawiano **pięć razy, ekran po ekranie** (#2841, #2849, #2852, #2877, #2880), bo nic nie powstrzymywało kolejnego feature'u przed wprowadzeniem go od nowa. Ta reguła jest tym powstrzymaniem.

```php
// ✗ zamknięte dla każdej roli z panelu
#[RequiresPermission(module: 'channel', action: 'read')]

// ✓
#[RequiresPermission(module: 'channel', action: 'read', anyOf: [
    'channel.read',          // podmioty sprzed PRD i klucze API
    'publications.view',     // role z panelu
])]
```

**Czego reguła NIE sprawdza:** czy wybrany kod PRD jest *właściwy*. To decyzja o tym, kto ma docierać do danej powierzchni, i żadna analiza statyczna jej nie podejmie. Reguła gwarantuje tylko, że decyzja w ogóle zapadła. Tabela mapowań: #2881.

**Kolizja nazw, którą reguła pomija świadomie:** `object.view` / `.add` / `.edit` / `.delete` / `.export` to werby ULV-04a (#985) zaseedowane jako kody PRD, mimo że `object` jest też zasobem legacy — `object.delete` to dosłownie jeden wiersz obsługujący oba katalogi. Kod, który sam jest w katalogu PRD, nie jest zgłaszany.

**Brak wyjątków na baseline.** Po #2881 na samym kodzie legacy nie stoi żaden endpoint produkcyjny, więc reguła wchodzi na czysto — nie ma czego grandfather'ować i nie ma daty przeglądu do pilnowania.

**Kiedy zniknie:** gdy zniknie katalog legacy (migracja ról istniejących instalacji, osobny ticket). Do tego czasu to jest jedyna rzecz, która trzyma oba katalogi razem.

## Deferred rules (follow-up tickets)

### Rule 2 — `FlushWithoutClearRule` (DEFERRED)

**Status:** not shipped in #649. Follow-up after the first batch handler that does not extend [`AbstractBatchHandler`](https://github.com/malipie/PIM/blob/main/apps/api/src/Shared/Application/AbstractBatchHandler.php).

**Why deferred:** the abstract pattern in `AbstractBatchHandler` already enforces `flush()` + `clear()` for every batch handler that subclasses it (see [`RebuildAttributesIndexedHandler`](https://github.com/malipie/PIM/blob/main/apps/api/src/Catalog/Application/Handler/RebuildAttributesIndexedHandler.php) as the canonical example). CLAUDE.md §"Memory management — FrankenPHP worker mode" mandates either `AbstractBatchHandler` or manual `clear()`. The rule becomes valuable when a contributor writes a batch handler that ignores the abstract pattern; until then the abstract + documentation pair is sufficient. Re-evaluate during Phase 6 hardening ([#720](https://github.com/malipie/PIM/issues/720)).

**Scope when shipped:**
- AST traversal of classes implementing `MessageHandlerInterface` or carrying `#[AsMessageHandler]`
- Detect `flush()` inside a loop body without a following `clear()` in the same scope
- Exempt classes that extend `AbstractBatchHandler`

## How to add a new rule

1. Implement the rule in `apps/api/src/PHPStan/Rules/<Name>Rule.php` with namespace `App\PHPStan\Rules`.
2. Register it in `apps/api/phpstan/services.neon` with tag `phpstan.rules.rule`.
3. Add fixtures + assertions in `apps/api/tests/StaticAnalysis/<Name>RuleTest.php` (PHPStan's `RuleTestCase` pattern).
4. Regenerate the baseline so the rule does not block the PR introducing it: `docker compose exec api composer phpstan -- --generate-baseline phpstan-baseline.neon --allow-empty-baseline`.
5. Document the rule in this file (allowed / disallowed examples + exemption rationale).

## How to clear a baseline entry

When a Phase 6 retrofit adds the proper attribute to a previously grandfathered endpoint:

1. Remove the matching block from `apps/api/phpstan-baseline.neon` (or run `composer phpstan -- --generate-baseline phpstan-baseline.neon --allow-empty-baseline` to regenerate).
2. `reportUnmatchedIgnoredErrors: true` is already enabled in `phpstan.dist.neon`, so a stale baseline entry would itself break CI — keeping the baseline honest as the codebase evolves.

## Cross-references

- [`apps/api/phpstan.dist.neon`](../../apps/api/phpstan.dist.neon) — PHPStan config, includes `phpstan/services.neon` + `phpstan-baseline.neon`
- [`apps/api/phpstan/services.neon`](../../apps/api/phpstan/services.neon) — rule registration
- [`apps/api/phpstan-baseline.neon`](../../apps/api/phpstan-baseline.neon) — 132 grandfathered controllers (Phase 6 retrofit target)
- [`apps/api/src/Identity/Domain/Attribute/RequiresPermission.php`](../../apps/api/src/Identity/Domain/Attribute/RequiresPermission.php) — attribute class
- [`apps/api/src/Identity/Domain/Attribute/NoPermissionRequired.php`](../../apps/api/src/Identity/Domain/Attribute/NoPermissionRequired.php) — opt-out attribute
- [`CLAUDE.md`](../../CLAUDE.md) §"Memory management — FrankenPHP worker mode" — the rule 2 deferral rationale
- [`Project Plan/07-rbac-implementation-plan.md`](../../Project%20Plan/07-rbac-implementation-plan.md) §3 — full security tooling roadmap
