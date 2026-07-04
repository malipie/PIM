import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { type AgentCapabilities, getAgentCapabilities } from '@/features/agent/api';
import { AGENT_ENABLED } from '@/lib/features';

export interface AgentQuickAction {
  id: string;
  label: string;
  prompt: string;
}

/**
 * #2246 — shared source of the agent quick-action chips (dashboard hero
 * + global Cmd+K). Fail-soft by design: a failed fetch hides the chips
 * but never disables the free-text path — dashboards without the stub
 * in e2e must stay quiet (no thrown errors, one retry only).
 */
export function useAgentQuickActions(enabled = true): {
  capabilities: AgentCapabilities | null;
  actions: AgentQuickAction[];
  isLoading: boolean;
} {
  const { i18n } = useTranslation();
  const query = useQuery<AgentCapabilities>({
    queryKey: ['agent', 'capabilities'],
    queryFn: getAgentCapabilities,
    enabled: AGENT_ENABLED && enabled,
    staleTime: 5 * 60_000,
    retry: 1,
  });

  const lang = i18n.language.toLowerCase().startsWith('pl') ? 'pl' : 'en';
  const actions = (query.data?.actions ?? []).map((action) => ({
    id: action.id,
    label: action.label[lang] ?? action.label.en,
    prompt: action.prompt[lang] ?? action.prompt.en,
  }));

  return { capabilities: query.data ?? null, actions, isLoading: query.isPending };
}
