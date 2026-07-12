import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui-v2/status-pill';
import { fetchWorkflowLog, placePillVariant, type WorkflowLogEntry } from '@/lib/workflow/api';

/**
 * Pakiet E (WFL-P3-05 #2427) — transition history timeline (Pimcore
 * "notes & events" pattern): who moved the object between which places,
 * with the reviewer comment and the auto-unpublish / bulk context
 * badges. UUIDv7 cursor pages backwards ("pokaż starsze").
 */
export function WorkflowHistorySheet({
  objectId,
  open,
  onOpenChange,
}: {
  objectId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t, i18n } = useTranslation();
  const [entries, setEntries] = useState<WorkflowLogEntry[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    fetchWorkflowLog(objectId)
      .then((page) => {
        setEntries(page.items);
        setCursor(page.next_cursor);
      })
      .catch(() => setEntries([]))
      .finally(() => setLoading(false));
  }, [open, objectId]);

  const loadOlder = () => {
    if (cursor === null) return;
    setLoading(true);
    fetchWorkflowLog(objectId, cursor)
      .then((page) => {
        setEntries((previous) => [...previous, ...page.items]);
        setCursor(page.next_cursor);
      })
      .finally(() => setLoading(false));
  };

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-96 overflow-y-auto">
        <SheetTitle>
          {t('workflow.history.title', { defaultValue: 'Historia workflow' })}
        </SheetTitle>
        <div className="mt-4 flex flex-col gap-3" data-testid="workflow-history-list">
          {entries.length === 0 && !loading ? (
            <p className="text-sm text-muted-foreground">
              {t('workflow.history.empty', { defaultValue: 'Brak przejść.' })}
            </p>
          ) : (
            entries.map((entry) => (
              <HistoryRow key={entry.id} entry={entry} locale={i18n.language} />
            ))
          )}
          {cursor !== null && (
            <Button variant="outline" size="sm" onClick={loadOlder} disabled={loading}>
              {t('workflow.history.older', { defaultValue: 'Pokaż starsze' })}
            </Button>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function HistoryRow({ entry, locale }: { entry: WorkflowLogEntry; locale: string }) {
  const { t } = useTranslation();
  const autoUnpublish = entry.context?.auto_unpublish === true;
  const bulk = entry.context?.bulk === true;

  return (
    <div className="rounded-md border border-border p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {t(`workflow.transition.${entry.transition}`, { defaultValue: entry.transition })}
        </span>
        <span className="text-xs text-muted-foreground">
          {formatTime(entry.created_at, locale)}
        </span>
      </div>
      <div className="mt-1 flex items-center gap-1.5">
        <StatusPill
          variant={placePillVariant(entry.from)}
          label={t(`workflow.place.${entry.from}`, { defaultValue: entry.from })}
        />
        <span className="text-zinc-400" aria-hidden="true">
          →
        </span>
        <StatusPill
          variant={placePillVariant(entry.to)}
          label={t(`workflow.place.${entry.to}`, { defaultValue: entry.to })}
        />
      </div>
      <p className="mt-0.5 text-[11px] text-zinc-500">
        {entry.actor_name ?? t('workflow.history.system', { defaultValue: 'System' })}
      </p>
      {entry.comment !== null && <p className="mt-1 text-sm">{entry.comment}</p>}
      {(autoUnpublish || bulk) && (
        <div className="mt-1 flex gap-1">
          {autoUnpublish && (
            <span className="rounded bg-orange-50 px-1.5 py-0.5 text-[10px] font-medium text-orange-800">
              {t('workflow.history.auto_unpublish', { defaultValue: 'auto-unpublish' })}
            </span>
          )}
          {bulk && (
            <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600">
              {t('workflow.history.bulk', { defaultValue: 'bulk' })}
            </span>
          )}
        </div>
      )}
    </div>
  );
}

function formatTime(value: string, locale: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
