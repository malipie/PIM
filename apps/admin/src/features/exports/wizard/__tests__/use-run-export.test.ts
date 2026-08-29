import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { WizardState } from '../types';
import { INITIAL_WIZARD_STATE } from '../types';
import type { RunError } from '../use-run-export';
import { useRunExport } from '../use-run-export';

// #2743 — the hook now goes through the shared client's rawFetch (which owns
// the 401 → refresh → replay path) instead of building the request itself, so
// the double stands in for that helper and still lets the tests drive the raw
// Response through the global fetch stub below.
vi.mock('@/lib/http', () => ({
  rawFetch: (path: string, init?: RequestInit) => fetch(path, init),
  jsonFetch: vi.fn(),
  HttpError: class HttpError extends Error {},
}));

const STATE: WizardState = {
  ...INITIAL_WIZARD_STATE,
  entityType: 'product',
  format: 'xlsx',
  targetScope: 'all',
  columns: ['sku'],
};

function mockFetch(response: Response): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => response),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('useRunExport', () => {
  it('throws a RunError instead of downloading when the body is an error page', async () => {
    // Regression: the export OOM returned a PHP fatal-error dump as text/html
    // with an ok-ish status. The hook must NOT save that as `pim-export.xlsx`.
    mockFetch(
      new Response('Fatal error: Allowed memory size of 268435456 bytes exhausted', {
        status: 200,
        headers: { 'content-type': 'text/html; charset=UTF-8' },
      }),
    );

    const { result } = renderHook(() => useRunExport());

    const outcome: { error: RunError | null } = { error: null };
    await act(async () => {
      outcome.error = await result.current.run(STATE).then(
        () => null,
        (rejected: RunError) => rejected,
      );
    });
    expect(outcome.error).not.toBeNull();
    expect(outcome.error?.status).toBe(200);
    expect(outcome.error?.detail).toContain('memory size');
  });

  it('returns an async result when the backend routes the export to a worker (202)', async () => {
    mockFetch(
      new Response(JSON.stringify({ id: 'session-123' }), {
        status: 202,
        headers: { 'content-type': 'application/json' },
      }),
    );

    const { result } = renderHook(() => useRunExport());

    let runResult: Awaited<ReturnType<typeof result.current.run>> | null = null;
    await act(async () => {
      runResult = await result.current.run(STATE);
    });

    expect(runResult).toEqual({
      kind: 'async',
      sessionId: 'session-123',
    });
  });
});
