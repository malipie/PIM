import { Check, Loader2, ShieldAlert, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { ProvenanceBadge } from '@/components/provenance-badge';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import {
  type AgentPlanRow,
  type AgentRunSummary,
  approveAgentRun,
  getAgentPlan,
  rejectAgentRun,
} from '@/features/agent/api';
import { useAgentPendingProposals } from '@/features/agent/hooks/useAgentPendingProposals';
import { httpErrorDetail } from '@/lib/http';

function planLine(
  row: AgentPlanRow,
  t: (k: string, o?: Record<string, unknown>) => string,
): string {
  const beforeValue = row.before === null ? '∅' : JSON.stringify(row.before.value ?? row.before);
  const afterValue = row.after === null ? '∅' : JSON.stringify(row.after.value ?? row.after);
  if (row.change_type === 'schema') {
    const kind =
      row.after?.schema_kind === 'attribute_group'
        ? t('agent.inbox.schema_group', { defaultValue: 'grupa' })
        : t('agent.inbox.schema_attribute', { defaultValue: 'atrybut' });
    return `+ ${kind} ${row.attribute_code ?? '?'}`;
  }
  if (row.change_type === 'object') {
    const code = typeof row.after?.code === 'string' ? row.after.code : '?';
    const objectType =
      typeof row.after?.object_type_code === 'string' ? row.after.object_type_code : '?';
    const attributes =
      row.after?.attributes !== null && typeof row.after?.attributes === 'object'
        ? JSON.stringify(row.after.attributes)
        : '{}';
    return `+ ${t('agent.inbox.object', { defaultValue: 'obiekt' })} ${code} · ${objectType} · ${attributes}`;
  }
  // #2154 — lead with the product identity (SKU · name) so the operator
  // can tell WHICH product each diff row touches, not just the raw delta.
  const identity = objectIdentity(row);
  if (row.change_type === 'category') {
    const op = typeof row.after?.operation === 'string' ? row.after.operation : '?';
    return `${identity} · ${t('agent.inbox.categories', { defaultValue: 'kategorie' })}: ${op}`;
  }
  if (row.change_type === 'status') {
    const before = typeof row.before?.status === 'string' ? row.before.status : '?';
    const after = typeof row.after?.status === 'string' ? row.after.status : '?';
    const transition = typeof row.after?.transition === 'string' ? row.after.transition : '?';
    return `${identity} · ${t('agent.inbox.status', { defaultValue: 'status' })}: ${before} → ${after} (${transition})`;
  }
  return `${identity} · ${row.attribute_code ?? '?'}: ${beforeValue} → ${afterValue}`;
}

/**
 * "SKU · Name", falling back to whatever identity we have (#2154). The
 * backend fills target_object_code/name on list reads; a bare id is the
 * last resort.
 */
function objectIdentity(row: AgentPlanRow): string {
  const parts: string[] = [];
  if (row.target_object_code) {
    parts.push(row.target_object_code);
  }
  if (row.target_object_name) {
    parts.push(row.target_object_name);
  }
  if (parts.length > 0) {
    return parts.join(' · ');
  }
  return row.target_object_id ?? '?';
}

function proposalMode(rows: AgentPlanRow[]): 'overwrite' | 'only_empty' | null {
  for (const row of rows) {
    const mode = row.meta?.mode;
    if (mode === 'overwrite' || mode === 'only_empty') {
      return mode;
    }
  }
  return null;
}

/**
 * AGENT-P6-03 (#1976) — the single approval gate's UI (PRD §5.3): runs
 * awaiting approval with intent, scope, cost/tokens and provenance;
 * the diff modal shows the materialized plan (before -> after per
 * object, new schema elements) — the human backstop against prompt
 * injection sees EXACTLY what will be committed. MVP all-or-nothing:
 * partial approval is a disabled hook with a tooltip.
 */
export function AgentInboxPage() {
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();
  const pending = useAgentPendingProposals();
  const runs = pending.items;
  const loading = pending.isLoading;
  const [planFor, setPlanFor] = useState<AgentRunSummary | null>(null);
  const [planRows, setPlanRows] = useState<AgentPlanRow[]>([]);
  const [planTotal, setPlanTotal] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const handledDeepLink = useRef<string | null>(null);
  const planMode = proposalMode(planRows);

  const reload = useCallback(async () => {
    await pending.refetch();
  }, [pending.refetch]);

  const openPlan = useCallback(async (run: AgentRunSummary) => {
    setPlanFor(run);
    setPlanRows([]);
    try {
      const plan = await getAgentPlan(run.id, 1, 100);
      setPlanRows(plan.items);
      setPlanTotal(plan.total);
    } catch (error) {
      toast.error(httpErrorDetail(error) ?? String(error));
    }
  }, []);

  useEffect(() => {
    const runId = searchParams.get('run');
    const batchId = searchParams.get('batch');
    const key = `${runId ?? ''}:${batchId ?? ''}`;
    if (loading || (runId === null && batchId === null) || handledDeepLink.current === key) {
      return;
    }
    const run = runs.find(
      (candidate) => candidate.id === runId || candidate.pending_change_batch_id === batchId,
    );
    if (run !== undefined) {
      handledDeepLink.current = key;
      void openPlan(run);
    }
  }, [loading, runs, searchParams, openPlan]);

  useEffect(() => {
    if (pending.error) {
      toast.error(httpErrorDetail(pending.error) ?? String(pending.error));
    }
  }, [pending.error]);

  const decide = async (run: AgentRunSummary, decision: 'approve' | 'reject') => {
    setBusyId(run.id);
    try {
      if (decision === 'approve') {
        await approveAgentRun(run.id);
        toast.success(
          t('agent.inbox.approved', {
            defaultValue: 'Zatwierdzono — {{count}} zmian zapisano.',
            count: run.affected_count ?? 0,
          }),
        );
      } else {
        await rejectAgentRun(run.id);
        toast.success(
          t('agent.inbox.rejected', { defaultValue: 'Odrzucono — katalog bez zmian.' }),
        );
      }
      setPlanFor(null);
      await reload();
    } catch (error) {
      toast.error(httpErrorDetail(error) ?? String(error));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-4" data-testid="agent-inbox">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">
          {t('agent.inbox.title', { defaultValue: 'Skrzynka akceptacji agenta' })}
        </h1>
        <p className="text-sm text-zinc-500">
          {t('agent.inbox.subtitle', {
            defaultValue:
              'Nic nie trafia do katalogu bez Twojej decyzji — przejrzyj diff i zatwierdź albo odrzuć.',
          })}
        </p>
      </div>

      {loading ? (
        <p className="flex items-center gap-2 text-sm text-zinc-500" role="status">
          <Loader2 className="size-4 animate-spin" aria-hidden />
          {t('agent.inbox.loading', { defaultValue: 'Wczytywanie…' })}
        </p>
      ) : runs.length === 0 ? (
        <p
          className="rounded-xl border border-dashed border-zinc-200 p-6 text-sm text-zinc-500"
          data-testid="agent-inbox-empty"
        >
          {t('agent.inbox.empty', { defaultValue: 'Brak propozycji czekających na akceptację.' })}
        </p>
      ) : (
        <ul className="space-y-3">
          {runs.map((run) => (
            <li
              key={run.id}
              className="rounded-xl border border-zinc-200 bg-white p-4"
              data-testid="agent-inbox-item"
            >
              <div className="flex flex-wrap items-center gap-2">
                <ProvenanceBadge provenance="agent" />
                <span className="text-sm font-medium">{run.intent}</span>
                <span className="ml-auto text-xs tabular-nums text-zinc-500">
                  {t('agent.inbox.scope', {
                    defaultValue: '{{count}} zmian',
                    count: run.affected_count ?? 0,
                  })}
                  {' · '}
                  {run.tokens_input + run.tokens_output}
                  {' tok · $'}
                  {run.cost_usd}
                  {run.model !== null ? ` · ${run.model}` : ''}
                </span>
              </div>
              <div className="mt-3 flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => void openPlan(run)}
                  data-testid="agent-inbox-show-diff"
                >
                  {t('agent.inbox.show_diff', { defaultValue: 'Pokaż diff' })}
                </Button>
                <Button
                  size="sm"
                  disabled={busyId === run.id}
                  onClick={() => void decide(run, 'approve')}
                  data-testid="agent-inbox-approve"
                >
                  <Check className="mr-1 size-3.5" aria-hidden />
                  {t('agent.inbox.approve', { defaultValue: 'Akceptuj' })}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busyId === run.id}
                  onClick={() => void decide(run, 'reject')}
                  data-testid="agent-inbox-reject"
                >
                  <X className="mr-1 size-3.5" aria-hidden />
                  {t('agent.inbox.reject', { defaultValue: 'Odrzuć' })}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {planFor !== null && (
        <div className="fixed inset-0 z-[60] grid place-items-center bg-zinc-900/40 p-4 backdrop-blur-sm">
          <button
            type="button"
            aria-label={t('app.close', { defaultValue: 'Zamknij' })}
            onClick={() => setPlanFor(null)}
            className="absolute inset-0 cursor-default"
          />
          <div
            role="dialog"
            aria-modal="true"
            aria-label={t('agent.inbox.diff_title', { defaultValue: 'Plan zmian' })}
            className="relative flex max-h-[80vh] w-[720px] max-w-[94vw] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
            data-testid="agent-diff-modal"
          >
            <div className="flex items-center gap-2 border-b border-zinc-100 px-5 py-3">
              <ShieldAlert className="size-4 text-purple-600" aria-hidden />
              <div className="min-w-0">
                <div className="truncate text-sm font-semibold">{planFor.intent}</div>
                <div className="text-xs text-zinc-500">
                  {t('agent.inbox.diff_scope', {
                    defaultValue: '{{shown}} z {{total}} pozycji planu · all-or-nothing',
                    shown: planRows.length,
                    total: planTotal,
                  })}
                </div>
                {planMode !== null ? (
                  <div className="mt-1" data-testid="agent-inbox-mode">
                    <span className="rounded-full bg-purple-50 px-2 py-0.5 text-[11px] font-medium text-purple-700">
                      {planMode === 'overwrite'
                        ? t('agent.inbox.mode_overwrite')
                        : t('agent.inbox.mode_only_empty')}
                    </span>
                  </div>
                ) : null}
              </div>
              <button
                type="button"
                onClick={() => setPlanFor(null)}
                aria-label={t('app.close', { defaultValue: 'Zamknij' })}
                className="ml-auto grid h-8 w-8 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
              >
                <X className="size-4" />
              </button>
            </div>
            <div className="flex-1 overflow-auto p-4">
              <ul className="space-y-1 font-mono text-[12.5px]">
                {planRows.map((row) => (
                  <li
                    key={row.id}
                    className="rounded bg-zinc-50 px-2 py-1"
                    data-testid="agent-diff-row"
                  >
                    {planLine(row, t)}
                  </li>
                ))}
              </ul>
            </div>
            <div className="flex items-center gap-2 border-t border-zinc-100 px-5 py-3">
              <span
                className="text-xs text-zinc-500"
                title={t('agent.inbox.partial_tooltip', {
                  defaultValue: 'Częściowa akceptacja w przygotowaniu — MVP zatwierdza cały plan.',
                })}
              >
                {t('agent.inbox.partial', { defaultValue: 'Częściowo (wkrótce)' })}
              </span>
              <Button
                className="ml-auto"
                size="sm"
                disabled={busyId === planFor.id}
                onClick={() => void decide(planFor, 'approve')}
                data-testid="agent-diff-approve"
              >
                <Check className="mr-1 size-3.5" aria-hidden />
                {t('agent.inbox.approve', { defaultValue: 'Akceptuj' })}
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={busyId === planFor.id}
                onClick={() => void decide(planFor, 'reject')}
              >
                <X className="mr-1 size-3.5" aria-hidden />
                {t('agent.inbox.reject', { defaultValue: 'Odrzuć' })}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
