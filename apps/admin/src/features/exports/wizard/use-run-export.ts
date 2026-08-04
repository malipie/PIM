import { useState } from 'react';

import { HttpError, jsonFetch, rawFetch } from '@/lib/http';

import type { WizardState } from './types';

interface RunResultAsync {
  kind: 'async';
  sessionId: string;
}

interface RunResultSync {
  kind: 'sync';
  filename: string;
}

export type RunResult = RunResultAsync | RunResultSync;

/**
 * #2743 — a real Error subclass, not a bare object literal. Throwing a plain
 * `{status, detail}` lost the stack and slipped past every generic handler
 * doing `err instanceof Error` (which then reported the untranslated 'unknown'
 * fallback instead of the reason).
 */
export class RunError extends Error {
  readonly status: number;
  readonly detail: string;

  constructor(status: number, detail: string) {
    super(detail);
    this.name = 'RunError';
    this.status = status;
    this.detail = detail;
  }
}

/** Build the POST /api/products/export payload from the wizard state. */
export function buildExportPayload(state: WizardState): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    entity_type: state.entityType,
    format: state.format,
    target_scope: state.targetScope,
    selected_columns: state.columns,
    include_variants: true,
    source: state.source,
  };
  if (state.objectTypeId !== null) payload.object_type_id = state.objectTypeId;
  if (state.targetScope === 'filter' && state.filterDsl !== null) {
    payload.filter_snapshot = state.filterDsl;
  }
  if (state.targetScope === 'selected') {
    payload.selected_object_ids = state.selectedIds ?? [];
  }
  if (state.locales !== null) payload.locales = state.locales;
  if (state.channels !== null) payload.channels = state.channels;
  return payload;
}

/**
 * EXR-12 — run the export through the existing sync controller
 * (POST /api/products/export). The backend routes on target_count:
 * <100 streams the file (we trigger a blob download), >=100 returns 202
 * with the pending session id. The UI never decides sync vs async — it
 * follows the response shape (single source of truth: SYNC_THRESHOLD).
 */
export function useRunExport() {
  const [isRunning, setIsRunning] = useState(false);

  const run = async (state: WizardState): Promise<RunResult> => {
    setIsRunning(true);
    try {
      // #2743 — rawFetch keeps the raw Response (the happy path is a binary
      // download) while riding the shared 401 → refresh → replay path.
      const response = await rawFetch('/api/products/export', {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          accept: 'application/json, application/octet-stream, text/csv',
        },
        body: JSON.stringify(buildExportPayload(state)),
      });

      if (!response.ok) {
        let detail = `HTTP ${response.status}`;
        try {
          const problem = (await response.json()) as { detail?: string; title?: string };
          detail = problem.detail ?? problem.title ?? detail;
        } catch {
          // non-JSON error body — keep the HTTP status fallback
        }
        throw new RunError(response.status, detail);
      }

      const contentType = response.headers.get('content-type') ?? '';
      if (response.status === 202 || contentType.includes('application/json')) {
        const body = (await response.json()) as { id: string };
        return { kind: 'async', sessionId: body.id };
      }

      // Guard: a 2xx response whose body is NOT a spreadsheet/CSV/binary is an
      // error leaking through with an ok-ish status (e.g. a PHP fatal-error
      // dump rendered as text/html when the export OOMs). Without this check we
      // would download that error text as `pim-export.xlsx` and the user gets a
      // "corrupt file". Surface it as a RunError so StepSummary toasts instead.
      const DOWNLOADABLE_CONTENT_TYPES = [
        'spreadsheetml.sheet',
        'text/csv',
        'application/octet-stream',
      ];
      if (!DOWNLOADABLE_CONTENT_TYPES.some((type) => contentType.includes(type))) {
        const text = await response.text();
        throw new RunError(
          response.status,
          text.slice(0, 300) || `Unexpected response content-type: ${contentType}`,
        );
      }

      const blob = await response.blob();
      const filename =
        parseFilename(response.headers.get('content-disposition')) ?? `pim-export.${state.format}`;
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      document.body.removeChild(anchor);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      return { kind: 'sync', filename };
    } finally {
      setIsRunning(false);
    }
  };

  return { run, isRunning };
}

/** EXR-13 — PATCH an existing profile from the wizard state. */
export async function updateProfile(state: WizardState, name: string, id: string): Promise<void> {
  await jsonFetch(`/api/exports/profiles/${id}`, {
    method: 'PATCH',
    accept: 'application/json',
    body: {
      name,
      config: {
        format: state.format,
        selected_columns: state.columns,
        locales: state.locales,
        channels: state.channels,
        include_variants: true,
        default_target_scope: state.targetScope,
        filter: state.filterDsl,
      },
    },
  });
}

/** POST /api/exports/profiles from the wizard state (does NOT run). */
export async function saveProfile(state: WizardState, name: string): Promise<void> {
  const payload: Record<string, unknown> = {
    name,
    entity_type: state.entityType,
    config: {
      format: state.format,
      selected_columns: state.columns,
      locales: state.locales,
      channels: state.channels,
      include_variants: true,
      default_target_scope: state.targetScope,
      filter: state.filterDsl,
    },
  };
  if (state.objectTypeId !== null) payload.object_type_id = state.objectTypeId;
  await jsonFetch('/api/exports/profiles', {
    method: 'POST',
    accept: 'application/json',
    body: payload,
  });
}

export function isHttpError(error: unknown): error is HttpError {
  return error instanceof HttpError;
}

function parseFilename(header: string | null): string | null {
  if (header === null) return null;
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
  return match?.[1] !== undefined ? decodeURIComponent(match[1]) : null;
}
