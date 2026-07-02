# Threat model — Agent layer (STRIDE)

> AGENT-P9-03 (#1990). Scope: the agentic admin (epik 0.7) and its interaction
> with the catalog, RBAC and BYOK. Complements the RBAC threat model in
> `Project Plan/07-rbac-implementation-plan.md` §5. Update after each red-team
> round (`docs/security/agent-red-team-checklist.md`) and after any new attack
> vector surfaces.

## Assets

- **Catalog data** (product values, schema/attributes) — integrity is the prize
  of a prompt-injection attack.
- **Tenant BYOK Anthropic key** — AES-256-GCM at rest; a leak is account
  takeover on the tenant's Anthropic account.
- **Cross-tenant isolation** — one tenant's run must never read or write
  another's data.
- **Audit trail** (`agent_runs`, `agent_tool_calls`, `audit_logs`) —
  accountability for "who approved what".
- **Spend** — the §8.5 budgets protect against runaway cost / a compromised
  loop draining the key.

## The seven defence layers (§11.5)

1. **RBAC on every tool call** — the loop has no security token; the registry
   filters tools by the initiating user's permissions (`ToolRegistry::
   availableFor`) and the executor re-checks on execute. Fine-grained
   per-attribute/locale/channel checks run inside the tools by user id.
2. **Single human approval gate** — no write tool commits; it materializes a
   `pending_changes` batch (plan == diff, ADR-0024 c). The operator sees the
   real before→after, not the model's narration.
3. **Tenant isolation** — Doctrine `TenantFilter` on SELECTs + explicit
   `tenant_id` predicates on the raw SQL bulk paths + Postgres RLS (FORCE) as
   the backstop.
4. **Per-role autonomy** (`roles.agent_autonomy`) — `off` / `read_only` /
   `propose`; write/schema stay behind approval at every level.
5. **§8.5 budgets + kill-switch** — in-run (10 calls, 100k tokens) and windowed
   (50/h/user, 500k/day/user, $20/day, $300/month per tenant); exceeding a
   window disables the agent until it rolls over.
6. **BYOK isolation** — the key is encrypted, never returned by any endpoint
   (only the display prefix), resolved per tenant at call time; no key ⇒ agent
   off (fail-closed feature guard).
7. **Append-only audit** — every tool call (allowed / forbidden / crashed) and
   every decision (start / commit / reject / cancel / rollback) lands in the DH
   Auditor with the actor and, for decisions, the approver.

## STRIDE

| Category | Vector | Mitigation | Residual / hook |
|----------|--------|------------|-----------------|
| **Spoofing** | Run attributed to the wrong user / off-request worker | Actor = run owner id carried explicitly into audit (not the request token); approver recorded separately | — |
| **Tampering** | Prompt injection in a description/schema orders a mass edit | Layers 1+2: RBAC bounds the surface, approval stops every write; red-team P9-01 proves zero writes pre-accept | Prompt classifier / tool sandbox = §13.5 hook if a vector ever defeats approval |
| **Tampering** | Compromised tool suppresses its own audit row by clearing the EM | Executor re-fetches a managed run and persists the audit row regardless (P9-01 hardening) | — |
| **Repudiation** | "The agent did it, not me" | Append-only `agent_runs` + `agent_tool_calls` + `audit_logs`; approver ≠ actor | — |
| **Information disclosure** | BYOK key exfiltrated via the model or an endpoint | Key encrypted, never in any response, resolved server-side; the model only ever sees tool *results* (minimal projections), never the key | Transparency copy in Settings lists exactly what reaches the model |
| **Information disclosure** | Cross-tenant read through a crafted filter | Layer 3 (filter + explicit predicate + RLS); red-team P9-01 cross-tenant test | — |
| **Denial of service** | Runaway loop drains the key / spend | Layer 5 in-run + windowed caps, kill-switch; one active run per user (partial unique index) | — |
| **Elevation of privilege** | Model calls a tool outside the user's permissions | Layer 1 per-call RBAC refuses + audits (`forbidden`); red-team P9-01 escalation test | — |
| **Elevation of privilege** | Schema rollback destroys data (delete-with-values) | P5-04 dataless-only boundary blocks it with a reason | — |

## Security review checklist (PRs touching auth / agent)

- [ ] New tool: declares the narrowest `requiredPermission`; write/schema kind
      routes through `pending_changes` (never commits directly).
- [ ] New cross-BC reach: goes through a `*\Contracts\*` port (Deptrac green),
      not internals.
- [ ] Raw SQL: explicit `tenant_id` predicate + `// tenant-safe:` comment.
- [ ] New endpoint: `AgentFeatureGuard::assertEnabled` (if agent) + RBAC
      attribute + RFC 7807 errors + ownership scoping (own-run ⇒ 404, not 403).
- [ ] Any new agent lifecycle transition: audited via `AgentActionAuditor`.
- [ ] Removability: agent DI stays in `services_agent.yaml`; no `App\Agent`
      reference leaks into core `src`/`config`/`tests` (CI `agent-removability`).
- [ ] Secrets: no plaintext key in responses/logs; BYOK stays encrypted.
