import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ModelingSection } from '@/components/modeling/modeling-section';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { EmptyState } from '@/components/ui-v2/empty-state';
import { jsonFetch } from '@/lib/http';
import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';
import { applyWorkflowTransition, fetchWorkflowLog } from '@/lib/workflow/api';

import { ReviewQueueRow, type ReviewRow } from './ReviewQueueRow';
import { kindLabelDefault } from './task-presentation';

/**
 * WFL-P3-02 (#2424) — the review queue: every object waiting for a
 * decision, with the submitter's comment and completeness, decidable
 * inline (approve / reject with a mandatory comment) or in bulk via
 * the state-machine bulk endpoint. This is the differentiator over
 * Pimcore (which has no inbox — states are only visible via reports).
 * The sidebar "Workflow" entry stops being comingSoon here.
 *
 * Live refresh: workflow transition events land on the tenant objects
 * broadcast topic (WFL-P2-01) — any submit/approve/reject triggers a
 * debounced refetch, so items appear/disappear without a reload.
 */

interface HydraObject {
  id: string;
  code?: string;
  completenessPct?: number;
  attributesIndexed?: Record<string, unknown>;
  kind?: string;
}

const QUEUE_LIMIT = 50;

function objectLabel(item: HydraObject, lang: string): string {
  const name = item.attributesIndexed?.name;
  if (typeof name === 'string' && name !== '') return name;
  if (name !== null && typeof name === 'object') {
    const map = name as Record<string, unknown>;
    const localized = map[lang] ?? map.pl ?? map.en;
    if (typeof localized === 'string' && localized !== '') return localized;
  }
  return item.code ?? item.id;
}

export function ReviewQueuePage() {
  const { t, i18n } = useTranslation();
  const { identity } = useIdentity();
  const [rows, setRows] = useState<ReviewRow[]>([]);
  const [kindFilter, setKindFilter] = useState<string>('all');
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
  const [decision, setDecision] = useState<{
    transition: 'approve' | 'reject';
    ids: string[];
  } | null>(null);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const canDecide = identity?.permissions.has('workflow.approve_reject') ?? false;

  const reload = useCallback(() => {
    setLoading(true);
    jsonFetch<{ 'hydra:member'?: HydraObject[]; member?: HydraObject[] }>(
      `/api/objects?status=review&itemsPerPage=${QUEUE_LIMIT}`,
    )
      .then(async (body) => {
        const members = body['hydra:member'] ?? body.member ?? [];
        // The newest transition of an in-review object IS the submit
        // (approve/reject would have moved it out) — one light log fetch
        // per row carries author, comment and timestamp.
        const enriched = await Promise.all(
          members.map(async (item): Promise<ReviewRow> => {
            const submitted = await fetchWorkflowLog(item.id, undefined, 1)
              .then((page) => page.items[0] ?? null)
              .catch(() => null);
            return {
              id: item.id,
              code: item.code ?? item.id,
              kind: item.kind ?? 'product',
              label: objectLabel(item, i18n.language),
              completenessPct: item.completenessPct ?? 0,
              submitted,
            };
          }),
        );
        setRows(enriched);
        setSelected(new Set());
      })
      .catch(() => setRows([]))
      .finally(() => setLoading(false));
  }, [i18n.language]);

  useEffect(() => {
    reload();
  }, [reload]);

  // Live refetch on workflow events (debounced by a micro-timeout).
  const tenantId = identity?.tenant?.id ?? null;
  useEffect(() => {
    if (typeof window === 'undefined' || typeof EventSource === 'undefined') return;
    if (tenantId === null) return;

    let source: EventSource | null = null;
    let disposed = false;
    let timer: ReturnType<typeof setTimeout> | null = null;

    void ensureMercureAuthorization().then(() => {
      if (disposed) return;
      source = new EventSource(mercureSubscribeUrl(mercureTenantTopic(tenantId, 'objects')), {
        withCredentials: true,
      });
      source.onmessage = (event: MessageEvent<string>) => {
        try {
          const parsed = JSON.parse(event.data) as { type?: string };
          if (typeof parsed.type !== 'string') return;
          if (
            parsed.type.startsWith('object.submitted_for_review') ||
            parsed.type.startsWith('object.approved') ||
            parsed.type.startsWith('object.rejected') ||
            parsed.type.startsWith('object.published')
          ) {
            if (timer !== null) clearTimeout(timer);
            timer = setTimeout(reload, 400);
          }
        } catch {
          // Ignore malformed frames.
        }
      };
    });

    return () => {
      disposed = true;
      if (timer !== null) clearTimeout(timer);
      source?.close();
    };
  }, [tenantId, reload]);

  // #2517 — filter by ObjectType (Produkt / Kategoria / …) with counts.
  const kindCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const row of rows) counts.set(row.kind, (counts.get(row.kind) ?? 0) + 1);
    return counts;
  }, [rows]);
  const visibleRows = useMemo(
    () => (kindFilter === 'all' ? rows : rows.filter((row) => row.kind === kindFilter)),
    [rows, kindFilter],
  );

  const allSelected = visibleRows.length > 0 && visibleRows.every((row) => selected.has(row.id));
  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(visibleRows.map((row) => row.id)));
  };
  const toggleOne = (id: string) => {
    setSelected((previous) => {
      const next = new Set(previous);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const requiresComment = decision?.transition === 'reject';

  const confirmDecision = () => {
    if (decision === null) return;
    if (requiresComment && comment.trim() === '') return;
    setSubmitting(true);

    const singleId = decision.ids.length === 1 ? decision.ids[0] : undefined;
    const run =
      singleId !== undefined
        ? applyWorkflowTransition(singleId, decision.transition, comment.trim() || undefined).then(
            () => ({ success: 1, locked: 0 }),
          )
        : jsonFetch<{ success_count: number; skipped_count: number; locked_count?: number }>(
            '/api/objects/bulk-actions/change_status',
            {
              method: 'POST',
              contentType: 'application/json',
              body: {
                target_ids: decision.ids,
                payload: {
                  transition: decision.transition,
                  ...(comment.trim() !== '' ? { comment: comment.trim() } : {}),
                },
              },
            },
          ).then((result) => ({
            success: result.success_count,
            locked: result.skipped_count + (result.locked_count ?? 0),
          }));

    run
      .then(({ success, locked }) => {
        toast.success(
          t('workflow.queue.decided', {
            defaultValue: 'Zastosowano: {{count}} (pominięte: {{locked}})',
            count: success,
            locked,
          }),
        );
        setDecision(null);
        setComment('');
        reload();
      })
      .catch((error: unknown) => {
        toast.error(
          error instanceof Error && error.message !== ''
            ? error.message
            : t('workflow.toast.failed', { defaultValue: 'Przejście nie powiodło się' }),
        );
      })
      .finally(() => setSubmitting(false));
  };

  const selectedIds = useMemo(() => [...selected], [selected]);

  return (
    <div data-testid="review-queue-page">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap items-center gap-1" data-testid="review-queue-kind-filter">
          {[
            {
              id: 'all',
              label: t('workflow.queue.kind_all', { defaultValue: 'Wszystkie' }),
              count: rows.length,
            },
            ...[...kindCounts.entries()].map(([kind, count]) => ({
              id: kind,
              label: t(`workflow.kind.${kind}`, { defaultValue: kindLabelDefault(kind) }),
              count,
            })),
          ].map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setKindFilter(tab.id)}
              data-testid={`review-queue-kind-${tab.id}`}
              className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[13px] font-medium transition ${
                kindFilter === tab.id ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
              }`}
            >
              {tab.label}
              <span
                className={`rounded-full px-1.5 text-[11px] ${kindFilter === tab.id ? 'bg-white/20' : 'bg-zinc-200/70'}`}
              >
                {tab.count}
              </span>
            </button>
          ))}
        </div>
        <div className="ml-auto flex items-center gap-2">
          {canDecide && selected.size > 0 ? (
            <>
              <Button
                size="sm"
                onClick={() => {
                  setComment('');
                  setDecision({ transition: 'approve', ids: selectedIds });
                }}
                data-testid="queue-bulk-approve"
              >
                {t('workflow.transition.approve', { defaultValue: 'Zatwierdź' })} ({selected.size})
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => {
                  setComment('');
                  setDecision({ transition: 'reject', ids: selectedIds });
                }}
                data-testid="queue-bulk-reject"
              >
                {t('workflow.transition.reject', { defaultValue: 'Odrzuć' })} ({selected.size})
              </Button>
            </>
          ) : null}
          <Button
            variant="ghost"
            size="icon"
            onClick={reload}
            aria-label={t('common.refresh', { defaultValue: 'Odśwież' })}
          >
            <RefreshCw className="size-4" />
          </Button>
        </div>
      </div>

      {rows.length === 0 && !loading ? (
        <EmptyState
          title={t('workflow.queue.empty_title', { defaultValue: 'Nic do przeglądu' })}
          description={t('workflow.queue.empty_body', {
            defaultValue: 'Zgłoszone obiekty pojawią się tutaj automatycznie.',
          })}
        />
      ) : (
        // #2677 — the queue mirrors the ObjectType list (ModelingSection card
        // shell + rows) instead of a `<table>`. The table forced horizontal
        // scroll on narrow viewports, hiding the approve/reject actions off
        // screen; the row now stacks vertically below `sm` so the actions are
        // always reachable without an overflow-x scroll.
        <div className="mt-4">
          <ModelingSection
            label={t('workflow.queue.section_label', { defaultValue: 'Do przeglądu' })}
            summary={
              <label className="flex items-center gap-2 text-[12px] normal-case tracking-normal">
                <input
                  type="checkbox"
                  checked={allSelected}
                  onChange={toggleAll}
                  aria-label={t('workflow.queue.select_all', {
                    defaultValue: 'Zaznacz wszystkie',
                  })}
                />
                {t('workflow.queue.select_all', { defaultValue: 'Zaznacz wszystkie' })}
              </label>
            }
          >
            {visibleRows.map((row) => (
              <ReviewQueueRow
                key={row.id}
                row={row}
                selected={selected.has(row.id)}
                canDecide={canDecide}
                onToggle={toggleOne}
                onDecide={(transition, id) => {
                  setComment('');
                  setDecision({ transition, ids: [id] });
                }}
              />
            ))}
          </ModelingSection>
        </div>
      )}

      <Dialog open={decision !== null} onOpenChange={(open) => !open && setDecision(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {decision?.transition === 'approve'
                ? t('workflow.transition.approve', { defaultValue: 'Zatwierdź' })
                : t('workflow.transition.reject', { defaultValue: 'Odrzuć' })}
              {decision !== null && decision.ids.length > 1 ? ` (${decision.ids.length})` : ''}
            </DialogTitle>
            <DialogDescription>
              {requiresComment
                ? t('workflow.dialog.comment_required', {
                    defaultValue: 'Odrzucenie wymaga komentarza dla autora.',
                  })
                : t('workflow.dialog.comment_optional', {
                    defaultValue: 'Możesz dodać komentarz (opcjonalnie).',
                  })}
            </DialogDescription>
          </DialogHeader>
          <Textarea
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            maxLength={2000}
            data-testid="queue-comment"
          />
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDecision(null)} disabled={submitting}>
              {t('common.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              onClick={confirmDecision}
              disabled={submitting || (requiresComment && comment.trim() === '')}
              data-testid="queue-confirm"
            >
              {t('common.confirm', { defaultValue: 'Potwierdź' })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
