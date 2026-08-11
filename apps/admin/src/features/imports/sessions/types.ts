import type { ImportMode, SessionStatus, SourceType } from '../primitives';

export type FilterValue = 'all' | 'success' | 'warning' | 'error' | 'cancelled';

export interface ImportSessionRow {
  id: string;
  status: ApiStatus;
  file_name: string;
  file_size_bytes?: number;
  total_rows: number | null;
  /**
   * #2815 — rows the worker has consumed, persisted per chunk. The outcome
   * counters below split a row three ways, so a re-import that only updates
   * rows keeps `success_count` at 0 for its whole run; deriving progress from
   * them showed a working import as "nothing has happened".
   */
  processed_rows?: number;
  /** #2815 — when `processed_rows` last moved: slow run vs. stuck one. */
  progress_updated_at?: string | null;
  success_count: number;
  error_count: number;
  error_message?: string | null;
  started_at: string | null;
  completed_at: string | null;
  rollback_until: string | null;
  duration_sec: number | null;
  profile_name?: string | null;
  profile_id?: string | null;
  target_object_type_code?: string;
  mode?: ImportMode;
}

export interface ThroughputResponse {
  rows_per_sec: number;
  active_sessions: number;
  window_min: number;
  sampled_at: string;
}

export type ApiStatus =
  | 'pending'
  | 'running'
  | 'paused'
  | 'success'
  | 'partial'
  | 'failed'
  | 'cancelled'
  | 'rolled_back';

const STATUS_TO_PILL: Record<ApiStatus, SessionStatus> = {
  pending: 'queued',
  running: 'running',
  paused: 'paused',
  success: 'success',
  partial: 'warning',
  failed: 'error',
  cancelled: 'cancelled',
  rolled_back: 'cancelled',
};

/**
 * #2815 — rows the run has worked through.
 *
 * `processed_rows` is the durable counter the worker writes with every chunk.
 * The sum of the outcome counters is the pre-#2815 derivation, kept as a
 * fallback for sessions that finished before the column existed — it reads 0
 * for a re-import of an unchanged export, because every row of one counts as
 * `skipped`, which is how a working import came to render as "nothing has
 * happened".
 */
export function processedRowsOf(session: ImportSessionRow): number {
  return Math.max(session.processed_rows ?? 0, session.success_count + session.error_count);
}

export function pillFor(status: ApiStatus): SessionStatus {
  return STATUS_TO_PILL[status];
}

export function filterMatches(filter: FilterValue, status: ApiStatus): boolean {
  if (filter === 'all') {
    return true;
  }
  if (filter === 'success') {
    return status === 'success';
  }
  if (filter === 'warning') {
    return status === 'partial' || status === 'paused';
  }
  if (filter === 'error') {
    return status === 'failed';
  }
  return status === 'cancelled' || status === 'rolled_back';
}

export const SOURCE_FALLBACK: SourceType = 'upload';
