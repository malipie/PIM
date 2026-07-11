import { HttpError, jsonFetch } from '@/lib/http';

/**
 * WFL-P5-03 (#2433) — typed client for /api/workflow/definitions
 * (pakiet G CRUD). Definitions are inert configuration until the
 * deployment runs with WORKFLOW_CUSTOM_DEFINITIONS on AND the
 * definition is enabled.
 */

export interface DefinitionPlace {
  name: string;
  /** Multilingual label {pl, en} — optional, machine name is the fallback. */
  label?: Record<string, string>;
  /** Hex or token color for the status pill preview. */
  color?: string;
}

export interface DefinitionTransition {
  name: string;
  from: string | string[];
  to: string;
  permission?: string;
  comment_required?: boolean;
  completeness_gate?: { min_completeness_pct: number };
}

export interface WorkflowDefinitionResource {
  id: string;
  name: string;
  object_type_id: string | null;
  places: DefinitionPlace[];
  transitions: DefinitionTransition[];
  enabled: boolean;
  updated_at: string;
}

export interface DefinitionPayload {
  name: string;
  object_type_id?: string;
  places: DefinitionPlace[];
  transitions: DefinitionTransition[];
}

/** Field-scoped validator violation from the 422 response. */
export interface DefinitionViolation {
  field: string;
  message: string;
}

export function fetchDefinitions(): Promise<{ items: WorkflowDefinitionResource[] }> {
  return jsonFetch('/api/workflow/definitions');
}

export function createDefinition(payload: DefinitionPayload): Promise<WorkflowDefinitionResource> {
  return jsonFetch('/api/workflow/definitions', {
    method: 'POST',
    contentType: 'application/json',
    body: payload,
  });
}

export function updateDefinition(
  id: string,
  payload: DefinitionPayload,
): Promise<WorkflowDefinitionResource> {
  return jsonFetch(`/api/workflow/definitions/${id}`, {
    method: 'PUT',
    contentType: 'application/json',
    body: payload,
  });
}

export function setDefinitionEnabled(
  id: string,
  enabled: boolean,
): Promise<WorkflowDefinitionResource> {
  return jsonFetch(`/api/workflow/definitions/${id}/${enabled ? 'enable' : 'disable'}`, {
    method: 'POST',
  });
}

/**
 * The definitions endpoints answer invalid shapes with 422 whose body is
 * `{"violations": [{field, message}]}` — extract them for inline field
 * errors; anything else returns an empty list (generic toast path).
 */
export function extractViolations(error: unknown): DefinitionViolation[] {
  if (!(error instanceof HttpError) || error.status !== 422) return [];
  const body: unknown = error.body;
  if (typeof body !== 'object' || body === null) return [];
  const detail = (body as { detail?: unknown }).detail;
  const source = typeof detail === 'string' ? safeParse(detail) : body;
  if (typeof source !== 'object' || source === null) return [];
  const violations = (source as { violations?: unknown }).violations;
  if (!Array.isArray(violations)) return [];
  return violations.filter(
    (entry): entry is DefinitionViolation =>
      typeof entry === 'object' &&
      entry !== null &&
      typeof (entry as { field?: unknown }).field === 'string' &&
      typeof (entry as { message?: unknown }).message === 'string',
  );
}

function safeParse(raw: string): unknown {
  try {
    return JSON.parse(raw) as unknown;
  } catch {
    return null;
  }
}
