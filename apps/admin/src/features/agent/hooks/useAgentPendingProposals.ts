import { useQuery } from '@tanstack/react-query';

import { listPendingAgentRuns } from '@/features/agent/api';
import { AGENT_ENABLED } from '@/lib/features';
import { hasPermission, useIdentity } from '@/lib/identity';

export const AGENT_PENDING_QUERY_KEY = ['agent', 'pending-proposals'] as const;

/** Shared source for the sidebar, bell and approval inbox (#2982). */
export function useAgentPendingProposals() {
  const { identity } = useIdentity();
  const allowed = AGENT_ENABLED && hasPermission(identity, 'agent.approve_pending');

  const query = useQuery({
    queryKey: AGENT_PENDING_QUERY_KEY,
    queryFn: () => listPendingAgentRuns(1, 100),
    enabled: allowed,
    staleTime: 5_000,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
  });

  return {
    ...query,
    allowed,
    items: query.data?.items ?? [],
    count: query.data?.total ?? 0,
  };
}
