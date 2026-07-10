# 0029. Workflow engine — symfony/workflow, RBAC-mapped guards, Workflow BC placement

- **Status:** accepted
- **Date:** 2026-07-10
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> Zaakceptowany w tickecie WFL-P0-01 (epik WFL, #2409). Backlog i pełny kontekst (benchmark Akeneo / Pimcore / inRiver / Salsify / Sales Layer / Ergonode + filary projektowe zatwierdzone przez operatora 2026-07-09): `Project Plan/feature-workflow-tickets.md`. Spec źródłowa polityki stanów: `Project Plan/PRD/PRD-PIM-rbac.md` §3.8.
>
> **Nota o numerze:** backlog pierwotnie rezerwował 0028; numer zajął równolegle epik GRID (`0028-attribute-sort-strategy.md`, rezerwacja w `feature-grid-tickets.md`). WFL = **0029** (korekta PR #2438). Weryfikacja wolnego numeru: `ls docs/adr/` ∪ rezerwacje w backlogach epików (`grep -h "ADR-00" 'Project Plan/feature-*-tickets.md'`).

## Context and Problem Statement

PIM ma dziś **status edytorski bez maszyny stanów**: `CatalogObject.status` (`draft/published/archived`) to zwykłe pole — `transitionTo()` pozwala na dowolne przejście, jedyną ścieżką zmiany jest generyczny PATCH, nie istnieje stan `review`. Jednocześnie warstwa RBAC od Phase 3 zawiera **uśpioną politykę stanów**: `Identity\Application\Policy\WorkflowStatePolicy` (#674, zaimplementowana + przetestowana, zero produkcyjnych call sites) oraz zaseedowane permissiony `workflow.view / approve_reject / edit_any_state`. PRD RBAC §3.8 specyfikuje pełną semantykę (stany `draft → review → published → archived`, macierz per-rola, auto-unpublish z audit flagą) i wprost zakłada Symfony Workflow jako mechanizm. Plan projektu rezerwuje „Workflow engine (Symfony Workflow)" w Fazie 1 i „advanced (custom per tenant, approval chains, SLA)" w Fazie 2.

Benchmark rynku (rekonesans 2026-07-09) pokazuje table stakes, których brakuje: role-gated transitions, gate completeness na publikacji, kolejka review / inbox zadań, bulk transitions, komentarze przy przejściach, notyfikacje. Epik WFL (28 ticketów, M0–M6) dostarcza tę warstwę — ale dotyka ≥3 bounded contextów (Catalog: marking na obiekcie; Identity: polityka + permissiony; nowy obszar: definicje/log/zadania; pośrednio Dashboard). Bez jednej autorytatywnej decyzji M1 (guardy) i M5 (definicje DB) renegocjowałyby fundament.

## Decision Drivers

- **PRD §3.8 jako kontrakt** — macierz per-rola, semantyka auto-unpublish i stan `review` są już wyspecyfikowane i zaakceptowane; silnik ma je egzekwować, nie redefiniować.
- **Obudzenie istniejącej warstwy zamiast drugiej implementacji** — `WorkflowStatePolicy`, permission codes, `pending_changes` (ADR-0024), completeness read-model (`objects.completeness` + porty) i Mercure już istnieją; epik ma je spinać.
- **Deptrac / granice BC (ADR-0013, ADR-0015)** — cross-BC wyłącznie przez `*\Contracts\*`; marking żyje na encji Catalog, ale definicje/log/zadania nie są domeną katalogu.
- **Lekcja Pimcore:** guardy jako expressions w configu = każda zmiana reguł wymaga deployu, a visual designer to płatny dodatek. Nasze guardy muszą być danymi (permission codes), żeby M5 (builder per tenant) był możliwy bez przepisywania silnika.
- **Benchmark „nigdzie hard-block domyślnie"** — gate completeness musi być opt-in per ObjectType.
- **Integracje nie mogą utknąć na review** — ścieżki `import`/`integration` (provenance) muszą pisać niezależnie od stanu edytorskiego.
- **API-first (ADR-0012, ADR-0020)** — przejścia to operacje proceduralne: dedykowane trasy custom `#[Route]`, widoczne w OpenAPI, z discovery dozwolonych przejść dla FE.

## Considered Options

1. **`symfony/workflow` (type `state_machine`) + nowy BC `src/Workflow/`** — komponent standardowy (ten sam, na którym stoi Pimcore), definicja `object_editorial`, guardy przez `GuardEvent` mapowane na RBAC; definicje/log/zadania w dedykowanym kontekście z `Workflow_Contracts`.
2. **Rozbudowa ręcznego FSM w Catalog** — whitelist przejść w `CatalogObject::transitionTo()` + ifologia permission w handlerach; bez nowej zależności.
3. **Własny silnik DB-driven od dnia 1** — definicje stanów/przejść w tabelach, interpretowane runtime; bez `symfony/workflow`.

## Decision Outcome

Chosen option: **Option 1**, bo daje egzekwowaną topologię + eventy/guardy/blockery za darmo (standard ekosystemu, zero utrzymania własnego rdzenia), a DB-driven definicje (M5) da się zbudować NA nim (runtime loader definicji), zamiast zamiast niego.

Rozstrzygnięcia szczegółowe (filary 1–8, zatwierdzone przez operatora w backlogu):

1. **Silnik:** `symfony/workflow`, type `state_machine`, definicja **`object_editorial`**; marking store = istniejąca kolumna `objects.status` (method marking store na `CatalogObject::getStatus()/setStatus()`, single state). Bez nowej tabeli markingu.
2. **Topologia:** places `draft / review / published / archived`; transitions: `submit_for_review` (draft→review), **`publish` (draft→published — skrót dla ról z approve; solo operator nie cierpi pętli review)**, `approve` (review→published), `reject` (review→draft), `unpublish` (published→draft), `archive` (draft|published→archived), `restore` (archived→draft). Zabronione topologicznie m.in.: review→archived, published→review.
3. **Placement:** nowy bounded context **`src/Workflow/`** (`Workflow_Internals` + `Workflow_Contracts` w Deptrac) — gospodarz definicji, guard listenera, logu przejść (`workflow_transitions`) i zadań (`workflow_tasks`). **Marking zostaje na `CatalogObject` w Catalog** (status to atrybut obiektu katalogowego). Enforcement edycji per stan: Identity (votery) + Catalog (procesory) sięgają portów przez Contracts. Ruleset: `Workflow_Internals → [Workflow_Contracts, Catalog_Contracts, Identity_Contracts, Shared, Vendor]`; `Catalog_Internals` i `Identity_Internals` mogą sięgać `Workflow_Contracts`.
4. **Guardy = RBAC permission map (dane, nie expressions):** `GuardEvent` listener deleguje do `PermissionResolver`/`WorkflowStatePolicy`; mapa: `submit_for_review→products.edit`, `publish/approve/reject/archive/restore→workflow.approve_reject`, `unpublish→workflow.transition.unpublish`. Odmowa = `TransitionBlocker` z kodem permission (409 RFC 7807 z powodem, nie gołe 403).
5. **Log przejść zamiast wersjonowania:** tabela `workflow_transitions` (tenant RLS, bare UUID `object_id` per ADR-0015, actor, from/to, komentarz, context JSONB — wzorzec „notes & events" Pimcore'a). Wersjonowanie produktów = osobny item Fazy 1, poza epikiem.
6. **Gate completeness na publish/approve:** konfiguracja per `ObjectType` (`workflow_publish_gate` JSONB: enabled / min_completeness_pct / scope global|per_channel), **default OFF**; czyta istniejący read-model `objects.completeness` / `completeness_pct` (kontrakt `docs/api/jsonb-schemas.md` §3).
7. **Definicje DB-driven per tenant = M5 za feature flagiem** (`workflow_custom_definitions`, default OFF): MVP epiku działa w pełni na jednej seedowanej definicji; M5 (loader definicji do Registry + builder w Settings, gated `workflow.manage_definitions`) jest odcinalne do Fazy 2 bez ruszania M0–M4.
8. **Ścieżki zapisu poza UI:** `import`/`integration` (provenance) piszą **niezależnie od stanu** — integracja nie może utknąć na review (świadoma decyzja; audyt provenance pozostaje). Agent ma własny gate (`pending_changes`, ADR-0024); opcjonalny hook „agent-provenance → auto submit_for_review" to WFL-P4-04 `[DEF]` (decyzja operatora przy realizacji — ryzyko double-gate).

### Consequences

- **Positive:** topologia i autoryzacja przejść egzekwowane centralnie (koniec „dowolnego przejścia przez PATCH"); uśpiona warstwa RBAC zaczyna działać bez zmiany jej kontraktu; discovery przejść (`GET …/workflow`) daje FE guard-aware przyciski bez duplikacji logiki; M5 builder nie wymaga przepisania silnika (guardy są danymi).
- **Negative / breaking:** `status` w PATCH przestaje być wolnym polem — wartość niezgodna z dozwolonym przejściem → **409 RFC 7807** (breaking change powierzchni API, wersjonowany w OpenAPI snapshot; jedyny znany konsument to admin FE, aktualizowany w M3). Nowy stan `review` musi zostać dopisany do whitelist filtrów/inputów. Pojawia się nowa zależność core (`symfony/workflow`) — wersja pinowana lockfile, komponent Symfony LTS.
- **Follow-ups:** M1 wpina `WorkflowStatePolicy` w write-path (edycja per stan + auto-unpublish z audit flagą `AUTO_UNPUBLISH_FOR_EDIT`); M4 dodaje zadania; known limitation MVP: **self-approve dozwolony** (autor submitu z `workflow.approve_reject` może zatwierdzić własną zmianę — four-eyes = Faza 2), udokumentować w `docs/workflow.md` (P6-03).

## Alternatives Considered

- **Option 2 (rozbudowa ręcznego FSM):** odrzucona — ifologia permission w handlerach rozlewa autoryzację po Catalog (dziś już jest rozjazd: doc-comment `CatalogObjectPatchInput` twierdzi, że FSM istnieje, a nie istnieje); brak eventów/guardów/blockerów = własny rdzeń do utrzymania; M5 wymagałby i tak przepisania na silnik.
- **Option 3 (własny silnik DB-driven od dnia 1):** odrzucona — najdroższa ścieżka do pierwszej wartości; 90% epiku (M0–M4) nie potrzebuje customizacji definicji, a runtime loader nad `symfony/workflow` (M5) daje ten sam efekt bez utrzymywania interpretera przejść.
- **Placement w Catalog (bez nowego BC):** odrzucona — definicje/log/zadania to nie domena katalogu; Catalog jest już największym BC (płaski 65-plikowy shard `api-catalog` to wąskie gardło CI); Dashboard (ADR-0026) ustanowił precedens cienkiego kontekstu.
- **Placement w Identity:** odrzucona — Identity odpowiada „kto może", nie „w jakim stanie jest obiekt i jaka praca czeka"; zadania/notyfikacje nie są tożsamością.

## Links

- `Project Plan/feature-workflow-tickets.md` — backlog epiku WFL (28 ticketów, benchmark, filary, pakiety PR)
- `Project Plan/PRD/PRD-PIM-rbac.md` §3.8 — workflow-state policy (macierz per-rola, auto-unpublish)
- Related ADRs: ADR-0012 (CQRS custom routes), ADR-0013 (Deptrac), ADR-0015 (cross-BC bare UUID), ADR-0020 (OpenAPI custom routes), ADR-0024 (pending_changes single gate), ADR-0026 (thin read-model BC precedens)
- Tickets: #2409 (WFL-P0-01); epik: issues #2409–#2436, milestone'y #74–#80
