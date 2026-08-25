# Operator runbook — Agent layer

> AGENT-P9-04 (#1991). Procedures for Piotr (IT/operator): BYOK key setup and
> rotation, the kill-switch, per-tenant off, and rollback. User-facing usage
> docs are in `docs/agent-user-guide.md`.

## 1. BYOK key setup (from zero)

The agent runs on the tenant's own Anthropic key (BYOK). Without an active key
the agent is off.

1. Get an Anthropic API key (`sk-ant-…`) from the tenant's Anthropic Console.
2. In the admin: **Settings → AI**.
3. Paste the key into **Klucz API** and **Zapisz**. The field is write-only —
   only the prefix (`sk-ant-api03-…`) and `last_used_at` are ever shown back.
4. The state flips to **Aktywny — agent włączony**. The agent is now usable via
   the chat panel (Sparkles icon, top bar) and Cmd+K.

Endpoint equivalents (integrators): `PUT /api/settings/agent-key`
`{ "api_key": "sk-ant-…" }`, `GET /api/settings/agent-key` (status, never the
key), `DELETE /api/settings/agent-key` (soft-off).

## 2. Key rotation

Same PUT with the new key — it re-encrypts and replaces the old one atomically.
No downtime; in-flight runs finish on whichever key they started.

## 3. Kill-switch / "agent blocked until midnight"

The §8.5 budgets are the kill-switch. When a window is exceeded the agent
refuses new runs until the window rolls over:

- **tool calls**: 50 / hour / user — rolls over on the sliding hour.
- **tokens**: 500 000 / day / user — rolls over at **00:00 UTC**.
- **cost**: $20 / day and $300 / month per tenant — day rolls at 00:00 UTC,
  month on the 1st.

There is no manual "unblock" — that is the design (a compromised or runaway
loop cannot be un-throttled by a single click). To lift a block early, raise the
cap (`pim.agent.limits.*` env, then restart `api` and `agent-worker`) — do this only with
a clear reason. Watch spend in **Settings → AI → Koszt i limity** or scrape
`GET /api/agent/cost?format=prometheus`.

## 4. Agent off per tenant

**Settings → AI → Wyłącz agenta** (or `DELETE /api/settings/agent-key`)
soft-disables: the key is retained (disabled), and every run start returns 403.
Re-enable by saving a key again (PUT). To remove the agent surface entirely at
build time, ship the admin with `VITE_AGENT_ENABLED=false`.

## 5. Rollback ("Cofnij tę operację")

Every committed run is reversible within 24 h:

1. **Settings → Agent → Historia** (`/agent/history`).
2. Find the `done` run, press **Cofnij tę operację**.
3. Value edits and category assignments revert cleanly; the status becomes
   `rolled_back`.

**Schema-op boundary (P5-04):** a rollback that would delete an attribute which
already carries values (someone filled it after the commit) is **blocked** with
a reason — the run stays `done`. Decide: keep the attribute, or clear the data
manually first, then retry. Rollback never destroys data silently.

## 6. Incident: suspected prompt-injection

1. The approval gate is the backstop — check the inbox: a malicious plan shows
   the sabotage in the diff (e.g. `price: 250 → 0`). **Reject it.**
2. Review `audit_logs` (resource_type=`agent_run`) and `agent_tool_calls` for
   the run — forbidden attempts are recorded.
3. If a vector reached the catalog, roll the run back (§5) and open a security
   issue referencing `docs/security/agent-red-team-checklist.md`.

## 7. Proactive scan (opt-in)

`Settings → AI` toggle (`PATCH /api/settings/agent-key`
`{ "proactive_scan_enabled": true }`) enables the scheduled data-steward scan.
Run it via `pim:agent:proactive-scan <tenant-code> <steward-user-id>`; findings
open a `proactive` run in the inbox — nothing commits without approval.

## 8. Slow or stuck interactive run

Interactive turns use the dedicated Messenger transport `agent` and the
dedicated `agent-worker`. Imports and bulk content continue on `import`, so a
large job must not create head-of-line blocking for chat.

1. Check the consumer: `docker compose ps agent-worker` and
   `docker compose logs --tail=200 agent-worker`.
2. Check backlog: `docker compose exec api php bin/console messenger:stats agent`.
3. Check latency telemetry in `GET /api/agent/cost?format=prometheus`:
   `agent_queue_delay_ms_today`, `agent_llm_ttft_ms_today` and
   `agent_llm_duration_ms_today`. Queue delay diagnoses worker capacity; TTFT
   diagnoses provider/model latency.
4. Restart only the interactive consumer when needed:
   `docker compose up -d --force-recreate agent-worker`. Do not restart the
   bulk worker merely to recover the agent queue.

Each run response also exposes `queue_delay_ms`, `llm_duration_ms`,
`llm_ttft_ms`, `cache_read_tokens` and `cache_creation_tokens`. The worker logs
the same values with `run_id` and model, without prompts or catalog data.
