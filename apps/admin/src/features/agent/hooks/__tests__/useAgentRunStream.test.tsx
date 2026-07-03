import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useAgentRunStream } from '@/features/agent/hooks/useAgentRunStream';

vi.mock('@/lib/identity', () => ({
  useIdentity: () => ({ identity: { tenant: { id: 'tenant-1' } } }),
}));

vi.mock('@/lib/mercure', () => ({
  ensureMercureAuthorization: () => Promise.reject(new Error('hub down')),
  mercureSubscribeUrl: (topic: string) => `https://hub/.well-known/mercure?topic=${topic}`,
  mercureTenantTopic: (tenantId: string, ...segments: string[]) =>
    `https://hub/tenant/${tenantId}/${segments.join('/')}`,
}));

/**
 * AGENT-P6-07 (#1980) — graceful degradation: Mercure down (auth
 * refused / no EventSource) leaves connected=false and never throws -
 * the chat panel keeps its polling fallback.
 */
describe('useAgentRunStream', () => {
  it('stays disconnected without crashing when the hub is down', async () => {
    const { result } = renderHook(() => useAgentRunStream('run-1'));

    await Promise.resolve();
    expect(result.current.connected).toBe(false);
    expect(result.current.lastEvent).toBeNull();
  });

  it('is idle without a run id', () => {
    const { result } = renderHook(() => useAgentRunStream(null));

    expect(result.current.connected).toBe(false);
  });
});
