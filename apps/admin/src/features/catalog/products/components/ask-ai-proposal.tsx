import { Check, Loader2, MessageSquareText, Sparkles, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import type { AskAiState } from './use-ask-ai';

/**
 * AICG-P5-02 (#2340) — the inline proposal card under an asked field:
 * a before/after diff with Accept/Reject wired to the SAME run-approval
 * API the inbox uses (accept commits through pending_changes, backend
 * P3-01). Progress and clarifying-question states link to the chat.
 */
export function AskAiProposal({
  state,
  onAccept,
  onReject,
  onDismiss,
}: {
  state: AskAiState;
  onAccept: () => void;
  onReject: () => void;
  onDismiss: () => void;
}) {
  const { t } = useTranslation();

  const openChat = () => {
    window.dispatchEvent(
      new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: state.runId } }),
    );
  };

  if (state.phase === 'running' || state.phase === 'deciding') {
    return (
      <div
        className="mx-2 mb-2 flex items-center gap-2 rounded-lg border border-purple-200 bg-purple-50 px-3 py-2 text-sm text-purple-900"
        data-testid="ask-ai-proposal"
        role="status"
      >
        <Loader2 className="size-4 animate-spin" aria-hidden />
        {state.phase === 'deciding'
          ? t('aicg.deciding', { defaultValue: 'Zapisywanie decyzji…' })
          : t('aicg.generating', { defaultValue: 'Agent pisze propozycję…' })}
      </div>
    );
  }

  if (state.phase === 'awaiting_input') {
    return (
      <div
        className="mx-2 mb-2 flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        data-testid="ask-ai-proposal"
      >
        <span>{t('aicg.needs_input', { defaultValue: 'Agent potrzebuje doprecyzowania.' })}</span>
        <button
          type="button"
          onClick={openChat}
          className="inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium hover:bg-amber-100"
        >
          <MessageSquareText className="size-3.5" aria-hidden />
          {t('aicg.open_chat', { defaultValue: 'Otwórz czat' })}
        </button>
      </div>
    );
  }

  if (state.phase === 'error') {
    return (
      <div
        className="mx-2 mb-2 flex items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"
        data-testid="ask-ai-proposal"
      >
        <span>
          {t('aicg.error', { defaultValue: 'Generowanie nie powiodło się.' })}
          {state.errorDetail !== null ? ` ${state.errorDetail}` : ''}
        </span>
        <button
          type="button"
          onClick={onDismiss}
          className="rounded-md px-2 py-1 font-medium hover:bg-red-100"
        >
          {t('aicg.dismiss', { defaultValue: 'Zamknij' })}
        </button>
      </div>
    );
  }

  const before = envelopeText(state.proposal?.before);
  const after = envelopeText(state.proposal?.after);

  return (
    <div
      className="mx-2 mb-2 rounded-lg border border-purple-200 bg-purple-50/60 p-3 text-sm"
      data-testid="ask-ai-proposal"
    >
      <div className="mb-2 flex items-center gap-1.5 font-medium text-purple-900">
        <Sparkles className="size-4" aria-hidden />
        {t('aicg.proposal_title', { defaultValue: 'Propozycja agenta' })}
      </div>
      {before !== '' && (
        <p className="mb-1 whitespace-pre-wrap rounded bg-red-50 px-2 py-1 text-red-900 line-through decoration-red-400">
          {before}
        </p>
      )}
      <p
        className="whitespace-pre-wrap rounded bg-emerald-50 px-2 py-1 text-emerald-900"
        data-testid="ask-ai-after"
      >
        {after !== '' ? after : t('aicg.empty_proposal', { defaultValue: '(pusta propozycja)' })}
      </p>
      <div className="mt-2 flex items-center gap-2">
        <button
          type="button"
          onClick={onAccept}
          className="inline-flex items-center gap-1 rounded-md bg-purple-600 px-3 py-1.5 font-medium text-white hover:bg-purple-700"
        >
          <Check className="size-3.5" aria-hidden />
          {t('aicg.accept', { defaultValue: 'Akceptuj' })}
        </button>
        <button
          type="button"
          onClick={onReject}
          className="inline-flex items-center gap-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 font-medium text-zinc-700 hover:bg-zinc-50"
        >
          <X className="size-3.5" aria-hidden />
          {t('aicg.reject', { defaultValue: 'Odrzuć' })}
        </button>
        <button
          type="button"
          onClick={openChat}
          className="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-purple-700 hover:bg-purple-100"
        >
          <MessageSquareText className="size-3.5" aria-hidden />
          {t('aicg.open_chat', { defaultValue: 'Otwórz czat' })}
        </button>
      </div>
    </div>
  );
}

function envelopeText(envelope: Record<string, unknown> | null | undefined): string {
  const value = envelope?.value;
  return typeof value === 'string' ? value : '';
}
