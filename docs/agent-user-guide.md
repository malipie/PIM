# Agent — user guide

> AGENT-P9-04 (#1991). How Kasia/Magda/Marcin use the PIM agent. Operator/BYOK
> procedures are in `docs/operations/agent-runbook.md`.

## What the agent is

A conversational assistant for the catalog. You describe what you want in plain
language; the agent grounds it in real numbers, proposes a change, and **nothing
is written until you approve it**. It works within your permissions — it can
only do what your role lets you do.

## Two ways in (same backend)

- **Chat panel** — the Sparkles icon in the top bar. Best for longer,
  multi-step work ("prepare the DE launch for category X") and back-and-forth.
- **Cmd+K** — from any list. Best for a quick command carrying what you're
  looking at (the active filter, your selection). Free-form commands go to the
  agent; the six quick bulk actions stay on the local planner.

## The flow

1. **Say the intent** — e.g. *"ustaw cenę 100 wszystkim produktom bez ceny"*.
2. **The agent grounds it** — it counts the matches first (never guesses) and
   may ask ONE clarifying question.
3. **It proposes a plan** — a materialized diff, not a commit. The run shows
   *awaiting approval*.
4. **You review in the inbox** — `/agent/inbox`: the exact before→after per
   object (`∅ → 100`), the scope (how many objects), the cost/tokens and the
   `agent` provenance badge. This is where you catch anything wrong — a
   malicious product description that tried to hijack the agent shows up here as
   an obvious sabotage, and you just reject it.
5. **Approve or reject** — approve commits through the normal bulk path
   (provenance=agent); reject leaves the catalog untouched.

## Undo

Committed a run and changed your mind? **Settings → Agent → Historia** →
**Cofnij tę operację** within 24 h. Value and category changes revert cleanly. A
schema change (new attribute) can only be undone while the attribute has no data
— once someone filled it, undo is blocked and tells you why.

## Privacy (what reaches the model)

Your Anthropic key is yours (BYOK) and never leaves the system in a response.
The model sees your intent, the view context (object type, filter, selection
counts — not full product data) and the results of the tools it runs (counts,
codes, minimal projections). It never sees your key, passwords, API tokens or
other tenants' data. See **Settings → AI** for the full list. Catalog writes
happen only after your approval.

## If the agent is unavailable

- *"agent wymaga klucza"* — no Anthropic key is configured. Ask your operator
  (see the runbook).
- A run refuses with a limit message — the tenant/your daily budget is spent;
  it resets at midnight UTC (or the operator can raise the cap).
