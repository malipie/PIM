import { Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AdvancedFilterPanel } from '@/components/catalog/advanced-filter-panel';
import { Button } from '@/components/ui/button';
import { useFilterMatchCount } from '@/features/catalog/search/use-filter-match-count';
import {
  conditionsToDsl,
  dslToFlatConditions,
  type FilterCondition,
  type FilterDsl,
} from '@/lib/filters/filter-dsl';

import { SectionLabel } from '../../components/primitives';

interface FilterObjectType {
  id: string;
  code: string;
  kind?: string;
}

/**
 * #2549 — the "Z PIM (wysyłka)" outbound scope for a SyncBinding. Rendered only
 * for a write flow (SyncConfigScreen gates it on `dir !== 'inbound'`), so a
 * PIM-side filter can never be set on an import. Reuses the same
 * {@link AdvancedFilterPanel} (FilterDsl) as the grid / export / feeds, so the
 * operator builds `status = published AND kategoria = X` visually. The parent
 * owns the persisted DSL; this component edits it and reports changes back.
 */
export function OutboundFilterSection({
  dsl,
  onDslChange,
  objectType = null,
}: {
  dsl: FilterDsl | null;
  onDslChange: (dsl: FilterDsl | null) => void;
  /** ObjectType of the binding — enables the live match counter (#2640). */
  objectType?: FilterObjectType | null;
}) {
  const { t } = useTranslation();
  const [conditions, setConditions] = useState<FilterCondition[]>([]);
  const [matchOperator, setMatchOperator] = useState<'AND' | 'OR'>('AND');
  const [open, setOpen] = useState(false);

  // #2640 — live match preview: counts from the panel's draft while editing,
  // from the applied conditions when the panel is closed.
  const [draft, setDraft] = useState<{
    conditions: FilterCondition[];
    operator: 'AND' | 'OR';
  } | null>(null);
  const effectiveConditions = open && draft !== null ? draft.conditions : conditions;
  const effectiveOperator = open && draft !== null ? draft.operator : matchOperator;
  const { count: matchCount, isLoading: countLoading } = useFilterMatchCount(
    objectType === null || objectType.kind === 'product' || objectType.code === 'product'
      ? { kind: 'products' }
      : { objectTypeId: objectType.id },
    effectiveConditions,
    effectiveOperator,
  );

  // The panel's apply calls setConditions and setMatchOperator back-to-back in
  // one event (#2637). Deriving the second commit from this component's state
  // would read the stale pre-render value and wipe the DSL the first commit
  // just reported, so the last committed pair lives in refs and every commit
  // reads from them.
  const conditionsRef = useRef<FilterCondition[]>([]);
  const operatorRef = useRef<'AND' | 'OR'>('AND');

  // Hydrate the flat editor from the stored DSL. A nested (AND/OR-of-groups)
  // DSL can't render flat; fall back to an empty editor (the stored filter
  // still applies on the backend until re-saved).
  useEffect(() => {
    conditionsRef.current = dslToFlatConditions(dsl) ?? [];
    operatorRef.current =
      dsl !== null && typeof dsl === 'object' && 'operator' in dsl && dsl.operator === 'OR'
        ? 'OR'
        : 'AND';
    setConditions(conditionsRef.current);
    setMatchOperator(operatorRef.current);
  }, [dsl]);

  const commit = (next?: FilterCondition[], operator?: 'AND' | 'OR'): void => {
    if (next !== undefined) conditionsRef.current = next;
    if (operator !== undefined) operatorRef.current = operator;
    setConditions(conditionsRef.current);
    setMatchOperator(operatorRef.current);
    onDslChange(conditionsToDsl(conditionsRef.current, operatorRef.current));
  };

  return (
    <section
      className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="outbound-filter-section"
    >
      <SectionLabel
        right={
          <span className="inline-flex items-center gap-1 text-[11px] font-medium text-zinc-500">
            <Send className="size-3.5" aria-hidden="true" />
            {t('api_configurator.sync.outbound_filter.tag', { defaultValue: 'Z PIM' })}
          </span>
        }
      >
        {t('api_configurator.sync.outbound_filter.title', {
          defaultValue: 'Zakres wysyłki — które obiekty wysyłać',
        })}
      </SectionLabel>
      <p className="mb-3 text-[12.5px] leading-relaxed text-zinc-600">
        {t('api_configurator.sync.outbound_filter.hint', {
          defaultValue:
            'Filtr dotyczy wyłącznie danych wychodzących z PIM. Pusty = wysyłamy wszystkie obiekty tego typu.',
        })}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          data-testid="outbound-filter-toggle"
        >
          {conditions.length === 0
            ? t('api_configurator.sync.outbound_filter.set', {
                defaultValue: 'Ustaw filtr wysyłki',
              })
            : t('api_configurator.sync.outbound_filter.edit', {
                defaultValue: 'Edytuj filtr wysyłki',
              })}
        </Button>
        <span className="text-[12px] text-zinc-500" data-testid="outbound-filter-summary">
          {conditions.length === 0
            ? t('api_configurator.sync.outbound_filter.none', {
                defaultValue: 'Bez filtra — wszystkie obiekty',
              })
            : t('api_configurator.sync.outbound_filter.count', {
                defaultValue: '{{count}} warunków',
                count: conditions.length,
              })}
        </span>
      </div>

      {/* #2640 — live match preview (search-index based; the push itself counts via SQL). */}
      <div
        data-testid="outbound-filter-match-count"
        aria-live="polite"
        className="mt-3 rounded-xl border border-zinc-200 bg-zinc-50/60 px-4 py-2.5 text-[12.5px]"
      >
        {countLoading && matchCount === undefined ? (
          <span className="text-zinc-500">
            {t('api_configurator.sync.outbound_filter.count_loading', {
              defaultValue: 'Liczę obiekty…',
            })}
          </span>
        ) : (
          <span className="text-zinc-800">
            <strong className="tabular-nums">{(matchCount ?? 0).toLocaleString('pl-PL')}</strong>{' '}
            {t('api_configurator.sync.outbound_filter.count_preview', {
              count: matchCount ?? 0,
              defaultValue: 'obiektów zostanie wysłanych',
            })}
            <span className="ml-2 text-[11px] text-zinc-500">
              {t('api_configurator.sync.outbound_filter.count_note', {
                defaultValue: 'podgląd z indeksu wyszukiwania',
              })}
            </span>
          </span>
        )}
      </div>
      <div className="relative mt-3">
        <AdvancedFilterPanel
          open={open}
          conditions={conditions}
          setConditions={(next) => commit(next)}
          matchOperator={matchOperator}
          setMatchOperator={(operator) => commit(undefined, operator)}
          onDraftChange={(next, operator) => setDraft({ conditions: next, operator })}
          onApply={() => setOpen(false)}
          onClose={() => setOpen(false)}
          onClear={() => {
            commit([], 'AND');
            setOpen(false);
          }}
        />
      </div>
    </section>
  );
}
