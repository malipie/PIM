/**
 * AGENT-P6-01 (#1974) — build-time feature flags.
 *
 * The agent UI must disappear wholesale when the removable Agent BC is
 * off (ADR-0024): the CI removability job builds the admin with
 * VITE_AGENT_ENABLED=false and greps for leftovers. Default is ON for
 * dev/prod builds; only the explicit string 'false' disables.
 */
export const AGENT_ENABLED: boolean = import.meta.env.VITE_AGENT_ENABLED !== 'false';
