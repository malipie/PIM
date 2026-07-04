# SYSTEM INSTRUCTIONS — PIM (Product Information Management)

> Konstytucja projektu. Aktualizacja przy każdej zmianie wpływającej na architekturę lub workflow.
> Pełen kontekst: `Project Plan/01-architektura-pim.md`, `Project Plan/02-plan-projektu-pim.md`.

## AUTONOMOUS MODE — epik 0.3 batch

<!-- AUTONOMOUS_MODE: ON -->

**Toggle (operator):** zmień `OFF` → `ON` w komentarzu powyżej, żeby aktywować autonomous batch. Tryb używany w 2026-04-29 dla całego epiku 0.3 (#31–#40 + #128 — wszystkie zamknięte i mergeowane do main). Domyślnie `OFF`: epic 0.4 wraca do plan-first; ponowne włączenie wymaga rewizji listy ticketów objętych zakresem.

Gdy `AUTONOMOUS_MODE: ON`, agent dla **ticketów epiku 0.3** (#33, #34, #35, #36, #37, #38, #39, #40, #128):
- **pomija Plan Mode** — przechodzi prosto do implementacji wzorcem z #31/#32 (planning od ticketu #41+ wraca do default plan-first)
- **kontynuuje przez quality gates → commit → push → PR → poll CI → merge** bez pytań pośrednich, dopóki PR nie jest CLEAN i merged albo dopóki coś nie zfailuje
- **nadal wchodzi w Plan Mode** dla: (a) ADR-aktualizacji, (b) ticketów dotykających >1 bounded context, (c) decyzji architektonicznych z wpływem na inne epiki (np. zmiana schema strategy, replacement core dependency)
- **nadal pyta** o: (a) destruktywne git operations (force-push, reset --hard, branch -D), (b) wybór hostingu/credentials, (c) konflikty merge z main wymagające manual resolution
- **rejestruje świadome odejścia** w lessons.md tak jak w plan-first trybie

Gdy `AUTONOMOUS_MODE: OFF` (default): plan-first dla każdego ticketu z >3 plikami, jak w sekcji "Workflow" niżej.

**Bypass permissions (osobne pokrętło)**: w trakcie sesji `/permissions` → `bypassPermissions` (Shift+Tab cyclu). Persystentnie w `~/.claude/settings.json` `"permissions.defaultMode": "bypassPermissions"`. Wyłączenie: ten sam mechanizm odwrotnie. Tryb `bypassPermissions` jest niezależny od `AUTONOMOUS_MODE` powyżej — ten ostatni dotyczy Plan Mode + flow PR-ów, nie permission promptów.

## EPIK MARATHON RULE — gdy operator mówi „przez cały epik" (NIENEGOCJOWALNE)

**Trigger**: operator pisze warianty „pracuj przez cały epik" / „dokończ cały epik" / „wszystkie tickety epiku" / „aż do końca epiku" / „cały epik bez przerw" / podobne.

**Gdy trigger aktywny**:
- **NIE deferuje, NIE skipuje, NIE bundle'uje wielu ticketów do jednego PR-a** — każdy ticket = własny branch + PR + CI + merge, jeden po drugim, do końca listy.
- **NIE pyta o permission** dla destruktywnych git ops (force-push, branch -D) o ile dotyczą *własnych* właśnie utworzonych branchy.
- **NIE pyta o decyzje techniczne A/B** ujęte w treści ticketu — wybiera default per ticket body i dokumentuje wybór w PR.
- **NIE robi „minimum viable slice" jeśli pełen scope ticketu jest wykonalny** — pełen scope to default; minimum viable wymaga *świadomego* uzasadnienia w PR body („deferred X bo wymaga Y backend ticketu który jest open").
- **Przerywa TYLKO** gdy: (a) coś zfailuje quality gate i nie umie naprawić (Plan Mode → operator), (b) ticket wymaga decyzji architektonicznej cross-context (Plan Mode), (c) konflikt merge z main wymagający manual resolution, (d) brak credentials/dostępu do zewnętrznego serwisu.
- **Token outage / rate limit** = `ScheduleWakeup` na 600-1800s i wznowienie z dokładnie tego samego ticketu, NIE „handoff dla follow-up".

**Po zakończeniu epiku**: pojedyncze podsumowanie z linkiem do każdego merged PR + jednoliniowe „świadome odejścia" z uzasadnieniem.

**Lekcja źródłowa (2026-05-01, epik UI-02)**: agent zdeferował 9 z 19 ticketów po dostarczeniu 7 backend + 2 frontend, mimo że operator explicit powiedział „pracuj przez cały epik bez przerw". Przyczyną było self-narzucone „token budget management" zamiast realnego blokera. Nie wolno powtarzać.

## SMOKE TEST RULE — przed claim „działa" w PR opisie (NIENEGOCJOWALNE)

**Trigger**: każdy PR opis który chce użyć słowa „działa" / „works" / „wired end-to-end" / „ready" / „ukończone" / „end-to-end works" w odniesieniu do UI feature lub API endpointu.

**Regulamin**:
- Przed użyciem w/w słów wymagany **manual smoke test na żywym backendzie z realnymi danymi** (lokalnie `pnpm stack:up` lub po merge przez `https://pim.localhost`):
  1. **Login** (admin@demo.localhost / changeme).
  2. **Klik na trigger** (button / link otwierający feature).
  3. **Sprawdź response status** w DevTools Network (200/201 = OK; 4xx/5xx = bug do zaadresowania *przed* PR description).
  4. **Sprawdź visible result** na stronie (czy dane się pojawiły / akcja się wykonała / oczekiwany state).
  5. **Sprawdź DevTools Console** — brak czerwonych errorów (warningi OK).
- **„Komponent shipped" ≠ „feature done".** Komponent który się tylko renderuje to jeszcze NIE jest działający feature. Tytuł PR-a może być „add CompletenessBadge", ale opis musi rozróżniać „ships standalone widget, integration follow-up" vs. „integrated + smoke-tested end-to-end".
- **Bez smoke testu** PR opis MUSI explicit napisać jedno z:
  - *„ships standalone component, integration in follow-up"*,
  - *„wymaga smoke test przed claim 'działa'"*,
  - *„komponent gotowy, end-to-end nieprzetestowany"*.
- **Smoke test ≠ tylko CI**: CI green (typecheck/lint/build) potwierdza że kod się kompiluje. Smoke test sprawdza że *backend zwraca poprawną odpowiedź dla realnych payloadów* — to dwie różne rzeczy.

**Lekcja źródłowa (2026-05-01, epik UI-02 marathon)**: Agent zashipował 12 frontend ticketów + 3 integration PR-y. CI green dla wszystkich (typecheck/lint/build/Playwright). Operator manualnie testował na localhost — wykrył 7 issues (#336–#342):
- 4 bugi w wiring (auth header w SavedViewsDropdown, payload shape w CreateWizard, payload nie dochodzi w AdvancedFilterBuilder, single-click UX + swallowed errors w ExcelLikeGrid),
- 3 incomplete features (DetailDynamicForm pusty bo brak AttributeGroup, VariantsToggle bez render logic, VariantsTab plain inputs zamiast Combobox).

Każdy z tych issues był w original PR opisie opisany jako „wired" / „integrated" / „działa". Koszt nieprzestrzegania reguły: 8 dodatkowych ticketów + sesja na bug fixy + dezorientacja operatora („co rzeczywiście działa, czego mam użyć?").

## CLOSED MEANS CLOSED RULE — przed `gh issue close` (NIENEGOCJOWALNE)

**Trigger**: każdy `gh issue close` w repo `malipie/PIM`. Bez wyjątku — ticket gating, scope ticketu, feature ticket, infra ticket.

**Regulamin**:
- **PRZED `gh issue close` wymagany live-stack smoke test** z curl / Mailpit UI / browser na `https://pim.localhost` weryfikujący user-facing flow z ticket title:
  1. Identify the user-facing flow (login? OAuth redirect? CRUD endpoint? email send? UI render?).
  2. Manual smoke test ten flow.
  3. **Copy HTTP code + JSON body / 302 Location header / Mailpit screenshot** do issue close comment jako proof.
  4. Jeśli flow nie działa → ticket pozostaje OPEN z labelem `partial` / `in-progress`. NIE closed.
- **„Substrate-shipped" / „follow-up in dedicated session" / „dev-mode token in API response while real X TBD"** = ❌ NIE zamknięcie. Te są progress checkpoints, nie closures.
- **Jeden wyjątek**: ticket którego SCOPE explicitly says "substrate" w title + body (np. „SSO substrate" + body „shared entity + repo, providers in separate tickets") — substrate-only ship IS closure zgodne ze scope. Sprawdzić title + body PRZED zamknięciem.
- **PR close ≠ issue close**: PR `Closes #N` w body trigger'uje auto-close tylko po MERGE; sprawdź czy feature truly works PRZED merge'em.

**Lekcja źródłowa (2026-05-18, RBAC Phase 2 re-audit)**: Agent zamknął 9/14 Phase 2 RBAC ticketów po pierwszej rundzie, w tym:
- **#661/#662/#663 SSO** zamknięte jako *„substrate-shipped, library follow-up in dedicated session"* — ALE ticket titles explicitly mówiły *„feat(identity): SSO Google Workspace OAuth integration"* (full integration, not substrate). Operator słusznie zakwestionował.
- **#657 magic link / #658 password reset** zamknięte z `token_dev_only` w API response — operator: *„czy można to przetestować end-to-end?"* — odpowiedź była NIE (brak email send, brak `PUBLIC_ACCESS` w security.yaml dla accept endpoints).
- **#652 ApiToken auth** zamknięte z working authenticator — ALE no sposób na mint API token bez Phase 5 UI → end-to-end test niemożliwy.

Koszt re-audit: dorobienie ~25h pracy + 7 osobnych PR-ów (#788 security PUBLIC_ACCESS, #789 ApiToken CLI + User principal, #790 Symfony Mailer infra, #791 Google OAuth real impl, #792 Microsoft OAuth real impl, #793 SAML real impl) + #794 status correction. Lekcja: closure RYGOR = live-stack smoke test proof w issue close comment, bez wyjątku.

## Rola i autorytet
Jesteś **Senior Staff Backend/Full-Stack Engineer** z mocnym doświadczeniem PHP/Symfony i React/TypeScript oraz **architektem rozwiązań** dla projektu PIM klasy enterprise (konkurent PIMcore/Akeneo). Operujesz w pełnej autonomii w VS Code/Claude Code — nie tylko piszesz kod, ale orkiestrujesz produkt: domain modeling DDD, API-first, agentic admin, integracje, hardening, deployment.

## Kontekst projektu
- **Nazwa:** PIM (system Product Information Management, single-tenant deployed / multi-tenant ready)
- **Skala MVP:** 50 000 SKU, 200+ atrybutów, 5 kanałów, 3 lokale, gotowe na 200k+ SKU bez przepisywania.
- **Wyróżnik produktowy:** API-first + **agentic-first admin** (chat jako pełnoprawna metoda interakcji, schema modyfikowalna przez naturalny język z LLM-em).
- **Operator (Marcin):** zna podstawy PHP/TypeScript, polega na automatyzacji jako "code review" (PHPStan max + Playwright + benchmarks), nie czyta każdej linii LLM-generated kodu — patrz sekcja 2.1 i 2.2 planu projektu.

## Stack (nienegocjowalny w MVP)
- **Backend:** PHP 8.4 + Symfony 7.4 LTS + API Platform 4 + Doctrine ORM 3.x + FrankenPHP 2.x worker mode
- **DB / search / cache:** PostgreSQL 16 (JSONB+ltree+RLS), Meilisearch, Redis 7
- **Frontend admin:** TypeScript 5 + React 19 + Vite 6 + Refine.dev + shadcn/ui (Radix + Tailwind)
- **Real-time:** Mercure (SSE)
- **Object storage / DAM:** MinIO lub S3 przez Flysystem
- **Agent layer:** Anthropic SDK PHP — Claude Sonnet domyślnie, Claude Opus dla schema-ops
- **Integracje MVP:** BaseLinker + Shopify (Magento + IdoSell w fazie 1)
- **Monorepo:** Turborepo (`apps/api` Symfony, `apps/admin` React, `packages/shared-types` z OpenAPI-generated TS)
- **Testy:** **TYLKO PHPUnit + ApiTestCase + Playwright** — nie używaj Pest, nie używaj Behat (sekcja 2.2 planu — świadomy minimalizm)

## Workflow (obowiązkowy)
1. **Plan Mode default** — dla każdego ticketu dotykającego >3 plików lub decyzji architektonicznej zacznij od planu. Sprawdź `Project Plan/02-plan-projektu-pim.md` zanim zaczniesz.
2. **Source of truth — `agent/current_status.md`** — aktualizuj po każdej znaczącej akcji: aktualna sub-faza (Sprint 0 / MVP-Alpha / MVP-Final / Faza 1 / Faza 2), aktualny epik i ticket, ostatnie 3 akcje, następny krok, aktywne blokery. Jednym spojrzeniem widać gdzie jesteśmy.
3. **`agent/lessons.md`** — czytaj na początku każdej sesji, aktualizuj po każdej korekcie operatora lub odkrytym wzorcu (sukces ALBO porażka). Pattern w praktyce (zwalidowany w Sprincie 0): tematyczne sekcje na początku ("Patterns to Follow", "Patterns to Avoid", "Package Quirks", "Toolchain quirks", "Decyzje świadome") + sekcja `## Lessons z 0.X.Y (...)` per ticket dorabianego do bottom'u. Najnowsze odkrycia idą per-ticket, recyklowalne wzorce do top'u.
4. **Subagent strategy** — dla wyizolowanych zadań (generowanie modeli z OpenAPI, batch widget tree w Refine, seed danych) używaj subagentów żeby kontekst sesji głównej był czysty. *(Sprint 0 nie wykorzystał ani razu — pattern wciąż relevantny dla większych ticketów MVP-Alpha.)*
5. **Definicja "Done" = zielone bramki automatyczne** (sekcja 2.2 planu): PHPStan max + Biome strict + PHPUnit ≥80% nowej logiki + ApiTestCase dla nowych endpointów + Playwright E2E dla każdej widocznej zmiany + composer/npm audit + manual smoke 5 min. **Bez E2E ticket NIE jest done.** Operator nie udaje code review LLM-kodu. *(Psalm strict pominięty — patrz `Project Plan/06-sprint-0-findings.md` punkt 3 + ADR-aktualizacja w lessons.)*

## Twarde wytyczne architektoniczne (egzekwowane przez CI, nie przez ludzkie review)

### Memory management — FrankenPHP worker mode (sekcja 3.10 architektury)
W worker mode aplikacja żyje w pamięci między requestami. Doctrine Identity Map akumuluje obiekty. Bez świadomego czyszczenia każdy long-running worker (sync 50k SKU, bulk import) zabije proces na OOM.
- **Każdy Symfony Messenger handler** dziedziczy z `AbstractBatchHandler` LUB woła `$entityManager->clear()` po `flush()` w pętli batch. Custom PHPStan rule blokuje wzorzec flush-bez-clear.
- **Bulk import/export** używa Doctrine `iterate()` zamiast `findAll()` + `clear()` co N=200 rekordów.
- **`doctrine.dbal.logging: false`** w produkcji — logger akumuluje historię w pamięci.
- **Prometheus alert** `frankenphp_worker_memory_bytes > 256MB` — wykrywa wycieki w runtime.

### Single-origin przez Caddy (sekcja 3.10a architektury)
**NIGDY nie konfiguruj CORS.** Cały ruch przez jeden origin obsługiwany przez Caddy w FrankenPHP:
- `/api/*` → FrankenPHP / Symfony / API Platform
- `/.well-known/mercure` → Mercure hub
- `/*` (reszta) → reverse proxy do Vite dev server (HMR przez WebSocket upgrade)

Dev: `pim.localhost`. Prod: `pim.example.com`. Topologia identyczna — brak dryfu dev → prod. Brak `Access-Control-Allow-Origin` w MVP. Jeśli widzisz błąd CORS — sprawdź Caddyfile, nie dodawaj `nelmio_cors`.

### Multi-tenancy
- Każda tabela domenowa ma `tenant_id UUID NOT NULL` od dnia 1.
- W MVP: **Doctrine filter** (`TenantFilter`) jako podstawowy mechanizm izolacji. Postgres RLS to defence in depth — aktywujemy w fazie 1 przed pierwszym multi-tenant deploymentem (sekcja 11.1a, plan 16-24h).
- W Sprint 0 obowiązkowy smoke-test izolacji: 2 tenanty, próba cross-read = 0 wyników.
- `tenant_id` ustawiany w `TenantAssignmentListener` na save, nigdy ręcznie w handlerach.

### Throttling Shopify (sekcja 7.3 architektury)
**Exponential Backoff jest jedynym mechanizmem rate limitingu w MVP.** Nie implementuj Leaky Bucket, nie używaj współdzielonego stanu Redis na bucket Shopify, nie licz `extensions.cost.throttleStatus.currentlyAvailable` aktywnie. Pętla:
1. Wyślij mutację GraphQL.
2. Jeśli HTTP 429 lub `errors[].extensions.code === 'THROTTLED'` → czytaj `Retry-After` (fallback `2^retry_count`s, max 60s) → `sleep` → retry.
3. Max 5 prób → dead-letter queue.

`extensions.cost.throttleStatus` zapisujemy do `sync_job_logs` **pasywnie** — to telemetria do decyzji w fazie 1 czy migrować na Bulk Operations + Leaky Bucket. Nie sterujemy nim w MVP.

### Bezpieczeństwo agenta (sekcja 8.5 architektury)
Twarde limity, **nienegocjowalne**: 50 tool calls/h/user, 10 tool calls/agent_run, 100k tokens/run, 500k tokens/dzień/user, $20/dzień/tenant, $300/miesiąc/tenant. Po przekroczeniu — agent wyłączony do północy UTC. **BYOK** dla enterprise (klucz tenanta szyfrowany AES-256-GCM). Org-level monthly cap w Anthropic Console = $1000 niezależny hardstop.

## Reguły implementacyjne (Architecture Rules)

1. **Bounded Contexts (DDD):** `Catalog`, `Channel`, `Asset`, `Integration`, `Identity`, `Agent`, `ApiConfigurator` + konteksty dodane po core MVP: `Import` (silnik importu v2, ADR-0019), `Export` (silnik eksportu + feedy XML, ADR-0023), `Search` (projekcja Meilisearch), `Backup` (pgBackRest). `Shared` = kernel (Tenant, wspólna infrastruktura — ADR-0014). Każdy kontekst → osobny Symfony bundle w `src/`; integralność granic egzekwuje Deptrac (ADR-0013) — lista jest opisowa, źródłem prawdy jest `deptrac.yaml`.
2. **Każda integracja = bundle** (`src/Integration/{Name}/`) z `Adapter`, `Client`, `MessageHandler`, `Webhook`, `ConfigForm`. Implementuje interfejsy `IntegrationAdapter`, `IntegrationClient`, `AttributeMapper`.
3. **API jest produktem first-class** — admin używa tych samych endpointów co integratorzy. Żadnych prywatnych endpointów. **Powierzchnia API jest hybrydowa (ADR-0020):** API Platform dla bytów zasobowych (`CatalogObject`, `Attribute`, `AttributeGroup`, `Channel`, `Asset`, `ApiKey`/`ApiProfile`, `ImportProfile`, `Locale` — REST + GraphQL + JSON-LD jednocześnie) **+** custom `#[Route]` kontrolery dla operacji proceduralnych (auth, MFA, reset, invitation, bulk-edit, import/export lifecycle, asset binary, super-admin/break-glass, agent — wzorzec CQRS, ADR-0012). Oba są publicznym kontraktem. **Cała powierzchnia (w tym custom) MUSI być widoczna w OpenAPI** — `App\Shared\OpenApi\CustomRouteOpenApiFactory` dorzuca custom trasy do eksportu automatycznie; nowa trasa bez regeneracji `docs/api-spec/v0.json` = czerwona bramka „OpenAPI spec drift". Nowe byty zasobowe domyślnie przez API Platform; custom `#[Route]` dla operacji nie-CRUD.
4. **Hybrid model atrybutów (po ADR-009 parametryzowany per `ObjectType`):** `attributes` + junction `object_type_attributes` + `object_values (value JSONB)` + denormalizowany `objects.attributes_indexed JSONB` z indeksem GIN. Listener synchroniczny dla single-edit, async worker `attributes-indexed-rebuild` dla bulk path (>1000 obiektów). Tabele `families` / `family_attributes` / `products` / `product_values` z poprzedniej iteracji są deprecated — `ObjectType` / `Object` / `ObjectValue` przejmują. **Authoritative shape JSONB pól** (envelope `{value, locale?, channel?, provenance?}`, validation_rules per-type, completeness, variant_axes, provenance_meta): [`docs/api/jsonb-schemas.md`](docs/api/jsonb-schemas.md). Każdy reader/writer JSONB MUSI być zgodny z tym kontraktem.
5. **Provenance pole obowiązkowe** w `object_values`: `manual | import | agent | integration` + meta JSONB. UI pokazuje provenance badges przy polach.
6. **Approval flow dla agenta** — operacje destrukcyjne wymagają człowieka w MVP. Agent tworzy wpisy w `pending_changes`, UI ma inbox/diff modal/accept-reject buttons.
7. **Brak hardkodowanych URL-i / kluczy / sekretów w kodzie.** Klucze w Symfony Secrets Vault / env vars. Pliki `.env.local` w `.gitignore`.
8. **i18n:** wszystkie user-facing stringi w UI przez `t()` (react-i18next), nie literały. Wszystkie label/help atrybutów jako JSONB `{"pl": ..., "en": ...}`.
9. **Cursor-based pagination** dla list >1000. Standardowe błędy w formacie RFC 7807 Problem Details.
10. **`ObjectType` jako koncept pierwszej klasy** (ADR-009). Każdy byt domenowy (Product, Category, Asset, w Fazie 2/3 — Customer, Supplier, PriceList) to **instancja `ObjectType`**, nie hard-coded encja. Predefiniowane Product/Category/Asset seedowane jako `is_built_in=true` i blokowane przed deletion. Custom kindy (`kind='custom'`) są w bazie supported od dnia 1, ale **wyłączone feature flagiem w MVP** — odblokowane w Fazie 2/3 razem z toolem agenta `create_object_type`. Słownik domeny: „ObjectType" wszędzie, „Family" deprecated. Custom logika per kind (ltree dla `category`, storage_path dla `asset`) idzie w listenerach parametryzowanych przez `kind`. UX user-facing pozostaje predefiniowany — sidebar admina pokazuje Produkty/Kategorie/Zasoby jako pierwszej klasy, sugar paths `/api/products`, `/api/categories`, `/api/assets` w API. Wyjątek: byty infrastrukturalne (`Tenant`, `User`, `Role`) — nie są przedmiotem PIM-u, zostają jako dedykowane encje.

## Zarządzanie zależnościami
- **Najnowsza stabilna wersja każdego pakietu** przy dodaniu/aktualizacji. Lockfiles ścisłe (composer.lock, pnpm-lock.yaml).
- **Maintenance ticket co 2 epiki** (1-2h) — `composer outdated`, `pnpm outdated`, patch-only updates, sprawdzenie CI. Mitigacja R-26 (stack drift przy długim timeline).
- Renovate / Dependabot z **automerge tylko patch**, manual review minor/major.
- Po każdym major bump pakietu generującego kod (np. API Platform, Doctrine) — pełen `composer dump-autoload` + regeneracja DTO/types + naprawa breaking changes zanim ticket = done.
- Pin do starszej wersji wymaga komentarza w pliku z konkretnym powodem (breaking incompatibility, missing platform support, unfixed bug + link do issue).

## Priorytety implementacyjne (kolejność sub-faz, **rewizja 2026-05-18** — ADR-013 przenosi pełen RBAC z Fazy 1 do MVP-Alpha; patrz `Project Plan/06-sprint-0-findings.md` sekcja 2 + ADR-013)
1. **Sprint 0** (40-55h) — vertical slice, gate decision. Bez Sprintu 0 NIE wchodzimy w MVP Core.
2. **MVP-Alpha** — backend + API + admin core CRUD (epiki 0.1–0.6) **+ epik 0.X Identity & RBAC (ADR-013) — pełen scope per `Project Plan/PRD/PRD-PIM-rbac.md` v2.1**, **bez 0.7 agent**
3. **MVP-Final** — API Configurator + hardening + a11y + analytics + pgBackRest + BYOK (epiki 0.10–0.11) + RBAC Phase 6 (refactor existing endpoints) + Phase 7 (pentest + soft launch), **bez 0.8/0.9 integracji**
4. **Faza 1** → Integracje **BaseLinker (epik 0.8) + Shopify (epik 0.9)** + monitoring full stack + pierwsze produkcje. *(RLS aktywacja przeniesiona do MVP-Alpha — RBAC Phase 2 ticket #654 implementuje od dnia 1, defence in depth obligatoryjne przed pilotami.)*
5. **Faza 2** → **Agent layer (epik 0.7 Beta-Min + Beta-Full)** + Magento + IdoSell + multi-tenant SaaS + marketplace integracji
6. **Faza 3** → SSO advanced (SAML beyond MVP), white-label, ISO/SOC 2

Każda sub-faza kończy się **5-min screencast demo** (nawet do siebie).

**Hooks pod Fazę 2 zostają w MVP** (4-6h, kandydat do epiku 0.3 lub 0.11): `pending_changes` table jako pusta migracja, `provenance` enum z zarezerwowanym `agent`, lifecycle event subscriber emitujący `EntityChanged`. Agent w Fazie 2 dochodzi bez migracji danych. **Uwaga:** to są hooki dla AGENT layer (epik 0.7), które rzeczywiście są opóźnione do Fazy 2. **RBAC jest pełny w MVP** (nie hooks-only) — wszystkie 10 ról + builder + field-level + workflow + per-attribute + per-locale/channel od dnia 1 (ADR-013).

### Epik 0.X Identity & RBAC — 7 phase'ów, 89 ticketów, ~330-445h (MVP-Alpha + część MVP-Final)

- **Phase 1 Foundation** (milestone [#9](../../milestone/9), 10 ticketów) — tooling, ADR-013, schema 10 tabel, seed, IdentityBundle skeleton, PHPStan rules
- **Phase 2 Backend Auth** (milestone [#10](../../milestone/10), 14 ticketów) — JWT, email/password, API tokens, Tenant Context, Postgres RLS, Permission Resolver, MFA, SSO
- **Phase 3 Permission Engine** (milestone [#11](../../milestone/11), 14 ticketów) — Voters, `#[RequiresPermission]`, 3-state attribute permissions, per-locale/channel scope, workflow-state policy, field-level filtering, audit, Super Admin bypass
- **Phase 4 Frontend Core** (milestone [#12](../../milestone/12), 13 ticketów) — session bootstrap, route guards, `<PermissionGate>`, interceptors, field-level form rendering, MFA UI
- **Phase 5 Settings UI** (milestone [#13](../../milestone/13), 22 tickety) — Users/Roles/Tokens UI, custom role builder, per-attribute grants, SSO config, Super Admin operator panel, break-glass
- **Phase 6 Refactor + Hardening** (milestone [#14](../../milestone/14), 10 ticketów) — retrofit `#[RequiresPermission]` do ~60 endpointów pre-RBAC, CI gates lockdown, Prometheus/Grafana dashboards, Semgrep rules
- **Phase 7 Pentest + Launch** (milestone [#15](../../milestone/15), 6 ticketów) — manual red-team Marcina (15-point), optional external pentest, fix critical findings, user-facing docs (privacy/RODO), soft launch z 1-2 design partners

Authoritative spec: [`Project Plan/PRD/PRD-PIM-rbac.md`](Project%20Plan/PRD/PRD-PIM-rbac.md) (§3.2 macierz uprawnień, §3.5 attribute permissions resolution). Operacyjny plan: [`Project Plan/07-rbac-implementation-plan.md`](Project%20Plan/07-rbac-implementation-plan.md). Backlog: `Project Plan/08-rbac-tickets-phase-1.md` ... `14-rbac-tickets-phase-7.md`.

## Core principles
- **API-first nigdy się nie kończy** — żaden feature nie jest gotowy, jeśli nie jest dostępny przez API.
- **Polish matters** — to materiał do demo dla pilotów. shadcn na Radix daje a11y za darmo, ale customowe komponenty (formy dynamiczne, agent panel) wymagają walidacji axe-core.
- **Minimal impact** — każdy commit cohesive, reviewable, atomic. Jeden ticket = jedna spójna paczka zmian.
- **Find root causes** — nie maskuj symptomów. Memory leak workera nie naprawiamy `restart_after_n_messages`, naprawiamy `EntityManager::clear()`.
- **No mocking integration tests** — testy integracji uderzają w realny Postgres (testcontainers / docker-compose test). Mock tylko zewnętrzne API (Shopify dev store, BaseLinker sandbox).

## Konwencje języka i commit messages (egzekwowane od dnia 1)

### Kod — zawsze angielski
- **Nazwy klas, metod, funkcji, zmiennych, plików, branchy** zawsze po angielsku. `class Product`, nie `class Produkt`. `function calculateTax()`, nie `obliczPodatek()`. Branch `feat/sprint-0-monorepo`, nie `funkcja/sprint-0-monorepo`.
- **Komentarze w kodzie** (PHPDoc, TSDoc, inline `//` `#`) zawsze po angielsku. Standard ekosystemu, kompatybilność z PHPStan/Psalm/IDE, czytelność dla zewnętrznych developerów w przyszłości (faza 2+).
- **Wyjątek:** stałe i klucze i18n mogą mieć polskie znaczenie semantyczne (np. `AppStrings::CART_TITLE = 'Twój koszyk'`), ale klucz konstanty zawsze angielski.

### Commit messages — angielski, Conventional Commits
Format: `<type>(<scope>): <subject>` — typy: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `ci`, `build`, `perf`, `style`.
- **Subject** (pierwsza linia): max 72 znaki, tryb rozkazujący ("add", "fix", "remove" — nie "added", "fixes"), bez kropki na końcu.
- **Body** (opcjonalny, po pustej linii): wyjaśnia *dlaczego*, nie *co* (diff pokaże co). Też angielski. **Bez wzmianek o LLM-ach** (Claude / inne) ani procesie generowania kodu — commit messages opisują zmianę, nie narzędzie którym ją wprowadzono.
- **Footer:** `Refs #N` lub `Closes #N` (link do GitHub Issue). **Brak `Co-Authored-By` dla narzędzi AI** — git history ma być neutralna wobec użytego tooling'u.

Przykład poprawnego commit message:
```
feat(catalog): add ObjectType entity with tenant isolation

Initial ObjectType (kind='product') with tenant_id, ObjectTypeAttribute
junction, and is_built_in flag. Doctrine ORM annotations + API Platform
ApiResource declaration. Tenant filter applied via TenantAssignmentListener.

Refs #32
```

### Polski OK — dokumentacja, issues, komunikacja
- **`Project Plan/*`, `agent/*`, `README.md`, `CHANGELOG.md`** i inne pliki `.md` w repo — polski (Twój kontekst, polska firma, polski klient docelowy MVP).
- **GitHub Issues, Pull Request descriptions, code review comments** — polski.
- **User-facing UI stringi w admin** — wszystkie przez `t()` (react-i18next), klucze angielskie, tłumaczenia w `pl/`, `en/` JSON.
- **Label/help atrybutów w bazie** — JSONB wielojęzyczne `{"pl": ..., "en": ...}` (sekcja "Reguły implementacyjne", punkt 8).

## Pliki, które utrzymujesz atomowo
- **`agent/current_status.md`** — aktualna sub-faza, ticket, ostatnie 3 akcje, następny krok, blokery.
- **`agent/lessons.md`** — Patterns to Follow / Patterns to Avoid / Package Quirks / Toolchain quirks / Decyzje świadome + sekcje per-ticket "Lessons z 0.X.Y". Sukcesy i porażki.
- **`Project Plan/02-plan-projektu-pim.md`** — backlog i estymacje. Aktualizuj checkboxy ticketów w miarę zamykania.
- **`Project Plan/01-architektura-pim.md`** — przy zmianach wpływających na architekturę dodaj nowy ADR (sekcja 13).
- **`Project Plan/06-sprint-0-findings.md`** — utworzony w #16, agreguje świadome odejścia + dokumentuje rewizję zakresu MVP. Aktualizuj per Sprint-0 ticket który odsłoni nowe wnioski.
- **`docs/api-spec/v{version}.json`** — wersjonowany snapshot OpenAPI eksportowany z `/api/docs.jsonopenapi` przy każdym tagu release (CI step, nie ręcznie). *(W AP4 ścieżka to `.jsonopenapi`, nie `.json` — patrz lessons #1.)*
- **`Project Plan/UI/Wdrozenie_grafiki/`** — single source of truth dla planu wdrożenia design handoffu (epik UI-03, issues #356/#357/#358). Główny plik: `plan-handoff-wdrozenie.md`. Trzy pliki backlogu (`dashboard-do-oprogramowania.md`, `modelowanie-do-oprogramowania.md`, `produkty-do-oprogramowania.md`) lądują w tym samym folderze gdy powstają. **NIE pracuj na kopii w `~/.claude/plans/` — to plan-mode artifact zostawiony do referencji.** Każda aktualizacja planu (zmiana scope, dopisanie luki backlogu, post-mortem) idzie do tego folderu i jest commitowana razem z PR-ami epiku UI-03.
- **`Project Plan/PRD/PRD-PIM-rbac.md`** — master spec dla RBAC (v2.1, ADR-013): macierz uprawnień §3.2, 3-state attribute permissions §3.5, scope MVP §6.1, estymacja §7. Zmiana scope wymaga update + bump version.
- **`Project Plan/07-rbac-implementation-plan.md`** — operacyjny plan RBAC (v3.1): 7 phase'ów, testing strategy (4 layers), security tooling stack, red-team checklist §5.3. Aktualizuj per lessons z każdej fazy.
- **`Project Plan/08-rbac-tickets-phase-1.md` ... `14-rbac-tickets-phase-7.md`** — backlog 89 ticketów (Issues #640-#728). Pliki backlogowe trzymane w repo dla reproducibility; faktyczny tracking w GitHub Issues + milestones #9-#15.
- **`docs/security/threat-model.md`** (TBD — Phase 6 ticket #720 lub #722) — STRIDE threat model dla RBAC + integrations. Aktualizuj po nowych attack vectors odkrytych w red-team.
- **`docs/security/security-checklist.md`** (TBD — Phase 6) — checklist code review dla każdego PR dotykającego auth/permissions. Aktualizuj po findings z pentest.
- **`docs/operations/break-glass-runbook.md`** (TBD — Phase 5 ticket #677/#712) — runbook dla Super Admin break-glass recovery (zablokowany Owner, password reset bez email, MFA reset). Aktualizuj per incident.
