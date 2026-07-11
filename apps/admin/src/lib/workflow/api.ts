import { jsonFetch } from '@/lib/http';

/**
 * Pakiet E (WFL-P3-01/#2423 + WFL-P3-05/#2427) — typed client for the
 * object_editorial workflow surface (WFL-P0-05). Discovery is the FE
 * source of truth for which buttons render enabled: blockers carry the
 * machine codes (missing permission / completeness_gate), so the UI
 * never re-implements guard logic (ADR-0029).
 */

export interface WorkflowTransitionOption {
  name: string;
  to: string;
  enabled: boolean;
  blockers: { code: string; message: string }[];
}

export interface WorkflowState {
  object_id: string;
  workflow: string;
  current_place: string;
  transitions: WorkflowTransitionOption[];
}

export interface WorkflowLogEntry {
  id: string;
  transition: string;
  from: string;
  to: string;
  actor_user_id: string | null;
  comment: string | null;
  context: Record<string, unknown> | null;
  created_at: string;
}

export interface WorkflowLogPage {
  object_id: string;
  items: WorkflowLogEntry[];
  next_cursor: string | null;
}

export function fetchWorkflowState(objectId: string): Promise<WorkflowState> {
  return jsonFetch<WorkflowState>(`/api/objects/${objectId}/workflow`);
}

export function applyWorkflowTransition(
  objectId: string,
  transition: string,
  comment?: string,
): Promise<{ object_id: string; applied: string; current_place: string }> {
  return jsonFetch(`/api/objects/${objectId}/workflow/transitions/${transition}`, {
    method: 'POST',
    contentType: 'application/json',
    body: comment !== undefined && comment !== '' ? { comment } : {},
  });
}

export function fetchWorkflowLog(
  objectId: string,
  before?: string,
  limit = 20,
): Promise<WorkflowLogPage> {
  const cursor = before !== undefined ? `&before=${before}` : '';
  return jsonFetch<WorkflowLogPage>(
    `/api/objects/${objectId}/workflow/transitions?limit=${limit}${cursor}`,
  );
}

/** Editorial places -> StatusPill variants (ui-v2 status-maps palette). */
export function placePillVariant(
  place: string,
): 'success' | 'warning' | 'queued' | 'cancelled' | 'running' {
  switch (place) {
    case 'published':
      return 'success';
    case 'review':
      return 'warning';
    case 'archived':
      return 'cancelled';
    default:
      return 'queued';
  }
}
