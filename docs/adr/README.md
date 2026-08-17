# Architecture Decision Records

Architectural decisions for the PIM project follow [MADR 4.0](https://adr.github.io/madr/).

Why per-file ADRs alongside the narrative architecture in `Project Plan/01-architektura-pim.md`:

- the Project Plan reads top-to-bottom for onboarding and tells a story;
- the ADR set is the authoritative source for "why is X this way" — each file pins a single decision with its context, alternatives, and consequences;
- post-RF refactor we cross-reference both: Project Plan section 13 lists the ADR numbers, the ADR files carry the actual reasoning.

## Index

- [adr-template.md](adr-template.md) — copy when starting a new ADR
- [0000-use-markdown-architectural-decision-records.md](0000-use-markdown-architectural-decision-records.md)
- [0010-src-top-level-policy.md](0010-src-top-level-policy.md)
- [0011-orm-xml-mapping-in-infrastructure.md](0011-orm-xml-mapping-in-infrastructure.md)
- [0012-cqrs-application-layer.md](0012-cqrs-application-layer.md)
- [0013-deptrac-rollout.md](0013-deptrac-rollout.md)
- [0014-tenant-as-shared-kernel.md](0014-tenant-as-shared-kernel.md)
- [0015-cross-bc-fk-policy.md](0015-cross-bc-fk-policy.md)

ADRs 0001-0009 codify the existing decisions narrated in `Project Plan/01-architektura-pim.md` section 13. Lift-and-shift to per-file MADR is a follow-up housekeeping ticket — the canonical record stays in the Project Plan until that lands.

- [0015-cross-bc-fk-policy.md](0015-cross-bc-fk-policy.md) — bare UUID references between BCs; no Doctrine cross-BC associations
- [0016-api-configurator-key-format.md](0016-api-configurator-key-format.md) — API key format + Argon2id hashing
- [0017-byok-encryption-strategy.md](0017-byok-encryption-strategy.md) — BYOK AES-256-GCM with versioned master key
- [0018-channel-publication-profile.md](0018-channel-publication-profile.md) — per-channel attribute/locale allow-list; `?publication=<channel>` distinct from `?channel=`
- [0019-import-v2-engine-contracts.md](0019-import-v2-engine-contracts.md) — Import v2 engine contracts (CREATE/UPDATE/UPSERT, JSONB canon, column grammar)
- [0020-openapi-custom-route-documentation.md](0020-openapi-custom-route-documentation.md) — API Platform + custom `#[Route]`; `CustomRouteOpenApiFactory` folds custom routes into the OpenAPI export
- [0021-frontend-data-fetching.md](0021-frontend-data-fetching.md) — frontend data-fetching strategy
- [0022-api-configurator-consumer-producer-boundary.md](0022-api-configurator-consumer-producer-boundary.md) — consumer/producer boundary; generic connector in `Integration/Generic`
- [0023-konfigurator-xml-placement.md](0023-konfigurator-xml-placement.md) — Konfigurator XML: engine in `Export`, feeds in `Export/Feed`, associative `ItemWriter`, pull cache-and-serve, token-in-URL auth
- [0024-agent-removable-bc-and-tool-registry.md](0024-agent-removable-bc-and-tool-registry.md) — Agent layer: removable `src/Agent/` BC (open-core, CI removability gate), tool = engine `Contracts` port + thin adapter, RBAC-filtered tool registry, single approval gate via `pending_changes`
- [0025-object-type-cross-field-validation.md](0025-object-type-cross-field-validation.md) — `object_types.validation_rules` JSONB; cross-field CompareRule/RequireWhenRule enforced in both write paths
- [0026-dashboard-read-model.md](0026-dashboard-read-model.md) — thin read-only `src/Dashboard` context; raw DBAL reads with explicit `tenant_id`; on-the-fly alert aggregator + daily snapshots
- [0027-catalog-pdf-renderer-port.md](0027-catalog-pdf-renderer-port.md) — Catalog PDF: web-to-print engine in `Export/Catalog`, `PdfRenderer` port (Dompdf default in-process, Gotenberg optional sidecar, PDFreactor future CMYK), cache-and-serve + token-in-URL
- 0028 — reserved by epic GRID (`feature-grid-tickets.md`, attribute sort strategy)
- [0028-attribute-sort-strategy.md](0028-attribute-sort-strategy.md) — Sort po wartościach atrybutów: Postgres JSONB path expression (`->>'value'::cast NULLS LAST` + tie-breaker `id`), LIMIT/OFFSET dla sortowanych zapytań, bez indeksów wyrażeniowych w MVP (benchmark 50k: p95 12–25 ms); eskalacja: btree wyrażeniowy → Meilisearch przy p95>500 ms
- [0029-workflow-engine-and-placement.md](0029-workflow-engine-and-placement.md) — Workflow engine: `symfony/workflow` state machine `object_editorial` (draft→review→published→archived) marking on `objects.status`, guards as RBAC permission map (data, not expressions), new `src/Workflow/` BC (transition log, tasks), completeness gate per ObjectType default OFF, tenant DB-driven definitions behind feature flag (M5)
- [0030-ai-content-generation-tools.md](0030-ai-content-generation-tools.md) — AI content generation: agent-native `kind=Write` tools in the existing `ToolRegistry`, `ContentRecipe`/`BrandVoiceProfile` in `src/Agent/` (removability), contracted grounding + anti-hallucination contract, zero auto-write (`pending_changes` + approval), SEO rules in recipe not schema, Sonnet-tier + BYOK
- [0033-long-running-jobs-own-their-transaction-boundaries.md](0033-long-running-jobs-own-their-transaction-boundaries.md) — długie zadania (import, wycofanie) na szynie bez `doctrine_transaction`; wycofanie wymienia atomowość na wznawialność
- [0035-tenant-isolation-instance-per-tenant.md](0035-tenant-isolation-instance-per-tenant.md) — instancja per tenant: własny Postgres (niezależny PITR — stanza pgBackRest obejmuje klaster) + własne `api`/`worker`/`redis`/`mercure`; wspólne Meilisearch (stała `INDEX_NAME`), MinIO (bucket per tenant), edge Caddy i bundel SPA; `TenantFilter` + RLS zostają bez zmian jako warstwa wewnątrz instancji
