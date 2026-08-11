import { describe, expect, it } from 'vitest';

import { type ImportSessionRow, processedRowsOf } from './types';

/**
 * #2815 — the sessions hub derived progress from `success_count + error_count`.
 * Both stay at 0 while a run is genuinely working — a re-import of an unchanged
 * export counts every row as `skipped` — so the hub showed "0 wierszy" beside a
 * job that had been going for twenty minutes, while the detail screen (reading
 * Mercure) showed real progress. The durable `processed_rows` written per chunk
 * is what makes the two agree.
 */

function session(overrides: Partial<ImportSessionRow>): ImportSessionRow {
  return {
    id: 's1',
    status: 'running',
    file_name: 'export.csv',
    total_rows: 1000,
    success_count: 0,
    error_count: 0,
    started_at: '2026-08-10T20:28:30.000Z',
    completed_at: null,
    rollback_until: null,
    duration_sec: null,
    ...overrides,
  };
}

describe('processedRowsOf', () => {
  it('reports progress for a run whose rows are all no-op skips', () => {
    expect(
      processedRowsOf(session({ processed_rows: 420, success_count: 0, error_count: 0 })),
    ).toBe(420);
  });

  it('counts successes and errors when the durable counter is behind', () => {
    // The counter is written per chunk, so within a chunk the outcome counters
    // are the fresher number — take whichever has got further.
    expect(
      processedRowsOf(session({ processed_rows: 200, success_count: 260, error_count: 5 })),
    ).toBe(265);
  });

  it('falls back to the outcome counters for sessions predating the column', () => {
    expect(processedRowsOf(session({ success_count: 900, error_count: 100 }))).toBe(1000);
  });

  it('is zero for a session that has not started', () => {
    expect(processedRowsOf(session({ status: 'pending', processed_rows: 0 }))).toBe(0);
  });
});
