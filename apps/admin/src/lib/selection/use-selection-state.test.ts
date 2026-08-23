import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { jsonFetch } from '@/lib/http';
import { useSelectionState } from './use-selection-state';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));

describe('useSelectionState', () => {
  beforeEach(() => vi.mocked(jsonFetch).mockReset());

  it('keeps visible checkboxes selected and exposes the real capped count', async () => {
    vi.mocked(jsonFetch).mockResolvedValue({
      ids: ['server-1', 'server-2'],
      totalMatched: 50060,
      capped: true,
      limit: 3,
    });
    const { result } = renderHook(() => useSelectionState());

    await act(() =>
      result.current.selectAllMatching({ q: 'fan', variantsMode: 'tree' }, [
        'visible-1',
        'visible-2',
      ]),
    );

    expect(result.current.mode).toBe('all-matching');
    expect([...result.current.ids]).toEqual(['visible-1', 'visible-2', 'server-1']);
    expect(result.current.totalMatched).toBe(50060);
    expect(result.current.capped).toBe(true);
    expect(jsonFetch).toHaveBeenCalledWith(
      '/api/products/select-all-matching',
      expect.objectContaining({
        body: expect.objectContaining({ variants_mode: 'tree' }),
      }),
    );
  });
});
