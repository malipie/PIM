# Backlog — Workflow: maszyna stanów, review, zadania (epik WFL)

> **Status:** backlog do realizacji. Utworzony 2026-07-09.
> **Źródło architektury:** rekonesans 2026-07-09 (audyt repo + benchmark Akeneo / Pimcore / inRiver / Salsify / Sales Layer / Ergonode — esencja w §Benchmark niżej) + [`PRD/PRD-PIM-rbac.md`](PRD/PRD-PIM-rbac.md) §3.8 „Workflow-state policy" (stany, macierz per-rola, auto-unpublish) + rezerwacje w planie projektu (§4.2 Faza 1 Track B „Workflow engine", §5.2 Faza 2 Track E „advanced").
> **Decyzja architektoniczna:** ADR-0029 (`docs/adr/0029-workflow-engine-and-placement.md`) — finalizowany w WFL-P0-01. *(Korekta 2026-07-10: 0028 zarezerwowany równolegle przez epik GRID — `0028-attribute-sort-strategy.md`, PR #2408 zmergowany pierwszy; WFL bierze 0029. Weryfikować `git grep ADR-00 origin/main -- '*.md'`, nie tylko `ls docs/adr/`.)*
> **Designy UI:** brak dedykowanego handoffu — FE klonuje istniejące wzorce (agent inbox „Skrzynka", `StatusPill`, `HistoryTable`, dialogi ui-v2, `PillTabs`).
> **Epik label:** `epik-WFL`. Prefix ID: `WFL`, format `WFL-P{faza}-{nn}`.
> **Milestone'y:** M0 Silnik + ADR · M1 Egzekwowanie + RBAC · M2 Zdarzenia + notyfikacje · M3 UI (badge, przejścia, kolejka review) · M4 Zadania · M5 Definicje per tenant (builder) · M6 Hardening + E2E + demo.

Ten plik to **single source of truth** backlogu. GitHub Issues są lustrem (skondensowane body + link tutaj). Tracking faktyczny w Issues + milestone'ach.

**28 ticketów (27 issues + 1 [DEF]), ~210–300h.** Epik to w dużej mierze **obudzenie istniejącej, niepodłączonej warstwy**: komponent `symfony/workflow` NIE jest zainstalowany — dziś istnieje ręczny `CatalogObject::transitionTo()` bez guardów (dowolne przejście przez generyczny PATCH), uśpiony `WorkflowStatePolicy` (RBAC #674 — zaimplementowany + przetestowany, zero produkcyjnych call sites), zaseedowane permissiony `workflow.view/approve_reject/edit_any_state` oraz PRD §3.8 z gotową specyfikacją stanów `draft → review → published → archived` i macierzą per-rola. Substrat do reuse: `pending_changes` (ADR-0024), completeness per kanał/locale (read-model + porty), Mercure, placeholder „Workflow" w sidebarze (`comingSoon: true`).

---

## Benchmark — esencja (rekonesans 2026-07-09)

| PIM | Model | Co bierzemy / czego unikamy |
|---|---|---|
| **Pimcore** | Symfony Workflow + warstwa konfigu: places/transitions, guardy per przejście (expression), dialogi przejścia z komentarzem („notes"), notyfikacje per transition, marking stores na kolumnie obiektu | Potwierdza wybór silnika i wzorzec „dialog z komentarzem → log przejść". Unikamy słabości: brak inboxu zadań (stany widoczne tylko przez raporty), guardy w configu niedostępne dla business userów |
| **Akeneo** | Dwa rozłączne systemy: draft/proposal z partial approval per atrybut (Advanced+) oraz Collaboration Workflows (kroki-zadania, kryteria completeness, due dates, task API `PATCH /workflows/tasks/{uuid}`) | Zadania z przypisaniem + due date; kryteria completeness jako wejście do workflow; API-writable taski. Partial approval per atrybut = Faza 2 (substrat `pending_changes` już jest) |
| **inRiver** | Kroki workflow generują assignmenty jako to-do listę po zalogowaniu | „Moje zadania" jako pierwszorzędny widok (dashboard widget + inbox) |
| **Salsify** | Draft + „final review task" commituje zmiany; AI-generowany content wpada do tej samej kolejki review co ludzki | Hook agent→review (WFL-P4-04 [DEF]) |
| **Sales Layer** | Review Mode = globalny firewall zmian (wszystko, też importy i AI, czeka na approve/discard) | To dosłownie nasz `pending_changes` — mamy substrat ich wyróżnika; nie budujemy drugiego mechanizmu |
| **Ergonode** | Stany per język; warunki przejść: completeness (per language), atrybut ma wartość, rola | Gate completeness na publish. Stany per locale/kanał = świadome odcięcie do Fazy 2 |
| **Rynek — table stakes** | Role-gated transitions · gate completeness na publish · bulk zmiana statusu z grida · komentarze przy przejściach · notification center · audit trail | Wszystko w scope M0–M4 |
| **Whitespace** | SLA / aging-tasks analytics, per-field approval — nikt nie robi porządnie | Faza 2 (plan już rezerwuje Track E); `pending_changes` + Dashboard BC dają tanią ścieżkę |

## Filary projektowe (finalizowane w ADR-0029)

1. **Silnik = `symfony/workflow`**, typ `state_machine`, definicja `object_editorial`; marking store = istniejąca kolumna `objects.status` (method marking store na `CatalogObject`).
2. **Stany:** `draft → review → published → archived`. **Przejścia:** `submit_for_review` (draft→review), `publish` (draft→published, skrót dla ról z approve — solo operator nie cierpi pętli review), `approve` (review→published), `reject` (review→draft), `unpublish` (published→draft), `archive` (draft|published→archived), `restore` (archived→draft).
3. **Placement:** nowy bounded context `apps/api/src/Workflow/` (definicja+guardy, log przejść, zadania) z `Workflow_Contracts`; marking zostaje na `CatalogObject` w Catalog; cross-BC wyłącznie przez `*\Contracts\*` (Deptrac).
4. **Guardy = RBAC**: mapa transition→permission code przez `GuardEvent` listener delegujący do `WorkflowStatePolicy` + `PermissionResolver`; edycja per stan wg PRD §3.8.
5. **Log przejść** (`workflow_transitions`) zamiast wersjonowania — kto/kiedy/skąd-dokąd/komentarz (wzorzec „notes" Pimcore'a). Wersjonowanie produktów = osobny item Fazy 1, poza epikiem.
6. **Gate completeness na publish** — konfigurowalny per `ObjectType`, default OFF (benchmark: nigdzie hard-block domyślnie), oparty o istniejący read-model `objects.completeness`/`completeness_pct`.
7. **Definicje DB-driven per tenant** (M5) za feature flagiem — MVP epiku działa w pełni na jednej seedowanej definicji; M5 jest odcinalne do Fazy 2 bez utraty spójności.
8. **Ścieżki zapisu poza UI:** import/integracje (provenance `import`/`integration`) piszą **niezależnie od stanu** (świadoma decyzja — integracja nie może utknąć na review; odnotowana w ADR); agent ma własny gate `pending_changes` + opcjonalny hook editorial review (WFL-P4-04 [DEF]).

## Świadome odcięcia (poza epik — Faza 2)

- **Per-attribute proposals / partial approval** (Akeneo EE) — substrat `pending_changes` istnieje; wymaga UI diff per pole i resolution rules.
- **Stany per locale/kanał** (Ergonode) — duża zmiana modelu markingu.
- **SLA / aging-tasks analytics** — plan rezerwuje w Fazie 2 Track E („approval chains, SLA tracking").
- **Wersjonowanie produktów (history view, restore)** — osobny item Fazy 1 Track B.
- **Email digest** (weekly recap wzorem Akeneo) — w epiku tylko in-app + Mercure.

---

## Mapa GitHub Issues

_Odwrotny indeks ID → numer (issues #2409–#2436, utworzone 2026-07-10; #2411 zajęty równolegle — poza epikiem)._

| ID | Issue | ID | Issue | ID | Issue |
|---|---|---|---|---|---|
| WFL-P0-01 | #2409 | WFL-P0-02 | #2410 | WFL-P0-03 | #2412 |
| WFL-P0-04 | #2413 | WFL-P0-05 | #2414 | WFL-P1-01 | #2415 |
| WFL-P1-02 | #2416 | WFL-P1-03 | #2417 | WFL-P1-04 | #2418 |
| WFL-P1-05 | #2419 | WFL-P2-01 | #2420 | WFL-P2-02 | #2421 |
| WFL-P2-03 | #2422 | WFL-P3-01 | #2423 | WFL-P3-02 | #2424 |
| WFL-P3-03 | #2425 | WFL-P3-04 | #2426 | WFL-P3-05 | #2427 |
| WFL-P4-01 | #2428 | WFL-P4-02 | #2429 | WFL-P4-03 | #2430 |
| WFL-P4-04 | [DEF] | WFL-P5-01 | #2431 | WFL-P5-02 | #2432 |
| WFL-P5-03 | #2433 | WFL-P6-01 | #2434 | WFL-P6-02 | #2435 |
| WFL-P6-03 | #2436 | | | | |

---

## Konwencje

- **Cls:** `BE` · `FE` · `SEC` (security-first, failing-test-first) · `DOCS`.
- **[PM]:** ticket wymaga Plan Mode — cross-context, decyzja architektoniczna, lub nowa zależność core.
- **[SEC]:** ticket bezpieczeństwa, failing-test-first.
- **[DEF]:** hook świadomie odłożony — decyzja operatora przy realizacji epiku (nie ma issue na starcie).
- **Bounded context:** nowy BC `apps/api/src/Workflow/` (`Workflow_Internals` + `Workflow_Contracts` w deptrac.yaml); marking (kolumna `status`) zostaje na `CatalogObject` w Catalog; enforcement edycji w Identity (voters) + Catalog (processors) przez `Workflow_Contracts`. Finalna decyzja placement w ADR-0029 (WFL-P0-01).
- **Tytuł Issue:** angielski Conventional Commit `{feat|docs|chore|test}(scope): subject`. Body + AC po polsku. Kod po angielsku.

### Standard DoD (każdy ticket, o ile nie zaznaczono inaczej)

- [ ] Acceptance criteria spełnione.
- [ ] **PHPStan max**: 0 errors (BE).
- [ ] **Deptrac**: 0 violations (cross-BC tylko przez Contracts).
- [ ] **PHP-CS-Fixer**: czysto (BE).
- [ ] **Biome strict** + **tsc --noEmit**: 0 errors (FE).
- [ ] **PHPUnit** ≥80% nowej logiki domenowej; **ApiTestCase** dla każdego endpointu (401 + 403 + 404 + walidacja + happy path).
- [ ] **Playwright E2E**: happy path + ≥1 edge case (FE z widoczną zmianą).
- [ ] **axe-core**: 0 violations serious/critical (FE).
- [ ] **Multi-tenancy**: cross-tenant read = 0 wyników (encje TenantScoped, RLS + TenantFilter).
- [ ] **composer audit + pnpm audit**: 0 high/critical.
- [ ] **OpenAPI snapshot** `docs/api-spec/v0.json` zaktualizowany (nowe custom trasy — ADR-0020).
- [ ] **Workflow smoke:** pełna pętla przejścia na `pim.localhost` (login → akcja → 200/201 w Network → widoczny efekt → brak czerwonych errorów w konsoli); PR opis nie używa „działa" bez smoke testu (SMOKE TEST RULE).
- [ ] CI green; PR merged do main.

### Reuse (potwierdzone sygnatury as-is, 2026-07-09)

| Klocek | Ścieżka | Rola w epiku WFL |
|---|---|---|
| Ręczny FSM statusu (`STATUS_DRAFT/PUBLISHED/ARCHIVED`, `transitionTo()`, eventy `ObjectPublished`/`ObjectArchived`) | `apps/api/src/Catalog/Domain/Entity/CatalogObject.php:57-88,293-319` | Kolumna `status` = marking store; `transitionTo()` przechodzi pod kontrolę Symfony Workflow |
| `WorkflowStatePolicy` (`canEditInState()`, `requiresAutoUnpublish()`) + testy unit | `apps/api/src/Identity/Application/Policy/WorkflowStatePolicy.php` | Gotowa polityka edit-in-state — wpinana w guardy (M1), dziś 0 produkcyjnych call sites |
| Permission codes + role templates (`workflow.view/approve_reject/edit_any_state`) | `apps/api/src/DataFixtures/Identity/PrdPermissionFixtures.php:98-100`, `apps/api/src/Identity/Domain/Rbac/PrdRoleTemplates.php` | Doseeding brakujących (`workflow.transition.unpublish`, `workflow.edit_in_review`, `workflow.manage_definitions`) w WFL-P1-01 |
| `#[RequiresPermission]` + `EndpointGuardListener` + PHPStan rule | `apps/api/src/Identity/**` | Gating endpointów workflow |
| `pending_changes` (ADR-0024): encja + Contracts + serwisy + migracja z RLS | `apps/api/src/Catalog/{Domain/Entity/PendingChange.php, Contracts/PendingChanges/**, Application/PendingChanges/**}`, `apps/api/migrations/Version20260702090000.php` | Wzorzec migracji RLS dla `workflow_transitions`/`workflow_tasks`; substrat „request unpublish" i przyszłych per-attribute proposals |
| Agent inbox FE (lista + diff modal + approve/reject) | `apps/admin/src/features/agent/inbox/AgentInboxPage.tsx`, `features/agent/api.ts` | Kalka UI dla kolejki review (M3) |
| Completeness read-model (JSONB + pct + porty raportowe + filtr) | `CatalogObject.completeness/completenessPct`, `apps/api/src/Catalog/Application/Query/{SqlCompletenessReport,SqlChannelCompletenessReport}.php`, `apps/api/src/Catalog/Contracts/Query/**`, `apps/api/src/Catalog/Infrastructure/ApiPlatform/Filter/CompletenessFilter.php`, `docs/api/jsonb-schemas.md` §3 | Gate completeness na publish (WFL-P1-04) + kolumna completeness w kolejce review |
| Filtr statusu na liście | `apps/api/src/Catalog/Infrastructure/ApiPlatform/Filter/StatusFilter.php`, `apps/admin/src/hooks/use-object-list.ts:21,51-52,99-100` | Rozszerzenie o `review`; kolejka review = lista z `?status=review` |
| Bulk substrate (`change_status` jawnie odroczony, mapping pre-exists) | `apps/api/src/Catalog/Presentation/Controller/BulkObjectsController.php:34,117`, `BulkActionsController.php:343-397` | WFL-P1-05 domyka odroczony bulk przez maszynę stanów |
| Mercure + `AbstractBatchHandler` + Messenger transport `import` | `apps/api/src/Shared/**`, wzorce `useFeedRunsStream` / `catalog-runs.{id}` | Live kolejka/zadania; async bulk |
| `CustomRouteOpenApiFactory` (ADR-0020) | `apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php` | Custom trasy workflow → `docs/api-spec/v0.json` |
| Sidebar placeholder + menu registry + gate menu | `apps/admin/src/layout/sidebar-nav.tsx:99-109` (`system:workflow`, `comingSoon: true`), `apps/api/src/Catalog/Domain/SystemMenuItemRegistry.php:67-70,140`, `apps/admin/src/lib/identity/menu-permissions.ts:50-51` (`workflow.view`) | Punkt zaczepienia FE — wymiana placeholdera (WFL-P3-02) |
| Audit (dh-auditor, `audit_logs`) + `Provenance` enum | `apps/api/src/Catalog/Domain/Provenance.php` | Audit przejść + hook agent→review ([DEF]) |
| UI prymitywy | `PillTabs` · `StatusPill` · `HistoryTable` · `EmptyState` · dialogi `apps/admin/src/components/ui-v2/` | Kolejka, timeline, dialogi przejść, taby zadań |
| Dashboard BC (thin read model, ADR-0026) | `apps/api/src/Dashboard/**` | Widget „Moje zadania" / „Do przeglądu" (WFL-P4-03) |
| FE permission catalogue (moduł `workflow` już widoczny w role builder) | `apps/admin/src/features/settings/roles/permission-catalogue.ts:28` | Update o nowe kody permission (WFL-P1-01) |

---

# M0 — Silnik + ADR (fundament)

### WFL-P0-01: docs(architecture): add ADR-0029 for workflow engine and BC placement
- **Typ:** `docs` · **Cls:** DOCS · **Milestone:** M0 · **Est:** 4-6h · **Risk:** low · `[PM]`
- **Blocked by:** — · **Blocks:** WFL-P0-02, WFL-P0-03
- **Po co:** Epik dotyka ≥3 bounded contextów (Catalog — marking na `CatalogObject`; Identity — polityka + permissiony; nowy BC Workflow; pośrednio Dashboard). Wybór silnika, topologii stanów i umiejscowienia musi zapaść raz, autorytatywnie, zanim powstanie kod — inaczej M1 (guardy) i M5 (definicje DB) renegocjują fundament.
- **Stan obecny:** `symfony/workflow` niezainstalowany; ręczny FSM w `CatalogObject` (dowolne przejście, brak guardów); `WorkflowStatePolicy` uśpiony; PRD §3.8 specyfikuje stany + macierz per-rola + auto-unpublish i wprost zakłada Symfony Workflow; 0028 zarezerwowany przez GRID (sort strategy), WFL bierze 0029.
- **Zakres:**
  - Utworzyć `docs/adr/0029-workflow-engine-and-placement.md` wg `docs/adr/adr-template.md` (status Accepted, data 2026-07-09).
  - Sfinalizować filary z nagłówka tego pliku: (1) `symfony/workflow` typ `state_machine`, definicja `object_editorial`, marking store = kolumna `objects.status`; (2) topologia stanów/przejść (w tym skrót `publish` draft→published dla ról z approve); (3) nowy BC `src/Workflow/` + `Workflow_Contracts`, marking w Catalog, enforcement przez Contracts; (4) guardy = RBAC permission map (nie expression w configu — lekcja Pimcore: business user musi móc zarządzać w M5); (5) log przejść zamiast wersjonowania; (6) gate completeness per ObjectType default OFF; (7) definicje DB-driven za feature flagiem (M5, odcinalne); (8) ścieżki zapisu: import/integration piszą niezależnie od stanu, agent przez pending_changes + hook [DEF].
  - Udokumentować konsekwencje: PATCH `status` przestaje być wolnym polem (przejścia tylko przez maszynę — breaking change powierzchni API, wersjonowany w OpenAPI); świadome odcięcia (per-attribute approval, stany per locale/kanał, SLA) z uzasadnieniem.
  - Powiązane ADR: 0012 (CQRS custom routes), 0013 (Deptrac), 0015 (cross-BC bare UUID), 0020 (OpenAPI custom routes), 0024 (pending_changes single gate), 0026 (dashboard read model). Wpis do indeksu `docs/adr/README.md`.
- **Poza zakresem:** implementacja (P0-02+); schema tabel (kierunek, nie kolumny); UI.
- **AC:**
  - [ ] `docs/adr/0029-workflow-engine-and-placement.md` istnieje, status Accepted, zgodny z template.
  - [ ] Jednoznaczne decyzje: silnik+typ+marking store; placement BC; mapa guard→permission; import bypass; definicje DB = M5 za flagą.
  - [ ] Sekcja konsekwencji opisuje breaking change PATCH `status` i plan migracji konsumentów.
  - [ ] „Powiązane ADR" linkuje istniejące pliki 0012/0013/0015/0020/0024/0026.
- **Smoke:** wszystkie decyzje rozstrzygnięte (nie „proponowane"); linki wskazują istniejące pliki; spójność z `deptrac.yaml`.
- **Reuse:** `docs/adr/adr-template.md` · `docs/adr/0023-konfigurator-xml-placement.md` + `0027-catalog-pdf-renderer-port.md` (wzorce placement ADR) · `docs/adr/0024-agent-removable-bc-and-tool-registry.md`.
- **Referencje:** `Project Plan/PRD/PRD-PIM-rbac.md` §3.8 · §Benchmark + §Filary tego pliku.
- **DoD:** standard (docs-only — bez bramek kodowych).

### WFL-P0-02: chore(deptrac): add Workflow bounded context layers
- **Typ:** `chore` · **Cls:** BE · **Milestone:** M0 · **Est:** 3-4h · **Risk:** medium
- **Blocked by:** WFL-P0-01 · **Blocks:** WFL-P0-03, WFL-P0-04
- **Po co:** ADR-0029 ustala nowy BC `src/Workflow/`. Deptrac musi egzekwować granicę CI-owo od dnia 1 — inaczej pierwszy ticket M1 przypadkiem sięgnie do `Catalog\Domain` i utrwali dług.
- **Stan obecny:** `deptrac.yaml` ma wzorzec `X_Internals`/`X_Contracts` per BC (np. `Catalog_Internals`/`Catalog_Contracts`); `src/Workflow/` nie istnieje.
- **Zakres:**
  - Warstwy `Workflow_Internals` (collector `src/Workflow/{Domain,Application,Infrastructure,Presentation}/.*`) i `Workflow_Contracts` (`src/Workflow/Contracts/.*`).
  - Ruleset: `Workflow_Internals` → [`Workflow_Contracts`, `Catalog_Contracts`, `Identity_Contracts`, `Shared`, `Vendor`]; `Workflow_Contracts` → [`Shared`, `Vendor`].
  - Dopuścić `Workflow_Contracts` w dependencies `Catalog_Internals` oraz `Identity_Internals` (enforcement edycji i guardy sięgają portów Workflow) — zgodnie z ADR-0029.
  - Szkielet katalogów `src/Workflow/` + rejestracja w `services.yaml`/bundle wg wzorca istniejących BC.
  - `deptrac` 0 nowych naruszeń; bez wpisów `skip_violations`.
- **Poza zakresem:** jakakolwiek logika domenowa.
- **AC:**
  - [ ] Warstwy + ruleset w `deptrac.yaml` zgodne z ADR-0029; CI deptrac green.
  - [ ] Szkielet `src/Workflow/` z autoloadem działa (`bin/console` bez błędów DI).
- **Smoke:** `vendor/bin/deptrac` w kontenerze `pim-api-1` → 0 violations.
- **Reuse:** wzorzec warstw Feed/Catalog w `deptrac.yaml` (XMLF-P0-02, CPDF-P0-02).
- **DoD:** standard (BE, bez endpointów/FE).

### WFL-P0-03: feat(workflow): install symfony/workflow with object_editorial state machine
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** WFL-P0-02 · **Blocks:** WFL-P0-04, WFL-P0-05
- **Po co:** Serce epiku — podmiana ręcznego, bez-guardowego `transitionTo()` na prawdziwą maszynę stanów z egzekwowaną topologią. Wszystko dalej (guardy, log, UI) wisi na tej definicji.
- **Stan obecny:** `CatalogObject::transitionTo()` (linie 293-319) pozwala na dowolne przejście; `CatalogObjectPatchInput.php:31` przyjmuje `status` z `Assert\Choice(['draft','published','archived'])` — doc-comment fałszywie twierdzi, że „transitionTo() encodes the FSM"; stan `review` nie istnieje.
- **Zakres:**
  - `composer require symfony/workflow` (najnowsza stabilna).
  - `framework.workflows.object_editorial`: type `state_machine`, marking_store method na `CatalogObject::getStatus()/setStatus()`, supports `CatalogObject`; places `draft/review/published/archived`; transitions wg §Filary pkt 2 (`submit_for_review`, `publish`, `approve`, `reject`, `unpublish`, `archive` z draft|published, `restore`).
  - Nowy stan `review`: stała `STATUS_REVIEW` w encji; whitelist w `StatusFilter` + `CatalogObjectPatchInput`; migracja niepotrzebna dla kolumny (string), sprawdzić check constrainty/indeksy.
  - Refactor ścieżki zapisu statusu: `UpdateCatalogObjectHandler` deleguje zmianę `status` z PATCH do `workflow->can()/apply()` — status niezgodny z dozwolonym przejściem → 409 RFC 7807 (breaking change udokumentowany w ADR-0029); `transitionTo()` przestaje być publicznym „wolnym" setterem.
  - Eventy domenowe: reuse `ObjectPublished`/`ObjectArchived`; dorobienie brakujących stubów pod M2 (bez konsumentów).
  - Testy unit: pełna macierz can/apply (dozwolone i zabronione pary stanów).
- **Poza zakresem:** guardy permission (M1); log przejść (P0-04); endpointy przejść (P0-05); UI.
- **AC:**
  - [ ] Workflow `object_editorial` zarejestrowany; `debug:config framework workflows` pokazuje definicję.
  - [ ] Macierz przejść pokryta testami: m.in. draft→archived OK, review→archived ZABRONIONE, published→review ZABRONIONE.
  - [ ] PATCH `status` niezgodny z przejściem → 409 z RFC 7807; zgodny → przejście przez maszynę (wpis eventowy).
  - [ ] `?status=review` filtruje na liście obiektów.
- **Smoke:** curl PATCH draft→published na `pim.localhost` → 200 (przejście `publish` topologicznie dozwolone), published→review → 409.
- **Reuse:** `CatalogObject.php` · `StatusFilter.php` · `CatalogObjectPatchInput.php` · `UpdateCatalogObjectHandler.php`.
- **DoD:** standard.

### WFL-P0-04: feat(workflow): add workflow transition log with tenant RLS
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 6-8h · **Risk:** low
- **Blocked by:** WFL-P0-02 · **Blocks:** WFL-P0-05, WFL-P3-05
- **Po co:** Audytowalna historia decyzji edytorskich (kto zgłosił, kto zatwierdził, z jakim komentarzem) — wzorzec „notes & events" Pimcore'a. Zasila timeline (M3), kolejkę review (kto zgłosił) i automatykę zadań (M4). Świadomie zamiast wersjonowania produktów.
- **Stan obecny:** brak tabeli; audit dh-auditor loguje zmiany encji, ale bez semantyki przejść/komentarzy.
- **Zakres:**
  - Migracja `workflow_transitions`: `id uuid PK`, `tenant_id uuid NOT NULL`, `object_id uuid NOT NULL` (bare UUID bez FK — ADR-0015), `workflow_name`, `transition`, `from_place`, `to_place`, `actor_user_id uuid NULL` (NULL = system), `comment text NULL`, `context jsonb` (np. `auto_unpublish: true`, `agent_run_id`), `created_at`; indeksy (tenant+object+created_at, tenant+created_at); RLS + polityka super-admin bypass (kalka `Version20260702090000.php`); whitelist w TenantAudit (lekcja EXP #607).
  - Encja `WorkflowTransition` w `Workflow/Domain` + repo; port zapisu/odczytu w `Workflow/Contracts`.
  - Listener `workflow.object_editorial.completed` → wpis logu (actor z tokena, komentarz z kontekstu apply).
  - Integration test: przejście → wpis; cross-tenant read = 0.
- **Poza zakresem:** endpoint listy (P0-05); UI timeline (P3-05).
- **AC:**
  - [ ] Każde przejście (PATCH i przyszły endpoint) zostawia wpis z from/to/actor.
  - [ ] Cross-tenant = 0; TenantAudit green; RLS aktywne.
  - [ ] Komentarz i context zapisują się, gdy podane.
- **Smoke:** przejście na `pim.localhost` → `psql` (user `pim`, omija RLS) pokazuje wpis z poprawnym tenant_id.
- **Reuse:** `apps/api/migrations/Version20260702090000.php` (wzorzec RLS) · dh-auditor.
- **DoD:** standard.

### WFL-P0-05: feat(workflow): add transition endpoints with guard-aware discovery
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P0-03, WFL-P0-04 · **Blocks:** WFL-P1-01, WFL-P3-01
- **Po co:** API-first: przejścia to operacje proceduralne (CQRS, ADR-0012) — dedykowane trasy zamiast magii w PATCH. `GET` z listą dozwolonych przejść + powodami blokad to kontrakt, na którym FE zbuduje guard-aware przyciski (M3) bez duplikowania logiki.
- **Stan obecny:** jedyna ścieżka = generyczny PATCH; brak discovery.
- **Zakres:**
  - `POST /api/objects/{id}/workflow/transitions/{transition}` (body: `{comment?: string}`) → `can()` + `apply()` przez Registry; 409 RFC 7807 z `TransitionBlockerList` (kody blokad) gdy niedozwolone; 404 obiekt/transition; zapis logu + komentarza.
  - `GET /api/objects/{id}/workflow` → `{current_place, enabled_transitions: [{name, to, blockers: []}]}` — wszystkie przejścia z definicji + status enabled/blocked z powodami.
  - `GET /api/objects/{id}/workflow/transitions` → log przejść (cursor-based pagination — reguła #9), najnowsze pierwsze.
  - Gating tymczasowy: `GET` → `#[RequiresPermission('workflow','view')]`; `POST` → `#[RequiresPermission('products','edit')]` (zaostrzenie per-transition w WFL-P1-01 — odnotowane w kodzie TODO z numerem ticketu).
  - OpenAPI przez `CustomRouteOpenApiFactory` + snapshot `docs/api-spec/v0.json`; ApiTestCase 401/403/404/409/422 + happy.
- **Poza zakresem:** mapa guard→permission (P1-01); FE.
- **AC:**
  - [ ] POST wykonuje przejście + log + komentarz; niedozwolone → 409 z czytelnymi kodami blokad.
  - [ ] GET zwraca enabled/blocked spójnie z maszyną (test: published → tylko `unpublish`/`archive`).
  - [ ] Log endpoint stronicuje cursor-based; OpenAPI snapshot zaktualizowany (bez driftu).
- **Smoke:** pełna pętla curl na `pim.localhost`: GET → POST submit_for_review → GET (place=review) → POST approve → published.
- **Reuse:** `CustomRouteOpenApiFactory` · wzorce controllerów `Import/Presentation/Controller/ImportSessionStateController.php` (state endpoints) · RFC 7807 infra.
- **DoD:** standard.

# M1 — Egzekwowanie + RBAC (obudzenie uśpionej warstwy)

### WFL-P1-01: feat(identity): map workflow transition guards to RBAC permissions
- **Typ:** `feat` · **Cls:** BE+SEC · **Milestone:** M1 · **Est:** 8-12h · **Risk:** high · `[SEC]`
- **Blocked by:** WFL-P0-05 · **Blocks:** WFL-P1-02, WFL-P1-05, WFL-P3-01
- **Po co:** Bez guardów maszyna egzekwuje tylko topologię — każdy z `products.edit` może approve'ować własne zmiany. Mapa transition→permission realizuje macierz per-rola z PRD §3.8 i jest fundamentem separation-of-duties całego epiku. Failing-test-first.
- **Stan obecny:** permissiony `workflow.view/approve_reject/edit_any_state` zaseedowane; brakuje `workflow.transition.unpublish`, `workflow.edit_in_review`, `workflow.manage_definitions` (PRD linia 220); `WorkflowStatePolicy` przewiduje kody, ale short-circuituje do `false` dla niezaseedowanych.
- **Zakres:**
  - Doseed w `PrdPermissionFixtures` + `PrdRoleTemplates` wg macierzy PRD §3.8: `workflow.transition.unpublish` (Owner/Admin/Approver), `workflow.edit_in_review` (Owner/Admin/Catalog Manager/Approver), `workflow.manage_definitions` (Owner/Admin).
  - `GuardEvent` listener (`workflow.object_editorial.guard`) w `Workflow/Infrastructure`: mapa `submit_for_review→products.edit` · `publish/approve/reject/archive/restore→workflow.approve_reject` · `unpublish→workflow.transition.unpublish`; delegacja do `PermissionResolver` przez `Identity_Contracts`; `TransitionBlocker` z kodem permission przy odmowie.
  - Zdjęcie tymczasowego gatingu POST z P0-05 (guard przejmuje autoryzację; endpoint zostaje za `workflow.view` jako minimum wejścia).
  - Update FE permission catalogue (`permission-catalogue.ts`) o nowe kody (renderują się w role builderze).
  - Failing-test-first: ApiTestCase per rola (10 ról × kluczowe przejścia — marketing nie approve'uje, approver nie edytuje draftu cudzą ścieżką itd.); test że `GET /workflow` zwraca tylko przejścia dozwolone dla danego usera.
- **Poza zakresem:** enforcement edycji pól per stan (P1-02); auto-unpublish (P1-03).
- **AC:**
  - [ ] Macierz PRD §3.8 (per-rola × przejście) pokryta testami; wszystkie zielone.
  - [ ] Odmowa przejścia → 409 z kodem brakującej permission (nie generyczne 403 — user ma wiedzieć czemu).
  - [ ] Role builder w Settings pokazuje nowe kody; snapshot OpenAPI bez zmian kontraktu (guardy nie zmieniają tras).
- **Smoke:** na `pim.localhost` konto restricted (invitation dev-token, `role_code=marketing`): submit_for_review OK, approve → 409/403; admin: approve OK.
- **Reuse:** `WorkflowStatePolicy` · `PermissionResolver` · `PrdRoleTemplates` · wzorzec restricted-user z lekcji `bulk-endpoint-permission-escalation`.
- **DoD:** standard + failing-test-first udokumentowany w PR.

### WFL-P1-02: feat(identity): enforce workflow-state edit policy on write paths
- **Typ:** `feat` · **Cls:** BE+SEC · **Milestone:** M1 · **Est:** 8-12h · **Risk:** high · `[SEC]`
- **Blocked by:** WFL-P1-01 · **Blocks:** WFL-P1-03, WFL-P3-03
- **Po co:** Sedno PRD §3.8: „macierz mówi czy w ogóle może edit, workflow-state mówi czy może edit TERAZ". Bez tego published można edytować mimo review-flow — cała warstwa editorial jest teatrem. To jest moment wpięcia uśpionego `WorkflowStatePolicy` (dziś 0 call sites).
- **Stan obecny:** `WorkflowStatePolicy::canEditInState()` gotowy + testy unit; `CatalogObjectVoter`/`ObjectScopedVoter` istnieją, ale nie delegują; wszystkie ścieżki zapisu (PATCH obiektu, zapis wartości, bulk, Excel grid) ignorują stan.
- **Zakres:**
  - Delegacja z voterów / procesorów do `WorkflowStatePolicy` przez `Workflow_Contracts`/`Identity_Contracts` (zgodnie z ADR-0029): `published` → edycja tylko z `workflow.edit_any_state`; `review` → `workflow.edit_in_review` lub `edit_any_state`; `archived` → read-only twardo (bez wyjątków poza restore); `draft` → bez zmian.
  - Pokrycie WSZYSTKICH ścieżek zapisu wartości/atrybutów/kategorii: `CatalogObjectProcessor`/`UpdateCatalogObjectHandler`, zapis `object_values`, bulk actions (`BulkActionsController` — wszystkie actionType!), Excel-grid path. Lekcja `bulk-endpoint-permission-escalation`: sprawdzać per-action, nie jedną coarse permission.
  - **Świadoma decyzja (ADR-0029):** ścieżki `import`/`integration` (provenance) piszą niezależnie od stanu — integracja nie może utknąć na review; ścieżka agenta bez zmian (własny gate `pending_changes`).
  - 403 RFC 7807 z komunikatem stanu („Produkt opublikowany — wymaga cofnięcia publikacji") — kontrakt dla FE banera (P3-03).
  - Failing-test-first: macierz stan × permission × ścieżka zapisu (PATCH, values, bulk, grid).
- **Poza zakresem:** auto-unpublish (P1-03); UI lock (P3-03).
- **AC:**
  - [ ] Marketing nie zapisze published żadną ścieżką (PATCH/values/bulk/grid) — testy na każdą.
  - [ ] `edit_any_state` (Owner/Admin) edytuje published; `edit_in_review` edytuje review; archived read-only dla wszystkich.
  - [ ] Import na published przechodzi (test integracyjny z provenance=import).
  - [ ] 403 zawiera kod stanu + akcję zalecaną (kontrakt udokumentowany w OpenAPI).
- **Smoke:** restricted user na `pim.localhost`: edycja pola na published → 403 z komunikatem; admin → 200.
- **Reuse:** `WorkflowStatePolicy` (as-is) · `CatalogObjectVoter`/`ObjectScopedVoter` · `EndpointGuardListener`.
- **DoD:** standard + failing-test-first.

### WFL-P1-03: feat(workflow): add auto-unpublish-for-edit with audit flag
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 6-8h · **Risk:** medium
- **Blocked by:** WFL-P1-02 · **Blocks:** WFL-P3-03
- **Po co:** PRD §3.8 + open question (linia 1117, default potwierdzony: auto-transition): user z `workflow.transition.unpublish` edytujący published nie może być zmuszany do dwóch requestów — atomowo unpublish + edit w jednej transakcji, z audytowalnym śladem.
- **Stan obecny:** `WorkflowStatePolicy::requiresAutoUnpublish()` gotowy (short-circuit do false dopóki permission niezaseedowana — P1-01 to odblokowuje).
- **Zakres:**
  - Ścieżka zapisu (z P1-02): edycja published + `workflow.transition.unpublish` → w jednej transakcji: `apply('unpublish')` + zapis edycji; wpis `workflow_transitions` z `context.auto_unpublish=true`; audit log `special_flag=AUTO_UNPUBLISH_FOR_EDIT` (dokładnie wg PRD).
  - Response sygnalizuje przejście (pole `workflow_transitioned: {from, to}` w odpowiedzi PATCH) — FE pokaże toast.
  - Bez permission → 403 z kodem `request_unpublish_available` (hook pod P3-03).
  - Testy: atomowość (fail edycji → rollback przejścia), audit flag, macierz ról.
- **Poza zakresem:** UI (P3-03); request-unpublish flow (P3-03 + P4-02).
- **AC:**
  - [ ] Approver edytuje published jednym requestem; obiekt ląduje w draft; audit + log z flagą.
  - [ ] Rollback przejścia gdy zapis edycji failuje (test transakcyjności).
  - [ ] Marketing → 403 z `request_unpublish_available`.
- **Smoke:** approver na `pim.localhost` edytuje published pole → 200, badge draft, wpis w timeline z flagą auto-unpublish (psql).
- **Reuse:** `WorkflowStatePolicy::requiresAutoUnpublish()` · transakcje Doctrine w `PendingBatchCommitter` (wzorzec atomowości).
- **DoD:** standard.

### WFL-P1-04: feat(workflow): add completeness gate on publish transitions
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** WFL-P1-01 · **Blocks:** —
- **Po co:** Table stakes rynkowy (Ergonode conditions, Akeneo entry criteria, Salsify readiness): publikacja niekompletnego produktu to główny błąd, przed którym PIM ma chronić. Gate na istniejącym read-modelu completeness — zero nowej matematyki.
- **Stan obecny:** completeness liczona i denormalizowana (`objects.completeness` JSONB + `completeness_pct`, kontrakt `docs/api/jsonb-schemas.md` §3); porty raportowe istnieją; żaden mechanizm nie gate'uje publikacji.
- **Zakres:**
  - Konfiguracja per `ObjectType`: pole `workflow_publish_gate` JSONB `{enabled: bool, min_completeness_pct: int, scope: 'global'|'per_channel', channels?: []}` — **default OFF** (benchmark: nigdzie hard-block domyślnie); update kontraktu w `docs/api/jsonb-schemas.md`.
  - Guard listener na `publish` i `approve`: gate ON → sprawdzenie `completeness_pct` (global) lub `completeness.per_channel` (scope per_channel); poniżej progu → `TransitionBlocker` z listą brakujących atrybutów (reuse `CompletenessReportPort`).
  - 409 RFC 7807 z payloadem braków (atrybut + locale/channel) — kontrakt dla FE tooltipa (P3-01).
  - API do ustawiania gate: przez istniejący CRUD `ObjectType` (API Platform resource) — walidacja shape'u JSONB.
  - Testy: gate off = bez zmian; on global; on per_channel; edge: brak rules → completeness 100 (fallback z kontraktu §3).
- **Poza zakresem:** UI konfiguracji gate w builderze (M5); UI tooltipa (P3-01).
- **AC:**
  - [ ] Gate OFF (default) → zachowanie bez zmian (testy regresji przejść).
  - [ ] Gate ON: publish/approve poniżej progu → 409 z listą braków per atrybut; ≥ progu → przechodzi.
  - [ ] Scope per_channel czyta `completeness.per_channel` zgodnie z kontraktem §3 (optional-safe).
  - [ ] `docs/api/jsonb-schemas.md` zaktualizowany o `workflow_publish_gate`.
- **Smoke:** ustaw gate 100% na Product przez API, obiekt z brakiem → approve 409 z listą; uzupełnij pole → approve 200.
- **Reuse:** `SqlCompletenessReport`/`CompletenessReportPort` · `objects.completeness_pct` · kontrakt JSONB §3.
- **DoD:** standard.

### WFL-P1-05: feat(catalog): wire bulk change_status through the state machine
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** WFL-P1-01 · **Blocks:** WFL-P3-04
- **Po co:** Bulk zmiana statusu to najczęstsza operacja workflow w praktyce (benchmark: wszyscy ją mają) — i jest już JAWNIE odroczona w kodzie (`BulkObjectsController.php:34,117`, mapping pre-exists „for follow-up"). Musi iść przez maszynę per obiekt, żeby nie powstała ścieżka obejścia guardów.
- **Stan obecny:** `change_status` zmapowany, nie zaimplementowany; zaimplementowane bulk actions nie dotykają statusu; lekcja audytu: bulk gated jedną coarse permission = eskalacja.
- **Zakres:**
  - Implementacja `change_status` w bulk path: input `{transition, comment?}` (transition, NIE docelowy status — semantyka maszyny); per obiekt: `can()` (topologia + guardy + gate completeness) → `apply()`; wynik per obiekt `{id, ok, blockers[]}`.
  - Autoryzacja per-action: wymóg identyczny jak pojedyncze przejście (guard per obiekt) — żadnej zbiorczej „bulk permission" (lekcja `bulk-endpoint-permission-escalation`).
  - Async dla batchy > N (próg konfigurowany, np. 200): kolejka `import` + `TenantAwareMessage` + `AbstractBatchHandler` (batch 200 + `clear()` — reguła memory management); wynik przez Mercure (wzorzec `catalog-runs.{id}`).
  - Wpisy `workflow_transitions` per obiekt (actor = inicjator bulka, context `bulk: true`).
  - Testy: mixed states (część przechodzi, część blokowana — raport per obiekt), eskalacja (marketing bulk approve → wszystkie blocked), async path (drain in-memory transport w CI — lekcja `ci-inmemory-messenger-drain`).
- **Poza zakresem:** FE (P3-04).
- **AC:**
  - [ ] Bulk 100 obiektów draft→review działa; obiekt z blokadą failuje pojedynczo, nie blokując reszty; raport per obiekt z powodami.
  - [ ] Marketing NIE wykona bulk approve (test eskalacji per-action).
  - [ ] Async > progu: job w kolejce `import`, wynik przez Mercure, memory-safe (batch+clear).
- **Smoke:** bulk submit_for_review 20 produktów z grida curl-em → raport 20 ok; bulk approve restricted userem → 100% blocked.
- **Reuse:** `BulkObjectsController` (mapping) · `AbstractBatchHandler` · lekcja `export-async-dev-worker-queue` (kolejka `import` w dev workerze).
- **DoD:** standard.

---

# M2 — Zdarzenia + notyfikacje

### WFL-P2-01: feat(workflow): emit transition domain events with Mercure topics
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 6-8h · **Risk:** low
- **Blocked by:** WFL-P0-05 · **Blocks:** WFL-P2-02, WFL-P3-02
- **Po co:** Live kolejka review i live zadania (M3/M4) potrzebują zdarzeń przejść na Mercure; notyfikacje (P2-02) potrzebują eventów domenowych. Jeden listener, dwa konsumenty.
- **Stan obecny:** `ObjectPublished`/`ObjectArchived` recordowane w encji; brak eventów dla pozostałych przejść; Mercure działa (feed/catalog/agent runs).
- **Zakres:**
  - Komplet eventów przejść: `ObjectSubmittedForReview`, `ObjectRejected` (z komentarzem), `ObjectUnpublished`, `ObjectRestored` (+ reuse `ObjectPublished`/`ObjectArchived`); payload: object id+label, from, to, actor, comment, context.
  - Listener `workflow.completed` → dispatch eventów + publikacja Mercure: topic `workflow/{tenantId}` (kolejka review, listy) i `objects/{id}/workflow` (detal produktu).
  - Testy integracyjne: przejście → event + hub message (transport in-memory, drain — lekcja CI).
- **Poza zakresem:** persystencja notyfikacji (P2-02); FE (P2-03/M3).
- **AC:**
  - [ ] Każde z 7 przejść emituje event z pełnym payloadem.
  - [ ] Mercure message na obu topicach przy przejściu (test integracyjny).
- **Smoke:** `curl -N` na topic `workflow/{tenant}` podczas przejścia w drugiej sesji → event widoczny.
- **Reuse:** infra Mercure + wzorce topiców `catalog-runs.{id}` · `recordThat()` w `CatalogObject`.
- **DoD:** standard.

### WFL-P2-02: feat(notifications): add persistent in-app notifications API
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P2-01 · **Blocks:** WFL-P2-03, WFL-P4-02
- **Po co:** Table stakes (benchmark: wszyscy poza Plytix): „zgłoszono do review" musi dotrzeć do approverów, „odrzucono z komentarzem" do autora — także gdy są offline (Mercure jest ulotny). Infra celowo generyczna (type+payload), bo M4 (zadania) i przyszłe epiki będą emitować własne typy.
- **Stan obecny:** brak persystentnych notyfikacji; jedynie ulotne SSE.
- **Zakres:**
  - Mini-BC `apps/api/src/Notification/` (Internals+Contracts, deptrac — precedens thin BC: Dashboard/ADR-0026): encja `Notification` (`id, tenant_id, user_id, type, payload jsonb, read_at, created_at`) + migracja RLS + TenantAudit whitelist; indeks (tenant+user+read_at).
  - `NotifierPort` w Contracts: `notify(userIds|roleCode, type, payload)`; resolve „userzy z rolą/permission" przez `Identity_Contracts` (potrzebny port `UsersWithPermissionPort` — dodać, jeśli brak).
  - Endpointy: `GET /api/notifications` (cursor, filtr unread), `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`; `#[RequiresPermission]` niepotrzebny poza auth (własne dane usera — filtr po user_id z tokena, IDOR-test!).
  - Emisja workflow: `submit_for_review` → notyfikacja dla userów z `workflow.approve_reject`; `approve`/`reject` → dla autora submitu (ostatni `submit_for_review` z `workflow_transitions`); payload z object id+label+komentarzem.
  - Mercure topic per user `users/{id}/notifications` (unread count + nowa notyfikacja).
  - OpenAPI + ApiTestCase (w tym IDOR: user A nie czyta notyfikacji usera B).
- **Poza zakresem:** FE dzwonek (P2-03); email (świadome odcięcie); notyfikacje zadań (P4-02 emituje przez ten port).
- **AC:**
  - [ ] Submit → approverzy mają notyfikację (persystentna, przeżywa relogin); reject → autor ma, z komentarzem.
  - [ ] Mark-read/read-all działa; unread count spójny; IDOR-test green; cross-tenant 0.
  - [ ] Mercure user-topic dostaje event przy emisji.
- **Smoke:** dwie sesje na `pim.localhost` (marketing + admin): submit marketingiem → notyfikacja u admina po reloginie.
- **Reuse:** wzorzec thin BC Dashboard (ADR-0026) · migracja RLS `Version20260702090000.php` · Mercure.
- **DoD:** standard.

### WFL-P2-03: feat(admin): add notification bell with live updates
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M2 · **Est:** 8-10h · **Risk:** low
- **Blocked by:** WFL-P2-02 · **Blocks:** —
- **Po co:** Notyfikacje bez UI nie istnieją. Dzwonek w top barze to standardowy pattern (benchmark: notification center wszędzie) — i pierwsza widoczna wartość epiku dla operatora.
- **Stan obecny:** top bar bez dzwonka; brak komponentu.
- **Zakres:**
  - Dzwonek w top barze: badge unread count (GET + live z Mercure `users/{id}/notifications`); dropdown: ostatnie 20, tytuł/typ/relative time, klik → nawigacja do obiektu (route z payload) + mark-read; „oznacz wszystkie".
  - Toast przy nowej notyfikacji w trakcie sesji (istniejący system toastów).
  - i18n pl/en przez `t()`; axe (focus trap w dropdownie, aria-live dla countu — uwaga na kontrast zinc, lekcja `fe-axe-playwright-gotchas`).
  - Playwright: notyfikacja pojawia się live (druga sesja robi submit), klik nawiguje.
- **Poza zakresem:** strona „wszystkie notyfikacje" (dropdown wystarcza w MVP epiku); preferencje per user.
- **AC:**
  - [ ] Badge live bez refresh; klik → produkt + read; read-all zeruje badge.
  - [ ] axe 0 serious/critical; działa po polsku (seed `i18nextLng=pl` w testach).
- **Smoke:** pełna pętla na `pim.localhost` z dwiema sesjami wg SMOKE TEST RULE (Network 200, wynik widoczny, konsola czysta).
- **Reuse:** wzorce Mercure FE (`useFeedRunsStream`) · toasty · dropdown ui-v2.
- **DoD:** standard.

---

# M3 — UI: badge, przejścia, kolejka review

### WFL-P3-01: feat(admin): add status badge and transition actions on product detail
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P0-05, WFL-P1-01 · **Blocks:** WFL-P3-03
- **Po co:** Stan edytorski musi być widoczny i sterowalny tam, gdzie użytkownik pracuje — na karcie produktu (wzorzec Pimcore: chip stanu + przyciski przejść w headerze). Guard-aware discovery z `GET /workflow` = przyciski zawsze zgodne z uprawnieniami, zero duplikacji logiki na FE.
- **Stan obecny:** product detail bez kontrolki statusu (status = zwykłe pole filtru na liście); brak `StatusBadge` dla produktów.
- **Zakres:**
  - Badge statusu w headerze product detail (`StatusPill`: draft szary, review bursztyn, published zielony, archived czerwony/wyszarzony) + kolumna/badge na liście produktów.
  - Przyciski przejść z `GET /api/objects/{id}/workflow`: enabled → aktywne; blocked → disabled z tooltipem powodów (blockers: brakująca permission / gate completeness z listą braków — kontrakt z P1-04).
  - Dialog przejścia: komentarz (opcjonalny; **wymagany dla `reject`**), potwierdzenie; po sukcesie refetch + toast; obsługa `workflow_transitioned` z PATCH (auto-unpublish toast, P1-03).
  - i18n pl/en; axe; Playwright: happy (submit→approve adminem) + edge (przycisk approve niewidoczny/disabled dla marketing — role locators, lekcja gotchas).
- **Poza zakresem:** lock UX published (P3-03); kolejka (P3-02); timeline (P3-05).
- **AC:**
  - [ ] Badge odzwierciedla stan live (Mercure `objects/{id}/workflow` lub refetch po akcji).
  - [ ] Przyciski = dokładnie enabled_transitions z API; blocked z czytelnym tooltipem po polsku.
  - [ ] Reject bez komentarza niemożliwy (walidacja w dialogu).
- **Smoke:** SMOKE TEST RULE na `pim.localhost`: klik submit → 200 → badge review → konsola czysta.
- **Reuse:** `StatusPill` · dialogi ui-v2 · `use-object-list` (badge na liście).
- **DoD:** standard.

### WFL-P3-02: feat(admin): add workflow review queue page
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P3-01, WFL-P2-01 · **Blocks:** WFL-P4-03
- **Po co:** Wyróżnik vs Pimcore (który nie ma inboxu — stany widać tylko przez raporty): approver po zalogowaniu widzi JEDNO miejsce z całą pracą do przejrzenia. Sidebar „Workflow" przestaje być wydmuszką (`comingSoon` od miesięcy).
- **Stan obecny:** `sidebar-nav.tsx:99-109` — `system:workflow`, `route: null`, `comingSoon: true`; menu już gated `workflow.view`; brak route'a `/workflow`.
- **Zakres:**
  - Route `/workflow` + zdjęcie `comingSoon` (sidebar-nav + `SystemMenuItemRegistry` BE); layout z `PillTabs` (zakładka „Do przeglądu"; „Zadania" dojdą w P4-03).
  - Kolejka: lista obiektów `?status=review` (reuse `use-object-list`) — kolumny: nazwa (link do detalu), ObjectType, kto zgłosił + kiedy + komentarz (ostatni `submit_for_review` z log endpointu), completeness (pct z listy), akcje approve/reject (dialog z komentarzem — reuse z P3-01).
  - Bulk-select approve/reject (bulk endpoint P1-05) z raportem wyników (modal fail/success per obiekt).
  - Live: Mercure `workflow/{tenant}` → refetch listy (pozycje znikają po decyzji z innej sesji); `EmptyState` gdy pusto.
  - i18n; axe; Playwright: approve z kolejki znika z listy; marketing nie widzi akcji approve.
- **Poza zakresem:** zadania (M4); saved views kolejki.
- **AC:**
  - [ ] `/workflow` renderuje kolejkę z metadanymi zgłoszenia i completeness; sidebar bez `comingSoon`.
  - [ ] Approve/reject inline + bulk działają; lista odświeża się live z drugiej sesji.
  - [ ] Menu niewidoczne bez `workflow.view` (test).
- **Smoke:** SMOKE TEST RULE: submit z sesji A → pojawia się w kolejce sesji B bez refresh → approve → znika.
- **Reuse:** `AgentInboxPage` (kalka layoutu inbox) · `HistoryTable` · `PillTabs` · `use-object-list`.
- **DoD:** standard.

### WFL-P3-03: feat(admin): add published edit lock with request-unpublish flow
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** WFL-P1-02, WFL-P1-03 · **Blocks:** —
- **Po co:** Enforcement z P1-02 bez UX = ściana niezrozumiałych 403. PRD §3.8 definiuje dokładny flow: modal „Produkt opublikowany. Skontaktuj się z Approver…" + button „Request unpublish". Marketing rozumie CO się dzieje i CO może zrobić.
- **Stan obecny:** formularz produktu nie zna stanów — po P1-02 dostawałby gołe 403.
- **Zakres:**
  - Product detail w `published` (user bez `edit_any_state`/`unpublish`): pola read-only + banner „Produkt opublikowany — edycja wymaga cofnięcia publikacji" + button **Request unpublish** → dialog z komentarzem → notyfikacja do approverów (P2-02, type `unpublish_requested`) + wpis w `workflow_transitions.context` (request, nie przejście). *(Upgrade do zadania w P4-02.)*
  - User z `workflow.transition.unpublish`: button **Unpublish & edit** → odblokowanie formularza (auto-unpublish przy zapisie, P1-03) + toast o przejściu.
  - `review`: banner „W przeglądzie" — formularz read-only bez `edit_in_review`; `archived`: read-only + Restore dla uprawnionych.
  - Obsługa 403 z kodami z P1-02 (fallback gdy stan zmienił się race'owo).
  - i18n; axe; Playwright: marketing widzi banner + request; approver unpublish&edit.
- **Poza zakresem:** zadanie request_unpublish (P4-02 — tu tylko notyfikacja).
- **AC:**
  - [ ] Published dla marketing: pola disabled, banner, Request unpublish wysyła i potwierdza toastem.
  - [ ] Approver: Unpublish & edit działa jednym flow; badge po zapisie = draft.
  - [ ] Review/archived pokazują właściwe bannery wg permission.
- **Smoke:** SMOKE TEST RULE dwiema rolami wg scenariusza PRD.
- **Reuse:** kody błędów z P1-02/P1-03 · `NotifierPort` (P2-02) · bannery/dialogi ui-v2.
- **DoD:** standard.

### WFL-P3-04: feat(admin): add bulk status transitions from product grid
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 6-10h · **Risk:** low
- **Blocked by:** WFL-P1-05, WFL-P3-01 · **Blocks:** —
- **Po co:** Segment-driven workflow (benchmark: uniwersalny idiom „filtr = work queue"): odfiltruj 50 draftów gotowych do publikacji → zaznacz → submit jednym ruchem.
- **Stan obecny:** `BulkActionsToolbar` w gridzie obsługuje istniejące bulk actions; `change_status` dochodzi w P1-05.
- **Zakres:**
  - Akcja „Zmień status" w `BulkActionsToolbar`: wybór przejścia (lista przejść maszyny; disabled bez sensu dla selekcji) + komentarz → bulk endpoint (P1-05).
  - Raport wyników: modal per obiekt (ok/blocked + powody z blockers) — wzorzec raportu z P1-05; refetch grida po zamknięciu.
  - Async path (>próg): toast „w toku" + wynik przez Mercure.
  - i18n; axe; Playwright: mixed selection (draft+published) → raport pokazuje częściowe blokady.
- **Poza zakresem:** zapisy segmentów jako work-queue (Faza 2 — smart lists).
- **AC:**
  - [ ] Bulk submit_for_review z grida działa; raport per obiekt czytelny po polsku.
  - [ ] Selekcja mixed states nie blokuje całości; wyniki jasno rozdzielone.
- **Smoke:** SMOKE TEST RULE: 5 draftów → bulk submit → 5× review w gridzie.
- **Reuse:** `BulkActionsToolbar` · raport wyników bulk (istniejące wzorce bulk edit).
- **DoD:** standard.

### WFL-P3-05: feat(admin): add workflow history timeline on product detail
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M3 · **Est:** 6-8h · **Risk:** low
- **Blocked by:** WFL-P0-04, WFL-P0-05 · **Blocks:** —
- **Po co:** Audyt decyzji edytorskich dostępny tam, gdzie toczy się praca (wzorzec Notes & Events Pimcore'a): kto zgłosił, kto odrzucił i dlaczego — bez grzebania w bazie.
- **Stan obecny:** log w `workflow_transitions` + endpoint cursor (P0-05); brak UI.
- **Zakres:**
  - Sekcja/zakładka „Historia workflow" na product detail: timeline z `GET /api/objects/{id}/workflow/transitions` — avatar/nazwa aktora („System" dla NULL), przejście (from→to z ikonami per typ), relative time, komentarz, badge `auto-unpublish`/`bulk` z context.
  - Paginacja cursor („pokaż starsze"); live append z Mercure `objects/{id}/workflow`.
  - i18n; axe.
- **Poza zakresem:** historia zmian wartości (to audit/wersjonowanie — poza epikiem).
- **AC:**
  - [ ] Timeline pokazuje wszystkie przejścia z komentarzami; „pokaż starsze" dociąga kolejne strony.
  - [ ] Wpis auto-unpublish ma wyróżnik wizualny.
- **Smoke:** SMOKE TEST RULE: wykonaj 3 przejścia → timeline pokazuje 3 wpisy w dobrej kolejności.
- **Reuse:** `HistoryTable`/timeline wzorce · endpoint z P0-05.
- **DoD:** standard.

# M4 — Zadania (workflow tasks)

### WFL-P4-01: feat(workflow): add workflow tasks entity and API
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P0-04 · **Blocks:** WFL-P4-02, WFL-P4-03
- **Po co:** Przewaga nad Pimcore i esencja Akeneo/inRiver: praca ma trafiać DO ludzi (assignment + due date + inbox), a nie czekać aż ktoś sam znajdzie obiekty w stanie X. Encja + API najpierw, automatyka i UI osobno.
- **Stan obecny:** brak modelu zadań; PRD §3.7: „Workflow tasks — visible dla wszystkich z permission (workflow jest kolaboracyjne)" — bez ownership semantics.
- **Zakres:**
  - Migracja `workflow_tasks`: `id, tenant_id, object_id uuid NULL` (bare UUID, ADR-0015), `title`, `type` enum (`review|fix|request_unpublish|custom`), `assignee_user_id uuid NULL`, `assignee_role_code text NULL` (user LUB rola), `due_date date NULL`, `status` enum (`open|done|cancelled`), `created_by`, `resolved_by`, `resolved_at`, `comment`, `context jsonb`, timestamps; RLS + TenantAudit whitelist; indeksy (tenant+status+due, tenant+assignee_user, tenant+object).
  - Encja + repo w `Workflow/Domain`; port w `Workflow/Contracts` (tworzenie z listenerów P4-02).
  - API: `GET /api/workflow/tasks` (cursor; filtry: `mine` [user LUB jego role], `status`, `object_id`, `due_before`), `POST /api/workflow/tasks` (manualne, `workflow.view` + `products.edit`), `PATCH /api/workflow/tasks/{id}` (`complete|cancel|reassign` — assignee lub `workflow.approve_reject`); RFC 7807; OpenAPI + snapshot.
  - ApiTestCase: 401/403/404 + IDOR (complete cudzego bez permission = 403) + `mine` z rolą.
- **Poza zakresem:** automatyka (P4-02); FE (P4-03); SLA/eskalacje (Faza 2).
- **AC:**
  - [ ] CRUD + complete/cancel/reassign działa wg macierzy uprawnień; `mine` łączy user + role assignments.
  - [ ] Cross-tenant 0; IDOR-testy green; OpenAPI bez driftu.
- **Smoke:** curl: create → list mine → complete → status done.
- **Reuse:** wzorzec RLS/migracji z P0-04 · cursor pagination (reguła #9).
- **DoD:** standard.

### WFL-P4-02: feat(workflow): automate task lifecycle on transitions
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 6-10h · **Risk:** medium
- **Blocked by:** WFL-P4-01, WFL-P2-02 · **Blocks:** WFL-P4-03
- **Po co:** Zadania tworzone ręcznie nikt nie będzie tworzył — wartość jest w automatyce (wzorzec Akeneo: krok workflow = task; inRiver: assignment przy trigger). Pętla: submit tworzy pracę, decyzja ją domyka, reject oddaje autorowi.
- **Stan obecny:** eventy przejść (P2-01), taski (P4-01), notyfikacje (P2-02) — do spięcia.
- **Zakres:**
  - Listener eventów przejść: `submit_for_review` → task `review` (assignee_role_code = rola z `workflow.approve_reject`; title z labelem obiektu; context z komentarzem zgłoszenia); `approve`/`reject` → auto-complete otwartego tasku `review` dla obiektu; `reject` → dodatkowo task `fix` dla autora submitu (assignee_user_id; komentarz rejecta w context); `unpublish` → auto-complete otwartego `request_unpublish`.
  - Request-unpublish (P3-03): upgrade notyfikacji do tasku `request_unpublish` (assignee_role_code z `workflow.transition.unpublish`).
  - Idempotencja: nie duplikować otwartego tasku (obiekt+typ); powtórny submit reuse'uje istniejący.
  - Notyfikacja przy utworzeniu tasku przez `NotifierPort` (typ `task_assigned`).
  - Testy integracyjne pełnej pętli: submit→task; reject→complete+fix; approve→complete.
- **Poza zakresem:** due dates z automatu (manualne w MVP; auto-SLA = Faza 2); FE.
- **AC:**
  - [ ] Pełna pętla: submit tworzy task review; approve domyka; reject domyka + tworzy fix dla autora z komentarzem.
  - [ ] Brak duplikatów przy powtórnym submit (test idempotencji).
  - [ ] Assignee dostaje notyfikację.
- **Smoke:** na `pim.localhost`: submit marketingiem → task widoczny w API admina; approve → task done.
- **Reuse:** eventy P2-01 · `NotifierPort` P2-02 · port tasków P4-01.
- **DoD:** standard.

### WFL-P4-03: feat(admin): add my-tasks inbox and dashboard widget
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M4 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P4-02, WFL-P3-02 · **Blocks:** —
- **Po co:** Wzorzec inRiver: użytkownik po zalogowaniu widzi SWOJĄ pracę. Inbox zadań + widget na dashboardzie zamykają pętlę współpracy — bez tego taski są niewidzialnym API.
- **Stan obecny:** `/workflow` ma zakładkę „Do przeglądu" (P3-02); dashboard bez widgetu zadań.
- **Zakres:**
  - Zakładki na `/workflow` (`PillTabs`): **Do przeglądu** (P3-02) · **Moje zadania** (`?mine=1&status=open`: typ z ikoną, tytuł→link do obiektu, due date z wyróżnikiem przeterminowanych, komentarz z context; akcje complete/cancel z dialogiem) · **Wszystkie zadania** (dla `workflow.approve_reject`; filtry status/typ/assignee; reassign).
  - Widget dashboardu „Moje zadania": count open + top 5 wg due date, link do inboxu (wzorzec widgetów Dashboard BC; prosty odczyt z API tasków — bez nowego read modelu, chyba że ADR-0026 wymusi).
  - Live: Mercure (`users/{id}/notifications` jako trigger refetch lub topic `workflow/{tenant}`).
  - i18n; axe; Playwright: task pojawia się po submit z drugiej sesji; complete znika z „Moje".
- **Poza zakresem:** widoki kanban per stan (Faza 2); due-date przypomnienia email.
- **AC:**
  - [ ] „Moje zadania" pokazuje user+rola assignments; przeterminowane wyróżnione; complete działa.
  - [ ] Widget na dashboardzie liczy open i linkuje; live update bez refresh.
- **Smoke:** SMOKE TEST RULE dwiema sesjami: submit → task u approvera (dashboard + inbox) → complete.
- **Reuse:** `PillTabs` · layout P3-02 · wzorce widgetów Dashboard.
- **DoD:** standard.

### WFL-P4-04 `[DEF]`: feat(workflow): route agent-provenance writes through review state
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 4-6h · **Risk:** low · `[DEF]` — bez issue na starcie epiku
- **Blocked by:** WFL-P4-02 · **Blocks:** —
- **Po co:** Wzorzec Salsify/Sales Layer: AI-generowany content wpada do TEJ SAMEJ kolejki review co ludzki. Spina epik z agentem (0.7) i przyszłym AICG.
- **Dlaczego [DEF]:** agent MA już własny gate (`pending_changes` — human approve przed commitem, ADR-0024). Hook dodawałby DRUGĄ warstwę (editorial review po commicie) — do decyzji operatora przy realizacji epiku, czy to nie double-gate. Jeśli AICG (bulk generacja) wyląduje przed WFL, hook zyskuje sens dla ścieżek bulk-AI.
- **Zakres (gdy aktywowany):** config flag per tenant `workflow_agent_review` (default OFF); commit `pending_changes` z provenance=`agent` na obiekcie w `draft` → auto `submit_for_review` (actor=system, `context.agent_run_id`); notyfikacja/task standardową automatyką P4-02; testy: flag OFF = bez zmian.
- **AC:** flag ON → obiekt po agent-commit ląduje w review z taskiem; flag OFF → zachowanie dzisiejsze.
- **Reuse:** `PendingBatchCommitter` (punkt zaczepienia) · `Provenance` enum · automatyka P4-02.
- **DoD:** standard.

---

# M5 — Definicje workflow per tenant (builder) — feature flag; kandydat do wydzielenia do Fazy 2

> **Uwaga scope:** M0–M4 dostarczają pełny, spójny produkt na JEDNEJ seedowanej definicji. M5 realizuje PRD-owe `manage_workflow_definitions` (builder stanów/przejść per tenant) za feature flagiem `workflow_custom_definitions` (default OFF). Jeśli priorytety się zmienią, M5 wycina się w całości do Fazy 2 bez ruszania M0–M4 — plan projektu i tak rezerwuje tam „customowe workflow per tenant".

### WFL-P5-01: feat(workflow): add database-driven workflow definitions with registry loader
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M5 · **Est:** 12-16h · **Risk:** high · `[PM]`
- **Blocked by:** WFL-P1-01 · **Blocks:** WFL-P5-02, WFL-P5-03
- **Po co:** Lekcja Pimcore (guardy w configu = deploy na każdą zmianę reguł, visual designer = płatny dodatek): definicje w DB per tenant to nasz wyróżnik w multi-tenant SaaS. Runtime loader to najtrudniejszy technicznie kawałek epiku — stąd [PM].
- **Stan obecny:** definicja `object_editorial` statycznie w YAML (P0-03).
- **Zakres:**
  - Encja `WorkflowDefinition` (`tenant_id, name, object_type_id uuid NULL` [NULL = wszystkie], `places jsonb` [{name,label,color}], `transitions jsonb` [{name,from,to,permission,comment_required,completeness_gate?}], `is_default, enabled`) + migracja RLS.
  - Walidator spójności: initial place istnieje, wszystkie stany osiągalne, brak orphan transitions, unikalne nazwy, permission code istnieje w katalogu; ZAKAZ usunięcia stanu, w którym są obiekty (bez zmapowanej migracji stanów).
  - Loader: `TenantWorkflowRegistry` budujący `Workflow` w runtime z definicji DB (cache per tenant + inwalidacja po zapisie definicji — uwaga FrankenPHP worker mode: cache w pamięci procesu musi się inwalidować cross-worker, np. przez cache pool z wersją).
  - Feature flag `workflow_custom_definitions` (default OFF → YAML jak dotąd); seed definicji domyślnej = odwzorowanie YAML (single source po włączeniu flagi).
  - Guard listener + gate completeness czytają parametry z definicji (permission per transition, comment_required, gate) — refactor P1-01/P1-04 na parametry.
  - Testy: walidator (każda reguła), loader z cache, flag OFF regresja pełnej macierzy przejść.
- **Poza zakresem:** CRUD API (P5-02); UI (P5-03); migrator stanów między definicjami (odmowa wystarczy w MVP).
- **AC:**
  - [ ] Flag OFF → zachowanie w 100% identyczne (regresja M0–M4 green).
  - [ ] Flag ON → definicja z DB steruje: dodany stan `translation` z przejściami działa end-to-end.
  - [ ] Walidator odrzuca definicje niespójne (testy per reguła); usunięcie stanu z obiektami niemożliwe.
  - [ ] Cache inwaliduje się po edycji definicji bez restartu workera.
- **Smoke:** flag ON + edycja definicji przez psql/API → nowy stan widoczny w `GET /workflow` bez restartu.
- **Reuse:** guard/gate z P1-01/P1-04 (parametryzacja) · wzorce cache Shared.
- **DoD:** standard.

### WFL-P5-02: feat(workflow): add workflow definitions CRUD API
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M5 · **Est:** 6-8h · **Risk:** low
- **Blocked by:** WFL-P5-01 · **Blocks:** WFL-P5-03
- **Po co:** API-first — builder UI (P5-03) i przyszli integratorzy dostają ten sam kontrakt.
- **Zakres:**
  - Endpointy: `GET /api/workflow/definitions` (+`/{id}`), `POST`, `PUT /{id}`, `POST /{id}/enable|disable` — wszystkie `#[RequiresPermission('workflow','manage_definitions')]`; walidacja przez walidator z P5-01 (422 z listą błędów per pole); OpenAPI + snapshot.
  - ApiTestCase: 401/403 (marketing/approver bez manage), 422 (definicja niespójna), happy; cross-tenant 0.
- **AC:**
  - [ ] CRUD pełny za permission; 422 wskazuje konkretny błąd definicji; OpenAPI bez driftu.
- **Smoke:** curl: create definicji z nowym stanem → enable → `GET /workflow` obiektu pokazuje nowe przejścia.
- **Reuse:** walidator P5-01 · `CustomRouteOpenApiFactory`.
- **DoD:** standard.

### WFL-P5-03: feat(admin): add workflow definition builder UI behind feature flag
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 12-16h · **Risk:** medium
- **Blocked by:** WFL-P5-02 · **Blocks:** —
- **Po co:** Domknięcie wyróżnika: Owner/Admin zmienia proces edytorski bez deployu (czego Pimcore OSS nie umie). Form-based, ŚWIADOMIE nie canvas-graph (estymata realna, a11y za darmo z shadcn).
- **Zakres:**
  - Settings → Workflow (za flagą `workflow_custom_definitions`, menu gated `workflow.manage_definitions`): lista definicji (nazwa, ObjectType, stan enabled, default) + edytor: stany (add/rename/label pl+en/kolor), przejścia (from/to selecty, permission dropdown z permission catalogue, checkbox „komentarz wymagany", opcjonalny gate completeness pct), enable/disable.
  - Walidacja inline z 422 API (błędy per pole); potwierdzenie przy edycji definicji enabled (dotyka żywych obiektów).
  - Opcjonalny prosty podgląd grafu (lista stanów ze strzałkami, bez biblioteki graph) — nice-to-have, nie AC.
  - i18n; axe; Playwright: dodanie stanu + przejścia → widoczne na produkcie.
- **Poza zakresem:** canvas drag&drop designer (Faza 2 jeśli będzie popyt); wersjonowanie definicji.
- **AC:**
  - [ ] Pełny cykl w UI: nowa definicja → stany/przejścia → enable → produkt pokazuje nowe przejścia.
  - [ ] Błędy walidatora czytelne przy polach; bez flagi sekcja niewidoczna.
- **Smoke:** SMOKE TEST RULE: dodaj stan `translation` w UI → przejście widoczne na produkcie bez deployu.
- **Reuse:** wzorce Settings (role builder — `permission-catalogue.ts`) · formy shadcn.
- **DoD:** standard.

---

# M6 — Hardening + E2E + docs + demo

### WFL-P6-01: test(workflow): security hardening for transitions and tasks
- **Typ:** `test` · **Cls:** BE+SEC · **Milestone:** M6 · **Est:** 6-10h · **Risk:** medium · `[SEC]`
- **Blocked by:** WFL-P1-05, WFL-P4-02 · **Blocks:** WFL-P6-03
- **Po co:** Workflow to warstwa autoryzacyjna — każda dziura to eskalacja (self-approve, obejście review przez bulk/grid/PATCH). Dedykowany pass adwersaryjny zanim ogłosimy epik done; kontynuacja rygorów RBAC Phase 6.
- **Zakres (failing-test-first tam, gdzie brak pokrycia):**
  - Cross-tenant: `workflow_transitions`/`workflow_tasks`/`notifications`/`workflow_definitions` = 0 wyników (+ próba przejścia na obiekcie cudzego tenanta = 404).
  - Eskalacje: raw PATCH `status` nie omija guardów (spójność z maszyną po P0-03); bulk `change_status` per-action (marketing bulk approve = 100% blocked); Excel-grid/values path respektuje stan (potwierdzenie P1-02); self-approve — autor submitu z `approve_reject` może approve'ować własne? (decyzja: TAK w MVP — brak four-eyes; odnotować jako known limitation w docs, kandydat Faza 2); IDOR na taskach/notyfikacjach.
  - Race: podwójny approve (dwie sesje) → drugi dostaje 409, nie duplikat logu/tasków.
  - Gates CI: PHPStan max, Deptrac 0 violations na finalnej strukturze; Semgrep rules (jeśli reguły RBAC Phase 6 istnieją — dodać wzorzec dla tras workflow).
- **AC:**
  - [ ] Wszystkie wektory z listy mają test; 0 otwartych findings.
  - [ ] Known limitations (self-approve) udokumentowane w `docs/workflow.md` (P6-03).
- **Smoke:** ręczny mini red-team na `pim.localhost` (15 min): próby z restricted userem wg listy wektorów.
- **Reuse:** wzorce testów RBAC Phase 3/6 · lekcja `bulk-endpoint-permission-escalation`.
- **DoD:** standard.

### WFL-P6-02: test(e2e): cover editorial workflow loop with Playwright and axe
- **Typ:** `test` · **Cls:** FE · **Milestone:** M6 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** WFL-P3-03, WFL-P4-03 · **Blocks:** WFL-P6-03
- **Po co:** Definicja Done projektu: bez E2E ticket nie jest done — a workflow to przepływ MIĘDZY rolami, którego unit-testy nie widzą. Jeden scenariusz-kręgosłup pokrywający całą pętlę współpracy.
- **Zakres:**
  - Scenariusz główny (2 konteksty przeglądarki: admin/approver + restricted marketing przez invitation dev-token): marketing edytuje draft → submit z komentarzem → approver: notyfikacja + task + pozycja w kolejce → reject z komentarzem → marketing: fix task z komentarzem → poprawka → submit → approve → published → marketing próbuje edytować → lock banner → request unpublish → approver: task → unpublish & edit → draft.
  - Scenariusz bulk: filtr draftów → bulk submit z grida → raport → kolejka pokazuje wszystkie.
  - axe na: `/workflow` (obie zakładki), dialogi przejść, banner locka, dzwonek (0 serious/critical).
  - Lekcje lokalne: `i18nextLng=pl` w `addInitScript`; role locators (nie getByText); reset rate limitu loginów przy powtórkach (`restart redis api`).
- **AC:**
  - [ ] Oba scenariusze zielone w CI (właściwy shard); bez flake (3× stabilnie).
  - [ ] axe green na wszystkich nowych powierzchniach.
- **Smoke:** przebieg scenariusza ręcznie na `pim.localhost` przed zapisem testu (SMOKE TEST RULE).
- **Reuse:** helpery Playwright (apiLogin z lekcjami DNS) · wzorce E2E agent inbox.
- **DoD:** standard.

### WFL-P6-03: docs(workflow): documentation, OpenAPI snapshot, demo and lessons
- **Typ:** `docs` · **Cls:** DOCS · **Milestone:** M6 · **Est:** 4-6h · **Risk:** low
- **Blocked by:** WFL-P6-01, WFL-P6-02 · **Blocks:** —
- **Po co:** Zamknięcie epiku wg konstytucji: dokumentacja + spec + demo + lessons. PRD §3.8 przestaje być „specyfikacją na przyszłość" — cross-ref na implementację.
- **Zakres:**
  - `docs/workflow.md`: stany/przejścia (diagram), mapa permission, gate completeness, taski, known limitations (self-approve, import bypass, brak per-attribute approval — z odesłaniem do Fazy 2).
  - Update: `docs/api/jsonb-schemas.md` (gate — jeśli nie domknięte w P1-04), `Project Plan/PRD/PRD-PIM-rbac.md` §3.8 (adnotacja implemented + odstępstwa), `Project Plan/02-plan-projektu-pim.md` (checkboxy epiku), `agent/lessons.md` (sekcja „Lessons z WFL").
  - OpenAPI snapshot finalny `docs/api-spec/v0.json` (całość powierzchni workflow).
  - **Live smoke pełnej pętli** na `pim.localhost` z proofem (HTTP codes + screenshoty) — wymagany przed zamknięciem OSTATNIEGO issue epiku (CLOSED MEANS CLOSED).
  - Screencast 5 min (pętla marketing↔approver + bulk + builder jeśli M5 zrealizowane).
- **AC:**
  - [ ] Dokumentacja kompletna; snapshot bez driftu; PRD cross-ref zaktualizowany.
  - [ ] Proof live smoke w komentarzu zamykającym; screencast nagrany.
- **Smoke:** = live smoke z zakresu.
- **Reuse:** wzorce docs poprzednich epików.
- **DoD:** standard (docs + weryfikacje).

---

## Podsumowanie estymacji

| Milestone | Tickety | Est |
|---|---|---|
| M0 — Silnik + ADR | 5 | 31-44h |
| M1 — Egzekwowanie + RBAC | 5 | 38-56h |
| M2 — Zdarzenia + notyfikacje | 3 | 24-32h |
| M3 — UI | 5 | 40-58h |
| M4 — Zadania | 3 + 1 [DEF] | 26-38h (+4-6h) |
| M5 — Definicje (builder) | 3 | 30-40h |
| M6 — Hardening + E2E + docs | 3 | 20-30h |
| **Razem** | **28 (27 issues + 1 [DEF])** | **~210-300h** |

Ścieżka krytyczna: P0-01 → P0-02 → P0-03 → P0-05 → P1-01 → P1-02 → P3-01/P3-03. M2 (notyfikacje) i M4 (zadania) równoległe do M3 po P2-01/P2-02. M5 w całości odcinalne.
