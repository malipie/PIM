import { jsonFetch } from '@/lib/http';

/**
 * AGENT-P6-01 (#1974) — typed client for the public agent API (P4-01).
 * The admin uses exactly the endpoints integrators use (ADR-0020).
 */

export type AgentRunStatus =
  | 'planning'
  | 'awaiting_input'
  | 'awaiting_approval'
  | 'committing'
  | 'done'
  | 'rejected'
  | 'cancelled'
  | 'error'
  | 'rolled_back';

export interface AgentRunSummary {
  id: string;
  status: AgentRunStatus;
  surface: 'chat' | 'cmdk' | 'proactive';
  intent: string;
  model: string | null;
  pending_change_batch_id: string | null;
  affected_count: number | null;
  bulk_operation_id: string | null;
  tokens_input: number;
  tokens_output: number;
  cost_usd: string;
  approved_by: string | null;
  approved_at: string | null;
  started_at: string;
  completed_at: string | null;
}

export interface AgentMessageBlock {
  type?: string;
  text?: string;
  name?: string;
  [key: string]: unknown;
}

export interface AgentMessageDto {
  id: string;
  role: 'user' | 'assistant' | 'tool';
  content: AgentMessageBlock[];
  created_at: string;
}

export interface AgentToolCallDto {
  id: string;
  tool: string;
  kind: string;
  arguments: Record<string, unknown>;
  result_summary: Record<string, unknown> | null;
  duration_ms: number | null;
  created_at: string;
}

export interface AgentRunDetail extends AgentRunSummary {
  context: Record<string, unknown>;
  error_message: string | null;
  messages: AgentMessageDto[];
  tool_calls: AgentToolCallDto[];
}

const JSON_OPTS = { contentType: 'application/json', accept: 'application/json' } as const;

export function startAgentRun(
  intent: string,
  surface: 'chat' | 'cmdk',
  context: Record<string, unknown> = {},
): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>('/api/agent/runs', {
    ...JSON_OPTS,
    method: 'POST',
    body: { intent, surface, context },
  });
}

export function getAgentRun(id: string): Promise<AgentRunDetail> {
  return jsonFetch<AgentRunDetail>(`/api/agent/runs/${id}`, JSON_OPTS);
}

export function listAgentRuns(
  page = 1,
  perPage = 20,
): Promise<{ items: AgentRunSummary[]; page: number; per_page: number }> {
  return jsonFetch(`/api/agent/runs`, { ...JSON_OPTS, query: { page, per_page: perPage } });
}

export function sendAgentMessage(id: string, message: string): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>(`/api/agent/runs/${id}/messages`, {
    ...JSON_OPTS,
    method: 'POST',
    body: { message },
  });
}

export function approveAgentRun(id: string): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>(`/api/agent/runs/${id}/approve`, {
    ...JSON_OPTS,
    method: 'POST',
    body: {},
  });
}

export function rejectAgentRun(id: string): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>(`/api/agent/runs/${id}/reject`, {
    ...JSON_OPTS,
    method: 'POST',
    body: {},
  });
}

export function rollbackAgentRun(id: string): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>(`/api/agent/runs/${id}/rollback`, {
    ...JSON_OPTS,
    method: 'POST',
    body: {},
  });
}

export function cancelAgentRun(id: string): Promise<AgentRunSummary> {
  return jsonFetch<AgentRunSummary>(`/api/agent/runs/${id}/cancel`, {
    ...JSON_OPTS,
    method: 'POST',
    body: {},
  });
}

export interface AgentPlanRow {
  id: string;
  change_type: 'value' | 'schema' | 'category';
  status: string;
  target_object_id: string | null;
  attribute_code: string | null;
  scope_locale: string | null;
  scope_channel: string | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  provenance: string;
}

export function getAgentPlan(
  id: string,
  page = 1,
  perPage = 50,
): Promise<{ items: AgentPlanRow[]; total: number; page: number; per_page: number }> {
  return jsonFetch(`/api/agent/runs/${id}/plan`, {
    ...JSON_OPTS,
    query: { page, per_page: perPage },
  });
}

/** Statuses in which the loop is still working server-side (poll). */
export interface AgentCostReport {
  cost_today_usd: string;
  cost_month_usd: string;
  tokens_today: number;
  tokens_month: number;
  runs_today: number;
  runs_month: number;
  day_cap_usd: number;
  month_cap_usd: number;
  day_cap_pct: number;
  month_cap_pct: number;
  per_user_today: Array<{ user_id: string; runs: number; tokens: number; cost_usd: string }>;
}

export function getAgentCost(): Promise<AgentCostReport> {
  return jsonFetch<AgentCostReport>('/api/agent/cost', JSON_OPTS);
}

export function isRunBusy(status: AgentRunStatus): boolean {
  return status === 'planning' || status === 'committing';
}

export function isRunTerminal(status: AgentRunStatus): boolean {
  return (
    status === 'done' ||
    status === 'rejected' ||
    status === 'cancelled' ||
    status === 'error' ||
    status === 'rolled_back'
  );
}
