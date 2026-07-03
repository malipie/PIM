# Epik 0.7 „Agent layer" — raport końcowy (AGENT-P9-05, #1992)

Domknięcie epiku agentowego (49 ticketów, #1944–#1992). Wszystkie milestone'y
M0–M9 dostarczone; kod w łańcuchu stackowanych PR-ów #2085–#2117.

## Zakres dostarczony

| Milestone | Zakres | PR-y |
|-----------|--------|------|
| M0 spine | ADR-0024, Deptrac warstwy, pending_changes, rejestr narzędzi, SDK+BYOK, removability job | #2052 + M0 (merged) |
| M1 rdzeń | encje runu, RBAC per tool-call, pętla async, limity §8.5, Mercure | #2079/#2080 + P1-01/02/05 |
| M2 read | search_catalog, aggregate_count, completeness_report | #2069/#2090/#2084 |
| M3 write | bulk_edit_values → approve→commit → reject/cancel → rollback → assign_categories → trigger_export → audyt | #2085–#2089, #2091, #2092 |
| M4 API | 8 endpointów /api/agent/*, OpenAPI snapshot | #2093/#2094 |
| M5 schema-ops (Opus) | create_attributes_from_schema, modeling tools, per-kind model, schema rollback | #2096–#2099 |
| M6 frontend | chat panel, Cmd+K real, inbox diff, historia+rollback, badge, BYOK UI, SSE | #2100–#2106 |
| M7 engine-gated | suggest_feed_structure/generate_feed (XMLF), publish_to_channel (integracje) | #2107/#2108 |
| M8 proaktywność | data steward, AI-mapping, multi-step intents, webhooks, ObjectTypes+autonomia | #2109–#2113 |
| M9 hardening | red-team, cost dashboard, threat-model, docs, ten raport | #2115–#2117 |

## Dowód usuwalności (open-core, ADR-0024)

Symulacja na drzewie agentowym: po `rm -rf src/Agent config/services_agent.yaml
config/packages/agent.yaml tests/{Unit,Integration,Api}/Agent` — `grep App\Agent
src tests config` = **zero referencji**, core kompiluje z `AGENT_ENABLED=0`
(`lint:container` zielony). Bramka `agent-removability` egzekwuje to na każdym PR
i na finalnym merge epiku.

## Dowód działania (deterministyczny, bez klucza BYOK)

Cały cykl UC2 (bez ceny → 100) jest pokryty testami integracyjnymi na realnym
Postgresie ze skryptowanym `AgentLlmClientInterface` (deterministyczne, bez
sieci): materializacja → approve→commit (provenance=agent) → rollback. UC1
(schema z IdoSell) analogicznie przez `AgentSchemaFlowTest`. Red-team (P9-01)
dowodzi, że skompromitowany model nie przebija się przez approval+RBAC.

## Świadome odejścia (całego epiku)

- **BRAK klucza Anthropic (BYOK) w środowisku** → LLM-live smoke niewykonalny.
  Każdy PR dotykający LLM oznaczony uczciwie „wired + unit/integration green;
  **LLM-live smoke pending BYOK key**". Pełen smoke UC2/UC1 na żywym LLM
  `pim.localhost` = do wykonania przez operatora po wprowadzeniu klucza (kroki:
  `docs/operations/agent-runbook.md`, weryfikacja: inbox diff → approve → psql
  `SELECT provenance FROM object_values`).
- **Regresja main naprawiona po drodze**: zgubiony `imports: services_agent.yaml`
  (agent ładował się przez `App\` bez tagów `agent.tool` → pusty rejestr,
  niewidoczne dla CI) — #2086.
- Projekcja zamiast field-level map w search; aggregate bez median (dodane w
  proaktywnym skanie P8-01); własny unique-index zamiast BulkOperationLock;
  EventDispatcher zamiast Messenger dla EntityChanged; global-only values w
  bulk_edit MVP; publish/feed engine-gated (silniki w Fazie 1); multi-step plan
  w obrębie jednej rodziny zmian; autonomia bez `auto_commit` (sprzeczne z
  single approval gate).
- Hooki §13.5 świadomie odłożone: klasyfikatory/sandbox anty-injection ponad
  approval+RBAC, approval częściowy, delete atrybutu przez agenta, shared-key
  trial, multi-agent współbieżny ponad limit §8.5.

## Następne kroki (poza epikiem)

1. Operator wprowadza klucz BYOK → LLM-live smoke UC2 + UC1 na `pim.localhost`.
2. Faza 1: integracje 0.8/0.9 podmieniają `ChannelPublishPort` (publish_to_channel
   zapala się bez zmian w pętli).
3. Opcjonalny external pentest (P9-03 threat-model jako wejście).
