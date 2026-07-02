# 0024. Agent layer — removable bounded context, tool registry, single approval gate

- **Status:** proposed
- **Date:** 2026-07-01
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> **Uwaga:** to szkic do finalizacji w tickecie AGENT-P0-01 (epik 0.7 Agent layer). Po akceptacji status → `accepted` i streszczenie trafia do `Project Plan/01-architektura-pim.md` §13. Master feature-PRD: `Project Plan/PRD/PRD-PIM-agent.md`.

## Context and Problem Statement

Cortex PIM ma jeden differentiator, którego Akeneo/Pimcore/BaseLinker nie mają: **agent AI, który wykonuje operacje na katalogu i na schemacie danych z approvalem operatora, cofalnie i audytowalnie** (`PRD-PIM-agent.md` §1). Agent nie ma własnej logiki domenowej — orkiestruje istniejące silniki (import, eksport, bulk-edit z undo-log, modeling, filtr, completeness) jako **narzędzia (tools)**. Ta „cienka warstwa tool-callingu" niesie trzy twarde wymogi biznesowo-architektoniczne, które trzeba rozstrzygnąć **zanim** powstanie kod, żeby ~45 ticketów epiku nie renegocjowało granic:

1. **Wydzielalność (open-core).** PIM ma być udostępnialny jako open source **bez** agenta, a agent trzymany jako proprietary/enterprise add-on (`PRD-PIM-agent.md` §11.1, §12). Fizyczne usunięcie modułu musi zostawić budowalny, zielony PIM.
2. **Jak wystawić silniki modelowi**, skoro Deptrac zabrania Agentowi sięgać do internals innych BC, a większość silników (Modeling CQRS, StructuralImport, AutoMapper, Export, CatalogSearchService) **nie ma dziś seamu `Contracts`**.
3. **Gdzie jest punkt zatwierdzenia** — jeden gate czy dwa (osobno „zatwierdź plan", osobno „zatwierdź diff").

Fundament częściowo istnieje: scaffold `src/Agent/` (epik 0.1 #19), BYOK zaimplementowany (ADR-0017: `TenantAgentConfig` + `ByokKeyManager` + `AesGcmEncryptionService`), warstwa Deptrac `Agent`. Brakuje trzech „huków", które PRD zakładał jako gotowe w MVP, a których w kodzie nie ma: tabela `pending_changes`, subscriber `EntityChanged`, case `Provenance::Agent` (świadomie odroczony w enumie). Ten ADR ustala granice; huki dorabiają tickety M0.

## Decision Drivers

- **Open-core wydzielalność jest wymogiem biznesowym**, nie preferencją — model monetyzacji stoi na „darmowy rdzeń + płatny agent".
- **Zero drugiej ścieżki zapisu** — agent musi iść tym samym sprawdzonym silnikiem co ręczna edycja (walidacje, completeness, indeksowanie, rollback za darmo).
- **Zgodność z Deptrac** — cross-BC wyłącznie przez `*\Contracts\*` (ADR-0013); Agent nie sięga internals.
- **Bezpieczeństwo bez kompromisów** — RBAC per tool-call, approval-first, rollback, provenance, audyt, limity §8.5, BYOK (obrona w głąb, PRD §11.5).
- **Powierzchnia rośnie sama** — dodanie narzędzia = nowy port + adapter + wpis w rejestrze; zero zmian w pętli agenta.
- **Prompt-injection backstopem jest człowiek + RBAC**, nie klasyfikatory (PRD §10.5) — decyzja wymaga, by RBAC działał na *każdym* tool-callu, a nie raz przy wejściu.

## Considered Options

1. **Agent jako zwykły BC z zależnościami do Application innych kontekstów** — najprościej wołać handlery bezpośrednio. Odrzucony: łamie Deptrac (Agent→Internals) i wydzielalność (twarde zależności), a przy usunięciu modułu nic by się nie skompilowało po drugiej stronie.
2. **Agent jako usuwalny BC; każde narzędzie = port `Contracts` w BC-gospodarzu + cienki adapter w `src/Agent/`** — jednokierunkowa zależność Agent → `*_Contracts`, rejestr deklaratywny, single approval gate przez `pending_changes`.
3. **Osobny mikroserwis agenta poza monolitem** — pełna izolacja fizyczna, ale duplikacja auth/RBAC/tenant-context + sieć + brak reużycia silników in-process. Odrzucony jako gruby overhead niespójny z „cienką warstwą".

## Decision Outcome

Chosen option: **Option 2** — agent to w pełni wydzielalny BC, którego każde narzędzie jest cienkim adapterem nad portem `Contracts` istniejącego silnika, z jednym punktem zatwierdzenia przez `pending_changes`. Trzy rozstrzygnięcia:

### (a) `src/Agent/` jako usuwalny bounded context

- Cały kod agenta w `src/Agent/` (Symfony bundle) + feature w `apps/admin` za feature-flagiem; huki współdzielone (`pending_changes`, `Provenance::Agent`, `EntityChanged`) żyją w **core** i istnieją niezależnie od tego, czy ktoś ich słucha.
- **Zero zależności core→Agent.** Żaden BC nie importuje `App\Agent\*`. Egzekwuje to Deptrac: warstwa `Agent` nie występuje w ruleset żadnego innego kontekstu, więc import `App\Agent\*` skądkolwiek = violation (fail-closed, bez potrzeby dodatkowej reguły „nikt nie importuje Agent").
- Komunikacja core→agent wyłącznie przez zdarzenia (`EntityChanged`) i współdzielone huki. Migracje encji agenta (`agent_runs`, `agent_messages`, `agent_tool_calls`) w namespace modułu; huki core w migracjach core.
- **Test wydzielalności jest bramką DoD i jobem CI:** build + core test-suite na drzewie z `rm -rf src/Agent` + FE flag off → zielone, UI degraduje się gracefully (znika chat/Cmd+K-agent/inbox agenta).

### (b) Rejestr narzędzi + kontrakt „narzędzie = adapter nad portem Contracts silnika"

- Każde narzędzie deklaruje: `name`, `description` (dla modelu), schemat parametrów, wymagany permission RBAC, `kind` (`read` | `write` | `schema` | `action`).
- **Model dostaje tylko narzędzia, do których zalogowany user ma uprawnienie** (rejestr filtrowany per user). Każdy tool-call przechodzi ten sam voter/permission-check co ręczna akcja (per-atrybut/locale/kanał, `PRD-PIM-rbac.md`) — to twarda granica czyniąca prompt-injection niegroźnym poza scope usera.
- **Implementacja każdego narzędzia to para:** (1) **port w `*/Contracts/`** BC-gospodarza (np. `Catalog\Contracts\Command\BulkEditValuesPort`, `Import\Contracts\SchemaImportPort`, `Export\Contracts\ExportTriggerPort`, `Search\Contracts\CatalogQueryPort`) + (2) **adapter w `src/Agent/Application/Tool/`** zależny wyłącznie od tego portu. Silniki, które dziś nie mają Contracts, dostają port w swoim tickecie — to jedyny dozwolony sposób wystawienia ich agentowi.
- **Wybór modelu per kind:** `schema` → Claude Opus (większy blast radius), reszta → Claude Sonnet (`PRD-PIM-agent.md` §10.1). Klient Anthropic bierze klucz z `ByokKeyManager::resolveKey($tenant)` (BYOK, ADR-0017).
- Dodanie narzędzia = port + adapter + wpis w rejestrze; **zero zmian w pętli agenta**. „Co agent umie" = deterministyczna funkcja „jakie porty Contracts istnieją".

### (c) Single approval gate przez `pending_changes`

- Agent **materializuje plan jako konkretne diffy w `pending_changes`** — i to *jest* plan pokazany operatorowi (realne liczby: „1 800 wierszy `price: ∅ → 100`"). Brak osobnego kroku „zatwierdź plan zanim policzę diffy" — plan i diff to ten sam artefakt.
- Autonomia agenta kończy się **przed** approvalem: kroki plan→dopytanie→materializacja są autonomiczne; commit do katalogu jest zawsze po akcepcie człowieka.
- Po akcepcie zmiany idą przez **istniejący bulk-path** (`BatchValueWriter` + `AbstractBulkHandler`, `Provenance::Agent`), audyt w DH Auditor, a cały batch jest cofalny przez **istniejący undo-log** (`BulkRollbackHandler`, IMP2-2.4). MVP: approval all-or-nothing (partial = hook); 1 aktywny run/user (reuse `BulkOperationLock`).

### Consequences

- **Positive:** open-core spełniony (moduł usuwalny, test w CI); zero drugiej ścieżki zapisu (agent = ręczna edycja przez ten sam silnik); powierzchnia narzędzi rośnie bez dotykania pętli; obrona w głąb (RBAC/approval/rollback/provenance/audyt/limity/BYOK); prompt-injection ograniczony przez RBAC per tool-call.
- **Negative:** każdy silnik bez Contracts wymaga dorobienia portu (jednorazowy koszt per narzędzie); huki (`pending_changes`/`EntityChanged`/`Provenance::Agent`) trzeba faktycznie zbudować (PRD zakładał, że są); dwie klasy modeli (Sonnet/Opus) w jednej pętli.
- **Follow-ups (hooki, PRD §13.4/§13.5):** proaktywny data steward; AI-assisted auto-mapping; wieloetapowe intencje wysokiego poziomu; webhooks agenta; agent na innych ObjectType; konfigurowalny poziom autonomii per rola; shared-key trial obok BYOK; klasyfikatory prompt-injection/sandbox (gdy approval+RBAC okaże się niewystarczające).

## Alternatives Considered

- **Bezpośrednie wołanie handlerów Application** — odrzucone: łamie Deptrac i wydzielalność; usunięcie modułu psuje build po stronie zależnej.
- **Mikroserwis agenta** — odrzucony: duplikacja auth/RBAC/tenant-context + narzut sieciowy + utrata reużycia in-process silników.
- **Dwa gate'y (plan, potem diff)** — odrzucone: dubluje decyzję operatora bez wartości; plan z groundingu *jest* już diffem w `pending_changes`.
- **Własny log audytu agenta** — odrzucone: DH Auditor (`Identity\Contracts\Audit`) już daje append-only „kto/co/kiedy"; osobny log rozjeżdża accountability.

## Links

- PRD: `Project Plan/PRD/PRD-PIM-agent.md` (§5 model operacyjny, §5.5 tool-surface, §5.6 cienka warstwa, §10 AI/limity, §11 architektura/wydzielalność)
- Plan: `Project Plan/02-plan-projektu-pim.md` (epik 0.7 Agent layer); Architektura §8.5 (limity agenta), §3.10 (memory FrankenPHP)
- Backlog: `Project Plan/feature-agent-tickets.md` (epik 0.7, ~45 ticketów, M0–M9)
- Related ADRs: ADR-0012 (CQRS Application), ADR-0013 (Deptrac rollout Internals/Contracts), ADR-0015 (bare UUID cross-BC), ADR-0017 (BYOK AES-256-GCM — klucz Anthropic tenanta), ADR-0019 (import engine contracts), ADR-0020 (OpenAPI custom route), ADR-0022 (consumer/producer boundary)
- Tickets: AGENT-P0-01 (finalizacja tego ADR)
