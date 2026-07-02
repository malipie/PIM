# Agent — Bounded Context

> **Status:** under active implementation — epic 0.7 (issues #1944–#1992,
> milestones M0–M9). Backlog: `Project Plan/feature-agent-tickets.md`.
> Governing decision: **ADR-0024** (removable BC, tool registry, single
> approval gate). Feature PRD: `Project Plan/PRD/PRD-PIM-agent.md`.

Houses the LLM-driven catalog/schema-operation surface: chat-as-first-class
interaction, tool calls, pending changes (human approval flow), audit log.
Anthropic SDK PHP — Claude Sonnet by default, Claude Opus for schema-ops.

**Removability (open-core, ADR-0024):** this whole directory is deletable.
No core BC may import `App\Agent\*` (fail-closed via deptrac: the Agent
layer appears in no other ruleset). Every tool is a thin adapter over a
`*\Contracts\*` port of the host engine — the agent has no domain logic of
its own. CI runs a removability job (`rm -rf src/Agent` → build + core
suite must stay green).

## Layer responsibilities (DDD)

- `Domain/` — `AgentRun`, `ToolCall`, `PendingChange`, value objects (`TokenBudget`,
  `ToolCallLimit`).
- `Application/` — orchestration, budget enforcement, approval workflow.
- `Infrastructure/` — Anthropic SDK client, tool dispatcher, audit persistence.
- `Presentation/` — `/api/agent/run` endpoint, SSE streaming, approval UI hooks.

## Hard limits (non-negotiable, architecture §8.5)

- 50 tool calls / hour / user
- 10 tool calls / agent run
- 100k tokens / run
- 500k tokens / day / user
- $20 / day / tenant, $300 / month / tenant
- Org-level monthly cap $1000 in Anthropic Console (independent hardstop)

After 100% — agent disabled until midnight UTC. BYOK for enterprise (key
encrypted AES-256-GCM).

## Core hooks (live outside this directory)

Three shared hooks live in **core** (Catalog), exist independently of the
agent, and survive its removal. Contrary to what earlier revisions of this
README claimed, none of them pre-existed — they are built by epic 0.7 M0
tickets:

- `pending_changes` table + `Catalog\Contracts\PendingChanges` port — AGENT-P0-03 (#1946).
- `Provenance::Agent` enum case + `provenance_meta` shape — AGENT-P0-04 (#1947).
- Doctrine lifecycle subscriber emitting `EntityChanged` — AGENT-P0-05 (#1948).

Those land outside this directory but exist *for* this context.
