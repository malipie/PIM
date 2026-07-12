import { GitBranch, History } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { StatusPill } from '@/components/ui-v2/status-pill';
import { fetchWorkflowState, placePillVariant, type WorkflowState } from '@/lib/workflow/api';

import { WorkflowHistorySheet } from './workflow-history-sheet';
import { WorkflowModal } from './workflow-modal';

/**
 * Pakiet E (WFL-P3-01 #2423) + redesign (#2529) — the editorial workflow
 * entry point in the product-detail breadcrumb: a status pill mirroring the
 * machine, a "Workflow" chip that opens the {@link WorkflowModal} (state +
 * role preview + guard-aware transitions + reviewer routing) and a history
 * icon that opens the {@link WorkflowHistorySheet}. The transition buttons
 * themselves moved into the modal (approved design) — this component is now
 * the compact trigger. Users without `workflow.view` see nothing (the
 * discovery call fails silently).
 */
export function WorkflowStatusControl({ objectId }: { objectId: string }) {
  const { t } = useTranslation();
  const [state, setState] = useState<WorkflowState | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);

  const reload = useCallback(() => {
    fetchWorkflowState(objectId)
      .then(setState)
      .catch(() => {
        // Silent: users without workflow.view simply see no control.
        setState(null);
      });
  }, [objectId]);

  useEffect(() => {
    reload();
  }, [reload]);

  if (state === null) {
    return null;
  }

  return (
    <span className="inline-flex items-center gap-1.5" data-testid="workflow-status-control">
      <StatusPill
        variant={placePillVariant(state.current_place)}
        label={t(`workflow.place.${state.current_place}`, { defaultValue: state.current_place })}
      />
      <button
        type="button"
        onClick={() => setModalOpen(true)}
        data-testid="workflow-open"
        className="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-[12px] font-medium text-zinc-600 transition hover:border-zinc-300 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
      >
        <GitBranch className="size-3.5" aria-hidden="true" />
        {t('workflow.modal.title', { defaultValue: 'Workflow' })}
      </button>
      <button
        type="button"
        onClick={() => setHistoryOpen(true)}
        aria-label={t('workflow.history.title', { defaultValue: 'Historia workflow' })}
        data-testid="workflow-history-icon"
        className="grid size-6 place-items-center rounded-full text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
      >
        <History className="size-3.5" />
      </button>

      <WorkflowModal
        objectId={objectId}
        state={state}
        open={modalOpen}
        onOpenChange={setModalOpen}
        onApplied={reload}
        onOpenHistory={() => {
          setModalOpen(false);
          setHistoryOpen(true);
        }}
      />
      <WorkflowHistorySheet objectId={objectId} open={historyOpen} onOpenChange={setHistoryOpen} />
    </span>
  );
}
