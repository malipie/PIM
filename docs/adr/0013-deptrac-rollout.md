# 0013. Deptrac as the Architectural Fitness Gate

- **Status:** accepted
- **Date:** 2026-04-29 (RF-21)
- **Deciders:** Marcin Lipiec

## Context and Problem Statement

The audit's DDD-010 (CRITICAL) reported 65 cross-BC imports. After RF-02..05 + RF-19 the live count is much smaller, but without a mechanical gate the next regression sneaks in unnoticed. PHPStan does not understand Bounded Contexts; we needed a static rule engine that does.

## Decision Drivers

- DDD-010 CRITICAL: cross-BC imports must fail at CI, not at code review.
- modular monolith: the ringfence between BCs is the most load-bearing rule we have.
- audit checklist TOOL-001 calls for `vendor/bin/deptrac analyse` as a required check.

## Decision Outcome

Deptrac 4 wired up at `apps/api/deptrac.yaml`. Layers:

- per-BC Internals + Contracts (Catalog_Internals + Catalog_Contracts, Channel_*, Asset_*, Identity_*, Shared);
- forward-compatible Integration / Agent / ApiConfigurator scaffolding for empty BCs;
- Tooling slot (Benchmark, DataFixtures, Story) — see ADR-0010.

Ruleset: cross-BC traffic only through `*_Contracts`. Tooling may pull from any BC, no BC may pull from Tooling.

### 2026-08-28 amendment — persistence-free Catalog Domain

`Catalog_Domain` is now a separate layer from the remaining
`Catalog_Internals`. Doctrine is likewise carved out of the generic Vendor
layer. `Catalog_Domain` may depend on Catalog Contracts, Shared and non-Doctrine
Vendor code, but it may not depend on Doctrine. Application, Infrastructure and
Presentation retain their existing Doctrine access and may consume Domain.

Persistence-backed validation and effective-model reads cross semantic query
ports declared in `Catalog/Domain/Repository`; Doctrine implementations and all
DQL/SQL live in `Catalog/Infrastructure/Doctrine`. This makes a new
Domain-to-Doctrine import a Deptrac violation without adding a baseline entry.

A baseline of 27 pre-existing violations lives inline in `skip_violations`, each annotated with a follow-up:

1. `Catalog\Domain` enums (ObjectKind / AttributeType / Provenance) used by Catalog Contracts. Cleanup: move into `Catalog\Contracts\Enum`.
2. `ChannelObjectTypeMapping` cross-BC FKs to `Catalog\Domain\Entity\ObjectType` + `Attribute`. RF-19 deferred this junction's Uuid sweep.
3. `Shared\Infrastructure\Http\RequestTenantSubscriber` depending on `Identity\Application\CurrentTenantProvider`. Cleanup: move provider into `Shared\Application`.

CI: `Deptrac (architectural fitness)` job in `.github/workflows/quality-php.yml`, required-on-merge.

### Consequences

- **Positive:** new violations fail at CI. The baseline is finite and tracked.
- **Positive:** Catalog business rules cannot acquire new direct ORM/DBAL
  dependencies; persistence queries are replaceable ports with integration-tested
  adapters.
- **Negative:** baseline entries can rot — review them when a BC layout changes substantially.
- **Follow-ups:** clean up the three baseline clusters in dedicated tickets; target an empty `skip_violations` map.

## Alternatives Considered

- **Custom PHPStan rules:** PHPStan extension authoring overhead far exceeds Deptrac's YAML config for the same payoff.
- **Manual code review:** the current state proves it doesn't scale (65 violations had accumulated invisibly).

## Links

- AUDIT-REPORT-2026-04-29 §2 DDD-010 / TOOL-001
- RF-21 PR #203
- ADR-0010 (Tooling slot)
- ADR-0014 (Tenant move informs the baseline cluster #3)
