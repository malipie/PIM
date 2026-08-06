# Changelog

All notable changes to PIM. Format follows [Keep a Changelog](https://keepachangelog.com/),
versioning per epic milestones (`0.X.Y` matches the internal epic/ticket numbering).

## [Unreleased]

_(nic jeszcze — kolejne zmiany po 1.0.0-alpha.1)_

## [1.0.0-alpha.1] — 2026-08-06

Pierwsze wydanie oznaczone tagiem: **MVP ukończone, start testowy (alpha)**.
Zakres = całość prac epików 0.1–0.11 + RBAC + integracje substrate + agent AI
(historia poniżej i w git log). W ramach przygotowania do publikacji:

### Added
- Produkcyjny edge: `docker/caddy/Caddyfile.prod` (auto-TLS, statyczny bundle
  admina, strona `/docs`), kompletny `.env.prod.example` + guard CI spójności,
  runbook deploy end-to-end (`docs/operations/deploy-runbook.md`). (#2775)
- `pim:tenant:bootstrap` — prod-safe utworzenie tenanta + ownera (hasło z env,
  idempotentne); parametryzacja haseł seedów (`PIM_SEED_ADMIN_PASSWORD`). (#2773)
- Strona dokumentacji `apps/docs` (VitePress + Scalar na snapshot OpenAPI)
  z automatyczną publikacją (workflow `docs-publish`). (#2777)
- MonologBundle: JSON na stderr w prod (fingers_crossed + pas WARNING). (#2776)

### Changed
- Dokumenty wewnętrzne (Project Plan, notatki agenta, protokoły testów, audyty)
  wyłączone z repozytorium — pozostaje dokumentacja produktowa i `docs/api-spec`. (#2770)
- Linki maili i nadawca z env (`APP_BASE_URL`, `MAILER_FROM`); Mercure
  sparametryzowany pod realną domenę; worker prod konsumuje transporty
  schedulera; obraz prod nie jest już przesłaniany bind-mountem. (#2773, #2775)
- Dane demo „electronics" na fikcyjnych markach (Voltix, Aurion, Kyodo...). (#2774)
- Stopka pokazuje realną wersję z `package.json` (build-time). (#2772)

### Fixed
- Alertmanager: webhook faktycznie podstawiany (render template w entrypoint —
  wcześniej alerty szły na literalny string). (#2775)
- Guard na all-zero `APP_BYOK_KEY_V1` przy `APP_ENV=prod`. (#2773)
- `symfony/http-client` w `require` (dotąd require-dev + dryf do 8.x). (#2776)

### Security
- Rotacja dev `JWT_PASSPHRASE` (wartość historyczna w git history unieważniona)
  + wymiana pary kluczy dev.

### Added — epik NUI (Retrofit UI v2, 2026-06-11/12)

- **NUI-01..13** retrofit widoków do nowego designu `PIM-nowoczesny`
  (13/13 ticketów #1420–#1432; PR #1444–#1449, #1451–#1457 + bramka):
  - sidebar v2: podmenu Ustawień w głównym sidebarze, custom OT bez
    wyróżnienia, live-dot Importów (#1420)
  - dashboard v2: KPI live (totale encji), układ rzędów wg designu,
    widget backupu (MOCK) (#1421)
  - lista produktów v2 (tab-rail widoków, de-violet, szerokości wg
    designu) + wygaszenie `/products/legacy` (#1423, #1424)
  - modelowanie: de-violet 25 plików, ink underline tabów (#1426)
  - Multimedia v2: eksplorator plików (kafle folderów, drawer 460px,
    upload w modalu, bulk bar) (#1427)
  - importy: hub w shellu v2 (PillTabs + liczniki, retire
    IntegrationsLayout), wizard 6 kroków na istniejącym backendzie,
    widok sesji z pipeline'em faz i live logiem Mercure (#1428–#1430)
  - globalna paleta ⌘K (nawigacja realna, sekcja agenta MOCK) (#1422)
  - ustawienia Users/Roles: de-violet 15 plików (#1431)
  - bramka jakości: trwały gate axe WCAG A/AA na 10 widokach
    (serious+critical = 0), AA-safe tokeny akcentów, globalny sweep
    text-zinc-400→500, semantyka listy produktów bez role="grid"
    (#1432)

### Fixed — przy okazji epiku NUI

- wykryty CI-only bug backendu: FK violation `objects_import_session_fk`
  na ścieżce inline-commit importu → #1455 (open)


### Added — epik UI-10 (Product Categories Assignment)

- **PCAT-01..07** Product↔category assignment end-to-end (1-day burst,
  2026-05-10):
  - DB junction `object_categories` (composite PK, partial unique
    index `WHERE is_primary = true`, ON DELETE CASCADE on both FKs).
    (#474 / PR #482)
  - HTTP layer `ProductCategoryAssignmentController` — GET / PUT
    (atomic replace) / POST (idempotent add) / DELETE (auto-promote
    next primary). 50-cap, kind validation, tenant isolation.
    (#475 / PR #483)
  - `EffectiveAttributeGroupResolver` activates the `kind=Product`
    branch — products inherit attribute groups from each assigned
    category's full ancestor chain. Killer-feature "Effective preview"
    in the modeling categories panel finally has empirical validation.
    `PrimaryCategoryRepairListener` promotes the next-oldest assignment
    when a category is cascade-removed. (#476 / PR #485)
  - `ObjectFormSchemaCacheInvalidator` bursts the per-ObjectType cache
    when an `ObjectCategory` row mutates. (#477 / PR #486)
  - Frontend tab "Kategorie" on the product detail page (between
    Multimedia and Powiązania) with chip-list + multi-select tree
    picker dialog supporting primary swap. (#478 / PR #487)
  - "Produkty (N)" card in the modeling category detail panel —
    paginated reverse listing of the products assigned to a category.
    (#479 / PR #488)
  - Activated the previously-MOCK "+ Create test object" button —
    one-click product creation pre-populated with the selected
    category as primary. (#480 / PR #489)
  - OpenAPI snapshot regenerated. (#481 / this PR)

### Added — chore (deps maintenance)

- **chore/deps** Force `fast-uri >= 3.1.2` via `pnpm.overrides` to
  clear two HIGH advisories transitively pulled in via
  `@commitlint/cli > ajv > fast-uri` (GHSA-q3j6-qgpj-74h6 path
  traversal + GHSA-v39h-62p7-jpjc host confusion). Workaround until
  upstream commitlint bumps ajv. (PR #484)

### Added — epic 0.11 hardening

- **0.11.4** Audit log MVP via DH Auditor for catalog schema (ObjectType /
  Attribute / AttributeGroup / AttributeOption / AssociationType) +
  Channel / Asset / Identity (User / Role / Permission / Tenant) +
  API Configurator (ApiProfile / ApiKey). 365-day retention via
  `pim:audit:cleanup` CLI. (#99 / PR #241)
- **0.11.2** Rate limiting hardening — sliding-window on
  `/api/auth/refresh` (30/h/IP) + per-key budget on `X-API-Key`
  (1000/h/key). `pim:security:unblock-ip` operator escape hatch.
  (#97 / PR #242)
- **0.11.3** Full security-headers stack at the Caddy edge — CSP,
  HSTS, X-Frame-Options, Permissions-Policy, COOP, CORP, Server header
  stripped. Playwright contract pin to prevent silent regressions.
  (#98 / PR #243)
- **0.11.7** Renovate config — patch / pin / digest auto-merge,
  minor + major manual review with ecosystem grouping (Symfony /
  Doctrine / API Platform / React+Refine / Vite stack / Playwright /
  PHPStan). Vulnerability alerts auto-merge regardless of update type.
  (#102 / PR #244)
- **0.11.8** This changelog + `docs/runbook/disaster-recovery.md`.
  (#103 / this PR)

### Audit findings (2026-04-29) closed

- **HIGH-001** End-to-end test for `pim.catalog.enable_custom_object_types`
  feature flag spinning every layer (API guard / service guard /
  REST surface). (PR #239)
- **HIGH-002** Tenant context rebinding on async Messenger handlers.
  `TenantStamp` + `TenantAwareMessage` interface +
  `TenantContextRebindingMiddleware`. Defensive pre-emptive fix
  before Faza 1 async transports. (PR #240)

### Earlier (epic 0.10 — API Configurator MVP)

- **0.10.1** ApiProfile + ApiKey entities + Argon2id hashing + ADR-0016
  documenting key format. CLI `pim:apikey:generate`. (PR #233)
- **0.10.2** Admin UI list / create / edit + AP4 CRUD endpoints + Voters.
  Serializer XML excludes `keyHash` from every group. (PR #234)
- **0.10.3** Form 4-tab structure (Basic / Attributes / Filters /
  Preview) + ObjectType / Attribute multiselect + JSON preview.
  (PR #235)
- **0.10.4** Webhook config — URL / events / Test / Rotate-secret.
  HMAC-signed delivery via `WebhookDeliverySubscriber`. (PR #236)
- **0.10.5** `X-API-Key` authenticator + per-profile serializer
  context. `Shared\Application\Auth\ApiKeyPrincipal` cross-BC
  marker. (PR #237)
- **0.10.6** `/api/profiles/{code}/test` + `/api/profiles/{code}/openapi.json`
  per-profile OpenAPI export. (PR #238)

For older history (epics 0.1 — 0.9), see git log.

[Unreleased]: https://github.com/malipie/PIM/compare/v1.0.0-alpha.1...HEAD
[1.0.0-alpha.1]: https://github.com/malipie/PIM/releases/tag/v1.0.0-alpha.1
