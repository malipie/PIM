import type { AuthType } from '../../components/primitives';

/** A single default-header row in the connection form. */
export interface HeaderRow {
  k: string;
  v: string;
}

/**
 * Client-side state for the connection wizard. Credential fields are kept flat
 * per auth scheme and folded into the API `credentials` map only on submit, so
 * switching auth type never leaks the wrong shape to the backend.
 */
export interface WizardForm {
  name: string;
  code: string;
  baseUrl: string;
  authType: AuthType;
  apiKeyHeader: string;
  apiKeyValue: string;
  bearer: string;
  basicUser: string;
  basicPass: string;
  oauthToken: string;
  headers: HeaderRow[];
  rateLimit: string;
}

/** Shape returned by `POST /api/connections/{id}/test` (APIC-P1-05). */
export interface ConnectionTestResult {
  ok: boolean;
  http_status?: number;
  latency_ms?: number;
  size_bytes?: number;
  content_type?: string | null;
  sample?: string;
  error?: string;
  status: string;
  checked_at: string;
}

export const INITIAL_FORM: WizardForm = {
  name: '',
  code: '',
  baseUrl: 'https://',
  authType: 'api_key',
  apiKeyHeader: 'X-API-Key',
  apiKeyValue: '',
  bearer: '',
  basicUser: '',
  basicPass: '',
  oauthToken: '',
  headers: [{ k: 'Accept', v: 'application/json' }],
  rateLimit: '600',
};

/**
 * Slug for the immutable `code` — the backend enforces `^[a-z0-9-]+$`, so we
 * collapse to hyphens (not underscores) and trim leading/trailing separators.
 */
export function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

/** Folds the flat per-scheme credential fields into the API `credentials` map. */
export function credentialsFor(form: WizardForm): Record<string, string> {
  switch (form.authType) {
    case 'api_key':
      return { header: form.apiKeyHeader, value: form.apiKeyValue };
    case 'bearer':
      return { token: form.bearer };
    case 'oauth2_token':
      return { token: form.oauthToken };
    case 'basic':
      return { user: form.basicUser, pass: form.basicPass };
    default:
      return {};
  }
}

/** Collapses header rows to an object, dropping rows with an empty key. */
export function headersFor(form: WizardForm): Record<string, string> {
  const out: Record<string, string> = {};
  for (const row of form.headers) {
    const key = row.k.trim();
    if (key !== '') {
      out[key] = row.v;
    }
  }
  return out;
}

/**
 * Builds the `ConnectionInput` POST body (APIC-P1-06). `rateLimitHint` is null
 * when the field is blank or non-numeric so the backend's `Positive` constraint
 * never sees a 0.
 */
export function toConnectionInput(form: WizardForm): Record<string, unknown> {
  const rate = Number.parseInt(form.rateLimit, 10);
  return {
    code: form.code,
    name: form.name.trim(),
    baseUrl: form.baseUrl.trim(),
    authType: form.authType,
    credentials: credentialsFor(form),
    defaultHeaders: headersFor(form),
    rateLimitHint: Number.isFinite(rate) && rate > 0 ? rate : null,
  };
}

/**
 * #2630 — true when the user actually typed a secret for the selected auth
 * scheme. The API never returns credentials (write-only), so in edit mode the
 * fields start blank and a PATCH must omit `credentials` entirely unless new
 * ones were entered — otherwise the stored secret would be rotated to blanks.
 */
export function hasCredentialInput(form: WizardForm): boolean {
  switch (form.authType) {
    case 'api_key':
      return form.apiKeyValue.trim() !== '';
    case 'bearer':
      return form.bearer.trim() !== '';
    case 'oauth2_token':
      return form.oauthToken.trim() !== '';
    case 'basic':
      return form.basicUser.trim() !== '' || form.basicPass.trim() !== '';
    default:
      return false;
  }
}

/**
 * #2630 — RFC 7396 PATCH body for an existing connection: `code` is immutable
 * (omitted) and `credentials` ride along only when the user typed new ones.
 */
export function toConnectionPatch(form: WizardForm): Record<string, unknown> {
  const { code: _code, credentials, ...rest } = toConnectionInput(form);
  return hasCredentialInput(form) ? { ...rest, credentials } : rest;
}

/** The subset of `GET /api/connections/{id}` the wizard prefills from. */
export interface ConnectionPrefill {
  code: string;
  name: string;
  baseUrl: string;
  authType: AuthType;
  defaultHeaders?: Record<string, string> | null;
  rateLimitHint?: number | null;
}

/**
 * #2630 — maps a persisted connection onto the wizard form for edit mode.
 * Credential fields stay blank (the API is write-only for secrets); the
 * api_key header name is part of the credentials map, so it falls back to the
 * INITIAL_FORM default.
 */
export function connectionToForm(row: ConnectionPrefill): WizardForm {
  const headers = Object.entries(row.defaultHeaders ?? {}).map(([k, v]) => ({ k, v }));
  return {
    ...INITIAL_FORM,
    name: row.name,
    code: row.code,
    baseUrl: row.baseUrl,
    authType: row.authType,
    headers: headers.length > 0 ? headers : [{ k: '', v: '' }],
    rateLimit: row.rateLimitHint != null ? String(row.rateLimitHint) : '',
  };
}
